<?php
/* =====================================================================
   crm/fb-webhook.php — يستقبل لِيدات Facebook / Instagram (Lead Ads)
   ويخزّنها في نفس مخزن الـCRM لتظهر في /crm مع بقية الپنيّات.

   يدعم طريقتين:

   (A) الربط الرسمي مع Meta (Webhook + Graph API):
       Meta ترسل إشعاراً يحتوي leadgen_id فقط (لا بيانات العميل)،
       فنسحب البيانات من Graph API عبر Page Access Token.
       - Callback URL : https://younisclinic.com/crm/fb-webhook.php
       - Verify Token : يُولَّد من لوحة الـCRM
       - App Secret + Page Access Token: يُحفظان من لوحة الـCRM
       تُحفظ كلها في fb_config.json خارج مجلد النشر.

   (B) وسيط (Make / Zapier / n8n) — POST بسيط مع Key:
       key, name|full_name, phone, email, interest, msg, form_name, source
       (JSON أو application/x-www-form-urlencoded)
   ===================================================================== */

require_once __DIR__ . '/store.php';

$DIR = crm_data_dir();
$CFG = crm_fb_cfg();

/* ---------------- أدوات ---------------- */
function fb_clean($v) { return trim(str_replace(["\r", "\n"], ' ', (string)$v)); }

function fb_log($msg) {
    @file_put_contents(
        crm_data_dir() . '/fb_errors.log',
        date('Y-m-d H:i') . ' | ' . $msg . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function fb_json($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* هل سبق تخزين هذا الليد؟ (Meta تعيد الإرسال عند عدم وصول 200) */
function fb_seen($dir, $leadgenId) {
    if ($leadgenId === '') return false;
    $f = $dir . '/leads.ndjson';
    if (!is_file($f)) return false;
    return strpos((string)file_get_contents($f), '"lead_id":"' . $leadgenId . '"') !== false;
}

function fb_store($dir, $lead) {
    @file_put_contents(
        $dir . '/leads.ndjson',
        json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function fb_new_id() {
    return date('YmdHis') . substr(md5(uniqid('', true)), 0, 6);
}

/* طلب GET إلى Graph API (curl وإلا file_get_contents) */
function fb_http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) { fb_log('curl error: ' . $err); return null; }
        return $body;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { fb_log('file_get_contents failed for graph request'); return null; }
    return $body;
}

/* اسم النموذج (لمعرفة من أي إعلان/فورم جاء اللِيد) */
function fb_form_name($formId, $token) {
    static $cache = [];
    if ($formId === '' || $token === '') return '';
    if (isset($cache[$formId])) return $cache[$formId];
    $body = fb_http_get('https://graph.facebook.com/v21.0/' . rawurlencode($formId)
                        . '?fields=name&access_token=' . rawurlencode($token));
    $j = $body !== null ? json_decode($body, true) : null;
    $cache[$formId] = (is_array($j) && !empty($j['name'])) ? fb_clean($j['name']) : '';
    return $cache[$formId];
}

/* ============ (1) التحقّق من الويبهوك: Meta ترسل GET مرة واحدة ============ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // PHP يحوّل النقطة في اسم الپارامتر إلى _ ، أي hub.mode => hub_mode
    $mode      = (string)($_GET['hub_mode']         ?? '');
    $token     = (string)($_GET['hub_verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge']    ?? '');
    $expected  = (string)($CFG['verify_token'] ?? '');

    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;                       // Meta تتوقّع إعادة الـchallenge كما هو
        exit;
    }
    fb_log('verify failed (mode=' . $mode . ')');
    fb_json(['ok' => false, 'error' => 'verification_failed'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fb_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$raw  = (string)file_get_contents('php://input');
$data = json_decode($raw, true);

/* ============ (2) الطريقة (A): إشعار leadgen رسمي من Meta ============ */
if (is_array($data) && (($data['object'] ?? '') === 'page') && !empty($data['entry'])) {

    // التحقّق من التوقيع إن كان App Secret محفوظاً
    $secret = (string)($CFG['app_secret'] ?? '');
    if ($secret !== '') {
        $sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        $exp = 'sha256=' . hash_hmac('sha256', $raw, $secret);
        if ($sig === '' || !hash_equals($exp, $sig)) {
            fb_log('bad signature');
            fb_json(['ok' => false, 'error' => 'bad_signature'], 401);
        }
    }

    $token = (string)($CFG['page_token'] ?? '');
    $saved = 0;

    foreach ($data['entry'] as $entry) {
        foreach (($entry['changes'] ?? []) as $ch) {
            if (($ch['field'] ?? '') !== 'leadgen') continue;
            $v      = $ch['value'] ?? [];
            $lgid   = fb_clean($v['leadgen_id'] ?? '');
            $formId = fb_clean($v['form_id'] ?? '');
            if ($lgid === '' || fb_seen($DIR, $lgid)) continue;

            $name = ''; $first = ''; $last = ''; $phone = ''; $email = ''; $extra = [];
            $formName = fb_form_name($formId, $token);

            // سحب بيانات الليد من Graph API
            if ($token !== '') {
                $url  = 'https://graph.facebook.com/v21.0/' . rawurlencode($lgid)
                      . '?access_token=' . rawurlencode($token);
                $body = fb_http_get($url);
                $g    = $body !== null ? json_decode($body, true) : null;

                if (is_array($g) && isset($g['error'])) {
                    fb_log('graph error for ' . $lgid . ': ' . json_encode($g['error'], JSON_UNESCAPED_UNICODE));
                } elseif (is_array($g)) {
                    foreach (($g['field_data'] ?? []) as $f) {
                        $key = strtolower(fb_clean($f['name'] ?? ''));
                        $val = fb_clean(implode(', ', (array)($f['values'] ?? [])));
                        if ($val === '') continue;
                        if     ($key === 'full_name')                      $name  = $val;
                        elseif ($key === 'first_name')                     $first = $val;
                        elseif ($key === 'last_name')                      $last  = $val;
                        elseif ($key === 'phone_number' || $key === 'phone') $phone = $val;
                        elseif ($key === 'email')                          $email = $val;
                        else   $extra[] = $key . ': ' . $val;   // أسئلة مخصّصة
                    }
                }
            } else {
                fb_log('no page_token saved — lead ' . $lgid . ' stored without details');
            }

            if ($name === '')  $name = trim($first . ' ' . $last);
            if ($name === '')  $name = 'ליד מפייסבוק';

            $msg = implode(' | ', $extra);
            if ($token === '') $msg = trim($msg . ' | leadgen_id: ' . $lgid . ' (חסר Page Access Token — הפרטים לא נמשכו)');

            fb_store($DIR, [
                'id'       => fb_new_id(),
                'ts'       => date('Y-m-d H:i'),
                'name'     => $name,
                'phone'    => $phone,
                'email'    => $email,
                'interest' => $formName,
                'msg'      => $msg,
                'source'   => 'Facebook',
                'ip'       => '',
                'lead_id'  => $lgid,
                'form_id'  => $formId,
                'page_id'  => fb_clean($v['page_id'] ?? ''),
                'ad_id'    => fb_clean($v['ad_id'] ?? ''),
            ]);
            $saved++;
        }
    }

    fb_json(['ok' => true, 'saved' => $saved]);   // Meta تتوقّع 200 دائماً
}

/* ============ (3) الطريقة (B): POST بسيط عبر Make / Zapier / n8n ============ */
$in = is_array($data) ? $data : $_POST;

$key = (string)($CFG['key'] ?? '');
$got = (string)($in['key'] ?? ($_SERVER['HTTP_X_CRM_KEY'] ?? ''));
if ($key === '' || !hash_equals($key, $got)) {
    fb_log('invalid key from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    fb_json(['ok' => false, 'error' => 'invalid_key'], 401);
}

$name  = fb_clean($in['name'] ?? ($in['full_name'] ?? ''));
if ($name === '') $name = trim(fb_clean($in['first_name'] ?? '') . ' ' . fb_clean($in['last_name'] ?? ''));
if ($name === '') $name = 'ליד מפייסבוק';

$phone = fb_clean($in['phone'] ?? ($in['phone_number'] ?? ''));
$lgid  = fb_clean($in['lead_id'] ?? ($in['leadgen_id'] ?? ''));
if ($lgid !== '' && fb_seen($DIR, $lgid)) fb_json(['ok' => true, 'duplicate' => true]);

fb_store($DIR, [
    'id'       => fb_new_id(),
    'ts'       => date('Y-m-d H:i'),
    'name'     => $name,
    'phone'    => $phone,
    'email'    => fb_clean($in['email'] ?? ''),
    'interest' => fb_clean($in['interest'] ?? ($in['form_name'] ?? '')),
    'msg'      => fb_clean($in['msg'] ?? ($in['message'] ?? '')),
    'source'   => fb_clean($in['source'] ?? '') ?: 'Facebook',
    'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    'lead_id'  => $lgid,
]);

fb_json(['ok' => true]);
