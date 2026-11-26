<?php
/**
 * Abu Anas Meetings Room, Chat, Pinning & Live Polls Service
 */
declare(strict_types=1);

class RoomService
{
    public static function presetLabels(): array
    {
        return [
            'space'        => ['title' => 'مساحة صوتية / بودكاست', 'icon' => 'fa-podcast', 'desc' => 'بث صوتي مباشر مع مداخلات الجمهور وتفاعل الأسئلة'],
            'classroom'    => ['title' => 'فصل تفاعلي / ويبينار', 'icon' => 'fa-chalkboard', 'desc' => 'صوت/فيديو مع سبورة تفاعلية وعرض شرائح متزامن'],
            'meeting'      => ['title' => 'اجتماع فريق عمل', 'icon' => 'fa-users', 'desc' => 'تعاون مفتوح للفريق مع تصويت فوري وتسجيل تلقائي'],
            'consultation' => ['title' => 'جلسة خاصة (1 على 1)', 'icon' => 'fa-user-friends', 'desc' => 'جلسة استشارة أو تدريب خاصة برمز دعوة ومؤقت'],
        ];
    }

    public static function generateUniqueCode(PDO $db): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($t = 0; $t < 15; $t++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $st = $db->prepare('SELECT id FROM rooms WHERE code = ? LIMIT 1');
            $st->execute([$code]);
            if (!$st->fetchColumn()) {
                return $code;
            }
        }
        return strtoupper(bin2hex(random_bytes(3)));
    }

    public static function createRoom(PDO $db, int $hostUserId, array $input): array
    {
        $title = mb_substr(trim((string)($input['title'] ?? '')), 0, 180);
        if ($title === '') {
            return ['ok' => false, 'error' => 'يرجى إدخال عنوان الغرفة'];
        }
        $desc = mb_substr(trim((string)($input['description'] ?? '')), 0, 480);
        $rawPreset = (string)($input['preset'] ?? 'space');
        $preset = in_array($rawPreset, ['space', 'classroom', 'meeting', 'consultation'], true) ? $rawPreset : 'space';
        $mediaMode = ($input['media_mode'] ?? 'audio') === 'video' ? 'video' : 'audio';
        $rawForced = (string)($input['forced_engine'] ?? 'auto');
        $forced = in_array($rawForced, ['auto', 'cf_ws', 'node_relay', 'cf_sfu', 'hms', 'daily', 'livekit'], true) ? $rawForced : 'auto';
        $rawAccess = (string)($input['access_mode'] ?? 'public');
        $accessMode = in_array($rawAccess, ['public', 'code', 'registered'], true) ? $rawAccess : 'public';
        $allowAll = !empty($input['allow_all_mic']) || $preset === 'meeting' ? 1 : 0;
        $wbEnabled = isset($input['whiteboard_enabled']) ? (!empty($input['whiteboard_enabled']) ? 1 : 0) : ($preset === 'classroom' ? 1 : 0);

        $code = self::generateUniqueCode($db);
        $now = Database::now();
        $st = $db->prepare("
            INSERT INTO rooms (code, title, description, preset, media_mode, forced_engine, access_mode, status, allow_all_mic, whiteboard_enabled, host_user_id, started_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'live', ?, ?, ?, ?)
        ");
        $st->execute([$code, $title, $desc, $preset, $mediaMode, $forced, $accessMode, $allowAll, $wbEnabled, $hostUserId, $now]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId(), 'code' => $code];
    }

    public static function getByCode(PDO $db, string $code): ?array
    {
        $clean = strtoupper(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($code)));
        if ($clean === '') {
            return null;
        }
        $st = $db->prepare('SELECT r.*, u.name AS host_name FROM rooms r LEFT JOIN users u ON u.id = r.host_user_id WHERE r.code = ? LIMIT 1');
        $st->execute([$clean]);
        return $st->fetch() ?: null;
    }

    public static function heartbeat(PDO $db, int $roomId, array $actor): void
    {
        $role = $actor['is_host'] ? 'host' : 'listener';
        $now = Database::now();
        $sql = Database::isSqlite()
            ? 'INSERT INTO room_participants (room_id, user_key, display_name, role, joined_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(room_id, user_key) DO UPDATE SET display_name = excluded.display_name, role = excluded.role, last_seen_at = excluded.last_seen_at'
            : 'INSERT INTO room_participants (room_id, user_key, display_name, role, joined_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), role = VALUES(role), last_seen_at = VALUES(last_seen_at)';
        $st = $db->prepare($sql);
        $st->execute([$roomId, $actor['user_key'], $actor['name'], $role, $now, $now]);
    }

    public static function getMessages(PDO $db, int $roomId, int $afterId = 0): array
    {
        if ($afterId > 0) {
            $st = $db->prepare('SELECT * FROM room_messages WHERE room_id = ? AND id > ? ORDER BY id ASC LIMIT 80');
            $st->execute([$roomId, $afterId]);
        } else {
            $st = $db->prepare('SELECT * FROM room_messages WHERE room_id = ? ORDER BY id DESC LIMIT 60');
            $st->execute([$roomId]);
            return array_reverse($st->fetchAll());
        }
        return $st->fetchAll();
    }

    public static function getPinnedMessage(PDO $db, int $roomId): ?array
    {
        $st = $db->prepare('SELECT * FROM room_messages WHERE room_id = ? AND is_pinned = 1 ORDER BY id DESC LIMIT 1');
        $st->execute([$roomId]);
        return $st->fetch() ?: null;
    }

    public static function getPolls(PDO $db, int $roomId, string $userKey): array
    {
        $st = $db->prepare('SELECT * FROM room_polls WHERE room_id = ? ORDER BY id DESC LIMIT 5');
        $st->execute([$roomId]);
        $polls = $st->fetchAll();
        foreach ($polls as &$p) {
            $opts = json_decode((string)$p['options_json'], true) ?: [];
            $vSt = $db->prepare('SELECT option_index, COUNT(*) AS cnt FROM room_poll_votes WHERE poll_id = ? GROUP BY option_index');
            $vSt->execute([(int)$p['id']]);
            $counts = [];
            $total = 0;
            foreach ($vSt->fetchAll() as $vr) {
                $counts[(int)$vr['option_index']] = (int)$vr['cnt'];
                $total += (int)$vr['cnt'];
            }
            $mySt = $db->prepare('SELECT option_index FROM room_poll_votes WHERE poll_id = ? AND user_key = ? LIMIT 1');
            $mySt->execute([(int)$p['id'], $userKey]);
            $myVote = $mySt->fetchColumn();
            $p['options'] = [];
            foreach ($opts as $idx => $txt) {
                $c = $counts[$idx] ?? 0;
                $p['options'][] = [
                    'index' => $idx,
                    'label' => $txt,
                    'votes' => $c,
                    'pct'   => $total > 0 ? (int)round(($c / $total) * 100) : 0,
                ];
            }
            $p['total_votes'] = $total;
            $p['my_vote'] = $myVote !== false ? (int)$myVote : null;
            unset($p['options_json']);
        }
        unset($p);
        return $polls;
    }
}
