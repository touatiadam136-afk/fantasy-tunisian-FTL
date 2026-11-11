<?php
require_once __DIR__ . '/backend/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo 'clubs=' . $pdo->query('SELECT COUNT(*) FROM clubs')->fetchColumn() . "\n";
echo 'active players=' . $pdo->query('SELECT COUNT(*) FROM players WHERE is_active = 1')->fetchColumn() . "\n";

foreach ($pdo->query('SELECT position, COUNT(*) AS c FROM players WHERE is_active = 1 GROUP BY position') as $r) {
    echo $r['position'] . ' ' . $r['c'] . "\n";
}

echo "sample names:\n";
$stmt = $pdo->query(
    "SELECT p.name, p.position, p.price, c.short_name
     FROM players p
     JOIN clubs c ON c.id = p.club_id
     WHERE p.is_active = 1
       AND (p.name LIKE '%Mastouri%' OR p.name LIKE '%Aloui%' OR p.name LIKE '%Ben Said%' OR p.name LIKE '%Belhadj%')
     ORDER BY p.name"
);
foreach ($stmt as $r) {
    echo $r['name'] . ' | ' . $r['position'] . ' | ' . $r['price'] . ' | ' . $r['short_name'] . "\n";
}
