<?php
/**
 * Abu Anas Meetings — User & Host Workspace Dashboard
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

$user = Auth::requireLogin();
$db = Database::getInstance()->connect();
$err = '';
$okMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vsVerifyCsrf()) {
        $err = 'انتهت صلاحية الجلسة.';
    } else {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'upgrade_host') {
            Auth::upgradeToHost((int)$user['id']);
            vsRedirect(SITE_URL . '/dashboard.php?upgraded=1');
        } elseif ($act === 'create_room' && Auth::canHost()) {
            $res = RoomService::createRoom($db, (int)$user['id'], $_POST);
            if ($res['ok']) {
                vsRedirect(SITE_URL . '/room.php?code=' . urlencode($res['code']));
            }
            $err = $res['error'] ?? 'تعذر إنشاء الاجتماع';
        } elseif ($act === 'end_room' && Auth::canHost()) {
            $rid = (int)($_POST['room_id'] ?? 0);
            $now = Database::now();
            if ($user['role'] === 'admin') {
                $db->prepare("UPDATE rooms SET status = 'ended', ended_at = ? WHERE id = ?")->execute([$now, $rid]);
            } else {
                $db->prepare("UPDATE rooms SET status = 'ended', ended_at = ? WHERE id = ? AND host_user_id = ?")->execute([$now, $rid, (int)$user['id']]);
            }
            $okMsg = 'تم إنهاء الاجتماع بنجاح.';
        }
    }
}

if (!empty($_GET['upgraded'])) {
    $okMsg = 'تمت ترقية حسابك إلى مضيف اجتماعات بنجاح! يمكنك الآن إنشاء ومشاركة الاجتماعات ودمجها في منصاتك.';
}

$canHost = Auth::canHost();
$presets = RoomService::presetLabels();
$pageTitle = $canHost ? 'إدارة الاجتماعات والتسجيلات' : 'لوحة المشارك';
require_once __DIR__ . '/includes/header.php';
?>
<div class="vs-container" style="padding:2rem 1.25rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
    <div>
      <h1 style="margin:0 0 0.25rem;font-size:1.6rem;">
        <?php echo $canHost ? 'إدارة الاجتماعات والتسجيلات' : 'مرحباً ' . vsEsc($user['name']); ?>
      </h1>
      <div style="color:var(--vs-muted);font-size:0.88rem;">
        مرحباً <?php echo vsEsc($user['name']); ?>
        <span class="vs-badge vs-badge-blue" style="margin-right:0.35rem;">
          <?php echo $user['role'] === 'admin' ? 'مدير النظام' : ($user['role'] === 'host' ? 'حساب مضيف' : 'حساب مشارك'); ?>
        </span>
      </div>
    </div>
    <?php if ($canHost): ?>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <a href="<?php echo SITE_URL; ?>/embed.php" class="vs-btn vs-btn-outline"><i class="fas fa-code"></i> الدمج في منصتك</a>
        <?php if ($user['role'] === 'admin'): ?>
          <a href="<?php echo SITE_URL; ?>/admin/users.php" class="vs-btn vs-btn-outline"><i class="fas fa-users-cog"></i> المستخدمون</a>
          <a href="<?php echo SITE_URL; ?>/admin/monitor.php" class="vs-btn vs-btn-outline"><i class="fas fa-heartbeat"></i> المراقبة</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($err !== ''): ?><div class="vs-card" style="border-color:var(--vs-rose);color:var(--vs-rose);margin-bottom:1rem;"><?php echo vsEsc($err); ?></div><?php endif; ?>
  <?php if ($okMsg !== ''): ?><div class="vs-card" style="border-color:var(--vs-emerald);color:var(--vs-emerald);margin-bottom:1rem;"><?php echo vsEsc($okMsg); ?></div><?php endif; ?>

  <?php if (!$canHost):
    $pubRooms = $db->query("SELECT r.*, u.name AS host_name FROM rooms r LEFT JOIN users u ON u.id = r.host_user_id WHERE r.status = 'live' AND r.access_mode = 'public' ORDER BY r.id DESC LIMIT 12")->fetchAll();
  ?>
    <div class="vs-grid-2" style="margin-bottom:1.5rem;">
      <div class="vs-card">
        <h2 style="margin:0 0 0.5rem;font-size:1.15rem;">انضمام سريع برمز الاجتماع</h2>
        <form action="<?php echo SITE_URL; ?>/room.php" method="GET" style="display:flex;gap:0.5rem;margin-top:0.85rem;">
          <input type="text" name="code" class="vs-input mono" placeholder="أدخل رمز الاجتماع..." required>
          <button type="submit" class="vs-btn vs-btn-primary">دخول</button>
        </form>
      </div>
      <div class="vs-card" style="background:linear-gradient(135deg, var(--vs-accent-soft), var(--vs-surface));">
        <h2 style="margin:0 0 0.45rem;font-size:1.15rem;">هل تريد إنشاء وإدارة اجتماعاتك الخاصة؟</h2>
        <p style="margin:0 0 0.9rem;color:var(--vs-muted);font-size:0.86rem;">حوّل حسابك مجاناً الآن إلى حساب مضيف (Host) لإنشاء غرف غير محدودة ومشاركتها ودمجها في مواقعك.</p>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
          <input type="hidden" name="action" value="upgrade_host">
          <button type="submit" class="vs-btn vs-btn-primary">الترقية لحساب مضيف مجاناً</button>
        </form>
      </div>
    </div>

    <div class="vs-card">
      <h2 style="margin:0 0 0.85rem;font-size:1.1rem;">الاجتماعات العامة النشطة</h2>
      <?php if (empty($pubRooms)): ?>
        <p style="margin:0;color:var(--vs-muted);font-size:0.88rem;">لا توجد اجتماعات عامة حالياً.</p>
      <?php else: ?>
        <div class="vs-grid-3">
          <?php foreach ($pubRooms as $r): ?>
            <div style="border:1px solid var(--vs-border);border-radius:12px;padding:0.85rem;">
              <div style="font-weight:700;margin-bottom:0.25rem;"><?php echo vsEsc($r['title']); ?></div>
              <div style="font-size:0.78rem;color:var(--vs-muted);margin-bottom:0.7rem;">المضيف: <?php echo vsEsc($r['host_name'] ?: 'مضيف'); ?></div>
              <a href="<?php echo SITE_URL; ?>/room.php?code=<?php echo urlencode($r['code']); ?>" class="vs-btn vs-btn-primary" style="height:34px;font-size:0.8rem;">انضمام</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php else:
    $cutoff45 = Database::cutoff(45);
    $myRoomsSt = $user['role'] === 'admin'
        ? (static function () use ($db, $cutoff45) {
            $s = $db->prepare("SELECT r.*, u.name AS host_name, (SELECT COUNT(*) FROM room_participants p WHERE p.room_id = r.id AND p.last_seen_at >= ?) AS online_cnt FROM rooms r LEFT JOIN users u ON u.id = r.host_user_id ORDER BY r.id DESC LIMIT 25");
            $s->execute([$cutoff45]);
            return $s;
        })()
        : (static function () use ($db, $user, $cutoff45) {
            $s = $db->prepare("SELECT r.*, u.name AS host_name, (SELECT COUNT(*) FROM room_participants p WHERE p.room_id = r.id AND p.last_seen_at >= ?) AS online_cnt FROM rooms r LEFT JOIN users u ON u.id = r.host_user_id WHERE r.host_user_id = ? ORDER BY r.id DESC LIMIT 25");
            $s->execute([$cutoff45, (int)$user['id']]);
            return $s;
        })();
    $myRooms = $myRoomsSt->fetchAll();

    $recQuery = $user['role'] === 'admin'
        ? $db->query("SELECT rc.*, rm.code FROM recordings rc LEFT JOIN rooms rm ON rm.id = rc.room_id ORDER BY rc.id DESC LIMIT 20")
        : (static function () use ($db, $user) {
            $s = $db->prepare("SELECT rc.*, rm.code FROM recordings rc INNER JOIN rooms rm ON rm.id = rc.room_id WHERE rm.host_user_id = ? ORDER BY rc.id DESC LIMIT 20");
            $s->execute([(int)$user['id']]);
            return $s;
        })();
    $recordings = $recQuery->fetchAll();
  ?>
  <div class="vs-grid-2" style="align-items:start;">
    <!-- Create Room Card (Zero technical jargon) -->
    <div class="vs-card">
      <h2 style="margin:0 0 1rem;font-size:1.15rem;">إنشاء اجتماع جديد</h2>
      <form method="POST" style="display:flex;flex-direction:column;gap:0.85rem;">
        <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
        <input type="hidden" name="action" value="create_room">

        <div>
          <label class="vs-label" for="roomTitleInp">عنوان الاجتماع</label>
          <input id="roomTitleInp" type="text" name="title" class="vs-input" required placeholder="مثال: اجتماع الفريق الأسبوعي / محاضرة تفاعلية">
        </div>

        <div class="vs-grid-2">
          <div>
            <label class="vs-label" for="roomPresetSel">نوع الجلسة</label>
            <select id="roomPresetSel" name="preset" class="vs-select">
              <?php foreach ($presets as $pk => $pv): ?>
                <option value="<?php echo vsEsc($pk); ?>"><?php echo vsEsc($pv['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="vs-label" for="roomMediaSel">وضع الاجتماع</label>
            <select id="roomMediaSel" name="media_mode" class="vs-select">
              <option value="audio">صوت وسبورة تفاعلية</option>
              <option value="video">فيديو وصوت ومشاركة شاشة</option>
            </select>
          </div>
        </div>

        <div>
          <label class="vs-label" for="roomAccessSel">الخصوصية والدخول</label>
          <select id="roomAccessSel" name="access_mode" class="vs-select">
            <option value="public">عام (يظهر في الرئيسية + يقبل الضيوف)</option>
            <option value="code">خاص برابط أو رمز الدعوة فقط</option>
            <option value="registered">للمستخدمين المسجلين فقط</option>
          </select>
        </div>

        <div style="display:flex;gap:1.25rem;flex-wrap:wrap;font-size:0.85rem;">
          <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;">
            <input type="checkbox" name="whiteboard_enabled" value="1">
            <span>تفعيل السبورة والشرائح عند البدء</span>
          </label>
          <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;">
            <input type="checkbox" name="allow_all_mic" value="1">
            <span>فتح المايك للجميع تلقائياً</span>
          </label>
        </div>

        <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;"><i class="fas fa-play"></i> بدء الاجتماع والدخول الآن</button>
      </form>
    </div>

    <!-- Active & Past Meetings -->
    <div class="vs-card">
      <h2 style="margin:0 0 1rem;font-size:1.15rem;">اجتماعاتك</h2>
      <?php if (empty($myRooms)): ?>
        <p style="color:var(--vs-muted);font-size:0.88rem;">لم تنشئ أي اجتماع بعد.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:0.65rem;">
          <?php foreach ($myRooms as $r): ?>
            <div style="border:1px solid var(--vs-border);border-radius:12px;padding:0.75rem;display:flex;align-items:center;justify-content:space-between;gap:0.6rem;flex-wrap:wrap;">
              <div style="min-width:0;">
                <div style="font-weight:700;font-size:0.92rem;"><?php echo vsEsc($r['title']); ?></div>
                <div style="font-size:0.76rem;color:var(--vs-muted);display:flex;gap:0.45rem;flex-wrap:wrap;margin-top:0.15rem;">
                  <span class="mono"><?php echo vsEsc($r['code']); ?></span>
                  <span>• <?php echo $r['status'] === 'live' ? 'مباشر (' . (int)$r['online_cnt'] . ')' : 'منتهي'; ?></span>
                </div>
              </div>
              <div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
                <?php if ($r['status'] === 'live'): ?>
                  <a href="<?php echo SITE_URL; ?>/room.php?code=<?php echo urlencode($r['code']); ?>" class="vs-btn vs-btn-primary" style="height:32px;padding:0 0.75rem;font-size:0.76rem;">دخول</a>
                  <button type="button" onclick="navigator.clipboard.writeText(<?php echo json_encode(SITE_URL . '/room.php?code=' . urlencode($r['code'])); ?>);Swal.fire({toast:true,position:'top-end',icon:'success',title:'تم نسخ رابط الدعوة',timer:1500,showConfirmButton:false})" class="vs-btn vs-btn-outline" style="height:32px;padding:0 0.65rem;font-size:0.76rem;">نسخ الرابط</button>
                  <a href="<?php echo SITE_URL; ?>/embed.php?code=<?php echo urlencode($r['code']); ?>" class="vs-btn vs-btn-outline" style="height:32px;padding:0 0.65rem;font-size:0.76rem;">تضمين</a>
                  <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
                    <input type="hidden" name="action" value="end_room">
                    <input type="hidden" name="room_id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="vs-btn vs-btn-danger" style="height:32px;padding:0 0.65rem;font-size:0.76rem;">إنهاء</button>
                  </form>
                <?php else: ?>
                  <span class="vs-badge vs-badge-muted">مغلق</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recordings Library -->
  <div class="vs-card" style="margin-top:1.25rem;">
    <h2 style="margin:0 0 1rem;font-size:1.15rem;">التسجيلات المحفوظة</h2>
    <?php if (empty($recordings)): ?>
      <p style="margin:0;color:var(--vs-muted);font-size:0.88rem;">تظهر هنا التسجيلات تلقائياً فور إنهاء وحفظ أي اجتماع.</p>
    <?php else: ?>
      <div class="vs-table-wrap">
        <table class="vs-table">
          <thead><tr><th>العنوان</th><th>الرمز</th><th>المدة</th><th>الحجم</th><th>التخزين</th><th>التشغيل والتحميل</th></tr></thead>
          <tbody>
            <?php foreach ($recordings as $rc): ?>
              <tr>
                <td style="font-weight:700;"><?php echo vsEsc($rc['title']); ?></td>
                <td class="mono"><?php echo vsEsc($rc['code'] ?: '--'); ?></td>
                <td class="mono"><?php echo gmdate('H:i:s', (int)$rc['duration_seconds']); ?></td>
                <td class="mono"><?php echo round(((float)$rc['size_bytes']) / 1048576, 2); ?> MB</td>
                <td>
                  <?php if ($rc['r2_url'] !== ''): ?><a href="<?php echo vsEsc($rc['r2_url']); ?>" target="_blank" class="vs-badge vs-badge-blue">نسخة سحابية ✓</a>
                  <?php else: ?><span class="vs-badge vs-badge-muted">محلي</span><?php endif; ?>
                </td>
                <td>
                  <div style="display:flex;align-items:center;gap:0.5rem;">
                    <audio controls preload="none" src="<?php echo SITE_URL . '/' . vsEsc($rc['file_path']); ?>" style="height:32px;max-width:220px;"></audio>
                    <a href="<?php echo SITE_URL . '/' . vsEsc($rc['file_path']); ?>" download class="vs-btn vs-btn-outline" style="height:32px;padding:0 0.7rem;font-size:0.75rem;">MP3</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
