<?php
/**
 * Abu Anas Meetings — Shared Layout Header
 */
declare(strict_types=1);
$vsUser = Auth::currentUser();
$vsPageTitle = ($pageTitle ?? 'أبو أنس ميتينجز — اجتماعات فيديو وصوت مفتوحة المصدر') . ' | أبو أنس ميتينجز';
$curScript = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
$userRole = (string)($vsUser['role'] ?? '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo vsEsc($vsPageTitle); ?></title>
  <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/favicon.png">
  <script>
    (function () {
      var saved = localStorage.getItem('vs_theme');
      var dark = saved === 'dark' || (!saved && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      document.documentElement.classList.toggle('dark', dark);
    })();
  </script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@dawod/thmanyah-font-web@1.2.0/index.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/app.css?v=3">
  <script src="<?php echo SITE_URL; ?>/assets/js/sweetalert2.all.min.js"></script>
</head>
<body>
<header class="vs-navbar">
  <div class="vs-container vs-navbar-inner">
    <a href="<?php echo SITE_URL; ?>/index.php" class="vs-brand">
      <img src="<?php echo SITE_URL; ?>/assets/images/logo-mark.png" alt="Abu Anas Meetings" style="width:34px;height:34px;object-fit:contain;">
      <span>أبو أنس ميتينجز</span>
    </a>

    <nav class="vs-nav-links" id="vsMainNav">
      <a href="<?php echo SITE_URL; ?>/index.php" class="vs-nav-link <?php echo $curScript === 'index.php' ? 'active' : ''; ?>">الرئيسية</a>
      <?php if ($vsUser): ?>
        <a href="<?php echo SITE_URL; ?>/dashboard.php" class="vs-nav-link <?php echo $curScript === 'dashboard.php' ? 'active' : ''; ?>">
          <?php echo in_array($userRole, ['host', 'admin'], true) ? 'إدارة الاجتماعات' : 'لوحتي'; ?>
        </a>
        <?php if (in_array($userRole, ['host', 'admin'], true)): ?>
          <a href="<?php echo SITE_URL; ?>/embed.php" class="vs-nav-link <?php echo $curScript === 'embed.php' ? 'active' : ''; ?>">الدمج في منصتك</a>
        <?php endif; ?>
        <?php if ($userRole === 'admin'): ?>
          <a href="<?php echo SITE_URL; ?>/admin/users.php" class="vs-nav-link <?php echo $curScript === 'users.php' ? 'active' : ''; ?>">المستخدمون</a>
          <a href="<?php echo SITE_URL; ?>/admin/providers.php" class="vs-nav-link <?php echo $curScript === 'providers.php' ? 'active' : ''; ?>">المزودون</a>
          <a href="<?php echo SITE_URL; ?>/admin/monitor.php" class="vs-nav-link <?php echo $curScript === 'monitor.php' ? 'active' : ''; ?>">المراقبة</a>
        <?php endif; ?>
      <?php endif; ?>

      <div class="vs-mobile-only-actions">
        <?php if ($vsUser): ?>
          <a href="<?php echo SITE_URL; ?>/dashboard.php" class="vs-btn vs-btn-primary" style="width:100%;">
            <i class="fas <?php echo in_array($userRole, ['host', 'admin'], true) ? 'fa-plus' : 'fa-arrow-up'; ?>"></i>
            <span><?php echo in_array($userRole, ['host', 'admin'], true) ? 'اجتماع جديد' : 'ترقية لمضيف'; ?></span>
          </a>
          <a href="<?php echo SITE_URL; ?>/logout.php" class="vs-btn vs-btn-outline" style="width:100%;">تسجيل الخروج</a>
        <?php else: ?>
          <a href="<?php echo SITE_URL; ?>/register.php" class="vs-btn vs-btn-primary" style="width:100%;">حساب مضيف مجاني</a>
          <a href="<?php echo SITE_URL; ?>/login.php" class="vs-btn vs-btn-outline" style="width:100%;">تسجيل الدخول</a>
        <?php endif; ?>
      </div>
    </nav>

    <div class="vs-nav-actions">
      <button type="button" id="vsThemeBtn" onclick="vsToggleTheme()" class="vs-btn-icon" title="تبديل المظهر">
        <i class="fas fa-moon" id="vsThemeIcon"></i>
      </button>
      <?php if ($vsUser): ?>
        <a href="<?php echo SITE_URL; ?>/dashboard.php" class="vs-btn vs-btn-primary vs-nav-desktop-only">
          <i class="fas <?php echo in_array($userRole, ['host', 'admin'], true) ? 'fa-plus' : 'fa-arrow-up'; ?>"></i>
          <span><?php echo in_array($userRole, ['host', 'admin'], true) ? 'اجتماع جديد' : 'ترقية لمضيف'; ?></span>
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="vs-btn vs-btn-outline vs-nav-desktop-only" title="تسجيل الخروج">خروج</a>
      <?php else: ?>
        <a href="<?php echo SITE_URL; ?>/login.php" class="vs-btn vs-btn-outline vs-nav-desktop-only">دخول</a>
        <a href="<?php echo SITE_URL; ?>/register.php" class="vs-btn vs-btn-primary vs-nav-desktop-only">حساب مضيف مجاني</a>
      <?php endif; ?>
      <button type="button" class="vs-btn-icon vs-mobile-toggle" onclick="document.getElementById('vsMainNav').classList.toggle('open')" aria-label="القائمة">
        <i class="fas fa-bars"></i>
      </button>
    </div>
  </div>
</header>
<main>
