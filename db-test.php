<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require __DIR__.'/config.php';

echo "✅ DB connected!<br>";
$count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
echo "Users in table: " . (int)$count;
