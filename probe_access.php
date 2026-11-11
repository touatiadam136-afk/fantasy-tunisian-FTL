<?php
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
    curl_close($ch);
    return json_decode($body, true) ?: [];
}

echo "==== /status ====\n";
$st = callApi('/status');
echo json_encode($st['response'] ?? $st, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
sleep(7);

echo "\n==== fixtures 2025 ====\n";
$d = callApi('/fixtures', ['league' => FOOTBALL_LEAGUE_ID, 'season' => 2025]);
echo 'errors=' . json_encode($d['errors'] ?? []) . ' results=' . ($d['results'] ?? 0) . "\n";
sleep(7);

echo "\n==== live leagues ====\n";
$live = callApi('/fixtures', ['live' => 'all']);
foreach (($live['response'] ?? []) as $row) {
    echo ($row['league']['id'] ?? '') . ' | ' . ($row['league']['name'] ?? '') . ' | ' . ($row['league']['country'] ?? '')
        . ' | ' . ($row['teams']['home']['name'] ?? '') . ' vs ' . ($row['teams']['away']['name'] ?? '') . "\n";
}
