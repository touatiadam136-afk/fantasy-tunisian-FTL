<?php
$apiKey  = 'b3a985278e45d8c126a3eded7c40c1d6';
$baseUrl = 'https://v3.football.api-sports.io';

function callApi($baseUrl, $apiKey, $endpoint, $params)
{
    $url = $baseUrl . $endpoint . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-apisports-key: ' . $apiKey],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        die('cURL error: ' . curl_error($ch) . "\n");
    }
    curl_close($ch);
    return json_decode($body, true);
}

echo "==== search Hammam Lif ====\n";
print_r(callApi($baseUrl, $apiKey, '/teams', ['search' => 'Hammam Lif']));

echo "==== search Hammam Sousse ====\n";
$d = callApi($baseUrl, $apiKey, '/teams', ['search' => 'Hammam']);
foreach (($d['response'] ?? []) as $row) {
    $t = $row['team'] ?? [];
    echo ($t['id'] ?? '') . ' | ' . ($t['name'] ?? '') . ' | ' . ($t['code'] ?? '') . "\n";
}

echo "\n==== squads EST (980) ====\n";
$s = callApi($baseUrl, $apiKey, '/players/squads', ['team' => 980]);
if (!empty($s['errors'])) {
    print_r($s['errors']);
} else {
    $players = $s['response'][0]['players'] ?? [];
    echo 'count=' . count($players) . "\n";
    foreach (array_slice($players, 0, 8) as $p) {
        echo ($p['id'] ?? '') . ' | ' . ($p['name'] ?? '') . ' | ' . ($p['position'] ?? '') . "\n";
    }
}

echo "\n==== players EST season 2024 page 1 ====\n";
$p = callApi($baseUrl, $apiKey, '/players', ['team' => 980, 'season' => 2024]);
if (!empty($p['errors'])) {
    print_r($p['errors']);
} else {
    echo 'results=' . ($p['results'] ?? 0) . ' paging=' . json_encode($p['paging'] ?? []) . "\n";
    foreach (array_slice($p['response'] ?? [], 0, 3) as $row) {
        $pl = $row['player'] ?? [];
        $st = $row['statistics'][0] ?? [];
        echo ($pl['id'] ?? '') . ' | ' . ($pl['name'] ?? '') . ' | ' . ($st['games']['position'] ?? '') . ' | goals=' . ($st['goals']['total'] ?? 0) . "\n";
    }
}
