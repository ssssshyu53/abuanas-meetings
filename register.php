<?php
/**
 * Abu Anas Meetings — Host & Participant Registration
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
        $pass = (string)($_POST['password'] ?? '');
        $cnf = (string)($_POST['confirm_password'] ?? $pass);
        if ($pass !== $cnf) {
            $error = 'كلمتا المرور غير متطابقتين.';
        } else {
            $res = Auth::register(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['email'] ?? ''),
                $pass,
                $selectedType,
                (string)($_POST['workspace_name'] ?? '')
            );
            if ($res['ok']) {
                $next = trim((string)($_GET['next'] ?? ''));
                if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\')) {
                    vsRedirect($next);
                }
                vsRedirect(SITE_URL . '/dashboard.php');
            }
            $error = $res['error'] ?? 'تعذر إنشاء الحساب';
        }
    }
}

$pageTitle = 'إنشاء حساب مضيف مجاني';
require_once __DIR__ . '/includes/header.php';
?>
<div class="vs-container" style="padding:2.5rem 1.25rem;max-width:560px;">
  <div class="vs-card" style="padding:1.85rem;">
    <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:0.5rem;">
      <img src="<?php echo SITE_URL; ?>/assets/images/logo-mark.png" alt="Logo" style="width:36px;height:36px;object-fit:contain;">
      <h1 style="margin:0;font-size:1.45rem;">إنشاء مساحة اجتماعات خاصة بك</h1>
    </div>
    <p style="margin:0 0 1.2rem;color:var(--vs-muted);font-size:0.88rem;">كل مضيف يملك لوحة مستقلة، غرفه وتواريخها الخاصة، تسجيلاته، ومفاتيح الدمج في مواقعه.</p>

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
              <div style="font-size:0.76rem;color:var(--vs-muted);">مساحة مستقلة لإنشاء غرف خاصة، تسجيل فيديو وصوت، والدمج بمواقعك.</div>
            </div>
          </label>
          <label style="border:2px solid <?php echo $selectedType === 'member' ? 'var(--vs-accent)' : 'var(--vs-border)'; ?>;border-radius:13px;padding:0.75rem;cursor:pointer;display:flex;gap:0.55rem;align-items:flex-start;" onclick="this.parentElement.querySelectorAll('label').forEach(l=>l.style.borderColor='var(--vs-border)');this.style.borderColor='var(--vs-accent)';">
            <input type="radio" name="account_type" value="member" <?php echo $selectedType === 'member' ? 'checked' : ''; ?> style="margin-top:0.25rem;accent-color:var(--vs-accent);">
            <div>
              <div style="font-weight:800;font-size:0.9rem;">مشارك (Member)</div>
              <div style="font-size:0.76rem;color:var(--vs-muted);">دخول الاجتماعات المدعو لها بهوية موثقة.</div>
            </div>
          </label>
        </div>
      </div>

      <div class="vs-grid-2">
        <div>
          <label class="vs-label" for="regName">الاسم الشخصي</label>
          <input id="regName" type="text" name="name" class="vs-input" required placeholder="أحمد محمد">
        </div>
        <div>
          <label class="vs-label" for="regWs">اسم مساحتك / شركتك</label>
          <input id="regWs" type="text" name="workspace_name" class="vs-input" placeholder="اختياري: أكاديميتي / شركتي">
        </div>
      </div>

      <div>
        <label class="vs-label" for="regEmail">البريد الإلكتروني</label>
        <input id="regEmail" type="email" name="email" class="vs-input" required placeholder="name@example.com">
      </div>

      <div class="vs-grid-2">
        <div>
          <label class="vs-label" for="regPass">كلمة المرور (8 أحرف+)</label>
          <input id="regPass" type="password" name="password" class="vs-input" required minlength="8" placeholder="••••••••">
        </div>
        <div>
          <label class="vs-label" for="regPassCnf">تأكيد كلمة المرور</label>
          <input id="regPassCnf" type="password" name="confirm_password" class="vs-input" required minlength="8" placeholder="••••••••">
        </div>
      </div>

      <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;">إنشاء الحساب وبدء اجتماعاتك</button>
    </form>

    <div style="margin-top:1rem;padding-top:0.9rem;border-top:1px solid var(--vs-border);font-size:0.8rem;color:var(--vs-muted);text-align:center;">
      لديك حساب بالفعل؟ <a href="<?php echo SITE_URL; ?>/login.php" style="color:var(--vs-accent);font-weight:700;">تسجيل الدخول</a>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
