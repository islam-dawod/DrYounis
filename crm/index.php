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
$NOTES  = $DATA . '/notes.json';
$WA     = $DATA . '/wa.json';     // { id: {result, sendStatus, deliveryStatus, ...} }

/* حالات الليد — معرّفة في store.php ليستعملها الاستيراد أيضاً */
$ST_LBL = crm_statuses();

/* ---------- أدوات ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/* رقم بصيغة WhatsApp الدولية (بلا + ولا أصفار) — افتراض إسرائيل +972 */
function wa_number($p){
    $d = preg_replace('/\D/', '', (string)$p);
    if ($d === '') return '';
    if (strpos($d, '00972') === 0) return substr($d, 2);        // 00972... => 972...
    if (strpos($d, '972') === 0)   return $d;                    // 972... كما هو
    if ($d[0] === '0')             return '972' . substr($d, 1); // 05x... => 9725x...
    if (strlen($d) === 9 && $d[0] === '5') return '972' . $d;   // 5x... => 9725x...
    return $d;                                                    // رقم دولي آخر — كما هو
}
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
function load_wa($f){ if (is_file($f)) { $j = json_decode(file_get_contents($f), true); if (is_array($j)) return $j; } return []; }
function save_wa($f, $a){ @file_put_contents($f, json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX); }
/* نداء خادم-إلى-خادم للـWorker (يحمل السرّ) */
function wa_http($method, $url, $secret, $payload){
    $hdr = ['Authorization: Bearer ' . $secret, 'Accept: application/json'];
    if ($payload !== null) $hdr[] = 'Content-Type: application/json';
    $data = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code'=>$code, 'json'=>($resp !== false ? json_decode($resp, true) : null)];
    }
    $ctx = stream_context_create(['http'=>[
        'method'=>$method, 'header'=>implode("\r\n", $hdr), 'content'=>$data,
        'timeout'=>15, 'ignore_errors'=>true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    return ['code'=>$code, 'json'=>($resp !== false ? json_decode($resp, true) : null)];
}
/* دمج ردّ الـAPI في سجل الليد المحفوظ */
function wa_merge($old, $body, $code){
    $r = is_array($old) ? $old : [];
    foreach (['result','sendStatus','messageId','providerStatus','deliveryStatus','deliveryErrorCode','reason','error','requestedAt','updatedAt'] as $k) {
        if (array_key_exists($k, (array)$body)) $r[$k] = $body[$k];
    }
    $r['httpCode'] = $code;
    return $r;
}
/* نص عبري مختصر لحالة الإرسال/التسليم */
function wa_label($rec){
    if (!$rec || empty($rec['result'])) return '';
    $res = $rec['result']; $send = $rec['sendStatus'] ?? ''; $del = $rec['deliveryStatus'] ?? '';
    if ($del === 'read')      return 'נקרא ✓✓';
    if ($del === 'delivered') return 'נמסר ✓';
    if ($del === 'failed')    return 'נכשל (' . ($rec['deliveryErrorCode'] ?? '') . ')';
    if ($del === 'sent')      return 'בדרך…';
    if ($res === 'accepted' || $send === 'accepted') return 'נשלח, ממתין';
    if ($res === 'duplicate') return 'כבר נשלח';
    if ($res === 'ineligible') {
        $m = ['conversation_active'=>'בשיחה עם הבוט','handoff_active'=>'בטיפול נציג','recently_completed'=>'טופל לאחרונה','recently_contacted'=>'נשלח ב־24ש׳','not_in_pilot'=>'לא ברשימת הבדיקה'];
        return $m[$rec['reason'] ?? ''] ?? 'לא זמין כעת';
    }
    if ($res === 'error') {
        $m = ['send_outcome_unknown'=>'לא ודאי — בדקו ב־WhatsApp','automation_off'=>'השליחה מושהית','whatsapp_unavailable'=>'WhatsApp לא מחובר'];
        return $m[$rec['error'] ?? ''] ?? 'שגיאה';
    }
    return '';
}
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
            'city'     => note_clean($_POST['city']     ?? ''),
            'fit'      => note_clean($_POST['fit']      ?? ''),
            'question' => note_clean($_POST['question'] ?? ''),
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
            $leads[$i]['city']     = note_clean($_POST['city']     ?? '');
            $leads[$i]['fit']      = note_clean($_POST['fit']      ?? '');
            $leads[$i]['question'] = note_clean($_POST['question'] ?? '');
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
    if ($_POST['action'] === 'wa_genkey') {
        $cfg = crm_wa_cfg();
        $cfg['intake_key'] = bin2hex(random_bytes(24));
        crm_wa_cfg_save($cfg);
        echo json_encode(['ok'=>true, 'intake_key'=>$cfg['intake_key']]); exit;
    }
    if ($_POST['action'] === 'wa_save') {
        $cfg = crm_wa_cfg();
        $sec = trim((string)($_POST['secret']   ?? ''));
        $bu  = trim((string)($_POST['base_url'] ?? ''));
        if ($sec !== '') $cfg['secret'] = $sec;            // فارغ = أبقِ القديم
        if ($bu  !== '') $cfg['base_url'] = rtrim($bu, '/');
        crm_wa_cfg_save($cfg);
        echo json_encode(['ok'=>true, 'secret'=>!empty($cfg['secret']), 'base_url'=>$cfg['base_url']]); exit;
    }
    if ($_POST['action'] === 'wa_send' || $_POST['action'] === 'wa_status') {
        $cfg = crm_wa_cfg();
        $secret = (string)($cfg['secret'] ?? '');
        $base   = rtrim((string)($cfg['base_url'] ?? ''), '/');
        if ($secret === '' || $base === '') { echo json_encode(['ok'=>false,'error'=>'not_configured']); exit; }

        // ابحث عن الليد بالمعرّف الداخلي
        $lead = null;
        foreach (load_leads($LEADS) as $l0) { if (($l0['id'] ?? '') === $id) { $lead = $l0; break; } }
        if (!$lead) { echo json_encode(['ok'=>false,'error'=>'lead_not_found']); exit; }

        // معرّف الليد لـAPI = معرّفنا الداخلي (يطابق النمط المطلوب)
        $leadId = $id;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $leadId) || strpos($leadId, 'wa-staff-') === 0) {
            echo json_encode(['ok'=>false,'error'=>'bad_lead_id']); exit;
        }

        $waAll = load_wa($WA);

        if ($_POST['action'] === 'wa_status') {
            $r = wa_http('GET', $base . '/crm/v1/whatsapp-opening/' . rawurlencode($leadId), $secret, null);
            $body = is_array($r['json']) ? $r['json'] : [];
            if (($body['result'] ?? '') === 'found' || ($body['result'] ?? '') === 'not_found') {
                $waAll[$id] = wa_merge($waAll[$id] ?? [], $body, $r['code']);
                save_wa($WA, $waAll);
            }
            echo json_encode(['ok'=>true, 'rec'=>$waAll[$id] ?? ['result'=>'not_found'], 'label'=>wa_label($waAll[$id] ?? [])]); exit;
        }

        // wa_send — تحقّق الهاتف + الموافقة
        $phone = wa_number($lead['phone'] ?? '');            // صيغة دولية بلا + ولا صفر بادئ
        if (strlen($phone) < 7 || strlen($phone) > 15) { echo json_encode(['ok'=>false,'error'=>'bad_phone']); exit; }

        // الموافقة: ليدات نماذج الموقع مُوافَق عليها (خانة إلزامية). غيرها تتطلّب تأكيد الموظّف.
        $hasConsent = !empty($lead['consent']);
        if (!$hasConsent && empty($_POST['confirm'])) { echo json_encode(['ok'=>false,'error'=>'need_consent_confirm']); exit; }

        $payload = ['leadId'=>$leadId, 'phone'=>'+'.$phone, 'contactConsent'=>true];
        $r = wa_http('POST', $base . '/crm/v1/whatsapp-opening', $secret, $payload);
        $body = is_array($r['json']) ? $r['json'] : [];

        $rec = wa_merge($waAll[$id] ?? [], $body, $r['code']);
        if (empty($body)) { $rec['result'] = 'error'; $rec['error'] = 'no_response'; }
        $rec['at'] = date('Y-m-d H:i');
        $waAll[$id] = $rec;
        save_wa($WA, $waAll);

        $ok = in_array($r['code'], [200], true) && in_array(($body['result'] ?? ''), ['accepted','duplicate'], true);
        echo json_encode(['ok'=>$ok, 'code'=>$r['code'], 'rec'=>$rec, 'label'=>wa_label($rec),
                          'error'=>$ok ? null : (($body['reason'] ?? $body['error']) ?? 'error')]); exit;
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
$wa     = load_wa($WA);
$wa_cfg = crm_wa_cfg();
$wa_intake_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'younisclinic.com') . '/crm/wa-intake.php';

/* ترقية لمرة واحدة: الحالة «טופל» (done) استُبدلت بـ«נקבע תור» (appointment).
   أي قيمة لم تعد موجودة في قائمة الحالات تُنقل لأقرب مكافئ حتى لا يبقى ليد بحالة يتيمة. */
$stFix = false;
foreach ($status as $sk => $sv) {
    if (isset($ST_LBL[$sv])) continue;
    $status[$sk] = ($sv === 'done') ? 'appointment' : 'new';
    $stFix = true;
}
if ($stFix) save_status($STATUS, $status);
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
    fputcsv($out, ['תאריך','שם','טלפון','דוא״ל','עיר','טיפול','מה מתאים כרגע','שאלה / מידע','הודעה','מקור','סטטוס','הערות']);
    foreach ($leads as $l) {
        $lid = $l['id'] ?? '';
        $s   = $status[$lid] ?? 'new';
        $nl  = [];
        foreach (($notes[$lid] ?? []) as $n) {
            $nl[] = '[' . ($n['t'] ?? '') . '] ' . ($n['txt'] ?? '')
                  . (!empty($n['e']) ? ' (נערך ' . $n['e'] . ')' : '');
        }
        fputcsv($out, [$l['ts']??'', $l['name']??'', $l['phone']??'', $l['email']??'', $l['city']??'', $l['interest']??'', $l['fit']??'', $l['question']??'', $l['msg']??'', $l['source']??'', $ST_LBL[$s]??$s, implode(chr(10), $nl)]);
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
  .top{background:var(--teal);color:#fff;padding:14px 26px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
  .top h1{font-size:1.15rem;margin:0;font-weight:800}
  .top .spacer{flex:1}
  .top a.logout{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:8px 14px;border-radius:999px;font-size:.9rem}
  .top a.logout:hover{background:rgba(255,255,255,.28)}
  .wrap{max-width:2000px;margin:0 auto;padding:20px 26px}
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
  td.msg{max-width:360px;white-space:pre-wrap;word-break:normal;overflow-wrap:break-word;color:#3f4f4f}
  a.lnk{color:var(--teal);text-decoration:none}
  a.lnk:hover{text-decoration:underline}
  a.walnk{color:#0f8f4c;font-weight:700;display:inline-flex;align-items:center;gap:5px;direction:ltr;padding-left:10px}
  a.walnk:hover{text-decoration:none;color:#0b7d40}
  .waico{width:15px;height:15px;flex:0 0 auto}
  a.callbtn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;margin-inline-end:5px;
    border:1px solid var(--line);border-radius:9px;color:var(--teal);background:var(--pale);vertical-align:middle;text-decoration:none}
  a.callbtn:hover{background:#e1f1f0;border-color:var(--teal)}
  a.callbtn svg{width:15px;height:15px}
  .botbtn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;margin-inline-start:5px;
    border:1px solid #cdefda;border-radius:9px;color:#0f8f4c;background:#e7f9ee;vertical-align:middle;cursor:pointer}
  .botbtn:hover{background:#d6f3e2;border-color:#0f8f4c}
  .botbtn:disabled{opacity:.55;cursor:default}
  .botbtn.sent{color:#1857b8;background:#e5f0ff;border-color:#bcd6ff}
  .botbtn svg{width:16px;height:16px}
  .wast{font-size:.72rem;color:var(--muted);margin-inline-start:6px;white-space:nowrap}
  #waBase,#waSecret{width:100%;max-width:560px;display:block;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:.88rem;direction:ltr;text-align:left;background:#fff}
  .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700}
  select.st{padding:6px 8px;border-radius:8px;border:1px solid var(--line);font-family:inherit;font-size:.85rem;cursor:pointer;max-width:165px}
  .st-new{background:#fff4e0;color:#a86400}
  .st-contacted{background:#e5f0ff;color:#1857b8}
  .st-appointment{background:#efe7fb;color:#5b3a9e}
  .st-treated{background:#e4f6ec;color:#1c7a45}
  .st-no_answer{background:#f6ece9;color:#8a5148}
  td.acts{white-space:nowrap;position:sticky;right:0;background:#fff;box-shadow:-6px 0 8px -8px rgba(0,0,0,.25);z-index:2}
  tr:hover td.acts{background:#fafdfd}
  th.acth{position:sticky;right:0;z-index:3}
  .edt,.del{border:1px solid var(--line);cursor:pointer;font-size:.8rem;font-weight:700;padding:6px 10px;border-radius:9px;font-family:inherit;display:inline-block;margin-inline-end:6px;white-space:nowrap}
  .edt{background:var(--pale);color:var(--teal)}
  .edt:hover{background:#e1f1f0}
  .del{background:#fff;color:#c0392b;border-color:#eccfcb}
  .del:hover{background:#fdecea}
  td.itr{max-width:260px;white-space:normal;word-break:normal;overflow-wrap:break-word;color:#3f4f4f}
  td.city{white-space:nowrap;color:#3f4f4f}
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

  <details class="gads">
    <summary>בוט WhatsApp — שליחת הודעת פתיחה אוטומטית לליד</summary>
    <div class="gads-body">
      <label>כתובת השרת (Base URL)</label>
      <input type="text" id="waBase" dir="ltr" value="<?= h($wa_cfg['base_url'] ?? '') ?>">
      <label style="margin-top:10px">CRM API Secret <span id="waSecOk" class="okmark"<?= empty($wa_cfg['secret']) ? ' hidden' : '' ?>>שמור ✓</span></label>
      <input type="password" id="waSecret" autocomplete="off" placeholder="<?= empty($wa_cfg['secret']) ? 'הדביקו את המפתח הסודי כאן' : 'השאירו ריק כדי לא לשנות' ?>">
      <button type="button" id="waSave" class="gen">שמירת הפרטים</button>
      <span id="waSaveMsg" class="savemsg"></span>
      <div class="fbsec">
        <label>כתובת קליטת לידים מהבוט (Intake URL)</label>
        <div class="cp"><code id="waInUrl"><?= h($wa_intake_url) ?></code><button type="button" data-copy="waInUrl">העתק</button></div>
        <label>Intake Key (למפתח הבוט)</label>
        <div class="cp"><code id="waInKey"><?= !empty($wa_cfg['intake_key']) ? h($wa_cfg['intake_key']) : '— טרם נוצר —' ?></code><button type="button" data-copy="waInKey">העתק</button></div>
        <button type="button" id="waGenKey" class="gen"><?= !empty($wa_cfg['intake_key']) ? 'יצירת Key חדש' : 'יצירת Intake Key' ?></button>
        <p class="gads-help">כשלקוח כותב ל־WhatsApp של המרפאה ואינו קיים כליד, הבוט אוסף את פרטיו ושולח אותם לכתובת הזו (עם ה־Key ככותרת <code>Authorization: Bearer</code>), וה־CRM יוצר ליד חדש אוטומטית. מניעת כפילות לפי טלפון.</p>
      </div>

      <p class="gads-help">
        לחיצה על כפתור הבוט 🤖 שליד הטלפון שולחת לליד את הודעת הפתיחה של המרפאה ב־WhatsApp, והבוט ממשיך את השיחה. נשלח פעם אחת בלבד לכל ליד. המפתח הסודי נשמר רק בשרת ה־CRM (מחוץ לתיקיית הפרסום) ולעולם לא נחשף בדפדפן.
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
        <tr><th class="acth">פעולות</th><th>תאריך</th><th>שם</th><th>טלפון</th><th>דוא״ל</th><th>עיר</th><th>טיפול</th><th>מה מתאים כרגע?</th><th>שאלה / מידע</th><th>הודעה</th><th>מקור</th><th>הערות</th><th>סטטוס</th></tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $l):
          $id=$l['id']??''; $s=$status[$id]??'new'; $ns=$notes[$id]??[];
          $ntxt=''; foreach ($ns as $n) $ntxt .= ' ' . ($n['txt'] ?? ''); ?>
        <tr class="lead<?= $ns ? ' has-notes' : '' ?>" data-id="<?= h($id) ?>" data-status="<?= h($s) ?>" data-notes="<?= h(trim($ntxt)) ?>"
            data-name="<?= h($l['name']??'') ?>" data-phone="<?= h($l['phone']??'') ?>" data-email="<?= h($l['email']??'') ?>"
            data-interest="<?= h($l['interest']??'') ?>" data-msg="<?= h($l['msg']??'') ?>" data-source="<?= h($l['source']??'') ?>"
            data-city="<?= h($l['city']??'') ?>" data-fit="<?= h($l['fit']??'') ?>" data-question="<?= h($l['question']??'') ?>">
          <td class="acts">
            <button type="button" class="edt" data-id="<?= h($id) ?>" title="עריכת פרטי הליד">✎ עריכה</button>
            <button type="button" class="del" data-id="<?= h($id) ?>" title="מחיקת הליד">🗑 מחיקה</button>
          </td>
          <td style="white-space:nowrap"><?= h($l['ts']??'') ?></td>
          <td><?= h($l['name']??'') ?></td>
          <td style="white-space:nowrap"><?php if(!empty($l['phone'])): ?><a class="lnk walnk" dir="ltr" target="_blank" rel="noopener noreferrer" href="https://wa.me/<?= h(wa_number($l['phone'])) ?>" data-track="crm_wa" title="פתיחת WhatsApp עם הפונה"><svg class="waico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 00-8.5 15.2L2 22l4.9-1.4A10 10 0 1012 2zm5.3 14.1c-.2.6-1.2 1.2-1.7 1.2-.5.1-1 .1-1.6-.1-.4-.1-.9-.3-1.5-.6-2.6-1.1-4.3-3.8-4.4-4-.1-.2-1-1.4-1-2.6s.6-1.8.9-2.1c.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.7 1.8c.1.2 0 .4-.1.5l-.3.4c-.1.2-.3.3-.1.6.1.3.7 1.1 1.4 1.7.9.8 1.7 1 2 1.2.2.1.4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.7.8c.2.1.4.2.4.3.1.1.1.6-.1 1z"/></svg><?= h($l['phone']) ?></a><a class="callbtn" dir="ltr" href="tel:<?= h(preg_replace('/[^0-9+]/','',$l['phone'])) ?>" data-track="crm_call" title="התקשרות לפונה" aria-label="התקשרות"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M6.6 10.8a15 15 0 006.6 6.6l2.2-2.2a1 1 0 011-.24 11 11 0 003.5.56 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1 11 11 0 00.56 3.5 1 1 0 01-.24 1z"/></svg></a><?php $wr=$wa[$id]??[]; $wl=wa_label($wr); ?><button type="button" class="botbtn<?= $wl!==''?' sent':'' ?>" data-id="<?= h($id) ?>" data-consent="<?= !empty($l['consent'])?'1':'0' ?>" title="שליחת הודעת פתיחה של הבוט ב־WhatsApp" aria-label="בוט WhatsApp"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="8" width="16" height="11" rx="3"/><path d="M12 8V4M9 3h6"/><circle cx="9" cy="13.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="15" cy="13.5" r="1.1" fill="currentColor" stroke="none"/></svg></button><span class="wast" data-id="<?= h($id) ?>"><?= h($wl) ?></span><?php endif; ?></td>
          <td><?php if(!empty($l['email'])): ?><a class="lnk" dir="ltr" href="mailto:<?= h($l['email']) ?>"><?= h($l['email']) ?></a><?php endif; ?></td>
          <td class="city"><?= h($l['city']??'') ?></td>
          <td class="itr"><?= h($l['interest']??'') ?></td>
          <td class="itr"><?= h($l['fit']??'') ?></td>
          <td class="msg"><?= h($l['question']??'') ?></td>
          <td class="msg"><?= h($l['msg']??'') ?></td>
          <td class="src"><?= h($l['source']??'') ?></td>
          <td><button type="button" class="nbtn" title="הצגת/הוספת הערות">📝 הערות <span class="ncount"><?= $ns ? '('.count($ns).')' : '' ?></span></button></td>
          <td>
            <select class="st st-<?= h($s) ?>" data-id="<?= h($id) ?>">
              <?php foreach($ST_LBL as $k=>$v): ?><option value="<?= h($k) ?>"<?= $s===$k?' selected':'' ?>><?= h($v) ?></option><?php endforeach; ?>
            </select>
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
          <label>עיר<input name="city"></label>
          <label>מקור<input name="source" value="הוספה ידנית"></label>
          <label>סטטוס
            <select name="status">
              <?php foreach ($ST_LBL as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <label>מה מתאים לכם כרגע?<input name="fit"></label>
        <label>יש לכם שאלה או מידע שתרצו לקבל מאיתנו?<textarea name="question" rows="2"></textarea></label>
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
    lSet('city', g('city'));
    lSet('fit', g('fit'));
    lSet('question', g('question'));
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
    ['name','phone','email','interest','city','fit','question','source','status','msg','note'].forEach(function(k){
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

  // בוט WhatsApp: שמירת הסוד
  var waSave = document.getElementById('waSave');
  if(waSave) waSave.addEventListener('click', function(){
    var sec=document.getElementById('waSecret'), base=document.getElementById('waBase'),
        msg=document.getElementById('waSaveMsg');
    if(!sec.value.trim() && !base.value.trim()){ msg.textContent='אין מה לשמור'; return; }
    waSave.disabled=true; msg.textContent='שומר…';
    post({action:'wa_save', secret:sec.value.trim(), base_url:base.value.trim()}).then(function(r){
      waSave.disabled=false;
      if(!(r&&r.ok)){ msg.textContent='שגיאה — נסו שוב'; return; }
      sec.value=''; sec.placeholder='השאירו ריק כדי לא לשנות';
      var ok=document.getElementById('waSecOk'); if(ok) ok.hidden=!r.secret;
      msg.textContent='נשמר ✓'; setTimeout(function(){ msg.textContent=''; },2000);
    });
  });
  // בוט WhatsApp: יצירת Intake Key
  var waGenKey = document.getElementById('waGenKey');
  if(waGenKey) waGenKey.addEventListener('click', function(){
    if(document.getElementById('waInKey').textContent.indexOf('—')===-1 &&
       !confirm('יצירת Key חדש תנתק את הבוט מהקליטה עד שתעדכנו אותו שם. להמשיך?')) return;
    waGenKey.disabled=true; waGenKey.textContent='יוצר…';
    post({action:'wa_genkey'}).then(function(r){
      if(r&&r.ok){ document.getElementById('waInKey').textContent=r.intake_key; waGenKey.textContent='יצירת Key חדש'; }
      else waGenKey.textContent='שגיאה — נסו שוב';
      waGenKey.disabled=false;
    });
  });
  // בוט WhatsApp: שליחת הודעת פתיחה
  function waErr(e){
    var m={not_configured:'הבוט לא מוגדר — הזינו Secret בהגדרות למעלה',
           bad_phone:'מספר טלפון לא תקין',lead_not_found:'הליד לא נמצא',
           need_consent_confirm:'',no_response:'אין תשובה מהשרת',
           recently_contacted:'כבר נשלח ב־24 השעות האחרונות',conversation_active:'הליד כבר בשיחה עם הבוט',
           not_in_pilot:'המספר אינו ברשימת הבדיקה המאושרת'};
    return (e&&m[e]!==undefined)?m[e]:'שגיאה — נסו שוב';
  }
  document.querySelectorAll('.botbtn').forEach(function(b){
    b.addEventListener('click', function(){
      var id=b.getAttribute('data-id'),
          consent=b.getAttribute('data-consent')==='1',
          st=document.querySelector('.wast[data-id="'+id+'"]');
      var confirmNeeded = !consent;
      if(confirmNeeded && !confirm('לא תועד אישור WhatsApp לליד זה. לשלוח בכל זאת את הודעת הפתיחה של הבוט?')) return;
      if(consent && !confirm('לשלוח לליד את הודעת הפתיחה של הבוט ב־WhatsApp?')) return;
      b.disabled=true; if(st) st.textContent='שולח…';
      post({action:'wa_send', id:id, confirm: confirmNeeded?'1':'0'}).then(function(r){
        if(r && r.ok){
          b.classList.add('sent');
          if(st) st.textContent=r.label||'נשלח';
          // השאר מושבת אחרי שליחה מוצלחת
        } else {
          b.disabled=false;
          if(st) st.textContent = (r&&r.label) ? r.label : waErr(r&&r.error);
        }
      }).catch(function(){ b.disabled=false; if(st) st.textContent='שגיאה'; });
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
      var id=b.getAttribute('data-id'), tr=b.closest('tr'),
          nm=(tr.getAttribute('data-name')||'').trim();
      if(!confirm('למחוק את הליד' + (nm ? ' „'+nm+'”' : '') + ' לצמיתות?\n\nיימחקו גם ההערות והסטטוס שלו. לא ניתן לשחזר.')) return;
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
