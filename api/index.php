<?php
$route=$_GET['__route'] ?? ltrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH),'/');
unset($_GET['__route'],$_REQUEST['__route']);
if (!is_string($route)) { http_response_code(404); exit('Not found.'); }
$route=$route==='' ? 'index.php' : $route;
$allowed=['index.php','login.php','register.php','logout.php','dashboard.php','menu.php',
    'cart.php','checkout.php','orders.php','profile.php','staff_dashboard.php','staff_menu.php',
    'staff_profile.php','admin_dashboard.php','admin_users.php','admin_stores.php',
    'admin_profile.php','payment_receipt.php','submit_payment.php'];
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: private, no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','HEAD','POST'],true)) {
    http_response_code(405); header('Allow: GET, HEAD, POST'); exit;
}
if (preg_match('~^uploads/(foods|payments)/[a-f0-9]{32}\.(jpg|png|webp)$~D',$route)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') { http_response_code(405); exit; }
    require_once dirname(__DIR__).'/app/config/cloud-storage.php';
    try {
        [$url]=cbStorageConfig();
        header('Cache-Control: public, max-age=300');
        header('Location: '.$url.'/storage/v1/object/public/campusbite-images/'.substr($route,8),302);
    } catch (Throwable $error) { http_response_code(503); }
    exit;
}
if (!in_array($route,$allowed,true)) { http_response_code(404); exit('Not found.'); }

try {
    require_once dirname(__DIR__).'/app/config/cloud-database.php';
    require_once dirname(__DIR__).'/app/config/cloud-sessions.php';
    $secret=getenv('APP_SESSION_SECRET') ?: '';
    if (strlen($secret)<64) throw new RuntimeException('Session secret is not configured.');
    session_name('campusbite_session');
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.lazy_write','0');
    ini_set('session.gc_probability','0');
    $secure=getenv('CAMPUSBITE_LOCAL_TEST')!=='1';
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_set_save_handler(new CampusBiteSessions(cbCloudDatabase(),$secret),true);
    session_start();
    if (isset($_SESSION['user_id'])) {
        $identityDatabase=cbCloudDatabase();
        $identity=$identityDatabase->prepare('SELECT u.role_id,r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND u.is_active=1');
        $identity->execute([(int)$_SESSION['user_id']]);
        $account=$identity->fetch();
        if (!$account || (int)$account['role_id']!==(int)($_SESSION['role_id'] ?? 0)) {
            $_SESSION=[]; session_destroy(); header('Location: login.php',true,302); exit;
        }
        $_SESSION['role_name']=$account['role_name'];
        unset($identity,$identityDatabase);
    }
    $_SERVER['SCRIPT_NAME']='/'.$route;
    chdir(dirname(__DIR__).'/app');
    require dirname(__DIR__).'/app/'.$route;
} catch (Throwable $error) {
    error_log('CampusBite request failed: '.$error->getMessage());
    if (!headers_sent()) { http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'CampusBite is temporarily unavailable. Please try again later.';
}
