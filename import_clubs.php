<?php
// import_clubs.php
// Fetches Ligue 1 clubs from API-Football and inserts them into the clubs table.
// Run: C:\xampp\php\php.exe import_clubs.php
// Free API plan: seasons 2022-2024 only. We use 2024 (latest allowed).

require_once __DIR__ . '/backend/config.php';

$apiKey    = 'b3a985278e45d8c126a3eded7c40c1d6';
$baseUrl   = 'https://v3.football.api-sports.io';
$leagueId  = 202;   // Tunisian Ligue 1
$season    = 2024;  // latest season the free plan can read

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
    $data = json_decode($body, true);
    if (!is_array($data)) {
        die("Invalid JSON:\n" . $body . "\n");
    }
    return $data;
}

$data = callApi($baseUrl, $apiKey, '/teams', [
    'league' => $leagueId,
    'season' => $season,
]);

if (!empty($data['errors'])) {
    echo "API error:\n";
    print_r($data['errors']);
    exit(1);
}

$teams = $data['response'] ?? [];
if (count($teams) === 0) {
    die("No clubs returned.\n");
}

echo 'Season ' . $season . ', clubs found: ' . count($teams) . "\n";

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$insert = $pdo->prepare(
    'INSERT INTO clubs (name, short_name, api_id)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name), short_name = VALUES(short_name)'
);

// api_id is not unique in the original schema. We still skip rows that already have the same api_id.
$existing = $pdo->query('SELECT api_id FROM clubs WHERE api_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
$existing = array_map('intval', $existing);

$added = 0;
$skipped = 0;

foreach ($teams as $row) {
    $team = $row['team'] ?? [];
    $apiId = isset($team['id']) ? (int) $team['id'] : 0;
    $name  = trim((string) ($team['name'] ?? ''));
    $code  = strtoupper(trim((string) ($team['code'] ?? '')));

    if ($apiId === 0 || $name === '') {
        echo "Skipped a row with missing id or name.\n";
        continue;
    }

    if ($code === '') {
        $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3));
        if (strlen($code) < 3) {
            $code = str_pad($code, 3, 'X');
        }
        echo "No API code for {$name}, using {$code}\n";
    }

    if (in_array($apiId, $existing, true)) {
        echo "Already in DB: {$name} (api_id {$apiId})\n";
        $skipped++;
        continue;
    }

    $insert->execute([$name, $code, $apiId]);
    echo "Inserted: {$name} ({$code}) api_id={$apiId}\n";
    $added++;
}

echo "\nAdded {$added}, skipped {$skipped}.\n";

$rows = $pdo->query('SELECT id, name, short_name, api_id FROM clubs ORDER BY name')->fetchAll();
echo 'clubs table now has ' . count($rows) . " rows:\n";
foreach ($rows as $club) {
    echo $club['id'] . ' | ' . $club['name'] . ' | ' . $club['short_name'] . ' | ' . $club['api_id'] . "\n";
}
