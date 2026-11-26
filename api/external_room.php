<?php
/**
 * Abu Anas Meetings — External Platform REST API (Create Meetings via X-API-KEY)
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    vsJson(405, ['success' => false, 'error' => 'Method not allowed. Use POST.']);
}

$raw = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$apiKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ($raw['api_key'] ?? '')));
if ($apiKey === '') {
    vsJson(401, ['success' => false, 'error' => 'Missing X-API-KEY header']);
}

$db = Database::getInstance()->connect();
$st = $db->prepare("
    SELECT k.*, u.role AS user_role
    FROM api_keys k
    INNER JOIN users u ON u.id = k.user_id
    WHERE k.api_key = ? AND k.is_active = 1
    LIMIT 1
");
$st->execute([$apiKey]);
$keyRow = $st->fetch();
if (!$keyRow || !in_array($keyRow['user_role'], ['admin', 'host'], true)) {
    vsJson(403, ['success' => false, 'error' => 'Invalid or inactive API key']);
}

$allowed = trim((string)($keyRow['allowed_domains'] ?? '*'));
if ($allowed !== '' && $allowed !== '*') {
    $originHost = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST) ?: '';
    $allowList = array_filter(array_map('trim', explode(',', strtolower($allowed))));
    if ($originHost !== '' && !in_array(strtolower($originHost), $allowList, true)) {
        vsJson(403, ['success' => false, 'error' => 'Origin domain not permitted']);
    }
}

$res = RoomService::createRoom($db, (int)$keyRow['user_id'], $raw);
if (!$res['ok']) {
    vsJson(422, ['success' => false, 'error' => $res['error'] ?? 'Could not create meeting']);
}

$code = (string)$res['code'];
vsJson(201, [
    'success'   => true,
    'id'        => (int)$res['id'],
    'code'      => $code,
    'join_url'  => SITE_URL . '/room.php?code=' . urlencode($code),
    'embed_url' => SITE_URL . '/room.php?code=' . urlencode($code) . '&embed=1',
]);
