<?php
// update_prices.php
// Prices follow ability, not only position.
// 1) Floor by position (cheap unknown / youth)
// 2) 2024 season stats from the API (last season the free plan can read)
// 3) Extra bump for players already producing in 2026-27 (from our live points table)
//
// Run: C:\xampp\php\php.exe update_prices.php

require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/football_api.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function floorPrice(string $position): float
{
    return ['GK' => 4.0, 'DEF' => 4.5, 'MID' => 5.0, 'FWD' => 5.5][$position] ?? 4.5;
}

function abilityPrice(string $position, int $apps, int $goals, int $assists, int $livePoints): float
{
    $price = floorPrice($position);

    // Reliability
    if ($apps >= 25) {
        $price += 1.0;
    } elseif ($apps >= 15) {
        $price += 0.5;
    }

    // Output (this is "how good he is", not his position)
    $price += min(4.0, $goals * 0.4);
    $price += min(2.0, $assists * 0.3);

    // 2026-27 form already stored in FTL
    if ($livePoints >= 10) {
        $price += 1.5;
    } elseif ($livePoints >= 6) {
        $price += 1.0;
    } elseif ($livePoints >= 3) {
        $price += 0.5;
    }

    if ($price < 4.0) {
        $price = 4.0;
    }
    if ($price > 13.0) {
        $price = 13.0;
    }
    return round($price * 2) / 2; // 0.5 steps like FPL
}

echo "Reset to position floors...\n";
$pdo->exec("UPDATE players SET price = CASE position
    WHEN 'GK' THEN 4.0 WHEN 'DEF' THEN 4.5 WHEN 'MID' THEN 5.0 WHEN 'FWD' THEN 5.5 ELSE 4.5 END
    WHERE is_active = 1");

$livePts = [];
foreach ($pdo->query('SELECT player_id, SUM(final_points) AS pts FROM gameweek_points GROUP BY player_id') as $row) {
    $livePts[(int) $row['player_id']] = (int) $row['pts'];
}

$players = [];
foreach ($pdo->query('SELECT id, api_id, club_id, name, position FROM players WHERE is_active = 1 AND api_id IS NOT NULL') as $row) {
    $players[(int) $row['api_id']] = $row;
}

$clubs = $pdo->query('SELECT id, name, api_id FROM clubs ORDER BY name')->fetchAll();
$update = $pdo->prepare('UPDATE players SET price = ?, name = ? WHERE api_id = ?');
$priced = 0;

echo "Reading 2024 stats (ability). This is slow because of the 10 requests/minute limit.\n";

foreach ($clubs as $club) {
    $page = 1;
    $total = 1;
    $teamHits = 0;
    do {
        $data = footballApi('/players', [
            'team'   => (int) $club['api_id'],
            'season' => 2024,
            'page'   => $page,
        ]);
        $err = footballErrors($data);
        if ($err) {
            echo "  {$club['name']} page {$page}: {$err}\n";
            break;
        }
        $total = (int) ($data['paging']['total'] ?? 1);
        foreach ($data['response'] ?? [] as $row) {
            $apiId = (int) ($row['player']['id'] ?? 0);
            if ($apiId === 0 || !isset($players[$apiId])) {
                continue;
            }
            $st   = $row['statistics'][0] ?? [];
            $apps = (int) ($st['games']['appearences'] ?? $st['games']['appearances'] ?? 0);
            $gls  = (int) ($st['goals']['total'] ?? 0);
            $ast  = (int) ($st['goals']['assists'] ?? 0);
            $pid  = (int) $players[$apiId]['id'];
            $pos  = $players[$apiId]['position'];
            $name = trim((string) ($row['player']['name'] ?? $players[$apiId]['name']));
            $price = abilityPrice($pos, $apps, $gls, $ast, $livePts[$pid] ?? 0);
            $update->execute([$price, $name, $apiId]);
            $priced++;
            $teamHits++;
        }
        $page++;
    } while ($page <= $total);
    echo "  {$club['name']}: updated {$teamHits} current players from 2024 stats\n";
}

// Players with no 2024 overlap keep the floor, plus live-form bump only
$bump = $pdo->prepare('UPDATE players SET price = ? WHERE id = ?');
foreach ($pdo->query('SELECT id, position, price FROM players WHERE is_active = 1') as $row) {
    $pid = (int) $row['id'];
    if (!isset($livePts[$pid]) || $livePts[$pid] < 3) {
        continue;
    }
    $price = abilityPrice($row['position'], 0, 0, 0, $livePts[$pid]);
    if ($price > (float) $row['price']) {
        $bump->execute([$price, $pid]);
    }
}

echo "Players priced from 2024 stats: {$priced}\n";
echo "Most expensive now:\n";
foreach ($pdo->query(
    'SELECT p.name, p.position, p.price, c.short_name
     FROM players p JOIN clubs c ON c.id = p.club_id
     WHERE p.is_active = 1
     ORDER BY p.price DESC, p.name
     LIMIT 20'
) as $r) {
    echo "  {$r['price']} {$r['position']} {$r['name']} ({$r['short_name']})\n";
}
echo "Done.\n";
