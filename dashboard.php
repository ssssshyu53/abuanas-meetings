<?php
/**
 * Abu Anas Meetings — Isolated Multi-Host Workspace Dashboard
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
            $st = $db->prepare("UPDATE rooms SET status = 'ended', ended_at = ? WHERE id = ? AND host_user_id = ?");
            $st->execute([$now, $rid, (int)$user['id']]);
            if ($st->rowCount() > 0) {
                $okMsg = 'تم إنهاء الاجتماع بنجاح.';
            } else {
                $err = 'غير مصرح بإنهاء هذه الغرفة.';
            }
        } elseif ($act === 'delete_recording' && Auth::canHost()) {
            $recId = (int)($_POST['recording_id'] ?? 0);
            $st = $db->prepare('SELECT rc.id, rc.file_path FROM recordings rc INNER JOIN rooms rm ON rm.id = rc.room_id WHERE rc.id = ? AND rm.host_user_id = ? LIMIT 1');
            $st->execute([$recId, (int)$user['id']]);
            $recRow = $st->fetch();
            if ($recRow) {
                $db->prepare('DELETE FROM recordings WHERE id = ?')->execute([(int)$recRow['id']]);
                $rel = ltrim((string)$recRow['file_path'], '/');
                if ($rel !== '' && str_starts_with($rel, 'uploads/recordings/')) {
                    $abs = VS_ROOT . '/' . $rel;
                    if (is_file($abs)) {
                        @unlink($abs);
                    }
                }
                $okMsg = 'تم حذف التسجيل نهائياً.';
            } else {
                $err = 'التسجيل غير موجود أو لا يخص مساحتك.';
            }
        } elseif ($act === 'update_profile') {
            $pr = Auth::updateProfile((int)$user['id'], (string)($_POST['name'] ?? ''), (string)($_POST['workspace_name'] ?? ''));
            if ($pr['ok']) {
                $user = Auth::currentUser() ?? $user;
                $okMsg = 'تم حفظ بيانات مساحتك الشخصية.';
            } else {
                $err = $pr['error'] ?? 'تعذر حفظ البيانات';
            }
        } elseif ($act === 'change_password') {
            $cur = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $cnf = (string)($_POST['confirm_password'] ?? '');
            if ($new !== $cnf) {
                $err = 'كلمتا المرور الجديدتان غير متطابقتين.';
            } else {
                $cp = Auth::changePassword((int)$user['id'], $cur, $new);
                if ($cp['ok']) {
                    $okMsg = 'تم تحديث كلمة المرور بنجاح.';
                } else {
                    $err = $cp['error'] ?? 'تعذر تحديث كلمة المرور';
                }
            }
        }
    }
}

if (!empty($_GET['upgraded'])) {
    $okMsg = 'تمت ترقية حسابك إلى مضيف اجتماعات مستقل بنجاح!';
}

$canHost = Auth::canHost();
$presets = RoomService::presetLabels();
$wsTitle = trim((string)($user['workspace_name'] ?? ''));
$pageTitle = $canHost ? ($wsTitle ?: 'مساحة اجتماعاتي') : 'لوحة المشارك';
require_once __DIR__ . '/includes/header.php';
?>
<div class="vs-container" style="padding:2rem 1.25rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
    <div>
      <h1 style="margin:0 0 0.25rem;font-size:1.6rem;">
        <?php echo $canHost ? vsEsc($wsTitle ?: 'مساحة اجتماعاتي وتسجيلاتي') : 'مرحباً ' . vsEsc($user['name']); ?>
      </h1>
      <div style="color:var(--vs-muted);font-size:0.88rem;display:flex;align-items:center;gap:0.45rem;flex-wrap:wrap;">
        <span><?php echo vsEsc($user['name']); ?></span>
        <span class="vs-badge vs-badge-blue">
          <?php echo $user['role'] === 'admin' ? 'مدير النظام' : ($user['role'] === 'host' ? 'حساب مضيف مستقل' : 'حساب مشارك'); ?>
        </span>
        <span class="mono" style="font-size:0.78rem;"><?php echo vsEsc($user['email']); ?></span>
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

  <?php if (!$canHost): ?>
    <div class="vs-grid-2" style="margin-bottom:1.5rem;">
      <div class="vs-card">
        <h2 style="margin:0 0 0.5rem;font-size:1.15rem;">انضمام سريع برمز الدعوة</h2>
        <p style="margin:0 0 0.75rem;color:var(--vs-muted);font-size:0.85rem;">أدخل رمز الاجتماع الذي شاركه معك المضيف للانتقال لغرفة الانتظار.</p>
        <form action="<?php echo SITE_URL; ?>/room.php" method="GET" style="display:flex;gap:0.5rem;">
          <input type="text" name="code" class="vs-input mono" placeholder="أدخل رمز الاجتماع..." required>
          <button type="submit" class="vs-btn vs-btn-primary">دخول</button>
        </form>
      </div>
      <div class="vs-card" style="background:linear-gradient(135deg, var(--vs-accent-soft), var(--vs-surface));">
        <h2 style="margin:0 0 0.45rem;font-size:1.15rem;">امتلك مساحة اجتماعاتك المستقلة</h2>
        <p style="margin:0 0 0.9rem;color:var(--vs-muted);font-size:0.86rem;">حوّل حسابك مجاناً إلى مضيف (Host) لتنشئ غرفك الخاصة، تسجّل الاجتماعات فيديو وصوت، وتدمجها بمواقعك.</p>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
          <input type="hidden" name="action" value="upgrade_host">
          <button type="submit" class="vs-btn vs-btn-primary">الترقية لحساب مضيف مجاناً</button>
        </form>
      </div>
    </div>
  <?php else:
    // STRICT TENANT ISOLATION: every host (including admin workspace) sees ONLY their own rooms & recordings
    $cutoff45 = Database::cutoff(45);
    $myRoomsSt = $db->prepare("
        SELECT r.*,
               (SELECT COUNT(*) FROM room_participants p WHERE p.room_id = r.id AND p.last_seen_at >= ?) AS online_cnt
        FROM rooms r
        WHERE r.host_user_id = ?
        ORDER BY r.id DESC
        LIMIT 25
    ");
    $myRoomsSt->execute([$cutoff45, (int)$user['id']]);
    $myRooms = $myRoomsSt->fetchAll();

    $recQuery = $db->prepare('
        SELECT rc.*, rm.code
        FROM recordings rc
        INNER JOIN rooms rm ON rm.id = rc.room_id
        WHERE rm.host_user_id = ?
        ORDER BY rc.id DESC
        LIMIT 25
    ');
    $recQuery->execute([(int)$user['id']]);
    $recordings = $recQuery->fetchAll();
  ?>
  <div class="vs-grid-2" style="align-items:start;">
    <!-- Create Private Room Card -->
    <div class="vs-card">
      <h2 style="margin:0 0 1rem;font-size:1.15rem;">إنشاء اجتماع جديد خاص بك</h2>
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
              <option value="video" selected>فيديو وصوت ومشاركة شاشة</option>
              <option value="audio">صوت وسبورة تفاعلية</option>
            </select>
          </div>
        </div>

        <div class="vs-grid-2">
          <div>
            <label class="vs-label" for="roomAccessSel">سياسة الانضمام</label>
            <select id="roomAccessSel" name="access_mode" class="vs-select">
              <option value="code" selected>خاص برابط أو رمز الدعوة فقط (موصى به)</option>
              <option value="registered">للمستخدمين المسجلين فقط</option>
              <option value="public">مفتوح بالرابط والضيوف</option>
            </select>
          </div>
          <div>
            <label class="vs-label" for="roomPassInp">رمز حماية اختياري (Passcode)</label>
            <input id="roomPassInp" type="text" name="passcode" class="vs-input mono" maxlength="32" placeholder="بدون رمز">
          </div>
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

    <!-- Owner-Isolated Meetings List -->
    <div class="vs-card">
      <h2 style="margin:0 0 1rem;font-size:1.15rem;">اجتماعاتك الخاصة</h2>
      <?php if (empty($myRooms)): ?>
        <p style="color:var(--vs-muted);font-size:0.88rem;">لم تنشئ أي اجتماع في مساحتك بعد.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:0.65rem;">
          <?php foreach ($myRooms as $r): ?>
            <div style="border:1px solid var(--vs-border);border-radius:12px;padding:0.75rem;display:flex;align-items:center;justify-content:space-between;gap:0.6rem;flex-wrap:wrap;">
              <div style="min-width:0;">
                <div style="font-weight:700;font-size:0.92rem;display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                  <span><?php echo vsEsc($r['title']); ?></span>
                  <?php if (!empty($r['passcode'])): ?>
                    <span class="vs-badge vs-badge-amber" title="محمي برمز"><i class="fas fa-lock"></i> محمي</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:0.76rem;color:var(--vs-muted);display:flex;gap:0.45rem;flex-wrap:wrap;margin-top:0.15rem;">
                  <span class="mono"><?php echo vsEsc($r['code']); ?></span>
                  <span>• <?php echo $r['media_mode'] === 'video' ? 'فيديو' : 'صوت'; ?></span>
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

  <!-- Owner-Isolated Recordings Library (Video + Audio) -->
  <div class="vs-card" style="margin-top:1.25rem;">
    <h2 style="margin:0 0 1rem;font-size:1.15rem;">تسجيلات الفيديو والصوت الخاصة بك</h2>
    <?php if (empty($recordings)): ?>
      <p style="margin:0;color:var(--vs-muted);font-size:0.88rem;">تظهر هنا تسجيلات الفيديو والصوت تلقائياً فور حفظ أي اجتماع.</p>
    <?php else: ?>
      <div class="vs-table-wrap">
        <table class="vs-table">
          <thead><tr><th>العنوان</th><th>النوع</th><th>الرمز</th><th>المدة</th><th>الحجم</th><th>المعاينة والتحميل</th></tr></thead>
          <tbody>
            <?php foreach ($recordings as $rc):
              $isVid = ($rc['media_type'] ?? 'audio') === 'video';
              $dlExt = $isVid ? strtoupper(pathinfo((string)$rc['file_path'], PATHINFO_EXTENSION) ?: 'WEBM') : 'MP3';
            ?>
              <tr>
                <td style="font-weight:700;"><?php echo vsEsc($rc['title']); ?></td>
                <td>
                  <span class="vs-badge <?php echo $isVid ? 'vs-badge-blue' : 'vs-badge-muted'; ?>">
                    <?php echo $isVid ? 'فيديو' : 'صوت'; ?>
                  </span>
                </td>
                <td class="mono"><?php echo vsEsc($rc['code'] ?: '--'); ?></td>
                <td class="mono"><?php echo gmdate('H:i:s', (int)$rc['duration_seconds']); ?></td>
                <td class="mono"><?php echo round(((float)$rc['size_bytes']) / 1048576, 2); ?> MB</td>
                <td>
                  <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <?php if ($isVid): ?>
                      <video controls playsinline preload="metadata" src="<?php echo SITE_URL . '/' . vsEsc($rc['file_path']); ?>" style="width:200px;max-height:115px;border-radius:10px;background:#0F172A;"></video>
                    <?php else: ?>
                      <audio controls preload="none" src="<?php echo SITE_URL . '/' . vsEsc($rc['file_path']); ?>" style="height:32px;max-width:210px;"></audio>
                    <?php endif; ?>
                    <a href="<?php echo SITE_URL . '/' . vsEsc($rc['file_path']); ?>" download class="vs-btn vs-btn-outline" style="height:32px;padding:0 0.7rem;font-size:0.75rem;"><?php echo vsEsc($dlExt); ?></a>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('حذف هذا التسجيل نهائياً؟');">
                      <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
                      <input type="hidden" name="action" value="delete_recording">
                      <input type="hidden" name="recording_id" value="<?php echo (int)$rc['id']; ?>">
                      <button type="submit" class="vs-btn vs-btn-danger" style="height:32px;padding:0 0.65rem;font-size:0.75rem;">حذف</button>
                    </form>
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

  <!-- Workspace Identity & Account Security -->
  <div class="vs-card" style="margin-top:1.25rem;">
    <details>
      <summary style="cursor:pointer;font-weight:800;font-size:0.98rem;display:flex;align-items:center;gap:0.5rem;">
        <i class="fas fa-shield-alt" style="color:var(--vs-accent);"></i>
        <span>إعدادات مساحتك وتغيير كلمة المرور</span>
      </summary>
      <form method="POST" style="margin-top:1rem;padding-bottom:1rem;border-bottom:1px solid var(--vs-border);display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.8rem;align-items:end;">
        <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
        <input type="hidden" name="action" value="update_profile">
        <div>
          <label class="vs-label" for="profName">الاسم الشخصي</label>
          <input id="profName" type="text" name="name" class="vs-input" value="<?php echo vsEsc($user['name']); ?>" required>
        </div>
        <div>
          <label class="vs-label" for="profWs">اسم مساحتك الخاصة</label>
          <input id="profWs" type="text" name="workspace_name" class="vs-input" value="<?php echo vsEsc($wsTitle); ?>" placeholder="مثال: أكاديمية أنس">
        </div>
        <button type="submit" class="vs-btn vs-btn-primary" style="width:100%;">حفظ بيانات المساحة</button>
      </form>

      <form method="POST" style="margin-top:1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.8rem;align-items:end;">
        <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
        <input type="hidden" name="action" value="change_password">
        <div>
          <label class="vs-label" for="curPassInp">كلمة المرور الحالية</label>
          <input id="curPassInp" type="password" name="current_password" class="vs-input" required>
        </div>
        <div>
          <label class="vs-label" for="newPassInp">كلمة المرور الجديدة (8 أحرف+)</label>
          <input id="newPassInp" type="password" name="new_password" class="vs-input" minlength="8" required>
        </div>
        <div>
          <label class="vs-label" for="cnfPassInp">تأكيد كلمة المرور الجديدة</label>
          <input id="cnfPassInp" type="password" name="confirm_password" class="vs-input" minlength="8" required>
        </div>
        <button type="submit" class="vs-btn vs-btn-outline" style="width:100%;">تحديث كلمة المرور</button>
      </form>
    </details>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
