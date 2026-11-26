<?php
/**
 * Abu Anas Meetings — Admin User & Host Role Management
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

$me = Auth::requireAdmin();
$db = Database::getInstance()->connect();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && vsVerifyCsrf()) {
    $uid = (int)($_POST['user_id'] ?? 0);
    $newRole = (string)($_POST['role'] ?? '');
    if ($uid > 0 && $uid !== (int)$me['id'] && in_array($newRole, ['member', 'host', 'admin'], true)) {
        $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $uid]);
        $msg = 'تم تحديث صلاحية الحساب بنجاح.';
    }
}

$users = $db->query('SELECT id, name, email, role, created_at FROM users ORDER BY id DESC')->fetchAll();
$pageTitle = 'إدارة المستخدمين والمضيفين';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="vs-container" style="padding:2rem 1.25rem;">
  <h1 style="margin:0 0 0.35rem;font-size:1.55rem;">المستخدمون والمضيفون</h1>
  <p style="margin:0 0 1.25rem;color:var(--vs-muted);font-size:0.88rem;">ترقية أو تعديل صلاحيات الحسابات (مشارك / مضيف اجتماعات / مدير).</p>

  <?php if ($msg !== ''): ?><div class="vs-card" style="border-color:var(--vs-emerald);color:var(--vs-emerald);margin-bottom:1rem;"><?php echo vsEsc($msg); ?></div><?php endif; ?>

  <div class="vs-card">
    <div class="vs-table-wrap">
      <table class="vs-table">
        <thead><tr><th>الاسم</th><th>البريد الإلكتروني</th><th>الدور الحالي</th><th>تاريخ التسجيل</th><th>تعديل الدور</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td style="font-weight:700;"><?php echo vsEsc($u['name']); ?></td>
              <td class="mono"><?php echo vsEsc($u['email']); ?></td>
              <td>
                <span class="vs-badge <?php echo $u['role'] === 'admin' ? 'vs-badge-blue' : ($u['role'] === 'host' ? 'vs-badge-live' : 'vs-badge-muted'); ?>">
                  <?php echo $u['role'] === 'admin' ? 'مدير' : ($u['role'] === 'host' ? 'مضيف' : 'مشارك'); ?>
                </span>
              </td>
              <td class="mono" style="font-size:0.78rem;"><?php echo vsEsc($u['created_at']); ?></td>
              <td>
                <?php if ((int)$u['id'] === (int)$me['id']): ?>
                  <span style="font-size:0.78rem;color:var(--vs-muted);">حسابك الحالي</span>
                <?php else: ?>
                  <form method="POST" style="display:flex;gap:0.4rem;align-items:center;margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                    <select name="role" class="vs-select" style="width:auto;padding:0.3rem 0.6rem;font-size:0.8rem;">
                      <option value="member" <?php echo $u['role'] === 'member' ? 'selected' : ''; ?>>مشارك</option>
                      <option value="host" <?php echo $u['role'] === 'host' ? 'selected' : ''; ?>>مضيف</option>
                      <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>مدير</option>
                    </select>
                    <button type="submit" class="vs-btn vs-btn-outline" style="height:32px;padding:0 0.7rem;font-size:0.78rem;">حفظ</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
