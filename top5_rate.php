<?php
// top5.php
session_start();

/**
 * 1) Get the logged-in user id from your login system.
 *    Your login code should set $_SESSION['user_id'] when the user authenticates.
 */
if (!isset($_SESSION['user_id'])) {
  // TEMP for localhost testing only — point this at an existing user id
  $_SESSION['user_id'] = 3; 
}
$uid = (int) $_SESSION['user_id'];

/** 2) PDO connection (change credentials) */
$dsn = 'mysql:host=127.0.0.1;dbname=playerpath;charset=utf8mb4';
$user = 'root';        // ← your mysql user
$pass = '';            // ← your mysql password (often empty on localhost)
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

/** Helpers */
function slugify($s) {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
  return trim($s, '-');
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * 3) If the user has no rows in user_top_games,
 *    try to migrate from user_profiles.game1…game5 automatically.
 */
$stmt = $pdo->prepare('SELECT COUNT(*) FROM user_top_games WHERE user_id=?');
$stmt->execute([$uid]);
$hasTop = (int)$stmt->fetchColumn();

if ($hasTop === 0) {
  // pull game1..game5 from user_profiles
  $profile = $pdo->prepare('SELECT game1, game2, game3, game4, game5 FROM user_profiles WHERE user_id = ?');
  $profile->execute([$uid]);
  $row = $profile->fetch();

  if ($row) {
    // normalize list
    $names = array_values(array_filter([
      1 => trim((string)$row['game1'] ?? ''),
      2 => trim((string)$row['game2'] ?? ''),
      3 => trim((string)$row['game3'] ?? ''),
      4 => trim((string)$row['game4'] ?? ''),
      5 => trim((string)$row['game5'] ?? ''),
    ]));

    if (!empty($names)) {
      $pdo->beginTransaction();
      try {
        // clear any old rows
        $pdo->prepare('DELETE FROM user_top_games WHERE user_id=?')->execute([$uid]);

        // upsert each game into games, then insert rank 1..5
        $insGame = $pdo->prepare('
          INSERT INTO games (slug, name) VALUES (:slug, :name)
          ON DUPLICATE KEY UPDATE name = VALUES(name)
        ');
        $getGameId = $pdo->prepare('SELECT id FROM games WHERE slug = ?');
        $insTop = $pdo->prepare('INSERT INTO user_top_games (user_id, game_id, rank) VALUES (?, ?, ?)');

        $rank = 1;
        foreach ($names as $name) {
          $slug = slugify($name);
          if ($slug === '') continue;
          $insGame->execute([':slug'=>$slug, ':name'=>$name]);
          $getGameId->execute([$slug]);
          $gid = (int)$getGameId->fetchColumn();
          if ($gid) {
            $insTop->execute([$uid, $gid, $rank]);
            $rank++;
            if ($rank > 5) break;
          }
        }
        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        // swallow: we'll just render the "no Top-5" message below
      }
    }
  }
}

/** 4) Fetch the user record (for greeting/avatar/etc) */
$userStmt = $pdo->prepare('SELECT id, username, display_name FROM users WHERE id = ?');
$userStmt->execute([$uid]);
$userRow = $userStmt->fetch();
$displayName = $userRow['display_name'] ?? $userRow['username'] ?? 'Player';

/** 5) Load Top-5 with game info */
$topStmt = $pdo->prepare('
  SELECT utg.rank, g.id AS game_id, g.name, g.slug
  FROM user_top_games utg
  JOIN games g ON g.id = utg.game_id
  WHERE utg.user_id = ?
  ORDER BY utg.rank
');
$topStmt->execute([$uid]);
$top = $topStmt->fetchAll();

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My Top-5 Games</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;padding:2rem;background:#0b0b0c;color:#f3f4f6}
    a{color:#93c5fd;text-decoration:none}
    .container{max-width:980px;margin:0 auto}
    .title{font-size:2.2rem;font-weight:800;margin:0 0 1rem}
    .note{background:#cffafe14;border:1px solid #67e8f91f;border-radius:12px;padding:12px 14px;margin:16px 0;color:#a7f3d0}
    .game{border:1px solid #ffffff14;border-radius:16px;padding:16px 18px;margin:18px 0;background:#111318}
    .badge{display:inline-block;background:#1f2937;border:1px solid #ffffff12;border-radius:999px;padding:2px 10px;margin-right:8px;color:#93c5fd;font-size:.8rem}
    .section{margin-top:10px}
    .cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
    .card{border:1px solid #ffffff12;border-radius:12px;padding:10px;background:#0f1116}
    .muted{color:#9ca3af;font-size:.95rem}
    .rate form{display:inline}
    input[type=number]{width:64px;background:#0b0b0c;color:#fff;border:1px solid #ffffff2e;border-radius:8px;padding:6px}
    button{background:#2563eb;border:none;border-radius:8px;color:#fff;padding:6px 10px;cursor:pointer}
  </style>
</head>
<body>
<div class="container">
  <h1 class="title">Hi <?=h($displayName)?> — Your Top-5 Games</h1>

  <?php if (!$top): ?>
    <div class="note">
      No Top-5 found yet. Fill your favorites in <em>user_profiles.game1…game5</em> for this user, or insert rows in <code>user_top_games</code>, then refresh.
    </div>
  <?php else: ?>

    <?php
    // Tiny helpers for content queries
    $haveQuestionsGameId = false;
    try {
      $haveQuestionsGameId = (bool)$pdo->query("SHOW COLUMNS FROM questions LIKE 'game_id'")->fetch();
    } catch (\Throwable $e) {}
    $qByGameId = $pdo->prepare('SELECT id, title, created_at FROM questions WHERE game_id=? ORDER BY created_at DESC LIMIT 5');
    $qByText   = $pdo->prepare('SELECT id, title, created_at FROM questions WHERE title LIKE ? OR body LIKE ? ORDER BY created_at DESC LIMIT 5');

    $vStmt = $pdo->prepare('SELECT id, title, url, created_at FROM videos WHERE game_id=? ORDER BY created_at DESC LIMIT 6');
    $pStmt = $pdo->prepare('SELECT id, url, caption, created_at FROM photos WHERE game_id=? ORDER BY created_at DESC LIMIT 6');
    $rGet  = $pdo->prepare('SELECT score FROM ratings WHERE user_id=? AND game_id=? LIMIT 1');
    $rUp   = $pdo->prepare('INSERT INTO ratings (user_id, game_id, score) VALUES (?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score)');
    ?>

    <?php foreach ($top as $g): ?>
      <?php
        $gid   = (int)$g['game_id'];
        $gname = $g['name'];

        // videos
        $vStmt->execute([$gid]);
        $videos = $vStmt->fetchAll();

        // questions (prefer game_id if exists)
        if ($haveQuestionsGameId) {
          $qByGameId->execute([$gid]);
          $questions = $qByGameId->fetchAll();
        } else {
          $like = '%'.$gname.'%';
          $qByText->execute([$like,$like]);
          $questions = $qByText->fetchAll();
        }

        // photos (table optional; if missing, skip gracefully)
        $photos = [];
        try { $pStmt->execute([$gid]); $photos = $pStmt->fetchAll(); } catch (\Throwable $e) {}

        // rating
        $rGet->execute([$uid,$gid]);
        $rating = $rGet->fetchColumn();
      ?>
      <div class="game">
        <div class="badge">#<?= (int)$g['rank'] ?></div>
        <strong><?= h($gname) ?></strong>

        <div class="section">
          <div class="muted">Videos</div>
          <?php if ($videos): ?>
            <div class="cards">
              <?php foreach ($videos as $v): ?>
                <div class="card">
                  <div><a href="<?=h($v['url'])?>" target="_blank"><?=h($v['title'])?></a></div>
                  <div class="muted"><?=h($v['created_at'])?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="muted">No videos yet.</div>
          <?php endif; ?>
        </div>

        <div class="section">
          <div class="muted">Questions</div>
          <?php if ($questions): ?>
            <ul>
              <?php foreach ($questions as $q): ?>
                <li><?=h($q['title'])?> <span class="muted">(<?=h($q['created_at'])?>)</span></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="muted">No questions yet.</div>
          <?php endif; ?>
        </div>

        <?php if ($photos !== []): ?>
        <div class="section">
          <div class="muted">Photos</div>
          <?php if ($photos): ?>
            <div class="cards">
              <?php foreach ($photos as $p): ?>
                <div class="card">
                  <div><img src="<?=h($p['url'])?>" alt="" style="max-width:100%;height:auto;border-radius:8px;"></div>
                  <?php if ($p['caption']): ?><div class="muted"><?=h($p['caption'])?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="muted">No photos yet.</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="section rate">
          <div class="muted">Your rating:</div>
          <form method="post" action="top5_rate.php" style="margin-top:6px">
            <input type="hidden" name="game_id" value="<?=$gid?>">
            <input type="number" name="score" min="1" max="5" step="1" value="<?= $rating ? (int)$rating : '' ?>" placeholder="1–5">
            <button type="submit">Save</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

  <?php endif; ?>
</div>
</body>
</html>
