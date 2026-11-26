<?php
/**
 * Admin-Only Multi-Engine Health & Quota Telemetry API
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

if (!Auth::isAdmin()) {
    vsJson(403, ['success' => false, 'error' => 'هذه الواجهة مخصصة لمدير المنصة فقط']);
}

$db = Database::getInstance()->connect();
$pool = EngineRouter::audioFailoverUrls($db);
$providers = $pool['providers'];

$liveRoomsSt = $db->prepare("
    SELECT r.id, r.code, r.title, r.preset, r.media_mode, r.active_engine, r.started_at,
           (SELECT COUNT(*) FROM room_participants p WHERE p.room_id = r.id AND p.last_seen_at >= ?) AS db_online
    FROM rooms r
    WHERE r.status = 'live'
    ORDER BY r.id DESC
    LIMIT 10
");
$liveRoomsSt->execute([Database::cutoff(40)]);
$liveRooms = $liveRoomsSt->fetchAll();

$targetRoomId = !empty($liveRooms[0]['code']) ? ('aam-' . $liveRooms[0]['code']) : 'lobby';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$probeToken = vsGenerateJwt([
    'roomId' => $targetRoomId,
    'userId' => 'aam_probe_' . (int)($_SESSION['vs_user_id'] ?? 1),
    'name'   => 'System Probe',
    'role'   => 'host',
    'probe'  => true,
], vsJwtSecret(), 120);

function vsProbeUrl(string $url, int $timeoutSec = 5): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'connect_ms' => 0, 'rtt_ms' => 0, 'error' => 'cURL unavailable', 'data' => null];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSec),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $connMs = round((float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000, 1);
    $totMs = round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, 1);
    curl_close($ch);
    $json = ($body && $code >= 200 && $code < 300) ? json_decode((string)$body, true) : null;
    return [
        'ok'         => ($code >= 200 && $code < 300 && is_array($json)),
        'http_code'  => $code,
        'connect_ms' => $connMs,
        'rtt_ms'     => $totMs,
        'error'      => $err ?: (is_array($json) ? '' : "HTTP {$code}"),
        'data'       => is_array($json) ? $json : null,
    ];
}

$nodes = [];
$globalUsage = null;
foreach (['cf_ws', 'node_relay'] as $k) {
    $p = $providers[$k] ?? null;
    if (!$p || !$p['configured']) continue;
    $wsUrl = rtrim((string)$p['config']['ws_url'], '/');
    $httpBase = EngineRouter::wsUrlToHttp($wsUrl);

    // Call /metrics first and skip redundant /health when /metrics succeeds (50% quota savings)
    $metricsProbe = vsProbeUrl($httpBase . '/metrics?room=' . urlencode($targetRoomId) . '&token=' . urlencode($probeToken), 5);
    $healthProbe = !empty($metricsProbe['ok'])
        ? ['ok' => true, 'http_code' => $metricsProbe['http_code'], 'connect_ms' => $metricsProbe['connect_ms'], 'rtt_ms' => $metricsProbe['rtt_ms'], 'error' => '', 'data' => $metricsProbe['data']]
        : vsProbeUrl($httpBase . '/health', 4);

    $mData = $metricsProbe['data'] ?? $healthProbe['data'] ?? [];
    if (!empty($mData['globalUsage']) && is_array($mData['globalUsage'])) {
        $globalUsage = $mData['globalUsage'];
        if ($k === 'cf_ws' && isset($mData['globalUsage']['dayRequests'])) {
            $db->prepare("UPDATE provider_configs SET used_units = ? WHERE provider_key = 'cf_ws'")->execute([(float)$mData['globalUsage']['dayRequests']]);
            $providers['cf_ws']['used_units'] = (float)$mData['globalUsage']['dayRequests'];
        } elseif ($k === 'node_relay' && isset($mData['globalUsage']['monthEgressBytes'])) {
            $gbUsed = round(((float)$mData['globalUsage']['monthEgressBytes']) / (1024 * 1024 * 1024), 4);
            $db->prepare("UPDATE provider_configs SET used_units = ? WHERE provider_key = 'node_relay'")->execute([$gbUsed]);
            $providers['node_relay']['used_units'] = $gbUsed;
        }
    }

    $nodes[] = [
        'key'         => $k,
        'label'       => $p['label'],
        'ws_url'      => $wsUrl,
        'reachable'   => !empty($healthProbe['ok']),
        'http_code'   => $metricsProbe['ok'] ? $metricsProbe['http_code'] : $healthProbe['http_code'],
        'connect_ms'  => $healthProbe['connect_ms'],
        'rtt_ms'      => $healthProbe['rtt_ms'],
        'error'       => $healthProbe['ok'] ? '' : $healthProbe['error'],
        'service'     => (string)($mData['service'] ?? ''),
        'runtime'     => (string)($mData['runtime'] ?? ''),
        'jwt_ok'      => !empty($mData['jwtConfigured']),
        'uptime'      => isset($mData['uptime']) ? (int)$mData['uptime'] : null,
        'memory'      => $mData['memory'] ?? null,
        'edge'        => $mData['edge'] ?? null,
        'room'        => $mData['room'] ?? null,
    ];
}

// Compute combined multi-provider video pool summary
$videoSummary = ['total_minutes_limit' => 0, 'total_minutes_used' => 0, 'cf_sfu_gb_limit' => 0, 'cf_sfu_gb_used' => 0, 'providers' => []];
foreach ($providers as $k => $pr) {
    if ($pr['category'] !== 'video') continue;
    $videoSummary['providers'][] = [
        'key'        => $k,
        'label'      => $pr['label'],
        'enabled'    => (bool)$pr['enabled'],
        'configured' => (bool)$pr['configured'],
        'priority'   => (int)$pr['priority'],
        'limit'      => (float)$pr['monthly_limit'],
        'used'       => (float)$pr['used_units'],
        'unit'       => $pr['limit_unit'],
    ];
    if ((int)$pr['enabled'] === 1) {
        if ($pr['limit_unit'] === 'minutes_month') {
            $videoSummary['total_minutes_limit'] += (float)$pr['monthly_limit'];
            $videoSummary['total_minutes_used'] += (float)$pr['used_units'];
        } elseif ($k === 'cf_sfu') {
            $videoSummary['cf_sfu_gb_limit'] += (float)$pr['monthly_limit'];
            $videoSummary['cf_sfu_gb_used'] += (float)$pr['used_units'];
        }
    }
}

$uploadsDir = VS_ROOT . '/uploads';
vsJson(200, [
    'success'       => true,
    'checked_at'    => date('Y-m-d H:i:s'),
    'target_room'   => $targetRoomId,
    'ws_probe'      => ['wsUrls' => $pool['ws_urls'], 'token' => $probeToken, 'roomId' => $targetRoomId],
    'nodes'         => $nodes,
    'global_usage'  => $globalUsage,
    'cf_day_reqs'   => (int)($globalUsage['dayRequests'] ?? ($providers['cf_ws']['used_units'] ?? 0)),
    'cf_day_ws_msgs'=> (int)($globalUsage['dayWsMessages'] ?? 0),
    'relay_gb_used' => (float)($providers['node_relay']['used_units'] ?? 0),
    'video_pool'    => $videoSummary,
    'live_rooms'    => $liveRooms,
    'host_server'   => [
        'php_version'   => PHP_VERSION,
        'php_memory_mb' => round(memory_get_usage(true) / 1048576, 2),
        'disk_free_gb'  => is_dir($uploadsDir) && disk_free_space($uploadsDir) ? round(disk_free_space($uploadsDir) / 1073741824, 2) : null,
    ],
]);
