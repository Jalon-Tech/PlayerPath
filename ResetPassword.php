<?php
// ResetPassword.php (PlayerPath)
declare(strict_types=1);

// (dev only) uncomment to see PHP errors
// ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/**
 * Accept the token from GET (first load) OR POST (form submit).
 * If missing or invalid, redirect to ForgotPassword with a flash message.
 */
$token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
if ($token === '') {
  flash('Reset link missing or expired. Please request a new one.');
  header('Location: ForgotPassword.php');
  exit;
}

$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare(
  'SELECT pr.user_id, pr.expires_at, u.email
     FROM password_resets pr
     JOIN users u ON u.id = pr.user_id
    WHERE pr.token_hash = ?
    LIMIT 1'
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

if (!$row) {
  flash('Invalid or expired reset link. Please request a new one.');
  header('Location: ForgotPassword.php');
  exit;
}

if (new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
  $pdo->prepare('DELETE FROM password_resets WHERE token_hash = ?')->execute([$tokenHash]);
  flash('This reset link has expired. Please request a new one.');
  header('Location: ForgotPassword.php');
  exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password_confirm'] ?? '');

  $len = function(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); };

  if ($len($pass) < 8) { $errors[] = 'Password must be at least 8 characters.'; }
  if ($pass !== $pass2) { $errors[] = 'Passwords do not match.'; }

  if (!$errors) {
    // Update password & invalidate the token
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, (int)$row['user_id']]);
    // Remove this token (and optionally any others for the user)
    $pdo->prepare('DELETE FROM password_resets WHERE token_hash = ?')->execute([$tokenHash]);
    // Optionally clean all tokens for this user:
    // $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int)$row['user_id']]);

    flash('Password updated. You can sign in now.');
    header('Location: SignIn.php');
    exit;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>PlayerPath — Reset Password</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Orbitron:wght@700;800&display=swap" rel="stylesheet">
<style>
:root{--brand:#e60023;--bg:#0e0e10;--surface:#131315;--border:#2a2b31;--ink:#f4f5f7;--ink-dim:#b8bcc4;--radius:14px;--ring:0 0 0 3px rgba(230,0,35,.28)}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
.wrap{max-width:480px;margin:28px auto;padding:0 20px}
.card{border-radius:var(--radius);background:var(--surface);border:1px solid var(--border);padding:20px}
.input{width:100%;padding:12px;border-radius:10px;border:1px solid #2a2b31;background:#0f0f12;color:#f4f5f7}
.btn{border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:var(--brand);color:#fff}
a:focus-visible,button:focus-visible,input:focus-visible{outline:0;box-shadow:var(--ring);border-radius:8px}
.error{background:#2b0f14;border:1px solid #5c0d18;color:#ffd7dd;padding:10px;border-radius:10px;margin-bottom:10px}
.help{font-size:.9rem;color:var(--ink-dim)}
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Reset password</h1>
      <p class="help">Account: <?php echo htmlspecialchars($row['email']); ?></p>

      <?php if (!empty($errors)): ?>
        <div class="error"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <label>
          <div>New password</div>
          <input class="input" type="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label>
          <div>Confirm password</div>
          <input class="input" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
        </label>
        <div style="margin-top:12px">
          <button class="btn" type="submit">Update password</button>
        </div>
      </form>

      <p style="margin-top:12px"><a href="SignIn.php">Back to sign in</a></p>
    </div>
  </div>
</body>
</html>
