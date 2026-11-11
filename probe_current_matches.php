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

function show($label, $data, $limit = 5)
{
    echo "\n==== {$label} ====\n";
    if (!empty($data['errors'])) {
        echo 'errors: ' . json_encode($data['errors']) . "\n";
    }
    echo 'results=' . ($data['results'] ?? 0) . "\n";
    foreach (array_slice($data['response'] ?? [], 0, $limit) as $row) {
        echo ($row['fixture']['id'] ?? '') . ' | ' . ($row['fixture']['date'] ?? '')
            . ' | ' . ($row['league']['name'] ?? '') . ' ' . ($row['league']['season'] ?? '')
            . ' | ' . ($row['league']['round'] ?? '')
            . ' | ' . ($row['teams']['home']['name'] ?? '') . ' vs ' . ($row['teams']['away']['name'] ?? '')
            . ' | ' . ($row['goals']['home'] ?? '-') . '-' . ($row['goals']['away'] ?? '-')
            . ' | ' . ($row['fixture']['status']['short'] ?? '') . "\n";
    }
}

show('EST last 10', callApi('/fixtures', ['team' => 980, 'last' => 10]));
sleep(7);
show('EST next 10', callApi('/fixtures', ['team' => 980, 'next' => 10]));
sleep(7);
show('date 2026-11-01 league 202', callApi('/fixtures', ['league' => FOOTBALL_LEAGUE_ID, 'date' => '2026-11-01']));
sleep(7);
show('date 2026-11-01 no league', callApi('/fixtures', ['date' => '2026-11-01']));
sleep(7);
show('league 202 live', callApi('/fixtures', ['league' => FOOTBALL_LEAGUE_ID, 'live' => 'all']));
