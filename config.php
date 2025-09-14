<?php
// config.php
declare(strict_types=1);

if (!defined('PP_NET_DISABLED')) define('PP_NET_DISABLED', false); // set true to hard-disable all outbound fetches


/**
 * Session hardening (start once, cookie-only, HttpOnly, SameSite=Lax).
 * If you serve over HTTPS, cookie_secure will be enabled automatically.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_only_cookies', '1');
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_samesite', 'Lax');

  // Set cookie_secure when HTTPS is detected
  if (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
  ) {
    ini_set('session.cookie_secure', '1');
  }

  session_start();
}

/**
 * Optional: set your default timezone (matches your local dev)
 */
date_default_timezone_set('America/New_York');

/**
 * Database connection (PDO, MySQL).
 * NOTE: On macOS, using 127.0.0.1 avoids socket-path mismatches with XAMPP.
 */
$DB_HOST = '127.0.0.1';          // use TCP explicitly; avoids socket issues on macOS
$DB_PORT = 3306;
$DB_NAME = 'playerpath';
$DB_USER = 'playerpath_user';
$DB_PASS = 'Falcons4Life!';

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
} catch (Throwable $e) {
  // During development it’s OK to surface the message; in production, log it instead.
  http_response_code(500);
  exit('DB error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

/**
 * Small HTML escaping helper used around the app.
 * Example: <?= h($username) ?>
 */
if (!function_exists('h')) {
  function h(null|string|int|float $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
// API Keys
define('YT_API_KEY', 'AIzaSyC6uxDR9ny7aOh3l2dwPwXXXXXXXX'); // YouTube Data API v3 key
define('BING_API_KEY', ''); // Azure Bing Image Search key (add later if you want daily photos)
