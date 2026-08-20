<?php
/* =====================================================================
   crm/index.php — لوحة CRM بسيطة وآمنة لعيادة د. تحسين يونس.
   - كل إرسال من الفورم (send.php) يُخزَّن في data/leads.ndjson.
   - محمية بكلمة مرور (إعداد أول مرة ثم تسجيل دخول).
   - البيانات وكلمة المرور تُنشأ وقت التشغيل على الخادم ولا تُرفع للمستودع.
   ===================================================================== */

session_start();

require __DIR__ . '/store.php';
$CFG    = is_file(__DIR__ . '/config.php') ? (include __DIR__ . '/config.php') : null;

$DATA   = crm_data_dir();             // مجلد ثابت خارج مجلد النشر
$LEADS  = $DATA . '/leads.ndjson';    // سطر JSON لكل ليد
$STATUS = $DATA . '/status.json';     // { id: "new"|"contacted"|"done"|"not_interested" }
$NOTES  = $DATA . '/notes.json';      // { id: [ {"t":"2026-08-20 14:05","txt":"..."} , ... ] }

/* حالات الليد — مكان واحد لإضافة/تعديل أي حالة (المفتاح => التسمية العبرية) */
$ST_LBL = [
    'new'            => 'חדש',
    'contacted'      => 'נוצר קשר',
    'done'           => 'טופל',
    'not_interested' => 'לא מעוניין',
];

/* ---------- أدوات ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function load_leads($f){
    $out = [];
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
            $o = json_decode($ln, true);
            if (is_array($o)) $out[] = $o;
        }
    }
    return $out;
}
function load_status($f){
    if (is_file($f)) { $j = json_decode(file_get_contents($f), true); if (is_array($j)) return $j; }
    return [];
}
function save_status($f, $a){ @file_put_contents($f, json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX); }
function load_notes($f){
    if (is_file($f)) { $j = json_decode(file_get_contents($f), true); if (is_array($j)) return $j; }
    return [];
}
function save_notes($f, $a){ @file_put_contents($f, json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX); }
function csrf(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_ok(){ return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }

$authed  = !empty($_SESSION['crm_auth']);

/* ---------- تسجيل الخروج ---------- */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php'); exit;
}

/* ---------- تسجيل الدخول (مستخدم/كلمة مرور ثابتان من config.php) ---------- */
if (!$authed) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = (string)($_POST['user'] ?? '');
        $p = (string)($_POST['pw'] ?? '');
        if (!csrf_ok())                                        $err = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        elseif (!$CFG || !isset($CFG['user'], $CFG['hash']))   $err = 'שגיאת הגדרה בשרת.';
        elseif (hash_equals((string)$CFG['user'], $u) && password_verify($p, (string)$CFG['hash'])) {
            $_SESSION['crm_auth'] = true; session_regenerate_id(true);
            header('Location: index.php'); exit;
        } else $err = 'שם משתמש או סיסמה שגויים.';
    }
    render_shell('כניסה', function () use ($err) { ?>
        <h1>כניסת ניהול</h1>
        <p class="sub">מערכת פניות — מרפאת ד״ר תחסין יונס</p>
        <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <label>שם משתמש<input type="text" name="user" required autofocus autocomplete="username"></label>
            <label>סיסמה<input type="password" name="pw" required autocomplete="current-password"></label>
            <button type="submit">כניסה</button>
        </form>
    <?php });
    exit;
}

/* ================= مصادَق عليه من هنا فصاعداً ================= */

/* ---------- إجراءات AJAX (حالة / حذف) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }
    $id = (string)($_POST['id'] ?? '');

    if ($_POST['action'] === 'status') {
        $val = in_array($_POST['value'] ?? '', array_keys($ST_LBL), true) ? $_POST['value'] : 'new';
        $st = load_status($STATUS); $st[$id] = $val; save_status($STATUS, $st);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($_POST['action'] === 'delete') {
        $leads = load_leads($LEADS);
        $kept = array_filter($leads, fn($l) => ($l['id'] ?? '') !== $id);
        $lines = array_map(fn($l) => json_encode($l, JSON_UNESCAPED_UNICODE), $kept);
        @file_put_contents($LEADS, $lines ? implode("\n", $lines) . "\n" : '', LOCK_EX);
        $st = load_status($STATUS); unset($st[$id]); save_status($STATUS, $st);
        $nt = load_notes($NOTES);   unset($nt[$id]); save_notes($NOTES, $nt);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($_POST['action'] === 'note_add') {
        $txt = trim((string)($_POST['text'] ?? ''));
        $txt = str_replace(array("\r\n", "\r"), "\n", $txt);
        $txt = preg_replace('/[^\P{C}\n\t]+/u', '', $txt);
        if ($id === '' || $txt === '') { echo json_encode(['ok'=>false]); exit; }
        if (function_exists('mb_substr') && mb_strlen($txt, 'UTF-8') > 2000) $txt = mb_substr($txt, 0, 2000, 'UTF-8');
        $nt = load_notes($NOTES);
        if (!isset($nt[$id]) || !is_array($nt[$id])) $nt[$id] = [];
        $note = ['t' => date('Y-m-d H:i'), 'txt' => $txt];
        $nt[$id][] = $note;
        save_notes($NOTES, $nt);
        echo json_encode(['ok'=>true, 'note'=>$note, 'count'=>count($nt[$id])]); exit;
    }
    if ($_POST['action'] === 'gads_genkey') {
        $key = bin2hex(random_bytes(20));
        @file_put_contents($DATA . '/gads_key.txt', $key, LOCK_EX);
        echo json_encode(['ok'=>true, 'key'=>$key]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

$leads  = load_leads($LEADS);
$status = load_status($STATUS);
$notes  = load_notes($NOTES);
usort($leads, fn($a,$b) => strcmp($b['id'] ?? '', $a['id'] ?? '')); // الأحدث أولاً
$gads_key = is_file($DATA . '/gads_key.txt') ? trim(file_get_contents($DATA . '/gads_key.txt')) : '';
$gads_url = ((($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'younisclinic.com') . '/crm/gads-webhook.php';

/* ---------- تصدير CSV ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="younis-leads.csv"');
    echo "\xEF\xBB\xBF"; // BOM لإكسل
    $out = fopen('php://output', 'w');
    fputcsv($out, ['תאריך','שם','טלפון','דוא״ל','טיפול','הודעה','מקור','סטטוס','הערות']);
    foreach ($leads as $l) {
        $lid = $l['id'] ?? '';
        $s   = $status[$lid] ?? 'new';
        $nl  = [];
        foreach (($notes[$lid] ?? []) as $n) $nl[] = '[' . ($n['t'] ?? '') . '] ' . ($n['txt'] ?? '');
        fputcsv($out, [$l['ts']??'', $l['name']??'', $l['phone']??'', $l['email']??'', $l['interest']??'', $l['msg']??'', $l['source']??'', $ST_LBL[$s]??$s, implode(chr(10), $nl)]);
    }
    fclose($out); exit;
}

/* ---------- إحصاءات ---------- */
$today = date('Y-m-d');
$countToday = 0; $countNew = 0;
foreach ($leads as $l) {
    if (strpos((string)($l['ts'] ?? ''), $today) === 0) $countToday++;
    if (($status[$l['id'] ?? ''] ?? 'new') === 'new') $countNew++;
}

/* ================= واجهة اللوحة ================= */
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>CRM · מרפאת ד״ר תחסין יונס</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
  :root{--teal:#0B6F70;--teal2:#1AADAD;--pale:#EFF8F7;--ink:#231F20;--line:#e3edec;--muted:#6a7a7a}
  *{box-sizing:border-box}
  body{margin:0;font-family:"Heebo",Arial,sans-serif;background:#f4f8f8;color:var(--ink)}
  .top{background:var(--teal);color:#fff;padding:14px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
  .top h1{font-size:1.15rem;margin:0;font-weight:800}
  .top .spacer{flex:1}
  .top a.logout{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:8px 14px;border-radius:999px;font-size:.9rem}
  .top a.logout:hover{background:rgba(255,255,255,.28)}
  .wrap{max-width:1200px;margin:0 auto;padding:20px}
  .stats{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px}
  .stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 18px;min-width:130px}
  .stat b{display:block;font-size:1.7rem;color:var(--teal);line-height:1}
  .stat span{font-size:.85rem;color:var(--muted)}
  .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
  .toolbar input[type=search],.toolbar select{padding:10px 14px;border:1px solid var(--line);border-radius:10px;font-size:.95rem;font-family:inherit;background:#fff}
  .toolbar input[type=search]{min-width:240px;flex:1}
  .toolbar a.btn{background:var(--teal);color:#fff;text-decoration:none;padding:10px 16px;border-radius:10px;font-size:.9rem;font-weight:700}
  .toolbar a.btn:hover{background:#095657}
  .table-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:auto}
  table{width:100%;border-collapse:collapse;font-size:.92rem;min-width:900px}
  th,td{padding:12px 14px;text-align:right;border-bottom:1px solid var(--line);vertical-align:top}
  th{background:var(--pale);color:var(--teal);font-weight:700;white-space:nowrap;position:sticky;top:0}
  tr:hover td{background:#fafdfd}
  td.msg{max-width:280px;white-space:pre-wrap;word-break:break-word;color:#3f4f4f}
  a.lnk{color:var(--teal);text-decoration:none}
  a.lnk:hover{text-decoration:underline}
  .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700}
  select.st{padding:6px 8px;border-radius:8px;border:1px solid var(--line);font-family:inherit;font-size:.85rem;cursor:pointer}
  .st-new{background:#fff4e0;color:#a86400}
  .st-contacted{background:#e5f0ff;color:#1857b8}
  .st-done{background:#e4f6ec;color:#1c7a45}
  .del{background:none;border:0;color:#c0392b;cursor:pointer;font-size:1.05rem;padding:4px 8px;border-radius:8px}
  .del:hover{background:#fdeceA;background:#fdecea}
  .empty{padding:50px 20px;text-align:center;color:var(--muted)}
  .src{font-size:.8rem;color:var(--muted)}
  @media(max-width:640px){.wrap{padding:12px}}
  .gads{background:#fff;border:1px solid var(--line);border-radius:14px;margin-bottom:16px;padding:0 18px}
  .gads summary{cursor:pointer;font-weight:700;color:var(--teal);padding:14px 0}
  .gads-body{padding:0 0 16px}
  .gads-body label{display:block;font-size:.8rem;color:var(--muted);margin:10px 0 4px}
  .gads .cp{display:flex;gap:8px;align-items:center}
  .gads code{flex:1;background:#f4f8f8;border:1px solid var(--line);border-radius:8px;padding:9px 12px;font-size:.86rem;word-break:break-all;direction:ltr;text-align:left}
  .gads .cp button,.gads .gen{background:var(--teal);color:#fff;border:0;border-radius:8px;padding:9px 14px;font-weight:700;cursor:pointer;font-size:.85rem}
  .gads .gen{margin-top:14px}
  .gads-help{color:var(--muted);font-size:.82rem;line-height:1.6;margin-top:12px}
  .st-not_interested{background:#f0f1f2;color:#5c6564}
  .tzhint{font-size:.78rem;color:rgba(255,255,255,.85);white-space:nowrap}
  .nbtn{background:var(--pale);border:1px solid var(--line);border-radius:9px;padding:6px 10px;cursor:pointer;font-family:inherit;font-size:.82rem;color:var(--teal);white-space:nowrap;font-weight:700}
  .nbtn:hover{background:#e1f1f0}
  tr.has-notes .nbtn{background:#e4f6ec;border-color:#c7e8d5;color:#1c7a45}
  tr.nrow>td{background:#fbfdfd;border-bottom:2px solid var(--line)}
  .notes{display:flex;flex-direction:column;gap:10px;max-width:820px}
  .nlist{display:flex;flex-direction:column;gap:6px}
  .note{background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 11px;font-size:.88rem;white-space:pre-wrap;word-break:break-word;line-height:1.55}
  .note .nt{display:block;font-size:.74rem;color:var(--muted);margin-bottom:2px}
  .nempty{color:var(--muted);font-size:.85rem}
  .nform{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap}
  .nform textarea{flex:1;min-width:220px;padding:9px 12px;border:1px solid var(--line);border-radius:10px;font-family:inherit;font-size:.9rem;resize:vertical;background:#fff}
  .nform button{background:var(--teal);color:#fff;border:0;border-radius:10px;padding:10px 15px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.88rem}
  .nform button:hover{background:#095657}
  .nform button:disabled{opacity:.6;cursor:default}
  .nform .nmsg{font-size:.82rem;color:var(--muted);align-self:center}
</style>
</head>
<body>
<div class="top">
  <h1>CRM · מרפאת ד״ר תחסין יונס</h1>
  <span class="tzhint">🕒 שעון ישראל · <?= h(date('d/m/Y H:i')) ?></span>
  <div class="spacer"></div>
  <a class="logout" href="?logout=1">יציאה ←</a>
</div>
<div class="wrap">
  <div class="stats">
    <div class="stat"><b><?= count($leads) ?></b><span>סה״כ פניות</span></div>
    <div class="stat"><b><?= $countToday ?></b><span>פניות היום</span></div>
    <div class="stat"><b><?= $countNew ?></b><span>ממתינות לטיפול</span></div>
  </div>

  <details class="gads">
    <summary>חיבור Google Ads (Webhook) — פרטים להדבקה במערכת Google Ads</summary>
    <div class="gads-body">
      <label>Webhook URL</label>
      <div class="cp"><code id="gadsUrl"><?= h($gads_url) ?></code><button type="button" data-copy="gadsUrl">העתק</button></div>
      <label>Key (מפתח)</label>
      <div class="cp"><code id="gadsKey"><?= $gads_key !== '' ? h($gads_key) : '— טרם נוצר —' ?></code><button type="button" data-copy="gadsKey">העתק</button></div>
      <button type="button" id="gadsGen" class="gen"><?= $gads_key !== '' ? 'יצירת מפתח חדש' : 'יצירת מפתח' ?></button>
      <p class="gads-help">ב־Google Ads: נכס טופס לידים → אפשרויות מסירה (Delivery) → Webhook. הדביקו את ה־URL ואת ה־Key למעלה, ואז שלחו „Send test data”. הלידים יופיעו כאן אוטומטית.</p>
    </div>
  </details>

  <div class="toolbar">
    <input type="search" id="q" placeholder="חיפוש לפי שם / טלפון / דוא״ל / הודעה…">
    <select id="fstatus">
      <option value="">כל הסטטוסים</option>
      <?php foreach ($ST_LBL as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
    </select>
    <a class="btn" href="?export=csv">⬇ ייצוא CSV</a>
  </div>

  <div class="table-card">
    <?php if (!$leads): ?>
      <div class="empty">אין עדיין פניות. פניות מהאתר יופיעו כאן אוטומטית.</div>
    <?php else: ?>
    <table id="tbl">
      <thead>
        <tr><th>תאריך</th><th>שם</th><th>טלפון</th><th>דוא״ל</th><th>טיפול</th><th>הודעה</th><th>מקור</th><th>הערות</th><th>סטטוס</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $l):
          $id=$l['id']??''; $s=$status[$id]??'new'; $ns=$notes[$id]??[];
          $ntxt=''; foreach ($ns as $n) $ntxt .= ' ' . ($n['txt'] ?? ''); ?>
        <tr class="lead<?= $ns ? ' has-notes' : '' ?>" data-id="<?= h($id) ?>" data-status="<?= h($s) ?>" data-notes="<?= h(trim($ntxt)) ?>">
          <td style="white-space:nowrap"><?= h($l['ts']??'') ?></td>
          <td><?= h($l['name']??'') ?></td>
          <td style="white-space:nowrap"><?php if(!empty($l['phone'])): ?><a class="lnk" dir="ltr" href="tel:<?= h(preg_replace('/[^0-9+]/','',$l['phone'])) ?>"><?= h($l['phone']) ?></a><?php endif; ?></td>
          <td><?php if(!empty($l['email'])): ?><a class="lnk" dir="ltr" href="mailto:<?= h($l['email']) ?>"><?= h($l['email']) ?></a><?php endif; ?></td>
          <td><?= h($l['interest']??'') ?></td>
          <td class="msg"><?= h($l['msg']??'') ?></td>
          <td class="src"><?= h($l['source']??'') ?></td>
          <td><button type="button" class="nbtn" title="הצגת/הוספת הערות">📝 הערות <span class="ncount"><?= $ns ? '('.count($ns).')' : '' ?></span></button></td>
          <td>
            <select class="st st-<?= h($s) ?>" data-id="<?= h($id) ?>">
              <?php foreach($ST_LBL as $k=>$v): ?><option value="<?= h($k) ?>"<?= $s===$k?' selected':'' ?>><?= h($v) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td><button class="del" data-id="<?= h($id) ?>" title="מחיקה">🗑</button></td>
        </tr>
        <tr class="nrow" hidden>
          <td colspan="10">
            <div class="notes">
              <div class="nlist">
                <?php foreach ($ns as $n): ?>
                <div class="note"><span class="nt"><?= h($n['t']??'') ?></span><span class="nx"><?= h($n['txt']??'') ?></span></div>
                <?php endforeach; ?>
                <?php if (!$ns): ?><div class="nempty">אין הערות עדיין.</div><?php endif; ?>
              </div>
              <form class="nform" data-id="<?= h($id) ?>">
                <textarea rows="2" placeholder="הוסיפו הערה — למשל: נוצר קשר עם הפונה, ביקש שנחזור אליו מחר בבוקר…"></textarea>
                <button type="submit">שמירת הערה</button>
                <span class="nmsg"></span>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
  var CSRF = <?= json_encode(csrf()) ?>;
  function post(data){
    data.csrf = CSRF;
    return fetch('index.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(data)})
      .then(function(r){return r.json();});
  }
  // Google Ads webhook: generate key + copy
  document.querySelectorAll('[data-copy]').forEach(function(b){
    b.addEventListener('click', function(){
      var t=document.getElementById(b.getAttribute('data-copy'));
      navigator.clipboard.writeText(t.textContent.trim()).then(function(){b.textContent='הועתק ✓';setTimeout(function(){b.textContent='העתק';},1500);});
    });
  });
  var gen=document.getElementById('gadsGen');
  if(gen) gen.addEventListener('click', function(){
    if(document.getElementById('gadsKey').textContent.indexOf('—')===-1 && !confirm('יצירת מפתח חדש תבטל את המפתח הקיים ב־Google Ads. להמשיך?')) return;
    gen.disabled=true; gen.textContent='יוצר…';
    post({action:'gads_genkey'}).then(function(r){
      if(r&&r.ok){document.getElementById('gadsKey').textContent=r.key;gen.textContent='יצירת מפתח חדש';}
      else gen.textContent='שגיאה — נסו שוב';
      gen.disabled=false;
    });
  });

  var q = document.getElementById('q'), fs = document.getElementById('fstatus'), tbl = document.getElementById('tbl');
  function applyFilter(){
    if(!tbl) return;
    var term=(q.value||'').toLowerCase(), st=fs.value;
    tbl.querySelectorAll('tbody tr.lead').forEach(function(tr){
      var hay = (tr.innerText + ' ' + (tr.getAttribute('data-notes')||'')).toLowerCase();
      var okText = !term || hay.indexOf(term)>-1;
      var okStat = !st || tr.getAttribute('data-status')===st;
      var show = okText && okStat;
      tr.style.display = show ? '' : 'none';
      var nr = tr.nextElementSibling;
      if (nr && nr.classList.contains('nrow')) nr.style.display = show ? '' : 'none';
    });
  }
  if(q) q.addEventListener('input', applyFilter);
  if(fs) fs.addEventListener('change', applyFilter);

  document.querySelectorAll('select.st').forEach(function(sel){
    sel.addEventListener('change', function(){
      var id=sel.getAttribute('data-id'), val=sel.value, tr=sel.closest('tr');
      sel.className='st st-'+val; tr.setAttribute('data-status',val);
      post({action:'status', id:id, value:val}).then(applyFilter);
    });
  });
  // הערות: פתיחה/סגירה
  document.querySelectorAll('.nbtn').forEach(function(b){
    b.addEventListener('click', function(){
      var nr = b.closest('tr').nextElementSibling;
      if(!nr || !nr.classList.contains('nrow')) return;
      nr.hidden = !nr.hidden;
      if(!nr.hidden){ var ta = nr.querySelector('textarea'); if(ta) ta.focus(); }
    });
  });
  // הערות: שמירה
  document.querySelectorAll('.nform').forEach(function(f){
    f.addEventListener('submit', function(e){
      e.preventDefault();
      var id  = f.getAttribute('data-id'),
          ta  = f.querySelector('textarea'),
          btn = f.querySelector('button'),
          msg = f.querySelector('.nmsg'),
          txt = (ta.value||'').trim();
      if(!txt) { ta.focus(); return; }
      btn.disabled = true; msg.textContent = 'שומר…';
      post({action:'note_add', id:id, text:txt}).then(function(r){
        btn.disabled = false;
        if(!(r && r.ok)) { msg.textContent = 'שגיאה — נסו שוב'; return; }
        var list = f.parentNode.querySelector('.nlist'),
            em   = list.querySelector('.nempty');
        if(em) em.remove();
        var d  = document.createElement('div'); d.className = 'note';
        var s1 = document.createElement('span'); s1.className = 'nt'; s1.textContent = r.note.t;
        var s2 = document.createElement('span'); s2.className = 'nx'; s2.textContent = r.note.txt;
        d.appendChild(s1); d.appendChild(s2); list.appendChild(d);
        ta.value = ''; msg.textContent = 'נשמר ✓';
        setTimeout(function(){ msg.textContent = ''; }, 1600);
        var tr = f.closest('tr').previousElementSibling;
        if(tr){
          var c = tr.querySelector('.ncount'); if(c) c.textContent = '('+r.count+')';
          tr.classList.add('has-notes');
          tr.setAttribute('data-notes', ((tr.getAttribute('data-notes')||'') + ' ' + r.note.txt).trim());
        }
      });
    });
  });

  document.querySelectorAll('.del').forEach(function(b){
    b.addEventListener('click', function(){
      if(!confirm('למחוק פנייה זו לצמיתות?')) return;
      var id=b.getAttribute('data-id'), tr=b.closest('tr');
      post({action:'delete', id:id}).then(function(r){
        if(!r.ok) return;
        var nr = tr.nextElementSibling;
        if (nr && nr.classList.contains('nrow')) nr.remove();
        tr.remove();
      });
    });
  });
</script>
</body>
</html>
<?php
/* ---------- قالب صفحات الدخول/الإعداد ---------- */
function render_shell($title, $body){ ?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · CRM</title>
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0B6F70;font-family:"Heebo",Arial,sans-serif;padding:20px}
  .box{background:#fff;border-radius:20px;padding:34px 30px;width:min(380px,100%);box-shadow:0 20px 60px rgba(0,0,0,.25)}
  h1{margin:0 0 6px;font-size:1.4rem;color:#0B6F70}
  .sub{margin:0 0 18px;color:#6a7a7a;font-size:.92rem}
  label{display:block;margin-bottom:14px;font-size:.9rem;color:#231F20}
  input{width:100%;margin-top:6px;padding:11px 13px;border:1px solid #d8e5e4;border-radius:10px;font-size:1rem;font-family:inherit}
  button{width:100%;padding:12px;border:0;border-radius:999px;background:#0B6F70;color:#fff;font-weight:800;font-size:1rem;cursor:pointer}
  button:hover{background:#095657}
  .err{background:#fdecea;color:#c0392b;padding:10px 12px;border-radius:10px;margin-bottom:14px;font-size:.9rem}
</style>
</head>
<body><div class="box"><?php $body(); ?></div></body>
</html>
<?php }
