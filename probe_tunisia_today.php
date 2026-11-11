<?php
require_once __DIR__ . '/backend/config.php';

function callApi(string $endpoint, array $params = []): array
{
    $url = FOOTBALL_API_BASE . $endpoint . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-apisports-key: ' . FOOTBALL_API_KEY],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true) ?: [];
}

foreach (['2026-11-06', '2026-11-07'] as $date) {
    echo "\n==== {$date} ====\n";
    $data = callApi('/fixtures', ['date' => $date]);
    if (!empty($data['errors'])) {
        echo 'errors: ' . json_encode($data['errors']) . "\n";
        continue;
    }
    echo 'total results=' . ($data['results'] ?? 0) . ' response=' . count($data['response'] ?? []) . "\n";
    $hits = [];
    foreach ($data['response'] ?? [] as $row) {
        if ((int) ($row['league']['id'] ?? 0) === FOOTBALL_LEAGUE_ID || ($row['league']['country'] ?? '') === 'Tunisia') {
            $hits[] = $row;
        }
    }
    echo 'Tunisia hits=' . count($hits) . "\n";
    foreach ($hits as $row) {
        echo ($row['fixture']['id'] ?? '') . ' | ' . ($row['league']['id'] ?? '') . ' ' . ($row['league']['name'] ?? '')
            . ' | ' . ($row['league']['round'] ?? '')
            . ' | ' . ($row['teams']['home']['id'] ?? '') . ' ' . ($row['teams']['home']['name'] ?? '')
            . ' vs ' . ($row['teams']['away']['id'] ?? '') . ' ' . ($row['teams']['away']['name'] ?? '')
            . ' | ' . ($row['goals']['home'] ?? '-') . '-' . ($row['goals']['away'] ?? '-')
            . ' | ' . ($row['fixture']['status']['short'] ?? '') . "\n";
    }
    sleep(7);
}
