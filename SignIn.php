<?php
// SignIn.php — PlayerPath
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// DEV ONLY: show errors while debugging
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

// Redirect helper function
function redirect(string $url): void {
  header('Location: ' . $url);
  exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? '');

  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $errors[] = 'Email and password are required.';
  } else {
    // Fetch user by email; users table has: id, username, display_name, password (hash)
    $stmt = $pdo->prepare('SELECT id, username, display_name, password FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($pass, $user['password'])) {

      // Store session using the login helper from auth.php
      if (function_exists('login')) {
        login($user);
      } else {
        // last-resort inline session setup
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        session_regenerate_id(true);
        $_SESSION['user_id']      = (int)$user['id'];
        $_SESSION['username']     = (string)$user['username'];
        $_SESSION['display_name'] = (string)($user['display_name'] ?: $user['username']);
      }

      $who = $user['display_name'] ?: $user['username'] ?: 'Player';
      flash('Welcome back, ' . h($who) . '!');
      redirect('index.php');
    } else {
      $errors[] = 'Invalid email or password.';
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>PlayerPath — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Orbitron:wght@700;800&display=swap" rel="stylesheet">
<style>
:root{
  --brand:#e60023;
  --bg:#0e0e10;
  --surface:#131315;
  --border:#2a2b31;
  --ink:#f4f5f7;
  --ink-dim:#b8bcc4;
  --radius:14px;
  --ring:0 0 0 3px rgba(230,0,35,.28)
}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 Inter,system-ui}
.wrap{max-width:420px;margin:40px auto;padding:0 20px}
.card{border-radius:var(--radius);background:var(--surface);border:1px solid var(--border);padding:28px;box-shadow:0 4px 18px rgba(0,0,0,.5)}
h1{font-family:Orbitron,sans-serif;font-weight:800;margin:0 0 18px}
.input{width:100%;padding:12px;border-radius:10px;border:1px solid #2a2b31;background:#0f0f12;color:#f4f5f7;margin-bottom:14px}
.btn{border:none;border-radius:10px;padding:12px;font-weight:700;cursor:pointer;background:var(--brand);color:#fff;width:100%;transition:.2s}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.notice{background:#1c1c1f;border:1px solid #3a3a3d;color:#fff;padding:10px;border-radius:10px;margin-bottom:14px}
.error{background:#2b0f14;border:1px solid #5c0d18;color:#ffd7dd;padding:10px;border-radius:10px;margin-bottom:14px}
.links{font-size:.9rem;color:var(--ink-dim);margin-top:14px;text-align:center}
.links a{color:#fff;text-decoration:underline}
</style>
</head>
<body>
<div class="wrap"><div class="card">
  <h1>Sign In</h1>

  <?php if ($msg = flash()): ?>
    <div class="notice"><?php echo $msg; ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="error"><?php echo implode('<br>', array_map('h', $errors)); ?></div>
  <?php endif; ?>

  <form method="post" novalidate autocomplete="off">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <input class="input" type="email" name="email" placeholder="Email address" required maxlength="190"
           value="<?php echo h($_POST['email'] ?? ''); ?>">
    <input class="input" type="password" name="password" placeholder="Password" required minlength="8" autocomplete="current-password">
    <button class="btn" type="submit">Sign In</button>
  </form>

  <div class="links">
    <p><a href="SignUp.php">Create an account</a></p>
    <p><a href="ResetPassword.php">Forgot password?</a></p>
  </div>
</div></div>
</body>
</html>
