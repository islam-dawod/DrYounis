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
$STATUS = $DATA . '/status.json';     // { id: "new"|"contacted"|"done" }

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
        $val = in_array($_POST['value'] ?? '', ['new','contacted','done'], true) ? $_POST['value'] : 'new';
        $st = load_status($STATUS); $st[$id] = $val; save_status($STATUS, $st);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($_POST['action'] === 'delete') {
        $leads = load_leads($LEADS);
        $kept = array_filter($leads, fn($l) => ($l['id'] ?? '') !== $id);
        $lines = array_map(fn($l) => json_encode($l, JSON_UNESCAPED_UNICODE), $kept);
        @file_put_contents($LEADS, $lines ? implode("\n", $lines) . "\n" : '', LOCK_EX);
        $st = load_status($STATUS); unset($st[$id]); save_status($STATUS, $st);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

$leads  = load_leads($LEADS);
$status = load_status($STATUS);
usort($leads, fn($a,$b) => strcmp($b['id'] ?? '', $a['id'] ?? '')); // الأحدث أولاً

/* ---------- تصدير CSV ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="younis-leads.csv"');
    echo "\xEF\xBB\xBF"; // BOM لإكسل
    $out = fopen('php://output', 'w');
    fputcsv($out, ['תאריך','שם','טלפון','דוא״ל','טיפול','הודעה','מקור','סטטוס']);
    $lbl = ['new'=>'חדש','contacted'=>'נוצר קשר','done'=>'טופל'];
    foreach ($leads as $l) {
        $s = $status[$l['id'] ?? ''] ?? 'new';
        fputcsv($out, [$l['ts']??'', $l['name']??'', $l['phone']??'', $l['email']??'', $l['interest']??'', $l['msg']??'', $l['source']??'', $lbl[$s]??$s]);
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
</style>
</head>
<body>
<div class="top">
  <h1>CRM · מרפאת ד״ר תחסין יונס</h1>
  <div class="spacer"></div>
  <a class="logout" href="?logout=1">יציאה ←</a>
</div>
<div class="wrap">
  <div class="stats">
    <div class="stat"><b><?= count($leads) ?></b><span>סה״כ פניות</span></div>
    <div class="stat"><b><?= $countToday ?></b><span>פניות היום</span></div>
    <div class="stat"><b><?= $countNew ?></b><span>ממתינות לטיפול</span></div>
  </div>

  <div class="toolbar">
    <input type="search" id="q" placeholder="חיפוש לפי שם / טלפון / דוא״ל / הודעה…">
    <select id="fstatus">
      <option value="">כל הסטטוסים</option>
      <option value="new">חדש</option>
      <option value="contacted">נוצר קשר</option>
      <option value="done">טופל</option>
    </select>
    <a class="btn" href="?export=csv">⬇ ייצוא CSV</a>
  </div>

  <div class="table-card">
    <?php if (!$leads): ?>
      <div class="empty">אין עדיין פניות. פניות מהאתר יופיעו כאן אוטומטית.</div>
    <?php else: ?>
    <table id="tbl">
      <thead>
        <tr><th>תאריך</th><th>שם</th><th>טלפון</th><th>דוא״ל</th><th>טיפול</th><th>הודעה</th><th>מקור</th><th>סטטוס</th><th></th></tr>
      </thead>
      <tbody>
        <?php $lbl=['new'=>'חדש','contacted'=>'נוצר קשר','done'=>'טופל']; foreach ($leads as $l):
          $id=$l['id']??''; $s=$status[$id]??'new'; ?>
        <tr data-id="<?= h($id) ?>" data-status="<?= h($s) ?>">
          <td style="white-space:nowrap"><?= h($l['ts']??'') ?></td>
          <td><?= h($l['name']??'') ?></td>
          <td style="white-space:nowrap"><?php if(!empty($l['phone'])): ?><a class="lnk" dir="ltr" href="tel:<?= h(preg_replace('/[^0-9+]/','',$l['phone'])) ?>"><?= h($l['phone']) ?></a><?php endif; ?></td>
          <td><?php if(!empty($l['email'])): ?><a class="lnk" dir="ltr" href="mailto:<?= h($l['email']) ?>"><?= h($l['email']) ?></a><?php endif; ?></td>
          <td><?= h($l['interest']??'') ?></td>
          <td class="msg"><?= h($l['msg']??'') ?></td>
          <td class="src"><?= h($l['source']??'') ?></td>
          <td>
            <select class="st st-<?= h($s) ?>" data-id="<?= h($id) ?>">
              <?php foreach($lbl as $k=>$v): ?><option value="<?= $k ?>"<?= $s===$k?' selected':'' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
          </td>
          <td><button class="del" data-id="<?= h($id) ?>" title="מחיקה">🗑</button></td>
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
  var q = document.getElementById('q'), fs = document.getElementById('fstatus'), tbl = document.getElementById('tbl');
  function applyFilter(){
    if(!tbl) return;
    var term=(q.value||'').toLowerCase(), st=fs.value;
    tbl.querySelectorAll('tbody tr').forEach(function(tr){
      var okText = tr.innerText.toLowerCase().indexOf(term)>-1;
      var okStat = !st || tr.getAttribute('data-status')===st;
      tr.style.display = (okText && okStat) ? '' : 'none';
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
  document.querySelectorAll('.del').forEach(function(b){
    b.addEventListener('click', function(){
      if(!confirm('למחוק פנייה זו לצמיתות?')) return;
      var id=b.getAttribute('data-id'), tr=b.closest('tr');
      post({action:'delete', id:id}).then(function(r){ if(r.ok) tr.remove(); });
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
