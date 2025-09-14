<?php
// ForgotPassword.php — request reset link (tidy dev UI)
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

$sent    = false;
$devUrl  = null;   // holds the dev-only reset url (when on XAMPP / no mailer)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? '');

  $email = trim((string)($_POST['email'] ?? ''));
  // Always behave the same to prevent account enumeration
  $sent = true;

  if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Look up user
    $stmt = $pdo->prepare('SELECT id, display_name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
      // Create token (valid 1 hour)
      $rawToken  = bin2hex(random_bytes(32));   // shown in link
      $tokenHash = hash('sha256', $rawToken);   // stored in DB
      $expires   = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

      // Your table: id, email, token, expires_at, created_at
      $ins = $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?,?,?)');
      $ins->execute([$email, $tokenHash, $expires]);

      // Build absolute link for the dev helper
      $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
      $baseDir = ($baseDir === '/') ? '' : $baseDir;
      $host    = $_SERVER['HTTP_HOST'];
      $devUrl  = $scheme . '://' . $host . $baseDir . '/ResetPassword.php?token=' . urlencode($rawToken);

      // In production, SEND this via email and do NOT render it on the page.
      // mail($email, 'Reset your PlayerPath password', "Click to reset: $devUrl");
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>PlayerPath — Forgot Password</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Orbitron:wght@700;800&display=swap" rel="stylesheet">
<style>
:root{
  --brand:#e60023; --bg:#0e0e10; --surface:#131315; --border:#2a2b31;
  --ink:#f4f5f7; --ink-dim:#b8bcc4; --radius:14px; --ring:0 0 0 3px rgba(230,0,35,.28);
}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 Inter,system-ui}
.wrap{max-width:520px;margin:48px auto;padding:0 20px}
.card{border-radius:var(--radius);background:var(--surface);border:1px solid var(--border);padding:28px;box-shadow:0 4px 18px rgba(0,0,0,.5)}
h1{font-family:Orbitron,sans-serif;font-weight:800;margin:0 0 18px}

.input{width:100%;padding:12px;border-radius:10px;border:1px solid #2a2b31;background:#0f0f12;color:#f4f5f7;margin-bottom:14px}
.btn{border:none;border-radius:10px;padding:12px 14px;font-weight:700;cursor:pointer;background:var(--brand);color:#fff;transition:.2s}
.btn:hover{opacity:.95;transform:translateY(-1px)}
.btn-row{display:flex;gap:10px;flex-wrap:wrap}
.btn.ghost{background:#0f0f12;color:#f4f5f7;border:1px solid #2a2b31}

.notice{background:#1c1c1f;border:1px solid #3a3a3d;color:#fff;padding:12px;border-radius:10px;margin:14px 0}
.error{background:#2b0f14;border:1px solid #5c0d18;color:#ffd7dd;padding:12px;border-radius:10px;margin:14px 0}
.links{font-size:.9rem;color:var(--ink-dim);margin-top:14px;text-align:center}
.links a{color:#fff;text-decoration:underline}

/* Dev helper panel */
.dev{
  background:#101114;border:1px dashed #3a3a3d;border-radius:12px;padding:12px 12px 14px;margin-bottom:14px
}
.dev-title{font-weight:800;margin:0 0 8px;color:#fff}
.dev-row{display:flex;gap:10px;align-items:center}
.dev-input{
  flex:1; min-width:0; padding:10px 12px; border-radius:10px; border:1px solid #2a2b31;
  background:#0b0c0f; color:#dfe2e6; font-family:ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size:.92rem;
}
.copy-done{background:#1c3b1f !important; border-color:#2e6b3a !important}
.small{font-size:.9rem;color:var(--ink-dim);margin-top:6px}
</style>
</head>
<body>
<div class="wrap"><div class="card">
  <h1>Forgot Password</h1>

  <?php if ($sent): ?>
    <?php if ($devUrl): ?>
      <div class="dev">
        <div class="dev-title">Dev reset link</div>
        <div class="dev-row">
          <input id="resetUrl" class="dev-input" type="text" readonly value="<?php echo h($devUrl); ?>">
          <button id="copyBtn" class="btn" type="button" onclick="copyResetUrl()">Copy</button>
          <a class="btn ghost" href="<?php echo h($devUrl); ?>">Open</a>
        </div>
        <div class="small">This box is for local development. Remove it when email is configured.</div>
      </div>
    <?php endif; ?>

    <div class="notice">If that email is registered, we’ve sent a link to reset your password.</div>
    <div class="links"><a href="SignIn.php">Back to sign in</a></div>

  <?php else: ?>
    <form method="post" novalidate autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input class="input" type="email" name="email" placeholder="Email address" required maxlength="190">
      <div class="btn-row">
        <button class="btn" type="submit">Send Reset Link</button>
        <a class="btn ghost" href="SignIn.php">Back</a>
      </div>
    </form>
  <?php endif; ?>
</div></div>

<script>
function copyResetUrl() {
  var input = document.getElementById('resetUrl');
  var btn   = document.getElementById('copyBtn');
  input.select();
  input.setSelectionRange(0, 99999);
  try {
    document.execCommand('copy');
    btn.textContent = 'Copied';
    btn.classList.add('copy-done');
    setTimeout(function(){ btn.textContent='Copy'; btn.classList.remove('copy-done'); }, 1500);
  } catch(e) {
    // Fallback: keep selection so user can Cmd/Ctrl+C
  }
}
</script>
</body>
</html>
