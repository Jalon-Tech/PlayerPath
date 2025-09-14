<?php
// Profiles.php — PlayerPath: profile cards + inline Q/A + profile editing + community message box + profile wall
declare(strict_types=1);

/* ===== Dev errors (remove in prod) ===== */
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/* ---------- helpers ---------- */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
if (!function_exists('base_path')) {
  function base_path(): string {
    $d = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return ($d === '/' ? '' : $d);
  }
}
if (!function_exists('url')) {
  function url(string $path): string { return base_path() . '/' . ltrim($path, '/'); }
}
function time_ago(string $datetime): string {
  $ts = strtotime($datetime); if ($ts === false) return $datetime;
  $diff = time() - $ts;
  if ($diff < 60)    return $diff.'s ago';
  if ($diff < 3600)  return floor($diff/60).'m ago';
  if ($diff < 86400) return floor($diff/3600).'h ago';
  if ($diff < 604800)return floor($diff/86400).'d ago';
  return date('M j, Y', $ts);
}
function rows(PDO $pdo, string $sql, array $bind = []): array {
  $st = $pdo->prepare($sql); $st->execute($bind);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function one(PDO $pdo, string $sql, array $bind = [], $def=null) {
  $st = $pdo->prepare($sql); $st->execute($bind);
  $v = $st->fetchColumn(); return $v === false ? $def : $v;
}

/* ---------- auth ---------- */
$uid         = $_SESSION['user_id']      ?? null;
$displayName = $_SESSION['display_name'] ?? null;

/* ---------- ensure tables ---------- */
try {
  // community feed
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS community_messages (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      body TEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (user_id),
      INDEX (created_at, id),
      CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  // per-profile wall
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS profile_wall_messages (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      target_user_id INT UNSIGNED NOT NULL,
      author_user_id INT UNSIGNED NOT NULL,
      body TEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (target_user_id, created_at, id),
      INDEX (author_user_id),
      CONSTRAINT fk_pwm_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_pwm_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
} catch (Throwable $t) { /* ignore — page still renders read-only */ }

/* ---------- POST actions ---------- */
$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    csrf_check($_POST['csrf'] ?? '');

    /* ---- community message ---- */
    if ($action === 'post_message') {
      if (!$uid) throw new RuntimeException('Sign in to post.');
      $body = trim((string)($_POST['body'] ?? ''));
      if ($body === '') throw new RuntimeException('Message cannot be empty.');
      if ((function_exists('mb_strlen') ? mb_strlen($body) : strlen($body)) > 1000) {
        throw new RuntimeException('Message is too long (≤ 1000 chars).');
      }
      $last = rows($pdo, "SELECT created_at FROM community_messages WHERE user_id=? ORDER BY id DESC LIMIT 1", [$uid]);
      if ($last && (time() - strtotime($last[0]['created_at'])) < 10) {
        throw new RuntimeException('You are posting too fast. Please wait a few seconds.');
      }
      $st = $pdo->prepare("INSERT INTO community_messages (user_id, body) VALUES (?, ?)");
      $st->execute([$uid, $body]);
      header('Location: '.url('Profiles.php#msgbox')); exit;
    }

    /* ---- profile wall: post a note ---- */
    if ($action === 'post_wall') {
      if (!$uid) throw new RuntimeException('Sign in to post.');
      $target = (int)($_POST['target_user_id'] ?? 0);
      if ($target <= 0) throw new RuntimeException('Invalid profile.');
      $body = trim((string)($_POST['body'] ?? ''));
      if ($body === '') throw new RuntimeException('Message cannot be empty.');
      if ((function_exists('mb_strlen') ? mb_strlen($body) : strlen($body)) > 500) {
        throw new RuntimeException('Message is too long (≤ 500 chars).');
      }
      $last = rows($pdo,
        "SELECT created_at FROM profile_wall_messages WHERE author_user_id=? AND target_user_id=? ORDER BY id DESC LIMIT 1",
        [$uid, $target]
      );
      if ($last && (time()-strtotime($last[0]['created_at'])) < 10) {
        throw new RuntimeException('You are posting too fast. Please wait a few seconds.');
      }
      $pdo->prepare("INSERT INTO profile_wall_messages (target_user_id, author_user_id, body) VALUES (?,?,?)")
          ->execute([$target, $uid, $body]);
      header('Location: '.url('Profiles.php#u-'.$target.'-wall')); exit;
    }

    /* ---- voting ---- */
    if ($action === 'vote_question') {
      if (!$uid) throw new RuntimeException('Sign in to vote.');
      $qid = (int)$_POST['question_id'] ?? 0;
      $val = (int)$_POST['value'] ?? 0;
      if (!in_array($val, [-1,1], true)) throw new RuntimeException('Invalid vote.');
      if ($qid <= 0) throw new RuntimeException('Invalid question.');
      $qOwner = (int)one($pdo,"SELECT user_id FROM questions WHERE id=? LIMIT 1",[$qid],0);
      if ($qOwner === (int)$uid) throw new RuntimeException('You cannot vote on your own question.');
      $pdo->beginTransaction();
      try {
        $pdo->prepare("INSERT INTO votes (user_id,entity_type,entity_id,value)
                       VALUES (:u,'question',:id,:v)
                       ON DUPLICATE KEY UPDATE value=VALUES(value)")
            ->execute([':u'=>$uid, ':id'=>$qid, ':v'=>$val]);
        try {
          $sum = (int)one($pdo,"SELECT COALESCE(SUM(value),0) FROM votes WHERE entity_type='question' AND entity_id=?",[$qid],0);
          $pdo->prepare("UPDATE questions SET score=:s, updated_at=NOW() WHERE id=:id")
              ->execute([':s'=>$sum, ':id'=>$qid]);
        } catch (Throwable $ignore) {}
        $pdo->commit();
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $notice = 'Voting isn’t enabled yet (add a `votes` table).';
      }
      header('Location: '.($_POST['return'] ?? url('Profiles.php'))); exit;
    }

    if ($action === 'vote_answer') {
      if (!$uid) throw new RuntimeException('Sign in to vote.');
      $aid = (int)$_POST['answer_id'] ?? 0;
      $val = (int)$_POST['value'] ?? 0;
      if (!in_array($val, [-1,1], true)) throw new RuntimeException('Invalid vote.');
      if ($aid <= 0) throw new RuntimeException('Invalid answer.');
      $aOwner = (int)one($pdo,"SELECT user_id FROM answers WHERE id=? LIMIT 1",[$aid],0);
      if ($aOwner === (int)$uid) throw new RuntimeException('You cannot vote on your own answer.');
      $pdo->beginTransaction();
      try {
        $pdo->prepare("INSERT INTO votes (user_id,entity_type,entity_id,value)
                       VALUES (:u,'answer',:id,:v)
                       ON DUPLICATE KEY UPDATE value=VALUES(value)")
            ->execute([':u'=>$uid, ':id'=>$aid, ':v'=>$val]);
        try {
          $pdo->prepare("UPDATE answers SET updated_at=NOW() WHERE id=:a")->execute([':a'=>$aid]);
          $pdo->prepare("UPDATE questions q JOIN answers a ON a.question_id=q.id SET q.updated_at=NOW() WHERE a.id=:a")->execute([':a'=>$aid]);
        } catch (Throwable $ignore) {}
        $pdo->commit();
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $notice = 'Voting isn’t enabled yet (add a `votes` table).';
      }
      header('Location: '.($_POST['return'] ?? url('Profiles.php'))); exit;
    }

    /* ---- create answer from Profiles ---- */
    if ($action === 'create_answer') {
      if (!$uid) throw new RuntimeException('Sign in to answer.');
      $qid  = (int)($_POST['question_id'] ?? 0);
      $body = trim((string)($_POST['body'] ?? ''));
      if ($qid <= 0) $errors[] = 'Invalid question.';
      if ($body === '') $errors[] = 'Answer cannot be empty.';
      $qOwner = 0;
      if (!$errors) {
        $qOwner = (int)one($pdo,"SELECT user_id FROM questions WHERE id=? LIMIT 1",[$qid],0);
        if ($qOwner === 0) $errors[] = 'Question not found.';
      }
      if (!$errors && $qOwner === (int)$uid) $errors[] = 'You cannot answer your own question.';
      if (!$errors) {
        $last = rows($pdo,"SELECT created_at FROM answers WHERE user_id=? ORDER BY id DESC LIMIT 1",[$uid]);
        if ($last && (time()-strtotime($last[0]['created_at'])) < 20) $errors[]='You just posted—try again in a few seconds.';
      }
      if (!$errors) {
        $pdo->prepare("INSERT INTO answers (question_id,user_id,body) VALUES (?,?,?)")
            ->execute([$qid,$uid,$body]);
        $pdo->prepare("UPDATE questions SET updated_at=NOW() WHERE id=?")->execute([$qid]);
        header('Location: '.url('questions.php#q-'.$qid.'-answers')); exit;
      }
    }

  } catch (Throwable $ex) {
    $errors[] = $ex->getMessage();
  }
}

/* ---------- score expressions ---------- */
function score_q_expr(PDO $pdo): string {
  try {
    $c = one($pdo,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='score' LIMIT 1",[],null);
    if ($c) return 'q.score';
    $v = one($pdo,"SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='votes' LIMIT 1",[],null);
    if ($v) return "(SELECT COALESCE(SUM(v.value),0) FROM votes v WHERE v.entity_type='question' AND v.entity_id=q.id)";
  } catch (Throwable $t) {}
  return '0';
}
function score_a_expr(PDO $pdo): string {
  try {
    $c = one($pdo,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='answers' AND COLUMN_NAME='score' LIMIT 1",[],null);
    if ($c) return 'a.score';
    $v = one($pdo,"SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='votes' LIMIT 1",[],null);
    if ($v) return "(SELECT COALESCE(SUM(v.value),0) FROM votes v WHERE v.entity_type='answer' AND v.entity_id=a.id)";
  } catch (Throwable $t) {}
  return '0';
}

/* ---------- data ---------- */
$profileSql = "
  SELECT
    u.id AS uid, COALESCE(u.display_name, CONCAT('User #', u.id)) AS display_name,
    up.platform, up.bio,
    up.game1, up.game1_cover,
    up.game2, up.game2_cover,
    up.game3, up.game3_cover,
    up.game4, up.game4_cover,
    up.game5, up.game5_cover,
    up.updated_at,
    (SELECT COUNT(*) FROM questions WHERE user_id = u.id) AS q_count,
    (SELECT COUNT(*) FROM answers   WHERE user_id = u.id) AS a_count
  FROM users u
  LEFT JOIN user_profiles up ON up.user_id = u.id
  ORDER BY u.display_name ASC, u.id ASC
  LIMIT 200";
$profiles = rows($pdo, $profileSql);

/* recent Q/A for each user */
$qExpr = score_q_expr($pdo);
$aExpr = score_a_expr($pdo);

$recentQ = rows($pdo,"SELECT q.id, q.user_id, q.title, $qExpr AS score, q.created_at
                      FROM questions q ORDER BY q.created_at DESC LIMIT 800");
$qByUser=[]; $allQIds=[];
foreach ($recentQ as $r) {
  $u = (int)$r['user_id'];
  if (!isset($qByUser[$u])) $qByUser[$u] = [];
  $qByUser[$u][] = $r;
  $allQIds[(int)$r['id']] = true;
}

$recentA = rows($pdo,"
  SELECT
    a.id, a.user_id, a.question_id, $aExpr AS score, a.is_accepted, a.created_at,
    q.title AS q_title, q.user_id AS q_owner,
    COALESCE(uo.display_name, CONCAT('User #', uo.id)) AS q_owner_name
  FROM answers a
  JOIN questions q ON q.id = a.question_id
  JOIN users uo ON uo.id = q.user_id
  ORDER BY a.created_at DESC
  LIMIT 800
  ");
$aByUser=[]; $allAIds=[];
foreach ($recentA as $r) {
  $u = (int)$r['user_id'];
  if (!isset($aByUser[$u])) $aByUser[$u] = [];
  $aByUser[$u][] = $r;
  $allAIds[(int)$r['id']] = true;
}

/* community messages feed — DM style (oldest → newest) */
$messages = [];
try {
  $messages = rows(
    $pdo,
    "SELECT m.id, m.user_id, m.body, m.created_at, COALESCE(u.display_name, CONCAT('User #', u.id)) AS display_name
     FROM community_messages m
     JOIN users u ON u.id = m.user_id
     ORDER BY m.created_at ASC, m.id ASC
     LIMIT 100"
  );
} catch (Throwable $t) { /* ignore */ }

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta http-equiv="Cache-Control" content="no-store" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>PlayerPath — Profiles</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Orbitron:wght@700;800&display=swap" rel="stylesheet">

<style>
.accepted-label { font-weight: 800; font-size: 1rem; color: var(--brand); }
.accepted-label .check { font-size: 1.1rem; margin-right: 4px; }
.accepted-icon svg{ width:12px; height:12px; fill:#fff; }

:root{
  --brand:#e60023;
  --bg:#0e0e10; --surface:#131315; --surface-2:#191a1d; --tag:#1d1e22; --border:#2a2b31;
  --ink:#f4f5f7; --ink-dim:#b8bcc4; --ring:0 0 0 3px rgba(230,0,35,.28);
  --white:#fff; --foot-h:76px; --foot-gap:36px;
}
*{ box-sizing:border-box }
html, body{ margin:0; background:var(--bg); color:var(--ink); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-size:clamp(14px,.42vw+13.5px,16px); line-height:1.6; }
a{ color:inherit; text-decoration:none }
a:focus-visible,button:focus-visible{ box-shadow: var(--ring) }

/* 🔴 Global red focus for all form fields */
:where(input, textarea, select, .input, .wall-input, .msg-input, #answerBody):focus{
  outline: none !important;
  border-color: var(--brand) !important;
  box-shadow: 0 0 0 3px rgba(230,0,35,.28) !important;
  caret-color: var(--brand);
}

/* top bar */
.top{ background:var(--surface); border-bottom:1px solid var(--border); position:sticky; top:0; z-index:30 }
.top-inner{ height:70px; display:flex; align-items:center; gap:16px; max-width:1440px; margin:0 auto; padding:0 22px }
.brand{ display:flex; gap:.6rem; font-family:Orbitron, Inter, sans-serif; font-weight:800; letter-spacing:.8px; text-transform:uppercase; font-size:clamp(1.2rem, 1.02vw + 1rem, 1.5rem) }
.brand .half-red{ color:var(--brand) } .brand .half-white{ color:#fff; text-shadow:0 0 6px rgba(230,0,35,.45) }

.btn{ border:none; border-radius:12px; padding:8px 14px; font-weight:700; cursor:pointer; font-size:.95rem; min-height:36px }
.btn.ghost{ background:#0f0f12; color:#f1f2f4; border:1px solid #24252a }
.btn.logout,.btn.primary{ background:var(--brand); color:#fff; box-shadow:0 6px 18px rgba(230,0,35,.28) }
.btn.white-red{ background:#fff; color:var(--brand); border:1px solid var(--brand) }
.btn[disabled]{ opacity:.5; cursor:not-allowed }

/* app grid + left spine */
.app{ display:grid; grid-template-columns:240px minmax(0,1fr); grid-template-areas:"nav main"; gap:22px; max-width:1440px; padding:22px; margin:0 auto; align-items:start; }
@media (max-width:980px){ .app{ grid-template-columns:1fr; grid-template-areas:"nav" "main" } }

.lnav{ grid-area:nav; border:2px solid #fff; padding:16px 12px 16px 16px; display:flex; flex-direction:column; gap:6px; background:var(--bg); border-radius:16px; position:sticky; top:22px; max-height:calc(100vh - 44px); overflow:auto; }
.lnav a{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:12px; color:#fff; font-size:.98rem; opacity:.82 }
.lnav a:hover,.lnav a[aria-current="page"]{ background:#141416; color:#fff; font-weight:700; outline:1px solid #232326; opacity:1 }

/* main column & vertical timeline */
.content{ grid-area:main; position:relative; padding-left:28px; }
.content::before{
  content:"";
  position:absolute; left:6px; top:0; bottom:var(--foot-gap);
  width:3px; background:var(--brand); border-radius:3px;
  box-shadow:0 0 0 1px rgba(230,0,35,.25);
}
@media (max-width:980px){ .content{ padding-left:18px } .content::before{ left:4px } }

.page-head{ display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:12px }
.h1{ font:800 1.12rem/1.1 Inter, sans-serif; }

/* ===== Profile Wall controls ===== */
.wall-input{ flex:1; min-height:72px; resize:vertical; padding:14px 16px; border-radius:12px; border:1px solid #2a2b31; background:#0f0f12; color:#f4f5f7; font-size:1rem; line-height:1.5; }
.wall-btn{ padding:12px 16px; border-radius:12px; border:1px solid var(--brand); background:#fff; color:var(--brand); font-size:1rem; font-weight:800; cursor:pointer; }

/* =========================
   Community Message Box
   ========================= */
.msgboard{
  position:relative; background:#fff; color:#111;
  border:2px solid var(--brand); border-radius:18px; padding:0;
  box-shadow:0 14px 36px rgba(0,0,0,.3); overflow:hidden; margin:16px 0 26px;
}
.msg-head{
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding:14px 16px; background:var(--brand); color:#fff;
}
.msg-head h3{ margin:0; font-size:1.2rem; font-weight:800; letter-spacing:.25px; }
.msg-sub{ margin:3px 0 0; color:rgba(255,255,255,.9); font-size:1rem; }

/* Composer */
.msg-form{
  padding:14px 16px; display:flex; gap:10px; align-items:flex-start;
  background:#fafafa; border-bottom:1px solid #ececf0;
}
/* 🔴 row reacts in red when focused */
.msg-form:focus-within{ outline:2px solid var(--brand); outline-offset:-2px; border-bottom-color:var(--brand); }

.msg-input{
  flex:1; min-height:76px; resize:vertical; padding:12px 14px; border-radius:12px;
  border:1px solid #d8dbe5; background:#fff; color:#111; font-size:1rem; line-height:1.5;
}
.msg-input::placeholder{ color:#9aa1b2; }
/* 🔴 input focus is red */
.msg-input:focus{ outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(230,0,35,.28); }
.msg-submit{
  padding:12px 16px; border-radius:12px; border:none;
  background:var(--brand); color:#fff; font-weight:800; cursor:pointer;
  box-shadow:0 8px 20px rgba(230,0,35,.28);
}

/* Thread */
.msg-list{
  padding:16px; background:#fff;
  display:flex; flex-direction:column; gap:12px;
  max-height:460px; overflow:auto;
}
.msg-list::-webkit-scrollbar{ height:10px; width:10px }
.msg-list::-webkit-scrollbar-thumb{ background:#e5e7ee; border-radius:10px; border:2px solid #fff }
.msg-list::-webkit-scrollbar-track{ background:#fff }

/* Row */
.msg-item{ display:flex; align-items:flex-start; }
.msg-item.mine{ justify-content:flex-end; }

/* Hide old outside username chips (keeps markup backward-compatible) */
.msg-avatar{ display:none !important; }

/* Bubble */
.bubble{
  position:relative; max-width:76%;
  background:#fff;
  border:2px solid rgba(230,0,35,.55); /* others = subtle red */
  border-radius:14px; padding:12px 14px;
  box-shadow:0 6px 14px rgba(16,20,28,.08);
}
.msg-item.mine .bubble{
  border-color:var(--brand);           /* yours = strong red */
  box-shadow:0 0 0 3px rgba(230,0,35,.28) inset;
}
.bubble .msg-meta{ display:flex; align-items:center; gap:8px; font-size:.95rem; color:#5a6273; line-height:1.2; }
.bubble .msg-name{ font-weight:800; color:#1c2434; }
.bubble .msg-body{ color:#0f1420; font-size:1rem; line-height:1.55; white-space:pre-wrap; overflow-wrap:anywhere; margin-top:6px; }

/* Remove gray accent bar */
.msg-item:not(.mine) .bubble::before{ display:none !important; }

/* Mine → red highlight */
.msg-item.mine .bubble{
  background:#fff;
  border:2px solid var(--brand);      /* strong red outline */
  border-radius:14px;
  box-shadow:0 0 0 3px rgba(230,0,35,.28) inset;
}

/* Accent bar for OTHER users (subtle visibility) */
.msg-item:not(.mine) .bubble::before{
  content:""; position:absolute; left:-5px; top:12px; width:3px; height:22px;
  background:#d7deee; border-radius:2px; opacity:.95;
}

/* Mine → right align, brand pill, clearer outline */
.msg-item.mine{ justify-content:flex-end; }
.msg-item.mine .msg-avatar{ order:2; }
.msg-item.mine .msg-meta .msg-name::after{
  content:"YOU"; margin-left:6px; padding:2px 8px;
  background:var(--brand); color:#fff; border-radius:999px; font-weight:800; font-size:.78rem; letter-spacing:.2px;
}
.msg-item.mine .bubble{
    background:#fff;
    border:2px solid var(--brand);      /* strong red outline */
    border-radius:14px;
    box-shadow:0 0 0 3px rgba(230,0,35,.28) inset;
}

/* Responsiveness for bubbles */
@media (max-width:780px){
  .bubble{ max-width:100%; }
}

/* ===== Profile Card ===== */
.p-card{
  background:#121214; border:2px solid #fff; border-radius:18px;
  padding:14px; margin:18px 0 26px; box-shadow:0 10px 24px rgba(0,0,0,.28);
}
.p-card:hover{ box-shadow:0 14px 36px rgba(0,0,0,.36), 0 0 0 3px rgba(255,255,255,.06) inset; }

.p-top{ display:grid; grid-template-columns:1fr 460px; gap:14px; align-items:stretch; }
@media (max-width:1100px){ .p-top{ grid-template-columns:1fr; } }
.p-head{ border:2px solid var(--brand); border-radius:14px; padding:10px 12px; background:transparent; }
.p-title{ margin:0; font:800 1.22rem/1.25 Inter, sans-serif }
.p-sub{ color:#cfd3db; font-size:.9rem; margin:2px 0 8px }
.tags{ margin-top:8px; display:flex; flex-wrap:wrap; gap:6px }
.count-pill{ display:inline-flex; align-items:center; gap:6px; background:var(--white); color:var(--brand); border:1px solid var(--brand); border-radius:999px; font-weight:800; padding:4px 10px; line-height:1; font-size:.86rem; }
.tag{ display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border-radius:999px; background:#1d1e22; border:1px solid #2a2b31; color:#d7dae2; font-size:.72rem }
.platform{ color:#bfc4ce; font-size:.95rem }
.bio{ color:#f1f3f6; white-space:pre-wrap; overflow-wrap:anywhere; margin-top:6px }

/* Edit my profile (inline) */
.edit{ background:#0f1014; border:1px solid #24252a; border-radius:12px; padding:10px; margin-top:10px; }
.grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
@media (max-width:900px){ .grid-2{ grid-template-columns:1fr; } }
.input{ width:100%; padding:10px 12px; border-radius:10px; border:1px solid #2a2b31; background:#0f0f12; color:#f4f5f7; min-height:38px; }
textarea.input{ min-height:80px; resize:vertical; }

/* Profile Wall */
.wall { background:#0f1014; border:1px solid #24252a; border-radius:12px; padding:12px; margin-top:12px; }
.wall h3 { margin:0 0 6px; font-size:.98rem; letter-spacing:.2px; }
.wall-form { display:flex; gap:8px; align-items:flex-start; margin-top:8px; }
.wall-list { display:flex; flex-direction:column; gap:10px; margin-top:10px; max-height:360px; overflow:auto; }
.wall-item { display:flex; gap:12px; align-items:flex-start; background:#101114; border:1px solid #23242a; border-radius:12px; padding:12px; }
.wall-name { font-weight:800; color:#fff; }
.wall-meta { color:#aeb3bc; font-size:.95rem; margin-left:6px; }
.wall-body { color:#f1f3f6; font-size:1rem; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere; margin-top:4px; }
.wall-avatar { min-width:max-content; padding:6px 12px; border-radius:999px; background:linear-gradient(135deg,#17181f,#e60023); color:#fff; font-weight:800; font-size:1rem; }

/* Activity area */
.blocks{ display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:14px }
@media (max-width:980px){ .blocks{ grid-template-columns:1fr; } }
.block{ background:#0f1014; border:1px solid #24252a; border-radius:12px; padding:10px; }
.block h3{ margin:0 0 6px; font-size:.98rem; letter-spacing:.2px; }

/* Compact list items with vote column */
.li{ display:grid; grid-template-columns:54px minmax(0,1fr) auto; gap:10px; align-items:start; padding:8px; border:1px solid #23242a; border-radius:10px; background:#101114; }
.li + .li{ margin-top:8px }
.vote{ display:flex; flex-direction:column; align-items:center; gap:6px; user-select:none; }
.vote .arrow{ width:30px; height:30px; border-radius:8px; border:1px solid #2a2b31; background:#0f0f12; color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; cursor:pointer }
.vote .arrow.active{ outline:2px solid rgba(230,0,35,.45) }
.vote .arrow[disabled]{ opacity:.45; cursor:not-allowed }
.vote .score{ background:var(--white); color:var(--brand); border:1px solid var(--brand); border-radius:12px; font-weight:800; font-size:.88rem; padding:2px 8px; line-height:1; }

.li .title{ font-weight:800; line-height:1.35; }
.li .meta{ color:#aeb3bc; font-size:.86rem; margin-top:2px }
.li .right{ display:flex; align-items:center; gap:8px }
.pill{ background:#fff; color:var(--brand); border:1px solid var(--brand); border-radius:999px; padding:2px 8px; font-weight:800; font-size:.82rem; }

/* Gallery */
.gallery{ background:#0f1014; border:1px solid #24252a; border-radius:12px; padding:12px; display:flex; flex-direction:column; align-self:stretch; }
.gallery h3{ margin:0 0 8px; font-size:1rem; letter-spacing:.2px }
.games{ display:flex; flex-direction:column; gap:12px }
.game{ background:#0f1016; border:1px solid #2a2b31; border-radius:14px; padding:10px; display:flex; flex-direction:column; gap:8px; }
.cover{ position:relative; border:1px solid #2a2b31; border-radius:12px; overflow:hidden; background:#0f0f12; height:200px; display:flex; align-items:center; justify-content:center; color:#6b6f78; }
.cover img{ width:100%; height:100%; object-fit:cover; }
.badge-date{ position:absolute; top:8px; right:8px; background:#fff; color:#111; border:1px solid #2a2b31; border-radius:10px; padding:2px 8px; font-weight:800; font-size:.78rem; line-height:1; opacity:.95 }
.caption{ margin-top:2px; color:#fff; font-weight:800; font-size:.98rem; text-align:left; }

/* Footer */
footer{ border-top:1px solid var(--border); background:var(--surface); margin-top:var(--foot-gap); }
.foot{ height:var(--foot-h); display:flex; align-items:center; justify-content:space-between; max-width:1440px; margin:0 auto; padding:0 20px; color:#b8bcc4; font-size:.98rem; }
.foot a{ color:#b8bcc4; text-decoration:none; margin:0 6px; }
.foot a:hover{ color:#fff; }
</style>
</head>
<body>

<header class="top">
  <div class="top-inner">
    <div class="brand"><span class="half-red">PLAYER</span><span class="half-white">PATH</span></div>
    <div style="margin-left:auto;display:flex;gap:10px;align-items:center">
      <?php if (!$uid): ?>
        <a href="<?= url('SignUp.php') ?>"><button class="btn ghost" type="button">Sign Up</button></a>
        <a href="<?= url('SignIn.php') ?>"><button class="btn ghost" type="button">Sign In</button></a>
      <?php else: ?>
        <div style="color:#b8bcc4">Welcome,&nbsp;<strong style="color:#fff"><?= e((string)($displayName ?? '')) ?></strong></div>
        <a href="<?= url('index.php') ?>"><button class="btn logout" type="button">Home</button></a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="app">
  <nav class="lnav" aria-label="Sections">
    <a href="<?= url('index.php') ?>">Home</a>
    <a href="<?= url('questions.php') ?>">Questions</a>
    <a href="#">Tags</a>
    <a href="#">Leaderboard</a>
    <a href="<?= url('Profiles.php') ?>" aria-current="page">Profiles</a>
    <div style="margin:10px 0; border-top:2px solid var(--brand)"></div>
    <a href="top5.php">My Top 5 Games</a>
    <a href="#">Playbooks</a>
    <a href="#">Challenges</a>
    <a href="#">Guides</a>
  </nav>

  <main class="content">
    <div class="page-head"><div class="h1">Community Profiles</div></div>

    <!-- ===== Community Message Box ===== -->
    <section id="msgbox" class="msgboard">
      <div class="msg-head">
        <div>
          <h3>Community Message Box</h3>
          <p class="msg-sub">Say hey, share a quick update, or link your new guide. Keep it respectful.</p>
        </div>
        <?php if (!$uid): ?>
          <div><a class="btn white-red" href="<?= url('SignIn.php') ?>">Sign in to post</a></div>
        <?php endif; ?>
      </div>

      <?php if ($uid): ?>
        <form class="msg-form" method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="post_message">
          <textarea class="msg-input" name="body" maxlength="1000" placeholder="Type a message for everyone to see…" required></textarea>
          <button class="msg-submit" type="submit">Post</button>
        </form>
      <?php endif; ?>

      <div class="msg-list">
      <?php if (!$messages): ?>
        <div class="msg-item">
          <div class="bubble">
            <div class="msg-meta"><span class="msg-name">PlayerPath</span><span>just now</span></div>
            <div class="msg-body">Welcome to the Community Message Box! Sign in to post your first message.</div>
          </div>
        </div>
      <?php else: foreach ($messages as $m):
        $mine = ($uid && (int)$m['user_id'] === (int)$uid) ? ' mine' : '';
      ?>
        <div class="msg-item<?= $mine ?>" id="m-<?= (int)$m['id'] ?>">
          <!-- keep avatar div for backward-compat (hidden by CSS) -->
          <div class="msg-avatar"><?= e((string)$m['display_name'] ?? 'User') ?></div>
          <div class="bubble">
            <div class="msg-meta">
              <span class="msg-name"><?= e((string)$m['display_name']) ?></span>
              <span><?= e(time_ago((string)$m['created_at'])) ?></span>
            </div>
            <div class="msg-body"><?= nl2br(e((string)$m['body'])) ?></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    
    </section>
    <!-- ===== /Community Message Box ===== -->

    <?php if ($notice): ?>
      <p style="margin:8px 0; padding:10px; border-radius:12px; background:#141416; border:1px solid #2a2b31; color:#ffd7dd"><?= e($notice) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $eMsg): ?>
      <p style="margin:8px 0; padding:10px; border-radius:12px; background:#2b0f14; border:1px solid #5c0d18; color:#ffd7dd"><?= e($eMsg) ?></p>
    <?php endforeach; ?>

    <?php if (empty($profiles)): ?>
      <section class="p-card">
        <div class="p-head"><h2 class="p-title">No profiles yet</h2></div>
        <div style="color:#ffd7dd">Ask users to fill out their profile card.</div>
      </section>
    <?php else: ?>

      <?php foreach ($profiles as $p):
        $uidRow     = (int)$p['uid'];
        $qs         = array_slice($qByUser[$uidRow] ?? [], 0, 3);
        $as         = array_slice($aByUser[$uidRow] ?? [], 0, 3);
        $updatedRaw = (string)($p['updated_at'] ?? '');
        $updatedAbs = $updatedRaw ? date('M j, Y', strtotime($updatedRaw)) : null;
        $updatedTip = $updatedRaw ? date('Y-m-d H:i:s', strtotime($updatedRaw)) : '';

        // Fetch this profile's wall
        $wall = [];
        try {
          $wall = rows(
            $pdo,
            "SELECT w.id, w.author_user_id, w.body, w.created_at,
                    COALESCE(u.display_name, CONCAT('User #', u.id)) AS author_name
             FROM profile_wall_messages w
             JOIN users u ON u.id = w.author_user_id
             WHERE w.target_user_id = ?
             ORDER BY w.created_at ASC, w.id ASC
             LIMIT 100",
            [$uidRow]
          );
        } catch (Throwable $t) { /* ignore */ }
      ?>
      <section class="p-card" id="<?= 'u-'.$uidRow ?>">
        <div class="p-top">
          <!-- Left: header + bio + (if me) editor + wall + activity -->
          <div>
            <div class="p-head">
              <h2 class="p-title"><?= e($p['display_name']) ?></h2>
              <div class="p-sub"><?= $updatedRaw ? 'Updated '.e(time_ago($updatedRaw)).' • '.e($updatedAbs) : 'No updates yet' ?></div>
              <div class="tags">
                <span class="count-pill"><strong><?= (int)$p['q_count'] ?></strong>&nbsp;Questions</span>
                <span class="count-pill"><strong><?= (int)$p['a_count'] ?></strong>&nbsp;Answers</span>
                <?php if (!empty($p['platform'])): ?><span class="tag">Platform: <?= e((string)$p['platform']) ?></span><?php endif; ?>
              </div>
              <?php if (!empty($p['bio'])): ?>
                <div class="bio"><?= nl2br(e((string)$p['bio'])) ?></div>
              <?php endif; ?>
            </div>

            <?php if ($uid && $uidRow === (int)$uid): ?>
              <div class="edit">
                <div style="color:#bfc4ce; font-weight:800; margin-bottom:6px">Edit my profile</div>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="save_profile">
                  <div class="grid-2">
                    <div>
                      <div style="font-weight:800;margin:4px 0">Platform</div>
                      <input class="input" type="text" name="platform" maxlength="60" value="<?= e((string)($p['platform'] ?? '')) ?>" placeholder="PC / PS5 / Xbox / Switch">
                    </div>
                    <div>
                      <div style="font-weight:800;margin:4px 0">Bio</div>
                      <textarea class="input" name="bio" maxlength="2000" placeholder="Brief gaming bio…"><?= e((string)($p['bio'] ?? '')) ?></textarea>
                    </div>
                  </div>
                  <div style="font-weight:800;margin:10px 0 6px">Top 5 (name & image URL)</div>
                  <div class="grid-2">
                    <?php for($i=1;$i<=5;$i++): ?>
                      <div>
                        <input class="input" type="text" name="game<?= $i ?>" maxlength="80" value="<?= e((string)($p['game'.$i] ?? '')) ?>" placeholder="Game #<?= $i ?>">
                        <input class="input" style="margin-top:6px" type="url" name="game<?= $i ?>_cover" maxlength="255" value="<?= e((string)($p['game'.$i.'_cover'] ?? '')) ?>" placeholder="https://example.com/cover.jpg">
                      </div>
                    <?php endfor; ?>
                  </div>
                  <div style="display:flex;justify-content:flex-end;margin-top:10px">
                    <button class="btn white-red" type="submit">Save My Profile</button>
                  </div>
                </form>
              </div>
            <?php endif; ?>

            <!-- Profile Wall -->
            <div class="wall" id="u-<?= $uidRow ?>-wall">
              <h3>Wall — Leave a note for <span class="wall-name"><?= e($p['display_name']) ?></span></h3>

              <?php if ($uid && (int)$uid !== $uidRow): ?>
                <form class="wall-form" method="post">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="post_wall">
                  <input type="hidden" name="target_user_id" value="<?= $uidRow ?>">
                  <textarea class="wall-input" name="body" maxlength="500" placeholder="Say hey, invite to a match, or drop a quick tip…" required></textarea>
                  <button class="wall-btn" type="submit">Post</button>
                </form>
              <?php elseif (!$uid): ?>
                <div style="margin-top:8px">
                  <a class="btn white-red" href="<?= url('SignIn.php') ?>">Sign in to leave a note</a>
                </div>
              <?php endif; ?>

              <div class="wall-list">
                <?php if (!$wall): ?>
                  <div class="wall-item">
                    <div class="wall-avatar">PP</div>
                    <div>
                      <div><span class="wall-name">PlayerPath</span><span class="wall-meta"> · just now</span></div>
                      <div class="wall-body">Be the first to write on this wall 👋</div>
                    </div>
                  </div>
                <?php else: foreach ($wall as $w): ?>
                  <div class="wall-item" id="w-<?= (int)$w['id'] ?>">
                    <div class="wall-avatar"><?= e((string)$w['author_name']) ?></div>
                    <div>
                      <div>
                        <span class="wall-name"><?= e((string)$w['author_name']) ?></span>
                        <span class="wall-meta">· <?= e(time_ago((string)$w['created_at'])) ?></span>
                      </div>
                      <div class="wall-body"><?= nl2br(e((string)$w['body'])) ?></div>
                    </div>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </div>

            <!-- Activity -->
            <div class="blocks">
              <!-- Recent Questions -->
              <div class="block">
                <h3>Recent Questions</h3>
                <?php if (!$qs): ?>
                  <div class="li"><div></div><div class="meta">No questions yet.</div><div></div></div>
                <?php else: foreach (array_slice($qs,0,3) as $q):
                  $qid = (int)$q['id'];
                  $isMyQ = $uid && ((int)$q['user_id'] === (int)$uid);
                  $myQ = $myVotesQ[$qid] ?? 0;
                ?>
                  <div class="li" id="q-<?= $qid ?>">
                    <div class="vote" aria-label="Question voting">
                      <?php if ($uid && !$isMyQ): ?>
                        <form method="post" style="margin:0">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="vote_question">
                          <input type="hidden" name="question_id" value="<?= $qid ?>">
                          <input type="hidden" name="value" value="1">
                          <input type="hidden" name="return" value="<?= e(url('Profiles.php#q-'.$qid)) ?>">
                          <button class="arrow <?= $myQ===1?'active':'' ?>" title="Upvote" type="submit">▲</button>
                        </form>
                      <?php elseif ($uid && $isMyQ): ?>
                        <button class="arrow" title="You cannot vote on your own question" disabled>▲</button>
                      <?php else: ?>
                        <a class="arrow" href="<?= url('SignIn.php') ?>" title="Sign in to upvote">▲</a>
                      <?php endif; ?>

                      <div class="score"><?= (int)($q['score'] ?? 0) ?></div>

                      <?php if ($uid && !$isMyQ): ?>
                        <form method="post" style="margin:0">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="vote_question">
                          <input type="hidden" name="question_id" value="<?= $qid ?>">
                          <input type="hidden" name="value" value="-1">
                          <input type="hidden" name="return" value="<?= e(url('Profiles.php#q-'.$qid)) ?>">
                          <button class="arrow <?= $myQ===-1?'active':'' ?>" title="Downvote" type="submit">▼</button>
                        </form>
                      <?php elseif ($uid && $isMyQ): ?>
                        <button class="arrow" title="You cannot vote on your own question" disabled>▼</button>
                      <?php else: ?>
                        <a class="arrow" href="<?= url('SignIn.php') ?>" title="Sign in to downvote">▼</a>
                      <?php endif; ?>
                    </div>

                    <div>
                      <div class="title"><a href="<?= url('questions.php#q-'.$qid) ?>"><?= e((string)$q['title']) ?></a></div>
                      <div class="meta">asked <?= e(time_ago((string)$q['created_at'])) ?></div>
                      <?php if ($uid && !$isMyQ): ?>
                        <div style="margin-top:6px">
                          <button class="btn white-red" type="button" onclick='openAnswer(<?= $qid ?>, <?= json_encode((string)$q["title"]) ?>)'>Answer</button>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="right"><span class="pill"><?= (int)($q['score'] ?? 0) ?></span></div>
                  </div>
                <?php endforeach; endif; ?>
              </div>

              <!-- Recent Answers -->
              <div class="block">
                <h3>Recent Answers</h3>
                <?php if (!$as): ?>
                  <div class="li"><div></div><div class="meta">No answers yet.</div><div></div></div>
                <?php else: foreach (array_slice($as,0,3) as $a):
                  $aid = (int)$a['id'];
                  $isMyA = $uid && ((int)$a['user_id'] === (int)$uid);
                  $myA = $myVotesA[$aid] ?? 0;
                  $accepted = !empty($a['is_accepted']);
                ?>
                  <div class="li" id="a-<?= $aid ?>">
                    <div class="vote" aria-label="Answer voting">
                      <?php if ($uid && !$isMyA): ?>
                        <form method="post" style="margin:0">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="vote_answer">
                          <input type="hidden" name="answer_id" value="<?= $aid ?>">
                          <input type="hidden" name="value" value="1">
                          <input type="hidden" name="return" value="<?= e(url('Profiles.php#a-'.$aid)) ?>">
                          <button class="arrow <?= $myA===1?'active':'' ?>" title="Upvote answer" type="submit">▲</button>
                        </form>
                      <?php elseif ($uid && $isMyA): ?>
                        <button class="arrow" title="You cannot vote on your own answer" disabled>▲</button>
                      <?php else: ?>
                        <a class="arrow" href="<?= url('SignIn.php') ?>" title="Sign in to upvote">▲</a>
                      <?php endif; ?>

                      <div class="score"><?= (int)($a['score'] ?? 0) ?></div>

                      <?php if ($uid && !$isMyA): ?>
                        <form method="post" style="margin:0">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="vote_answer">
                          <input type="hidden" name="answer_id" value="<?= $aid ?>">
                          <input type="hidden" name="value" value="-1">
                          <input type="hidden" name="return" value="<?= e(url('Profiles.php#a-'.$aid)) ?>">
                          <button class="arrow <?= $myA===-1?'active':'' ?>" title="Downvote answer" type="submit">▼</button>
                        </form>
                      <?php elseif ($uid && $isMyA): ?>
                        <button class="arrow" title="You cannot vote on your own answer" disabled>▼</button>
                      <?php else: ?>
                        <a class="arrow" href="<?= url('SignIn.php') ?>" title="Sign in to downvote">▼</a>
                      <?php endif; ?>
                    </div>

                    <div>
                      <div class="title">
                        To: <a href="<?= url('questions.php#q-'.(int)$a['question_id']) ?>"><?= e((string)($a['q_title'] ?? 'Question')) ?></a>
                    <?php if ($accepted): ?>
                      <span class="accepted-label"><span class="check">✔</span>Accepted by <?= e((string)($a['q_owner_name'] ?? 'OP')) ?></span>
                    <?php endif; ?>
                    </div>
                      <div class="meta">answered <?= e(time_ago((string)$a['created_at'])) ?></div>
                    </div>

                    <div class="right"><span class="pill"><?= (int)($a['score'] ?? 0) ?></span></div>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>

          <!-- Right: Top-5 (big vertical for ALL profiles) -->
          <aside class="gallery" aria-label="Top 5 games">
            <h3>Top&nbsp;5&nbsp;Games</h3>
            <div class="games">
              <?php for ($i=1;$i<=5;$i++): $g=$p["game{$i}"]??''; $c=$p["game{$i}_cover"]??''; ?>
                <div class="game">
                  <div class="cover" title="<?= e($g ?: ('Game #'.$i)) ?>">
                    <?php if ($c): ?><img loading="lazy" src="<?= e($c) ?>" alt="<?= e($g ?: ('Game #'.$i)) ?>"><?php else: ?>Game #<?= $i ?><?php endif; ?>
                    <?php if ($updatedAbs): ?>
                      <div class="badge-date" title="<?= e($updatedTip) ?>"><?= e($updatedAbs) ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="caption"><?= e($g ?: ('Game #'.$i)) ?></div>
                </div>
              <?php endfor; ?>
            </div>
          </aside>
        </div>
      </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>

<footer>
  <div class="foot">
    <div>© <span id="yr"></span>
      <span style="font-family:Orbitron;font-weight:800">
        <span style="color:var(--brand)">PLAYER</span><span style="color:#fff">PATH</span>
      </span>
    </div>
    <nav><a href="#">About</a> • <a href="#">How It Works</a> • <a href="#">FAQ</a></nav>
  </div>
</footer>

<?php if ($uid): ?>
<!-- Answer modal (identical to questions.php) -->
<div id="answerWrap" class="dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="answerTitle" style="position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;padding:16px;z-index:50">
  <div class="dialog" style="width:min(700px,100%);background:#131315;border:2px solid #fff;border-radius:18px;padding:16px">
    <h2 id="answerTitle" style="margin:0 0 10px;font-size:1rem;color:#fff">Answer</h2>
    <div id="answeringTitle" class="answering-title" style="margin:.35rem 0 .8rem;padding:.5rem .7rem;border:1px solid #34353d;border-radius:12px;background:#0f1116;color:#cfd3db;font-size:.9rem"></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_answer">
      <input type="hidden" id="answerQId" name="question_id" value="">
      <div class="field" style="margin-bottom:10px">
        <label for="answerBody" style="display:block;margin-bottom:6px">Your Answer</label>
        <textarea id="answerBody" name="body" placeholder="Share a helpful, detailed answer…" maxlength="20000" required style="width:100%;background:#0f0f12;border:1px solid #2b2c33;color:#cfd3db;padding:10px 12px;border-radius:12px;min-height:140px"></textarea>
      </div>
      <div class="row" style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn ghost" onclick="closeAnswer()">Cancel</button>
        <button class="btn white-red" type="submit">Post Answer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.getElementById('yr').textContent = new Date().getFullYear();

/* Auto-scroll message areas to the newest message (bottom) */
document.querySelectorAll('.msg-list, .wall-list').forEach(el=>{
  el.scrollTop = el.scrollHeight;
});

/* Answer modal controls */
const answerWrap   = document.getElementById('answerWrap');
const answerQId    = document.getElementById('answerQId');
const answeringDiv = document.getElementById('answeringTitle');

function openAnswer(id, title){
  if(!answerWrap) return;
  answerQId.value = id;
  const t = typeof title === 'string' ? title : String(title ?? '');
  const trimmed = t.length > 140 ? t.slice(0,137) + '…' : t;
  answeringDiv.textContent = 'You are answering: “' + trimmed + '”';
  answerWrap.style.display = 'flex';
  setTimeout(()=>document.getElementById('answerBody')?.focus(),0);
}
function closeAnswer(){ if(answerWrap){ answerWrap.style.display='none'; } }
</script>
<?php if (isset($_GET['focus'])): ?>
<script>
  (function(){
    const id = String(<?php echo (int)$_GET['focus']; ?>);
    const go = () => { location.hash = '#q-' + id; };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', go);
    } else { go(); }
  })();
</script>
<?php endif; ?>
</body>
</html>
