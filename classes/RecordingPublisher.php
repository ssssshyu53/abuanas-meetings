<?php
/**
 * Abu Anas Meetings Recording Assembler & Optional Cloud Publishers (R2 / Telegram)
 */
declare(strict_types=1);

class RecordingPublisher
{
    public static function appendChunk(int $roomId, string $binData, string $mediaType = 'audio', string $ext = 'mp3'): array
    {
        $mediaType = $mediaType === 'video' ? 'video' : 'audio';
        $ext = in_array($ext, ['mp3', 'webm', 'mp4'], true) ? $ext : ($mediaType === 'video' ? 'webm' : 'mp3');
        $dir = VS_ROOT . '/uploads/recordings';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $partFile = $dir . '/room_' . $roomId . '_' . $mediaType . '.part.' . $ext;
        $curSize = is_file($partFile) ? (int)filesize($partFile) : 0;
        $maxBytes = ($mediaType === 'video' ? 300 : 120) * 1024 * 1024;
        if (($curSize + strlen($binData)) > $maxBytes) {
            return ['ok' => false, 'error' => 'تجاوز التسجيل الحد الأقصى المسموح'];
        }
        $written = file_put_contents($partFile, $binData, FILE_APPEND | LOCK_EX);
        return [
            'ok'    => $written !== false,
            'bytes' => is_file($partFile) ? (int)filesize($partFile) : 0,
        ];
    }

    public static function finalize(PDO $db, array $room, int $durationSeconds = 0, string $mediaType = 'audio', string $ext = 'mp3'): array
    {
        $roomId = (int)$room['id'];
        $mediaType = $mediaType === 'video' ? 'video' : 'audio';
        $ext = in_array($ext, ['mp3', 'webm', 'mp4'], true) ? $ext : ($mediaType === 'video' ? 'webm' : 'mp3');
        $dir = VS_ROOT . '/uploads/recordings';
        $partFile = $dir . '/room_' . $roomId . '_' . $mediaType . '.part.' . $ext;
        if (!is_file($partFile) && $mediaType === 'audio' && is_file($dir . '/room_' . $roomId . '.part.mp3')) {
            $partFile = $dir . '/room_' . $roomId . '.part.mp3';
        }
        if (!is_file($partFile) || filesize($partFile) < 256) {
            return ['ok' => true, 'saved' => false, 'media_type' => $mediaType, 'message' => 'لا توجد مقاطع مسجلة لحفظها'];
        }

        $prefix = $mediaType === 'video' ? 'abuanas_vid_' : 'abuanas_';
        $finalName = $prefix . strtolower((string)$room['code']) . '_' . date('Ymd_His') . '.' . $ext;
        $finalPath = $dir . '/' . $finalName;
        @rename($partFile, $finalPath);
        $relPath = 'uploads/recordings/' . $finalName;
        $size = (int)(filesize($finalPath) ?: 0);

        $providers = EngineRouter::getProviders($db);
        $r2Url = '';
        $tgSent = 0;

        // Optional Cloudflare R2 upload via S3 SigV4 PUT
        if (!empty($providers['r2']) && (int)$providers['r2']['enabled'] === 1 && $providers['r2']['configured']) {
            $r2Url = self::uploadToR2($providers['r2']['config'], $finalPath, 'recordings/' . $finalName);
        }

        // Optional Telegram Bot publish (audio files)
        if ($mediaType === 'audio' && !empty($providers['telegram']) && (int)$providers['telegram']['enabled'] === 1 && $providers['telegram']['configured']) {
            $tgSent = self::sendToTelegram($providers['telegram']['config'], $finalPath, (string)$room['title']) ? 1 : 0;
        }

        $st = $db->prepare('INSERT INTO recordings (room_id, title, media_type, file_path, r2_url, telegram_sent, duration_seconds, size_bytes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$roomId, (string)$room['title'], $mediaType, $relPath, $r2Url, $tgSent, max(0, $durationSeconds), $size]);

        return [
            'ok'            => true,
            'saved'         => true,
            'media_type'    => $mediaType,
            'recording_id'  => (int)$db->lastInsertId(),
            'file_url'      => SITE_URL . '/' . $relPath,
            'r2_url'        => $r2Url,
            'telegram_sent' => (bool)$tgSent,
            'size_bytes'    => $size,
        ];
    }

    private static function uploadToR2(array $cfg, string $localPath, string $objectKey): string
    {
        if (!function_exists('curl_init') || !is_file($localPath)) {
            return '';
        }
        $accountId = trim((string)($cfg['account_id'] ?? ''));
        $accessKey = trim((string)($cfg['access_key'] ?? ''));
        $secretKey = trim((string)($cfg['secret_key'] ?? ''));
        $bucket = trim((string)($cfg['bucket'] ?? ''));
        $publicUrl = rtrim(trim((string)($cfg['public_url'] ?? '')), '/');
        if ($accountId === '' || $accessKey === '' || $secretKey === '' || $bucket === '') {
            return '';
        }

        $host = "{$accountId}.r2.cloudflarestorage.com";
        $uri = '/' . $bucket . '/' . ltrim($objectKey, '/');
        $body = file_get_contents($localPath);
        if ($body === false) {
            return '';
        }

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);
        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalReq = "PUT\n{$uri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope = "{$dateStamp}/auto/s3/aws4_request";
        $strToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalReq);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', 'auto', $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $sig = hash_hmac('sha256', $strToSign, $kSigning);
        $auth = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$sig}";

        $ch = curl_init("https://{$host}{$uri}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => [
                'Host: ' . $host,
                'Content-Type: audio/mpeg',
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzDate,
                'Authorization: ' . $auth,
            ],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            return $publicUrl !== '' ? ($publicUrl . '/' . ltrim($objectKey, '/')) : "https://{$host}{$uri}";
        }
        return '';
    }

    private static function sendToTelegram(array $cfg, string $localPath, string $title): bool
    {
        if (!function_exists('curl_init') || !is_file($localPath) || filesize($localPath) > 49 * 1024 * 1024) {
            return false;
        }
        $bot = trim((string)($cfg['bot_token'] ?? ''));
        $chatId = trim((string)($cfg['chat_id'] ?? ''));
        if ($bot === '' || $chatId === '') {
            return false;
        }
        $ch = curl_init("https://api.telegram.org/bot{$bot}/sendAudio");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_POSTFIELDS     => [
                'chat_id' => $chatId,
                'title'   => $title,
                'audio'   => new CURLFile($localPath, 'audio/mpeg', basename($localPath)),
            ],
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return !empty($res['ok']);
    }
}
