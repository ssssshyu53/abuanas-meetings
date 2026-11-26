<?php
/**
 * Cloudflare Realtime Calls SFU Server-Side Proxy
 * Keeps CF_SFU_APP_SECRET strictly on the server while brokering WebRTC SDP/tracks.
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

$db = Database::getInstance()->connect();
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];

if (!vsVerifyCsrf((string)($body['csrf_token'] ?? ''))) {
    vsJson(403, ['success' => false, 'error' => 'CSRF invalid']);
}

$code = strtoupper(trim((string)($body['code'] ?? '')));
$room = RoomService::getByCode($db, $code);
if (!$room) {
    vsJson(404, ['success' => false, 'error' => 'Room not found']);
}
$actor = Auth::resolveActor($room);
if (!$actor) {
    vsJson(401, ['success' => false, 'error' => 'Unauthorized']);
}

$providers = EngineRouter::getProviders($db);
$sfu = $providers['cf_sfu']['config'] ?? [];
$appId = trim((string)($sfu['app_id'] ?? ''));
$appSecret = trim((string)($sfu['app_secret'] ?? ''));
if ($appId === '' || $appSecret === '' || !function_exists('curl_init')) {
    vsJson(400, ['success' => false, 'error' => 'Cloudflare Realtime SFU غير مُعد']);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$op = trim((string)($body['op'] ?? ''));
$sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($body['sessionId'] ?? ''));
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

$base = "https://rtc.live.cloudflare.com/v1/apps/{$appId}";
$method = 'POST';
$targetUrl = match ($op) {
    'sessions_new' => "{$base}/sessions/new",
    'tracks_new'   => "{$base}/sessions/{$sessionId}/tracks/new",
    'renegotiate'  => (static function () use (&$method, $base, $sessionId) { $method = 'PUT'; return "{$base}/sessions/{$sessionId}/renegotiate"; })(),
    default        => '',
};

if ($targetUrl === '') {
    vsJson(400, ['success' => false, 'error' => 'Invalid SFU operation']);
}

$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $appSecret,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
]);
$resp = (string)curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$decoded = json_decode($resp, true);
if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded)) {
    vsJson(200, ['success' => true, 'data' => $decoded]);
}
vsJson(502, ['success' => false, 'error' => $err ?: ("SFU HTTP {$httpCode}"), 'raw' => $decoded]);
