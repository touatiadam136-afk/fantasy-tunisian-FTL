<?php
// finish_squads.php
// Completes current-squad import for clubs that still have 0 active players.
// Waits 7 seconds between calls (free plan = 10 requests per minute).

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
    $data = json_decode($body, true);
    if (!is_array($data)) {
        die("Invalid JSON:\n" . $body . "\n");
    }
    sleep(7);
    return $data;
}

function mapPosition(?string $apiPosition): ?string
{
    $p = strtolower(trim((string) $apiPosition));
    if ($p === '') {
        return null;
    }
    if (strpos($p, 'goal') !== false) {
        return 'GK';
    }
    if (strpos($p, 'def') !== false || strpos($p, 'back') !== false) {
        return 'DEF';
    }
    if (strpos($p, 'mid') !== false) {
        return 'MID';
    }
    if (strpos($p, 'att') !== false || strpos($p, 'forw') !== false || strpos($p, 'wing') !== false || strpos($p, 'strik') !== false) {
        return 'FWD';
    }
    return null;
}

function basePrice(string $position): float
{
    return ['GK' => 4.5, 'DEF' => 5.0, 'MID' => 5.5, 'FWD' => 6.5][$position];
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "Waiting 60 seconds for the API rate limit to reset...\n";
sleep(60);

$clubs = $pdo->query(
    'SELECT c.id, c.name, c.short_name, c.api_id,
            COALESCE(SUM(p.is_active = 1), 0) AS active_players
     FROM clubs c
     LEFT JOIN players p ON p.club_id = c.id
     GROUP BY c.id
     HAVING active_players = 0
     ORDER BY c.name'
)->fetchAll();

echo 'Clubs still empty: ' . count($clubs) . "\n";

$existingByApi = [];
foreach ($pdo->query('SELECT id, api_id FROM players WHERE api_id IS NOT NULL') as $row) {
    $existingByApi[(int) $row['api_id']] = (int) $row['id'];
}

$insertPlayer = $pdo->prepare(
    'INSERT INTO players (club_id, name, position, price, api_id, is_active)
     VALUES (?, ?, ?, ?, ?, 1)'
);
$updatePlayer = $pdo->prepare(
    'UPDATE players SET club_id = ?, name = ?, position = ?, is_active = 1 WHERE api_id = ?'
);

$imported = 0;
$updated = 0;

foreach ($clubs as $club) {
    $data = callApi('/players/squads', ['team' => (int) $club['api_id']]);
    if (!empty($data['errors'])) {
        echo "Still blocked for {$club['name']}: " . json_encode($data['errors']) . "\n";
        continue;
    }

    $players = $data['response'][0]['players'] ?? [];
    echo $club['short_name'] . ' squad: ' . count($players) . "\n";

    foreach ($players as $p) {
        $apiId = isset($p['id']) ? (int) $p['id'] : 0;
        $name  = trim((string) ($p['name'] ?? ''));
        $pos   = mapPosition($p['position'] ?? null);
        if ($apiId === 0 || $name === '' || $pos === null) {
            continue;
        }
        if (isset($existingByApi[$apiId])) {
            $updatePlayer->execute([(int) $club['id'], $name, $pos, $apiId]);
            $updated++;
        } else {
            $insertPlayer->execute([(int) $club['id'], $name, $pos, basePrice($pos), $apiId]);
            $existingByApi[$apiId] = (int) $pdo->lastInsertId();
            $imported++;
        }
    }
}

echo "Inserted {$imported}, moved/updated {$updated}\n";

$rows = $pdo->query(
    'SELECT c.short_name, c.name, SUM(p.is_active = 1) AS active_players
     FROM clubs c
     LEFT JOIN players p ON p.club_id = c.id
     GROUP BY c.id
     ORDER BY c.name'
)->fetchAll();
foreach ($rows as $row) {
    echo $row['short_name'] . ' | ' . $row['name'] . ' | active=' . (int) $row['active_players'] . "\n";
}
$tot = $pdo->query('SELECT COUNT(*) FROM players WHERE is_active = 1')->fetchColumn();
echo "Active players total: {$tot}\n";
