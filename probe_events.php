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

echo "NS Tunisia fixture 1607462 events/lineups\n";
$ev = callApi('/fixtures/events', ['fixture' => 1607462]);
echo 'events errors=' . json_encode($ev['errors'] ?? []) . ' count=' . count($ev['response'] ?? []) . "\n";
sleep(7);
$lu = callApi('/fixtures/lineups', ['fixture' => 1607462]);
echo 'lineups errors=' . json_encode($lu['errors'] ?? []) . ' teams=' . count($lu['response'] ?? []) . "\n";
sleep(7);

$data = callApi('/fixtures', ['date' => '2026-11-06']);
$ftId = null;
foreach ($data['response'] ?? [] as $row) {
    if (($row['fixture']['status']['short'] ?? '') === 'FT') {
        $ftId = $row['fixture']['id'];
        echo 'FT sample ' . $ftId . ' ' . ($row['league']['name'] ?? '') . ' ' . ($row['teams']['home']['name'] ?? '') . ' vs ' . ($row['teams']['away']['name'] ?? '') . "\n";
        break;
    }
}
if ($ftId) {
    sleep(7);
    $ev = callApi('/fixtures/events', ['fixture' => $ftId]);
    echo 'FT events errors=' . json_encode($ev['errors'] ?? []) . ' count=' . count($ev['response'] ?? []) . "\n";
    foreach (array_slice($ev['response'] ?? [], 0, 6) as $e) {
        echo ($e['time']['elapsed'] ?? '') . "' " . ($e['type'] ?? '') . '/' . ($e['detail'] ?? '')
            . ' p=' . ($e['player']['id'] ?? '') . ' ' . ($e['player']['name'] ?? '')
            . ' a=' . ($e['assist']['id'] ?? '') . ' ' . ($e['assist']['name'] ?? '') . "\n";
    }
    sleep(7);
    $lu = callApi('/fixtures/lineups', ['fixture' => $ftId]);
    echo 'FT lineups errors=' . json_encode($lu['errors'] ?? []) . ' teams=' . count($lu['response'] ?? []) . "\n";
    foreach ($lu['response'] ?? [] as $t) {
        echo ($t['team']['name'] ?? '') . ' XI=' . count($t['startXI'] ?? []) . ' bench=' . count($t['substitutes'] ?? []) . "\n";
    }
}
