<?php
/**
 * Admin Multi-Engine Router & Provider Keys Settings
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

Auth::requireAdmin();
$db = Database::getInstance()->connect();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vsVerifyCsrf()) {
        $err = 'انتهت صلاحية الجلسة.';
    } else {
        $pkey = trim((string)($_POST['provider_key'] ?? ''));
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $priority = max(1, min(99, (int)($_POST['priority'] ?? 10)));
        $limit = max(0, (float)($_POST['monthly_limit'] ?? 0));
        $cfg = is_array($_POST['cfg'] ?? null) ? array_map(static fn($v) => trim((string)$v), $_POST['cfg']) : [];

        $st = $db->prepare('UPDATE provider_configs SET enabled = ?, priority = ?, monthly_limit = ?, config_json = ? WHERE provider_key = ?');
        $st->execute([$enabled, $priority, $limit, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $pkey]);
        $msg = 'تم تحديث إعدادات المزوّد (' . $pkey . ') بنجاح.';
    }
}

$providers = EngineRouter::getProviders($db);
$fieldMap = [
    'cf_ws'      => ['ws_url' => 'رابط Cloudflare Worker WSS (مثل https://...workers.dev أو wss://...)'],
    'node_relay' => ['ws_url' => 'رابط Node.js Relay (محلي ws://127.0.0.1:8788 أو Render wss://...)'],
    'cf_sfu'     => ['app_id' => 'Cloudflare Calls App ID', 'app_secret' => 'Cloudflare App Secret'],
    'hms'        => ['access_key' => '100ms Access Key', 'app_secret' => '100ms App Secret', 'template_id' => 'Template ID (اختياري)'],
    'daily'      => ['api_key' => 'Daily.co REST API Key', 'domain' => 'النطاق (مثل team.daily.co)'],
    'livekit'    => ['ws_url' => 'LiveKit WSS URL', 'api_key' => 'LiveKit API Key', 'api_secret' => 'LiveKit API Secret'],
    'r2'         => ['account_id' => 'CF Account ID', 'access_key' => 'R2 Access Key', 'secret_key' => 'R2 Secret Key', 'bucket' => 'Bucket Name', 'public_url' => 'Public URL (اختياري)'],
    'telegram'   => ['bot_token' => 'Telegram Bot Token', 'chat_id' => 'Channel / Chat ID'],
];

$pageTitle = 'الموجّه الذكي والمزودون';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="vs-container" style="padding:2rem 1.25rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.25rem;">
    <div>
      <h1 style="margin:0 0 0.25rem;font-size:1.55rem;">إدارة المحركات والموجّه الذكي (Multi-Engine Router)</h1>
      <p style="margin:0;color:var(--vs-muted);font-size:0.88rem;">اربط مفاتيحك واضبط الأولوية؛ سيوزّع النظام الجلسات تلقائياً للاستفادة من كل الباقات المجانية.</p>
    </div>
    <a href="<?php echo SITE_URL; ?>/admin/monitor.php" class="vs-btn vs-btn-primary"><i class="fas fa-heartbeat"></i> شاشة المراقبة</a>
  </div>

  <?php if ($msg !== ''): ?><div class="vs-card" style="border-color:var(--vs-emerald);color:var(--vs-emerald);margin-bottom:1rem;"><?php echo vsEsc($msg); ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="vs-card" style="border-color:var(--vs-rose);color:var(--vs-rose);margin-bottom:1rem;"><?php echo vsEsc($err); ?></div><?php endif; ?>

  <div class="vs-card" style="margin-bottom:1.25rem;background:var(--vs-accent-soft);border-color:rgba(37,99,235,0.28);">
    <strong style="font-size:0.95rem;">كيف يختار الموجّه المحرك؟</strong>
    <div style="font-size:0.84rem;color:var(--vs-text);margin-top:0.35rem;">
      1) <strong>الصوت + السبورة</strong>: يستخدم <code class="mono">cf_ws</code> أولاً (100k/يوم بحسبة 20:1) ويتحوّل تلقائياً إلى <code class="mono">node_relay</code> عند التعذر.<br>
      2) <strong>الفيديو + الشاشة</strong>: يختار الأعلى أولويةً من المفعّلين والمزوّدين بمفاتيح (<code class="mono">cf_sfu</code> ← <code class="mono">hms</code> ← <code class="mono">daily</code> ← <code class="mono">livekit</code>) مادام استهلاكه أقل من 92%، وإلا ينتقل للتالي أو يعود للصوت+السبورة بسلاسة.
    </div>
  </div>

  <div class="vs-grid-2">
    <?php foreach ($providers as $pk => $pr):
      $fields = $fieldMap[$pk] ?? [];
      $limit = (float)$pr['monthly_limit'];
      $used = (float)$pr['used_units'];
      $pct = $limit > 0 ? min(100, round(($used / $limit) * 100, 1)) : 0;
    ?>
      <div class="vs-card">
        <form method="POST" style="display:flex;flex-direction:column;gap:0.75rem;">
          <input type="hidden" name="csrf_token" value="<?php echo vsEsc(vsCsrfToken()); ?>">
          <input type="hidden" name="provider_key" value="<?php echo vsEsc($pk); ?>">

          <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
            <div>
              <div style="font-weight:800;font-size:0.98rem;"><?php echo vsEsc($pr['label']); ?></div>
              <div class="mono" style="font-size:0.74rem;color:var(--vs-muted);"><?php echo vsEsc($pk); ?> • <?php echo vsEsc($pr['category']); ?></div>
            </div>
            <span class="vs-badge <?php echo $pr['configured'] ? 'vs-badge-live' : 'vs-badge-muted'; ?>">
              <?php echo $pr['configured'] ? 'مضبوط ✓' : 'غير مكتمل'; ?>
            </span>
          </div>

          <?php if ($limit > 0): ?>
            <div>
              <div style="display:flex;justify-content:space-between;font-size:0.76rem;margin-bottom:0.25rem;">
                <span>الاستهلاك (<span class="mono"><?php echo vsEsc($pr['period_key']); ?></span>)</span>
                <span class="mono"><?php echo number_format($used, 1); ?> / <?php echo number_format($limit); ?> (<?php echo $pct; ?>%)</span>
              </div>
              <div class="vs-prog-track"><div class="vs-prog-fill" style="width:<?php echo max(2, $pct); ?>%;"></div></div>
            </div>
          <?php endif; ?>

          <div class="vs-grid-3">
            <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.84rem;font-weight:700;cursor:pointer;">
              <input type="checkbox" name="enabled" value="1" <?php echo (int)$pr['enabled'] === 1 ? 'checked' : ''; ?>>
              <span>مفعّل</span>
            </label>
            <div>
              <label class="vs-label" style="font-size:0.74rem;">الأولوية</label>
              <input type="number" name="priority" class="vs-input mono" value="<?php echo (int)$pr['priority']; ?>" min="1" max="99" style="padding:0.4rem 0.6rem;">
            </div>
            <div>
              <label class="vs-label" style="font-size:0.74rem;">الحد (<?php echo vsEsc($pr['limit_unit']); ?>)</label>
              <input type="number" step="0.1" name="monthly_limit" class="vs-input mono" value="<?php echo vsEsc((string)$pr['monthly_limit']); ?>" style="padding:0.4rem 0.6rem;">
            </div>
          </div>

          <?php foreach ($fields as $fk => $flabel):
            $isSecret = str_contains($fk, 'secret') || str_contains($fk, 'token') || $fk === 'api_key';
            $val = (string)($pr['config'][$fk] ?? '');
          ?>
            <div>
              <label class="vs-label" style="font-size:0.76rem;"><?php echo vsEsc($flabel); ?></label>
              <input type="<?php echo $isSecret ? 'password' : 'text'; ?>" name="cfg[<?php echo vsEsc($fk); ?>]" class="vs-input mono" value="<?php echo vsEsc($val); ?>" placeholder="<?php echo $isSecret ? 'اتركه كما هو أو أدخل المفتاح...' : ''; ?>" style="padding:0.45rem 0.7rem;font-size:0.82rem;">
            </div>
          <?php endforeach; ?>

          <button type="submit" class="vs-btn vs-btn-outline" style="width:100%;">حفظ التغييرات</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
