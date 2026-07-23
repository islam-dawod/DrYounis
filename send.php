<?php
/* =====================================================================
   send.php — يستقبل نماذج الموقع ويرسلها إلى بريد العيادة.
   بدون أي إضافة (plugin) وبدون مكتبات خارجية — PHP خالص.
   ضع هذا الملف في المجلد الجذر للموقع (بجانب الصفحات).
   ===================================================================== */

// ------- الإعدادات (عدّلها عند الحاجة) -------
$TO         = 'info.younisclinic@gmail.com';          // وجهة الوصول
$FROM_EMAIL = 'no-reply@younisclinic.com';            // مرسِل على نطاقك (مهم للوصول لـGmail)
$FROM_NAME  = 'אתר ד״ר תחסין יונס';                    // اسم المرسِل
$SUBJECT    = 'פנייה חדשה מהאתר';                      // موضوع الرسالة
// ---------------------------------------------

header('Content-Type: application/json; charset=utf-8');

// اقبل POST فقط
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// حماية من السبام (Honeypot): حقل مخفي اسمه company يجب أن يبقى فارغًا
if (!empty($_POST['company'])) {
    // نردّ "نجاح" لخداع الـbot دون إرسال شيء
    echo json_encode(['ok' => true]);
    exit;
}

// إزالة أحرف قد تُستغل لحقن ترويسات البريد
function clean($v) {
    return trim(str_replace(["\r", "\n", "%0a", "%0d", "%0A", "%0D"], ' ', (string)$v));
}

// أسماء عربية/عبرية مقروءة للحقول الشائعة
$labels = [
    'fname'    => 'שם מלא',
    'name'     => 'שם מלא',
    'phone'    => 'טלפון',
    'method'   => 'דרך התקשרות מועדפת',
    'interest' => 'טיפול שמעניין',
    'time'     => 'זמן מועדף',
    'msg'      => 'הודעה',
    'message'  => 'הודעה',
];

$name  = clean($_POST['fname'] ?? ($_POST['name'] ?? ''));
$phone = clean($_POST['phone'] ?? '');

// تحقّق أساسي: الاسم والهاتف مطلوبان
if ($name === '' || $phone === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

// بناء نص الرسالة من كل الحقول المُرسلة
$lines = [];
foreach ($_POST as $key => $val) {
    if (in_array($key, ['company', 'consent'], true)) continue; // تجاهل الـhoneypot والموافقة
    if (is_array($val)) $val = implode(', ', $val);
    if (trim((string)$val) === '') continue;
    $label   = $labels[$key] ?? $key;
    $lines[] = $label . ': ' . clean($val);
}

$body  = "פנייה חדשה מהאתר\n";
$body .= "==============================\n\n";
$body .= implode("\n", $lines) . "\n\n";
$body .= "------------------------------\n";
$body .= 'התקבל: ' . date('Y-m-d H:i') . "\n";
$body .= 'מקור: ' . ($_SERVER['HTTP_HOST'] ?? '') . "\n";
$body .= 'IP: '   . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n";

// ترميز الموضوع UTF-8 حتى تظهر العبرية بشكل صحيح
$subjectEnc = '=?UTF-8?B?' . base64_encode($SUBJECT . ($name ? ' — ' . $name : '')) . '?=';
$fromEnc    = '=?UTF-8?B?' . base64_encode($FROM_NAME) . '?=';

$headers  = 'MIME-Version: 1.0' . "\r\n";
$headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
$headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";
$headers .= 'From: ' . $fromEnc . ' <' . $FROM_EMAIL . '>' . "\r\n";
$headers .= 'Reply-To: ' . $TO . "\r\n";
$headers .= 'X-Mailer: PHP/' . phpversion();

// الإرسال. المعامل الخامس يضبط المرسِل على مستوى المُغلّف (يحسّن الوصول)
$sent = @mail($TO, $subjectEnc, $body, $headers, '-f' . $FROM_EMAIL);

// نسخة احتياطية اختيارية: سجل محلي في حال فشل البريد (احذف التعليق للتفعيل)
// @file_put_contents(__DIR__.'/leads.log', $body."\n\n", FILE_APPEND | LOCK_EX);

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'send_failed']);
}
