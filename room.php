<?php
/**
 * Abu Anas Meetings — Standalone & Embeddable Live Room (100dvh x 100vw)
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

$isEmbed = !empty($_GET['embed']);
vsSendSecurityHeaders($isEmbed);

$db = Database::getInstance()->connect();
$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$room = RoomService::getByCode($db, $code);

if (!$room) {
    $pageTitle = 'غرفة غير موجودة';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="vs-container" style="padding:4rem 1.25rem;text-align:center;"><div class="vs-card" style="max-width:460px;margin:0 auto;"><h2>تعذر العثور على الاجتماع</h2><p style="color:var(--vs-muted);">تأكد من صحة الرمز وحاول مجدداً.</p><a href="' . SITE_URL . '/index.php" class="vs-btn vs-btn-primary">العودة للرئيسية</a></div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if (($room['status'] ?? '') === 'ended') {
    $pageTitle = 'انتهى الاجتماع';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="vs-container" style="padding:4rem 1.25rem;text-align:center;"><div class="vs-card" style="max-width:460px;margin:0 auto;"><h2>انتهى هذا الاجتماع</h2><p style="color:var(--vs-muted);">تم إغلاق البث في غرفة ' . vsEsc($room['title']) . '.</p><a href="' . SITE_URL . '/index.php" class="vs-btn vs-btn-primary">استعراض الاجتماعات النشطة</a></div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Guest entry handler (including seamless ?name= auto-join when embedded)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['guest_action'] ?? '') === 'enter') {
    if (vsVerifyCsrf()) {
        Auth::setGuestName((string)($_POST['guest_name'] ?? ''));
        vsRedirect(SITE_URL . '/room.php?code=' . urlencode($room['code']) . ($isEmbed ? '&embed=1' : ''));
    }
}

$actor = Auth::resolveActor($room);
if ($actor === null && $isEmbed && trim((string)($_GET['name'] ?? '')) !== '' && ($room['access_mode'] ?? 'public') !== 'registered') {
    Auth::setGuestName((string)$_GET['name']);
    $actor = Auth::resolveActor($room);
}

if ($actor === null) {
    if (($room['access_mode'] ?? 'public') === 'registered') {
        vsRedirect(SITE_URL . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? SITE_URL));
    }
    $pageTitle = 'انضمام للاجتماع — ' . $room['title'];
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="vs-container" style="padding:3rem 1.25rem;max-width:450px;">
      <div class="vs-card" style="padding:1.75rem;">
        <span class="vs-badge vs-badge-live" style="margin-bottom:0.75rem;">اجتماع مباشر • <?php echo vsEsc($room['code']); ?></span>
        <h1 style="margin:0 0 0.4rem;font-size:1.35rem;"><?php echo vsEsc($room['title']); ?></h1>
        <p style="margin:0 0 1.2rem;color:var(--vs-muted);font-size:0.86rem;">أدخل اسمك للدخول الفوري إلى الاجتماع.</p>
        <form method="POST" style="display:flex;flex-direction:column;gap:0.85rem;">
          <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
          <input type="hidden" name="guest_action" value="enter">
          <div>
            <label class="vs-label" for="guestNameInp">الاسم الظاهر</label>
            <input id="guestNameInp" type="text" name="guest_name" class="vs-input" required maxlength="50" placeholder="اكتب اسمك...">
          </div>
          <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;">دخول الاجتماع الآن</button>
        </form>
        <?php if (!$isEmbed): ?>
        <div style="margin-top:1rem;font-size:0.8rem;color:var(--vs-muted);text-align:center;">
          لديك حساب؟ <a href="<?php echo SITE_URL; ?>/login.php?next=<?php echo urlencode(SITE_URL . '/room.php?code=' . $room['code']); ?>" style="color:var(--vs-accent);font-weight:700;">تسجيل الدخول</a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$isHost = !empty($actor['is_host']);
$initialElapsed = $room['started_at'] ? max(0, time() - strtotime((string)$room['started_at'])) : 0;
$shareUrl = SITE_URL . '/room.php?code=' . urlencode((string)$room['code']);
$hostTitleDisplay = (string)($room['host_name'] ?: 'مضيف الاجتماع');
$hostInitial = mb_substr($hostTitleDisplay, 0, 1);
$csrf = vsCsrfToken();
$forcedTheme = in_array($_GET['theme'] ?? '', ['light', 'dark'], true) ? $_GET['theme'] : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo vsEsc($room['title']); ?> | أبو أنس ميتينجز</title>
  <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/favicon.png">
  <script>
    (function () {
      var forced = <?php echo json_encode($forcedTheme); ?>;
      var saved = forced || localStorage.getItem('vs_theme');
      var dark = saved === 'dark' || (!saved && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      document.documentElement.classList.toggle('dark', dark);
    })();
  </script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@dawod/thmanyah-font-web@1.2.0/index.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/room.css?v=3">
  <script src="<?php echo SITE_URL; ?>/assets/js/sweetalert2.all.min.js"></script>
</head>
<body>
<div id="voxRoomRoot">
  <!-- Pinned Top Header -->
  <div id="roomHeaderBar">
    <div class="vs-h-right">
      <div class="vs-h-logo" style="background:transparent;padding:0;" aria-hidden="true">
        <img src="<?php echo SITE_URL; ?>/assets/images/logo-mark.png" alt="Abu Anas Meetings" style="width:34px;height:34px;object-fit:contain;">
      </div>
      <div class="vs-h-title-block">
        <div class="vs-h-title-row">
          <h2 id="roomTitleHeading"><?php echo vsEsc($room['title']); ?></h2>
          <?php if ($isHost): ?>
          <button type="button" onclick="vsEditTitle()" class="vs-icon-mini" title="تعديل عنوان الغرفة"><i class="fas fa-pen" style="font-size:0.7rem;"></i></button>
          <?php endif; ?>
        </div>
        <div class="vs-h-meta">
          <span style="display:inline-flex;align-items:center;gap:0.32rem;"><span class="vs-rec-dot"></span><span>مباشر <span id="meetingElapsedTimer" style="font-family:var(--vs-font-mono);"><?php echo gmdate('H:i:s', $initialElapsed); ?></span></span></span>
          <span>• <span id="meetingOnlineCount">1</span> متصل</span>
        </div>
      </div>
    </div>

    <div class="vs-h-actions">
      <?php if ($isHost): ?>
      <button type="button" id="headerHandBadge" onclick="vsFocusHands()" class="hidden vs-pill-btn is-hand-alert">
        <i class="fas fa-hand-paper"></i>
        <span><span id="headerHandCount">0</span> <span class="vs-pill-label">يطلب المداخلة</span></span>
      </button>
      <?php endif; ?>

      <button type="button" id="wbToggleBtn" onclick="vsToggleWhiteboard()" class="vs-pill-btn <?php echo (int)$room['whiteboard_enabled'] === 1 ? 'is-active' : ''; ?>" title="السبورة والشرائح">
        <i class="fas fa-chalkboard"></i>
        <span class="vs-pill-label">السبورة</span>
      </button>

      <button type="button" id="chatToggleBtn" onclick="vsToggleChat()" class="vs-pill-btn is-active" title="المحادثة والاستطلاعات">
        <i class="fas fa-comments"></i>
        <span class="vs-pill-label">المحادثة</span>
      </button>

      <div id="shareMenuWrap">
        <button type="button" onclick="vsToggleShare(event)" class="vs-pill-btn" title="مشاركة">
          <i class="fas fa-share-alt" style="color:var(--vs-accent);"></i>
          <span class="vs-pill-label">مشاركة</span>
        </button>
        <div id="shareMenuDropdown" class="hidden">
          <button type="button" onclick="vsShare('whatsapp')" class="vs-share-opt"><i class="fab fa-whatsapp" style="color:#10B981;"></i> مشاركة عبر واتساب</button>
          <button type="button" onclick="vsShare('telegram')" class="vs-share-opt"><i class="fab fa-telegram-plane" style="color:#0EA5E9;"></i> مشاركة عبر تليجرام</button>
          <button type="button" onclick="vsShare('copy')" class="vs-share-opt"><i class="fas fa-link" style="color:var(--vs-accent);"></i> نسخ رابط الغرفة</button>
        </div>
      </div>
    </div>
  </div>

  <div id="roomBodyWrap">
    <!-- Center Stage + Pinned Dock -->
    <div id="stageCol">
      <div class="vs-lounge-scroll">
        <div id="pinnedBanner" class="hidden">
          <div style="min-width:0;">
            <div style="font-size:0.76rem;font-weight:800;color:var(--vs-accent);"><i class="fas fa-thumbtack"></i> رسالة مثبّتة من <span id="pinnedSender"></span></div>
            <div id="pinnedBody" style="font-size:0.86rem;margin-top:0.15rem;"></div>
          </div>
          <?php if ($isHost): ?>
          <button type="button" onclick="vsUnpinMessage()" class="vs-icon-mini" title="إلغاء التثبيت"><i class="fas fa-times"></i></button>
          <?php endif; ?>
        </div>

        <?php if ($isHost): ?>
        <div id="hostMicBanner" class="hidden vs-unlock-banner amber">
          <div>
            <div style="font-weight:800;font-size:0.88rem;">اضغط لتشغيل الميكروفون وبدء البث الصوتي</div>
            <div style="font-size:0.78rem;color:var(--vs-muted);">يتطلب المتصفح تأكيداً يدوياً لالتقاط الصوت.</div>
          </div>
          <button type="button" onclick="vsStartHostMic()" class="vs-act-btn allow" style="padding:0.48rem 1rem;font-size:0.82rem;">تشغيل المايك الآن</button>
        </div>
        <?php else: ?>
        <div id="studentAudioBanner" class="vs-unlock-banner">
          <div>
            <div style="font-weight:800;font-size:0.88rem;">انضم لصوت الغرفة المباشر</div>
            <div style="font-size:0.78rem;color:var(--vs-muted);">اضغط لتفعيل سماعة الصوت والاستماع للبث مباشرة.</div>
          </div>
          <button type="button" onclick="vsUnlockAudio()" id="studentUnlockAudioBtn" class="vs-act-btn" style="background:var(--vs-accent);color:#FFFFFF;padding:0.48rem 1rem;font-size:0.82rem;">تشغيل الصوت الآن</button>
        </div>
        <?php endif; ?>

        <!-- 1. Main Speaker Stage + Permitted Speakers ALWAYS FIRST -->
        <div id="stageSpeakersGrid">
          <div class="vs-host-stage">
            <div id="hostAvatarWrap">
              <div id="hostAuraHalo"></div>
              <div id="hostAvatarCircle">
                <div id="hostAvatarInner"><?php echo vsEsc($hostInitial); ?></div>
              </div>
              <span id="hostMicBadge"><i class="fas fa-microphone"></i></span>
            </div>
            <h2 class="vs-host-name"><?php echo vsEsc($hostTitleDisplay); ?></h2>
            <span id="hostStatusLabel">المضيف • بث مباشر</span>
          </div>
          <div id="permittedSpeakersContainer"></div>
        </div>

        <!-- Video Grid (Shown automatically when a Video engine is active) -->
        <div id="videoGridWrap" class="hidden"></div>

        <!-- Synced Whiteboard & Slides Board -->
        <div id="whiteboardPanel" class="<?php echo (int)$room['whiteboard_enabled'] === 1 ? '' : 'hidden'; ?>">
          <div class="vs-wb-toolbar">
            <strong style="font-size:0.85rem;"><i class="fas fa-chalkboard" style="color:var(--vs-accent);"></i> السبورة التفاعلية والشرائح</strong>
            <?php if ($isHost): ?>
            <div class="vs-wb-tools">
              <button type="button" onclick="vsWbTool('pen', this)" class="vs-wb-btn vs-wb-tool-btn active">قلم</button>
              <button type="button" onclick="vsWbTool('highlighter', this)" class="vs-wb-btn vs-wb-tool-btn">تمييز</button>
              <button type="button" onclick="vsWbTool('laser', this)" class="vs-wb-btn vs-wb-tool-btn">ليزر</button>
              <button type="button" onclick="vsWbTool('eraser', this)" class="vs-wb-btn vs-wb-tool-btn">ممحاة</button>
              <input type="color" value="#2563EB" onchange="vsWbColor(this.value)" title="لون القلم" style="width:28px;height:28px;border:none;background:transparent;cursor:pointer;">
              <button type="button" onclick="vsWbClear()" class="vs-wb-btn">مسح</button>
              <label class="vs-wb-btn" style="cursor:pointer;">
                <span>+ شريحة</span>
                <input type="file" accept="image/*" onchange="vsUploadSlide(this)" style="display:none;">
              </label>
              <button type="button" onclick="vsSlideStep(-1)" class="vs-wb-btn">السابق</button>
              <button type="button" onclick="vsSlideStep(1)" class="vs-wb-btn">التالي</button>
            </div>
            <?php endif; ?>
          </div>
          <div class="vs-wb-canvas-wrap">
            <img id="wbSlideImg" class="hidden" alt="شريحة العرض">
            <canvas id="wbCanvas"></canvas>
            <div id="wbLaserDot" class="hidden"></div>
          </div>
        </div>

        <!-- 2. Raised Hands Queue (Below Stage — Never Clips Host) -->
        <?php if ($isHost): ?>
        <div id="raisedHandsQueueBox" class="hidden">
          <div class="vs-hands-head">
            <h4><i class="fas fa-hand-paper"></i> طلبات المداخلة الصوتية (<span id="raisedHandsCount">0</span>)</h4>
            <button type="button" onclick="vsDismissAllHands()" class="vs-act-btn neutral">إنزال كل الأيادي</button>
          </div>
          <div id="raisedHandsList"></div>
        </div>
        <?php endif; ?>

        <!-- 3. Priority-Sorted Searchable Roster -->
        <div id="rosterPanelCard">
          <div class="vs-roster-head">
            <h4>الحضور في الغرفة (<span id="rosterOnlineBadge">1</span>)</h4>
            <input type="search" id="participantSearchInput" oninput="renderParticipantsList()" placeholder="ابحث عن اسم مشارك...">
          </div>
          <div id="participantsGrid">
            <div style="padding:1rem;text-align:center;color:var(--vs-muted);font-size:0.84rem;">جاري الاتصال بقائمة الحضور...</div>
          </div>
        </div>
      </div>

      <!-- Bottom Google Meet Controls Dock -->
      <div class="vs-controls-dock">
        <div class="vs-vol-bar">
          <i class="fas fa-volume-up" style="color:var(--vs-accent);"></i>
          <span>درجة الصوت</span>
          <input type="range" min="0" max="1" step="0.02" value="1" oninput="vsSetVolume(this.value)" aria-label="درجة الصوت">
          <span id="volumePctTxt" class="mono">100%</span>
        </div>

        <?php if ($isHost): ?>
        <div class="gm-host-row">
          <button type="button" id="hostMuteBtn" onclick="vsToggleHostMic()" class="gm-ctrl-btn gm-mic-on">
            <span class="gm-ctrl-icon"><i class="fas fa-microphone" id="hostMuteIcon"></i></span>
            <span class="gm-lbl-full" id="hostMuteText">كتم المايك</span>
            <span class="gm-lbl-short">المايك</span>
          </button>
          <button type="button" id="hostPauseRecBtn" onclick="vsToggleRecPause()" class="gm-ctrl-btn">
            <span class="gm-ctrl-icon"><i class="fas fa-pause" style="color:var(--vs-amber);"></i></span>
            <span class="gm-lbl-full" id="hostPauseRecText">إيقاف مؤقت</span>
            <span class="gm-lbl-short">التسجيل</span>
          </button>
          <button type="button" id="hostAllowAllBtn" onclick="vsToggleAllowAllMic()" class="gm-ctrl-btn">
            <span class="gm-ctrl-icon"><i class="fas fa-unlock" style="color:var(--vs-emerald);"></i></span>
            <span class="gm-lbl-full">فتح المايك للجميع</span>
            <span class="gm-lbl-short">للجميع</span>
          </button>
          <button type="button" onclick="vsMuteAll()" class="gm-ctrl-btn">
            <span class="gm-ctrl-icon"><i class="fas fa-volume-mute" style="color:var(--vs-rose);"></i></span>
            <span class="gm-lbl-full">كتم الجميع</span>
            <span class="gm-lbl-short">كتم</span>
          </button>
          <?php if (($room['media_mode'] ?? 'audio') === 'video'): ?>
          <button type="button" id="hostCamBtn" onclick="vsToggleCam()" class="gm-ctrl-btn gm-active-blue">
            <span class="gm-ctrl-icon"><i class="fas fa-video"></i></span>
            <span class="gm-lbl-full">الكاميرا</span>
            <span class="gm-lbl-short">كاميرا</span>
          </button>
          <button type="button" id="hostScreenBtn" onclick="vsToggleScreen()" class="gm-ctrl-btn">
            <span class="gm-ctrl-icon"><i class="fas fa-desktop"></i></span>
            <span class="gm-lbl-full">مشاركة الشاشة</span>
            <span class="gm-lbl-short">الشاشة</span>
          </button>
          <?php endif; ?>
          <button type="button" onclick="vsEndRoom()" class="gm-ctrl-btn gm-end">
            <span class="gm-ctrl-icon"><i class="fas fa-phone-slash"></i></span>
            <span class="gm-lbl-full">إنهاء وحفظ</span>
            <span class="gm-lbl-short">إنهاء</span>
          </button>
        </div>
        <?php else: ?>
        <div class="gm-host-row">
          <button type="button" id="studentRaiseHandBtn" onclick="vsToggleRaiseHand()" class="gm-ctrl-btn">
            <span class="gm-ctrl-icon"><i class="fas fa-hand-paper" style="color:var(--vs-amber);"></i></span>
            <span class="gm-lbl-full" id="studentRaiseHandText">رفع اليد</span>
            <span class="gm-lbl-short">رفع اليد</span>
          </button>
          <button type="button" id="studentMicBtn" onclick="vsToggleStudentMic()" class="hidden gm-ctrl-btn gm-muted">
            <span class="gm-ctrl-icon"><i class="fas fa-microphone"></i></span>
            <span class="gm-lbl-full" id="studentMicText">تشغيل المايك</span>
            <span class="gm-lbl-short">المايك</span>
          </button>
          <button type="button" onclick="vsLeaveRoom()" class="gm-ctrl-btn gm-end">
            <span class="gm-ctrl-icon"><i class="fas fa-phone-slash"></i></span>
            <span class="gm-lbl-full">مغادرة الغرفة</span>
            <span class="gm-lbl-short">مغادرة</span>
          </button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Side Panel: Live Chat + Polls -->
    <div id="mobileChatBackdrop" onclick="vsToggleChat()"></div>
    <aside id="chatPanelCard">
      <div class="vs-side-tabs">
        <div style="display:flex;gap:0.25rem;">
          <button type="button" id="tabChatBtn" onclick="vsSwitchSideTab('chat')" class="vs-tab-btn active">الدردشة</button>
          <button type="button" id="tabPollsBtn" onclick="vsSwitchSideTab('polls')" class="vs-tab-btn">الاستطلاعات</button>
        </div>
        <button type="button" onclick="vsToggleChat()" class="vs-icon-mini" title="إغلاق"><i class="fas fa-times"></i></button>
      </div>

      <div id="sideChatPane" style="display:flex;flex-direction:column;flex:1 1 0%;min-height:0;">
        <div id="chatMessagesBox" class="vs-side-body">
          <div id="chatEmptyNotice" style="text-align:center;color:var(--vs-muted);font-size:0.82rem;padding:1.5rem 0.5rem;">
            شارك رسائلك وأسئلتك أثناء البث هنا...
          </div>
        </div>
        <form id="chatComposer" onsubmit="vsSendChat(event)">
          <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.78rem;color:var(--vs-muted);cursor:pointer;">
            <input type="checkbox" id="chatIsQuestion" style="accent-color:var(--vs-accent);">
            <span>تمييز كسؤال للمضيف</span>
          </label>
          <div class="vs-composer-row">
            <input type="text" id="chatInput" maxlength="500" placeholder="اكتب رسالتك...">
            <button type="submit" id="chatSendBtn" title="إرسال"><i class="fas fa-paper-plane"></i></button>
          </div>
        </form>
      </div>

      <div id="sidePollsPane" class="hidden" style="display:flex;flex-direction:column;flex:1 1 0%;min-height:0;">
        <div id="pollsListBox" class="vs-side-body"></div>
        <?php if ($isHost): ?>
        <form onsubmit="vsCreatePoll(event)" style="border-top:1px solid var(--vs-border);padding:0.75rem;display:flex;flex-direction:column;gap:0.45rem;">
          <input type="text" id="pollQInput" required placeholder="سؤال الاستطلاع..." style="border:1px solid var(--vs-border);background:var(--vs-subtle);color:var(--vs-text);border-radius:9px;padding:0.45rem 0.65rem;font-size:0.82rem;">
          <textarea id="pollOptsInput" rows="3" required placeholder="كل خيار في سطر..." style="border:1px solid var(--vs-border);background:var(--vs-subtle);color:var(--vs-text);border-radius:9px;padding:0.45rem 0.65rem;font-size:0.8rem;"></textarea>
          <button type="submit" class="vs-act-btn" style="background:var(--vs-accent);color:#FFFFFF;padding:0.45rem;">نشر الاستطلاع</button>
        </form>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</div>

<div id="saveOverlay" class="hidden">
  <div class="vs-save-card">
    <h3 style="margin:0 0 0.4rem;">جاري إنهاء الجلسة وحفظ التسجيل...</h3>
    <p style="margin:0;color:var(--vs-muted);font-size:0.86rem;">يتم الآن تجميع ملف الـ MP3 ورفعه للسحابة إن وُجدت.</p>
  </div>
</div>

<script>
window.AAM_ROOM_CONFIG = {
  siteUrl: <?php echo json_encode(SITE_URL, JSON_UNESCAPED_SLASHES); ?>,
  roomCode: <?php echo json_encode((string)$room['code']); ?>,
  roomTitle: <?php echo json_encode((string)$room['title'], JSON_UNESCAPED_UNICODE); ?>,
  mediaMode: <?php echo json_encode((string)$room['media_mode']); ?>,
  isHost: <?php echo $isHost ? 'true' : 'false'; ?>,
  isEmbed: <?php echo $isEmbed ? 'true' : 'false'; ?>,
  wsUserId: <?php echo json_encode((string)$actor['user_key']); ?>,
  userName: <?php echo json_encode((string)$actor['name'], JSON_UNESCAPED_UNICODE); ?>,
  initialElapsed: <?php echo (int)$initialElapsed; ?>,
  initialWhiteboard: <?php echo (int)$room['whiteboard_enabled'] === 1 ? 'true' : 'false'; ?>,
  shareUrl: <?php echo json_encode($shareUrl, JSON_UNESCAPED_SLASHES); ?>,
  csrfToken: <?php echo json_encode($csrf); ?>
};
</script>
<script src="<?php echo SITE_URL; ?>/assets/js/lame.min.js"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/audio-engine.js?v=1"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/whiteboard.js?v=1"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/engine-adapters.js?v=2"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/room-app.js?v=3"></script>
</body>
</html>
