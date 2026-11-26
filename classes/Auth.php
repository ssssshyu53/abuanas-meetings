<?php
/**
 * Abu Anas Meetings — Authentication, Host Role & Guest Actor Manager
 */
declare(strict_types=1);

class Auth
{
    public static function currentUser(): ?array
    {
        $uid = (int)($_SESSION['vs_user_id'] ?? 0);
        if ($uid <= 0) {
            return null;
        }
        $db = Database::getInstance()->connect();
        $st = $db->prepare('SELECT id, name, workspace_name, email, role, avatar_color, is_active, last_login_at, created_at FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $row = $st->fetch();
        if (!$row || (int)($row['is_active'] ?? 1) !== 1) {
            unset($_SESSION['vs_user_id']);
            return null;
        }
        return $row;
    }

    public static function isLoggedIn(): bool
    {
        return self::currentUser() !== null;
    }

    public static function isAdmin(): bool
    {
        $u = self::currentUser();
        return $u !== null && ($u['role'] ?? '') === 'admin';
    }

    public static function isHost(): bool
    {
        $u = self::currentUser();
        return $u !== null && ($u['role'] ?? '') === 'host';
    }

    public static function canHost(): bool
    {
        $u = self::currentUser();
        return $u !== null && in_array($u['role'] ?? '', ['admin', 'host'], true);
    }

    public static function requireLogin(): array
    {
        $u = self::currentUser();
        if ($u === null) {
            vsRedirect(SITE_URL . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? SITE_URL . '/dashboard.php'));
        }
        return $u;
    }

    public static function requireHost(): array
    {
        $u = self::requireLogin();
        if (!in_array($u['role'] ?? '', ['admin', 'host'], true)) {
            vsRedirect(SITE_URL . '/dashboard.php');
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireLogin();
        if (($u['role'] ?? '') !== 'admin') {
            vsRedirect(SITE_URL . '/dashboard.php');
        }
        return $u;
    }

    public static function upgradeToHost(int $userId): void
    {
        $db = Database::getInstance()->connect();
        $st = $db->prepare("UPDATE users SET role = 'host' WHERE id = ? AND role = 'member'");
        $st->execute([$userId]);
    }

    public static function register(string $name, string $email, string $password, string $accountType = 'host', string $workspaceName = ''): array
    {
        $name = mb_substr(trim($name), 0, 100);
        $workspaceName = mb_substr(trim($workspaceName), 0, 100);
        $email = strtolower(trim($email));
        $role = $accountType === 'member' ? 'member' : 'host';
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'error' => 'الاسم يجب أن يكون حرفين على الأقل'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'البريد الإلكتروني غير صالح'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'];
        }
        if ($role === 'host' && $workspaceName === '') {
            $workspaceName = 'غرفة ' . $name;
        }

        $db = Database::getInstance()->connect();
        $chk = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'هذا البريد مسجل بالفعل'];
        }

        $colors = ['#1D63ED', '#0EA5E9', '#10B981', '#F59E0B', '#6366F1'];
        $color = $colors[array_rand($colors)];
        $st = $db->prepare('INSERT INTO users (name, workspace_name, email, password_hash, role, avatar_color, is_active, last_login_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
        $st->execute([$name, $workspaceName, $email, password_hash($password, PASSWORD_BCRYPT), $role, $color, Database::now()]);
        $newId = (int)$db->lastInsertId();

        session_regenerate_id(true);
        $_SESSION['vs_user_id'] = $newId;
        return ['ok' => true, 'user_id' => $newId, 'role' => $role];
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $db = Database::getInstance()->connect();
        $st = $db->prepare('SELECT id, password_hash, is_active FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, (string)$u['password_hash'])) {
            return ['ok' => false, 'error' => 'بيانات الدخول غير صحيحة'];
        }
        if ((int)($u['is_active'] ?? 1) !== 1) {
            return ['ok' => false, 'error' => 'تم إيقاف هذا الحساب من قِبل الإدارة'];
        }
        $db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Database::now(), (int)$u['id']]);
        session_regenerate_id(true);
        $_SESSION['vs_user_id'] = (int)$u['id'];
        return ['ok' => true, 'user_id' => (int)$u['id']];
    }

    public static function updateProfile(int $userId, string $name, string $workspaceName): array
    {
        $name = mb_substr(trim($name), 0, 100);
        $workspaceName = mb_substr(trim($workspaceName), 0, 100);
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'error' => 'الاسم يجب أن يكون حرفين على الأقل'];
        }
        $db = Database::getInstance()->connect();
        $db->prepare('UPDATE users SET name = ?, workspace_name = ? WHERE id = ?')->execute([$name, $workspaceName, $userId]);
        return ['ok' => true];
    }

    public static function adminCreateUser(string $name, string $workspaceName, string $email, string $password, string $role = 'host'): array
    {
        $name = mb_substr(trim($name), 0, 100);
        $workspaceName = mb_substr(trim($workspaceName), 0, 100);
        $email = strtolower(trim($email));
        $role = in_array($role, ['admin', 'host', 'member'], true) ? $role : 'host';
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            return ['ok' => false, 'error' => 'تأكد من الاسم وبريد صالح وكلمة مرور من 8 أحرف على الأقل'];
        }
        $db = Database::getInstance()->connect();
        $chk = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'هذا البريد مسجل مسبقاً'];
        }
        $st = $db->prepare('INSERT INTO users (name, workspace_name, email, password_hash, role, avatar_color, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $st->execute([$name, $workspaceName ?: ('غرفة ' . $name), $email, password_hash($password, PASSWORD_BCRYPT), $role, '#1D63ED']);
        return ['ok' => true, 'user_id' => (int)$db->lastInsertId()];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        @session_destroy();
    }

    public static function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return ['ok' => false, 'error' => 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل'];
        }
        $db = Database::getInstance()->connect();
        $st = $db->prepare('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u || !password_verify($currentPassword, (string)$u['password_hash'])) {
            return ['ok' => false, 'error' => 'كلمة المرور الحالية غير صحيحة'];
        }
        $upd = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($newPassword, PASSWORD_BCRYPT), $userId]);
        session_regenerate_id(true);
        return ['ok' => true];
    }

    public static function setGuestName(string $name): string
    {
        $clean = mb_substr(trim(strip_tags($name)), 0, 50);
        if ($clean === '') {
            $clean = 'ضيف';
        }
        if (empty($_SESSION['vs_guest_id'])) {
            $_SESSION['vs_guest_id'] = 'g_' . bin2hex(random_bytes(5));
        }
        $_SESSION['vs_guest_name'] = $clean;
        return $clean;
    }

    public static function resolveActor(array $room): ?array
    {
        $u = self::currentUser();
        if ($u !== null) {
            $isHost = ((int)$u['id'] === (int)$room['host_user_id']);
            return [
                'user_key' => 'u_' . (int)$u['id'],
                'name'     => (string)$u['name'],
                'role'     => $isHost ? 'host' : 'member',
                'is_host'  => $isHost,
                'is_guest' => false,
            ];
        }
        if (($room['access_mode'] ?? 'code') === 'registered') {
            return null;
        }
        $gName = trim((string)($_SESSION['vs_guest_name'] ?? ''));
        $gId = trim((string)($_SESSION['vs_guest_id'] ?? ''));
        if ($gName !== '' && $gId !== '') {
            return [
                'user_key' => $gId,
                'name'     => $gName,
                'role'     => 'guest',
                'is_host'  => false,
                'is_guest' => true,
            ];
        }
        return null;
    }

    public static function hasRoomPasscodeAccess(array $room, ?array $actor): bool
    {
        $pass = trim((string)($room['passcode'] ?? ''));
        if ($pass === '') {
            return true;
        }
        if ($actor && !empty($actor['is_host'])) {
            return true;
        }
        $code = strtoupper((string)($room['code'] ?? ''));
        return !empty($_SESSION['aam_pass_ok'][$code]);
    }

    public static function verifyRoomPasscode(array $room, string $candidate): bool
    {
        $pass = trim((string)($room['passcode'] ?? ''));
        if ($pass === '') {
            return true;
        }
        if (hash_equals($pass, trim($candidate))) {
            $code = strtoupper((string)($room['code'] ?? ''));
            $_SESSION['aam_pass_ok'][$code] = true;
            return true;
        }
        return false;
    }
}
