<?php
/* =====================================================================
   crm/wa-intake.php — استقبال ليد جديد قادم من بوت WhatsApp (اتجاه داخل).

   عندما يراسل عميلٌ واتساب العيادة ولا يكون موجوداً كليد، يجمع البوت بياناته
   عبر أسئلته ثم ينادي هذه النقطة (خادم-إلى-خادم) لإنشاء ليد في الـCRM.

   - POST فقط، بمفتاح Bearer (intake_key) يُولَّد من لوحة الـCRM ويُحفظ
     خارج مجلد النشر في wa_config.json. لا يصل المتصفح أبداً.
   - منع التكرار حسب الهاتف (آخر 9 أرقام): إن كان الليد موجوداً تُضاف ملاحظة
     بالبيانات الجديدة بدل إنشاء ليد مكرّر.
   - الحقول: phone (إلزامي) + name, city, fit, question, msg, interest,
     source, wa_id  (JSON أو form-urlencoded).

   العقد الكامل: crm-whatsapp-intake.md
   ===================================================================== */

require_once __DIR__ . '/store.php';   // ضبط التوقيت + crm_data_dir + crm_wa_cfg
header('Content-Type: application/json; charset=utf-8');

function wi_json($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function wi_clean($v) {
    $v = (string)$v;
    $v = preg_replace('/[^\P{C}\n\t]+/u', '', $v);
    return trim(str_replace(["\r", "\n", "\t"], ' ', $v));
}
/* هاتف بصيغة دولية +972… (منع الصفر البادئ) */
function wi_phone($p) {
    $d = preg_replace('/\D/', '', (string)$p);
    if ($d === '') return '';
    if (strpos($d, '00') === 0)        $d = substr($d, 2);
    if (strpos($d, '972') === 0)       { /* ok */ }
    elseif ($d[0] === '0')             $d = '972' . substr($d, 1);
    elseif (strlen($d) === 9 && $d[0] === '5') $d = '972' . $d;
    return $d;
}
function wi_phone_key($p) {
    $d = preg_replace('/\D/', '', (string)$p);
    return strlen($d) > 9 ? substr($d, -9) : $d;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    wi_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

/* ---- المصادقة: Bearer intake_key (أو ترويسة X-CRM-Key) ---- */
$cfg = crm_wa_cfg();
$key = (string)($cfg['intake_key'] ?? '');
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$got  = '';
if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $got = trim($m[1]);
if ($got === '') $got = trim((string)($_SERVER['HTTP_X_CRM_KEY'] ?? ''));
if ($key === '' || !hash_equals($key, $got)) {
    wi_json(['ok' => false, 'error' => 'unauthorized'], 401);
}

/* ---- قراءة الجسم (JSON أو form) ---- */
$raw = (string)file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$phoneRaw = $in['phone'] ?? ($in['phone_number'] ?? '');
$phone    = wi_phone($phoneRaw);
if (strlen($phone) < 7 || strlen($phone) > 15) {
    wi_json(['ok' => false, 'error' => 'invalid_phone'], 400);
}
$phoneStore = '+' . $phone;

$name = wi_clean($in['name'] ?? ($in['full_name'] ?? ''));
if ($name === '') $name = trim(wi_clean($in['first_name'] ?? '') . ' ' . wi_clean($in['last_name'] ?? ''));
if ($name === '') $name = 'פונה מ־WhatsApp';

$city     = wi_clean($in['city'] ?? '');
$fit      = wi_clean($in['fit'] ?? ($in['current'] ?? ''));
$question = wi_clean($in['question'] ?? '');
$msg      = wi_clean($in['msg'] ?? ($in['message'] ?? ''));
$interest = wi_clean($in['interest'] ?? '');
$source   = wi_clean($in['source'] ?? '') ?: 'WhatsApp';
$waId     = wi_clean($in['wa_id'] ?? ($in['leadId'] ?? ''));

$DIR    = crm_data_dir();
$LEADS  = $DIR . '/leads.ndjson';
$STATUS = $DIR . '/status.json';
$NOTES  = $DIR . '/notes.json';

/* ---- منع التكرار حسب الهاتف ---- */
$pk = wi_phone_key($phoneStore);
$existingId = '';
if ($pk !== '' && is_file($LEADS)) {
    foreach (file($LEADS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $o = json_decode($ln, true);
        if (is_array($o) && wi_phone_key($o['phone'] ?? '') === $pk) { $existingId = (string)($o['id'] ?? ''); }
    }
}

function wi_note_append($f, $id, $txt) {
    $all = [];
    if (is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j)) $all = $j; }
    if (!isset($all[$id]) || !is_array($all[$id])) $all[$id] = [];
    $all[$id][] = ['id' => bin2hex(random_bytes(4)), 't' => date('Y-m-d H:i'), 'txt' => $txt];
    @file_put_contents($f, json_encode($all, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/* ---- ليد موجود: أضف ملاحظة بدل التكرار ---- */
if ($existingId !== '') {
    $parts = [];
    if ($interest !== '') $parts[] = 'טיפול: ' . $interest;
    if ($city !== '')     $parts[] = 'עיר: ' . $city;
    if ($fit !== '')      $parts[] = 'מה מתאים: ' . $fit;
    if ($question !== '') $parts[] = 'שאלה: ' . $question;
    if ($msg !== '')      $parts[] = $msg;
    $txt = 'פנייה חדשה ב־WhatsApp' . ($parts ? ' — ' . implode(' | ', $parts) : '');
    wi_note_append($NOTES, $existingId, $txt);
    wi_json(['ok' => true, 'result' => 'duplicate', 'id' => $existingId]);
}

/* ---- ليد جديد ---- */
$lid = date('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
$lead = [
    'id'       => $lid,
    'ts'       => date('Y-m-d H:i'),
    'name'     => $name,
    'phone'    => $phoneStore,
    'email'    => wi_clean($in['email'] ?? ''),
    'interest' => $interest,
    'msg'      => $msg,
    'city'     => $city,
    'fit'      => $fit,
    'question' => $question,
    'source'   => $source,
    'ip'       => '',
    'consent'  => true,          // العميل بادر بالمراسلة (اتجاه داخل)
    'inbound'  => 1,
    'wa_id'    => $waId,
];
@file_put_contents($LEADS, json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

/* الحالة الأولية: נוצר קשר (العميل تواصل فعلاً) */
$st = [];
if (is_file($STATUS)) { $j = json_decode((string)file_get_contents($STATUS), true); if (is_array($j)) $st = $j; }
$st[$lid] = 'contacted';
@file_put_contents($STATUS, json_encode($st, JSON_UNESCAPED_UNICODE), LOCK_EX);

/* ملاحظة أولى إن وُجدت تفاصيل */
if ($question !== '' || $msg !== '') {
    wi_note_append($NOTES, $lid, 'מהשיחה ב־WhatsApp: ' . trim($question . ' ' . $msg));
}

wi_json(['ok' => true, 'result' => 'created', 'id' => $lid]);
