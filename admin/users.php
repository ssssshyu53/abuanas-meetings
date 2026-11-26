<?php
/**
 * Abu Anas Meetings — Admin User, Host Accounts & Workspace Management
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

$me = Auth::requireAdmin();
$db = Database::getInstance()->connect();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && vsVerifyCsrf()) {
    $act = (string)($_POST['action'] ?? 'update_role');
    if ($act === 'create_user') {
        $res = Auth::adminCreateUser(
            (string)($_POST['name'] ?? ''),
            (string)($_POST['workspace_name'] ?? ''),
            (string)($_POST['email'] ?? ''),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['role'] ?? 'host')
        );
        if ($res['ok']) {
            $msg = 'تم إنشاء الحساب بنجاح.';
        } else {
            $err = $res['error'] ?? 'تعذر إنشاء الحساب';
        }
    } elseif ($act === 'toggle_active') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0 && $uid !== (int)$me['id']) {
            $nextState = !empty($_POST['is_active']) ? 1 : 0;
            $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$nextState, $uid]);
            $msg = $nextState ? 'تم تفعيل الحساب بنجاح.' : 'تم إيقاف الحساب بنجاح.';
        }
    } elseif ($act === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $newPass = (string)($_POST['new_password'] ?? '');
        if ($uid > 0 && strlen($newPass) >= 8) {
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPass, PASSWORD_BCRYPT), $uid]);
            $msg = 'تم تعيين كلمة المرور الجديدة للمستخدم.';
        } else {
            $err = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
        }
    } else {
        $uid = (int)($_POST['user_id'] ?? 0);
        $newRole = (string)($_POST['role'] ?? '');
        if ($uid > 0 && $uid !== (int)$me['id'] && in_array($newRole, ['member', 'host', 'admin'], true)) {
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $uid]);
            $msg = 'تم تحديث صلاحية الحساب بنجاح.';
        }
    }
}

$users = $db->query('
    SELECT u.id, u.name, u.workspace_name, u.email, u.role, u.is_active, u.last_login_at, u.created_at,
           (SELECT COUNT(*) FROM rooms r WHERE r.host_user_id = u.id) AS room_cnt
    FROM users u
    ORDER BY u.id DESC
')->fetchAll();
$pageTitle = 'إدارة المستخدمين والمضيفين';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="vs-container" style="padding:2rem 1.25rem;">
  <h1 style="margin:0 0 0.35rem;font-size:1.55rem;">المستخدمون وحسابات المضيفين</h1>
  <p style="margin:0 0 1.25rem;color:var(--vs-muted);font-size:0.88rem;">إنشاء حسابات مضيفين مستقلة، التحكم بالتفعيل/الإيقاف، وإدارة الصلاحيات.</p>

  <?php if ($msg !== ''): ?><div class="vs-card" style="border-color:var(--vs-emerald);color:var(--vs-emerald);margin-bottom:1rem;"><?php echo vsEsc($msg); ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="vs-card" style="border-color:var(--vs-rose);color:var(--vs-rose);margin-bottom:1rem;"><?php echo vsEsc($err); ?></div><?php endif; ?>

  <div class="vs-card" style="margin-bottom:1.25rem;">
    <h2 style="margin:0 0 0.85rem;font-size:1.1rem;">إنشاء حساب مضيف / مستخدم جديد</h2>
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:0.7rem;align-items:end;">
      <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
      <input type="hidden" name="action" value="create_user">
      <div>
        <label class="vs-label">الاسم</label>
        <input type="text" name="name" class="vs-input" required placeholder="اسم المضيف">
      </div>
      <div>
        <label class="vs-label">اسم المساحة</label>
        <input type="text" name="workspace_name" class="vs-input" placeholder="مثال: غرفة المبيعات">
      </div>
      <div>
        <label class="vs-label">البريد الإلكتروني</label>
        <input type="email" name="email" class="vs-input" required placeholder="host2@company.com">
      </div>
      <div>
        <label class="vs-label">كلمة المرور (8+)</label>
        <input type="password" name="password" class="vs-input" required minlength="8" placeholder="••••••••">
      </div>
      <div>
        <label class="vs-label">الدور</label>
        <select name="role" class="vs-select">
          <option value="host" selected>مضيف (Host)</option>
          <option value="member">مشارك (Member)</option>
          <option value="admin">مدير (Admin)</option>
        </select>
      </div>
      <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;">إنشاء الحساب</button>
    </form>
  </div>

  <div class="vs-card">
    <div class="vs-table-wrap">
      <table class="vs-table">
        <thead><tr><th>المستخدم والمساحة</th><th>البريد الإلكتروني</th><th>الدور والحالة</th><th>الغرف</th><th>الإدارة</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <div style="font-weight:700;"><?php echo vsEsc($u['name']); ?></div>
                <?php if (!empty($u['workspace_name'])): ?>
                  <div style="font-size:0.75rem;color:var(--vs-muted);"><?php echo vsEsc($u['workspace_name']); ?></div>
                <?php endif; ?>
              </td>
              <td class="mono"><?php echo vsEsc($u['email']); ?></td>
              <td>
                <div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
                  <span class="vs-badge <?php echo $u['role'] === 'admin' ? 'vs-badge-blue' : ($u['role'] === 'host' ? 'vs-badge-live' : 'vs-badge-muted'); ?>">
                    <?php echo $u['role'] === 'admin' ? 'مدير' : ($u['role'] === 'host' ? 'مضيف' : 'مشارك'); ?>
                  </span>
                  <span class="vs-badge <?php echo (int)($u['is_active'] ?? 1) === 1 ? 'vs-badge-live' : 'vs-badge-amber'; ?>">
                    <?php echo (int)($u['is_active'] ?? 1) === 1 ? 'نشط' : 'موقوف'; ?>
                  </span>
                </div>
              </td>
              <td class="mono"><?php echo (int)$u['room_cnt']; ?></td>
              <td>
                <?php if ((int)$u['id'] === (int)$me['id']): ?>
                  <span style="font-size:0.78rem;color:var(--vs-muted);">حسابك الحالي</span>
                <?php else: ?>
                  <div style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
                    <form method="POST" style="display:flex;gap:0.3rem;align-items:center;margin:0;">
                      <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
                      <input type="hidden" name="action" value="update_role">
                      <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                      <select name="role" class="vs-select" style="width:auto;padding:0.25rem 0.5rem;font-size:0.78rem;">
                        <option value="member" <?php echo $u['role'] === 'member' ? 'selected' : ''; ?>>مشارك</option>
                        <option value="host" <?php echo $u['role'] === 'host' ? 'selected' : ''; ?>>مضيف</option>
                        <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>مدير</option>
                      </select>
                      <button type="submit" class="vs-btn vs-btn-outline" style="height:30px;padding:0 0.6rem;font-size:0.75rem;">حفظ</button>
                    </form>

                    <form method="POST" style="margin:0;">
                      <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                      <input type="hidden" name="is_active" value="<?php echo (int)($u['is_active'] ?? 1) === 1 ? '0' : '1'; ?>">
                      <button type="submit" class="vs-btn vs-btn-outline" style="height:30px;padding:0 0.6rem;font-size:0.75rem;">
                        <?php echo (int)($u['is_active'] ?? 1) === 1 ? 'إيقاف' : 'تفعيل'; ?>
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
