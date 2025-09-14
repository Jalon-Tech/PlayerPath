<?php
// rate_game.php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }
$UID = (int)$_SESSION['user_id'];

$gid = isset($_POST['game_id']) ? (int)$_POST['game_id'] : 0;
$score = isset($_POST['score']) ? (int)$_POST['score'] : 0;
if ($gid <= 0 || $score < 1 || $score > 5) { http_response_code(400); exit; }

$pdo = new PDO("mysql:host=127.0.0.1;dbname=playerpath;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Make sure ratings table exists (safe on localhost)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS ratings (
    user_id INT NOT NULL,
    game_id INT NOT NULL,
    score   TINYINT NOT NULL,
    PRIMARY KEY (user_id, game_id),
    INDEX (game_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$stmt = $pdo->prepare("INSERT INTO ratings (user_id, game_id, score) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE score = VALUES(score)");
$stmt->execute([$UID, $gid, $score]);

http_response_code(204); // no content
