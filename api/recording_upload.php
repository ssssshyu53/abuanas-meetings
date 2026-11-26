<?php
/**
 * Chunked MP3 Recording Upload & Cloud Publisher Endpoint
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

$db = Database::getInstance()->connect();
$action = trim((string)($_POST['action'] ?? 'append'));
$code = strtoupper(trim((string)($_POST['code'] ?? '')));

if (!vsVerifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    vsJson(403, ['success' => false, 'error' => 'رمز الأمان غير صالح']);
}

$room = RoomService::getByCode($db, $code);
if (!$room) {
    vsJson(404, ['success' => false, 'error' => 'الغرفة غير موجودة']);
}

$actor = Auth::resolveActor($room);
if (!$actor || !$actor['is_host']) {
    vsJson(403, ['success' => false, 'error' => 'التسجيل مخصص للمضيف فقط']);
}

// Release PHP session lock before disk / R2 / Telegram I/O
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($action === 'append') {
    if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        vsJson(422, ['success' => false, 'error' => 'ملف المقطع مفقود']);
    }
    $bin = file_get_contents($_FILES['chunk']['tmp_name']);
    if ($bin === false || $bin === '') {
        vsJson(422, ['success' => false, 'error' => 'مقطع فارغ']);
    }
    $res = RecordingPublisher::appendChunk((int)$room['id'], $bin);
    vsJson(200, array_merge(['success' => $res['ok']], $res));
}

if ($action === 'finalize') {
    $dur = max(0, (int)($_POST['duration_seconds'] ?? 0));
    $res = RecordingPublisher::finalize($db, $room, $dur);
    vsJson(200, array_merge(['success' => true], $res));
}

vsJson(400, ['success' => false, 'error' => 'إجراء غير معروف']);
