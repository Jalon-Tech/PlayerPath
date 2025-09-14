<?php
// auth.php (safe, idempotent)
declare(strict_types=1);

// ---- Sessions ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_only_cookies', '1');
  ini_set('session.cookie_httponly', '1');
  // If you're on HTTPS, uncomment the next line
  // ini_set('session.cookie_secure', '1');
  session_start();
}

/* ---------- Helpers ---------- */
if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (!function_exists('flash')) {
  function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['_flash'] = $msg; return null; }
    $m = $_SESSION['_flash'] ?? null; unset($_SESSION['_flash']); return $m;
  }
}

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
  }
}
if (!function_exists('csrf_check')) {
  function csrf_check(string $token): void {
    if (!hash_equals($_SESSION['_csrf'] ?? '', $token)) {
      http_response_code(400);
      exit('Invalid CSRF token.');
    }
  }
}

/* ---------- Auth ---------- */
if (!function_exists('login')) {
  function login(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']      = (int)$user['id'];
    $_SESSION['username']     = (string)($user['username'] ?? '');
    $_SESSION['display_name'] = (string)($user['display_name'] ?? ($user['username'] ?? 'Player'));
  }
}
// Back-compat alias
if (!function_exists('login_user')) {
  function login_user(array $user): void { login($user); }
}

if (!function_exists('logout')) {
  function logout(): void {
    session_regenerate_id(true);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $p = session_get_cookie_params();
      setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
  }
}
