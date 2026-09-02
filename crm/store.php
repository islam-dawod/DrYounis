<?php
/* =====================================================================
   crm/store.php — يحدّد مجلد تخزين بيانات الـCRM بحيث:
   1) يكون خارج مجلد النشر (public_html) فلا يُمسح مع كل نشر تلقائي من Git.
   2) لا يكون متاحاً من الويب إطلاقاً (أعلى من جذر الموقع).
   إن تعذّر ذلك (open_basedir)، يعود لمجلد محمي داخل الموقع كحل بديل.
   ===================================================================== */

/* المنطقة الزمنية الموحّدة لكل الـCRM (تخزين + عرض): توقيت القدس.
   هذا الملف يُستدعى من index.php و send.php و gads-webhook.php، فالضبط هنا يكفي. */
if (!defined('CRM_TZ')) {
    define('CRM_TZ', 'Asia/Jerusalem');
    @date_default_timezone_set(CRM_TZ);
}

/* ---------------------------------------------------------------
   إعدادات ربط Facebook / Instagram (Lead Ads) — تُحفظ خارج مجلد النشر:
   { verify_token, key, app_secret, page_token }
   --------------------------------------------------------------- */
function crm_fb_cfg_file() { return crm_data_dir() . '/fb_config.json'; }

function crm_fb_cfg() {
    $f = crm_fb_cfg_file();
    if (is_file($f)) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j)) return $j;
    }
    return [];
}

function crm_fb_cfg_save($c) {
    @file_put_contents(crm_fb_cfg_file(), json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(crm_fb_cfg_file(), 0600);
}

function crm_data_dir() {
    static $dir = null;
    if ($dir !== null) return $dir;

    $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')) : '';

    $tries = [];
    if ($docroot !== '') {
        // 1) خارج جذر الموقع (يبقى بعد كل نشر) — مثال: .../younisclinic.com/younis-crm-data
        $tries[] = rtrim(str_replace('\\', '/', dirname($docroot)), '/') . '/younis-crm-data';
        // 2) بديل: مجلد محمي داخل الموقع
        $tries[] = $docroot . '/crm/data';
    }
    // 3) بديل أخير نسبةً لموقع هذا الملف
    $tries[] = str_replace('\\', '/', __DIR__) . '/data';

    foreach ($tries as $d) {
        if (is_dir($d)) { if (is_writable($d)) { $dir = $d; return $dir; } continue; }
        if (@mkdir($d, 0775, true)) { $dir = $d; return $dir; }
    }
    $dir = end($tries);
    return $dir;
}
