<?php
/* =====================================================================
   crm/import.php — استيراد لِيدات من ملف Excel (.xlsx) أو CSV إلى الـCRM.

   المسار: رفع الملف → شاشة مطابقة الأعمدة (مع معاينة) → تنفيذ الاستيراد.
   - يقرأ .xlsx مباشرةً (ZipArchive + XML) بلا أي مكتبة خارجية.
   - يقرأ CSV بأي فاصل (,;Tab|) وبترميز UTF-8 أو Windows-1255.
   - يمنع التكرار حسب رقم الهاتف (آخر 9 أرقام).
   - عمود «ملاحظة» يُضاف كملاحظة في سجل الليد (notes.json).
   محمية بنفس جلسة لوحة الـCRM.
   ===================================================================== */

session_start();
require __DIR__ . '/store.php';

if (empty($_SESSION['crm_auth'])) { header('Location: index.php'); exit; }

$DATA   = crm_data_dir();
$LEADS  = $DATA . '/leads.ndjson';
$STATUS = $DATA . '/status.json';
$NOTES  = $DATA . '/notes.json';
$ST_LBL = crm_statuses();

const IMP_MAX_BYTES = 8388608;   // 8MB
const IMP_MAX_ROWS  = 5000;

/* ---------------- أدوات عامة ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_ok(){ return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }
function jload($f){ if (is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j)) return $j; } return []; }
function jsave($f, $a){ @file_put_contents($f, json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX); }

/* نص إلى UTF-8 (إكسل العبري يصدّر أحياناً بترميز Windows-1255) */
function imp_utf8($s) {
    $s = (string)$s;
    if ($s === '' || !function_exists('mb_check_encoding') || mb_check_encoding($s, 'UTF-8')) return $s;
    foreach (['CP1255', 'CP1256', 'ISO-8859-8'] as $enc) {
        $c = @iconv($enc, 'UTF-8//IGNORE', $s);
        if ($c !== false && $c !== '') return $c;
    }
    return $s;
}
function imp_clean($v) { return trim(str_replace(["\r", "\n", "\t"], ' ', imp_utf8($v))); }

/* آخر 9 أرقام من الهاتف — للمقارنة ومنع التكرار */
function imp_phone_key($p) {
    $d = preg_replace('/\D/', '', (string)$p);
    return strlen($d) > 9 ? substr($d, -9) : $d;
}
/* إكسل يحوّل 0501234567 إلى رقم ويحذف الصفر — نعيده */
function imp_phone_fix($p) {
    $p = trim((string)$p);
    if ($p === '') return '';
    if (preg_match('/^5\d{8}$/', $p)) return '0' . $p;         // 501234567  => 0501234567
    if (preg_match('/^9725\d{8}$/', $p)) return '+' . $p;      // 972501234567
    return $p;
}
/* تاريخ إكسل الرقمي (serial) إلى نص */
function imp_date($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {                  // serial: 45678.5
        $ts = ((float)$v - 25569) * 86400;
        if ($ts > 0) return date('Y-m-d H:i', (int)round($ts));
    }
    $ts = strtotime(str_replace('/', '-', $v));
    return $ts ? date('Y-m-d H:i', $ts) : $v;
}

/* ---------------- قراءة الملفات ---------------- */
function imp_col_index($letters) {
    $n = 0; $letters = strtoupper($letters);
    for ($i = 0; $i < strlen($letters); $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return $n - 1;
}

function imp_read_xlsx($path) {
    if (!class_exists('ZipArchive')) return ['err' => 'no_zip'];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return ['err' => 'bad_zip'];

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $x = @simplexml_load_string($ss);
        if ($x) foreach ($x->si as $si) {
            $t = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $node) $t .= (string)$node;
            $shared[] = $t;
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, 'xl/worksheets/sheet') === 0) { $sheet = $zip->getFromName($n); break; }
        }
    }
    $zip->close();
    if (!$sheet) return ['err' => 'no_sheet'];

    $x = @simplexml_load_string($sheet);
    if (!$x) return ['err' => 'bad_sheet'];

    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = imp_col_index(preg_replace('/\d+/', '', (string)$c['r']));
            if ($col < 0) continue;
            $t = (string)$c['t'];
            if     ($t === 's')         $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)($c->is->t ?? '');
            else                        $v = (string)($c->v ?? '');
            $cells[$col] = trim($v);
        }
        if ($cells) {
            $max = max(array_keys($cells)); $out = [];
            for ($i = 0; $i <= $max; $i++) $out[] = $cells[$i] ?? '';
            $rows[] = $out;
        } else {
            $rows[] = [];
        }
        if (count($rows) > IMP_MAX_ROWS) break;
    }
    return ['rows' => $rows];
}

function imp_read_csv($path) {
    $txt = (string)file_get_contents($path);
    $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
    $txt = imp_utf8($txt);

    $firstLine = strtok($txt, "\n");
    $best = ','; $bestCount = -1;
    foreach ([',', ';', "\t", '|'] as $d) {
        $c = substr_count((string)$firstLine, $d);
        if ($c > $bestCount) { $bestCount = $c; $best = $d; }
    }

    $tmp = fopen('php://temp', 'r+');
    fwrite($tmp, $txt);
    rewind($tmp);
    $rows = [];
    while (($r = fgetcsv($tmp, 0, $best)) !== false) {
        if ($r === [null]) continue;                 // سطر فارغ
        $rows[] = array_map(fn($v) => trim((string)$v), $r);
        if (count($rows) > IMP_MAX_ROWS) break;
    }
    fclose($tmp);
    return ['rows' => $rows];
}

/* ---------------- تخمين مطابقة الأعمدة من صف العناوين ---------------- */
function imp_guess($header) {
    $syn = [
        'name'     => ['שם', 'שם מלא', 'name', 'full name', 'fullname', 'lead', 'الاسم', 'اسم'],
        'phone'    => ['טלפון', 'נייד', 'פלאפון', 'מספר', 'phone', 'mobile', 'tel', 'هاتف', 'جوال', 'رقم'],
        'email'    => ['דוא"ל', 'דוא״ל', 'מייל', 'אימייל', 'email', 'mail', 'بريد', 'ايميل'],
        'interest' => ['טיפול', 'שירות', 'עניין', 'קמפיין', 'טופס', 'interest', 'service', 'treatment', 'form', 'علاج', 'خدمة'],
        'msg'      => ['הודעה', 'תוכן', 'message', 'msg', 'رسالة'],
        'note'     => ['הערה', 'הערות', 'note', 'notes', 'comment', 'ملاحظة', 'ملاحظات'],
        'source'   => ['מקור', 'source', 'ערוץ', 'channel', 'مصدر'],
        'ts'       => ['תאריך', 'זמן', 'date', 'time', 'created', 'تاريخ'],
        'status'   => ['סטטוס', 'status', 'مرحلة', 'حالة'],
    ];
    $map = array_fill_keys(array_keys($syn), -1);
    foreach ($header as $i => $cell) {
        $c = mb_strtolower(trim(imp_utf8($cell)), 'UTF-8');
        if ($c === '') continue;
        foreach ($syn as $field => $words) {
            if ($map[$field] !== -1) continue;
            foreach ($words as $w) {
                if ($c === $w || mb_strpos($c, $w, 0, 'UTF-8') !== false) { $map[$field] = $i; break 2; }
            }
        }
    }
    return $map;
}

/* ---------------- الحالة (state) ---------------- */
$step   = 'upload';
$err    = '';
$rows   = [];
$token  = '';
$map    = [];
$result = null;

/* تنظيف ملفات مؤقتة قديمة (> يوم) */
foreach ((array)@glob($DATA . '/import_*.json') as $old) {
    if (@filemtime($old) < time() - 86400) @unlink($old);
}

/* ========== (1) رفع الملف ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'upload') {
    if (!csrf_ok()) {
        $err = 'פג תוקף הדף — רעננו ונסו שוב.';
    } elseif (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        $err = 'לא נבחר קובץ (או שהקובץ גדול מדי).';
    } elseif ($_FILES['file']['size'] > IMP_MAX_BYTES) {
        $err = 'הקובץ גדול מ־8MB.';
    } else {
        $name = (string)$_FILES['file']['name'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $res = imp_read_xlsx($_FILES['file']['tmp_name']);
        } elseif (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            $res = imp_read_csv($_FILES['file']['tmp_name']);
        } elseif ($ext === 'xls') {
            $res = ['err' => 'old_xls'];
        } else {
            $res = ['err' => 'ext'];
        }

        if (isset($res['err'])) {
            $errs = [
                'no_zip'     => 'השרת לא תומך בקריאת xlsx. שמרו את הקובץ כ־CSV UTF-8 ונסו שוב.',
                'bad_zip'    => 'לא הצלחנו לפתוח את הקובץ — ודאו שהוא xlsx תקין.',
                'no_sheet'   => 'לא נמצא גיליון בקובץ.',
                'bad_sheet'  => 'שגיאה בקריאת הגיליון.',
                'old_xls'    => 'פורמט xls ישן אינו נתמך. שמרו כ־xlsx או כ־CSV UTF-8.',
                'ext'        => 'סוג קובץ לא נתמך. הקבצים הנתמכים: xlsx, csv.',
            ];
            $err = $errs[$res['err']] ?? 'שגיאה בקריאת הקובץ.';
        } else {
            $rows = array_values(array_filter($res['rows'], fn($r) => trim(implode('', (array)$r)) !== ''));
            if (count($rows) < 2) {
                $err = 'הקובץ ריק או מכיל שורת כותרת בלבד.';
            } else {
                $token = bin2hex(random_bytes(8));
                jsave($DATA . '/import_' . $token . '.json', ['file' => $name, 'rows' => $rows]);
                $map  = imp_guess($rows[0]);
                $step = 'map';
            }
        }
    }
}

/* ========== (2) تنفيذ الاستيراد ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'commit') {
    $token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
    $tmpF  = $DATA . '/import_' . $token . '.json';

    if (!csrf_ok()) {
        $err = 'פג תוקף הדף — התחילו מחדש.';
    } elseif ($token === '' || !is_file($tmpF)) {
        $err = 'הקובץ הזמני לא נמצא — העלו את הקובץ שוב.';
    } else {
        $saved  = jload($tmpF);
        $rows   = $saved['rows'] ?? [];
        $map    = [];
        foreach (['name','phone','email','interest','msg','note','source','ts','status'] as $f) {
            $map[$f] = (int)($_POST['map'][$f] ?? -1);
        }
        $hasHeader  = !empty($_POST['has_header']);
        $skipDup    = !empty($_POST['skip_dup']);
        $defSource  = imp_clean($_POST['default_source'] ?? '') ?: 'ייבוא מקובץ';
        $defStatus  = isset($ST_LBL[$_POST['default_status'] ?? '']) ? $_POST['default_status'] : 'new';

        /* أرقام هواتف موجودة مسبقاً */
        $existing = [];
        if (is_file($LEADS)) {
            foreach (file($LEADS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
                $o = json_decode($ln, true);
                if (is_array($o)) { $k = imp_phone_key($o['phone'] ?? ''); if ($k !== '') $existing[$k] = true; }
            }
        }
        $statusMap = jload($STATUS);
        $notesMap  = jload($NOTES);
        $revStatus = [];
        foreach ($ST_LBL as $k => $v) { $revStatus[$k] = $k; $revStatus[$v] = $k; }

        $added = 0; $dup = 0; $bad = 0; $lines = []; $newIds = [];
        foreach ($rows as $i => $r) {
            if ($i === 0 && $hasHeader) continue;
            $get = function($f) use ($r, $map) {
                $ci = $map[$f] ?? -1;
                return ($ci >= 0 && isset($r[$ci])) ? imp_clean($r[$ci]) : '';
            };
            $name  = $get('name');
            $phone = imp_phone_fix($get('phone'));
            if ($name === '' && $phone === '') { $bad++; continue; }
            if ($name === '') $name = 'ללא שם';

            $key = imp_phone_key($phone);
            if ($skipDup && $key !== '' && isset($existing[$key])) { $dup++; continue; }
            if ($key !== '') $existing[$key] = true;

            $ts  = imp_date($get('ts'));
            if ($ts === '') $ts = date('Y-m-d H:i');
            $sortTs = strtotime($ts) ?: time();
            $id  = date('YmdHis', $sortTs) . substr(md5(uniqid((string)$i, true)), 0, 6);

            $lead = [
                'id'       => $id,
                'ts'       => $ts,
                'name'     => $name,
                'phone'    => $phone,
                'email'    => $get('email'),
                'interest' => $get('interest'),
                'msg'      => $get('msg'),
                'source'   => $get('source') ?: $defSource,
                'ip'       => '',
                'imported' => 1,
            ];
            $lines[] = json_encode($lead, JSON_UNESCAPED_UNICODE);
            $newIds[] = $id;

            $st = $get('status');
            $statusMap[$id] = ($st !== '' && isset($revStatus[$st])) ? $revStatus[$st] : $defStatus;

            $note = $get('note');
            if ($note !== '') {
                $notesMap[$id][] = ['id' => bin2hex(random_bytes(4)), 't' => date('Y-m-d H:i'), 'txt' => $note];
            }
            $added++;
        }

        if ($lines) {
            @file_put_contents($LEADS, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
            jsave($STATUS, $statusMap);
            jsave($NOTES, $notesMap);
            jsave($DATA . '/import_last.json', [
                'at'   => date('Y-m-d H:i'),
                'file' => $saved['file'] ?? '',
                'ids'  => $newIds,
            ]);
        }
        @unlink($tmpF);
        $result = ['added' => $added, 'dup' => $dup, 'bad' => $bad, 'file' => $saved['file'] ?? ''];
        $step   = 'done';
    }
}

/* ========== (3) التراجع عن آخر استيراد ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'undo') {
    if (!csrf_ok()) {
        $err = 'פג תוקף הדף — רעננו ונסו שוב.';
    } else {
        $last = jload($DATA . '/import_last.json');
        $ids  = array_flip((array)($last['ids'] ?? []));
        $gone = 0;
        if ($ids && is_file($LEADS)) {
            $keep = [];
            foreach ((array)file($LEADS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
                $o = json_decode($ln, true);
                if (is_array($o) && isset($ids[$o['id'] ?? ''])) { $gone++; continue; }
                $keep[] = $ln;
            }
            @file_put_contents($LEADS, $keep ? implode("\n", $keep) . "\n" : '', LOCK_EX);

            $st = jload($STATUS); $nt = jload($NOTES);
            foreach (array_keys($ids) as $gid) { unset($st[$gid], $nt[$gid]); }
            jsave($STATUS, $st); jsave($NOTES, $nt);
        }
        @unlink($DATA . '/import_last.json');
        $result = ['undone' => $gone, 'file' => $last['file'] ?? ''];
        $step   = 'undone';
    }
}

/* آخر استيراد (لعرض زر التراجع خلال 24 ساعة) */
$lastImport = null;
if (is_file($DATA . '/import_last.json') && @filemtime($DATA . '/import_last.json') > time() - 86400) {
    $li = jload($DATA . '/import_last.json');
    if (!empty($li['ids'])) $lastImport = $li;
}

$FIELDS = [
    'name'     => 'שם',
    'phone'    => 'טלפון',
    'email'    => 'דוא״ל',
    'interest' => 'טיפול / קמפיין',
    'msg'      => 'הודעה',
    'note'     => 'הערה (תיכנס ליומן ההערות)',
    'source'   => 'מקור',
    'ts'       => 'תאריך',
    'status'   => 'סטטוס',
];
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ייבוא לידים · CRM</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
  :root{--teal:#0B6F70;--pale:#EFF8F7;--ink:#231F20;--line:#e3edec;--muted:#6a7a7a}
  *{box-sizing:border-box}
  body{margin:0;font-family:"Heebo",Arial,sans-serif;background:#f4f8f8;color:var(--ink)}
  .top{background:var(--teal);color:#fff;padding:14px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
  .top h1{font-size:1.1rem;margin:0;font-weight:800}
  .top .spacer{flex:1}
  .top a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:8px 14px;border-radius:999px;font-size:.9rem}
  .wrap{max-width:1000px;margin:0 auto;padding:20px}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;margin-bottom:16px}
  h2{margin:0 0 6px;font-size:1.05rem;color:var(--teal)}
  p.sub{margin:0 0 18px;color:var(--muted);font-size:.9rem;line-height:1.6}
  .err{background:#fdecea;color:#c0392b;padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:.9rem}
  input[type=file]{display:block;width:100%;padding:26px;border:2px dashed var(--line);border-radius:12px;background:#fbfdfd;font-family:inherit;cursor:pointer}
  button.go{background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 22px;font-weight:800;font-size:.95rem;cursor:pointer;font-family:inherit;margin-top:16px}
  button.go:hover{background:#095657}
  a.back{color:var(--teal);font-size:.9rem}
  table{width:100%;border-collapse:collapse;font-size:.88rem}
  th,td{padding:9px 11px;text-align:right;border-bottom:1px solid var(--line);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px}
  th{background:var(--pale);color:var(--teal);font-weight:700}
  .scroll{overflow:auto;border:1px solid var(--line);border-radius:12px;margin-bottom:18px}
  .maprow{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);flex-wrap:wrap}
  .maprow b{min-width:190px;font-weight:600;font-size:.92rem}
  select,input[type=text]{padding:9px 12px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:.9rem;background:#fff;min-width:220px}
  .opts{display:flex;gap:20px;flex-wrap:wrap;margin:18px 0 0;font-size:.9rem;align-items:center}
  .opts label{display:flex;align-items:center;gap:7px;cursor:pointer}
  .stat{display:inline-block;background:var(--pale);border-radius:12px;padding:14px 20px;margin:0 0 10px 10px;min-width:120px}
  .stat b{display:block;font-size:1.6rem;color:var(--teal);line-height:1.1}
  .stat span{font-size:.82rem;color:var(--muted)}
  .hint{color:var(--muted);font-size:.83rem;line-height:1.7;margin-top:14px}
  .undo{margin-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .undo button{background:#fff;border:1px solid #e6b7b1;color:#c0392b;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.9rem}
  .undo button:hover{background:#fdecea}
  .undo span{color:var(--muted);font-size:.83rem}
</style>
</head>
<body>
<div class="top">
  <h1>ייבוא לידים מקובץ</h1>
  <div class="spacer"></div>
  <a href="index.php">→ חזרה ל־CRM</a>
</div>
<div class="wrap">

<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
  <div class="card">
    <h2>שלב 1 — העלאת הקובץ</h2>
    <p class="sub">קבצים נתמכים: <b>xlsx</b> (Excel) או <b>csv</b>. עד 8MB / 5000 שורות.<br>
       מומלץ ששורה ראשונה תהיה כותרות (שם, טלפון, דוא״ל…) — המערכת תזהה אותן לבד.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <input type="hidden" name="step" value="upload">
      <input type="file" name="file" accept=".xlsx,.csv,.txt,.tsv" required>
      <button class="go" type="submit">המשך למיפוי העמודות ←</button>
    </form>
    <p class="hint">שום דבר לא נשמר ב־CRM בשלב זה — בשלב הבא תראו תצוגה מקדימה ותאשרו.</p>
  </div>
  <?php if ($lastImport): ?>
  <div class="card">
    <h2>הייבוא האחרון</h2>
    <p class="sub"><b><?= count($lastImport['ids']) ?></b> לידים מהקובץ <b><?= h($lastImport['file']) ?></b> · <?= h($lastImport['at']) ?></p>
    <form method="post" class="undo" onsubmit="return confirm('למחוק את הלידים שיובאו בפעם האחרונה?')">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <input type="hidden" name="step" value="undo">
      <button type="submit">↺ ביטול הייבוא האחרון</button>
      <span>אפשרי עד 24 שעות מהייבוא.</span>
    </form>
  </div>
  <?php endif; ?>

<?php elseif ($step === 'map'):
  $header  = $rows[0] ?? [];
  $preview = array_slice($rows, 0, 6);
?>
  <div class="card">
    <h2>שלב 2 — מיפוי העמודות</h2>
    <p class="sub">נמצאו <b><?= count($rows) - 1 ?></b> שורות נתונים בקובץ. בדקו שהעמודות מתאימות ואשרו.</p>

    <div class="scroll">
      <table>
        <?php foreach ($preview as $ri => $r): ?>
        <tr>
          <?php foreach ($header as $ci => $_): ?>
            <?php if ($ri === 0): ?><th><?= h(imp_utf8($r[$ci] ?? '')) ?></th>
            <?php else: ?><td><?= h(imp_utf8($r[$ci] ?? '')) ?></td><?php endif; ?>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <input type="hidden" name="step" value="commit">
      <input type="hidden" name="token" value="<?= h($token) ?>">

      <?php foreach ($FIELDS as $f => $lbl): ?>
      <div class="maprow">
        <b><?= h($lbl) ?><?= $f === 'name' || $f === 'phone' ? ' *' : '' ?></b>
        <select name="map[<?= h($f) ?>]">
          <option value="-1">— ללא —</option>
          <?php foreach ($header as $ci => $cell): ?>
            <option value="<?= (int)$ci ?>"<?= (($map[$f] ?? -1) === $ci) ? ' selected' : '' ?>>
              <?= h(imp_utf8($cell) !== '' ? imp_utf8($cell) : ('עמודה ' . ($ci + 1))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endforeach; ?>

      <div class="opts">
        <label><input type="checkbox" name="has_header" value="1" checked> השורה הראשונה היא כותרות</label>
        <label><input type="checkbox" name="skip_dup" value="1" checked> דילוג על טלפון שכבר קיים ב־CRM</label>
      </div>
      <div class="opts">
        <label>מקור ברירת מחדל: <input type="text" name="default_source" value="ייבוא מקובץ"></label>
        <label>סטטוס ברירת מחדל:
          <select name="default_status">
            <?php foreach ($ST_LBL as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>

      <button class="go" type="submit">ייבוא ל־CRM ←</button>
      <p class="hint">שורה בלי שם ובלי טלפון תדולג. טלפון שאיבד את ה־0 בגלל אקסל (501234567) יתוקן אוטומטית.</p>
    </form>
  </div>

<?php elseif ($step === 'undone' && $result): ?>
  <div class="card">
    <h2>הייבוא בוטל</h2>
    <p class="sub">נמחקו <b><?= (int)$result['undone'] ?></b> לידים שיובאו מהקובץ <b><?= h($result['file']) ?></b>.</p>
    <p><a class="back" href="import.php">→ ייבוא מחדש</a> &nbsp;·&nbsp; <a class="back" href="index.php">חזרה ל־CRM</a></p>
  </div>

<?php elseif ($step === 'done' && $result): ?>
  <div class="card">
    <h2>הייבוא הושלם</h2>
    <p class="sub">קובץ: <b><?= h($result['file']) ?></b></p>
    <div class="stat"><b><?= (int)$result['added'] ?></b><span>לידים נוספו</span></div>
    <div class="stat"><b><?= (int)$result['dup'] ?></b><span>דילוג — טלפון קיים</span></div>
    <div class="stat"><b><?= (int)$result['bad'] ?></b><span>שורות ריקות / לא תקינות</span></div>
    <p style="margin-top:18px"><a class="back" href="index.php">→ למעבר ל־CRM ולצפייה בלידים</a> &nbsp;·&nbsp; <a class="back" href="import.php">ייבוא קובץ נוסף</a></p>
    <?php if ((int)$result['added'] > 0): ?>
    <form method="post" class="undo" onsubmit="return confirm('לבטל את הייבוא ולמחוק את הלידים שנוספו כרגע?')">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <input type="hidden" name="step" value="undo">
      <button type="submit">↺ ביטול הייבוא ומחיקת <?= (int)$result['added'] ?> הלידים שנוספו</button>
      <span>אם המיפוי יצא שגוי — אפשר לבטל ולהתחיל מחדש.</span>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

</div>
</body>
</html>
