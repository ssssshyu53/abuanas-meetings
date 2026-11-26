<?php
/**
 * Abu Anas Meetings — PDO Database Connector with Auto-Bootstrap Schema
 */
declare(strict_types=1);

class Database
{
    private static ?self $instance = null;
    private static ?PDO $sharedConn = null;
    private static bool $bootstrapped = false;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function isSqlite(): bool
    {
        return defined('DB_DRIVER') && DB_DRIVER === 'sqlite';
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function cutoff(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() - max(0, $seconds));
    }

    public function connect(): PDO
    {
        if (self::$sharedConn !== null) {
            return self::$sharedConn;
        }

        $pdo = null;
        $lastErr = null;

        if (self::isSqlite()) {
            try {
                $dbPath = defined('DB_SQLITE_PATH') ? DB_SQLITE_PATH : (VS_ROOT . '/database/abuanas.sqlite');
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
            } catch (Throwable $e) {
                $lastErr = $e;
            }
        } else {
            $hosts = array_values(array_unique([DB_HOST, DB_HOST === 'localhost' ? '127.0.0.1' : 'localhost']));
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ];

            foreach ($hosts as $h) {
                try {
                    $pdo = new PDO(
                        'mysql:host=' . $h . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                        DB_USER,
                        DB_PASS,
                        $options
                    );
                    break;
                } catch (PDOException $e) {
                    $lastErr = $e;
                    // Auto-create database locally if missing (SQLSTATE 1049)
                    if (str_contains($e->getMessage(), '1049') || str_contains(strtolower($e->getMessage()), 'unknown database')) {
                        try {
                            $rootPdo = new PDO('mysql:host=' . $h . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
                            $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', DB_NAME);
                            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                            $pdo = new PDO('mysql:host=' . $h . ';dbname=' . $safeDb . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
                            break;
                        } catch (Throwable $inner) {
                            $lastErr = $inner;
                        }
                    }
                }
            }
        }

        if ($pdo === null) {
            error_log('AbuAnasMeetings DB Error: ' . ($lastErr ? $lastErr->getMessage() : 'unknown'));
            http_response_code(500);
            exit('Database connection unavailable.');
        }

        self::$sharedConn = $pdo;
        if (!self::$bootstrapped) {
            self::$bootstrapped = true;
            $this->ensureSchema($pdo);
        }
        return $pdo;
    }

    private function ensureSchema(PDO $pdo): void
    {
        $isSqlite = self::isSqlite();
        $hasUsers = $isSqlite
            ? (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn()
            : (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if (!$hasUsers) {
            $sqlFile = VS_ROOT . ($isSqlite ? '/database/schema_sqlite.sql' : '/database/schema.sql');
            if (is_file($sqlFile)) {
                $raw = file_get_contents($sqlFile) ?: '';
                $cleaned = (string)preg_replace('/^\s*--.*$/m', '', $raw);
                foreach (explode(';', $cleaned) as $stmt) {
                    $s = trim($stmt);
                    if ($s !== '') {
                        $pdo->exec($s);
                    }
                }
            }
        } else {
            $this->migrateColumns($pdo, $isSqlite);
        }

        // Seed default provider entries idempotently
        $dayKey = date('Y-m-d');
        $monthKey = date('Y-m');
        $defaults = [
            ['cf_ws', 'Cloudflare Workers + Durable Objects (الصوت الأساسي)', 'audio', 1, 1, 100000, 'requests_day', $dayKey, ['ws_url' => vsEnv('CF_WORKER_WS_URL', '')]],
            ['node_relay', 'Node.js WebSocket Relay (Render / المحلي الاحتياطي)', 'audio', 1, 2, 100, 'gb_month', $monthKey, ['ws_url' => vsEnv('NODE_RELAY_WS_URL', 'ws://127.0.0.1:8788')]],
            ['livekit', 'LiveKit Cloud (تفاعل مرئي ووكلاء)', 'video', 1, 1, 5000, 'minutes_month', $monthKey, ['api_key' => vsEnv('LIVEKIT_API_KEY', ''), 'api_secret' => vsEnv('LIVEKIT_API_SECRET', ''), 'ws_url' => vsEnv('LIVEKIT_WS_URL', '')]],
            ['daily', 'Daily.co (اجتماعات ولقاءات سريعة)', 'video', 1, 2, 10000, 'minutes_month', $monthKey, ['api_key' => vsEnv('DAILY_API_KEY', ''), 'domain' => vsEnv('DAILY_DOMAIN', '')]],
            ['cf_sfu', 'Cloudflare Realtime Calls SFU (فيديو وشاشة)', 'video', 1, 3, 1000, 'gb_month', $monthKey, ['app_id' => vsEnv('CF_SFU_APP_ID', ''), 'app_secret' => vsEnv('CF_SFU_APP_SECRET', '')]],
            ['hms', '100ms Live (محاضرات مرئية وبث)', 'video', 1, 4, 10000, 'minutes_month', $monthKey, ['access_key' => vsEnv('HMS_ACCESS_KEY', ''), 'app_secret' => vsEnv('HMS_APP_SECRET', ''), 'template_id' => vsEnv('HMS_TEMPLATE_ID', ''), 'host_role' => vsEnv('HMS_HOST_ROLE', 'host'), 'guest_role' => vsEnv('HMS_GUEST_ROLE', 'guest')]],
            ['r2', 'Cloudflare R2 Storage (أرشفة مجانية بدون رسوم سحب)', 'storage', 1, 1, 10, 'gb_month', $monthKey, ['account_id' => vsEnv('R2_ACCOUNT_ID', ''), 'access_key' => vsEnv('R2_ACCESS_KEY_ID', ''), 'secret_key' => vsEnv('R2_SECRET_ACCESS_KEY', ''), 'bucket' => vsEnv('R2_BUCKET', ''), 'public_url' => vsEnv('R2_PUBLIC_URL', '')]],
            ['telegram', 'Telegram Webhook Publishing (نشر تلقائي اختياري)', 'storage', 0, 2, 0, 'gb_month', $monthKey, ['bot_token' => vsEnv('TELEGRAM_BOT_TOKEN', ''), 'chat_id' => vsEnv('TELEGRAM_CHAT_ID', '')]],
        ];
        $insertCmd = $isSqlite ? 'INSERT OR IGNORE INTO provider_configs' : 'INSERT IGNORE INTO provider_configs';
        $insProv = $pdo->prepare("
            {$insertCmd}
                (provider_key, label, category, enabled, priority, monthly_limit, limit_unit, used_units, period_key, config_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
        ");
        foreach ($defaults as $d) {
            $insProv->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], json_encode($d[8], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }

        // Seed starter demo accounts ONLY if database has zero users
        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($userCount === 0) {
            $insUser = $pdo->prepare('INSERT INTO users (name, workspace_name, email, password_hash, role, avatar_color) VALUES (?, ?, ?, ?, ?, ?)');
            $insUser->execute(['مدير المنصة', 'أبو أنس ميتينجز', 'admin@abuanas.local', password_hash('Admin@2026', PASSWORD_BCRYPT), 'admin', '#1D63ED']);
            $insUser->execute(['أنس محمد (مضيف)', 'غرفة أنس الخاصة', 'host@abuanas.local', password_hash('Host@2026', PASSWORD_BCRYPT), 'host', '#0EA5E9']);
            $insUser->execute(['مشارك تجريبي', '', 'user@abuanas.local', password_hash('User@2026', PASSWORD_BCRYPT), 'member', '#10B981']);
        }
    }

    private function migrateColumns(PDO $pdo, bool $isSqlite): void
    {
        $hasCol = static function (string $table, string $col) use ($pdo, $isSqlite): bool {
            if ($isSqlite) {
                $rows = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    if (strcasecmp((string)($r['name'] ?? ''), $col) === 0) {
                        return true;
                    }
                }
                return false;
            }
            $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (strcasecmp((string)($r['Field'] ?? ''), $col) === 0) {
                    return true;
                }
            }
            return false;
        };

        $alterSpecs = [
            ['users', 'workspace_name', $isSqlite ? "ALTER TABLE users ADD COLUMN workspace_name TEXT NOT NULL DEFAULT ''" : "ALTER TABLE users ADD COLUMN workspace_name VARCHAR(120) NOT NULL DEFAULT '' AFTER name"],
            ['users', 'is_active', $isSqlite ? "ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1" : "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER avatar_color"],
            ['users', 'last_login_at', $isSqlite ? "ALTER TABLE users ADD COLUMN last_login_at TEXT NULL DEFAULT NULL" : "ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER is_active"],
            ['rooms', 'passcode', $isSqlite ? "ALTER TABLE rooms ADD COLUMN passcode TEXT NOT NULL DEFAULT ''" : "ALTER TABLE rooms ADD COLUMN passcode VARCHAR(64) NOT NULL DEFAULT '' AFTER access_mode"],
            ['recordings', 'media_type', $isSqlite ? "ALTER TABLE recordings ADD COLUMN media_type TEXT NOT NULL DEFAULT 'audio'" : "ALTER TABLE recordings ADD COLUMN media_type ENUM('audio','video') NOT NULL DEFAULT 'audio' AFTER title"],
        ];

        foreach ($alterSpecs as [$tbl, $col, $sql]) {
            if (!$hasCol($tbl, $col)) {
                try {
                    $pdo->exec($sql);
                } catch (Throwable $e) {
                    error_log("Migration skipped ({$tbl}.{$col}): " . $e->getMessage());
                }
            }
        }
    }
}
