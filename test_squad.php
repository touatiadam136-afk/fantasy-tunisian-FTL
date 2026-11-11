<?php
// test_squad.php — picks a legal 15 and calls the API on localhost.
// Run: C:\xampp\php\php.exe test_squad.php

require_once __DIR__ . '/backend/config.php';

$base = 'http://localhost/ftl/backend/API';

function http(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);
    echo $method . ' ' . $url . ' => HTTP ' . $code . "\n";
    echo $raw . "\n\n";
    return ['code' => $code, 'json' => is_array($json) ? $json : []];
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$email = 'squadtest' . time() . '@test.com';
$reg = http('POST', $base . '/register.php', [
    'email' => $email,
    'password' => '12345678',
    'team_name' => 'Test FC',
]);
$token = $reg['json']['token'] ?? '';
if ($token === '') {
    die("Register failed. Is Apache running?\n");
}

$gw = http('GET', $base . '/gameweeks.php', null, $token);
$gwId = $gw['json']['current_gameweek_id'] ?? null;
if (!$gwId) {
    die("No gameweek. Run sync_live.php first.\n");
}

// Cheapest legal 15: 2 GK, 5 DEF, 5 MID, 3 FWD, max 2 per club, <= 100
$need = ['GK' => 2, 'DEF' => 5, 'MID' => 5, 'FWD' => 3];
$clubCount = [];
$picked = [];
foreach ($need as $pos => $n) {
    $stmt = $pdo->prepare(
        'SELECT id, club_id, price FROM players WHERE is_active = 1 AND position = ? ORDER BY price ASC, id ASC'
    );
    $stmt->execute([$pos]);
    $got = 0;
    foreach ($stmt as $p) {
        $cid = (int) $p['club_id'];
        if (($clubCount[$cid] ?? 0) >= 2) {
            continue;
        }
        $clubCount[$cid] = ($clubCount[$cid] ?? 0) + 1;
        $picked[] = $p;
        $got++;
        if ($got >= $n) {
            break;
        }
    }
    if ($got < $n) {
        die("Not enough {$pos} to build a test squad.\n");
    }
}

$players = [];
$startersLeft = ['GK' => 1, 'DEF' => 4, 'MID' => 4, 'FWD' => 2]; // 4-4-2 + 1 GK
foreach ($picked as $i => $p) {
    $pos = null;
    foreach ($need as $k => $_) {
        // look up position
    }
}
$stmt = $pdo->query('SELECT id, position FROM players WHERE id IN (' . implode(',', array_column($picked, 'id')) . ')');
$posMap = [];
foreach ($stmt as $r) {
    $posMap[(int) $r['id']] = $r['position'];
}

$payloadPlayers = [];
$captain = null;
foreach ($picked as $p) {
    $pos = $posMap[(int) $p['id']];
    $start = false;
    if (($startersLeft[$pos] ?? 0) > 0) {
        $start = true;
        $startersLeft[$pos]--;
    }
    if ($start && $pos === 'FWD' && $captain === null) {
        $captain = (int) $p['id'];
    }
    $payloadPlayers[] = ['player_id' => (int) $p['id'], 'is_starter' => $start];
}
if ($captain === null) {
    foreach ($payloadPlayers as $pp) {
        if ($pp['is_starter']) {
            $captain = $pp['player_id'];
            break;
        }
    }
}

$save = http('POST', $base . '/squad_save.php', [
    'gameweek_id' => $gwId,
    'captain_player_id' => $captain,
    'players' => $payloadPlayers,
], $token);

http('GET', $base . '/squad_get.php', null, $token);

if (!empty($save['json']['ok'])) {
    echo "SQUAD SAVE OK\n";
} else {
    echo "SQUAD SAVE FAILED\n";
    exit(1);
}
