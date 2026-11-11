<?php
// sync_season_2026.php
// 1) Replace 2024 clubs with the 2026-27 Ligue 1 list (promotion / relegation).
// 2) Import CURRENT squads from API-Football /players/squads (no season needed).
// 3) Set a simple price by position; bump it with 2024 stats when the free plan allows.
//
// Run: C:\xampp\php\php.exe sync_season_2026.php

require_once __DIR__ . '/backend/config.php';

function callApi(string $endpoint, array $params = []): array
{
    $url = FOOTBALL_API_BASE . $endpoint . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-apisports-key: ' . FOOTBALL_API_KEY],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        die('cURL error: ' . curl_error($ch) . "\n");
    }
    curl_close($ch);
    $data = json_decode($body, true);
    if (!is_array($data)) {
        die("Invalid JSON:\n" . $body . "\n");
    }
    sleep(7); // free plan: 10 requests per minute
    return $data;
}

function mapPosition(?string $apiPosition): ?string
{
    $p = strtolower(trim((string) $apiPosition));
    if ($p === '') {
        return null;
    }
    if (strpos($p, 'goal') !== false) {
        return 'GK';
    }
    if (strpos($p, 'def') !== false || strpos($p, 'back') !== false) {
        return 'DEF';
    }
    if (strpos($p, 'mid') !== false) {
        return 'MID';
    }
    if (strpos($p, 'att') !== false || strpos($p, 'forw') !== false || strpos($p, 'wing') !== false || strpos($p, 'strik') !== false) {
        return 'FWD';
    }
    return null;
}

function basePrice(string $position): float
{
    return ['GK' => 4.5, 'DEF' => 5.0, 'MID' => 5.5, 'FWD' => 6.5][$position];
}

function priceFromStats(string $position, array $games, array $goals): float
{
    $price = basePrice($position);
    $apps  = (int) ($games['appearences'] ?? $games['appearances'] ?? 0);
    $gls   = (int) ($goals['total'] ?? 0);
    $asts  = (int) ($goals['assists'] ?? 0);

    if ($apps >= 20) {
        $price += 0.5;
    }
    if ($gls >= 8) {
        $price += 1.5;
    } elseif ($gls >= 4) {
        $price += 1.0;
    } elseif ($gls >= 2) {
        $price += 0.5;
    }
    if ($asts >= 5) {
        $price += 0.5;
    }

    if ($price < 4.0) {
        $price = 4.0;
    }
    if ($price > 12.0) {
        $price = 12.0;
    }
    return $price;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

echo "=== 1) Clubs: 2024 list -> 2026-27 list ===\n";

// 2024 Ligue 1 clubs that are not in 2026-27
$relegatedApiIds = [
    987,   // AS Gabes
    6253,  // AS Soliman
    1190,  // US Tataouine
    10604, // EGS Gafsa
];

$pdo->prepare('DELETE FROM clubs WHERE api_id IN (' . implode(',', $relegatedApiIds) . ')')->execute();
echo "Removed relegated / out clubs: AS Gabes, AS Soliman, US Tataouine, EGS Gafsa\n";

$newClubs = [
    ['AS Marsa', 'ASM', 10368],
    ['CS Hammam-Lif', 'CSHL', 1191],
    ['ES Hammam-Sousse', 'ESHS', 10609],
    ['PS Sakiet Eddaïer', 'PSS', 13358],
];

$insertClub = $pdo->prepare(
    'INSERT INTO clubs (name, short_name, api_id)
     SELECT ?, ?, ?
     FROM DUAL
     WHERE NOT EXISTS (SELECT 1 FROM clubs WHERE api_id = ?)'
);

foreach ($newClubs as $club) {
    $insertClub->execute([$club[0], $club[1], $club[2], $club[2]]);
    echo "Ensured club: {$club[0]} ({$club[1]}) api_id={$club[2]}\n";
}

$clubs = $pdo->query('SELECT id, name, short_name, api_id FROM clubs ORDER BY name')->fetchAll();
echo 'clubs in DB: ' . count($clubs) . "\n";
if (count($clubs) !== 16) {
    echo "WARNING: expected 16 clubs.\n";
}

echo "\n=== 2) Current squads from API-Football ===\n";

$insertPlayer = $pdo->prepare(
    'INSERT INTO players (club_id, name, position, price, api_id, is_active)
     VALUES (?, ?, ?, ?, ?, 1)'
);
$updatePlayer = $pdo->prepare(
    'UPDATE players SET club_id = ?, name = ?, position = ?, is_active = 1 WHERE api_id = ?'
);
$deactivateOthers = $pdo->prepare(
    'UPDATE players SET is_active = 0 WHERE club_id = ? AND (api_id IS NULL OR api_id NOT IN (' .
    'SELECT api_id FROM (SELECT api_id FROM players WHERE club_id = ? AND api_id IS NOT NULL) t' .
    '))'
);

$existingByApi = [];
foreach ($pdo->query('SELECT id, api_id FROM players WHERE api_id IS NOT NULL') as $row) {
    $existingByApi[(int) $row['api_id']] = (int) $row['id'];
}

$imported = 0;
$updated  = 0;
$skipped  = 0;
$clubApiIds = [];

foreach ($clubs as $club) {
    $teamApiId = (int) $club['api_id'];
    $clubId    = (int) $club['id'];
    $clubApiIds[] = $teamApiId;

    $data = callApi('/players/squads', ['team' => $teamApiId]);
    if (!empty($data['errors'])) {
        echo "Squad error for {$club['name']}:\n";
        print_r($data['errors']);
        continue;
    }

    $players = $data['response'][0]['players'] ?? [];
    echo $club['short_name'] . ' squad: ' . count($players) . " players\n";

    $seenApiIds = [];

    foreach ($players as $p) {
        $apiId = isset($p['id']) ? (int) $p['id'] : 0;
        $name  = trim((string) ($p['name'] ?? ''));
        $pos   = mapPosition($p['position'] ?? null);

        if ($apiId === 0 || $name === '' || $pos === null) {
            $skipped++;
            continue;
        }

        $seenApiIds[] = $apiId;
        $price = basePrice($pos);

        if (isset($existingByApi[$apiId])) {
            $updatePlayer->execute([$clubId, $name, $pos, $apiId]);
            $updated++;
        } else {
            $insertPlayer->execute([$clubId, $name, $pos, $price, $apiId]);
            $existingByApi[$apiId] = (int) $pdo->lastInsertId();
            $imported++;
        }
    }

    // Players of this club who are no longer in the current squad leave Ligue 1 fantasy.
    if (count($seenApiIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($seenApiIds), '?'));
        $sql = "UPDATE players SET is_active = 0 WHERE club_id = ? AND (api_id IS NULL OR api_id NOT IN ($placeholders))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$clubId], $seenApiIds));
    }
}

echo "Inserted {$imported}, updated club/name {$updated}, skipped {$skipped}\n";

echo "\n=== 3) Price bump from 2024 stats (free plan last allowed season) ===\n";

$priceStmt = $pdo->prepare('UPDATE players SET price = ?, name = ? WHERE api_id = ?');
$priced = 0;

foreach ($clubs as $club) {
    $teamApiId = (int) $club['api_id'];
    $page = 1;
    $totalPages = 1;

    do {
        $data = callApi('/players', [
            'team'   => $teamApiId,
            'season' => 2024,
            'page'   => $page,
        ]);

        if (!empty($data['errors'])) {
            echo "Stats error for {$club['name']} page {$page}: ";
            echo json_encode($data['errors']) . "\n";
            break;
        }

        $totalPages = (int) ($data['paging']['total'] ?? 1);

        foreach (($data['response'] ?? []) as $row) {
            $pl   = $row['player'] ?? [];
            $st   = $row['statistics'][0] ?? [];
            $apiId = isset($pl['id']) ? (int) $pl['id'] : 0;
            if ($apiId === 0 || !isset($existingByApi[$apiId])) {
                continue;
            }

            $pos = mapPosition($st['games']['position'] ?? null);
            if ($pos === null) {
                $cur = $pdo->prepare('SELECT position FROM players WHERE api_id = ?');
                $cur->execute([$apiId]);
                $pos = $cur->fetchColumn() ?: 'MID';
            }

            $price = priceFromStats($pos, $st['games'] ?? [], $st['goals'] ?? []);
            $fullName = trim((string) ($pl['name'] ?? ''));
            if ($fullName === '') {
                continue;
            }
            $priceStmt->execute([$price, $fullName, $apiId]);
            $priced++;
        }

        $page++;
    } while ($page <= $totalPages);
}

echo "Updated prices/names from 2024 stats for {$priced} current players.\n";

echo "\n=== Summary ===\n";
$rows = $pdo->query(
    'SELECT c.short_name, c.name,
            SUM(p.is_active = 1) AS active_players
     FROM clubs c
     LEFT JOIN players p ON p.club_id = c.id
     GROUP BY c.id
     ORDER BY c.name'
)->fetchAll();

foreach ($rows as $row) {
    echo $row['short_name'] . ' | ' . $row['name'] . ' | active=' . (int) $row['active_players'] . "\n";
}

$tot = $pdo->query('SELECT COUNT(*) FROM players WHERE is_active = 1')->fetchColumn();
echo "\nActive players total: {$tot}\n";
echo "Done.\n";
