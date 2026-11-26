<?php
/**
 * Abu Anas Meetings — Login Page
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

if (Auth::isLoggedIn()) {
    vsRedirect(SITE_URL . '/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vsRateLimit('login', 10, 300)) {
        $error = 'تم تجاوز الحد المسموح لمحاولات الدخول، انتظر قليلاً ثم حاول مجدداً.';
    } elseif (!vsVerifyCsrf()) {
        $error = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
    } else {
        $res = Auth::login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($res['ok']) {
            $next = trim((string)($_GET['next'] ?? ''));
            if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\')) {
                vsRedirect($next);
            }
            vsRedirect(SITE_URL . '/dashboard.php');
        }
        $error = $res['error'] ?? 'تعذر تسجيل الدخول';
    }
}

$pageTitle = 'تسجيل الدخول';
require_once __DIR__ . '/includes/header.php';
?>
<div class="vs-container" style="padding:3rem 1.25rem;max-width:470px;">
  <div class="vs-card" style="padding:1.85rem;">
    <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:0.75rem;">
      <img src="<?php echo SITE_URL; ?>/assets/images/logo-mark.png" alt="Logo" style="width:36px;height:36px;object-fit:contain;">
      <h1 style="margin:0;font-size:1.45rem;">تسجيل الدخول</h1>
    </div>
    <p style="margin:0 0 1.25rem;color:var(--vs-muted);font-size:0.88rem;">ادخل لحسابك لإدارة اجتماعاتك أو استخدام أدوات الدمج.</p>

    <?php if ($error !== ''): ?>
      <div style="padding:0.7rem 0.9rem;border-radius:11px;background:rgba(225,29,72,0.12);color:var(--vs-rose);font-size:0.85rem;font-weight:700;margin-bottom:1rem;"><?php echo vsEsc($error); ?></div>
    <?php endif; ?>

    <form method="POST" style="display:flex;flex-direction:column;gap:0.9rem;">
      <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
      <div>
        <label class="vs-label" for="loginEmail">البريد الإلكتروني</label>
        <input id="loginEmail" type="email" name="email" class="vs-input" required placeholder="host@abuanas.local">
      </div>
      <div>
        <label class="vs-label" for="loginPass">كلمة المرور</label>
        <input id="loginPass" type="password" name="password" class="vs-input" required placeholder="••••••••">
      </div>
      <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;">دخول</button>
    </form>

    <div style="margin-top:1.15rem;padding-top:1rem;border-top:1px solid var(--vs-border);font-size:0.78rem;color:var(--vs-muted);display:flex;flex-direction:column;gap:0.35rem;">
      <?php if (vsEnv('APP_ENV', 'local') === 'local'): ?>
        <div>حساب مضيف تجريبي: <code class="mono">host@abuanas.local</code> / <code class="mono">Host@2026</code></div>
        <div>حساب مدير النظام: <code class="mono">admin@abuanas.local</code> / <code class="mono">Admin@2026</code></div>
      <?php endif; ?>
      <div style="margin-top:0.25rem;">ليس لديك حساب؟ <a href="<?php echo SITE_URL; ?>/register.php" style="color:var(--vs-accent);font-weight:700;">إنشاء حساب جديد مجاناً</a></div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
