<?php
/**
 * Abu Anas Meetings — Smart Multi-Engine Router
 */
declare(strict_types=1);

class EngineRouter
{
    public static function getProviders(PDO $db): array
    {
        $rows = $db->query('SELECT * FROM provider_configs ORDER BY category ASC, priority ASC')->fetchAll();
        $out = [];
        $today = date('Y-m-d');
        $month = date('Y-m');
        foreach ($rows as $row) {
            $expectedPeriod = ($row['limit_unit'] === 'requests_day') ? $today : $month;
            if ($row['period_key'] !== $expectedPeriod) {
                $upd = $db->prepare('UPDATE provider_configs SET used_units = 0, period_key = ? WHERE provider_key = ?');
                $upd->execute([$expectedPeriod, $row['provider_key']]);
                $row['used_units'] = 0;
                $row['period_key'] = $expectedPeriod;
            }
            $row['config'] = json_decode((string)($row['config_json'] ?? '{}'), true) ?: [];
            $row['configured'] = self::isProviderConfigured($row['provider_key'], $row['config']);
            $out[$row['provider_key']] = $row;
        }
        return $out;
    }

    public static function isProviderConfigured(string $key, array $cfg): bool
    {
        return match ($key) {
            'cf_ws', 'node_relay' => trim((string)($cfg['ws_url'] ?? '')) !== '',
            'cf_sfu'   => trim((string)($cfg['app_id'] ?? '')) !== '' && trim((string)($cfg['app_secret'] ?? '')) !== '',
            'hms'      => trim((string)($cfg['access_key'] ?? '')) !== '' && trim((string)($cfg['app_secret'] ?? '')) !== '',
            'daily'    => trim((string)($cfg['api_key'] ?? '')) !== '',
            'livekit'  => trim((string)($cfg['api_key'] ?? '')) !== '' && trim((string)($cfg['api_secret'] ?? '')) !== '' && trim((string)($cfg['ws_url'] ?? '')) !== '',
            'r2'       => trim((string)($cfg['account_id'] ?? '')) !== '' && trim((string)($cfg['access_key'] ?? '')) !== '' && trim((string)($cfg['secret_key'] ?? '')) !== '' && trim((string)($cfg['bucket'] ?? '')) !== '',
            'telegram' => trim((string)($cfg['bot_token'] ?? '')) !== '' && trim((string)($cfg['chat_id'] ?? '')) !== '',
            default    => false,
        };
    }

    public static function wsUrlToHttp(string $wsUrl): string
    {
        $http = preg_replace('#^wss://#i', 'https://', trim($wsUrl));
        $http = preg_replace('#^ws://#i', 'http://', (string)$http);
        return rtrim((string)$http, '/');
    }

    public static function audioFailoverUrls(PDO $db): array
    {
        $providers = self::getProviders($db);
        $wsUrls = [];
        $httpUrls = [];
        $audioKeys = ['cf_ws', 'node_relay'];
        usort($audioKeys, static fn($a, $b) => ($providers[$a]['priority'] ?? 99) <=> ($providers[$b]['priority'] ?? 99));

        foreach ($audioKeys as $k) {
            $p = $providers[$k] ?? null;
            if (!$p || !(int)$p['enabled'] || !$p['configured']) {
                continue;
            }
            $limit = (float)$p['monthly_limit'];
            $used = (float)$p['used_units'];
            if ($k === 'cf_ws' && $limit > 0 && ($used / $limit) >= 0.96) {
                continue;
            }
            $u = rtrim(trim((string)$p['config']['ws_url']), '/');
            if ($u !== '' && !in_array($u, $wsUrls, true)) {
                $wsUrls[] = $u;
                $httpUrls[] = self::wsUrlToHttp($u);
            }
        }
        return ['ws_urls' => $wsUrls, 'http_urls' => $httpUrls, 'providers' => $providers];
    }

    public static function resolveForRoom(PDO $db, array $room, array $actor): array
    {
        $audioPool = self::audioFailoverUrls($db);
        $providers = $audioPool['providers'];
        $mediaMode = ($room['media_mode'] ?? 'audio') === 'video' ? 'video' : 'audio';
        $forced = trim((string)($room['forced_engine'] ?? 'auto'));
        $locked = trim((string)($room['active_engine'] ?? ''));

        $chosenEngine = $locked;
        $fallbackReason = '';

        if ($chosenEngine === '') {
            if ($mediaMode === 'audio') {
                if ($forced !== 'auto' && isset($providers[$forced]) && $providers[$forced]['category'] === 'audio' && $providers[$forced]['configured']) {
                    $chosenEngine = $forced;
                } else {
                    $chosenEngine = !empty($audioPool['ws_urls']) && isset($providers['cf_ws']) && (int)$providers['cf_ws']['enabled'] && $providers['cf_ws']['configured']
                        && ($providers['cf_ws']['monthly_limit'] <= 0 || ($providers['cf_ws']['used_units'] / $providers['cf_ws']['monthly_limit']) < 0.96)
                        ? 'cf_ws'
                        : 'node_relay';
                }
            } else {
                // Video pool selection
                if ($forced !== 'auto' && isset($providers[$forced]) && (int)$providers[$forced]['enabled'] && $providers[$forced]['configured']) {
                    $chosenEngine = $forced;
                } else {
                    $videoCandidates = array_filter($providers, static fn($p) => $p['category'] === 'video' && (int)$p['enabled'] === 1 && $p['configured']);
                    usort($videoCandidates, static fn($a, $b) => ((int)$a['priority']) <=> ((int)$b['priority']));
                    foreach ($videoCandidates as $cand) {
                        $lim = (float)$cand['monthly_limit'];
                        $used = (float)$cand['used_units'];
                        if ($lim <= 0 || ($used / $lim) < 0.92) {
                            $chosenEngine = $cand['provider_key'];
                            break;
                        }
                    }
                    if ($chosenEngine === '') {
                        // Graceful downgrade to audio + whiteboard when no video provider keys are configured yet
                        $chosenEngine = !empty($audioPool['ws_urls']) && isset($providers['cf_ws']) && $providers['cf_ws']['configured'] ? 'cf_ws' : 'node_relay';
                        $fallbackReason = 'تم بدء الاجتماع بوضع الصوت والسبورة التفاعلية.';
                    }
                }
            }

            if ($chosenEngine !== '' && ($room['status'] ?? '') === 'live') {
                $db->prepare('UPDATE rooms SET active_engine = ? WHERE id = ? AND (active_engine IS NULL OR active_engine = "")')->execute([$chosenEngine, (int)$room['id']]);
            }
        }

        $roomIdStr = 'aam-' . (string)$room['code'];
        $wsToken = vsGenerateJwt([
            'roomId' => $roomIdStr,
            'userId' => $actor['user_key'],
            'name'   => $actor['name'],
            'role'   => $actor['is_host'] ? 'host' : 'student',
            'probe'  => false,
        ], vsJwtSecret(), 14400);

        $wsUrls = $audioPool['ws_urls'];
        if ($chosenEngine === 'node_relay' && !empty($providers['node_relay']['config']['ws_url'])) {
            $nr = rtrim((string)$providers['node_relay']['config']['ws_url'], '/');
            $wsUrls = array_values(array_unique(array_merge([$nr], $wsUrls)));
        }

        $res = [
            'success'        => true,
            'engine'         => $chosenEngine,
            'media_mode'     => in_array($chosenEngine, ['cf_sfu', 'hms', 'daily', 'livekit'], true) ? 'video' : 'audio',
            'room_code'      => (string)$room['code'],
            'ws_room_id'     => $roomIdStr,
            'ws_urls'        => $wsUrls,
            'ws_token'       => $wsToken,
            'role'           => $actor['is_host'] ? 'host' : 'student',
            'fallbackReason' => $fallbackReason,
            'video'          => null,
        ];

        // Provision video engine credentials on demand
        if ($chosenEngine === 'livekit' && !empty($providers['livekit'])) {
            $lkCfg = $providers['livekit']['config'];
            $res['video'] = [
                'provider' => 'livekit',
                'wsUrl'    => rtrim((string)$lkCfg['ws_url'], '/'),
                'token'    => self::issueLiveKitToken($lkCfg, $roomIdStr, $actor),
            ];
        } elseif ($chosenEngine === 'hms' && !empty($providers['hms'])) {
            $hmsRes = self::create100msSession($providers['hms']['config'], $roomIdStr, $actor);
            if ($hmsRes['ok']) {
                $res['video'] = $hmsRes;
            } else {
                $res['engine'] = !empty($audioPool['ws_urls']) && isset($providers['cf_ws']) && $providers['cf_ws']['configured'] ? 'cf_ws' : 'node_relay';
                $res['media_mode'] = 'audio';
                $res['fallbackReason'] = 'تم التحويل للبث الصوتي والسبورة تلقائياً.';
            }
        } elseif ($chosenEngine === 'daily' && !empty($providers['daily'])) {
            $dailyRes = self::createDailySession($providers['daily']['config'], $roomIdStr, $actor);
            if ($dailyRes['ok']) {
                $res['video'] = $dailyRes;
            } else {
                $res['engine'] = 'node_relay';
                $res['media_mode'] = 'audio';
                $res['fallbackReason'] = 'تعذر إنشاء غرفة Daily (' . ($dailyRes['error'] ?? '') . ')؛ تم التبديل للبث الصوتي.';
            }
        } elseif ($chosenEngine === 'cf_sfu' && !empty($providers['cf_sfu'])) {
            $res['video'] = [
                'provider' => 'cf_sfu',
                'appId'    => (string)($providers['cf_sfu']['config']['app_id'] ?? ''),
                'proxyUrl' => SITE_URL . '/api/cf_sfu_proxy.php',
            ];
        }

        return $res;
    }

    private static function issueLiveKitToken(array $cfg, string $roomName, array $actor): string
    {
        $isHost = !empty($actor['is_host']);
        $claims = [
            'iss'   => (string)$cfg['api_key'],
            'sub'   => (string)$actor['user_key'],
            'name'  => (string)$actor['name'],
            'video' => [
                'roomJoin'     => true,
                'room'         => $roomName,
                'canPublish'   => $isHost,
                'canSubscribe' => true,
                'roomAdmin'    => $isHost,
            ],
        ];
        return vsGenerateJwt($claims, (string)$cfg['api_secret'], 14400);
    }

    private static function create100msSession(array $cfg, string $roomSlug, array $actor): array
    {
        $accessKey = trim((string)($cfg['access_key'] ?? ''));
        $appSecret = trim((string)($cfg['app_secret'] ?? ''));
        $templateId = trim((string)($cfg['template_id'] ?? ''));
        $hostRole = trim((string)($cfg['host_role'] ?? 'host')) ?: 'host';
        $guestRole = trim((string)($cfg['guest_role'] ?? 'guest')) ?: 'guest';

        if ($accessKey === '' || $appSecret === '' || !function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'Missing 100ms credentials or cURL'];
        }

        $mgmtToken = vsGenerateJwt([
            'access_key' => $accessKey,
            'type'       => 'management',
            'version'    => 2,
            'jti'        => bin2hex(random_bytes(8)),
        ], $appSecret, 600);

        $headers = ['Authorization: Bearer ' . $mgmtToken, 'Content-Type: application/json'];
        $payload = ['name' => $roomSlug, 'description' => 'Abu Anas Meetings Room'];
        if ($templateId !== '') {
            $payload['template_id'] = $templateId;
        }

        $ch = curl_init('https://api.100ms.live/v2/rooms');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
        $rBody = (string)curl_exec($ch);
        $rCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rj = json_decode($rBody, true);
        $hmsRoomId = (string)($rj['id'] ?? '');

        if ($hmsRoomId === '') {
            $gch = curl_init('https://api.100ms.live/v2/rooms?name=' . urlencode($roomSlug));
            curl_setopt_array($gch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => $headers]);
            $gj = json_decode((string)curl_exec($gch), true);
            curl_close($gch);
            $hmsRoomId = (string)($gj['data'][0]['id'] ?? '');
        }

        if ($hmsRoomId === '') {
            return ['ok' => false, 'error' => 'Could not resolve 100ms room (' . $rCode . ')'];
        }

        $appToken = vsGenerateJwt([
            'access_key' => $accessKey,
            'room_id'    => $hmsRoomId,
            'user_id'    => (string)$actor['user_key'],
            'role'       => !empty($actor['is_host']) ? $hostRole : $guestRole,
            'type'       => 'app',
            'version'    => 2,
            'jti'        => bin2hex(random_bytes(8)),
        ], $appSecret, 14400);

        return [
            'ok'        => true,
            'provider'  => 'hms',
            'roomId'    => $hmsRoomId,
            'authToken' => $appToken,
        ];
    }

    private static function createDailySession(array $cfg, string $roomName, array $actor): array
    {
        $apiKey = trim((string)($cfg['api_key'] ?? ''));
        if ($apiKey === '' || !function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'Missing Daily API key or cURL'];
        }
        $headers = ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'];

        // Ensure room exists (ignore already exists)
        $ch = curl_init('https://api.daily.co/v1/rooms');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode([
                'name'       => $roomName,
                'privacy'    => 'public',
                'properties' => ['exp' => time() + 21600, 'enable_chat' => false],
            ]),
        ]);
        $rBody = (string)curl_exec($ch);
        $rCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $domain = trim((string)($cfg['domain'] ?? ''));
        $roomUrl = $domain !== '' ? ('https://' . rtrim($domain, '/') . '/' . $roomName) : '';
        if ($rCode >= 200 && $rCode < 300) {
            $rj = json_decode($rBody, true);
            if (!empty($rj['url'])) {
                $roomUrl = (string)$rj['url'];
            }
        } elseif ($rCode === 400) {
            $gch = curl_init('https://api.daily.co/v1/rooms/' . urlencode($roomName));
            curl_setopt_array($gch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => $headers]);
            $gj = json_decode((string)curl_exec($gch), true);
            curl_close($gch);
            if (!empty($gj['url'])) {
                $roomUrl = (string)$gj['url'];
            }
        }

        if ($roomUrl === '') {
            return ['ok' => false, 'error' => 'Could not resolve Daily room URL'];
        }

        // Issue meeting token
        $tch = curl_init('https://api.daily.co/v1/meeting-tokens');
        curl_setopt_array($tch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode([
                'properties' => [
                    'room_name' => $roomName,
                    'user_name' => (string)$actor['name'],
                    'is_owner'  => !empty($actor['is_host']),
                    'exp'       => time() + 14400,
                ],
            ]),
        ]);
        $tb = json_decode((string)curl_exec($tch), true);
        curl_close($tch);

        return [
            'ok'       => true,
            'provider' => 'daily',
            'roomUrl'  => $roomUrl,
            'token'    => (string)($tb['token'] ?? ''),
        ];
    }

    public static function recordUsage(PDO $db, string $providerKey, float $units): void
    {
        if ($units <= 0) {
            return;
        }
        $st = $db->prepare('UPDATE provider_configs SET used_units = used_units + ? WHERE provider_key = ?');
        $st->execute([$units, $providerKey]);
    }
}
