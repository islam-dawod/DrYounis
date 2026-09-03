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

/* حالات الليد — معرّفة في store.php ليستعملها الاستيراد أيضاً */
$ST_LBL = crm_statuses();

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
/* تنقية نص الملاحظة: يحفظ الأسطر الجديدة والـtab ويحذف بقية أحرف التحكّم */
function note_clean($v){
    $v = trim((string)$v);
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    $v = preg_replace('/[^\P{C}\n\t]+/u', '', $v);
    if (function_exists('mb_strlen') && mb_strlen($v, 'UTF-8') > 2000) $v = mb_substr($v, 0, 2000, 'UTF-8');
    return $v;
}
/* يمنح كل ملاحظة معرّفاً ثابتاً (ترقية لمرة واحدة للملاحظات القديمة) */
function notes_ensure_ids(&$all){
    $changed = false;
    if (!is_array($all)) { $all = []; return true; }
    foreach ($all as $lid => $list) {
        if (!is_array($list)) { unset($all[$lid]); $changed = true; continue; }
        foreach ($list as $i => $n) {
            if (!is_array($n)) { unset($all[$lid][$i]); $changed = true; continue; }
            if (empty($n['id'])) { $all[$lid][$i]['id'] = bin2hex(random_bytes(4)); $changed = true; }
        }
        $all[$lid] = array_values($all[$lid]);
    }
    return $changed;
}
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
    if ($_POST['action'] === 'lead_add') {
        $name  = note_clean($_POST['name']  ?? '');
        $phone = note_clean($_POST['phone'] ?? '');
        if ($name === '')  { echo json_encode(['ok'=>false, 'error'=>'no_name']);  exit; }
        if (strlen(preg_replace('/\D/', '', $phone)) < 7) { echo json_encode(['ok'=>false, 'error'=>'bad_phone']); exit; }

        $lid  = date('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
        $lead = [
            'id'       => $lid,
            'ts'       => date('Y-m-d H:i'),
            'name'     => $name,
            'phone'    => $phone,
            'email'    => note_clean($_POST['email']    ?? ''),
            'interest' => note_clean($_POST['interest'] ?? ''),
            'msg'      => note_clean($_POST['msg']      ?? ''),
            'source'   => note_clean($_POST['source']   ?? '') ?: 'הוספה ידנית',
            'ip'       => '',
            'manual'   => 1,
        ];
        @file_put_contents($LEADS, json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

        $stv = in_array($_POST['status'] ?? '', array_keys($ST_LBL), true) ? $_POST['status'] : 'new';
        $st  = load_status($STATUS); $st[$lid] = $stv; save_status($STATUS, $st);

        $note = note_clean($_POST['note'] ?? '');
        if ($note !== '') {
            $nt = load_notes($NOTES);
            $nt[$lid][] = ['id' => bin2hex(random_bytes(4)), 't' => date('Y-m-d H:i'), 'txt' => $note];
            save_notes($NOTES, $nt);
        }
        echo json_encode(['ok'=>true, 'id'=>$lid]); exit;
    }
    if ($_POST['action'] === 'lead_edit') {
        $lid   = (string)($_POST['lead_id'] ?? '');
        $name  = note_clean($_POST['name']  ?? '');
        $phone = note_clean($_POST['phone'] ?? '');
        if ($lid === '')   { echo json_encode(['ok'=>false, 'error'=>'no_id']);    exit; }
        if ($name === '')  { echo json_encode(['ok'=>false, 'error'=>'no_name']);  exit; }
        if (strlen(preg_replace('/\D/', '', $phone)) < 7) { echo json_encode(['ok'=>false, 'error'=>'bad_phone']); exit; }

        $leads = load_leads($LEADS);
        $found = false;
        foreach ($leads as $i => $l) {
            if (($l['id'] ?? '') !== $lid) continue;
            $leads[$i]['name']     = $name;
            $leads[$i]['phone']    = $phone;
            $leads[$i]['email']    = note_clean($_POST['email']    ?? '');
            $leads[$i]['interest'] = note_clean($_POST['interest'] ?? '');
            $leads[$i]['msg']      = note_clean($_POST['msg']      ?? '');
            $leads[$i]['source']   = note_clean($_POST['source']   ?? '') ?: ($l['source'] ?? '');
            $leads[$i]['edited']   = date('Y-m-d H:i');
            $found = true;
            break;
        }
        if (!$found) { echo json_encode(['ok'=>false, 'error'=>'not_found']); exit; }

        $lines = array_map(fn($l) => json_encode($l, JSON_UNESCAPED_UNICODE), $leads);
        @file_put_contents($LEADS, $lines ? implode("\n", $lines) . "\n" : '', LOCK_EX);

        if (in_array($_POST['status'] ?? '', array_keys($ST_LBL), true)) {
            $st = load_status($STATUS); $st[$lid] = $_POST['status']; save_status($STATUS, $st);
        }
        $note = note_clean($_POST['note'] ?? '');
        if ($note !== '') {
            $nt = load_notes($NOTES);
            $nt[$lid][] = ['id' => bin2hex(random_bytes(4)), 't' => date('Y-m-d H:i'), 'txt' => $note];
            save_notes($NOTES, $nt);
        }
        echo json_encode(['ok'=>true]); exit;
    }
    if ($_POST['action'] === 'note_add') {
        $txt = note_clean($_POST['text'] ?? '');
        if ($id === '' || $txt === '') { echo json_encode(['ok'=>false]); exit; }
        $nt = load_notes($NOTES); notes_ensure_ids($nt);
        if (!isset($nt[$id]) || !is_array($nt[$id])) $nt[$id] = [];
        $note = ['id' => bin2hex(random_bytes(4)), 't' => date('Y-m-d H:i'), 'txt' => $txt];
        $nt[$id][] = $note;
        save_notes($NOTES, $nt);
        echo json_encode(['ok'=>true, 'note'=>$note, 'count'=>count($nt[$id])]); exit;
    }
    if ($_POST['action'] === 'note_edit') {
        $nid = (string)($_POST['nid'] ?? '');
        $txt = note_clean($_POST['text'] ?? '');
        if ($id === '' || $nid === '' || $txt === '') { echo json_encode(['ok'=>false]); exit; }
        $nt = load_notes($NOTES); notes_ensure_ids($nt);
        $found = null;
        foreach (($nt[$id] ?? []) as $i => $n) {
            if ((string)($n['id'] ?? '') === $nid) {
                $nt[$id][$i]['txt'] = $txt;
                $nt[$id][$i]['e']   = date('Y-m-d H:i');
                $found = $nt[$id][$i];
                break;
            }
        }
        if ($found === null) { echo json_encode(['ok'=>false, 'error'=>'not_found']); exit; }
        save_notes($NOTES, $nt);
        echo json_encode(['ok'=>true, 'note'=>$found]); exit;
    }
    if ($_POST['action'] === 'note_del') {
        $nid = (string)($_POST['nid'] ?? '');
        if ($id === '' || $nid === '') { echo json_encode(['ok'=>false]); exit; }
        $nt = load_notes($NOTES); notes_ensure_ids($nt);
        if (isset($nt[$id]) && is_array($nt[$id])) {
            $nt[$id] = array_values(array_filter($nt[$id], fn($n) => (string)($n['id'] ?? '') !== $nid));
            if (!$nt[$id]) unset($nt[$id]);
            save_notes($NOTES, $nt);
        }
        echo json_encode(['ok'=>true, 'count'=>count($nt[$id] ?? [])]); exit;
    }
    if ($_POST['action'] === 'fb_genkey') {
        $c = crm_fb_cfg();
        $c['verify_token'] = bin2hex(random_bytes(12));
        $c['key']          = bin2hex(random_bytes(20));
        crm_fb_cfg_save($c);
        echo json_encode(['ok'=>true, 'verify'=>$c['verify_token'], 'key'=>$c['key']]); exit;
    }
    if ($_POST['action'] === 'fb_save') {
        $c   = crm_fb_cfg();
        $sec = trim((string)($_POST['app_secret'] ?? ''));
        $tok = trim((string)($_POST['page_token'] ?? ''));
        if ($sec !== '') $c['app_secret'] = $sec;   // فارغ = أبقِ القديم
        if ($tok !== '') $c['page_token'] = $tok;
        crm_fb_cfg_save($c);
        echo json_encode([
            'ok'     => true,
            'secret' => !empty($c['app_secret']),
            'token'  => !empty($c['page_token']),
        ]); exit;
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
if (notes_ensure_ids($notes)) save_notes($NOTES, $notes); // ترقية لمرة واحدة: معرّف لكل ملاحظة قديمة
usort($leads, fn($a,$b) => strcmp($b['id'] ?? '', $a['id'] ?? '')); // الأحدث أولاً
$gads_key = is_file($DATA . '/gads_key.txt') ? trim(file_get_contents($DATA . '/gads_key.txt')) : '';
$gads_url = ((($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'younisclinic.com') . '/crm/gads-webhook.php';
$fb_cfg   = crm_fb_cfg();
$fb_url   = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'younisclinic.com') . '/crm/fb-webhook.php';

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
        foreach (($notes[$lid] ?? []) as $n) {
            $nl[] = '[' . ($n['t'] ?? '') . '] ' . ($n['txt'] ?? '')
                  . (!empty($n['e']) ? ' (נערך ' . $n['e'] . ')' : '');
        }
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
  .toolbar a.btn.alt{background:#fff;color:var(--teal);border:1px solid var(--teal)}
  .toolbar a.btn.alt:hover{background:var(--pale)}
  .toolbar button.btn.add{background:var(--teal2);color:#fff;border:0;padding:10px 16px;border-radius:10px;font-size:.9rem;font-weight:700;cursor:pointer;font-family:inherit}
  .toolbar button.btn.add:hover{background:#138f8f}
  .modal{position:fixed;inset:0;background:rgba(15,40,40,.55);display:flex;align-items:flex-start;justify-content:center;padding:24px 16px;overflow:auto;z-index:50}
  .modal[hidden]{display:none}
  .mbox{background:#fff;border-radius:18px;padding:24px;width:min(620px,100%);box-shadow:0 24px 70px rgba(0,0,0,.3)}
  .mbox h3{margin:0 0 16px;color:var(--teal);font-size:1.1rem}
  .mgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:560px){.mgrid{grid-template-columns:1fr}}
  .mbox label{display:block;font-size:.85rem;color:var(--muted);margin-bottom:12px}
  .mbox input,.mbox select,.mbox textarea{width:100%;margin-top:5px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-family:inherit;font-size:.95rem;color:var(--ink);background:#fff;resize:vertical}
  .mact{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:6px}
  .mact .save{background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 22px;font-weight:800;cursor:pointer;font-family:inherit;font-size:.95rem}
  .mact .save:hover{background:#095657}
  .mact .save:disabled{opacity:.6;cursor:default}
  .mact .cancel{background:#fff;border:1px solid var(--line);border-radius:10px;padding:12px 18px;cursor:pointer;font-family:inherit;font-size:.9rem;color:var(--muted)}
  .mact .mmsg{font-size:.85rem;color:var(--muted)}
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
  td.acts{white-space:nowrap}
  .edt{background:none;border:0;color:var(--teal);cursor:pointer;font-size:1.05rem;padding:4px 8px;border-radius:8px}
  .edt:hover{background:var(--pale)}
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
  .gads-help code{display:inline;background:#f4f8f8;border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-size:.8rem;direction:ltr}
  .fbsec{margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
  .fbsec input{width:100%;max-width:520px;display:block;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:.88rem;direction:ltr;text-align:left;background:#fff}
  .okmark{background:#e4f6ec;color:#1c7a45;border-radius:999px;padding:1px 8px;font-size:.72rem;font-weight:700}
  .savemsg{font-size:.82rem;color:var(--muted);margin-inline-start:10px}
  .st-not_interested{background:#f0f1f2;color:#5c6564}
  .tzhint{font-size:.78rem;color:rgba(255,255,255,.85);white-space:nowrap}
  .nbtn{background:var(--pale);border:1px solid var(--line);border-radius:9px;padding:6px 10px;cursor:pointer;font-family:inherit;font-size:.82rem;color:var(--teal);white-space:nowrap;font-weight:700}
  .nbtn:hover{background:#e1f1f0}
  tr.has-notes .nbtn{background:#e4f6ec;border-color:#c7e8d5;color:#1c7a45}
  tr.nrow>td{background:#fbfdfd;border-bottom:2px solid var(--line)}
  .notes{display:flex;flex-direction:column;gap:10px;max-width:820px}
  .nlist{display:flex;flex-direction:column;gap:6px}
  .note{background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 11px;font-size:.88rem;word-break:break-word;line-height:1.55}
  .note .nhead{display:flex;align-items:center;gap:8px;margin-bottom:3px}
  .note .nt{font-size:.74rem;color:var(--muted)}
  .note .ned{font-size:.72rem;color:#a86400}
  .note .nsp{flex:1}
  .note .nx{white-space:pre-wrap;word-break:break-word}
  .nact{background:none;border:0;cursor:pointer;font-size:.95rem;line-height:1;padding:3px 6px;border-radius:7px;color:var(--muted);opacity:.5}
  .note:hover .nact{opacity:1}
  .nact:hover{background:var(--pale);color:var(--teal)}
  .nact[data-act=del]:hover{background:#fdecea;color:#c0392b}
  .nedit{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;margin-top:6px}
  .nedit textarea{flex:1;min-width:200px;padding:8px 11px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:.9rem;resize:vertical}
  .nedit .nsave{background:var(--teal);color:#fff;border:0;border-radius:9px;padding:8px 13px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.85rem}
  .nedit .nsave:disabled{opacity:.6;cursor:default}
  .nedit .ncancel{background:#fff;border:1px solid var(--line);border-radius:9px;padding:8px 13px;cursor:pointer;font-family:inherit;font-size:.85rem;color:var(--muted)}
  .nedit .nmsg{font-size:.8rem;color:var(--muted);align-self:center}
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

  <details class="gads">
    <summary>חיבור Facebook / Instagram (Lead Ads) — טופס לידים ישר ל־CRM</summary>
    <div class="gads-body">
      <label>Callback URL</label>
      <div class="cp"><code id="fbUrl"><?= h($fb_url) ?></code><button type="button" data-copy="fbUrl">העתק</button></div>
      <label>Verify Token (ל־Meta Webhooks)</label>
      <div class="cp"><code id="fbVerify"><?= !empty($fb_cfg['verify_token']) ? h($fb_cfg['verify_token']) : '— טרם נוצר —' ?></code><button type="button" data-copy="fbVerify">העתק</button></div>
      <label>Key (למי שמחבר דרך Make / Zapier)</label>
      <div class="cp"><code id="fbKey"><?= !empty($fb_cfg['key']) ? h($fb_cfg['key']) : '— טרם נוצר —' ?></code><button type="button" data-copy="fbKey">העתק</button></div>
      <button type="button" id="fbGen" class="gen"><?= !empty($fb_cfg['verify_token']) ? 'יצירת Token + Key חדשים' : 'יצירת Verify Token + Key' ?></button>

      <div class="fbsec">
        <label>App Secret <span id="fbSecOk" class="okmark"<?= empty($fb_cfg['app_secret']) ? ' hidden' : '' ?>>שמור ✓</span></label>
        <input type="password" id="fbAppSecret" autocomplete="off" placeholder="<?= empty($fb_cfg['app_secret']) ? 'מ־Meta: App → Settings → Basic' : 'השאירו ריק כדי לא לשנות' ?>">
        <label>Page Access Token <span id="fbTokOk" class="okmark"<?= empty($fb_cfg['page_token']) ? ' hidden' : '' ?>>שמור ✓</span></label>
        <input type="password" id="fbPageToken" autocomplete="off" placeholder="<?= empty($fb_cfg['page_token']) ? 'טוקן עם ההרשאה leads_retrieval' : 'השאירו ריק כדי לא לשנות' ?>">
        <button type="button" id="fbSave" class="gen">שמירת הפרטים</button>
        <span id="fbSaveMsg" class="savemsg"></span>
      </div>

      <p class="gads-help">
        <b>אפשרות א׳ — חיבור ישיר ל־Meta:</b> developers.facebook.com → צרו App מסוג Business → Webhooks → Page → הדביקו את ה־Callback URL ואת ה־Verify Token שלמעלה → הירשמו לשדה <code>leadgen</code>. אחר כך הדביקו כאן את ה־App Secret ואת ה־Page Access Token (מומלץ טוקן של System User שלא פג תוקף). בדיקה: Lead Ads Testing Tool.<br>
        <b>אפשרות ב׳ — דרך Make/Zapier:</b> טריגר „Facebook Lead Ads → New Lead” → פעולה HTTP POST ל־Callback URL עם JSON: <code>{"key":"…","name":"…","phone":"…","email":"…"}</code>.
      </p>
    </div>
  </details>

  <div class="toolbar">
    <input type="search" id="q" placeholder="חיפוש לפי שם / טלפון / דוא״ל / הודעה…">
    <select id="fstatus">
      <option value="">כל הסטטוסים</option>
      <?php foreach ($ST_LBL as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
    </select>
    <a class="btn" href="?export=csv">⬇ ייצוא CSV</a>
    <a class="btn alt" href="import.php">⬆ ייבוא מקובץ</a>
    <button type="button" class="btn add" id="newLead">+ ליד חדש</button>
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
        <tr class="lead<?= $ns ? ' has-notes' : '' ?>" data-id="<?= h($id) ?>" data-status="<?= h($s) ?>" data-notes="<?= h(trim($ntxt)) ?>"
            data-name="<?= h($l['name']??'') ?>" data-phone="<?= h($l['phone']??'') ?>" data-email="<?= h($l['email']??'') ?>"
            data-interest="<?= h($l['interest']??'') ?>" data-msg="<?= h($l['msg']??'') ?>" data-source="<?= h($l['source']??'') ?>">
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
          <td class="acts">
            <button type="button" class="edt" data-id="<?= h($id) ?>" title="עריכת פרטי הליד">✎</button>
            <button class="del" data-id="<?= h($id) ?>" title="מחיקת הליד">🗑</button>
          </td>
        </tr>
        <tr class="nrow" hidden>
          <td colspan="10">
            <div class="notes">
              <div class="nlist">
                <?php foreach ($ns as $n): ?>
                <div class="note" data-nid="<?= h($n['id']??'') ?>">
                  <div class="nhead">
                    <span class="nt"><?= h($n['t']??'') ?></span>
                    <?php if (!empty($n['e'])): ?><span class="ned">(נערך <?= h($n['e']) ?>)</span><?php endif; ?>
                    <span class="nsp"></span>
                    <button type="button" class="nact" data-act="edit" title="עריכת הערה">✎</button>
                    <button type="button" class="nact" data-act="del" title="מחיקת הערה">🗑</button>
                  </div>
                  <div class="nx"><?= h($n['txt']??'') ?></div>
                </div>
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
  <div class="modal" id="leadModal" hidden>
    <div class="mbox">
      <h3 id="leadTitle">הוספת ליד חדש</h3>
      <form id="leadForm" autocomplete="off">
        <input type="hidden" name="lead_id" value="">
        <div class="mgrid">
          <label>שם מלא *<input name="name" required></label>
          <label>טלפון *<input name="phone" inputmode="tel" required dir="ltr"></label>
          <label>דוא״ל<input name="email" type="email" dir="ltr"></label>
          <label>טיפול / עניין<input name="interest"></label>
          <label>מקור<input name="source" value="הוספה ידנית"></label>
          <label>סטטוס
            <select name="status">
              <?php foreach ($ST_LBL as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <label>הודעה<textarea name="msg" rows="2"></textarea></label>
        <label><span id="noteLbl">הערה ראשונה (אופציונלי)</span><textarea name="note" rows="2" placeholder="למשל: פנה בטלפון, מעוניין בהשתלה"></textarea></label>
        <div class="mact">
          <button type="submit" class="save">הוספת הליד</button>
          <button type="button" class="cancel">ביטול</button>
          <span class="mmsg"></span>
        </div>
      </form>
    </div>
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

  // Facebook Lead Ads: יצירת Verify Token + Key
  var fbGen = document.getElementById('fbGen');
  if(fbGen) fbGen.addEventListener('click', function(){
    if(document.getElementById('fbVerify').textContent.indexOf('—')===-1 &&
       !confirm('יצירת Token חדש תנתק את החיבור הקיים ב־Meta עד שתעדכנו אותו שם. להמשיך?')) return;
    fbGen.disabled=true; fbGen.textContent='יוצר…';
    post({action:'fb_genkey'}).then(function(r){
      if(r&&r.ok){
        document.getElementById('fbVerify').textContent=r.verify;
        document.getElementById('fbKey').textContent=r.key;
        fbGen.textContent='יצירת Token + Key חדשים';
      } else fbGen.textContent='שגיאה — נסו שוב';
      fbGen.disabled=false;
    });
  });
  // Facebook Lead Ads: שמירת App Secret + Page Access Token
  var fbSave = document.getElementById('fbSave');
  if(fbSave) fbSave.addEventListener('click', function(){
    var sec=document.getElementById('fbAppSecret'), tok=document.getElementById('fbPageToken'),
        msg=document.getElementById('fbSaveMsg');
    if(!sec.value.trim() && !tok.value.trim()){ msg.textContent='אין מה לשמור'; return; }
    fbSave.disabled=true; msg.textContent='שומר…';
    post({action:'fb_save', app_secret:sec.value.trim(), page_token:tok.value.trim()}).then(function(r){
      fbSave.disabled=false;
      if(!(r&&r.ok)){ msg.textContent='שגיאה — נסו שוב'; return; }
      sec.value=''; tok.value='';
      sec.placeholder='השאירו ריק כדי לא לשנות'; tok.placeholder='השאירו ריק כדי לא לשנות';
      document.getElementById('fbSecOk').hidden = !r.secret;
      document.getElementById('fbTokOk').hidden = !r.token;
      msg.textContent='נשמר ✓'; setTimeout(function(){ msg.textContent=''; }, 2000);
    });
  });

  // ליד: הוספה ידנית + עריכת פרטים (אותו חלון)
  var lModal = document.getElementById('leadModal'),
      lForm  = document.getElementById('leadForm'),
      lOpen  = document.getElementById('newLead'),
      lTitle = document.getElementById('leadTitle'),
      lId    = '';
  function lField(n){ return lForm ? lForm.querySelector('[name='+n+']') : null; }
  function lSet(n, v){ var el = lField(n); if(el) el.value = v || ''; }
  function leadClose(){ if(lModal) lModal.hidden = true; }
  function leadOpen(tr){
    if(!lModal) return;
    lId = tr ? (tr.getAttribute('data-id') || '') : '';
    var g = function(a){ return tr ? (tr.getAttribute('data-'+a) || '') : ''; };
    lTitle.textContent = lId ? 'עריכת פרטי הליד' : 'הוספת ליד חדש';
    lForm.querySelector('.save').textContent = lId ? 'שמירת השינויים' : 'הוספת הליד';
    lForm.querySelector('.save').disabled = false;
    lForm.querySelector('.mmsg').textContent = '';
    document.getElementById('noteLbl').textContent = lId ? 'הוספת הערה (אופציונלי)' : 'הערה ראשונה (אופציונלי)';
    lSet('lead_id', lId);
    lSet('name', g('name'));
    lSet('phone', g('phone'));
    lSet('email', g('email'));
    lSet('interest', g('interest'));
    lSet('msg', g('msg'));
    lSet('source', tr ? g('source') : 'הוספה ידנית');
    lSet('note', '');
    var st = lField('status'); if(st) st.value = tr ? (tr.getAttribute('data-status') || 'new') : 'new';
    lModal.hidden = false;
    var f = lField('name'); if(f) f.focus();
  }
  if(lOpen) lOpen.addEventListener('click', function(){ leadOpen(null); });
  document.querySelectorAll('.edt').forEach(function(b){
    b.addEventListener('click', function(){ leadOpen(b.closest('tr')); });
  });
  if(lModal){
    lModal.addEventListener('click', function(e){ if(e.target === lModal) leadClose(); });
    var cx = lModal.querySelector('.cancel'); if(cx) cx.addEventListener('click', leadClose);
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && !lModal.hidden) leadClose(); });
  }
  if(lForm) lForm.addEventListener('submit', function(e){
    e.preventDefault();
    var btn = lForm.querySelector('.save'), msg = lForm.querySelector('.mmsg'),
        data = {action: lId ? 'lead_edit' : 'lead_add'};
    if(lId) data.lead_id = lId;
    ['name','phone','email','interest','source','status','msg','note'].forEach(function(k){
      var el = lField(k);
      data[k] = el ? el.value.trim() : '';
    });
    if(!data.name){ msg.textContent = 'נא למלא שם'; return; }
    if(data.phone.replace(/\D/g,'').length < 7){ msg.textContent = 'מספר טלפון לא תקין'; return; }
    btn.disabled = true; msg.textContent = 'שומר…';
    post(data).then(function(r){
      if(r && r.ok){ msg.textContent = 'נשמר ✓'; location.reload(); return; }
      btn.disabled = false;
      msg.textContent = (r && r.error === 'bad_phone') ? 'מספר טלפון לא תקין'
                      : (r && r.error === 'no_name')   ? 'נא למלא שם'
                      : (r && r.error === 'not_found') ? 'הליד לא נמצא — רעננו את הדף'
                      : 'שגיאה — נסו שוב';
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
  // הערות: סנכרון מונה + טקסט לחיפוש בשורת הליד, לפי מה שמוצג כרגע
  function refreshLeadNotes(list){
    if(!list) return;
    var nrow = list.closest('tr.nrow'); if(!nrow) return;
    var tr = nrow.previousElementSibling; if(!tr) return;
    var txts = [];
    list.querySelectorAll('.note .nx').forEach(function(x){ txts.push(x.textContent); });
    var c = tr.querySelector('.ncount'); if(c) c.textContent = txts.length ? '('+txts.length+')' : '';
    tr.setAttribute('data-notes', txts.join(' '));
    if(txts.length) tr.classList.add('has-notes'); else tr.classList.remove('has-notes');
    if(!txts.length && !list.querySelector('.nempty')){
      var em = document.createElement('div'); em.className = 'nempty';
      em.textContent = 'אין הערות עדיין.'; list.appendChild(em);
    }
  }
  // הערות: בניית פריט הערה (זהה למה שמייצר ה־PHP)
  function buildNote(n){
    var d  = document.createElement('div'); d.className = 'note'; d.setAttribute('data-nid', n.id||'');
    var hd = document.createElement('div'); hd.className = 'nhead';
    var t  = document.createElement('span'); t.className = 'nt'; t.textContent = n.t||'';
    hd.appendChild(t);
    if(n.e){ var ed = document.createElement('span'); ed.className='ned'; ed.textContent = '(נערך '+n.e+')'; hd.appendChild(ed); }
    var sp = document.createElement('span'); sp.className = 'nsp'; hd.appendChild(sp);
    [['edit','עריכת הערה','✎'],['del','מחיקת הערה','🗑']].forEach(function(a){
      var b = document.createElement('button'); b.type='button'; b.className='nact';
      b.setAttribute('data-act', a[0]); b.title = a[1]; b.textContent = a[2];
      hd.appendChild(b);
    });
    var x = document.createElement('div'); x.className = 'nx'; x.textContent = n.txt||'';
    d.appendChild(hd); d.appendChild(x);
    return d;
  }
  // הערות: הוספה
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
        list.appendChild(buildNote(r.note));
        ta.value = ''; msg.textContent = 'נשמר ✓';
        setTimeout(function(){ msg.textContent = ''; }, 1600);
        refreshLeadNotes(list);
      });
    });
  });
  // הערות: עריכה / מחיקה (delegation — תקף גם להערות שנוספו עכשיו)
  document.addEventListener('click', function(e){
    var b = (e.target && e.target.closest) ? e.target.closest('.nact') : null;
    if(!b) return;
    var note = b.closest('.note'); if(!note) return;
    var list = note.parentNode,
        nrow = note.closest('tr.nrow'),
        form = nrow ? nrow.querySelector('.nform') : null,
        lid  = form ? form.getAttribute('data-id') : '',
        nid  = note.getAttribute('data-nid');
    if(!lid || !nid) return;

    if(b.getAttribute('data-act') === 'del'){
      if(!confirm('למחוק הערה זו?')) return;
      post({action:'note_del', id:lid, nid:nid}).then(function(r){
        if(!(r && r.ok)) { alert('שגיאה במחיקת ההערה — נסו שוב.'); return; }
        note.remove(); refreshLeadNotes(list);
      });
      return;
    }
    if(note.querySelector('.nedit')) return;               // כבר במצב עריכה
    var body = note.querySelector('.nx'),
        box  = document.createElement('div'),
        ta   = document.createElement('textarea'),
        ok   = document.createElement('button'),
        no   = document.createElement('button'),
        msg  = document.createElement('span');
    box.className = 'nedit';
    ta.rows = 3; ta.value = body.textContent;
    ok.type = 'button'; ok.className = 'nsave';   ok.textContent = 'שמירה';
    no.type = 'button'; no.className = 'ncancel'; no.textContent = 'ביטול';
    msg.className = 'nmsg';
    box.appendChild(ta); box.appendChild(ok); box.appendChild(no); box.appendChild(msg);
    body.style.display = 'none'; note.appendChild(box); ta.focus();

    no.addEventListener('click', function(){ box.remove(); body.style.display = ''; });
    ok.addEventListener('click', function(){
      var txt = (ta.value||'').trim();
      if(!txt) { ta.focus(); return; }
      ok.disabled = true; msg.textContent = 'שומר…';
      post({action:'note_edit', id:lid, nid:nid, text:txt}).then(function(r){
        ok.disabled = false;
        if(!(r && r.ok)) { msg.textContent = 'שגיאה — נסו שוב'; return; }
        body.textContent = r.note.txt;
        var hd = note.querySelector('.nhead'), ed = note.querySelector('.ned');
        if(!ed){
          ed = document.createElement('span'); ed.className = 'ned';
          hd.insertBefore(ed, note.querySelector('.nsp'));
        }
        ed.textContent = '(נערך '+(r.note.e||'')+')';
        box.remove(); body.style.display = '';
        refreshLeadNotes(list);
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
