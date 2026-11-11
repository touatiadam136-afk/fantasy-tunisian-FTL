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

$queries = [
    ['season' => 2026, 'date' => '2026-11-05', 'league' => FOOTBALL_LEAGUE_ID],
    ['season' => 2026, 'date' => '2026-11-06', 'league' => FOOTBALL_LEAGUE_ID],
    ['season' => 2026, 'date' => '2026-11-07', 'league' => FOOTBALL_LEAGUE_ID],
    ['season' => 2026, 'date' => '2026-11-07'],
    ['date' => '2026-11-07'],
];

foreach ($queries as $i => $params) {
    echo "\n==== query " . ($i + 1) . ' ' . json_encode($params) . " ====\n";
    $data = callApi('/fixtures', $params);
    if (!empty($data['errors'])) {
        echo 'errors: ' . json_encode($data['errors']) . "\n";
    }
    echo 'results=' . ($data['results'] ?? 0) . "\n";
    foreach (array_slice($data['response'] ?? [], 0, 8) as $row) {
        echo ($row['fixture']['id'] ?? '') . ' | ' . ($row['league']['country'] ?? '') . ' ' . ($row['league']['name'] ?? '')
            . ' | ' . ($row['league']['round'] ?? '')
            . ' | ' . ($row['teams']['home']['name'] ?? '') . ' vs ' . ($row['teams']['away']['name'] ?? '')
            . ' | ' . ($row['fixture']['status']['short'] ?? '') . "\n";
    }
    sleep(7);
}
