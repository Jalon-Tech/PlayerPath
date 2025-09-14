<?php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? '');

  $username = trim((string)($_POST['username'] ?? ''));
  $display  = trim((string)($_POST['display_name'] ?? ''));
  $email    = trim((string)($_POST['email'] ?? ''));
  $pass     = (string)($_POST['password'] ?? '');
  $pass2    = (string)($_POST['password_confirm'] ?? '');

  $len = function(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
  };

  // ---- Validate ----
  if ($username === '' || !preg_match('/^[A-Za-z0-9_.]{3,20}$/', $username)) {
    $errors[] = 'Username must be 3–20 characters using letters, numbers, underscores, or periods.';
  }
  if ($display === '' || $len($display) < 3) {
    $errors[] = 'Display name must be at least 3 characters.';
  }
  if ($len($display) > 100) $errors[] = 'Display name is too long.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid email address.';
  }
  if ($len($email) > 190)   $errors[] = 'Email is too long.';
  if ($len($pass) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
  }
  if ($pass !== $pass2) {
    $errors[] = 'Passwords do not match.';
  }

  // ---- Uniqueness checks ----
  if (!$errors) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetch()) $errors[] = 'That username is already taken.';
  }
  if (!$errors) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) $errors[] = 'That email is already registered.';
  }

  // ---- Create account ----
  if (!$errors) {
    try {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      // NOTE: table column is `password` (not `password_hash`)
      $ins  = $pdo->prepare('INSERT INTO users (username, email, display_name, password) VALUES (?,?,?,?)');
      $ins->execute([$username, $email, $display, $hash]);

      flash('Account created! Please sign in.');
      header('Location: SignIn.php');
      exit;
    } catch (Throwable $e) {
      $errors[] = 'Could not create account: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>PlayerPath — Sign Up</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Orbitron:wght@700;800&display=swap" rel="stylesheet">
<style>
:root{--brand:#e60023;--bg:#0e0e10;--surface:#131315;--border:#2a2b31;--ink:#f4f5f7;--ink-dim:#b8bcc4;--radius:14px;--ring:0 0 0 3px rgba(230,0,35,.28)}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 Inter,system-ui}
.wrap{max-width:480px;margin:28px auto;padding:0 20px}
.card{border-radius:var(--radius);background:var(--surface);border:1px solid var(--border);padding:20px}
.input{width:100%;padding:12px;border-radius:10px;border:1px solid #2a2b31;background:#0f0f12;color:#f4f5f7;margin-bottom:12px}
.btn{border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:var(--brand);color:#fff;width:100%}
a:focus-visible,button:focus-visible,input:focus-visible{outline:0;box-shadow:var(--ring);border-radius:8px}
.error{background:#2b0f14;border:1px solid #5c0d18;color:#ffd7dd;padding:10px;border-radius:10px;margin-bottom:10px}
.help{font-size:.9rem;color:var(--ink-dim)}
h1{font-family:Orbitron,Inter,sans-serif;letter-spacing:.6px}
.small{font-size:.85rem;color:var(--ink-dim);margin:-8px 0 10px}
</style>
</head>
<body>
<div class="wrap"><div class="card">
  <h1>Create Account</h1>

  <?php if ($errors): ?>
    <div class="error"><?php echo implode('<br>', array_map('h',$errors)); ?></div>
  <?php endif; ?>

  <form method="post" novalidate autocomplete="off">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">

    <label>
      <input class="input" type="text" name="username" placeholder="Username (3–20: letters, numbers, _ .)"
             value="<?php echo h($_POST['username'] ?? ''); ?>" required minlength="3" maxlength="20" pattern="[A-Za-z0-9_.]{3,20}" autocomplete="username">
    </label>
    <div class="small">No spaces. You can’t change this later.</div>

    <label>
      <input class="input" type="text" name="display_name" placeholder="Display name"
             value="<?php echo h($_POST['display_name'] ?? ''); ?>" required minlength="3" maxlength="100" autocomplete="nickname">
    </label>
    <label>
      <input class="input" type="email" name="email" placeholder="Email address"
             value="<?php echo h($_POST['email'] ?? ''); ?>" required maxlength="190" autocomplete="email">
    </label>
    <label>
      <input class="input" type="password" name="password" placeholder="Password (min 8 chars)" required minlength="8" autocomplete="new-password">
    </label>
    <label>
      <input class="input" type="password" name="password_confirm" placeholder="Confirm password" required minlength="8" autocomplete="new-password">
    </label>

    <button class="btn" type="submit">Sign Up</button>
  </form>

  <p style="margin-top:12px">Already have an account? <a href="SignIn.php">Sign in</a></p>
  <p class="help">By creating an account, you agree to keep it respectful and on-topic. Let’s build the PlayerPath community together.</p>
</div></div>
</body>
</html>
