</main>

<!-- Loading overlay -->
<div id="actionOverlay" class="crawl-overlay" aria-hidden="true">
  <div class="crawl-modal" role="alertdialog" aria-live="assertive">
    <div class="crawl-spinner"></div>
    <h3 class="crawl-title" id="actionTitle">Sedang Memproses</h3>
    <p class="crawl-subtitle" id="actionSubtitle">Mohon tunggu sebentar.</p>
    <div class="crawl-progress"><div class="crawl-progress-bar"></div></div>
    <p class="crawl-tip" id="actionTip">Proses ini bisa memakan waktu hingga 1–2 menit. Mohon jangan tutup tab.</p>
  </div>
</div>

<footer class="footer">
  <div class="container">
    <small>&copy; <?= date('Y') ?> <?= h(APP_NAME) ?> &middot; ppj.jurnalsinta.id</small>
  </div>
</footer>

<script>
// Overlay helper
const overlay={
  el:document.getElementById('actionOverlay'),
  title:document.getElementById('actionTitle'),
  sub:document.getElementById('actionSubtitle'),
  tip:document.getElementById('actionTip'),
  show(t,s,tip){this.title.textContent=t||'Sedang Memproses';this.sub.textContent=s||'Mohon tunggu.';if(tip)this.tip.textContent=tip;this.el.classList.add('show');this.el.setAttribute('aria-hidden','false')},
  hide(){this.el.classList.remove('show');this.el.setAttribute('aria-hidden','true')}
};
document.querySelectorAll('form[action*="crawl_run"],form[action*="scan_judol_run"]').forEach(f=>{
  f.addEventListener('submit',()=>overlay.show('Sedang Memproses','Mohon tunggu...'));
});
</script>
</body>
</html>
