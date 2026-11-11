<?php
require_once __DIR__ . '/backend/football_api.php';
$st = footballApi('/status');
echo json_encode($st['response']['requests'] ?? $st, JSON_PRETTY_PRINT);
