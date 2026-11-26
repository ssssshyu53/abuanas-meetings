<?php
/**
 * Abu Anas Meetings — Account Registration (Member or Host Account Type)
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

if (Auth::isLoggedIn()) {
    vsRedirect(SITE_URL . '/dashboard.php');
}

$error = '';
$selectedType = ($_POST['account_type'] ?? 'host') === 'member' ? 'member' : 'host';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vsRateLimit('register', 8, 900)) {
        $error = 'تم تجاوز الحد المسموح للتسجيل المؤقت، حاول لاحقاً.';
    } elseif (!vsVerifyCsrf()) {
        $error = 'انتهت صلاحية الجلسة.';
    } else {
        $res = Auth::register(
            (string)($_POST['name'] ?? ''),
            (string)($_POST['email'] ?? ''),
            (string)($_POST['password'] ?? ''),
            $selectedType
        );
        if ($res['ok']) {
            vsRedirect(SITE_URL . '/dashboard.php');
        }
        $error = $res['error'] ?? 'تعذر إنشاء الحساب';
    }
}

$pageTitle = 'إنشاء حساب مجاني';
require_once __DIR__ . '/includes/header.php';
?>
<div class="vs-container" style="padding:2.75rem 1.25rem;max-width:540px;">
  <div class="vs-card" style="padding:1.85rem;">
    <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:0.5rem;">
      <img src="<?php echo SITE_URL; ?>/assets/images/logo-mark.png" alt="Logo" style="width:36px;height:36px;object-fit:contain;">
      <h1 style="margin:0;font-size:1.45rem;">إنشاء حساب مجاني</h1>
    </div>
    <p style="margin:0 0 1.2rem;color:var(--vs-muted);font-size:0.88rem;">اختر نوع حسابك وابدأ فوراً في استضافة أو حضور الاجتماعات.</p>

    <?php if ($error !== ''): ?>
      <div style="padding:0.7rem 0.9rem;border-radius:11px;background:rgba(225,29,72,0.12);color:var(--vs-rose);font-size:0.85rem;font-weight:700;margin-bottom:1rem;"><?php echo vsEsc($error); ?></div>
    <?php endif; ?>

    <form method="POST" style="display:flex;flex-direction:column;gap:0.95rem;">
      <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">

      <div>
        <span class="vs-label">نوع الحساب</span>
        <div class="vs-grid-2" style="gap:0.65rem;">
          <label style="border:2px solid <?php echo $selectedType === 'host' ? 'var(--vs-accent)' : 'var(--vs-border)'; ?>;border-radius:13px;padding:0.75rem;cursor:pointer;display:flex;gap:0.55rem;align-items:flex-start;" onclick="this.parentElement.querySelectorAll('label').forEach(l=>l.style.borderColor='var(--vs-border)');this.style.borderColor='var(--vs-accent)';">
            <input type="radio" name="account_type" value="host" <?php echo $selectedType === 'host' ? 'checked' : ''; ?> style="margin-top:0.25rem;accent-color:var(--vs-accent);">
            <div>
              <div style="font-weight:800;font-size:0.9rem;">مضيف اجتماعات (Host)</div>
              <div style="font-size:0.76rem;color:var(--vs-muted);">إنشاء اجتماعات، مشاركة الروابط، والدمج في منصاتك.</div>
            </div>
          </label>
          <label style="border:2px solid <?php echo $selectedType === 'member' ? 'var(--vs-accent)' : 'var(--vs-border)'; ?>;border-radius:13px;padding:0.75rem;cursor:pointer;display:flex;gap:0.55rem;align-items:flex-start;" onclick="this.parentElement.querySelectorAll('label').forEach(l=>l.style.borderColor='var(--vs-border)');this.style.borderColor='var(--vs-accent)';">
            <input type="radio" name="account_type" value="member" <?php echo $selectedType === 'member' ? 'checked' : ''; ?> style="margin-top:0.25rem;accent-color:var(--vs-accent);">
            <div>
              <div style="font-weight:800;font-size:0.9rem;">مشارك (Member)</div>
              <div style="font-size:0.76rem;color:var(--vs-muted);">حضور الاجتماعات الخاصة والمساهمة في النقاشات.</div>
            </div>
          </label>
        </div>
      </div>

      <div>
        <label class="vs-label" for="regName">الاسم</label>
        <input id="regName" type="text" name="name" class="vs-input" required placeholder="الاسم الكامل">
      </div>
      <div>
        <label class="vs-label" for="regEmail">البريد الإلكتروني</label>
        <input id="regEmail" type="email" name="email" class="vs-input" required placeholder="name@example.com">
      </div>
      <div>
        <label class="vs-label" for="regPass">كلمة المرور</label>
        <input id="regPass" type="password" name="password" class="vs-input" required minlength="6" placeholder="6 أحرف على الأقل">
      </div>
      <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;">إنشاء الحساب الآن</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
