<?php
/**
 * Room State Polling, Chat, Pinning, Polls, Slides & Lifecycle API
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

$db = Database::getInstance()->connect();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: $_POST) : $_GET;

$action = trim((string)($body['action'] ?? 'poll_state'));
$code = strtoupper(trim((string)($body['code'] ?? '')));
$room = RoomService::getByCode($db, $code);
if (!$room) {
    vsJson(404, ['success' => false, 'error' => 'الغرفة غير موجودة']);
}

$actor = Auth::resolveActor($room);
if (!$actor) {
    vsJson(401, ['success' => false, 'error' => 'غير مصرح بالدخول']);
}
if (!Auth::hasRoomPasscodeAccess($room, $actor)) {
    vsJson(403, ['success' => false, 'error' => 'يلزم إدخال رمز الدخول للغرفة']);
}

$roomId = (int)$room['id'];
if ($method === 'POST' && !vsVerifyCsrf((string)($body['csrf_token'] ?? ''))) {
    vsJson(403, ['success' => false, 'error' => 'انتهت صلاحية جلسة الأمان، أعد تحديث الصفحة']);
}

RoomService::heartbeat($db, $roomId, $actor);

if ($action === 'poll_state') {
    $afterId = max(0, (int)($body['last_msg_id'] ?? 0));
    $messages = RoomService::getMessages($db, $roomId, $afterId);
    $pinned = RoomService::getPinnedMessage($db, $roomId);
    $polls = RoomService::getPolls($db, $roomId, $actor['user_key']);
    $slides = json_decode((string)($room['slides_json'] ?? '[]'), true) ?: [];
    vsJson(200, [
        'success'     => true,
        'room_status' => (string)$room['status'],
        'room_title'  => (string)$room['title'],
        'messages'    => $messages,
        'pinned'      => $pinned,
        'polls'       => $polls,
        'slides'      => $slides,
    ]);
}

if ($action === 'send_message') {
    $txt = mb_substr(trim((string)($body['body'] ?? '')), 0, 500);
    if ($txt === '') {
        vsJson(422, ['success' => false, 'error' => 'الرسالة فارغة']);
    }
    $isQ = !empty($body['is_question']) ? 1 : 0;
    $st = $db->prepare('INSERT INTO room_messages (room_id, user_key, sender_name, sender_role, body, is_question) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$roomId, $actor['user_key'], $actor['name'], $actor['role'], $txt, $isQ]);
    vsJson(200, ['success' => true, 'id' => (int)$db->lastInsertId()]);
}

if ($action === 'pin_message') {
    if (!$actor['is_host']) vsJson(403, ['success' => false, 'error' => 'للمضيف فقط']);
    $msgId = (int)($body['message_id'] ?? 0);
    $db->prepare('UPDATE room_messages SET is_pinned = 0 WHERE room_id = ?')->execute([$roomId]);
    if ($msgId > 0) {
        $db->prepare('UPDATE room_messages SET is_pinned = 1 WHERE id = ? AND room_id = ?')->execute([$msgId, $roomId]);
    }
    vsJson(200, ['success' => true]);
}

if ($action === 'unpin_message') {
    if (!$actor['is_host']) vsJson(403, ['success' => false, 'error' => 'للمضيف فقط']);
    $db->prepare('UPDATE room_messages SET is_pinned = 0 WHERE room_id = ?')->execute([$roomId]);
    vsJson(200, ['success' => true]);
}

if ($action === 'create_poll') {
    if (!$actor['is_host']) vsJson(403, ['success' => false, 'error' => 'للمضيف فقط']);
    $q = mb_substr(trim((string)($body['question'] ?? '')), 0, 240);
    $rawOpts = is_array($body['options'] ?? null) ? $body['options'] : [];
    $opts = [];
    foreach ($rawOpts as $o) {
        $t = mb_substr(trim((string)$o), 0, 100);
        if ($t !== '') $opts[] = $t;
    }
    $opts = array_slice($opts, 0, 5);
    if ($q === '' || count($opts) < 2) {
        vsJson(422, ['success' => false, 'error' => 'أدخل سؤالاً وخيارين على الأقل']);
    }
    $st = $db->prepare('INSERT INTO room_polls (room_id, question, options_json) VALUES (?, ?, ?)');
    $st->execute([$roomId, $q, json_encode($opts, JSON_UNESCAPED_UNICODE)]);
    vsJson(200, ['success' => true, 'poll_id' => (int)$db->lastInsertId()]);
}

if ($action === 'vote_poll') {
    $pollId = (int)($body['poll_id'] ?? 0);
    $optIdx = max(0, (int)($body['option_index'] ?? 0));
    $st = $db->prepare('SELECT id, is_closed FROM room_polls WHERE id = ? AND room_id = ? LIMIT 1');
    $st->execute([$pollId, $roomId]);
    $p = $st->fetch();
    if (!$p || (int)$p['is_closed'] === 1) {
        vsJson(400, ['success' => false, 'error' => 'التصويت مغلق أو غير موجود']);
    }
    $vSql = Database::isSqlite()
        ? 'INSERT INTO room_poll_votes (poll_id, user_key, option_index) VALUES (?, ?, ?) ON CONFLICT(poll_id, user_key) DO UPDATE SET option_index = excluded.option_index'
        : 'INSERT INTO room_poll_votes (poll_id, user_key, option_index) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE option_index = VALUES(option_index)';
    $vSt = $db->prepare($vSql);
    $vSt->execute([$pollId, $actor['user_key'], $optIdx]);
    vsJson(200, ['success' => true, 'polls' => RoomService::getPolls($db, $roomId, $actor['user_key'])]);
}

if ($action === 'close_poll') {
    if (!$actor['is_host']) vsJson(403, ['success' => false, 'error' => 'للمضيف فقط']);
    $pollId = (int)($body['poll_id'] ?? 0);
    $db->prepare('UPDATE room_polls SET is_closed = 1 WHERE id = ? AND room_id = ?')->execute([$pollId, $roomId]);
    vsJson(200, ['success' => true]);
}

if ($action === 'update_title') {
    if (!$actor['is_host']) vsJson(403, ['success' => false, 'error' => 'للمضيف فقط']);
    $newTitle = mb_substr(trim((string)($body['title'] ?? '')), 0, 180);
    if (mb_strlen($newTitle) < 2) vsJson(422, ['success' => false, 'error' => 'العنوان قصير جداً']);
    $db->prepare('UPDATE rooms SET title = ? WHERE id = ?')->execute([$newTitle, $roomId]);
    vsJson(200, ['success' => true, 'title' => $newTitle]);
}

if ($action === 'upload_slide') {
    if (!$actor['is_host']) vsJson(403, ['success' => false, 'error' => 'للمضيف فقط']);
    if (empty($_FILES['slide']) || $_FILES['slide']['error'] !== UPLOAD_ERR_OK) {
        vsJson(422, ['success' => false, 'error' => 'لم يتم رفع الصورة بنجاح']);
    }
    if ((int)($_FILES['slide']['size'] ?? 0) > 5 * 1024 * 1024) {
        vsJson(422, ['success' => false, 'error' => 'الحد الأقصى لحجم الشريحة هو 5 ميجابايت']);
    }
    $ext = strtolower(pathinfo((string)$_FILES['slide']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || @getimagesize((string)$_FILES['slide']['tmp_name']) === false) {
        vsJson(422, ['success' => false, 'error' => 'يسمح فقط بصور PNG / JPG / WEBP صالحة للشرائح']);
    }
    $dir = VS_ROOT . '/uploads/slides';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = 'slide_' . $roomId . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['slide']['tmp_name'], $dir . '/' . $fname)) {
        vsJson(500, ['success' => false, 'error' => 'تعذر حفظ الشريحة']);
    }
    $slides = json_decode((string)($room['slides_json'] ?? '[]'), true) ?: [];
    $slides[] = SITE_URL . '/uploads/slides/' . $fname;
    $db->prepare('UPDATE rooms SET slides_json = ? WHERE id = ?')->execute([json_encode($slides, JSON_UNESCAPED_SLASHES), $roomId]);
    vsJson(200, ['success' => true, 'slides' => $slides, 'slideIndex' => count($slides) - 1]);
}

if ($action === 'record_video_minutes') {
    if (!$actor['is_host']) vsJson(403, ['success' => false, 'error' => 'للمضيف فقط']);
    $eng = trim((string)($room['active_engine'] ?? ''));
    $mins = max(0.0, min(60.0, (float)($body['minutes'] ?? 0)));
    if (in_array($eng, ['hms', 'daily', 'livekit'], true) && $mins > 0) {
        EngineRouter::recordUsage($db, $eng, $mins);
    }
    vsJson(200, ['success' => true]);
}

if ($action === 'end_room') {
    if (!$actor['is_host']) vsJson(403, ['success' => false, 'error' => 'للمضيف فقط']);
    $db->prepare("UPDATE rooms SET status = 'ended', ended_at = ? WHERE id = ?")->execute([Database::now(), $roomId]);
    vsJson(200, ['success' => true]);
}

vsJson(400, ['success' => false, 'error' => 'إجراء غير معروف']);
