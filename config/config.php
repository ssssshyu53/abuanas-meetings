<?php
/**
 * Abu Anas Meetings — Central Configuration & Security Helpers
 */
declare(strict_types=1);

date_default_timezone_set('Africa/Cairo');

define('VS_ROOT', dirname(__DIR__));

// Load .env into memory once without leaking to superglobals unnecessarily
(static function (): void {
    $envPath = VS_ROOT . DIRECTORY_SEPARATOR . '.env';
    $vars = [];
    if (is_file($envPath) && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }
            $eq = strpos($trim, '=');
            if ($eq === false) {
                continue;
            }
            $k = trim(substr($trim, 0, $eq));
            $v = trim(substr($trim, $eq + 1));
            if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '') {
                $vars[$k] = $v;
            }
        }
    }
    $GLOBALS['_VS_ENV'] = $vars;
})();

function vsEnv(string $key, string $default = ''): string
{
    if (isset($GLOBALS['_VS_ENV'][$key]) && $GLOBALS['_VS_ENV'][$key] !== '') {
        return (string)$GLOBALS['_VS_ENV'][$key];
    }
    $g = getenv($key);
    if ($g !== false && $g !== '') {
        return (string)$g;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    return $default;
}

// Compute safe SITE_URL automatically
(static function (): void {
    $configured = rtrim(vsEnv('SITE_URL', ''), '/');
    if ($configured !== '') {
        define('SITE_URL', $configured);
        return;
    }
    $rawHost = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $safeHost = preg_match('/^[a-z0-9.\-:\[\]]+$/i', $rawHost) ? $rawHost : 'localhost';
    $isHttps = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    $scheme = $isHttps ? 'https' : 'http';

    $docRoot = str_replace('\\', '/', realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');
    $appRoot = str_replace('\\', '/', realpath(VS_ROOT) ?: '');
    $subPath = '';
    if ($docRoot !== '' && $appRoot !== '' && str_starts_with(strtolower($appRoot), strtolower($docRoot))) {
        $subPath = rtrim(substr($appRoot, strlen($docRoot)), '/');
    } elseif (stripos($appRoot, '/htdocs/abuanas-meetings') !== false) {
        $subPath = '/abuanas-meetings';
    }
    define('SITE_URL', $scheme . '://' . $safeHost . $subPath);
})();

define('DB_DRIVER', strtolower(vsEnv('DB_DRIVER', 'mysql')));
define('DB_SQLITE_PATH', vsEnv('DB_SQLITE_PATH', VS_ROOT . '/database/abuanas.sqlite'));
define('DB_HOST', vsEnv('DB_HOST', '127.0.0.1'));
define('DB_NAME', vsEnv('DB_NAME', 'abuanas_meetings'));
define('DB_USER', vsEnv('DB_USER', 'root'));
define('DB_PASS', vsEnv('DB_PASS', ''));

function vsBootSession(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    session_name('ABUANAS_MEET_SESS');
    session_set_cookie_params([
        'lifetime' => 2592000,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => $isHttps ? 'None' : 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    @session_start();
    if (empty($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    }
}

vsBootSession();

function vsEsc(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function vsCsrfToken(): string
{
    if (empty($_SESSION['_vs_csrf']) || !is_string($_SESSION['_vs_csrf']) || strlen($_SESSION['_vs_csrf']) < 32) {
        $_SESSION['_vs_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_vs_csrf'];
}

function vsVerifyCsrf(?string $candidate = null): bool
{
    $tok = $candidate ?? (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
    $sess = (string)($_SESSION['_vs_csrf'] ?? '');
    if ($tok === '' || $sess === '') {
        return false;
    }
    return hash_equals($sess, trim($tok));
}

function vsBase64UrlEncode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function vsGenerateJwt(array $claims, string $secret, int $ttlSeconds = 14400): string
{
    $now = time();
    $payload = array_merge($claims, [
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $claims['exp'] ?? ($now + $ttlSeconds),
    ]);
    $h = vsBase64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $p = vsBase64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $sig = vsBase64UrlEncode(hash_hmac('sha256', "$h.$p", $secret, true));
    return "$h.$p.$sig";
}

function vsJwtSecret(): string
{
    $s = trim(vsEnv('JWT_SECRET', ''));
    if ($s === '') {
        $s = hash('sha256', VS_ROOT . '|abuanas-meetings-dev-secret-fallback');
    }
    return $s;
}

function vsJson(int $statusCode, array $data): never
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vsRedirect(string $url): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    }
    exit;
}

require_once __DIR__ . '/database.php';
require_once VS_ROOT . '/classes/Auth.php';
require_once VS_ROOT . '/classes/EngineRouter.php';
require_once VS_ROOT . '/classes/RoomService.php';
require_once VS_ROOT . '/classes/RecordingPublisher.php';
