<?php
/**
 * Abu Anas Meetings — External Platform Integration & Embed Studio (Hosts & Admins)
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

$user = Auth::requireHost();
$db = Database::getInstance()->connect();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vsVerifyCsrf()) {
        $err = 'انتهت صلاحية الجلسة.';
    } else {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'create_key') {
            $label = mb_substr(trim((string)($_POST['label'] ?? 'منصتي الخارجية')), 0, 100);
            $domains = trim((string)($_POST['allowed_domains'] ?? '*')) ?: '*';
            $newKey = 'aam_live_' . bin2hex(random_bytes(20));
            $st = $db->prepare('INSERT INTO api_keys (user_id, label, api_key, allowed_domains) VALUES (?, ?, ?, ?)');
            $st->execute([(int)$user['id'], $label ?: 'منصة خارجية', $newKey, $domains]);
            $msg = 'تم توليد مفتاح الربط الجديد بنجاح.';
        } elseif ($act === 'revoke_key') {
            $kid = (int)($_POST['key_id'] ?? 0);
            $db->prepare('UPDATE api_keys SET is_active = 0 WHERE id = ? AND user_id = ?')->execute([$kid, (int)$user['id']]);
            $msg = 'تم تعطيل المفتاح.';
        }
    }
}

$kSt = $db->prepare('SELECT * FROM api_keys WHERE user_id = ? AND is_active = 1 ORDER BY id DESC');
$kSt->execute([(int)$user['id']]);
$keys = $kSt->fetchAll();

$rSt = $user['role'] === 'admin'
    ? $db->query("SELECT code, title FROM rooms WHERE status = 'live' ORDER BY id DESC LIMIT 15")
    : (static function () use ($db, $user) {
        $s = $db->prepare("SELECT code, title FROM rooms WHERE status = 'live' AND host_user_id = ? ORDER BY id DESC LIMIT 15");
        $s->execute([(int)$user['id']]);
        return $s;
    })();
$liveRooms = $rSt->fetchAll();
$selectedCode = strtoupper(trim((string)($_GET['code'] ?? ($liveRooms[0]['code'] ?? 'ROOM123'))));

$pageTitle = 'الدمج في منصاتك ومواقعك';
require_once __DIR__ . '/includes/header.php';
?>
<div class="vs-container" style="padding:2rem 1.25rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.25rem;">
    <div>
      <h1 style="margin:0 0 0.25rem;font-size:1.6rem;">دمج أبو أنس ميتينجز داخل منصاتك الأخرى</h1>
      <p style="margin:0;color:var(--vs-muted);font-size:0.9rem;">اجعل أي موقع أو أكاديمية أو تطبيق ويب يفتح غرف اجتماعات مباشرة باسمك وبضغطة واحدة.</p>
    </div>
    <a href="<?php echo SITE_URL; ?>/dashboard.php" class="vs-btn vs-btn-outline">العودة للاجتماعات</a>
  </div>

  <?php if ($msg !== ''): ?><div class="vs-card" style="border-color:var(--vs-emerald);color:var(--vs-emerald);margin-bottom:1rem;"><?php echo vsEsc($msg); ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="vs-card" style="border-color:var(--vs-rose);color:var(--vs-rose);margin-bottom:1rem;"><?php echo vsEsc($err); ?></div><?php endif; ?>

  <div class="vs-grid-2" style="align-items:start;">
    <!-- Embed Code Builder -->
    <div class="vs-card">
      <h2 style="margin:0 0 0.9rem;font-size:1.15rem;">مولّد كود التضمين السريع</h2>

      <div class="vs-grid-2" style="margin-bottom:0.85rem;">
        <div>
          <label class="vs-label" for="embRoomSel">اختر غرفة نشطة أو اكتب رمزها</label>
          <input id="embRoomSel" type="text" class="vs-input mono" value="<?php echo vsEsc($selectedCode); ?>" oninput="updateEmbedSnippets()" list="liveCodesList">
          <datalist id="liveCodesList">
            <?php foreach ($liveRooms as $lr): ?>
              <option value="<?php echo vsEsc($lr['code']); ?>"><?php echo vsEsc($lr['title']); ?></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label class="vs-label" for="embThemeSel">مظهر الغرفة المدمجة</label>
          <select id="embThemeSel" class="vs-select" onchange="updateEmbedSnippets()">
            <option value="light">فاتح (Light)</option>
            <option value="dark">داكن (Dark)</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom:1rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;">
          <strong style="font-size:0.85rem;">1. كود HTML مباشرة (&lt;iframe&gt;)</strong>
          <button type="button" onclick="copyPre('snipIframe')" class="vs-btn vs-btn-outline" style="height:30px;padding:0 0.7rem;font-size:0.75rem;">نسخ</button>
        </div>
        <pre id="snipIframe" class="mono" dir="ltr" style="margin:0;padding:0.75rem;border-radius:11px;background:var(--vs-subtle);border:1px solid var(--vs-border);font-size:0.75rem;overflow-x:auto;"></pre>
      </div>

      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;">
          <strong style="font-size:0.85rem;">2. كود JavaScript SDK (مثل Jitsi API)</strong>
          <button type="button" onclick="copyPre('snipJs')" class="vs-btn vs-btn-outline" style="height:30px;padding:0 0.7rem;font-size:0.75rem;">نسخ</button>
        </div>
        <pre id="snipJs" class="mono" dir="ltr" style="margin:0;padding:0.75rem;border-radius:11px;background:var(--vs-subtle);border:1px solid var(--vs-border);font-size:0.75rem;overflow-x:auto;"></pre>
      </div>
    </div>

    <!-- API Keys for External Room Creation -->
    <div class="vs-card">
      <h2 style="margin:0 0 0.9rem;font-size:1.15rem;">مفاتيح الربط البرمجي (API Keys)</h2>
      <p style="margin:0 0 0.9rem;font-size:0.83rem;color:var(--vs-muted);">استخدم مفتاحاً لإنشاء غرف الاجتماعات تلقائياً من داخل منصاتك الأخرى عبر طلب POST.</p>

      <form method="POST" style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
        <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
        <input type="hidden" name="action" value="create_key">
        <input type="text" name="label" class="vs-input" placeholder="اسم المنصة (مثلاً: موقع التدريب)" required style="flex:1;min-width:170px;">
        <button type="submit" class="vs-btn vs-btn-primary">توليد مفتاح</button>
      </form>

      <?php if (!empty($keys)): ?>
        <div style="display:flex;flex-direction:column;gap:0.55rem;margin-bottom:1rem;">
          <?php foreach ($keys as $k): ?>
            <div style="border:1px solid var(--vs-border);border-radius:11px;padding:0.65rem 0.75rem;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
              <div style="min-width:0;flex:1;">
                <div style="font-weight:700;font-size:0.84rem;"><?php echo vsEsc($k['label']); ?></div>
                <div class="mono" dir="ltr" style="font-size:0.74rem;color:var(--vs-muted);overflow:hidden;text-overflow:ellipsis;"><?php echo vsEsc($k['api_key']); ?></div>
              </div>
              <div style="display:flex;gap:0.35rem;">
                <button type="button" onclick="navigator.clipboard.writeText(<?php echo json_encode($k['api_key']); ?>);Swal.fire({toast:true,position:'top-end',icon:'success',title:'تم نسخ المفتاح',timer:1500,showConfirmButton:false})" class="vs-btn vs-btn-outline" style="height:30px;padding:0 0.65rem;font-size:0.74rem;">نسخ</button>
                <form method="POST" style="margin:0;">
                  <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
                  <input type="hidden" name="action" value="revoke_key">
                  <input type="hidden" name="key_id" value="<?php echo (int)$k['id']; ?>">
                  <button type="submit" class="vs-btn vs-btn-danger" style="height:30px;padding:0 0.65rem;font-size:0.74rem;">تعطيل</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;">
          <strong style="font-size:0.85rem;">3. مثال PHP لإنشاء اجتماع من موقع آخر</strong>
          <button type="button" onclick="copyPre('snipPhp')" class="vs-btn vs-btn-outline" style="height:30px;padding:0 0.7rem;font-size:0.75rem;">نسخ</button>
        </div>
        <pre id="snipPhp" class="mono" dir="ltr" style="margin:0;padding:0.75rem;border-radius:11px;background:var(--vs-subtle);border:1px solid var(--vs-border);font-size:0.73rem;overflow-x:auto;"></pre>
      </div>
    </div>
  </div>
</div>

<script>
var BASE_URL = <?php echo json_encode(SITE_URL, JSON_UNESCAPED_SLASHES); ?>;
var FIRST_KEY = <?php echo json_encode($keys[0]['api_key'] ?? 'YOUR_API_KEY'); ?>;

function updateEmbedSnippets() {
  var code = (document.getElementById('embRoomSel').value || 'ROOM123').trim().toUpperCase();
  var theme = document.getElementById('embThemeSel').value || 'light';

  document.getElementById('snipIframe').textContent =
    '<iframe\n' +
    '  src="' + BASE_URL + '/room.php?code=' + encodeURIComponent(code) + '&embed=1&theme=' + theme + '&name=Guest"\n' +
    '  style="width:100%;height:650px;border:0;border-radius:16px;"\n' +
    '  allow="microphone; camera; display-capture; autoplay; clipboard-write; fullscreen"\n' +
    '  allowfullscreen>\n' +
    '</iframe>';

  document.getElementById('snipJs').textContent =
    '<div id="meeting-box"></div>\n' +
    '<script src="' + BASE_URL + '/assets/js/embed.js"><\/script>\n' +
    '<script>\n' +
    '  const meet = new AbuAnasMeetings("#meeting-box", {\n' +
    '    baseUrl: "' + BASE_URL + '",\n' +
    '    roomCode: "' + code + '",\n' +
    '    displayName: "اسم الطالب أو الضيف",\n' +
    '    theme: "' + theme + '",\n' +
    '    height: "650px",\n' +
    '    onLeft: () => console.log("Left meeting")\n' +
    '  });\n' +
    '<\/script>';

  document.getElementById('snipPhp').textContent =
    '$ch = curl_init("' + BASE_URL + '/api/external_room.php");\n' +
    'curl_setopt_array($ch, [\n' +
    '  CURLOPT_POST => true,\n' +
    '  CURLOPT_RETURNTRANSFER => true,\n' +
    '  CURLOPT_HTTPHEADER => ["Content-Type: application/json", "X-API-KEY: ' + FIRST_KEY + '"],\n' +
    '  CURLOPT_POSTFIELDS => json_encode([\n' +
    '    "title" => "جلسة مباشرة",\n' +
    '    "preset" => "classroom",\n' +
    '    "media_mode" => "video",\n' +
    '    "whiteboard_enabled" => 1\n' +
    '  ])\n' +
    ']);\n' +
    '$res = json_decode(curl_exec($ch), true);\n' +
    '// $res["join_url"] , $res["embed_url"]';
}
function copyPre(id) {
  navigator.clipboard.writeText(document.getElementById(id).textContent);
  Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'تم النسخ', timer: 1600, showConfirmButton: false });
}
updateEmbedSnippets();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
