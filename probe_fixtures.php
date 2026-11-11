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
    if ($body === false) {
        die('cURL error: ' . curl_error($ch) . "\n");
    }
    curl_close($ch);
    return json_decode($body, true) ?: [];
}

function dumpErrors($label, $data)
{
    echo "\n==== {$label} ====\n";
    if (!empty($data['errors'])) {
        echo 'errors: ' . json_encode($data['errors']) . "\n";
    }
    echo 'results=' . ($data['results'] ?? 'n/a') . "\n";
    return $data;
}

$d2026 = dumpErrors('fixtures 2026', callApi('/fixtures', [
    'league' => FOOTBALL_LEAGUE_ID,
    'season' => 2026,
]));
sleep(7);

$d2024 = dumpErrors('fixtures 2024', callApi('/fixtures', [
    'league' => FOOTBALL_LEAGUE_ID,
    'season' => 2024,
]));
sleep(7);

$dDate = dumpErrors('fixtures by date Aug-Nov 2026', callApi('/fixtures', [
    'league' => FOOTBALL_LEAGUE_ID,
    'from'   => '2026-08-01',
    'to'     => '2026-11-10',
]));
sleep(7);

$dLive = dumpErrors('live', callApi('/fixtures', ['live' => 'all']));

$sample = $d2026['response'][0] ?? $dDate['response'][0] ?? null;
if ($sample) {
    $fx = $sample['fixture'] ?? [];
    $id = $fx['id'] ?? null;
    $round = $sample['league']['round'] ?? '';
    echo "\nSample fixture id={$id} round={$round} date=" . ($fx['date'] ?? '') . "\n";
    echo 'home=' . ($sample['teams']['home']['name'] ?? '') . ' away=' . ($sample['teams']['away']['name'] ?? '') . "\n";
    echo 'status=' . ($fx['status']['short'] ?? '') . ' score=' . ($sample['goals']['home'] ?? '') . '-' . ($sample['goals']['away'] ?? '') . "\n";

    if ($id) {
        sleep(7);
        $ev = dumpErrors('events for sample', callApi('/fixtures/events', ['fixture' => $id]));
        $events = $ev['response'] ?? [];
        echo 'event types: ';
        $types = [];
        foreach ($events as $e) {
            $types[$e['type'] . '/' . ($e['detail'] ?? '')] = true;
        }
        echo implode(', ', array_keys($types)) . "\n";
        echo 'first 5 events:\n';
        foreach (array_slice($events, 0, 5) as $e) {
            echo ($e['time']['elapsed'] ?? '') . "' " . ($e['type'] ?? '') . ' ' . ($e['detail'] ?? '') . ' player=' . ($e['player']['id'] ?? '') . ' ' . ($e['player']['name'] ?? '') . "\n";
        }

        sleep(7);
        $lu = dumpErrors('lineups for sample', callApi('/fixtures/lineups', ['fixture' => $id]));
        foreach (($lu['response'] ?? []) as $teamLineup) {
            $start = count($teamLineup['startXI'] ?? []);
            $bench = count($teamLineup['substitutes'] ?? []);
            echo ($teamLineup['team']['name'] ?? '') . " startXI={$start} bench={$bench}\n";
            $p0 = $teamLineup['startXI'][0]['player'] ?? [];
            echo '  first starter: id=' . ($p0['id'] ?? '') . ' name=' . ($p0['name'] ?? '') . ' pos=' . ($p0['pos'] ?? '') . "\n";
        }
    }
} else {
    echo "\nNo 2026 fixture to inspect events/lineups.\n";
    if (!empty($d2024['response'][0])) {
        $fx = $d2024['response'][0]['fixture'] ?? [];
        echo '2024 sample id=' . ($fx['id'] ?? '') . ' date=' . ($fx['date'] ?? '') . "\n";
    }
}
