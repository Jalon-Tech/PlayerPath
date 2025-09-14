<?php
// top5.php — PlayerPath (Top 5) — robust daily internet media for Top-5 games
declare(strict_types=1);

/* Cache */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache'); header('Expires: 0');

/* Includes */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/* Escaper */
if (!function_exists('h')) { function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } }

/* Dev errors */
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

/* ===== Helpers ===== */
function table_exists(PDO $pdo, string $name): bool {
  try { $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name)); return (bool)$stmt->fetchColumn(); }
  catch (Throwable $e) { return false; }
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  if (!table_exists($pdo,$table)) return false;
  try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(); }
  catch (Throwable $e) { return false; }
}
function slugify(string $s): string { $s=strtolower(trim($s)); $s=preg_replace('/[^\p{L}\p{N}]+/u','-',$s); return trim($s,'-'); }

/* LIKE patterns */
function make_like_patterns(string $name, string $slug): array {
  $n = trim($name); $s = trim($slug);
  $spacey = str_replace('-', ' ', $s);
  $simple = preg_replace('/[^a-z0-9 ]+/i',' ', $n);
  $parts = array_values(array_unique(array_filter([$n,$spacey,$simple])));
  $parts = array_slice($parts, 0, 3);
  if (!$parts) $parts = [$n ?: ($s ?: '')];
  while (count($parts) < 3) $parts[] = end($parts);
  return array_map(fn($x)=>"%$x%", $parts);
}

/* Deterministic daily pick */
function day_seed(int $uid, int $gid, string $type): string { return gmdate('Y-m-d') . ":$uid:$gid:$type"; }
function pick_daily(array $rows, int $uid, int $gid, string $type, int $n=3): array {
  if (!$rows) return [];
  $seed = day_seed($uid,$gid,$type);
  usort($rows, function($a,$b) use($seed){
    $ha = crc32($seed.':'.json_encode($a,JSON_UNESCAPED_UNICODE));
    $hb = crc32($seed.':'.json_encode($b,JSON_UNESCAPED_UNICODE));
    return $ha <=> $hb;
  });
  return array_slice($rows, 0, $n);
}
function is_sunday(): bool { return (int)date('w') === 0; }

/* ===== Current user ===== */
$uid         = $_SESSION['user_id']      ?? null;
$displayName = $_SESSION['display_name'] ?? null;

/* POST: secure logout */
if ($uid && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
  csrf_check($_POST['csrf'] ?? '');
  logout();
  flash('Signed out.');
  header('Location: top5.php'); exit;
}

/* ===== DB ===== */
$pdo = $pdo ?? new PDO('mysql:host=127.0.0.1;dbname=playerpath;charset=utf8mb4','root','',[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);

/* Ensure cache tables for internet media exist */
function ensure_media_tables(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS external_media_videos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      game_id INT NOT NULL,
      game_name VARCHAR(255) NOT NULL,
      source VARCHAR(64) NOT NULL,
      title VARCHAR(512) NOT NULL,
      url TEXT NOT NULL,
      thumb TEXT DEFAULT NULL,
      published_at DATETIME DEFAULT NULL,
      fetched_at DATETIME NOT NULL,
      UNIQUE KEY uniq_u_g_url (user_id, game_id, url(180)),
      KEY idx_u_g_time (user_id, game_id, fetched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS external_media_photos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      game_id INT NOT NULL,
      game_name VARCHAR(255) NOT NULL,
      source VARCHAR(64) NOT NULL,
      caption VARCHAR(512) DEFAULT NULL,
      url TEXT NOT NULL,
      thumb TEXT DEFAULT NULL,
      published_at DATETIME DEFAULT NULL,
      fetched_at DATETIME NOT NULL,
      UNIQUE KEY uniq_u_g_purl (user_id, game_id, url(180)),
      KEY idx_u_g_time (user_id, game_id, fetched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}
ensure_media_tables($pdo);

/* Feature flags: local site tables (optional) */
$haveQuestions = table_exists($pdo,'questions');
$haveVideosTbl = table_exists($pdo,'videos');
$havePhotosTbl = table_exists($pdo,'photos');

$hasQGame = $haveQuestions && col_exists($pdo,'questions','game_id');
$hasVGame = $haveVideosTbl && col_exists($pdo,'videos','game_id');
$hasPGame = $havePhotosTbl && col_exists($pdo,'photos','game_id');
$hasVPlay = $haveVideosTbl && col_exists($pdo,'videos','is_gameplay');
$hasPPlay = $havePhotosTbl && col_exists($pdo,'photos','is_gameplay');

/* ===== Load profile + Top-5 ===== */
$display = 'Player';
$platform = '';
$coversBySlug = [];
$games = [];
$systemRibbon = [];
$topIds = [];

if ($uid) {
  $st = $pdo->prepare('SELECT id, username, display_name FROM users WHERE id=?');
  $st->execute([$uid]);
  $usr = $st->fetch() ?: ['username'=>'Player'];
  $display = $usr['display_name'] ?: $usr['username'];

  $st = $pdo->prepare('
    SELECT platform, game1,game1_cover,game2,game2_cover,game3,game3_cover,game4,game4_cover,game5,game5_cover
    FROM user_profiles WHERE user_id=?
  ');
  $st->execute([$uid]);
  $p = $st->fetch() ?: [];
  $platform = trim((string)($p['platform'] ?? ''));

  for ($i=1;$i<=5;$i++){
    $name = trim((string)($p["game{$i}"] ?? ''));
    $img  = trim((string)($p["game{$i}_cover"] ?? ''));
    if ($name !== '') $coversBySlug[slugify($name)] = $img;
  }

  // Seed user_top_games from profile if missing
  $hasTop = (int)$pdo->query("SELECT COUNT(*) FROM user_top_games WHERE user_id=".(int)$uid)->fetchColumn();
  if ($hasTop === 0 && $p){
    $pdo->beginTransaction();
    try{
      $pdo->prepare('DELETE FROM user_top_games WHERE user_id=?')->execute([$uid]);
      $insG = $pdo->prepare('INSERT INTO games (slug,name) VALUES (:s,:n) ON DUPLICATE KEY UPDATE name=VALUES(name)');
      $getG = $pdo->prepare('SELECT id FROM games WHERE slug=?');
      $insT = $pdo->prepare('INSERT INTO user_top_games (user_id,game_id,rank) VALUES (?,?,?)');
      $rank=1;
      for ($i=1;$i<=5;$i++){
        $name = trim((string)($p["game{$i}"] ?? '')); if ($name==='') continue;
        $slug = slugify($name);
        $insG->execute([':s'=>$slug, ':n'=>$name]);
        $getG->execute([$slug]);
        if ($gid=(int)$getG->fetchColumn()){ $insT->execute([$uid,$gid,$rank]); $rank++; }
        if ($rank>5) break;
      }
      $pdo->commit();
    }catch(Throwable $e){ $pdo->rollBack(); }
  }

  // Load Top-5
  $st = $pdo->prepare('
    SELECT utg.rank, g.id AS game_id, g.name, g.slug
    FROM user_top_games utg
    JOIN games g ON g.id=utg.game_id
    WHERE utg.user_id = ?
    ORDER BY utg.rank
  ');
  $st->execute([$uid]);
  $games = $st->fetchAll();
  $topIds = array_map(fn($r)=>(int)$r['game_id'], $games);
}

/* ===== HTTP helpers ===== */
function http_get_raw(string $url, array $headers = [], int $timeout = 20): string {
  // Try cURL first
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_USERAGENT => 'PlayerPathBot/1.0',
      CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $out = curl_exec($ch);
    $ok  = $out !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
    if (!$ok) { curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); $out = curl_exec($ch); $ok = $out !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400; }
    curl_close($ch);
    if ($ok && is_string($out)) return $out;
  }
  // Fallback: file_get_contents (if allowed)
  $ctx = stream_context_create(['http'=>['timeout'=>$timeout,'ignore_errors'=>true,'header'=>implode("\r\n",$headers)], 'https'=>['timeout'=>$timeout,'ignore_errors'=>true,'header'=>implode("\r\n",$headers)]]);
  $raw = @file_get_contents($url, false, $ctx);
  return is_string($raw) ? $raw : '';
}
function http_json(string $url, array $headers = []): array {
  $raw = http_get_raw($url, $headers);
  if ($raw === '') return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

/* ===== YouTube helpers ===== */
function yt_upsert(PDO $pdo, int $uid, int $gid, string $gname, string $title, string $videoId, ?string $pub, string $source): void {
  $ins = $pdo->prepare("
    INSERT INTO external_media_videos
      (user_id, game_id, game_name, source, title, url, thumb, published_at, fetched_at)
    VALUES
      (:uid, :gid, :gname, :src, :title, :url, :thumb, :pub, :fetch)
    ON DUPLICATE KEY UPDATE
      title=VALUES(title), thumb=VALUES(thumb), published_at=VALUES(published_at), fetched_at=VALUES(fetched_at)
  ");
  $ins->execute([
    ':uid'=>$uid, ':gid'=>$gid, ':gname'=>$gname, ':src'=>$source,
    ':title'=>($title ?: 'Watch'),
    ':url'=>"https://www.youtube.com/watch?v={$videoId}",
    ':thumb'=>"https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
    ':pub'=>$pub ? date('Y-m-d H:i:s', strtotime($pub)) : null,
    ':fetch'=>gmdate('Y-m-d H:i:s'),
  ]);
}

function yt_make_queries(string $gameName): array {
  $clean  = trim(preg_replace('/[^a-z0-9 ]+/i',' ', $gameName));
  $spacey = trim(str_replace(['-','–','—',':','&','  '], ' ', $gameName));
  $base   = [$gameName,$clean,$spacey];
  $suffix = ['gameplay','walkthrough','review','boss fight','ps5 gameplay','pc gameplay'];
  $q = [];
  foreach (array_unique(array_filter($base)) as $b) {
    foreach ($suffix as $s) $q[] = trim("$b $s");
  }
  return array_values(array_unique($q));
}

/* API fill */
function ytapi_fill(PDO $pdo, int $uid, int $gid, string $gname): int {
  if (!defined('YT_API_KEY') || YT_API_KEY==='') return 0;
  $n=0;
  foreach (yt_make_queries($gname) as $query) {
    $q = urlencode($query);
    foreach (['viewCount','relevance'] as $order) {
      $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&order={$order}&maxResults=25&q={$q}&regionCode=US&key=" . urlencode(YT_API_KEY);
      $data = http_json($url);
      if (empty($data['items'])) continue;
      foreach ($data['items'] as $it) {
        $id = $it['id']['videoId'] ?? null; if (!$id) continue;
        $sn = $it['snippet'] ?? [];
        yt_upsert($pdo,$uid,$gid,$gname,(string)($sn['title'] ?? 'Watch'),$id,(string)($sn['publishedAt'] ?? ''),'youtube');
        $n++;
      }
      if ($n>0) break 2;
    }
  }
  return $n;
}

/* RSS fetch (no key) */
function ytrss_fetch_ids(string $query, int $limit = 20): array {
  $q = urlencode($query);
  $url = "https://www.youtube.com/feeds/videos.xml?search_query={$q}";
  $raw = http_get_raw($url);
  if ($raw === '') return [];
  $out = [];

  if (function_exists('simplexml_load_string')) {
    $xml = @simplexml_load_string($raw);
    if ($xml && isset($xml->entry)) {
      foreach ($xml->entry as $e) {
        $id = (string)$e->id;
        if (strpos($id, 'yt:video:') === 0) $id = substr($id, 9);
        if ($id) $out[] = ['id'=>$id, 'title'=>(string)$e->title, 'published'=>(string)$e->published];
        if (count($out) >= $limit) break;
      }
      if ($out) return $out;
    }
  }
  if (preg_match_all('~yt:video:([A-Za-z0-9_\-]{6,})~', $raw, $m)) {
    foreach ($m[1] as $vid) { $out[] = ['id'=>$vid,'title'=>'Watch','published'=>null]; if (count($out) >= $limit) break; }
  }
  return $out;
}
function ytrss_fill(PDO $pdo, int $uid, int $gid, string $gname): int {
  $n=0;
  foreach (yt_make_queries($gname) as $query) {
    $rows = ytrss_fetch_ids($query, 25);
    foreach ($rows as $r) { yt_upsert($pdo,$uid,$gid,$gname,(string)$r['title'],$r['id'],(string)$r['published'],'youtube-rss'); $n++; }
    if ($n>0) break;
  }
  return $n;
}

/* Refresh daily (API → RSS) */
function refresh_youtube_for_game(PDO $pdo, int $uid, int $gameId, string $gameName, bool $force=false): void {
  if (!$force) {
    $st = $pdo->prepare("SELECT MAX(fetched_at) FROM external_media_videos WHERE user_id=? AND game_id=?");
    $st->execute([$uid,$gameId]);
    $last = $st->fetchColumn();
    if ($last && (time() - strtotime((string)$last)) < 86400) return;
  }
  $added = ytapi_fill($pdo,$uid,$gameId,$gameName);
  if ($added === 0) $added = ytrss_fill($pdo,$uid,$gameId,$gameName);
  if ($added === 0) { // one more pass
    $added = ytapi_fill($pdo,$uid,$gameId,$gameName);
    if ($added === 0) ytrss_fill($pdo,$uid,$gameId,$gameName);
  }
  $pdo->prepare("DELETE FROM external_media_videos WHERE user_id=? AND game_id=? AND fetched_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)")
      ->execute([$uid,$gameId]);
}

/* Photos (optional key) */
function refresh_bing_images_for_game(PDO $pdo, int $uid, int $gameId, string $gameName): void {
  if (!defined('BING_API_KEY') || BING_API_KEY === '') return;
  $st = $pdo->prepare("SELECT MAX(fetched_at) FROM external_media_photos WHERE user_id=? AND game_id=?");
  $st->execute([$uid,$gameId]);
  $last = $st->fetchColumn();
  if ($last && (time() - strtotime((string)$last)) < 86400) return;

  $q = urlencode($gameName . " screenshot gameplay");
  $url = "https://api.bing.microsoft.com/v7.0/images/search?q={$q}&count=30&safeSearch=Moderate&freshness=Week&imageType=Photo";
  $data = http_json($url, ["Ocp-Apim-Subscription-Key: " . BING_API_KEY]);
  if (empty($data['value'])) return;

  $ins = $pdo->prepare("
    INSERT INTO external_media_photos
      (user_id, game_id, game_name, source, caption, url, thumb, published_at, fetched_at)
    VALUES
      (:uid, :gid, :gname, 'bing', :caption, :url, :thumb, :pub, :fetch)
    ON DUPLICATE KEY UPDATE caption=VALUES(caption), thumb=VALUES(thumb), published_at=VALUES(published_at), fetched_at=VALUES(fetched_at)
  ");
  $now = gmdate('Y-m-d H:i:s');
  foreach ($data['value'] as $v) {
    $imgUrl = (string)($v['contentUrl'] ?? ''); if ($imgUrl==='') continue;
    $thumb  = (string)($v['thumbnailUrl'] ?? '');
    $name   = (string)($v['name'] ?? $gameName);
    $dateP  = (string)($v['datePublished'] ?? '');
    $pub    = $dateP ? date('Y-m-d H:i:s', strtotime($dateP)) : null;
    $ins->execute([
      ':uid'=>$uid, ':gid'=>$gameId, ':gname'=>$gameName,
      ':caption'=>$name, ':url'=>$imgUrl, ':thumb'=>$thumb,
      ':pub'=>$pub, ':fetch'=>$now
    ]);
  }
  $pdo->prepare("DELETE FROM external_media_photos WHERE user_id=? AND game_id=? AND fetched_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)")
      ->execute([$uid,$gameId]);
}

/* Refresh for all games */
if ($uid && $games) {
  foreach ($games as $g) {
    refresh_youtube_for_game($pdo, (int)$uid, (int)$g['game_id'], $g['name']);
    refresh_bing_images_for_game($pdo, (int)$uid, (int)$g['game_id'], $g['name']);
  }
}

/* System ribbon */
if ($uid) {
  $platformSlug = slugify((string)($platform ?? ''));
  if ($platformSlug !== '' && table_exists($pdo,'system_photos')) {
    $stmt = $pdo->prepare('SELECT url, caption FROM system_photos WHERE platform_slug=? ORDER BY created_at DESC LIMIT 40');
    $stmt->execute([$platformSlug]);
    $systemRibbon = $stmt->fetchAll();
  }
  $systemRibbon = pick_daily($systemRibbon, (int)$uid, 0, 'SYS', 5);
}

/* ====== HTML ====== */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta http-equiv="Cache-Control" content="no-store" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>PlayerPath — My Top 5</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Orbitron:wght@700;800&display=swap" rel="stylesheet">
<style>
:root{ --brand:#e60023; --bg:#0e0e10; --surface:#131315; --surface-2:#191a1d; --border:#2a2b31; --ink:#f4f5f7; --ink-dim:#b8bcc4; --radius:14px; --shadow-1:0 1px 1px rgba(0,0,0,.35), 0 10px 24px rgba(0,0,0,.45); --shadow-2:0 2px 8px rgba(0,0,0,.45), 0 20px 48px rgba(0,0,0,.55); --ring:0 0 0 3px rgba(230,0,35,.28); }
*{ box-sizing:border-box } html,body{ margin:0; background:var(--bg); color:var(--ink); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-size:clamp(15px,.45vw+14px,16.5px); line-height:1.6; min-height:100%; }
a{color:inherit;text-decoration:none} img{max-width:100%;display:block}
a:focus,button:focus{outline:0;box-shadow:none;border-radius:8px} a:focus-visible,button:focus-visible{box-shadow:var(--ring)}
*::-webkit-scrollbar{ width:10px; height:10px; } *::-webkit-scrollbar-track{ background:#0b0b0d; } *::-webkit-scrollbar-thumb{ background:#2a2b31; border-radius:8px; border:2px solid #0b0b0d; } *{ scrollbar-color:#2a2b31 #0b0b0d; }

.page{min-height:100vh; display:flex; flex-direction:column;}
.top{background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:30}
.top-inner{height:74px;display:flex;align-items:center;gap:16px;max-width:1440px;margin:0 auto;padding:0 20px}
.brand{display:flex;align-items:center;gap:.6rem;font-family:Orbitron,Inter,sans-serif;font-weight:800;letter-spacing:.8px;text-transform:uppercase;font-size:clamp(1.4rem,1.1vw+1rem,1.7rem)}
.brand .half-red{color:var(--brand)} .brand .half-white{color:#fff;text-shadow:0 0 6px rgba(230,0,35,.45),0 1px 2px rgba(0,0,0,.65)}
.btn{border:none;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer;font-size:.95rem;min-height:40px;min-width:40px}
.btn.logout{background:var(--brand);color:#fff;border:1px solid #b31329;border-radius:12px;box-shadow:0 8px 22px rgba(230,0,35,.28)}
.btn.ghost{background:#0f0f12;color:#f1f2f4;border:1px solid #24252a}

.app{display:grid;grid-template-columns:220px minmax(0,1fr) 340px;grid-template-areas:"nav main rail";gap:26px;max-width:1440px;padding:26px 20px 40px;margin:0 auto;align-items:start; flex:1}
@media (max-width:1220px){.app{grid-template-columns:200px minmax(0,1fr);grid-template-areas:"nav main" "rail rail"}}
@media (max-width:980px){.app{grid-template-columns:1fr;grid-template-areas:"nav" "main" "rail"}}

.lnav{grid-area:nav;border:1px solid var(--brand);padding:18px 10px 22px 18px;display:flex;flex-direction:column;gap:6px;background:var(--bg);border-radius:14px;align-self:start;position:sticky;top:22px;max-height:calc(100vh - 44px);overflow:auto}
.lnav a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:var(--ink-dim);font-size:.98rem;min-height:40px}
.lnav a:hover,.lnav a[aria-current="page"]{background:#141416;color:#fff;font-weight:700;outline:1px solid #232326;box-shadow:0 0 0 1px rgba(230,0,35,.15) inset}
.lnav-divider{margin:12px 0;border-top:1px solid var(--brand)}

.content{grid-area:main}
.hero{border:1px solid var(--brand); padding:22px 24px; background:var(--surface); border-radius:var(--radius); margin-bottom:18px; box-shadow:var(--shadow-1)}
.card{border-radius:var(--radius);background:var(--surface);box-shadow:var(--shadow-1);border:1px solid #ffffff1a;padding:16px;margin-bottom:24px}
.card.brand{border:1px solid var(--brand);}
.row{display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start}
.cover{width:min(46vw,520px); aspect-ratio:16/9; background:#0f0f12; border:1px solid #fff; border-radius:14px; overflow:hidden}
.cover img{width:100%;height:100%;object-fit:cover}
.title{font-weight:900;font-size:1.08rem;margin:8px 0 6px}
.badge{display:inline-block;margin-right:8px;background:#141416;border:1px solid #fff;border-radius:999px;padding:2px 10px;color:#fff;font-size:.8rem}
.sec{margin-top:14px}
.sec h3{margin:0 0 8px;font-size:.98rem}
.yt{width:100%;aspect-ratio:16/9;border:1px solid #fff;border-radius:12px;overflow:hidden;background:#0e1015}
.yt iframe{width:100%;height:100%;border:0;display:block}
.list{margin:4px 0 0 0;padding-left:18px}
.list li{margin:4px 0}
.hscroll{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:4px}
.hscroll .p{flex:0 0 340px;scroll-snap-align:start}
.hscroll .ph{width:100%;height:200px;background:#0e1015;border:1px solid #fff;border-radius:12px;overflow:hidden}
.hscroll img{width:100%;height:100%;object-fit:cover;cursor:zoom-in}
.caption{font-size:.85rem;color:var(--ink-dim);margin-top:6px}

.rail{grid-area:rail}
.mix{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.mix .mimg{width:100%;aspect-ratio:16/9;border:1px solid #fff;border-radius:10px;background:#0e1015;overflow:hidden}
.vlist{display:flex;flex-direction:column;gap:10px}
.vrow{display:flex;gap:10px;align-items:center}
.vthumb{flex:0 0 120px;aspect-ratio:16/9;border:1px solid #fff;border-radius:8px;overflow:hidden;background:#0e1015}
.vthumb img{width:100%;height:100%;object-fit:cover}

footer{border-top:1px solid var(--border);background:var(--surface);margin-top:40px}
.foot{height:76px;display:flex;align-items:center;justify-content:space-between;max-width:1440px;margin:0 auto;padding:0 20px;color:#fff;font-size:.98rem}
.foot nav a{color:#b8bcc4}

.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.82);display:none;align-items:center;justify-content:center;padding:28px;z-index:1000}
.lightbox.show{display:flex}
.lb-close{position:absolute;top:14px;right:16px;font-size:28px;line-height:1;color:#fff;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:12px;padding:6px 10px 8px;cursor:pointer}
.lightbox img{max-width:min(94vw,1280px);max-height:90vh;border-radius:16px;box-shadow:0 12px 36px rgba(0,0,0,.6), 0 4px 16px rgba(230,0,35,.25)}

.sec .list a, .sec .yt + div a, .sec .hscroll .caption a { color: var(--brand); font-weight: 600; text-decoration: none; transition: background .15s, color .15s; }
.sec .list a:hover, .sec .yt + div a:hover, .sec .hscroll .caption a:hover { background:#fff; color:var(--brand); text-decoration: underline; }
</style>
</head>
<body>
<div class="page">

  <!-- Top bar -->
  <header class="top">
    <div class="top-inner">
      <div class="brand"><span class="half-red">PLAYER</span><span class="half-white">PATH</span></div>
      <div style="margin-left:auto;display:flex;gap:10px;align-items:center">
        <?php if (!$uid): ?>
          <a href="SignUp.php"><button class="btn ghost" type="button">Sign Up</button></a>
          <a href="SignIn.php"><button class="btn ghost" type="button">Sign In</button></a>
        <?php else: ?>
          <div style="color:#b8bcc4;font-size:.98rem">Welcome, <strong style="color:#fff"><?php echo h($displayName ?: 'Player'); ?></strong></div>
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn logout" type="submit" aria-label="Log out">Log Out</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Layout -->
  <div class="app">
    <!-- Left nav -->
    <nav class="lnav" aria-label="Sections">
      <a href="index.php">Home</a>
      <a href="questions.php">Questions</a>
      <a href="#">Tags</a>
      <a href="#">Leaderboard</a>
      <a href="Profiles.php">Profiles</a>
      <div class="lnav-divider"></div>
      <a href="top5.php" aria-current="page">My Top 5 Games</a>
      <a href="#">Playbooks</a>
      <a href="#">Challenges</a>
      <a href="#">Guides</a>
    </nav>

    <!-- Main -->
    <main class="content">
      <?php if ($msg = flash()): ?>
        <div class="card" style="border-color:#2a2b31;background:#1c1c1f;color:#fff"><?php echo $msg; ?></div>
      <?php endif; ?>

      <?php if (!$uid): ?>
        <section class="hero" role="alert">
          <div style="font-weight:800">Please sign in to view your Top-5 page</div>
          <div style="color:#b8bcc4">Once signed in, you’ll see weekly highlights and new internet gameplay media every 24 hours for your Top-5 games.</div>
        </section>
      <?php else: ?>
        <section class="hero">
          <div style="font-weight:800">Hi <?php echo h($display); ?> — Your Top-5 Games</div>
          <div style="color:#b8bcc4">• Questions still pick by highest upvote score (ties → newest).<br>• Internet Videos & Photos refresh every 24 hours and are scoped to your Top-5 games.</div>
        </section>

        <?php if (!$games): ?>
          <div class="card">No Top-5 yet. Add rows to <code>user_top_games</code> or fill your <code>user_profiles</code> (game1…game5), then refresh.</div>
        <?php endif; ?>

        <?php
        // —— PER-GAME LOOP
        foreach ($games as $g):
          $gid   = (int)$g['game_id'];
          $gname = $g['name'];
          $slug  = $g['slug'];
          $cover = $coversBySlug[$slug] ?? '';

          // Question of the Week (optional)
          $qTop = null;
          if ($haveQuestions) {
            [$t1,$t2,$t3] = make_like_patterns($gname,$slug);
            if ($hasQGame) {
              $st = $pdo->prepare('SELECT id,title,created_at,COALESCE(score,0) s FROM questions WHERE game_id=? ORDER BY s DESC, created_at DESC LIMIT 1');
              $st->execute([$gid]); $qTop = $st->fetch();
            }
            if (!$qTop) {
              $st = $pdo->prepare('SELECT id,title,created_at,COALESCE(score,0) s FROM questions WHERE (title LIKE ? OR body LIKE ?) OR (title LIKE ? OR body LIKE ?) OR (title LIKE ? OR body LIKE ?) ORDER BY s DESC, created_at DESC LIMIT 1');
              $st->execute([$t1,$t1,$t2,$t2,$t3,$t3]); $qTop = $st->fetch();
            }
          }

          // -------- Internet videos (robust) ----------
          // 1) read cache
          $st = $pdo->prepare("SELECT title,url,thumb,published_at FROM external_media_videos WHERE user_id=? AND game_id=? ORDER BY fetched_at DESC, published_at DESC, id DESC LIMIT 80");
          $st->execute([$uid,$gid]);
          $vids = $st->fetchAll();

          // 2) if empty, force a refresh now and re-read
          if (!$vids) {
            refresh_youtube_for_game($pdo, (int)$uid, $gid, $gname, true);
            $st->execute([$uid,$gid]);
            $vids = $st->fetchAll();
          }

          // 3) if still empty, fetch one video directly via RSS and display immediately
          $onTheFly = null;
          if (!$vids) {
            foreach (yt_make_queries($gname) as $qv) {
              $rows = ytrss_fetch_ids($qv, 1);
              if ($rows) {
                $r = $rows[0];
                yt_upsert($pdo,(int)$uid,$gid,$gname,(string)$r['title'],$r['id'],(string)$r['published'],'youtube-rss');
                $onTheFly = [
                  'title' => (string)$r['title'],
                  'url'   => "https://www.youtube.com/watch?v=".$r['id'],
                  'thumb' => "https://i.ytimg.com/vi/".$r['id']."/hqdefault.jpg",
                  'published_at' => $r['published'] ? date('Y-m-d H:i:s', strtotime($r['published'])) : null,
                ];
                break;
              }
            }
          }

          // 4) FINAL FALLBACK: local videos table
          if (!$vids && !$onTheFly && $haveVideosTbl) {
            $fallback = [];
            if ($hasVGame) {
              $vst = $pdo->prepare("SELECT title,url,created_at FROM videos WHERE game_id=? ".($hasVPlay?'AND is_gameplay=1 ':'')." ORDER BY created_at DESC LIMIT 10");
              $vst->execute([$gid]); $fallback = $vst->fetchAll();
            }
            if (!$fallback) {
              [$tt1,$tt2,$tt3] = make_like_patterns($gname,$slug);
              $vst = $pdo->prepare("SELECT title,url,created_at FROM videos WHERE (title LIKE ? OR title LIKE ? OR title LIKE ?) ".($hasVPlay?'AND is_gameplay=1 ':'')." ORDER BY created_at DESC LIMIT 10");
              $vst->execute([$tt1,$tt2,$tt3]); $fallback = $vst->fetchAll();
            }
            $vids = array_map(fn($r)=>['title'=>$r['title']??'Watch','url'=>$r['url']??'','thumb'=>null,'published_at'=>$r['created_at']??null], $fallback);
          }

          // Pick the one to show
          $extVideo = $onTheFly ?: (pick_daily($vids, (int)$uid, $gid, 'V-DAILY', 1)[0] ?? null);

          // Photos
          $st = $pdo->prepare("SELECT caption,url,thumb,published_at FROM external_media_photos WHERE user_id=? AND game_id=? ORDER BY fetched_at DESC, published_at DESC, id DESC LIMIT 18");
          $st->execute([$uid,$gid]);
          $phos = $st->fetchAll();
          $extPhotos = array_slice($phos, 0, 6);
        ?>
        <section class="card" id="g<?php echo $gid; ?>" style="border:1px solid #fff;border-radius:20px">
          <div class="row">
            <div class="cover"><?php if ($cover): ?><img src="<?php echo h($cover); ?>" alt="cover"><?php endif; ?></div>

            <div style="flex:1 1 520px;min-width:320px">
              <div class="title"><span class="badge">#<?php echo (int)$g['rank']; ?></span><?php echo h($g['name']); ?></div>

              <!-- Question of the Week -->
              <div class="sec">
                <h3>Question of the Week</h3>
                <?php if ($qTop): ?>
                  <?php $qid = (int)($qTop['id'] ?? 0); ?>
                  <?php if (!is_sunday()): ?>
                    <div style="color:#b8bcc4">Revealed on Sundays (highest score wins).</div>
                  <?php else: ?>
                    <ul class="list">
                      <li>
                        <a href="<?php echo 'Profiles.php?focus=' . $qid; ?>"><?php echo h($qTop['title']); ?></a>
                        <?php if (!empty($qTop['created_at'])): ?><span style="color:#b8bcc4"> · <?php echo h((string)$qTop['created_at']); ?></span><?php endif; ?>
                        <span class="badge">Score: <?php echo (int)($qTop['s'] ?? 0); ?></span>
                      </li>
                    </ul>
                  <?php endif; ?>
                <?php else: ?>
                  <div style="color:#b8bcc4">No questions yet.</div>
                <?php endif; ?>
              </div>

              <!-- Internet Video -->
              <div class="sec">
                <h3>Internet Gameplay Video (Last 24h)</h3>
                <?php if (!$extVideo): ?>
                  <div style="color:#b8bcc4">No internet videos cached yet for this game.</div>
                <?php else:
                  $vidUrl = (string)($extVideo['url'] ?? '');
                  $vidId  = (preg_match('~(?:youtu\.be/|v=|watch\?v=|embed/)([A-Za-z0-9_\-]{6,})~', $vidUrl, $m) ? $m[1] : null);
                ?>
                  <div class="yt">
                    <?php if ($vidId): ?>
                      <iframe src="https://www.youtube.com/embed/<?php echo h($vidId); ?>" title="YouTube video player" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                    <?php else:
                      $thumb = (string)($extVideo['thumb'] ?? '');
                      if ($thumb): ?><img src="<?php echo h($thumb); ?>" alt="Video"><?php endif;
                    endif; ?>
                  </div>
                  <div style="margin-top:6px">
                    <a href="<?php echo h($vidUrl); ?>" target="_blank" rel="noopener"><?php echo h($extVideo['title'] ?? 'Watch'); ?></a>
                    <?php if (!empty($extVideo['published_at'])): ?><span style="color:#b8bcc4"> · <?php echo h((string)$extVideo['published_at']); ?></span><?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Photos -->
              <div class="sec">
                <h3>Internet Gameplay Photos (Last 24h)</h3>
                <?php if (!$extPhotos): ?>
                  <div style="color:#b8bcc4">Add your <code>BING_API_KEY</code> in <code>config.php</code> to enable daily photos.</div>
                <?php else: ?>
                  <div class="hscroll" role="list">
                    <?php foreach ($extPhotos as $ph): ?>
                    <div class="p" role="listitem">
                      <div class="ph"><img src="<?php echo h((string)$ph['thumb'] ?: (string)$ph['url']); ?>" alt="<?php echo h($ph['caption'] ?: $gname); ?>" data-zoom></div>
                      <div class="caption">
                        <?php if (!empty($ph['caption'])): ?><?php echo h($ph['caption']); ?> · <?php endif; ?>
                        <?php if (!empty($ph['published_at'])): ?><span><?php echo h((string)$ph['published_at']); ?></span><?php endif; ?>
                        <span> &nbsp; <a href="<?php echo h((string)$ph['url']); ?>" target="_blank" rel="noopener">Open</a></span>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

            </div>
          </div>
        </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </main>

    <!-- Right rail -->
    <aside class="rail" aria-label="Sidebar">
      <?php if ($uid): ?>
        <div class="card brand" style="margin-bottom:16px">
          <h3 style="margin:0 0 10px;font-size:1rem">Daily Internet Photo Mix</h3>
          <?php
          $mix = [];
          if ($topIds) {
            $in = implode(',', array_fill(0, count($topIds), '?'));
            $st = $pdo->prepare("SELECT url, thumb, caption FROM external_media_photos WHERE user_id=? AND game_id IN ($in) ORDER BY fetched_at DESC, published_at DESC LIMIT 80");
            $bind = array_merge([$uid], $topIds); $st->execute($bind);
            $pool = $st->fetchAll();
            $mix = $pool ? pick_daily($pool, (int)$uid, 0, 'P-MIX-EXT', min(8,count($pool))) : [];
          }
          ?>
          <?php if ($mix): ?>
            <div class="mix">
              <?php foreach ($mix as $m): ?>
              <div class="mimg"><img src="<?php echo h((string)($m['thumb'] ?: $m['url'])); ?>" alt="<?php echo h($m['caption'] ?? 'Gameplay'); ?>" data-zoom></div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="color:#b8bcc4">No internet photos yet.</div>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3 style="margin:0 0 10px;font-size:1rem">Daily Internet Video Mix</h3>
          <?php
          $vPicksDaily = [];
          if ($topIds) {
            $in = implode(',', array_fill(0, count($topIds), '?'));
            $st = $pdo->prepare("SELECT title, url, thumb, published_at FROM external_media_videos WHERE user_id=? AND game_id IN ($in) ORDER BY fetched_at DESC, published_at DESC LIMIT 60");
            $bind = array_merge([$uid], $topIds); $st->execute($bind);
            $pool = $st->fetchAll();
            if ($pool) {
              $seen = [];
              foreach ($pool as $v) {
                $url = (string)($v['url'] ?? ''); if ($url==='') continue;
                if (!preg_match('~(?:youtu\.be/|v=|watch\?v=|embed/)([A-Za-z0-9_\-]{6,})~', $url, $m)) { $m = [null, $url]; }
                $key = $m[1];
                if (!isset($seen[$key])) { $seen[$key]=true; $vPicksDaily[]=$v; }
                if (count($vPicksDaily)>=5) break;
              }
              $vPicksDaily = pick_daily($vPicksDaily, (int)$uid, 0, 'V-MIX-EXT', count($vPicksDaily));
            }
          }
          ?>
          <?php if ($vPicksDaily): ?>
            <div class="vlist">
              <?php foreach ($vPicksDaily as $v): $url=(string)($v['url']??''); $thumb=(string)($v['thumb']??''); ?>
                <a class="vrow" href="<?php echo h($url); ?>" target="_blank" rel="noopener">
                  <div class="vthumb"><?php if ($thumb): ?><img src="<?php echo h($thumb); ?>" alt="Video"><?php endif; ?></div>
                  <div>
                    <div style="font-weight:700"><?php echo h($v['title'] ?? 'Watch'); ?></div>
                    <?php if (!empty($v['published_at'])): ?><div style="color:#b8bcc4;font-size:.9rem"><?php echo h((string)$v['published_at']); ?></div><?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="color:#b8bcc4">No internet videos yet.</div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="card">
          <h3 style="margin:0 0 10px;font-size:1rem">Welcome to PlayerPath</h3>
          <div style="color:#b8bcc4">Sign in to see your personalized weekly highlights and daily internet mixes.</div>
        </div>
      <?php endif; ?>
    </aside>
  </div>

  <!-- Lightbox -->
  <div id="lightbox" class="lightbox" role="dialog" aria-modal="true" aria-label="Image preview" tabindex="-1">
    <button class="lb-close" type="button" aria-label="Close preview" onclick="ppCloseLightbox()">×</button>
    <img id="lightbox-img" alt="">
  </div>

  <footer>
    <div class="foot">
      <div>© <span id="yr"></span> <span style="font-family:Orbitron;font-weight:800"><span style="color:var(--brand)">PLAYER</span><span style="color:#fff">PATH</span></span></div>
      <nav><a href="#">About</a> &nbsp;•&nbsp; <a href="#">How It Works</a> &nbsp;•&nbsp; <a href="#">FAQ</a></nav>
    </div>
  </footer>

</div>

<script>
// Year + BF cache safety
document.getElementById('yr').textContent = new Date().getFullYear();
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

// Lightbox
const lb = document.getElementById('lightbox');
const lbImg = document.getElementById('lightbox-img');
function ppOpenLightbox(src){ if (!src) return; lbImg.src = src; lb.classList.add('show'); document.body.style.overflow = 'hidden'; lb.focus(); }
function ppCloseLightbox(){ lb.classList.remove('show'); lbImg.src = ''; document.body.style.overflow = ''; }
lb.addEventListener('click', (e) => { if (e.target === lb) ppCloseLightbox(); });
window.addEventListener('keydown', (e) => { if (e.key === 'Escape') ppCloseLightbox(); });

// Zoom any image with [data-zoom]
document.addEventListener('click', (e) => {
  const img = e.target.closest('[data-zoom]'); if (!img) return;
  ppOpenLightbox(img.getAttribute('src'));
});
</script>
</body>
</html>
