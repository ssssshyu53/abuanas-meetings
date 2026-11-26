<?php
/**
 * Abu Anas Meetings — Open Source Meetings Landing & Directory
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

$db = Database::getInstance()->connect();
$liveSt = $db->prepare("
    SELECT r.*, u.name AS host_name,
           (SELECT COUNT(*) FROM room_participants p WHERE p.room_id = r.id AND p.last_seen_at >= ?) AS online_count
    FROM rooms r
    LEFT JOIN users u ON u.id = r.host_user_id
    WHERE r.status = 'live' AND r.access_mode = 'public'
    ORDER BY r.id DESC
    LIMIT 12
");
$liveSt->execute([Database::cutoff(45)]);
$liveRooms = $liveSt->fetchAll();
$presets = RoomService::presetLabels();
$pageTitle = 'اجتماعات فيديو وصوت مفتوحة المصدر ومجانية';
require_once __DIR__ . '/includes/header.php';
?>

<section class="vs-hero">
  <div class="vs-container vs-hero-grid">
    <div>
      <h1>اجتماعات فيديو وصوت مجانية ومفتوحة المصدر</h1>
      <p class="vs-hero-sub">
        أنشئ اجتماعك أو مجلسك المباشر فوراً وشاركه برابط واحد، أو ادمجه داخل منصتك وموقعك بسهولة تامة وبلا أي تعقيد.
      </p>
      <div class="vs-hero-ctas">
        <a href="<?php echo Auth::canHost() ? SITE_URL . '/dashboard.php' : SITE_URL . '/register.php'; ?>" class="vs-btn vs-btn-primary">
          <i class="fas fa-video"></i>
          <span><?php echo Auth::canHost() ? 'ابدأ اجتماعاً الآن' : 'ابدأ اجتماعاً مجانياً'; ?></span>
        </a>
        <a href="#live-rooms" class="vs-btn vs-btn-outline">
          <i class="fas fa-headphones"></i>
          <span>الاجتماعات النشطة (<?php echo count($liveRooms); ?>)</span>
        </a>
      </div>
    </div>

    <div class="vs-stage-preview">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:0.9rem;">
        <div style="display:flex;align-items:center;gap:0.55rem;">
          <img src="<?php echo SITE_URL; ?>/assets/images/logo-mark.png" alt="Logo" style="width:28px;height:28px;object-fit:contain;">
          <span style="font-weight:800;font-size:0.96rem;">انضمام سريع برمز الاجتماع</span>
        </div>
        <span class="vs-badge vs-badge-live"><span style="width:7px;height:7px;border-radius:50%;background:currentColor;"></span>جاهز الآن</span>
      </div>
      <form action="<?php echo SITE_URL; ?>/room.php" method="GET" style="display:flex;gap:0.5rem;margin-bottom:1.1rem;">
        <input type="text" name="code" class="vs-input mono" placeholder="أدخل رمز الاجتماع (مثلاً A7K9P2)" required maxlength="16" style="text-transform:uppercase;">
        <button type="submit" class="vs-btn vs-btn-primary">دخول</button>
      </form>
      <div style="border-top:1px solid var(--vs-border);padding-top:0.95rem;display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;font-size:0.82rem;">
        <div style="padding:0.65rem;border-radius:12px;background:var(--vs-subtle);">
          <div style="color:var(--vs-muted);font-size:0.74rem;">متاح للجميع</div>
          <div style="font-weight:800;margin-top:0.15rem;">مفتوحة المصدر ومجانية</div>
        </div>
        <div style="padding:0.65rem;border-radius:12px;background:var(--vs-subtle);">
          <div style="color:var(--vs-muted);font-size:0.74rem;">مرونة كاملة</div>
          <div style="font-weight:800;margin-top:0.15rem;">دمج فوري داخل منصاتك</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="vs-container" style="margin-bottom:3rem;">
  <div class="vs-bento">
    <div class="vs-card vs-bento-wide" style="background:linear-gradient(135deg, var(--vs-accent-soft), var(--vs-surface));">
      <h2 style="margin:0 0 0.45rem;font-size:1.25rem;">مستقلة بذاتها.. وقابلة للدمج في أي موقع</h2>
      <p style="margin:0;color:var(--vs-muted);font-size:0.9rem;max-width:60ch;">
        أنشئ حساب مضيف مجاني وابدأ اجتماعاتك مباشرة من هنا، أو احصل على مفتاح الربط وكود التضمين لتشغيل نفس الغرف داخل أكاديميتك أو متجرك أو موقع فريقك.
      </p>
    </div>

    <div class="vs-card">
      <div style="width:38px;height:38px;border-radius:10px;background:var(--vs-accent-soft);color:var(--vs-accent);display:flex;align-items:center;justify-content:center;margin-bottom:0.75rem;">
        <i class="fas fa-chalkboard-teacher"></i>
      </div>
      <h3 style="margin:0 0 0.35rem;font-size:1.02rem;">سبورة وشرائح متزامنة</h3>
      <p style="margin:0;color:var(--vs-muted);font-size:0.84rem;">ارسم واشرح مباشرة مع مؤشر ليزر وعرض الشرائح أمام الحضور في الوقت الفعلي.</p>
    </div>

    <div class="vs-card">
      <div style="width:38px;height:38px;border-radius:10px;background:rgba(16,185,129,0.12);color:var(--vs-emerald);display:flex;align-items:center;justify-content:center;margin-bottom:0.75rem;">
        <i class="fas fa-microphone-alt"></i>
      </div>
      <h3 style="margin:0 0 0.35rem;font-size:1.02rem;">صوت وفيديو فائق النقاء</h3>
      <p style="margin:0;color:var(--vs-muted);font-size:0.84rem;">تجربة سلسة على أضعف اتصال إنترنت مع نظام رفع اليد وإدارة المداخلات والدردشة والتصويت.</p>
    </div>

    <div class="vs-card vs-bento-wide">
      <h3 style="margin:0 0 0.75rem;font-size:1.05rem;">تناسب كل الاستخدامات</h3>
      <div class="vs-grid-2">
        <?php foreach ($presets as $pr): ?>
          <div style="padding:0.75rem;border:1px solid var(--vs-border);border-radius:12px;display:flex;align-items:flex-start;gap:0.65rem;">
            <i class="fas <?php echo vsEsc($pr['icon']); ?>" style="color:var(--vs-accent);margin-top:0.2rem;"></i>
            <div>
              <div style="font-weight:700;font-size:0.88rem;"><?php echo vsEsc($pr['title']); ?></div>
              <div style="font-size:0.78rem;color:var(--vs-muted);"><?php echo vsEsc($pr['desc']); ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<section id="live-rooms" class="vs-container">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1rem;">
    <h2 style="margin:0;font-size:1.28rem;">الاجتماعات العامة النشطة الآن</h2>
    <a href="<?php echo Auth::isLoggedIn() ? SITE_URL . '/dashboard.php' : SITE_URL . '/login.php'; ?>" class="vs-btn vs-btn-outline">إدارة اجتماعاتي</a>
  </div>

  <?php if (empty($liveRooms)): ?>
    <div class="vs-card" style="text-align:center;padding:2.4rem 1.2rem;">
      <p style="margin:0 0 1rem;color:var(--vs-muted);">لا توجد اجتماعات عامة مفتوحة الآن.</p>
      <a href="<?php echo Auth::canHost() ? SITE_URL . '/dashboard.php' : SITE_URL . '/register.php'; ?>" class="vs-btn vs-btn-primary">أنشئ اجتماعك الأول مجاناً</a>
    </div>
  <?php else: ?>
    <div class="vs-grid-3">
      <?php foreach ($liveRooms as $rm):
        $pInfo = $presets[$rm['preset']] ?? ['title' => $rm['preset']];
      ?>
        <div class="vs-card" style="display:flex;flex-direction:column;justify-content:space-between;gap:0.85rem;">
          <div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.4rem;margin-bottom:0.55rem;">
              <span class="vs-badge vs-badge-live">مباشر • <?php echo (int)$rm['online_count']; ?> مشارك</span>
              <span class="vs-badge vs-badge-blue"><?php echo ($rm['media_mode'] === 'video') ? 'فيديو وصوت' : 'صوت وسبورة'; ?></span>
            </div>
            <h3 style="margin:0 0 0.3rem;font-size:1.05rem;"><?php echo vsEsc($rm['title']); ?></h3>
            <div style="font-size:0.8rem;color:var(--vs-muted);">
              المضيف: <?php echo vsEsc($rm['host_name'] ?: 'مضيف'); ?> • <?php echo vsEsc($pInfo['title']); ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;padding-top:0.65rem;border-top:1px solid var(--vs-border);">
            <span class="mono" style="font-size:0.78rem;color:var(--vs-muted);"><?php echo vsEsc($rm['code']); ?></span>
            <a href="<?php echo SITE_URL; ?>/room.php?code=<?php echo urlencode($rm['code']); ?>" class="vs-btn vs-btn-primary" style="height:34px;padding:0 0.9rem;font-size:0.8rem;">انضمام</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
