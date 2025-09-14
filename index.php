<?php
// index.php — PlayerPath (home) with Top-5 cover URLS + pop/zoom previews
declare(strict_types=1);

/* -------------------------------------------------------
   Cache busting for auth-sensitive pages
-------------------------------------------------------- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* -------------------------------------------------------
   Includes
-------------------------------------------------------- */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/* Fallback HTML escaper if your stack doesn't already define `h()` */
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/* -------------------------------------------------------
   DEV: show errors while you debug (remove in prod)
-------------------------------------------------------- */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* -------------------------------------------------------
   Helpers
-------------------------------------------------------- */

/** Validate a single POSTed URL field. */
function pp_validate_cover_from_post(string $field): array {
  $raw = trim((string)($_POST[$field] ?? ''));
  if ($raw === '') return ['', null]; // keep existing if blank

  if (strlen($raw) > 255)                       return ['', 'too long (max 255 characters).'];
  if (!preg_match('~^https?://~i', $raw))       return ['', 'must start with http:// or https://'];
  if (filter_var($raw, FILTER_VALIDATE_URL)===false) return ['', 'is not a valid URL'];
  return [$raw, null];
}

/** Cheap table-exists helper (so we can fallback cleanly). */
function table_exists(PDO $pdo, string $name): bool {
  try {
    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) { return false; }
}

/* -------------------------------------------------------
   Current user (if any)
-------------------------------------------------------- */
$uid         = $_SESSION['user_id']      ?? null;
$displayName = $_SESSION['display_name'] ?? null;

$errors = [];

/* -------------------------------------------------------
   POST actions
-------------------------------------------------------- */

/** Logout (POST + CSRF) */
if ($uid && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
  csrf_check($_POST['csrf'] ?? '');

  logout();
  flash('Signed out.');
  header('Location: index.php');
  exit;
}

/** Save profile (POST + CSRF) */
if ($uid && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
  csrf_check($_POST['csrf'] ?? '');

  // Pull current covers (so we keep them if user doesn't provide a new URL)
  $stmt = $pdo->prepare(
    'SELECT game1_cover, game2_cover, game3_cover, game4_cover, game5_cover
       FROM user_profiles WHERE user_id = ?'
  );
  $stmt->execute([$uid]);
  $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'game1_cover' => '',
    'game2_cover' => '',
    'game3_cover' => '',
    'game4_cover' => '',
    'game5_cover' => '',
  ];

  $platform = trim((string)($_POST['platform'] ?? ''));
  $game1    = trim((string)($_POST['game1'] ?? ''));
  $game2    = trim((string)($_POST['game2'] ?? ''));
  $game3    = trim((string)($_POST['game3'] ?? ''));
  $game4    = trim((string)($_POST['game4'] ?? ''));
  $game5    = trim((string)($_POST['game5'] ?? ''));
  $bio      = trim((string)($_POST['bio'] ?? ''));

  $len = static function (string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
  };

  if ($len($platform) > 60) {
    $errors[] = 'Platform too long.';
  }
  foreach (['game1' => $game1, 'game2' => $game2, 'game3' => $game3, 'game4' => $game4, 'game5' => $game5] as $k => $v) {
    if ($len($v) > 80) $errors[] = strtoupper($k) . ' is too long.';
  }
  if ($len($bio) > 2000) {
    $errors[] = 'Bio is too long.';
  }

  // Validate cover URLs (optional, only overwrite if a non-empty valid URL is provided)
  $covers = $current;
  foreach ([1,2,3,4,5] as $i) {
    [$url, $err] = pp_validate_cover_from_post("game{$i}_cover");
    if ($err)  $errors[] = "Game #$i cover: $err";
    if ($url)  $covers["game{$i}_cover"] = $url; // blank means keep current
  }

  if (!$errors) {
    $sql = 'INSERT INTO user_profiles
              (user_id, platform, game1, game2, game3, game4, game5,
               game1_cover, game2_cover, game3_cover, game4_cover, game5_cover,
               bio, updated_at)
            VALUES
              (?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
            ON DUPLICATE KEY UPDATE
              platform    = VALUES(platform),
              game1       = VALUES(game1),
              game2       = VALUES(game2),
              game3       = VALUES(game3),
              game4       = VALUES(game4),
              game5       = VALUES(game5),
              game1_cover = VALUES(game1_cover),
              game2_cover = VALUES(game2_cover),
              game3_cover = VALUES(game3_cover),
              game4_cover = VALUES(game4_cover),
              game5_cover = VALUES(game5_cover),
              bio         = VALUES(bio),
              updated_at  = NOW()';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      $uid, $platform, $game1, $game2, $game3, $game4, $game5,
      $covers['game1_cover'], $covers['game2_cover'], $covers['game3_cover'], $covers['game4_cover'], $covers['game5_cover'],
      $bio
    ]);

    flash('Profile updated.');
    header('Location: index.php');
    exit;
  }
}

/* -------------------------------------------------------
   Load profile for display
-------------------------------------------------------- */
$profile = null;

if ($uid) {
  $stmt = $pdo->prepare(
    'SELECT platform, game1, game2, game3, game4, game5,
            game1_cover, game2_cover, game3_cover, game4_cover, game5_cover,
            bio, updated_at
       FROM user_profiles
      WHERE user_id = ?'
  );
  $stmt->execute([$uid]);

  // FIX: remove stray trailing spaces in keys so lookups work
  $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'platform'    => '',
    'game1'       => '',
    'game2'       => '',
    'game3'       => '',
    'game4'       => '',
    'game5'       => '',
    'game1_cover' => '',
    'game2_cover' => '',
    'game3_cover' => '',
    'game4_cover' => '',
    'game5_cover' => '',
    'bio'         => '',
    'updated_at'  => null,
  ];
}

$favorites = [];
if ($uid && $profile) {
  $favorites = array_values(array_filter([
    $profile['game1'] ?? '',
    $profile['game2'] ?? '',
    $profile['game3'] ?? '',
    $profile['game4'] ?? '',
    $profile['game5'] ?? '',
  ]));
}

/* -------------------------------------------------------
   NEW: User-focused metrics (safe fallbacks)
-------------------------------------------------------- */
$metrics = [
  'xp' => 0,
  'streak' => 0,
  'credit' => 0,
  'questions' => 0,
  'accepted' => 0,
  'clips' => 0,
];

// If logged in, hydrate metrics
if ($uid) {
  // 1) Try user_stats first (if you have it)
  if (table_exists($pdo, 'user_stats')) {
    $stmt = $pdo->prepare('SELECT xp, streak_days, credit_points, clips_count FROM user_stats WHERE user_id = ?');
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $metrics['xp']     = (int)($row['xp'] ?? 0);
      $metrics['streak'] = (int)($row['streak_days'] ?? 0);
      $metrics['credit'] = (int)($row['credit_points'] ?? 0);
      $metrics['clips']  = (int)($row['clips_count'] ?? 0);
    }
  }

  // 2) Always compute what we can from core tables
  if (table_exists($pdo, 'questions')) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE user_id = ?');
    $stmt->execute([$uid]);
    $metrics['questions'] = (int)$stmt->fetchColumn();
  }

  if (table_exists($pdo, 'answers')) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM answers WHERE user_id = ? AND is_accepted = 1');
    $stmt->execute([$uid]);
    $metrics['accepted'] = (int)$stmt->fetchColumn();
  }

  if (table_exists($pdo, 'clips') && $metrics['clips'] === 0) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM clips WHERE user_id = ?');
    $stmt->execute([$uid]);
    $metrics['clips'] = (int)$stmt->fetchColumn();
  }
}

// XP -> progress bar (cap to 0–1000 per level window)
$xpGoal = 1000;
$xpNow  = max(0, (int)$metrics['xp']);
$xpPct  = max(0, min(100, round(($xpNow % $xpGoal) * 100 / $xpGoal)));

/* -------------------------------------------------------
   NEW: Trending questions — TOP 3 by MOST UPVOTES
   (falls back gracefully if tables are missing)
-------------------------------------------------------- */
$trending = [];
if (table_exists($pdo, 'questions')) {
  if (table_exists($pdo, 'answers')) {
    // Keep answer counts for display, but sort by question score (upvotes)
    $sql = "SELECT q.id, q.title, q.created_at, q.score,
                   COALESCE(COUNT(a.id),0) AS answers
              FROM questions q
              LEFT JOIN answers a ON a.question_id = q.id
             GROUP BY q.id
             ORDER BY q.score DESC, q.created_at DESC
             LIMIT 3";
  } else {
    $sql = "SELECT q.id, q.title, q.created_at, q.score, 0 AS answers
              FROM questions q
             ORDER BY q.score DESC, q.created_at DESC
             LIMIT 3";
  }
  $trending = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="Cache-Control" content="no-store" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>PlayerPath — Home</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Orbitron:wght@700;800&display=swap" rel="stylesheet">

  <style>
    /* ========= Theme ========= */ 
    :root{
      --brand:#e60023;

      --bg:#0e0e10;
      --surface:#131315;
      --surface-2:#191a1d;
      --soft:#17181b;
      --tag:#1d1e22;
      --border:#2a2b31;

      --ink:#f4f5f7;
      --ink-dim:#b8bcc4;

      --radius:14px;

      --shadow-1:0 1px 1px rgba(0,0,0,.35), 0 10px 24px rgba(0,0,0,.45);
      --shadow-2:0 2px 8px rgba(0,0,0,.45), 0 20px 48px rgba(0,0,0,.55);

      --ring:0 0 0 3px rgba(230,0,35,.28);
    }

    /* ========= Base ========= */
    *{ box-sizing: border-box; }

    html, body{
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      font-size: clamp(15px, 0.45vw + 14px, 16.5px);
      line-height: 1.6;
      -webkit-text-size-adjust: 100%;
      text-rendering: optimizeLegibility;
    }

    a{ color: inherit; text-decoration: none; }
    img{ max-width: 100%; display: block; }

    a:focus, button:focus{ outline: 0; box-shadow: none; border-radius: 8px; }
    a:focus-visible, button:focus-visible{ box-shadow: var(--ring); }

    /* Inputs: no blue glow; brand-red focus */
    input.input:focus,
    textarea.input:focus { outline: none; border-color: var(--brand); }
    input.input:focus-visible,
    textarea.input:focus-visible { box-shadow: var(--ring); border-color: var(--brand); }

    /* Scrollbars */
    *::-webkit-scrollbar{ width: 10px; height: 10px; }
    *::-webkit-scrollbar-track{ background: #0b0b0d; }
    *::-webkit-scrollbar-thumb{ background: #2a2b31; border-radius: 8px; border: 2px solid #0b0b0d; }
    *{ scrollbar-color: #2a2b31 #0b0b0d; }

    /* ========= Top bar ========= */
    .top{ background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 30; }
    .top-inner{ height: 74px; display: flex; align-items: center; gap: 16px; max-width: 1440px; margin: 0 auto; padding: 0 20px; }

    .brand{ display: flex; align-items: center; gap: .6rem; font-family: Orbitron, Inter, sans-serif; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; font-size: clamp(1.4rem, 1.1vw + 1rem, 1.7rem); line-height: 1; }
    .brand .half-red  { color: var(--brand); }
    .brand .half-white{ color: #fff; letter-spacing: 1px; text-shadow: 0 0 6px rgba(230,0,35,.45), 0 1px 2px rgba(0,0,0,.65); }

    .user-welcome{ color: var(--ink-dim); font-size: .98rem; }

    /* ========= Buttons ========= */
    .btn{ border: none; border-radius: 10px; padding: 9px 14px; font-weight: 700; cursor: pointer; font-size: .95rem; line-height: 1; min-height: 40px; min-width: 40px; }
    .btn.primary{ background: var(--brand); color: #fff; box-shadow: 0 8px 22px rgba(230,0,35,.28); transition: transform .12s ease, filter .15s ease, box-shadow .2s ease; }
    .btn.primary:hover{ transform: translateY(-1px); filter: brightness(1.05); box-shadow: var(--shadow-2); }
    .btn.ghost{ background: #0f0f12; color: #f1f2f4; border: 1px solid #24252a; }
    .btn.logout{ background: var(--brand); color: #fff; border: 1px solid #b31329; padding: 10px 16px; border-radius: 12px; font-weight: 800; letter-spacing: .3px; box-shadow: 0 8px 22px rgba(230,0,35,.28); transition: transform .12s ease, filter .15s ease, box-shadow .2s ease; min-height: 40px; }
    .btn.logout:hover{ transform: translateY(-1px); filter: brightness(1.05); }
    .btn.cta-lg{ font-size: .98rem; padding: 11px 18px; min-height: 42px; }

    /* ========= Layout ========= */
    .app{ display: grid; grid-template-columns: 220px minmax(0,1fr) 320px; grid-template-areas: "nav main rail"; gap: 22px; max-width: 1440px; padding: 22px 20px 36px; margin: 0 auto; align-items: start; }
    @media (max-width:1280px){ .app{ grid-template-columns: 200px minmax(0,1fr) 280px; gap: 20px; } }
    @media (max-width:1220px){ .app{ grid-template-columns: 200px minmax(0,1fr); grid-template-areas: "nav main" "rail rail"; } }
    @media (max-width:980px){ .app{ grid-template-columns: 1fr; grid-template-areas: "nav" "main" "rail"; } }

    /* Left nav */
    .lnav{ grid-area: nav; border: 1px solid var(--brand); padding: 18px 10px 22px 18px; display: flex; flex-direction: column; gap: 6px; background: var(--bg); border-radius: 14px; align-self: start; position: sticky; top: 22px; max-height: calc(100vh - 44px); overflow: auto; }
    .lnav a{ display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; color: var(--ink-dim); font-size: .98rem; min-height: 40px; }
    .lnav a:hover, .lnav a[aria-current="page"]{ background: #141416; color: #fff; font-weight: 700; outline: 1px solid #232326; box-shadow: 0 0 0 1px rgba(230,0,35,.15) inset; }
    .lnav-divider{ margin: 12px 0; border-top: 1px solid var(--brand); }

    /* Cards & sections */
    .card, .hero, .tile, .xp, .feed{ border-radius: var(--radius); background: var(--surface); box-shadow: var(--shadow-1); }
    .card{ border: 1px solid var(--brand); padding: 16px; margin-bottom: 16px; }
    .hero{ grid-area: main; border: 1px solid var(--brand); padding: 22px 24px; display: flex; gap: 18px; align-items: center; }
    .hero h1{ font-size: clamp(1.2rem, 1vw + 1rem, 1.6rem); line-height: 1.2; margin: 0 0 8px; }
    .hero p{ font-size: .98rem; color: var(--ink); }
    .section-title{ font: 800 clamp(1rem, .7vw + .8rem, 1.15rem)/1 Inter, sans-serif; letter-spacing: .2px; margin: 18px 0 8px; }

    /* Tiles + XP bar */
    .mtiles{ display: grid; grid-template-columns: repeat(3, minmax(220px,1fr)); grid-auto-rows: 1fr; gap: 16px; }
    @media (max-width:900px){ .mtiles{ grid-template-columns: repeat(2, minmax(200px,1fr)); } }
    @media (max-width:580px){ .mtiles{ grid-template-columns: 1fr; } }
    .tile{ border: 1px solid #2f3036; padding: 18px 18px 16px; display: flex; flex-direction: column; justify-content: space-between; min-height: 110px; }
    .tile .k{ color: var(--ink-dim); font-size: .95rem; margin-bottom: 8px; }
    .tile .v{ font-weight: 800; font-size: clamp(1.2rem, 1vw + .9rem, 1.45rem); }
    .tile.brand .v{ color: var(--brand); }
    .xp{ border: 1px solid #ffffff; padding: 18px; margin-top: 18px; }
    .xpbar{ height: 16px; border-radius: 999px; background: #0f0f11; overflow: hidden; box-shadow: inset 0 0 0 4px #23242a; }
    .xpfill{ height: 100%; width: 0; border-radius: 999px; background: var(--brand); }

    /* Right rail & feed */
    .rail{ grid-area: rail; }
    .feed{ border: 1px solid var(--border); padding: 14px 16px; margin-top: 8px; display: block; box-sizing: border-box; font-size: .98rem; }
    .empty{ margin: 0; padding: 6px 2px; color: var(--ink-dim); font-style: italic; }

    /* ========= Forms + messages ========= */
    .input{ width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #2a2b31; background: #0f0f12; color: #f4f5f7; font-size: .98rem; min-height: 40px; }
    .notice{ background: #1c1c1f; border: 1px solid #3a3a3d; color: #fff; padding: 10px 12px; border-radius: 10px; margin: 14px 0; font-size: .98rem; }
    .error{ background: #2b0f14; border: 1px solid #5c0d18; color: #ffd7dd; padding: 10px 12px; border-radius: 10px; margin: 14px 0; font-size: .98rem; }

    /* ========= Profile card ========= */
    .profile-card{ border: 1px solid var(--brand); padding: 18px; border-radius: var(--radius); background: var(--surface); box-shadow: var(--shadow-1); }
    .profile-card .header{ display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
    .profile-card .muted{ color: var(--ink-dim); font-size: .95rem; }
    .profile-card .label{ color: #f4f5f7; font-weight: 800; letter-spacing: .3px; margin: 0 0 6px; font-size: .96rem; }
    .profile-card .games{ display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
    @media (max-width:980px){ .profile-card .games{ grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); } }
    @media (max-width:640px){ .profile-card .games{ grid-template-columns: 1fr; } }

    .cover-wrap{ position: relative; border: 1px solid #2a2b31; background: #0f0f12; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,.35); aspect-ratio: 16 / 9; transition: transform .18s ease, box-shadow .25s ease, border-color .18s ease; will-change: transform; margin-bottom: 12px; }
    .cover-wrap:is(:hover,:focus-within){ transform: translateY(-2px); border-color: #3a3b41; box-shadow: 0 10px 32px rgba(230,0,35,.18), 0 6px 18px rgba(0,0,0,.6); }
    .cover-img{ width: 100%; height: 100%; object-fit: cover; transition: transform .25s ease, filter .25s ease; }
    .cover-wrap:hover .cover-img{ transform: scale(1.06); filter: saturate(1.08) contrast(1.04); }
    .cover-hint{ position: absolute; bottom: 8px; right: 10px; background: rgba(14,14,16,.55); border: 1px solid rgba(255,255,255,.1); color: #fff; font-size: .72rem; padding: 4px 8px; border-radius: 999px; pointer-events: none; backdrop-filter: blur(6px); }
    .cover-empty{ border: 1px dashed #2a2b31; border-radius: 14px; background: #0f0f12; height: 0; aspect-ratio: 16 / 9; display:flex; align-items:center; justify-content:center; color:#6b6f78; font-size:.9rem; margin-bottom: 12px; }

    .profile-card .input{ min-height: 40px; }
    .profile-card textarea.input{ min-height: 80px; resize: vertical; }
    .grid-2{ display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width:900px){ .grid-2{ grid-template-columns: 1fr; } }
    .profile-card .actions{ display: flex; justify-content: flex-end; margin-top: 14px; }

    footer{ border-top: 1px solid var(--border); background: var(--surface); margin-top: 36px; }
    .foot{ height: 76px; display: flex; align-items: center; justify-content: space-between; max-width: 1440px; margin: 0 auto; padding: 0 20px; color: var(--ink-dim); font-size: .98rem; }

    /* ===== Lightbox ===== */
    .lightbox{ position: fixed; inset: 0; background: rgba(0,0,0,.82); display: none; align-items: center; justify-content: center; padding: 28px; z-index: 1000; animation: lbFade .18s ease; }
    .lightbox.show{ display: flex; }
    .lightbox img{ max-width: min(94vw, 1280px); max-height: 90vh; border-radius: 16px; box-shadow: 0 12px 36px rgba(0,0,0,.6), 0 4px 16px rgba(230,0,35,.25); }
    .lb-close{ position: absolute; top: 14px; right: 16px; font-size: 28px; line-height: 1; color: #fff; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.18); border-radius: 12px; padding: 6px 10px 8px; cursor: pointer; min-height: 40px; min-width: 40px; }
    @keyframes lbFade{ from{ opacity:.6 } to{ opacity:1 } }

    /* ===== Trending row (fixed ranking) ===== */
    .trend-link{ text-decoration:none; color:inherit; }
    .trend-row{
      display:grid;
      grid-template-columns: 24px 1fr auto; /* rank | main | upvotes */
      gap:12px;
      align-items:center;
      border-top:1px solid #23242a;
      padding:10px 2px;
    }
    .rank{
      color: var(--brand);
      font-weight: 900;
      text-align: center;
      width: 24px;
    }
    .trend-main{ min-width:0; }
    .trend-title{ font-weight:800; line-height:1.35; }
    .trend-sub{ color:var(--ink-dim); font-size:.92rem; }
    .upvotes{
      background:#fff;
      border:2px solid var(--brand);
      color:var(--brand);
      font-weight:800;
      padding:2px 10px;
      border-radius:999px;
      font-size:.85rem;
      white-space:nowrap;
    }
  </style>
</head>
<body>

  <!-- ====== Top bar ====== -->
  <header class="top">
    <div class="top-inner">

      <div class="brand">
        <span class="half-red">PLAYER</span>
        <span class="half-white">PATH</span>
      </div>

      <div style="margin-left:auto;display:flex;gap:10px;align-items:center">
        <?php if (!$uid): ?>

          <a href="SignUp.php">
            <button class="btn ghost" type="button">Sign Up</button>
          </a>

          <a href="SignIn.php">
            <button class="btn ghost" type="button">Sign In</button>
          </a>

        <?php else: ?>

          <div class="user-welcome">
            Welcome, <strong style="color:#fff"><?php echo h($displayName); ?></strong>
          </div>

          <!-- Secure POST logout -->
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf"   value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn logout" type="submit" aria-label="Log out">Log Out</button>
          </form>

        <?php endif; ?>
      </div>

    </div>
  </header>

  <!-- ====== Main layout ====== -->
  <div class="app">

    <!-- Left Nav -->
    <nav class="lnav" aria-label="Sections">
      <a href="#" aria-current="page">Home</a>
      <a href="questions.php">Questions</a>
      <a href="#">Tags</a>
      <a href="#">Leaderboard</a>
      <a href="Profiles.php">Profiles</a>

      <div class="lnav-divider"></div>

      <a href="top5.php">My Top 5 Games</a>
      <a href="#">Playbooks</a>
      <a href="#">Challenges</a>
      <a href="#">Guides</a>
    </nav>

    <!-- Main Content -->
    <main class="content">

      <?php if ($msg = flash()): ?>
        <div class="notice"><?php echo $msg; ?></div>
      <?php endif; ?>

      <!-- Hero -->
      <section class="hero" aria-label="Intro">

        <div>
          <h1 style="margin:0 0 8px">
            <span style="color:var(--brand);font-family:Orbitron">PLAYER</span>
            <span style="color:#fff;font-family:Orbitron;text-shadow:0 0 6px rgba(230,0,35,.45)">PATH</span>
            — ask, share, and level up your game.
          </h1>

          <p>
            Share game clips, guides, playbooks, and breakdowns—organized by title, genre, and skill.
            Each game hub begins with player questions, where the community responds with answers that
            can be upvoted, highlighted as the best answer, or confirmed as proven solutions. Every
            contribution—whether posting a clip, asking a question, or providing an answer—earns XP,
            keeps your streaks alive, and pushes you up the leaderboard. PlayerPath personalizes the
            experience by spotlighting your Top 5 favorite games, concentrating the biggest rewards
            and toughest competition on the clips, questions, and solutions that matter most to you.
            Show your skills, help others improve, and build your reputation as you rise through the
            PlayerPath community.
          </p>
        </div>

        <div class="actions" style="margin-left:auto;display:flex;flex-direction:column;gap:10px">
          <a href="Profiles.php" class="btn primary cta-lg">PlayerPath Community Questions</a>
          <button class="btn ghost"   type="button">Browse Tags</button>
        </div>

      </section>

      <!-- Profile (only logged-in) -->
      <?php if ($uid): ?>

        <h2 class="section-title"></h2>

        <?php if ($errors): ?>
          <div class="error"><?php echo implode('<br>', array_map('h', $errors)); ?></div>
        <?php endif; ?>

        <div class="profile-card">

          <div class="header">
            <div style="font-weight:800">Profile Card</div>
            <div class="muted">
              <?php
                $when = $profile['updated_at'] ? date('Y-m-d H:i', strtotime((string)$profile['updated_at'])) : null;
                echo $when ? 'Last updated: ' . h($when) : 'Not updated yet';
              ?>
            </div>
          </div>

          <form method="post" novalidate>
            <input type="hidden" name="csrf"   value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_profile">

            <!-- Platform / Bio -->
            <div class="grid-2">
              <div>
                <div class="label">Platform</div>
                <input class="input"
                       type="text"
                       name="platform"
                       maxlength="60"
                       placeholder="PC / PS5 / Xbox / Switch"
                       value="<?php echo h($profile['platform'] ?? ''); ?>">
              </div>

              <div>
                <div class="label">Bio</div>
                <textarea class="input"
                          name="bio"
                          rows="3"
                          placeholder="Brief gaming bio (mains, genres, goals)"><?php echo h($profile['bio'] ?? ''); ?></textarea>
              </div>
            </div>

            <!-- Top 5 Games + Cover URLs -->
            <div style="margin-top:14px">
              <div class="label">Top 5 Games (paste an image URL for each)</div>

              <div class="games">
                <?php for ($i = 1; $i <= 5; $i++):
                  $gName  = "game{$i}";
                  $cName  = "game{$i}_cover";
                  $gVal   = h($profile[$gName]   ?? '');
                  $cover  = $profile[$cName]     ?? '';
                  $hasImg = $cover && is_string($cover);
                ?>
                <div>
                  <input class="input" type="text" name="<?php echo $gName; ?>" maxlength="80"
                         placeholder="Game #<?php echo $i; ?>" value="<?php echo $gVal; ?>" style="margin-bottom:8px">

                  <?php if ($hasImg): ?>
                    <div class="cover-wrap" role="button" tabindex="0" aria-label="Open cover image" data-zoomsrc="<?php echo h($cover); ?>">
                      <img id="prev-<?php echo $i; ?>" class="cover-img" loading="lazy" src="<?php echo h($cover); ?>" alt="Game #<?php echo $i; ?> cover">
                      <span class="cover-hint">Click to zoom</span>
                    </div>
                  <?php else: ?>
                    <div class="cover-empty" data-empty-for="<?php echo $i; ?>">Preview</div>
                  <?php endif; ?>

                  <input class="input" type="url" name="<?php echo $cName; ?>" maxlength="255"
                         placeholder="https://example.com/cover.jpg"
                         oninput="ppPreview(this, <?php echo $i; ?>)"
                         value="<?php echo h($cover ?? ''); ?>">
                </div>
                <?php endfor; ?>
              </div>
            </div>

            <div class="actions">
              <button class="btn primary" type="submit">Save Profile</button>
            </div>

          </form>

        </div>
      <?php endif; ?>

      <!-- Snapshot -->
      <h2 class="section-title">
        <span style="display:inline-block;font-weight:800;font-size:.9rem;color:#f4f5f7;background:#141416;border:1px solid #23242a;padding:6px 10px;border-radius:10px">
          Your Snapshot
        </span>
      </h2>

      <section class="mtiles" aria-label="Metrics">
        <div class="tile brand"><div class="k">XP</div>               <div class="v" id="xp-v"><?php echo (int)$metrics['xp']; ?></div></div>
        <div class="tile">       <div class="k">Streaks</div>         <div class="v" id="streak-v"><?php echo (int)$metrics['streak']; ?></div></div>
        <div class="tile">       <div class="k">Credit Points</div>   <div class="v" id="credit-v"><?php echo (int)$metrics['credit']; ?></div></div>
        <div class="tile">       <div class="k">Questions</div>       <div class="v" id="q-v"><?php echo (int)$metrics['questions']; ?></div></div>
        <div class="tile">       <div class="k">Accepted Answers</div><div class="v" id="acc-v"><?php echo (int)$metrics['accepted']; ?></div></div>
        <div class="tile">       <div class="k">Game Clips</div>      <div class="v" id="clips-v"><?php echo (int)$metrics['clips']; ?></div></div>
      </section>

      <!-- XP -->
      <section class="xp" aria-label="Progress">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
          <div style="font-weight:800">Progress to next level</div>
          <div style="color:var(--ink-dim);font-size:.95rem" id="xpnote"><?php echo ($xpNow % $xpGoal) . ' / ' . number_format($xpGoal) . ' XP'; ?></div>
        </div>
        <div class="xpbar"><div class="xpfill" id="xpbar" style="width: <?php echo $xpPct; ?>%"></div></div>
      </section>

      <!-- Feed -->
      <h2 class="section-title">Trending Questions</h2>

      <section class="feed" id="feed" aria-live="polite">
        <?php if (!$trending): ?>
          <div class="empty" id="empty-feed">No questions yet. Be the first to ask.</div>
        <?php else: ?>
          <div id="rows">
            <?php $rank = 1; foreach ($trending as $q): ?>
              <!-- Jump directly to the exact question block on Profiles.php -->
              <a href="<?php echo 'Profiles.php' . (int)$q['id']; ?>" class="trend-link">
                <div class="trend-row">
                  <div class="rank"><?php echo $rank; ?></div>
                  <div class="trend-main">
                    <div class="trend-title"><?php echo h($q['title']); ?></div>
                    <div class="trend-sub">
                      <?php echo (int)$q['answers']; ?> answer<?php echo ((int)$q['answers']===1?'':'s'); ?>
                      • asked <?php echo h(date('Y-m-d H:i', strtotime((string)$q['created_at']))); ?>
                    </div>
                  </div>
                  <div class="upvotes"><?php echo (int)$q['score']; ?> ↑</div>
                </div>
              </a>
            <?php $rank++; endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    </main>

    <!-- Right rail -->
    <aside class="rail" aria-label="Sidebar">
      <div class="card">
        <h3 style="margin:0 0 10px;font-size:1rem">Best Answer System</h3>
        <div style="color:var(--ink-dim)">When an answer hits the threshold, the row shows a red check and the “answers” tile gets highlighted.</div>
      </div>

      <div class="card">
        <h3 style="margin:0 0 10px;font-size:1rem">Quality Checklist</h3>
        <div style="color:var(--ink-dim)">Title • Game & tags • What you tried • Why it failed • Expected result.</div>
      </div>

      <div class="card">
        <h3 style="margin:0 0 10px;font-size:1rem">Playbooks</h3>
        <div style="color:var(--ink-dim)">Turn accepted answers into drills for repeatable practice.</div>
      </div>
    </aside>

  </div>

  <!-- Lightbox -->
  <div id="lightbox" class="lightbox" role="dialog" aria-modal="true" aria-label="Image preview" tabindex="-1">
    <button class="lb-close" type="button" aria-label="Close preview" onclick="ppCloseLightbox()">×</button>
    <img id="lightbox-img" alt="">
  </div>

  <!-- Footer -->
  <footer>
    <div class="foot">
      <div>
        © <span id="yr"></span>
        <span style="font-family:Orbitron;font-weight:800">
          <span style="color:var(--brand)">PLAYER</span><span style="color:#fff">PATH</span>
        </span>
      </div>

      <nav>
        <a href="#">About</a> &nbsp;•&nbsp; <a href="#">How It Works</a> &nbsp;•&nbsp; <a href="#">FAQ</a>
      </nav>
    </div>
  </footer>

  <script>
    /* Small UX helpers */
    document.getElementById('yr').textContent = new Date().getFullYear();

    // Ensure fresh state when navigating back/forward
    window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

    // Live preview for URL fields
    function ppPreview(input, idx){
      const val = (input.value || '').trim();
      if (!val || !(val.startsWith('http://') || val.startsWith('https://'))) return;

      // If an <img id="prev-#"> exists, update it.
      let img = document.getElementById('prev-' + idx);
      if (img) {
        img.src = val;
        img.closest('.cover-wrap')?.setAttribute('data-zoomsrc', val);
        return;
      }

      // Otherwise, upgrade the ".cover-empty" into a .cover-wrap with image
      const empty = document.querySelector('.cover-empty[data-empty-for="'+idx+'"]');
      if (empty) {
        const wrap = document.createElement('div');
        wrap.className = 'cover-wrap';
        wrap.setAttribute('role','button');
        wrap.setAttribute('tabindex','0');
        wrap.setAttribute('aria-label','Open cover image');
        wrap.setAttribute('data-zoomsrc', val);

        img = document.createElement('img');
        img.className = 'cover-img';
        img.id = 'prev-' + idx;
        img.loading = 'lazy';
        img.alt = 'Game #'+idx+' cover';
        img.src = val;

        const hint = document.createElement('span');
        hint.className = 'cover-hint';
        hint.textContent = 'Click to zoom';

        wrap.appendChild(img);
        wrap.appendChild(hint);
        empty.replaceWith(wrap);
      }
    }

    // Lightbox logic
    const lb = document.getElementById('lightbox');
    const lbImg = document.getElementById('lightbox-img');

    function ppOpenLightbox(src){
      if (!src) return;
      lbImg.src = src;
      lb.classList.add('show');
      document.body.style.overflow = 'hidden';
      lb.focus();
    }
    function ppCloseLightbox(){
      lb.classList.remove('show');
      lbImg.src = '';
      document.body.style.overflow = '';
    }
    lb.addEventListener('click', (e) => {
      if (e.target === lb) ppCloseLightbox();
    });
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') ppCloseLightbox();
    });

    // Delegate clicks to open zoom from any .cover-wrap
    document.addEventListener('click', (e) => {
      const wrap = e.target.closest('.cover-wrap');
      if (!wrap) return;
      const src = wrap.getAttribute('data-zoomsrc');
      if (src) ppOpenLightbox(src);
    });

    // Keyboard accessibility: Enter/Space on .cover-wrap
    document.addEventListener('keydown', (e) => {
      const wrap = e.target.closest?.('.cover-wrap');
      if (!wrap) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        const src = wrap.getAttribute('data-zoomsrc');
        if (src) ppOpenLightbox(src);
      }
    });
  </script>
</body>
</html>
