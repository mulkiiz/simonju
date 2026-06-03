</main>

<!-- Loading overlay (dipakai untuk Crawl & Scan Judol) -->
<div id="actionOverlay" class="crawl-overlay" aria-hidden="true">
  <div class="crawl-modal" role="alertdialog" aria-live="assertive">
    <div class="crawl-spinner"></div>
    <h3 class="crawl-title" id="actionTitle">Sedang Memproses</h3>
    <p class="crawl-subtitle" id="actionSubtitle">
      Mohon tunggu sebentar.
    </p>
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
(function () {
  // Konfigurasi overlay per jenis aksi
  var ACTIONS = {
    'crawl_run.php': {
      title: 'Sedang Crawl Jurnal',
      subtitle: 'Bot sedang membaca halaman archive dan menghitung jumlah artikel per issue.',
      tip: 'Proses ini bisa memakan waktu hingga 1–2 menit untuk jurnal dengan banyak issue. Mohon jangan tutup tab.'
    },
    'crawl_run_all.php': {
      title: 'Sedang Crawl Semua Jurnal',
      subtitle: 'Halaman akan menampilkan progress real-time. Mohon tunggu...',
      tip: 'Proses ini bisa memakan beberapa menit tergantung jumlah jurnal.'
    },
    'scan_judol_run.php': {
      title: '🛡️ Sedang Scan Judol',
      subtitle: 'Scanner sedang membaca halaman dengan dua User-Agent (browser & Googlebot) untuk mendeteksi cloaking dan injeksi judol.',
      tip: 'Proses ini bisa memakan 30–60 detik. Mohon jangan tutup tab.'
    },
    'scan_judol_run_all.php': {
      title: '🛡️ Sedang Scan Semua Jurnal',
      subtitle: 'Halaman akan menampilkan progress real-time. Mohon tunggu...',
      tip: 'Proses ini bisa memakan beberapa menit tergantung jumlah jurnal.'
    }
  };

  function escapeHTML(s) {
    return s.replace(/[<>&"']/g, function(c){
      return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function getActionKey(actionAttr) {
    if (!actionAttr) return null;
    for (var key in ACTIONS) {
      if (actionAttr === key || actionAttr.indexOf('/' + key) !== -1
          || actionAttr.endsWith('/' + key) || actionAttr === key) {
        return key;
      }
    }
    return null;
  }

  var overlay   = document.getElementById('actionOverlay');
  var titleEl   = document.getElementById('actionTitle');
  var subtitle  = document.getElementById('actionSubtitle');
  var tipEl     = document.getElementById('actionTip');

  var forms = document.querySelectorAll('form');
  forms.forEach(function (form) {
    var actionAttr = form.getAttribute('action') || '';
    var key = getActionKey(actionAttr);
    if (!key) return;

    form.addEventListener('submit', function () {
      var cfg = ACTIONS[key];

      // Coba detect nama jurnal dari row tabel atau header
      var nama = '';
      var row = form.closest('tr');
      if (row) {
        var link = row.querySelector('strong a');
        if (link) nama = link.innerText.trim();
      }
      if (!nama) {
        var h1 = document.querySelector('.page-head h1');
        if (h1) nama = h1.innerText.trim();
      }

      titleEl.textContent = cfg.title;
      var sub = cfg.subtitle;
      if (nama && key !== 'scan_judol_run_all.php') {
        sub = sub.replace(
          /halaman( archive)?/i,
          'halaman$1 jurnal <strong>' + escapeHTML(nama) + '</strong>'
        );
      }
      subtitle.innerHTML = sub;
      tipEl.textContent = cfg.tip;

      overlay.classList.add('show');
      overlay.setAttribute('aria-hidden', 'false');

      // Disable tombol supaya tidak double-submit
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.6';
      }
    });
  });

  // Reset overlay kalau page ditampilkan dari bfcache (back button)
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      overlay.classList.remove('show');
      overlay.setAttribute('aria-hidden', 'true');
    }
  });
})();
</script>

</body>
</html>
