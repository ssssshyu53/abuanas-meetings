<?php
/**
 * Abu Anas Meetings — Jitsi-Style Standalone Meeting Launcher (Private Multi-Host SaaS)
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

$me = Auth::currentUser();
$canHost = Auth::canHost();
$presets = RoomService::presetLabels();
$pageTitle = 'اجتماعات فيديو وصوت خاصة ومفتوحة المصدر';
require_once __DIR__ . '/includes/header.php';
?>

<section class="vs-hero">
  <div class="vs-container vs-hero-grid">
    <div>
      <span class="vs-badge vs-badge-blue" style="margin-bottom:0.85rem;">
        <i class="fas fa-lock"></i>
        <span>مساحة اجتماعات مستقلة لكل مضيف — بأسلوب Jitsi</span>
      </span>
      <h1>ابدأ اجتماعك الخاص الآن، أو انضم برابط الدعوة</h1>
      <p class="vs-hero-sub">
        أنشئ غرف فيديو وصوت خاصة بك وبفريقك مع تسجيل كامل للاجتماع وسبورة تفاعلية، أو ادمج المنصة داخل مواقعك بخصوصية تامة حيث لكل مضيف غرفه وتسجيلاته المستقلة.
      </p>
      <div class="vs-hero-ctas">
        <?php if ($canHost): ?>
          <a href="<?php echo SITE_URL; ?>/dashboard.php" class="vs-btn vs-btn-primary">
            <i class="fas fa-plus"></i>
            <span>لوحة اجتماعاتي</span>
          </a>
          <a href="<?php echo SITE_URL; ?>/embed.php" class="vs-btn vs-btn-outline">
            <i class="fas fa-code"></i>
            <span>الدمج في منصتك</span>
          </a>
        <?php else: ?>
          <a href="<?php echo SITE_URL; ?>/register.php" class="vs-btn vs-btn-primary">
            <i class="fas fa-user-plus"></i>
            <span>أنشئ حساب مضيف مجاني</span>
          </a>
          <a href="<?php echo SITE_URL; ?>/login.php" class="vs-btn vs-btn-outline">
            <i class="fas fa-sign-in-alt"></i>
            <span>تسجيل الدخول</span>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Jitsi-Style Two-Tab Launcher Card -->
    <div class="vs-stage-preview">
      <div style="display:flex;gap:0.4rem;background:var(--vs-subtle);padding:0.35rem;border-radius:12px;margin-bottom:1rem;">
        <button type="button" id="tabBtnStart" onclick="switchLauncherTab('start')" class="vs-btn vs-btn-primary" style="flex:1;height:36px;border-radius:9px;font-size:0.84rem;">بدء اجتماع جديد</button>
        <button type="button" id="tabBtnJoin" onclick="switchLauncherTab('join')" class="vs-btn vs-btn-outline" style="flex:1;height:36px;border-radius:9px;font-size:0.84rem;border-color:transparent;background:transparent;">انضمام بدعوة</button>
      </div>

      <!-- Pane 1: Start Instant Private Meeting -->
      <div id="launcherStartPane">
        <?php if ($canHost): ?>
          <form action="<?php echo SITE_URL; ?>/dashboard.php" method="POST" style="display:flex;flex-direction:column;gap:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
            <input type="hidden" name="action" value="create_room">
            <input type="hidden" name="preset" value="meeting">
            <input type="hidden" name="access_mode" value="code">
            <div>
              <label class="vs-label" for="quickTitle">اسم الاجتماع</label>
              <input id="quickTitle" type="text" name="title" class="vs-input" required placeholder="مثال: اجتماع الفريق الصباحي">
            </div>
            <div class="vs-grid-2" style="gap:0.65rem;">
              <div>
                <label class="vs-label" for="quickMedia">نوع البث</label>
                <select id="quickMedia" name="media_mode" class="vs-select">
                  <option value="video">فيديو وصوت وشاشة</option>
                  <option value="audio">صوت وسبورة تفاعلية</option>
                </select>
              </div>
              <div>
                <label class="vs-label" for="quickPass">رمز حماية (اختياري)</label>
                <input id="quickPass" type="text" name="passcode" class="vs-input mono" maxlength="32" placeholder="بدون رمز">
              </div>
            </div>
            <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;">
              <i class="fas fa-video"></i>
              <span>بدء الاجتماع والدخول فوراً</span>
            </button>
          </form>
        <?php else: ?>
          <div style="text-align:center;padding:0.9rem 0.4rem;">
            <div style="width:46px;height:46px;border-radius:14px;background:var(--vs-accent-soft);color:var(--vs-accent);display:flex;align-items:center;justify-content:center;margin:0 auto 0.7rem;font-size:1.15rem;">
              <i class="fas fa-shield-alt"></i>
            </div>
            <h3 style="margin:0 0 0.35rem;font-size:1.05rem;">امتلك مساحة اجتماعاتك الخاصة</h3>
            <p style="margin:0 0 1rem;color:var(--vs-muted);font-size:0.84rem;">سجّل حساب مضيف مجاني خلال ثوانٍ لتحصل على غرف خاصة بك، تحكم كامل، وتسجيل فيديو وصوت.</p>
            <div style="display:flex;gap:0.55rem;flex-wrap:wrap;justify-content:center;">
              <a href="<?php echo SITE_URL; ?>/register.php" class="vs-btn vs-btn-primary">إنشاء حساب مضيف</a>
              <a href="<?php echo SITE_URL; ?>/login.php" class="vs-btn vs-btn-outline">لدي حساب بالفعل</a>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Pane 2: Join by Code or Full Invite Link -->
      <div id="launcherJoinPane" style="display:none;">
        <form onsubmit="return handleSmartJoin(event)" style="display:flex;flex-direction:column;gap:0.75rem;">
          <div>
            <label class="vs-label" for="joinInput">رمز الاجتماع أو رابط الدعوة الكامل</label>
            <input id="joinInput" type="text" class="vs-input mono" required placeholder="أدخل الرمز مثلاً A7K9P2 أو الصق الرابط...">
          </div>
          <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;">
            <i class="fas fa-arrow-left"></i>
            <span>الانتقال لغرفة الانتظار (Lobby)</span>
          </button>
        </form>
      </div>

      <!-- Device-Local Private Recent Meetings (Stored only in this browser) -->
      <div id="localRecentWrap" style="display:none;margin-top:1rem;padding-top:0.85rem;border-top:1px solid var(--vs-border);">
        <div style="font-size:0.78rem;font-weight:800;color:var(--vs-muted);margin-bottom:0.45rem;">اجتماعاتك الأخيرة على هذا الجهاز</div>
        <div id="localRecentList" style="display:flex;flex-direction:column;gap:0.35rem;"></div>
      </div>
    </div>
  </div>
</section>

<section class="vs-container" style="margin-bottom:2rem;">
  <div class="vs-bento">
    <div class="vs-card vs-bento-wide" style="background:linear-gradient(135deg, var(--vs-accent-soft), var(--vs-surface));">
      <h2 style="margin:0 0 0.45rem;font-size:1.25rem;">خصوصية كاملة لكل مضيف.. وقابلية الدمج في أي موقع</h2>
      <p style="margin:0;color:var(--vs-muted);font-size:0.9rem;max-width:62ch;">
        لا تُعرض أي غرف خاصة على العامة؛ كل مضيف يدير غرفه وتسجيلاته ومفاتيح الـ API الخاصة به، مع إمكانية حماية أي اجتماع برمز دخول وتضمينه بسطر واحد في منصاتك.
      </p>
    </div>

    <div class="vs-card">
      <div style="width:38px;height:38px;border-radius:10px;background:var(--vs-accent-soft);color:var(--vs-accent);display:flex;align-items:center;justify-content:center;margin-bottom:0.75rem;">
        <i class="fas fa-film"></i>
      </div>
      <h3 style="margin:0 0 0.35rem;font-size:1.02rem;">تسجيل فيديو وصوت كامل</h3>
      <p style="margin:0;color:var(--vs-muted);font-size:0.84rem;">سجّل الاجتماع فيديو مع الصوت والسبورة، واحفظه في لوحتك الخاصة أو حمّله مباشرة.</p>
    </div>

    <div class="vs-card">
      <div style="width:38px;height:38px;border-radius:10px;background:rgba(16,185,129,0.12);color:var(--vs-emerald);display:flex;align-items:center;justify-content:center;margin-bottom:0.75rem;">
        <i class="fas fa-key"></i>
      </div>
      <h3 style="margin:0 0 0.35rem;font-size:1.02rem;">غرف خاصة برمز حماية</h3>
      <p style="margin:0;color:var(--vs-muted);font-size:0.84rem;">صلاحيات المضيف لصاحب الغرفة فقط، مع غرفة انتظار (Lobby) وتصريح دخول برمز حماية.</p>
    </div>

    <div class="vs-card vs-bento-wide">
      <h3 style="margin:0 0 0.75rem;font-size:1.05rem;">جاهزة لكل سيناريوهات العمل والتعليم</h3>
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

<script>
function switchLauncherTab(tab) {
  var isStart = tab === 'start';
  document.getElementById('launcherStartPane').style.display = isStart ? 'block' : 'none';
  document.getElementById('launcherJoinPane').style.display = isStart ? 'none' : 'block';
  var b1 = document.getElementById('tabBtnStart');
  var b2 = document.getElementById('tabBtnJoin');
  b1.className = isStart ? 'vs-btn vs-btn-primary' : 'vs-btn vs-btn-outline';
  b2.className = !isStart ? 'vs-btn vs-btn-primary' : 'vs-btn vs-btn-outline';
  if (isStart) { b2.style.background = 'transparent'; b2.style.borderColor = 'transparent'; b1.style.background = ''; b1.style.borderColor = ''; }
  else { b1.style.background = 'transparent'; b1.style.borderColor = 'transparent'; b2.style.background = ''; b2.style.borderColor = ''; }
}
function handleSmartJoin(ev) {
  ev.preventDefault();
  var raw = (document.getElementById('joinInput').value || '').trim();
  if (!raw) return false;
  var m = raw.match(/[?&]code=([a-zA-Z0-9_-]+)/i);
  var code = m ? m[1] : raw.replace(/[^a-zA-Z0-9_-]/g, '');
  if (!code) return false;
  window.location.href = <?php echo json_encode(SITE_URL . '/room.php?code='); ?> + encodeURIComponent(code.toUpperCase());
  return false;
}
(function () {
  try {
    var list = JSON.parse(localStorage.getItem('aam_recent_rooms') || '[]');
    if (!Array.isArray(list) || !list.length) return;
    var wrap = document.getElementById('localRecentWrap');
    var box = document.getElementById('localRecentList');
    wrap.style.display = 'block';
    list.slice(0, 4).forEach(function (item) {
      var a = document.createElement('a');
      a.href = <?php echo json_encode(SITE_URL . '/room.php?code='); ?> + encodeURIComponent(item.code);
      a.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:0.45rem 0.65rem;border-radius:10px;background:var(--vs-subtle);font-size:0.8rem;';
      var sp1 = document.createElement('span');
      sp1.style.fontWeight = '700';
      sp1.textContent = item.title || item.code;
      var sp2 = document.createElement('span');
      sp2.className = 'mono';
      sp2.style.color = 'var(--vs-accent)';
      sp2.textContent = item.code;
      a.appendChild(sp1);
      a.appendChild(sp2);
      box.appendChild(a);
    });
  } catch (e) {}
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
