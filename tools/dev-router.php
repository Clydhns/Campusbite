<?php
// Local-only router for PHP's built-in server (docroot app/). Serves the same static files that
// tools/build-public.mjs would publish, live from app/, and sends everything else to api/index.php.
$path=ltrim(rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH)),'/');
$extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
$publishable=in_array($extension,['css','js','png','jpg','jpeg','webp','svg','ico','woff','woff2','webmanifest'],true)
    && (!str_contains($path,'/') || str_starts_with($path,'assets/'));
// Public meal/QR images saved by the local storage fallback; receipts stay behind payment_receipt.php.
$storage=getenv('CAMPUSBITE_LOCAL_STORAGE_DIR') ?: '';
if ($storage!=='' && preg_match('~^uploads/(foods|payments)/[a-f0-9]{32}\.(jpg|png|webp)$~D',$path) && is_file($storage.'/'.$path)) {
    header('Content-Type: '.['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$extension]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=300');
    readfile($storage.'/'.$path);
    return true;
}
$app=realpath(dirname(__DIR__).'/app');
$file=$publishable ? realpath($app.'/'.$path) : false;
if ($file!==false && is_file($file) && str_starts_with($file,$app.DIRECTORY_SEPARATOR)) {
    // PHP's built-in server has no MIME type for web app manifests.
    if ($extension!=='webmanifest') return false;
    header('Content-Type: application/manifest+json');
    readfile($file);
    return true;
}
require dirname(__DIR__).'/api/index.php';
