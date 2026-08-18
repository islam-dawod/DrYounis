<?php
/* =====================================================================
   crm/gads-webhook.php — يستقبل لِيدات Google Ads (Lead Form Webhook)
   ويخزّنها في نفس مخزن الـCRM لتظهر في /crm مع بقية الپنيّات.
   في Google Ads: نموذج لِيدات → Delivery → Webhook:
     - Webhook URL: https://younisclinic.com/crm/gads-webhook.php
     - Key:        المفتاح المولَّد من لوحة الـCRM
   ===================================================================== */

require_once __DIR__ . '/store.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$dir = crm_data_dir();
$KEY = is_file($dir . '/gads_key.txt') ? trim(file_get_contents($dir . '/gads_key.txt')) : '';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_payload']);
    exit;
}

// التحقق من المفتاح (Google يرسله في google_key)
$sent = (string)($data['google_key'] ?? '');
if ($KEY === '' || !hash_equals($KEY, $sent)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_key']);
    exit;
}

function g_clean($v) { return trim(str_replace(["\r", "\n"], ' ', (string)$v)); }

// استخراج الحقول من user_column_data
$name = ''; $first = ''; $last = ''; $phone = ''; $email = '';
foreach (($data['user_column_data'] ?? []) as $c) {
    $id = strtoupper((string)($c['column_id'] ?? ''));
    $v  = g_clean($c['string_value'] ?? '');
    if ($v === '') continue;
    if ($id === 'FULL_NAME' || $id === 'NAME')      $name  = $v;
    elseif ($id === 'FIRST_NAME')                    $first = $v;
    elseif ($id === 'LAST_NAME')                     $last  = $v;
    elseif ($id === 'PHONE_NUMBER')                  $phone = $v;
    elseif ($id === 'EMAIL')                         $email = $v;
}
if ($name === '') $name = trim($first . ' ' . $last);
if ($name === '') $name = 'ליד מ־Google Ads';

$is_test = !empty($data['is_test']);
$lead = [
    'id'          => date('YmdHis') . substr(md5(uniqid('', true)), 0, 6),
    'ts'          => date('Y-m-d H:i'),
    'name'        => $name,
    'phone'       => $phone,
    'email'       => $email,
    'interest'    => '',
    'msg'         => '',
    'source'      => 'Google Ads' . ($is_test ? ' (בדיקה)' : ''),
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    'gclid'       => g_clean($data['gcl_id'] ?? ''),
    'campaign_id' => g_clean($data['campaign_id'] ?? ''),
    'lead_id'     => g_clean($data['lead_id'] ?? ''),
];
@file_put_contents(
    $dir . '/leads.ndjson',
    json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);

// Google يتوقّع 200
echo json_encode(['ok' => true, 'lead_id' => $lead['lead_id']]);
