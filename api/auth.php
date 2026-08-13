<?php
require_once __DIR__ . '/helpers.php';

const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

function auth_env($name, $default = '') { $value = $_SERVER[$name] ?? getenv($name); return trim((string)(($value === false || $value === '') ? $default : $value)); }
function auth_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(auth_env('SOC2_SESSION_NAME', 'soc2_admin'));
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
function auth_redirect($url) { header('Location: ' . $url, true, 302); exit; }
function auth_base_url() {
    $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    if (!in_array($proto, ['http','https'], true)) $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]);
    return $proto . '://' . $host;
}
function auth_redirect_uri() { return auth_env('SOC2_GOOGLE_REDIRECT_URI', auth_base_url() . '/api/auth.php?action=callback'); }
function auth_enabled() { return auth_env('SOC2_GOOGLE_CLIENT_ID') !== '' && auth_env('SOC2_GOOGLE_CLIENT_SECRET') !== ''; }
function auth_user() {
    auth_session_start(); $user = $_SESSION['soc2_user'] ?? null;
    if (!$user || (int)($user['expiresAt'] ?? 0) < time()) return null;
    return $user;
}
function auth_http_json($url, $post = null) {
    $ch = curl_init($url); $options = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Accept: application/json']];
    if ($post !== null) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $options); $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) throw new RuntimeException($error ?: 'Google authentication request failed');
    $json = json_decode($body, true); if (!is_array($json)) throw new RuntimeException('Invalid Google authentication response'); return $json;
}

$action = $_GET['action'] ?? 'status';
if ($action === 'check') { if (auth_user()) { http_response_code(204); exit; } http_response_code(401); exit; }
if ($action === 'status') { $user = auth_user(); json_response(['authenticated'=>(bool)$user,'user'=>$user]); }
if ($action === 'login') {
    if (!auth_enabled()) error_response('Google OAuth is not configured', 503);
    auth_session_start(); $state=bin2hex(random_bytes(24)); $nonce=bin2hex(random_bytes(24)); $_SESSION['oauth_state']=$state; $_SESSION['oauth_nonce']=$nonce; $_SESSION['oauth_started']=time();
    $query=['client_id'=>auth_env('SOC2_GOOGLE_CLIENT_ID'),'redirect_uri'=>auth_redirect_uri(),'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'nonce'=>$nonce,'access_type'=>'online','prompt'=>'select_account'];
    $domain=auth_env('SOC2_GOOGLE_HOSTED_DOMAIN','accessrrs.com'); if ($domain) $query['hd']=$domain;
    auth_redirect(GOOGLE_AUTH_URL . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
}
if ($action === 'callback') {
    auth_session_start(); $state=(string)($_GET['state']??''); $expected=(string)($_SESSION['oauth_state']??'');
    if (!$state || !$expected || !hash_equals($expected,$state) || time()-(int)($_SESSION['oauth_started']??0)>600) auth_redirect('/?auth_error=invalid_state');
    try {
        $token=auth_http_json(GOOGLE_TOKEN_URL,['code'=>(string)($_GET['code']??''),'client_id'=>auth_env('SOC2_GOOGLE_CLIENT_ID'),'client_secret'=>auth_env('SOC2_GOOGLE_CLIENT_SECRET'),'redirect_uri'=>auth_redirect_uri(),'grant_type'=>'authorization_code']);
        $claims=auth_http_json(GOOGLE_TOKENINFO_URL . '?id_token=' . rawurlencode((string)($token['id_token']??'')));
        if (($claims['aud']??'')!==auth_env('SOC2_GOOGLE_CLIENT_ID') || !in_array($claims['iss']??'', ['accounts.google.com','https://accounts.google.com'], true) || (int)($claims['exp']??0)<time() || ($claims['nonce']??'')!==($_SESSION['oauth_nonce']??'') || !in_array(strtolower((string)($claims['email_verified']??'')), ['true','1'], true)) throw new RuntimeException('Google identity validation failed');
        $email=strtolower(trim((string)($claims['email']??''))); $domain=auth_env('SOC2_GOOGLE_HOSTED_DOMAIN','accessrrs.com');
        if ($domain && strtolower((string)($claims['hd']??''))!==strtolower($domain)) throw new RuntimeException('Google Workspace domain is not authorized');
        $allowed=array_filter(array_map('trim',explode(',',strtolower(auth_env('SOC2_ALLOWED_EMAILS'))))); if ($allowed && !in_array($email,$allowed,true)) throw new RuntimeException('Account is not authorized');
        $_SESSION['soc2_user']=['email'=>$email,'name'=>(string)($claims['name']??''),'picture'=>(string)($claims['picture']??''),'authenticatedAt'=>gmdate(DATE_ATOM),'expiresAt'=>time()+max(300,(int)auth_env('SOC2_SESSION_TTL_SECONDS','28800'))];
        unset($_SESSION['oauth_state'],$_SESSION['oauth_nonce'],$_SESSION['oauth_started']); session_regenerate_id(true); auth_redirect('/');
    } catch (Throwable $e) { error_log('SOC2 OAuth callback failed: '.$e->getMessage()); auth_redirect('/?auth_error=oauth_failed'); }
}
if ($action === 'logout') { auth_session_start(); $_SESSION=[]; session_destroy(); auth_redirect('/api/auth.php?action=login'); }
error_response('Unknown authentication action', 404);
