<?php
require_once __DIR__ . '/backend/includes/database.php';
$db = getDb();
echo "tables:\n";
foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    echo " - $t\n";
}
echo "auth_tokens exists: ";
try {
    echo $db->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
}
