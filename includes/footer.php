</main>
<footer class="vs-footer">
  <div class="vs-container" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
    <div style="display:flex;align-items:center;gap:0.55rem;">
      <img src="<?php echo SITE_URL; ?>/assets/images/logo-mark.png" alt="Logo" style="width:22px;height:22px;object-fit:contain;">
      <strong>أبو أنس ميتينجز (Abu Anas Meetings)</strong> — منصة اجتماعات وبث مباشر مفتوحة المصدر ومجانية
    </div>
    <div style="font-size:0.8rem;">صُممت لتعمل مستقلة أو مدمجة داخل منصاتك الأخرى</div>
  </div>
</footer>
<script>
function vsSyncThemeIcon() {
  var icon = document.getElementById('vsThemeIcon');
  if (!icon) return;
  icon.className = document.documentElement.classList.contains('dark') ? 'fas fa-sun' : 'fas fa-moon';
}
function vsToggleTheme() {
  var next = !document.documentElement.classList.contains('dark');
  document.documentElement.classList.toggle('dark', next);
  localStorage.setItem('vs_theme', next ? 'dark' : 'light');
  vsSyncThemeIcon();
}
vsSyncThemeIcon();
</script>
</body>
</html>
