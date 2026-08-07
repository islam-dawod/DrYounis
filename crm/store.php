<?php
/* =====================================================================
   crm/store.php — يحدّد مجلد تخزين بيانات الـCRM بحيث:
   1) يكون خارج مجلد النشر (public_html) فلا يُمسح مع كل نشر تلقائي من Git.
   2) لا يكون متاحاً من الويب إطلاقاً (أعلى من جذر الموقع).
   إن تعذّر ذلك (open_basedir)، يعود لمجلد محمي داخل الموقع كحل بديل.
   ===================================================================== */

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
