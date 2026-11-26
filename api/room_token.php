<?php
/**
 * Room Token & Multi-Engine Resolver API
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

$db = Database::getInstance()->connect();
$code = strtoupper(trim((string)($_REQUEST['code'] ?? '')));
if ($code === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $code = strtoupper(trim((string)($raw['code'] ?? '')));
}

$room = RoomService::getByCode($db, $code);
if (!$room) {
    vsJson(404, ['success' => false, 'error' => 'الغرفة غير موجودة']);
}
if (($room['status'] ?? '') === 'ended') {
    vsJson(410, ['success' => false, 'error' => 'انتهى البث في هذه الغرفة']);
}

$actor = Auth::resolveActor($room);
if (!$actor) {
    vsJson(401, ['success' => false, 'error' => 'يرجى تسجيل الدخول أو إدخال اسم الضيف للمشاركة']);
}

RoomService::heartbeat($db, (int)$room['id'], $actor);
$resolved = EngineRouter::resolveForRoom($db, $room, $actor);
vsJson(200, $resolved);
