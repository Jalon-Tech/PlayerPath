<?php
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

/** check if comments.parent_id exists (optional) */
function comments_has_parent(PDO $pdo): bool {
  static $has = null;
  if ($has !== null) return $has;
  try {
    $st = $pdo->query("SHOW COLUMNS FROM comments LIKE 'parent_id'");
    $has = (bool)$st->fetch();
  } catch (Throwable $t) { $has = false; }
  return $has;
}

/** make an @handle from a display name */
function mention_handle(string $name): string {
  $h = strtolower($name);
  $h = preg_replace('/[^a-z0-9]+/','', $h ?? '');
  if ($h === '') $h = 'user';
  return '@'.$h;
}

/**
 * QUESTIONS PAGE
 */
$uid         = $_SESSION['user_id']      ?? null;
$displayName = $_SESSION['display_name'] ?? null;

$errors = [];
$notice = null;

/* -----------------------------
   POST actions
------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    csrf_check($_POST['csrf'] ?? '');

    if ($action === 'create_question') {
      if (!$uid) throw new RuntimeException('Sign in to ask a question.');
      $title = trim((string)($_POST['title'] ?? ''));
      $body  = trim((string)($_POST['body'] ?? ''));
      $len = function(string $s){ return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); };
      if ($title === '' || $len($title) > 140) $errors[] = 'Title is required (≤ 140 chars).';
      if ($body === '') $errors[] = 'Body is required.';
      if (!$errors) {
        $stmt = $pdo->prepare("SELECT created_at FROM questions WHERE user_id=:u ORDER BY id DESC LIMIT 1");
        $stmt->execute([':u'=>$uid]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
          if ((time() - strtotime((string)$row['created_at'])) < 60) $errors[] = 'Slow down—try again in a minute.';
        }
      }
      if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO questions (user_id, title, body) VALUES (:u,:t,:b)");
        $stmt->execute([':u'=>$uid, ':t'=>$title, ':b'=>$body]);
        $qid = (int)$pdo->lastInsertId();
        header('Location: '.url('questions.php#q-'.$qid)); exit;
      }
    }

    if ($action === 'vote_question') {
      if (!$uid) throw new RuntimeException('Sign in to vote.');
      $qid = (int)($_POST['question_id'] ?? 0);
      $val = (int)($_POST['value'] ?? 0);
      if (!in_array($val, [-1,1], true)) throw new RuntimeException('Invalid vote.');
      if ($qid <= 0) throw new RuntimeException('Invalid question.');
      $ownerQ = $pdo->prepare("SELECT user_id FROM questions WHERE id = :q LIMIT 1");
      $ownerQ->execute([':q'=>$qid]);
      $qOwner = (int)($ownerQ->fetchColumn() ?: 0);
      if ($qOwner === (int)$uid) throw new RuntimeException('You cannot vote on your own question.');
      $pdo->beginTransaction();
      try {
        $sql = "INSERT INTO votes (user_id, entity_type, entity_id, value)
                VALUES (:u,'question',:id,:v)
                ON DUPLICATE KEY UPDATE value=VALUES(value)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':u'=>$uid, ':id'=>$qid, ':v'=>$val]);
        $sum = $pdo->prepare("SELECT COALESCE(SUM(value),0) FROM votes WHERE entity_type='question' AND entity_id=:id");
        $sum->execute([':id'=>$qid]); $score = (int)$sum->fetchColumn();
        $upd = $pdo->prepare("UPDATE questions SET score=:s, updated_at=NOW() WHERE id=:id");
        $upd->execute([':s'=>$score, ':id'=>$qid]);
        $pdo->commit();
      } catch (Throwable $t) { $pdo->rollBack(); $notice = 'Voting isn’t enabled yet (add a votes table to turn it on).'; }
    }

    if ($action === 'vote_answer') {
      if (!$uid) throw new RuntimeException('Sign in to vote.');
      $aid = (int)($_POST['answer_id'] ?? 0);
      $val = (int)($_POST['value'] ?? 0);
      if (!in_array($val, [-1,1], true)) throw new RuntimeException('Invalid vote.');
      if ($aid <= 0) throw new RuntimeException('Invalid answer.');
      $ownerA = $pdo->prepare("SELECT user_id FROM answers WHERE id = :a LIMIT 1");
      $ownerA->execute([':a'=>$aid]);
      $aOwner = (int)($ownerA->fetchColumn() ?: 0);
      if ($aOwner === (int)$uid) throw new RuntimeException('You cannot vote on your own answer.');
      $pdo->beginTransaction();
      try {
        $sql = "INSERT INTO votes (user_id, entity_type, entity_id, value)
                VALUES (:u,'answer',:id,:v)
                ON DUPLICATE KEY UPDATE value=VALUES(value)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':u'=>$uid, ':id'=>$aid, ':v'=>$val]);
        $pdo->prepare("UPDATE answers SET updated_at = NOW() WHERE id = :a")->execute([':a'=>$aid]);
        $pdo->prepare("UPDATE questions q JOIN answers a ON a.question_id = q.id SET q.updated_at = NOW() WHERE a.id = :a")->execute([':a'=>$aid]);
        $pdo->commit();
      } catch (Throwable $t) { $pdo->rollBack(); $notice = 'Voting isn’t enabled yet (add a votes table to turn it on).'; }
    }

    if ($action === 'create_answer') {
      if (!$uid) throw new RuntimeException('Sign in to answer.');
      $qid  = (int)($_POST['question_id'] ?? 0);
      $body = trim((string)($_POST['body'] ?? ''));
      if ($qid <= 0) $errors[] = 'Invalid question.';
      if ($body === '') $errors[] = 'Answer cannot be empty.';
      $qOwner = 0;
      if (!$errors) {
        $chk = $pdo->prepare("SELECT user_id FROM questions WHERE id=:q LIMIT 1");
        $chk->execute([':q'=>$qid]); $qOwner = (int)($chk->fetchColumn() ?: 0);
        if ($qOwner === 0) $errors[] = 'Question not found.';
      }
      if (!$errors && $qOwner === (int)$uid) $errors[] = 'You cannot answer your own question.';
      if (!$errors) {
        $last = $pdo->prepare("SELECT created_at FROM answers WHERE user_id=:u ORDER BY id DESC LIMIT 1");
        $last->execute([':u'=>$uid]);
        if ($row = $last->fetch(PDO::FETCH_ASSOC)) {
          if ((time() - strtotime((string)$row['created_at'])) < 20) $errors[] = 'You just posted—try again in a few seconds.';
        }
      }
      if (!$errors) {
        $ins = $pdo->prepare("INSERT INTO answers (question_id, user_id, body) VALUES (:q,:u,:b)");
        $ins->execute([':q'=>$qid, ':u'=>$uid, ':b'=>$body]);
        $pdo->prepare("UPDATE questions SET updated_at = NOW() WHERE id = :q")->execute([':q' => $qid]);
        header('Location: '.url('questions.php#q-'.$qid.'-answers')); exit;
      }
    }

    if ($action === 'create_comment') {
      if (!$uid) throw new RuntimeException('Sign in to comment.');
      $aid = (int)$_POST['answer_id'] ?? 0;
      $qid = (int)$_POST['question_id'] ?? 0;
      $body = trim((string)($_POST['body'] ?? ''));
      $replyTo = (int)($_POST['reply_to'] ?? 0);

      if ($aid <= 0 || $qid <= 0) $errors[] = 'Invalid comment target.';
      if ($body === '') $errors[] = 'Comment cannot be empty.';
      if ((function_exists('mb_strlen') ? mb_strlen($body) : strlen($body)) > 2000) $errors[] = 'Comment too long (≤ 2000 chars).';

      $answerOwner = 0;
      if (!$errors) {
        $chk = $pdo->prepare("SELECT question_id, user_id FROM answers WHERE id=:a LIMIT 1");
        $chk->execute([':a'=>$aid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['question_id'] !== $qid) $errors[] = 'Answer not found for this question.';
        else $answerOwner = (int)$row['user_id'];
      }

      $useParent = comments_has_parent($pdo);
      $targetComment = null;
      if (!$errors && $replyTo > 0 && $useParent) {
        $cchk = $pdo->prepare("SELECT id, user_id FROM comments WHERE id=:c AND answer_id=:a LIMIT 1");
        $cchk->execute([':c'=>$replyTo, ':a'=>$aid]);
        $targetComment = $cchk->fetch(PDO::FETCH_ASSOC);
        if (!$targetComment) $errors[] = 'Cannot reply: comment not found.';
        else if ((int)$targetComment['user_id'] === (int)$uid) $errors[] = 'You cannot reply to your own comment.';
      }

      if (!$errors && $answerOwner === (int)$uid && $replyTo <= 0) {
        $errors[] = 'You can only comment on your own answer by replying to someone who commented on it.';
      }

      if (!$errors) {
        try {
          $pdo->beginTransaction();
          if ($useParent) {
            $pdo->prepare("INSERT INTO comments (answer_id, user_id, body, created_at, parent_id)
                           VALUES (:a,:u,:b, NOW(), :p)")
                ->execute([':a'=>$aid, ':u'=>$uid, ':b'=>$body, ':p'=>$replyTo ?: null]);
          } else {
            $pdo->prepare("INSERT INTO comments (answer_id, user_id, body, created_at)
                           VALUES (:a,:u,:b, NOW())")
                ->execute([':a'=>$aid, ':u'=>$uid, ':b'=>$body]);
          }
          $pdo->prepare("UPDATE answers SET updated_at = NOW() WHERE id = :a")->execute([':a'=>$aid]);
          $pdo->prepare("UPDATE questions q JOIN answers a ON a.question_id = q.id SET q.updated_at = NOW() WHERE a.id = :a")->execute([':a'=>$aid]);
          $pdo->commit();
        } catch (Throwable $t) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $notice = 'Comments aren’t enabled yet (add a comments table to enable them).';
        }
        header('Location: '.url('questions.php#a-'.$aid)); exit;
      }
    }

    if ($action === 'accept_answer') {
      if (!$uid) throw new RuntimeException('Sign in to accept an answer.');
      $aid = (int)($_POST['answer_id'] ?? 0);
      $qid = (int)($_POST['question_id'] ?? 0);
      if ($aid <= 0 || $qid <= 0) throw new RuntimeException('Invalid accept request.');

      $chkQ = $pdo->prepare("SELECT id, user_id FROM questions WHERE id = :q LIMIT 1");
      $chkQ->execute([':q'=>$qid]);
      $qrow = $chkQ->fetch(PDO::FETCH_ASSOC);
      if (!$qrow || (int)$qrow['user_id'] !== (int)$uid) throw new RuntimeException('Not authorized.');

      $chkA = $pdo->prepare("SELECT id, user_id FROM answers WHERE id = :a AND question_id = :q LIMIT 1");
      $chkA->execute([':a'=>$aid, ':q'=>$qid]);
      $aRow = $chkA->fetch(PDO::FETCH_ASSOC);
      if (!$aRow) throw new RuntimeException('Answer not found for this question.');
      $answerOwner = (int)$aRow['user_id'];
      if ($answerOwner === (int)$uid) throw new RuntimeException('You cannot accept your own answer.');

      try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE questions SET accepted_answer_id = :a, updated_at = NOW() WHERE id = :q")
            ->execute([':a'=>$aid, ':q'=>$qid]);
        try {
          $pdo->prepare("UPDATE answers SET is_accepted = 0 WHERE question_id = :q")->execute([':q'=>$qid]);
          $pdo->prepare("UPDATE answers SET is_accepted = 1 WHERE id = :a")->execute([':a'=>$aid]);
        } catch (Throwable $ignore) {}
        if ($answerOwner && $answerOwner !== (int)$uid) {
          try {
            $pdo->prepare("INSERT INTO notifications (user_id, actor_id, question_id, answer_id, type)
                           VALUES (:user, :actor, :q, :a, 'answer_accepted')")
                ->execute([':user'=>$answerOwner, ':actor'=>$uid, ':q'=>$qid, ':a'=>$aid]);
          } catch (Throwable $ignoreNotif) {}
        }
        $pdo->commit();
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Could not accept answer.';
      }
      header('Location: '.url('questions.php#q-'.$qid.'-answers')); exit;
    }

  } catch (Throwable $ex) {
    $errors[] = $ex->getMessage();
  }
}

/* -----------------------------
   Search / Sort / Feed  (UPGRADED)
   - If logged in, only show the logged-in user's questions
   - Answers/comments load for those questions as before
------------------------------*/
$sort = $_GET['sort'] ?? 'recent';
$orderSql = $sort === 'top' ? 'q.score DESC, q.created_at DESC' : 'q.updated_at DESC';

$search = trim((string)($_GET['q'] ?? ''));

// Build conditions array so we can combine "owner filter" and optional search cleanly
$conditions = [];
$params = [];

// If logged in: restrict feed to my questions only
if ($uid) {
  $conditions[] = 'q.user_id = :owner';
  $params[':owner'] = $uid;
}

// Optional search (scoped to my questions when logged in)
if ($search !== '') {
  $useFulltext = false;
  try {
    $idx = $pdo->query("SHOW INDEX FROM questions")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($idx as $i) {
      if (isset($i['Index_type']) && stripos((string)$i['Index_type'], 'FULLTEXT') !== false) { $useFulltext = true; break; }
      if (isset($i['Index_comment']) && stripos((string)$i['Index_comment'], 'FULLTEXT') !== false) { $useFulltext = true; break; }
    }
  } catch (Throwable $t) { /* ignore */ }

  if ($useFulltext) {
    $conditions[] = "MATCH(q.title, q.body) AGAINST(:q IN NATURAL LANGUAGE MODE)";
    $params[':q'] = $search;
  } else {
    $conditions[] = "(q.title LIKE :like OR q.body LIKE :like)";
    $params[':like'] = '%'.$search.'%';
  }
}

$myVoteSelect = '';
if ($uid) {
  $myVoteSelect = ",
    (SELECT v.value FROM votes v
      WHERE v.user_id = :me AND v.entity_type = 'question' AND v.entity_id = q.id
      LIMIT 1) AS my_vote_q";
  $params[':me'] = $uid;
}

$whereSql = $conditions ? ('WHERE '.implode(' AND ', $conditions)) : '';

try {
  $sql = "
  SELECT
    q.id, q.user_id, q.title, q.body, q.score, q.created_at, q.updated_at,
    q.accepted_answer_id,
    u.display_name AS question_display_name,
    (SELECT COUNT(*) FROM answers a WHERE a.question_id = q.id) AS answer_count
    $myVoteSelect
  FROM questions q
  LEFT JOIN users u ON u.id = q.user_id
  $whereSql
  ORDER BY $orderSql
  LIMIT 50";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {
  $rows = [];
  $errors[] = 'DB error: '.$t->getMessage();
}

/* -----------------------------
   Fetch answers + comments
------------------------------*/
$answersByQ = [];
$commentsByA = [];
$answerIds = [];

if (!empty($rows)) {
  $qids = array_map(fn($r) => (int)$r['id'], $rows);
  $qBind = []; $qPlace = [];
  foreach ($qids as $i => $qidVal) { $qPlace[] = ":q$i"; $qBind[":q$i"] = $qidVal; }
  $inQ = implode(',', $qPlace);

  try {
    $ansSql = "
      SELECT
        a.id, a.question_id, a.user_id, a.body, a.created_at,
        u.display_name AS answer_display_name,
        COALESCE((SELECT SUM(v.value) FROM votes v WHERE v.entity_type='answer' AND v.entity_id=a.id), 0) AS answer_score
        ".($uid ? ",
        (SELECT v2.value FROM votes v2 WHERE v2.user_id = :me AND v2.entity_type='answer' AND v2.entity_id=a.id LIMIT 1) AS my_vote_a" : "")."
      FROM answers a
      LEFT JOIN users u ON u.id = a.user_id
      WHERE a.question_id IN ($inQ)
      ORDER BY a.created_at ASC";
    $st = $pdo->prepare($ansSql);
    $bindAll = $qBind; if ($uid) { $bindAll[':me'] = $uid; }
    $st->execute($bindAll);
    while ($a = $st->fetch(PDO::FETCH_ASSOC)) {
      $qid = (int)$a['question_id']; $aid = (int)$a['id'];
      $answersByQ[$qid][] = $a; $answerIds[] = $aid;
    }
  } catch (Throwable $t) { $notice = $notice ?: 'Answers could not be loaded right now.'; }

  if ($answerIds) {
    $aBind = []; $aPlace = [];
    foreach ($answerIds as $i => $aidVal) { $aPlace[] = ":a$i"; $aBind[":a$i"] = (int)$aidVal; }
    $inA = implode(',', $aPlace);
    try {
      $cSql = "
        SELECT c.id, c.answer_id, c.user_id, c.body, c.created_at, u.display_name
        FROM comments c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.answer_id IN ($inA)
        ORDER BY c.created_at ASC";
      $cs = $pdo->prepare($cSql);
      $cs->execute($aBind);
      while ($c = $cs->fetch(PDO::FETCH_ASSOC)) {
        $commentsByA[(int)$c['answer_id']][] = $c;
      }
    } catch (Throwable $t) { if (!$notice) $notice = 'Tip: add a `comments` table to enable comments on answers.'; }
  }
}

/* -----------------------------
   Utils
------------------------------*/
function time_ago(string $datetime): string {
  $ts = strtotime($datetime);
  if ($ts === false) return $datetime;
  $diff = time() - $ts;
  if ($diff < 60)    return $diff.'s ago';
  if ($diff < 3600)  return floor($diff/60).'m ago';
  if ($diff < 86400) return floor($diff/3600).'h ago';
  if ($diff < 604800)return floor($diff/86400).'d ago';
  return date('M j, Y', $ts);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta http-equiv="Cache-Control" content="no-store" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>PlayerPath — Questions</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Orbitron:wght@700;800&display=swap" rel="stylesheet">

<style>
/* (styles unchanged) */
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
.top{ background:var(--surface); border-bottom:1px solid var(--border); position:sticky; top:0; z-index:30 }
.top-inner{ height:70px; display:flex; align-items:center; gap:16px; max-width:1440px; margin:0 auto; padding:0 22px }
.brand{ display:flex; gap:.6rem; font-family:Orbitron, Inter, sans-serif; font-weight:800; letter-spacing:.8px; text-transform:uppercase; font-size:clamp(1.2rem, 1.02vw + 1rem, 1.5rem) }
.brand .half-red{ color:var(--brand) } .brand .half-white{ color:#fff; text-shadow:0 0 6px rgba(230,0,35,.45) }

.btn{ border:none; border-radius:12px; padding:8px 14px; font-weight:700; cursor:pointer; font-size:.95rem; min-height:36px }
.btn.ghost{ background:#0f0f12; color:#f1f2f4; border:1px solid #24252a }
.btn.logout,.btn.primary{ background:var(--brand); color:#fff; box-shadow:0 6px 18px rgba(230,0,35,.28) }
.btn.white-red{ background:#fff; color:var(--brand); border:1px solid var(--brand) }
.btn[disabled]{ opacity:.5; cursor:not-allowed }

.app{ display:grid; grid-template-columns:240px minmax(0,1fr); grid-template-areas:"nav main"; gap:22px; max-width:1440px; padding:22px; margin:0 auto; align-items:start; }
@media (max-width:980px){ .app{ grid-template-columns:1fr; grid-template-areas:"nav" "main" } }

.lnav{ grid-area:nav; border:2px solid var(--brand); padding:16px 12px 16px 16px; display:flex; flex-direction:column; gap:6px; background:var(--bg); border-radius:16px; position:sticky; top:22px; max-height:calc(100vh - 44px); overflow:auto; }
.lnav a{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:12px; color:#fff; font-size:.98rem; opacity:.82 }
.lnav a:hover,.lnav a[aria-current="page"]{ background:#141416; color:#fff; font-weight:700; outline:1px solid #232326; opacity:1 }

.content{ grid-area:main; position:relative; padding-left:28px; }
.content::before{ content:""; position:absolute; left:6px; top:0; bottom: var(--foot-gap); width:2px; background:var(--white); border-radius:2px; opacity:.9; }
@media (max-width:980px){ .content{ padding-left:18px } .content::before{ left:4px; opacity:.7 } }

.page-head{ display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:12px }
.h1{ font:800 1.12rem/1.1 Inter, sans-serif; }
.seg{ display:inline-flex; border:1px solid var(--border); background:var(--surface); border-radius:999px; overflow:hidden }
.seg a{ padding:7px 12px; color:#b5bac5; border-right:1px solid var(--border); font-size:.9rem }
.seg a:last-child{ border-right:0 } .seg a.active{ color:#fff; background:linear-gradient(180deg, rgba(230,0,35,.18), rgba(230,0,35,0)) }

.searchbar{ display:flex; gap:10px; margin:12px 0 8px }
.input{ flex:1; background:#0f0f12; border:1px solid var(--border); color:#fff; padding:10px 12px; border-radius:12px; outline:none; min-height:38px; font-size:.95rem }

.q-section{ background:#121214; border:1px solid #24252a; border-radius:16px; padding:14px 16px 10px; margin:18px 0 26px; box-shadow:0 10px 24px rgba(0,0,0,.28); position:relative; }
.q-head{ border:2px solid var(--brand); border-radius:14px; padding:10px 12px; margin:0 0 12px; background:transparent; }
.q-head .post-title{ margin:0 0 4px }
.post-sub{ color:#cfd3db; font-size:.9rem; margin:-2px 0 8px }

.post{ display:grid; grid-template-columns:64px minmax(0,1fr); gap:14px; padding:18px 0; border-bottom:1px solid #23242a; }
.post:last-child{ border-bottom:0 }
.vote{ display:flex; flex-direction:column; align-items:center; gap:6px; user-select:none; position:relative; }
.vote .arrow{ width:34px; height:34px; border-radius:10px; border:1px solid #2a2b31; background:#0f0f12; color:#fff; display:flex; align-items:center; justify-content:center; font-size:18px; cursor:pointer }
.vote .arrow.active{ outline:2px solid rgba(230,0,35,.45) }
.vote .arrow[disabled]{ opacity:.45; cursor:not-allowed }
.vote .score{ background:var(--white); color:var(--brand); border:1px solid var(--brand); border-radius:12px; font-weight:800; font-size:1.02rem; padding:4px 10px; line-height:1; }

.post-body{ min-width:0 }
.post-title{ margin:0 0 6px; font:800 1.2rem/1.25 Inter, sans-serif }
.post-content{ color:#f1f3f6; white-space:pre-wrap; overflow-wrap:anywhere; font-size:.96rem }
.tags{ margin-top:10px; display:flex; flex-wrap:wrap; gap:6px }
.tag{ display:inline-flex; align-items:center; gap:6px; padding:5px 9px; border-radius:999px; background:#1d1e22; border:1px solid #2a2b31; color:#d7dae2; font-size:.72rem }
.post-meta{ margin-top:10px; color:#aeb3bc; font-size:.86rem }

.count-pill{ display:inline-flex; align-items:center; gap:6px; background:var(--white); color:var(--brand); border:1px solid var(--brand); border-radius:999px; font-weight:800; padding:4px 10px; line-height:1; font-size:.86rem; }

.comments{ margin-top:14px; border:1px solid #26272c; background:#101117; border-radius:12px; overflow:hidden }
.comments-header{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; border-bottom:1px solid #23242a; }
.comments-header .label{ font-weight:800; font-size:.9rem; color:var(--brand); letter-spacing:.3px; }
.badge{ display:inline-flex; align-items:center; justify-content:center; padding:2px 8px; border-radius:999px; font-size:.72rem; background:#1b1c21; border:1px solid #2a2b31; color:#cfd3db }
.comment-row{ margin:10px; padding:10px 12px; border:1px solid var(--white); border-radius:10px; background:#0f1014; font-size:.9rem }
.comment-head{ display:flex; align-items:center; gap:8px; font-size:.82rem; color:#b9bec7; margin-bottom:4px }
.comment-author{ color:#e6e8ee; font-weight:800; margin-right:6px }
.comment-time{ color:#8f94a1; font-size:.78rem }
.comment-body{ color:#d7dae2; white-space:pre-wrap; overflow-wrap:anywhere; line-height:1.48; font-size:.9rem }
.comment-actions{ margin-top:6px; display:flex; gap:8px; }

/* Reply button */
.reply-btn{
  background:#000; border:1px solid var(--brand); color:#fff;
  border-radius:10px; padding:4px 10px; font-size:.9rem; font-weight:800;
  cursor:pointer; transition:.15s ease-in-out;
}
.reply-btn:hover{ background:var(--brand); color:#fff; }

/* composer */
.comment-composer{ display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px; padding:8px 10px; border-top:1px solid #23242a; background:#0f1014; }
.comment-composer textarea{
  width:100%; background:#0f0f12; border:1px solid #2b2c33; color:#cfd3db;
  padding:8px 10px; border-radius:10px; font-size:.88rem; resize:none; min-height:36px; max-height:180px;
}
.comment-composer button{ border:1px solid var(--brand); color:#fff; background:var(--brand); padding:7px 10px; border-radius:10px; font-weight:800; font-size:.84rem; align-self:start; }

.answers-indent{ margin-left:30px; padding-left:12px; }
@media (max-width:980px){ .answers-indent{ margin-left:18px; padding-left:10px; } }

.answer-post{ background:#fff; border:1px solid #fff; border-radius:12px; padding:14px 12px; margin:12px 0; box-shadow:0 6px 18px rgba(0,0,0,.25); border-bottom:0; }
.answer-post .post-title,.answer-post .post-content,.answer-post .post-meta{ color:#111; }
.answer-post .vote .arrow{ background:#f2f2f2; border:1px solid #ddd; color:#111; }
.answer-post .vote .arrow.active{ outline:2px solid rgba(230,0,35,.35) }
.answer-post .vote .arrow[disabled]{ opacity:.45; cursor:not-allowed }
.answer-post .vote .score{ background:#fff; color:var(--brand); border:1px solid var(--brand); }
.answer-post .comments{ background:#fafafa; border-color:#e5e7eb; }
.answer-post .comments-header{ border-bottom:1px solid #e5e7eb; }
.answer-post .comment-row{ background:#fff; border:1px solid #e5e7eb; color:#111; }
.answer-post .comment-head{ color:#444; } .answer-post .comment-author{ color:#111; }
.answer-post .comment-time{ color:#666; } .answer-post .comment-body{ color:#111; }
.answer-post .comment-actions .reply-btn{ background:#000; border:1px solid var(--brand); color:#fff; }
.answer-post .comment-composer{ background:#fff; border-top:1px solid #e5e7eb; }
.answer-post .comment-composer textarea{ background:#fff; border:1px solid #e5e7eb; color:#111; }

.answers-header{ margin:8px 0 10px; font-weight:800; letter-spacing:.2px; color:#fff; display:flex; align-items:center; gap:10px }

.empty{ margin:10px 0; padding:10px; border:1px dashed var(--brand); border-radius:14px; background:#141416; color:#ffd7dd; font-size:.94rem }
.dialog-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.6); display:none; align-items:center; justify-content:center; padding:16px; z-index:50 }
.dialog{ width:min(700px,100%); background:var(--surface); border:2px solid var(--white); border-radius:18px; padding:16px }
.dialog h2{ margin:0 0 10px; font-size:1rem }
.dialog .field{ margin-bottom:10px }
.dialog input[type="text"], .dialog textarea{ width:100%; background:#0f0f12; border:1px solid var(--border); color:#fff; border-radius:12px; padding:10px 12px; font-size:.95rem; min-height:38px }
.dialog textarea{ min-height:140px }
.dialog .row{ display:flex; gap:8px; justify-content:flex-end }
.dialog .cancel{ background:transparent; border:1px solid var(--border); color:#fff }
.answering-title{ margin:.35rem 0 .8rem; padding:.5rem .7rem; border:1px solid #34353d; border-radius:12px; background:#0f1116; color:#cfd3db; font-size:.9rem }
.cta{ display:flex; gap:10px; align-items:center }

.accept-btn{ border:2px solid var(--brand); color:var(--brand); background:#fff; border-radius:12px; padding:6px 14px; font-weight:900; font-size:1.2rem; cursor:pointer; transition:.15s ease-in-out; }
.accept-btn:hover{ background:#000; border-color:#000; color:#fff; }
.accept-btn.active{ background:var(--brand); border-color:var(--brand); color:#fff; }
.accept-btn[disabled]{ opacity:.45; cursor:not-allowed }


/* Accepted label — follow brand red */
.accepted-label{
  color: var(--brand);          /* #e60023 */
  font-weight: 800;
  margin-left: 8px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.accepted-label svg{
  width: 14px;
  height: 14px;
  fill: currentColor;           /* the check inherits .accepted-label color */
  flex: 0 0 auto;
}



footer{ border-top:1px solid var(--border); background:var(--surface); margin-top:var(--foot-gap); }
.foot{ height:var(--foot-h); display:flex; align-items:center; justify-content:space-between; max-width:1440px; margin:0 auto; padding:0 20px; color:var(--ink-dim); font-size:.98rem; }
.foot a{ color:var(--ink-dim); text-decoration:none; margin:0 6px; }
.foot a:hover{ color:#fff; }

/* red focus */
.comment-composer textarea:focus{ outline:none; border-color:var(--brand)!important; box-shadow:0 0 0 2px rgba(230,0,35,0.45); }
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
    <a href="<?= url('questions.php') ?>" aria-current="page">Questions</a>
    <a href="#">Tags</a><a href="#">Leaderboard</a><a href="Profiles.php">Profiles</a>
    <div style="margin:10px 0; border-top:2px solid var(--brand)"></div>
    <a href="top5.php">My Top 5 Games</a><a href="#">Playbooks</a><a href="#">Challenges</a><a href="#">Guides</a>
  </nav>

  <main class="content">
    <div class="page-head">
      <div class="h1">
        <?= $uid ? 'My Questions' : 'Community Questions' ?>
      </div>
      <div class="cta">
        <nav class="seg" aria-label="Sort">
          <a href="<?= url('questions.php?sort=recent'.($search!=='' ? '&q='.urlencode($search) : '')) ?>" class="<?= $sort==='recent' ? 'active' : '' ?>">Recent</a>
          <a href="<?= url('questions.php?sort=top'.($search!=='' ? '&q='.urlencode($search) : ''))    ?>" class="<?= $sort==='top'    ? 'active' : '' ?>">Top</a>
        </nav>
        <?php if ($uid): ?>
          <button class="btn white-red" type="button" onclick="openAsk()">Ask Question</button>
        <?php else: ?>
          <a href="<?= url('SignIn.php') ?>"><button class="btn primary" type="button">Sign in to Ask</button></a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($notice): ?><p class="empty" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php foreach ($errors as $eMsg): ?>
      <p class="empty" role="alert" style="background:#2b0f14;border-color:#5c0d18"><?= e($eMsg) ?></p>
    <?php endforeach; ?>

    <form class="searchbar" method="get" action="<?= url('questions.php') ?>">
      <input class="input" name="q" placeholder="<?= $uid ? 'Search my questions…' : 'Search questions…' ?>" value="<?= e($search) ?>" aria-label="Search questions">
      <button class="btn primary" type="submit">Search</button>
    </form>

    <?php if ($search !== ''): ?>
      <p style="color:var(--ink-dim);margin:0 0 8px">
        Showing results for <strong>“<?= e($search) ?>”</strong><?= $uid ? ' in your questions' : '' ?>.
      </p>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
      <div class="empty">
        <?php if ($uid): ?>
          You don’t have any <?= $search ? 'matching' : '' ?> questions yet.
          <button class="btn white-red" type="button" onclick="openAsk()" style="margin-left:8px">Ask one</button>
        <?php else: ?>
          No questions <?= $search ? 'matching your search' : 'yet' ?>.
          <a href="<?= url('SignIn.php') ?>" style="color:#fff;text-decoration:underline">Sign in to ask</a>.
        <?php endif; ?>
      </div>
    <?php else: ?>

      <?php
      $qIndex=0;
      foreach ($rows as $r):
        $qIndex++;
        $qid   = (int)$r['id'];
        $title = (string)$r['title'];
        $alist = $answersByQ[$qid] ?? [];
        $acount = count($alist);

        $totalComments = 0;
        foreach ($alist as $ansTmp) {
          $aidTmp = (int)$ansTmp['id'];
          $totalComments += isset($commentsByA[$aidTmp]) ? count($commentsByA[$aidTmp]) : 0;
        }

        $myQ = isset($r['my_vote_q']) ? (int)$r['my_vote_q'] : 0;

        $askerName  = trim((string)($r['question_display_name'] ?? ''));
        $askerLabel = $askerName !== '' ? $askerName : ('User #'.(int)$r['user_id']);

        $acceptedId = (int)($r['accepted_answer_id'] ?? 0);

        $isMyQuestion = $uid && ((int)$r['user_id'] === (int)$uid);
      ?>

      <section class="q-section" id="<?= 'qsec-'.$qid ?>">

        <article id="<?= 'q-'.$qid ?>" class="post">
          <div class="vote" aria-label="Question voting">
            <?php if ($uid && !$isMyQuestion): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="vote_question">
                <input type="hidden" name="question_id" value="<?= $qid ?>">
                <input type="hidden" name="value" value="1">
                <button class="arrow <?= $myQ===1?'active':'' ?>" title="Upvote" type="submit">▲</button>
              </form>
            <?php elseif ($uid && $isMyQuestion): ?>
              <button class="arrow" title="You cannot vote on your own question" disabled>▲</button>
            <?php else: ?>
              <a class="arrow" href="<?= url('SignIn.php') ?>" title="Sign in to upvote">▲</a>
            <?php endif; ?>

            <div class="score"><?= (int)$r['score'] ?></div>

            <?php if ($uid && !$isMyQuestion): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="vote_question">
                <input type="hidden" name="question_id" value="<?= $qid ?>">
                <input type="hidden" name="value" value="-1">
                <button class="arrow <?= $myQ===-1?'active':'' ?>" title="Downvote" type="submit">▼</button>
              </form>
            <?php elseif ($uid && $isMyQuestion): ?>
              <button class="arrow" title="You cannot vote on your own question" disabled>▼</button>
            <?php else: ?>
              <a class="arrow" href="<?= url('SignIn.php') ?>" title="Sign in to downvote">▼</a>
            <?php endif; ?>
          </div>

          <div class="post-body">
            <div class="q-head">
              <h1 class="post-title">Q<?= $qIndex ?> — <?= e($title) ?></h1>
              <div class="post-sub">Asked by <strong><?= e($askerLabel) ?></strong></div>

              <div class="tags" aria-label="Meta">
                <span class="tag">asked <?= e(time_ago((string)$r['created_at'])) ?></span>
                <span class="tag">active <?= e(time_ago((string)$r['updated_at'])) ?></span>
                <?php if ($sort==='top'): ?><span class="tag">sorted by top</span><?php endif; ?>
              </div>

              <div class="post-meta" style="margin-top:10px">
                <span class="count-pill"><strong><?= $acount ?></strong></span>
                <span style="margin-left:6px">Answer<?= $acount===1?'':'s' ?></span>
                &nbsp;•&nbsp;
                <span class="count-pill"><strong><?= $totalComments ?></strong></span>
                <span style="margin-left:6px">Comment<?= $totalComments===1?'':'s' ?></span>
              </div>
            </div>

            <div class="post-content"><?= nl2br(e((string)($r['body'] ?? ''))) ?></div>
          </div>
        </article>

        <div class="answers-indent">
          <div class="answers-header" id="<?= 'q-'.$qid.'-answers' ?>">
            <span class="count-pill"><strong><?= $acount ?></strong></span>
            <span>Answer<?= $acount===1?'':'s' ?></span>
            <?php if ($uid): ?>
              <?php if ($isMyQuestion): ?>
                <button class="btn white-red" type="button" disabled title="You cannot answer your own question">Add Answer</button>
              <?php else: ?>
                <button class="btn white-red" type="button" onclick='openAnswer(<?= $qid ?>, <?= json_encode($title) ?>)'>Add Answer</button>
              <?php endif; ?>
            <?php else: ?>
              <a class="btn white-red" href="<?= url('SignIn.php') ?>">Sign in to answer</a>
            <?php endif; ?>
          </div>

          <?php if ($acount>0):
            $ord=0;
            foreach ($alist as $ans):
              $ord++;
              $aid = (int)$ans['id'];
              $clist = $commentsByA[$aid] ?? [];
              $ccount = count($clist);
              $ansName = trim((string)($ans['answer_display_name'] ?? ''));
              $ansLabel = $ansName !== '' ? $ansName : ('User #'.(int)$ans['user_id']);
              $ansScore = (int)($ans['answer_score'] ?? 0);
              $myA = isset($ans['my_vote_a']) ? (int)$ans['my_vote_a'] : 0;

              $isAccepted = ($acceptedId === $aid);
              $isMyAnswer = $uid && ((int)$ans['user_id'] === (int)$uid);
          ?>
          <article id="<?= 'a-'.$aid ?>" class="post answer-post">
            <div class="vote" aria-label="Answer voting">
              <?php if ($uid && !$isMyAnswer): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="vote_answer">
                  <input type="hidden" name="answer_id" value="<?= $aid ?>">
                  <input type="hidden" name="value" value="1">
                  <button class="arrow <?= $myA===1?'active':'' ?>" title="Upvote answer" type="submit">▲</button>
                </form>
              <?php elseif ($uid && $isMyAnswer): ?>
                <button class="arrow" title="You cannot vote on your own answer" disabled>▲</button>
              <?php else: ?>
                <a class="arrow" href="<?= url('SignIn.php') ?>" title="Sign in to upvote">▲</a>
              <?php endif; ?>

              <div class="score"><?= $ansScore ?></div>

              <?php if ($uid && !$isMyAnswer): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="vote_answer">
                  <input type="hidden" name="answer_id" value="<?= $aid ?>">
                  <input type="hidden" name="value" value="-1">
                  <button class="arrow <?= $myA===-1?'active':'' ?>" title="Downvote answer" type="submit">▼</button>
                </form>
              <?php elseif ($uid && $isMyAnswer): ?>
                <button class="arrow" title="You cannot vote on your own answer" disabled>▼</button>
              <?php else: ?>
                <a class="arrow" href="<?= url('SignIn.php') ?>" title="Sign in to downvote">▼</a>
              <?php endif; ?>
            </div>

            <div class="post-body">
              <div class="post-title" style="font-size:1rem; opacity:.9">
                Answer #<?= $ord ?> — <strong><?= e($ansLabel) ?></strong> • <?= e(time_ago((string)$ans['created_at'])) ?>
                <?php if ($isAccepted): ?>
                  <span class="accepted-label">✔ Accepted by <?= e($askerLabel) ?></span>
                <?php endif; ?>
              </div>

              <?php
                $questionOwnerId = (int)$r['user_id'];
                $disableAccept = $uid && $questionOwnerId === (int)$uid && $isMyAnswer;
              ?>
              <?php if ($uid && $questionOwnerId === (int)$uid): ?>
                <form method="post" style="margin:6px 0 10px 0; display:inline-block">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="accept_answer">
                  <input type="hidden" name="question_id" value="<?= $qid ?>">
                  <input type="hidden" name="answer_id" value="<?= $aid ?>">
                  <button type="submit" class="accept-btn <?= $isAccepted ? 'active' : '' ?>"
                          title="<?= $disableAccept ? 'You cannot accept your own answer' : ($isAccepted ? 'Accepted answer' : 'Mark as accepted') ?>"
                          <?= $disableAccept ? 'disabled' : '' ?>>✔</button>
                </form>
              <?php endif; ?>

              <div class="post-content"><?= nl2br(e((string)$ans['body'])) ?></div>

              <div class="comments" aria-label="Comments">
                <div class="comments-header">
                  <span class="label">COMMENTS</span>
                  <span class="badge"><?= $ccount ?></span>
                </div>

                <?php if ($ccount === 0): ?>
                  <div class="comment-row">
                    <div class="comment-head"><span class="comment-author">No comments yet</span></div>
                    <div class="comment-body">Be the first to share a tip or ask a follow-up.</div>
                  </div>
                <?php else:
                  $i=0;
                  foreach ($clist as $c):
                    $i++;
                    $hiddenAttr = $i > 3 ? ' style="display:none" data-collapsible="1"' : '';
                    $cName = trim((string)($c['display_name'] ?? ''));
                    $cLabel = $cName !== '' ? $cName : ('User #'.(int)$c['user_id']);
                    $cId = (int)$c['id'];
                    $cUserId = (int)$c['user_id'];
                    $mention = mention_handle($cLabel);
                ?>
                  <div class="comment-row"<?= $hiddenAttr ?>>
                    <div class="comment-head">
                      <span class="comment-author"><?= e($cLabel) ?></span>
                      <span class="comment-time">· <?= e(time_ago((string)$c['created_at'])) ?></span>
                    </div>
                    <div class="comment-body"><?= nl2br(e((string)$c['body'])) ?></div>
                    <?php if ($uid && $cUserId !== (int)$uid): ?>
                      <div class="comment-actions">
                        <button class="reply-btn" type="button"
                                data-reply-for="<?= $aid ?>"
                                data-comment-id="<?= $cId ?>"
                                data-comment-author="<?= e($cLabel) ?>"
                                data-comment-userid="<?= $cUserId ?>"
                                data-comment-handle="<?= e($mention) ?>">
                          Reply
                        </button>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach;
                  if ($ccount > 3): ?>
                    <button class="btn ghost" type="button" style="width:100%;border-radius:0;border-top:1px dashed #2a2b31" data-toggle-for="<?= $aid ?>">Show more comments</button>
                  <?php endif;
                endif; ?>

                <div class="comment-composer" data-answer-id="<?= $aid ?>" data-is-my-answer="<?= $isMyAnswer ? '1' : '0' ?>">
                  <?php if ($uid): ?>
                    <form method="post" style="display:contents" onsubmit="return clampComment(this);">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="create_comment">
                      <input type="hidden" name="answer_id" value="<?= $aid ?>">
                      <input type="hidden" name="question_id" value="<?= $qid ?>">
                      <input type="hidden" name="reply_to" id="reply_to_<?= $aid ?>" value="">
                      <textarea name="body"
                                placeholder="<?= $isMyAnswer ? 'Reply to someone who commented on your answer…' : 'Add a comment… (Shift+Enter = new line)' ?>"
                                maxlength="2000" required oninput="autoGrow(this)"></textarea>
                      <button type="submit" class="btn white-red">Comment</button>
                    </form>
                  <?php else: ?>
                    <div style="padding:6px 0 2px 2px"><a href="<?= url('SignIn.php') ?>" style="color:#fff;text-decoration:underline">Sign in to comment</a></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </article>
          <?php endforeach; else: ?>
            <article class="post answer-post">
              <div class="vote"><div class="score">0</div></div>
              <div class="post-body"><div class="post-content" style="opacity:.9;color:#111">Be the first to answer.</div></div>
            </article>
          <?php endif; ?>

          <article class="post">
            <div class="vote" aria-hidden="true"></div>
            <div class="post-body">
              <form onsubmit="<?= $uid && !$isMyQuestion ? "openAnswer($qid, ".json_encode($title)."); return false;" : "return false;" ?>" style="display:flex;gap:10px;align-items:center">
                <input class="input" style="min-height:38px" type="text" placeholder="Add an answer to “<?= e($title) ?>”…" <?php if ($isMyQuestion) echo 'disabled'; ?> onclick='<?= $isMyQuestion ? "void 0" : "openAnswer($qid, ".json_encode($title).")" ?>' maxlength="5000" aria-label="Add an answer">
                <?php if ($uid): ?>
                  <?php if ($isMyQuestion): ?>
                    <button class="btn white-red" type="button" disabled title="You cannot answer your own question">Answer</button>
                  <?php else: ?>
                    <button class="btn white-red" type="button" onclick='openAnswer(<?= $qid ?>, <?= json_encode($title) ?>)'>Answer</button>
                  <?php endif; ?>
                <?php else: ?>
                  <a href="<?= url('SignIn.php') ?>"><button class="btn primary" type="button">Sign in</button></a>
                <?php endif; ?>
              </form>
            </div>
          </article>
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
<div id="askWrap" class="dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="askTitle">
  <div class="dialog">
    <h2 id="askTitle">Ask a Question</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_question">
      <div class="field"><label for="askTitleInput">Title</label><br><input id="askTitleInput" type="text" name="title" maxlength="140" placeholder="Be specific (≤ 140 chars)" required></div>
      <div class="field"><label for="askBody">Body</label><br><textarea id="askBody" name="body" placeholder="Describe your problem in detail…" required></textarea></div>
      <div class="row"><button type="button" class="btn cancel" onclick="closeAsk()">Cancel</button><button class="btn white-red" type="submit">Post Question</button></div>
    </form>
  </div>
</div>

<div id="answerWrap" class="dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="answerTitle">
  <div class="dialog">
    <h2 id="answerTitle">Answer</h2>
    <div id="answeringTitle" class="answering-title"></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_answer">
      <input type="hidden" id="answerQId" name="question_id" value="">
      <div class="field">
        <label for="answerBody">Your Answer</label><br>
        <textarea id="answerBody" name="body" placeholder="Share a helpful, detailed answer…" maxlength="20000" required></textarea>
      </div>
      <div class="row"><button type="button" class="btn cancel" onclick="closeAnswer()">Cancel</button><button class="btn white-red" type="submit">Post Answer</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.getElementById('yr').textContent = new Date().getFullYear();

const askWrap = document.getElementById('askWrap');
function openAsk(){ if(askWrap){ askWrap.style.display='flex'; setTimeout(()=>document.getElementById('askTitleInput')?.focus(),0);} }
function closeAsk(){ if(askWrap){ askWrap.style.display='none'; } }

const answerWrap   = document.getElementById('answerWrap');
const answerQId    = document.getElementById('answerQId');
const answeringDiv = document.getElementById('answeringTitle');
function openAnswer(id, title){
  if(answerWrap){
    answerQId.value = id;
    const t = typeof title === 'string' ? title : String(title ?? '');
    const trimmed = t.length > 140 ? t.slice(0,137) + '…' : t;
    answeringDiv.textContent = 'You are answering: “' + trimmed + '”';
    answerWrap.style.display = 'flex';
    setTimeout(()=>document.getElementById('answerBody')?.focus(),0);
  }
}
function closeAnswer(){ if(answerWrap){ answerWrap.style.display='none'; } }

// Expand/collapse long comment lists
document.addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-toggle-for]');
  if (btn) {
    const wrap = btn.closest('.post-body').querySelector('.comments');
    if (!wrap) return;
    const hidden = wrap.querySelectorAll('[data-collapsible="1"]');
    const isHidden = hidden.length && hidden[0].style.display === 'none';
    hidden.forEach(el => el.style.display = isHidden ? '' : 'none');
    btn.textContent = isHidden ? 'Show fewer comments' : 'Show more comments';
  }
});

// Reply flow (single composer; no chip)
document.addEventListener('click', (e) => {
  const rb = e.target.closest('.reply-btn');
  if (!rb) return;

  const aid = rb.getAttribute('data-reply-for');
  const cid = rb.getAttribute('data-comment-id');
  const author = rb.getAttribute('data-comment-author') || 'someone';
  const handle = rb.getAttribute('data-comment-handle') || '@someone';
  const comp = document.querySelector('.comment-composer[data-answer-id="'+aid+'"]');
  if (!comp) return;

  // set hidden reply_to (server rules rely on this)
  const input = comp.querySelector('#reply_to_'+aid);
  if (input) input.value = cid;

  // prefill @mention in the same textarea (Instagram-style)
  const ta = comp.querySelector('textarea[name="body"]');
  if (ta) {
    ta.focus();
    const mention = handle + ' ';
    const cur = ta.value || '';
    if (!cur.trim().startsWith(mention)) {
      ta.value = (mention + cur).trimStart();
    }
    ta.selectionStart = ta.selectionEnd = ta.value.length;
    autoGrow(ta);
    // hint while replying
    ta.placeholder = 'Reply to ' + author + '…';
  }
});

// ESC clears reply target + removes prefilled @mention if present
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const comp = e.target.closest?.('.comment-composer');
  if (!comp) return;
  const input = comp.querySelector('input[name="reply_to"]');
  const ta = comp.querySelector('textarea[name="body"]');
  if (input) input.value = '';
  if (ta) {
    ta.value = ta.value.replace(/^@\w+\s+/, '');
    ta.placeholder = 'Add a comment… (Shift+Enter = new line)';
    autoGrow(ta);
  }
});

function autoGrow(el){ if(!el) return; el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,180)+'px'; }
function clampComment(form){
  const comp = form.closest('.comment-composer');
  if (!comp) return true;
  const isMyAnswer = comp.getAttribute('data-is-my-answer') === '1';
  const replyTo = comp.querySelector('input[name="reply_to"]')?.value || '';
  const ta = form.querySelector('textarea[name="body"]');
  if (ta && ta.value.trim()==='') return false;
  if (isMyAnswer && !replyTo) {
    alert("You can only comment on your own answer by replying to someone who commented on it.");
    return false;
  }
  return true;
}
</script>
</body>
</html>
