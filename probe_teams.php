<?php
// Probe API-Football for 2026-27 clubs that were not in the 2024 Ligue 1 dump.

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

$searches = ['Marsa', 'Hammam-Lif', 'Hammam Sousse', 'Sakiet'];

foreach ($searches as $q) {
    echo "\n==== search: {$q} ====\n";
    $data = callApi($baseUrl, $apiKey, '/teams', ['search' => $q]);
    if (!empty($data['errors'])) {
        print_r($data['errors']);
        continue;
    }
    foreach (($data['response'] ?? []) as $row) {
        $t = $row['team'] ?? [];
        $c = $row['country'] ?? [];
        $country = is_array($c) ? ($c['name'] ?? '') : (string) $c;
        echo ($t['id'] ?? '') . ' | ' . ($t['name'] ?? '') . ' | ' . ($t['code'] ?? '') . ' | ' . $country . "\n";
    }
}
