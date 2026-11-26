<?php
/**
 * Abu Anas Meetings Admin Server & Quota Monitor
 * 15s auto-refresh paused when hidden + 5-ping startup probe + 20:1 Cloudflare billing view
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

Auth::requireAdmin();
$pageTitle = 'مراقبة السيرفرات والكوتا';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="vs-container" style="padding:2rem 1.25rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.25rem;">
    <div>
      <h1 style="margin:0 0 0.25rem;font-size:1.55rem;">مراقبة السيرفرات واستهلاك الباقات المجانية</h1>
      <p style="margin:0;color:var(--vs-muted);font-size:0.86rem;">تحديث خفيف كل 15 ثانية (يتوقف عند إخفاء التبويب) بدون نداء /health مزدوج.</p>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
      <button type="button" id="burstBtn" onclick="vsBurstPing()" class="vs-btn vs-btn-primary"><i class="fas fa-bolt"></i> فحص فوري (5 نبضات)</button>
      <button type="button" onclick="vsTogglePoll()" class="vs-btn vs-btn-outline"><span id="pollLabel">تحديث تلقائي: 15 ث</span></button>
    </div>
  </div>

  <!-- Quota Overview Cards -->
  <div class="vs-grid-4" style="margin-bottom:1.25rem;">
    <div class="vs-card">
      <div style="font-size:0.78rem;color:var(--vs-muted);font-weight:700;">طلبات Cloudflare اليوم (20:1)</div>
      <div id="cfReqVal" class="mono" dir="ltr" style="font-size:1.45rem;font-weight:900;margin:0.35rem 0;text-align:right;">0 / 100,000</div>
      <div id="cfReqSub" style="font-size:0.75rem;color:var(--vs-muted);">رسائل WS الواردة تُحسب 20:1</div>
    </div>
    <div class="vs-card">
      <div style="font-size:0.78rem;color:var(--vs-muted);font-weight:700;">Node.js Relay (باقة 100 GB)</div>
      <div id="relayGbVal" class="mono" dir="ltr" style="font-size:1.45rem;font-weight:900;margin:0.35rem 0;text-align:right;">0.00 GB</div>
      <div style="font-size:0.75rem;color:var(--vs-muted);">باندويدث صادر هذا الشهر</div>
    </div>
    <div class="vs-card">
      <div style="font-size:0.78rem;color:var(--vs-muted);font-weight:700;">رصيد دقائق الفيديو المجمع</div>
      <div id="videoMinsVal" class="mono" dir="ltr" style="font-size:1.45rem;font-weight:900;margin:0.35rem 0;text-align:right;">0 / 25,000</div>
      <div id="videoSfuSub" style="font-size:0.75rem;color:var(--vs-muted);">100ms + Daily + LiveKit</div>
    </div>
    <div class="vs-card">
      <div style="font-size:0.78rem;color:var(--vs-muted);font-weight:700;">بينج مجسّ WebSocket</div>
      <div id="wsRttVal" class="mono" dir="ltr" style="font-size:1.45rem;font-weight:900;margin:0.35rem 0;color:var(--vs-emerald);text-align:right;">-- ms</div>
      <div id="wsStatusSub" style="font-size:0.75rem;color:var(--vs-muted);">تخزين PHP متاح: -- GB</div>
    </div>
  </div>

  <!-- Relay Nodes & Video Pool -->
  <div class="vs-grid-2" style="margin-bottom:1.25rem;">
    <div class="vs-card">
      <h3 style="margin:0 0 0.85rem;font-size:1.05rem;">عقد الصوت النشطة</h3>
      <div id="nodesWrap" style="display:flex;flex-direction:column;gap:0.7rem;font-size:0.84rem;color:var(--vs-muted);">جاري الفحص...</div>
    </div>
    <div class="vs-card">
      <h3 style="margin:0 0 0.85rem;font-size:1.05rem;">توزيع حصص محركات الفيديو</h3>
      <div id="videoPoolWrap" style="display:flex;flex-direction:column;gap:0.65rem;font-size:0.84rem;">جاري التحميل...</div>
    </div>
  </div>

  <!-- Live Rooms & Participants RTT -->
  <div class="vs-grid-2">
    <div class="vs-card">
      <h3 style="margin:0 0 0.85rem;font-size:1.05rem;">الغرف المفتوحة حالياً</h3>
      <div id="liveRoomsWrap" style="font-size:0.84rem;color:var(--vs-muted);">جاري الفحص...</div>
    </div>
    <div class="vs-card">
      <h3 style="margin:0 0 0.85rem;font-size:1.05rem;">جودة اتصال المشاركين (RTT)</h3>
      <div id="clientsTableWrap" style="font-size:0.84rem;color:var(--vs-muted);">جاري الفحص...</div>
    </div>
  </div>
</div>

<script>
(function () {
  var autoPoll = true;
  var probeWs = null;
  var probeBooted = false;
  var lastKey = '';
  var samples = [];

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function sendPing() {
    if (probeWs && probeWs.readyState === WebSocket.OPEN) {
      probeWs.send(JSON.stringify({ type: 'ping', t: Date.now() }));
    }
  }

  function ensureProbe(wp) {
    if (!wp || !wp.wsUrls || !wp.wsUrls.length || !wp.token) return;
    var key = wp.wsUrls[0] + '|' + wp.roomId;
    if (probeWs && (probeWs.readyState === WebSocket.OPEN || probeWs.readyState === WebSocket.CONNECTING) && lastKey === key) return;
    lastKey = key;
    try { if (probeWs) probeWs.close(); } catch (e) {}
    var sep = wp.wsUrls[0].includes('?') ? '&' : '?';
    probeWs = new WebSocket(wp.wsUrls[0] + sep + 'token=' + encodeURIComponent(wp.token));
    probeWs.onopen = async function () {
      if (!probeBooted) {
        probeBooted = true;
        for (var i = 0; i < 5; i++) {
          sendPing();
          await new Promise(function (r) { setTimeout(r, 240); });
        }
      } else {
        sendPing();
      }
    };
    probeWs.onmessage = function (ev) {
      if (typeof ev.data !== 'string') return;
      try {
        var m = JSON.parse(ev.data);
        if (m.type === 'pong') {
          var ms = Math.max(1, Date.now() - Number(m.t || Date.now()));
          samples.push(ms);
          if (samples.length > 20) samples.shift();
          var avg = Math.round(samples.reduce(function (a, b) { return a + b; }, 0) / samples.length);
          document.getElementById('wsRttVal').textContent = avg + ' ms';
        }
      } catch (e) {}
    };
  }

  window.vsBurstPing = async function () {
    for (var i = 0; i < 5; i++) {
      sendPing();
      await new Promise(function (r) { setTimeout(r, 220); });
    }
    await refresh();
  };

  window.vsTogglePoll = function () {
    autoPoll = !autoPoll;
    document.getElementById('pollLabel').textContent = autoPoll ? 'تحديث تلقائي: 15 ث' : 'متوقف مؤقتاً';
    if (autoPoll) refresh();
  };

  async function refresh() {
    try {
      var d = await fetch('../api/monitor_metrics.php', { cache: 'no-store' }).then(function (r) { return r.json(); });
      if (!d || !d.success) return;
      ensureProbe(d.ws_probe);
      sendPing();

      document.getElementById('cfReqVal').textContent = Number(d.cf_day_reqs || 0).toLocaleString() + ' / 100,000';
      document.getElementById('cfReqSub').textContent = d.cf_day_ws_msgs > 0
        ? ('تشمل ' + Number(d.cf_day_ws_msgs).toLocaleString() + ' رسالة WS (20:1)')
        : 'رسائل WS الواردة تُحسب 20:1';
      document.getElementById('relayGbVal').textContent = Number(d.relay_gb_used || 0).toFixed(3) + ' GB';
      document.getElementById('wsStatusSub').textContent = 'تخزين الخادم المتاح: ' + (d.host_server?.disk_free_gb ?? '--') + ' GB • آخر فحص: ' + d.checked_at;

      var vp = d.video_pool || {};
      document.getElementById('videoMinsVal').textContent = Math.round(vp.total_minutes_used || 0) + ' / ' + Number(vp.total_minutes_limit || 0).toLocaleString();
      document.getElementById('videoSfuSub').textContent = 'CF Realtime SFU: ' + (vp.cf_sfu_gb_used || 0) + ' / ' + (vp.cf_sfu_gb_limit || 1000) + ' GB';

      var nodes = Array.isArray(d.nodes) ? d.nodes : [];
      document.getElementById('nodesWrap').innerHTML = nodes.length === 0
        ? 'لا توجد عقد صوتية مضبوطة.'
        : nodes.map(function (n) {
            return '<div style="border:1px solid var(--vs-border);border-radius:12px;padding:0.75rem;display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">' +
              '<div><strong style="color:var(--vs-text);">' + esc(n.label) + '</strong><div class="mono" style="font-size:0.75rem;">' + esc(n.ws_url) + ' • ' + esc(n.runtime || '') + '</div></div>' +
              '<span class="vs-badge ' + (n.reachable ? 'vs-badge-live' : 'vs-badge-amber') + '">' + (n.reachable ? ('متصل ' + n.rtt_ms + 'ms') : 'غير متصل') + '</span>' +
            '</div>';
          }).join('');

      document.getElementById('videoPoolWrap').innerHTML = (vp.providers || []).map(function (p) {
        var pct = p.limit > 0 ? Math.min(100, Math.round((p.used / p.limit) * 100)) : 0;
        return '<div><div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:0.2rem;">' +
          '<span><strong>' + esc(p.label) + '</strong> ' + (p.configured ? '' : '<span style="color:var(--vs-muted);">(بحاجة لمفاتيح)</span>') + '</span>' +
          '<span class="mono">' + p.used + ' / ' + p.limit + '</span></div>' +
          '<div class="vs-prog-track"><div class="vs-prog-fill" style="width:' + Math.max(2, pct) + '%;"></div></div></div>';
      }).join('');

      var rooms = Array.isArray(d.live_rooms) ? d.live_rooms : [];
      document.getElementById('liveRoomsWrap').innerHTML = rooms.length === 0
        ? 'لا توجد غرف بث مفتوحة الآن.'
        : rooms.map(function (r) {
            return '<div style="border:1px solid var(--vs-border);border-radius:11px;padding:0.65rem;margin-bottom:0.5rem;display:flex;justify-content:space-between;align-items:center;">' +
              '<div><strong style="color:var(--vs-text);">' + esc(r.title) + '</strong><div class="mono" style="font-size:0.75rem;">' + esc(r.code) + ' • ' + esc(r.active_engine || r.media_mode) + '</div></div>' +
              '<a href="../room.php?code=' + encodeURIComponent(r.code) + '" class="vs-btn vs-btn-outline" style="height:30px;padding:0 0.7rem;font-size:0.75rem;">دخول</a></div>';
          }).join('');

      var activeNode = nodes.find(function (n) { return n.reachable && n.room; });
      var plist = activeNode?.room?.participants || [];
      document.getElementById('clientsTableWrap').innerHTML = plist.length === 0
        ? 'لا يوجد متصلون في العقدة النشطة حالياً.'
        : '<div class="vs-table-wrap"><table class="vs-table"><thead><tr><th>الاسم</th><th>الدور</th><th>الترميز</th><th>البينج</th></tr></thead><tbody>' +
          plist.map(function (p) {
            return '<tr><td>' + esc(p.name) + '</td><td>' + esc(p.role) + '</td><td class="mono">' + (p.opusSupported ? 'Opus' : 'ADPCM') + '</td><td class="mono">' + (p.lastRttMs > 0 ? p.lastRttMs + ' ms' : '--') + '</td></tr>';
          }).join('') + '</tbody></table></div>';
    } catch (e) {}
  }

  refresh();
  setInterval(function () { if (autoPoll && !document.hidden) refresh(); }, 15000);
  document.addEventListener('visibilitychange', function () { if (autoPoll && !document.hidden) refresh(); });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
