<?php
$page_title = 'Detail Jurnal';
require_once __DIR__ . '/../includes/header_admin.php';

$id = (int)($_GET['id'] ?? 0);
$j = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$id]);
if (!$j) { echo '<p>Jurnal tidak ditemukan.</p>'; require_once __DIR__.'/../includes/footer.php'; exit; }

// Data editor sekarang dipisah di tabel `editor`
$editor = fetch_one("SELECT * FROM editor WHERE jurnal_id=?", 'i', [$id]) ?: [
    'nama'=>'','email'=>'','no_hp'=>'',
    'scopus_id'=>'','sinta_id'=>'','gscholar_id'=>'',
];

$terbitan = fetch_all(
    "SELECT * FROM terbitan WHERE jurnal_id=? ORDER BY tahun DESC, volume DESC, nomor DESC",
    'i', [$id]
);

$logs = fetch_all(
    "SELECT * FROM crawl_log WHERE jurnal_id=? ORDER BY executed_at DESC LIMIT 10",
    'i', [$id]
);

// Log scan judol
$judol_logs = fetch_all(
    "SELECT * FROM judol_scan_log WHERE jurnal_id=? ORDER BY scanned_at DESC LIMIT 10",
    'i', [$id]
);

// =========================================================
// Helper untuk hyperlink ID akademik
// =========================================================
function ext_link($val, $url_pattern, $label_filled = 'lihat') {
    $val = trim((string)$val);
    if ($val === '') {
        return '<span class="link-empty">[belum diisi]</span>';
    }
    $url = str_replace('{ID}', rawurlencode($val), $url_pattern);
    return '<a href="' . h($url) . '" target="_blank" rel="noopener" class="link-ext">[' . h($label_filled) . '] <span class="ext-arrow">&#8599;</span></a>';
}

// =========================================================
// Agregasi data terbitan untuk grafik (per tahun)
// =========================================================
$by_year = [];
foreach ($terbitan as $t) {
    $y = $t['tahun'] !== '' ? $t['tahun'] : 'N/A';
    if (!isset($by_year[$y])) $by_year[$y] = ['issues' => 0, 'articles' => 0];
    $by_year[$y]['issues']   += 1;
    $by_year[$y]['articles'] += (int)$t['jumlah_artikel'];
}
$years_sorted = array_keys($by_year);
usort($years_sorted, function($a, $b) {
    if ($a === 'N/A') return 1;
    if ($b === 'N/A') return -1;
    return strcmp($a, $b);
});

$total_issues   = count($terbitan);
$total_articles = 0;
foreach ($by_year as $y => $v) $total_articles += $v['articles'];

// Chart: tiap nomor/issue terbitan = 1 bar (dikelompokkan per tahun).
// Tinggi bar = jumlah artikel issue itu. Tahun dgn 2 nomor -> 2 bar.
$issues_by_year = [];
foreach ($terbitan as $t) {
    $y = $t['tahun'] !== '' ? $t['tahun'] : 'N/A';
    $issues_by_year[$y][] = [
        'vol'   => trim((string)$t['volume']),
        'nomor' => trim((string)$t['nomor']),
        'art'   => (int)$t['jumlah_artikel'],
    ];
}
// Urutkan issue dalam tiap tahun: volume lalu nomor (numerik)
foreach ($issues_by_year as $y => &$list) {
    usort($list, function($a, $b) {
        $va = (int)$a['vol']; $vb = (int)$b['vol'];
        if ($va !== $vb) return $va <=> $vb;
        return (int)$a['nomor'] <=> (int)$b['nomor'];
    });
}
unset($list);

// Skala bar: max artikel per issue (lintas semua tahun)
$max_issue_articles = 0;
foreach ($issues_by_year as $list) {
    foreach ($list as $it) if ($it['art'] > $max_issue_articles) $max_issue_articles = $it['art'];
}
?>
<div class="page-head">
  <div>
    <h1><?= h($j['nama_jurnal']) ?></h1>
    <a href="<?= h($j['url_archive']) ?>" target="_blank" rel="noopener" class="muted small">
      <span class="ico">&#128279;</span> <?= h($j['url_archive']) ?> &#8599;
    </a>
  </div>
  <div>
    <a href="jurnal_form.php?id=<?= (int)$j['id'] ?>" class="btn btn-edit">✏️ Edit</a>
    <form method="post" action="crawl_run.php" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="jurnal_id" value="<?= (int)$j['id'] ?>">
      <button class="btn btn-primary" type="submit"><span class="ico">&#128260;</span> Crawl Sekarang</button>
    </form>
    <form method="post" action="scan_judol_run.php" style="display:inline" onsubmit="return confirm('Scan judol (judi online) untuk jurnal ini?');">
      <?= csrf_field() ?>
      <input type="hidden" name="jurnal_id" value="<?= (int)$j['id'] ?>">
      <button class="btn btn-scan" type="submit">🛡️ Scan Judol</button>
    </form>
    <form id="deleteJurnalForm" method="post" action="jurnal_delete.php" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="jurnal_id" value="<?= (int)$j['id'] ?>">
      <input type="hidden" name="confirm_name" id="deleteConfirmName" value="">
      <button class="btn btn-danger" type="button" id="deleteJurnalBtn">🗑️ Hapus Jurnal</button>
    </form>
  </div>
</div>

<script>
(function () {
  var btn  = document.getElementById('deleteJurnalBtn');
  var form = document.getElementById('deleteJurnalForm');
  var hid  = document.getElementById('deleteConfirmName');
  if (!btn) return;
  var nama = <?= json_encode($j['nama_jurnal'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
  btn.addEventListener('click', function () {
    var msg = 'PERINGATAN: Hapus jurnal ini PERMANEN beserta SEMUA data terkait ' +
      '(terbitan, log crawl, log scan judol, akreditasi, konfirmasi, akun login, editor, file cover/sertifikat).\n\n' +
      'Ketik nama jurnal persis untuk konfirmasi:\n' + nama;
    var typed = window.prompt(msg, '');
    if (typed === null) return;            // batal
    if (typed.trim() !== nama.trim()) {
      alert('Nama tidak cocok. Hapus dibatalkan.');
      return;
    }
    hid.value = typed.trim();
    form.submit();
  });
})();
</script>

<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-info">Data tersimpan.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
  <div class="alert alert-<?= $_GET['deleted']==='ok'?'info':'error' ?>">
    <?= h($_GET['msg'] ?? 'Selesai.') ?>
  </div>
<?php endif; ?>
<?php if (isset($_GET['crawled'])): ?>
  <?php $cs = $_GET['crawled']; ?>
  <div class="alert alert-<?= $cs==='ok'?'info':'error' ?>">
    <?= h($_GET['msg'] ?? 'Crawl selesai.') ?>
  </div>
<?php endif; ?>
<?php if (isset($_GET['scanned'])): ?>
  <?php $ss = $_GET['scanned']; ?>
  <div class="alert alert-<?= $ss==='ok'?'info':'error' ?>">
    <?= h($_GET['msg'] ?? 'Scan judol selesai.') ?>
  </div>
<?php endif; ?>

<section class="card-grid">
  <!-- IDENTITAS -->
  <div class="card">
    <h3><span class="ico">&#128218;</span> Identitas</h3>
    <dl>
      <dt><span class="ico">&#127991;&#65039;</span> ISSN</dt>
      <dd><?= h($j['issn'] ?: '—') ?></dd>

      <dt><span class="ico">&#128279;</span> DOI Prefix</dt>
      <dd><?= h($j['doi'] ?: '—') ?></dd>

      <dt><span class="ico">&#127942;</span> Akreditasi</dt>
      <dd>
        <?php
          $aj  = isset($j['akreditasi_jenis']) ? $j['akreditasi_jenis'] : 'belum';
          $ap  = isset($j['akreditasi_peringkat']) ? $j['akreditasi_peringkat'] : '';
          $aurl= isset($j['akreditasi_url']) ? $j['akreditasi_url'] : '';
          if ($aj === 'sinta' && $ap !== ''):
            $cls = 'akr-sinta-' . preg_replace('/[^0-9]/', '', $ap);
        ?>
          <span class="akr-badge <?= h($cls) ?>"><?= h($ap) ?></span>
          <?php if ($aurl): ?>
            <a href="<?= h($aurl) ?>" target="_blank" rel="noopener" class="link-ext">[lihat] <span class="ext-arrow">&#8599;</span></a>
          <?php endif; ?>
        <?php elseif ($aj === 'scopus' && $ap !== ''):
            $cls = 'akr-scopus-' . strtolower($ap);
        ?>
          <span class="akr-badge <?= h($cls) ?>">Scopus <?= h($ap) ?></span>
          <?php if ($aurl): ?>
            <a href="<?= h($aurl) ?>" target="_blank" rel="noopener" class="link-ext">[scimago] <span class="ext-arrow">&#8599;</span></a>
          <?php endif; ?>
        <?php else: ?>
          <span class="akr-badge akr-belum">Belum Terakreditasi</span>
        <?php endif; ?>
        <?php if (!empty($j['file_sertifikat'])): ?>
          <div style="margin-top:6px">
            <a href="../uploads/jurnal/<?= h($j['file_sertifikat']) ?>" target="_blank" rel="noopener" class="link-ext">
              📄 [lihat sertifikat] <span class="ext-arrow">&#8599;</span>
            </a>
          </div>
        <?php endif; ?>
      </dd>

      <dt><span class="ico">&#128197;</span> Frekuensi</dt>
      <dd><?= nl2br(h($j['frekuensi_terbit'] ?: '—')) ?></dd>
    </dl>
  </div>

  <!-- KETUA EDITOR (dari tabel `editor`) -->
  <div class="card card-editor">
    <h3><span class="ico">&#128100;</span> Ketua Editor</h3>
    <?php if (!empty($editor['nama'])): ?>
      <div class="editor-name"><?= h($editor['nama']) ?></div>
    <?php else: ?>
      <div class="editor-name muted">[belum diisi]</div>
    <?php endif; ?>
    <div class="editor-contacts">
      <?php if (!empty($editor['email']) && filter_var($editor['email'], FILTER_VALIDATE_EMAIL)): ?>
        <a href="mailto:<?= h($editor['email']) ?>" class="editor-link" title="Email">
          <span class="ico">📧</span> <?= h($editor['email']) ?>
        </a>
      <?php endif; ?>
      <?php
        $hp = trim($editor['no_hp'] ?? '');
        if ($hp !== ''):
          // Format nomor untuk wa.me: 08xx → 628xx, +62xx → 62xx
          $wa = preg_replace('/[^0-9]/', '', $hp);
          if (substr($wa, 0, 1) === '0') $wa = '62' . substr($wa, 1);
      ?>
        <a href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener" class="editor-link editor-link-wa" title="WhatsApp">
          <span class="ico">💬</span> <?= h($hp) ?>
        </a>
      <?php endif; ?>
    </div>
    <div class="editor-ids">
      <div class="editor-id-item">
        <span class="editor-id-label">Scopus</span>
        <?= ext_link($editor['scopus_id'], 'https://www.scopus.com/authid/detail.uri?authorId={ID}') ?>
      </div>
      <div class="editor-id-item">
        <span class="editor-id-label">Sinta</span>
        <?= ext_link($editor['sinta_id'], 'https://sinta.kemdiktisaintek.go.id/authors/profile/{ID}') ?>
      </div>
      <div class="editor-id-item">
        <span class="editor-id-label">GScholar</span>
        <?= ext_link($editor['gscholar_id'], 'https://scholar.google.com/citations?user={ID}') ?>
      </div>
    </div>
  </div>

  <!-- STATUS CRAWL -->
  <div class="card">
    <h3><span class="ico">&#128270;</span> Status Crawl</h3>
    <dl>
      <dt><span class="ico">&#128337;</span> Terakhir</dt>
      <dd><?= h($j['last_crawled_at'] ?: '—') ?></dd>

      <dt><span class="ico">&#9989;</span> Status</dt>
      <dd>
        <?php if ($j['last_crawl_status']): ?>
          <span class="badge badge-<?= h($j['last_crawl_status']) ?>"><?= h($j['last_crawl_status']) ?></span>
        <?php else: ?>—<?php endif; ?>
      </dd>

      <dt><span class="ico">&#128218;</span> Total Terbitan</dt>
      <dd><strong><?= (int)$total_issues ?></strong> issue</dd>

      <dt><span class="ico">&#128196;</span> Total Artikel</dt>
      <dd><strong><?= (int)$total_articles ?></strong> artikel</dd>
    </dl>
  </div>

  <!-- STATUS SCAN JUDOL -->
  <div class="card">
    <h3>🛡️ Scan Judol</h3>
    <dl>
      <dt>🕐 Terakhir</dt>
      <dd><?= h($j['last_judol_scan_at'] ?: '—') ?></dd>

      <dt>📊 Skor</dt>
      <dd>
        <?php if ($j['last_judol_scan_at']):
          $jsc = (int)$j['last_judol_score'];
          $jlb = $j['last_judol_label'] ?? '—';
          $jcls = 'badge-success';
          if ($jsc >= 50) $jcls = 'badge-failed';
          elseif ($jsc >= 25) $jcls = 'badge-partial';
        ?>
          <span class="badge <?= $jcls ?>"><?= $jsc ?>/100 — <?= h($jlb) ?></span>
        <?php else: ?>
          <span class="muted">belum pernah</span>
        <?php endif; ?>
      </dd>

      <dt>📋 Detail</dt>
      <dd>
        <?php if ($j['last_judol_scan_at']): ?>
          <a href="scan_judol_detail.php?jurnal=<?= (int)$j['id'] ?>" class="btn btn-sm">Lihat Detail</a>
        <?php else: ?>—<?php endif; ?>
      </dd>
    </dl>
  </div>
</section>

<h2><span class="ico">&#128202;</span> Riwayat Terbitan</h2>

<?php if (empty($terbitan)): ?>
  <p class="muted">Belum ada data terbitan. Klik "Crawl Sekarang" untuk mengambil data.</p>
<?php else: ?>

<!-- ============== DASHBOARD GRAFIK (Pure CSS, no library) ============== -->
<section class="dashboard">
  <div class="dash-toolbar">
    <label class="filter-label">
      <span class="ico">&#128197;</span> Filter Tahun:
      <select id="filterYear">
        <option value="all">Semua Tahun</option>
        <?php foreach ($years_sorted as $y): ?>
          <option value="<?= h($y) ?>"><?= h($y) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="dash-stats">
      <span class="dash-stat">
        <span class="ico">&#128218;</span>
        <strong id="statIssues"><?= (int)$total_issues ?></strong> issue
      </span>
      <span class="dash-stat">
        <span class="ico">&#128196;</span>
        <strong id="statArticles"><?= (int)$total_articles ?></strong> artikel
      </span>
    </div>
  </div>

  <div class="chart-card">
    <div class="chart-legend">
      <span class="legend-item"><span class="legend-swatch sw-articles"></span> Jumlah artikel per nomor terbit</span>
    </div>

    <?php
      // Palet warna konsisten; urutan ke-n dalam tiap tahun pakai warna ke-n.
      // 2 bar -> biru,hijau ; 3 bar -> biru,hijau,oranye ; dst. Reset tiap tahun.
      $palette = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4',
                  '#ec4899','#84cc16'];
    ?>
    <div class="bar-chart" id="barChart">
      <?php foreach ($years_sorted as $y):
        $list = $issues_by_year[$y];
      ?>
      <div class="bar-group" data-year="<?= h($y) ?>" role="button" tabindex="0"
           title="Klik untuk filter tahun <?= h($y) ?>">
        <div class="bars">
          <?php foreach ($list as $i => $it):
            $art = $it['art'];
            $h_art = $max_issue_articles > 0 ? round($art / $max_issue_articles * 100) : 0;
            $color = $palette[$i % count($palette)];
            $titleTxt = 'Vol ' . ($it['vol'] !== '' ? $it['vol'] : '?') . ' No ' . ($it['nomor'] !== '' ? $it['nomor'] : '?') . ' · ' . $art . ' artikel';
          ?>
          <div class="bar" style="height: <?= $h_art ?>%;background:<?= $color ?>;border-color:<?= $color ?>"
               data-value="<?= $art ?>" title="<?= h($titleTxt) ?>">
            <span class="bar-label"><?= (int)$art ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="bar-year"><?= h($y) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="muted small chart-hint">Tiap bar = 1 nomor terbit (Vol·No). Klik tahun pada grafik untuk filter tabel, atau gunakan dropdown di atas.</p>
  </div>
</section>

<!-- ============== TABEL DETAIL ============== -->
<div class="table-section">
  <h3 id="tableTitle">
    <span class="ico">&#128203;</span> Daftar Terbitan &mdash;
    <span id="tableYearLabel">Semua Tahun</span>
  </h3>
  <div class="table-wrap">
    <table class="table" id="terbitanTable">
      <thead>
        <tr>
          <th>Judul Issue</th>
          <th>Vol</th><th>No</th><th>Tahun</th>
          <th>Pubdate</th>
          <th class="num">Artikel</th>
          <th>Crawled</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($terbitan as $t): ?>
        <tr data-tahun="<?= h($t['tahun'] !== '' ? $t['tahun'] : 'N/A') ?>">
          <td>
            <?php if ($t['issue_url']): ?>
              <a href="<?= h($t['issue_url']) ?>" target="_blank" rel="noopener"><?= h($t['raw_title']) ?></a>
            <?php else: ?>
              <?= h($t['raw_title']) ?>
            <?php endif; ?>
          </td>
          <td><?= h($t['volume']) ?></td>
          <td><?= h($t['nomor']) ?></td>
          <td><?= h($t['tahun']) ?></td>
          <td><?= h($t['pubdate'] ?: '—') ?></td>
          <td class="num"><?= (int)$t['jumlah_artikel'] ?></td>
          <td class="muted small"><?= h($t['crawled_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted small" id="emptyHint" style="display:none">Tidak ada terbitan di tahun terpilih.</p>

  <!-- Pagination controls -->
  <nav class="pagination" id="pagination">
    <div class="pg-info">
      <label class="pg-pagesize">
        Tampilkan
        <select id="pageSize">
          <option value="10" selected>10</option>
          <option value="25">25</option>
          <option value="50">50</option>
          <option value="100">100</option>
        </select>
        per halaman
      </label>
      <span class="pg-summary" id="pgSummary"></span>
    </div>
    <div class="pg-buttons" id="pgButtons"></div>
  </nav>
</div>

<!-- ============== Filter + pagination logic (pure JS) ============== -->
<script>
(function () {
  var rows = Array.prototype.slice.call(
    document.querySelectorAll('#terbitanTable tbody tr')
  );
  var bars = document.querySelectorAll('#barChart .bar-group');

  var state = {
    year: 'all',
    page: 1,
    pageSize: 10,
    filteredRows: rows.slice()
  };

  function applyFilter(year) {
    state.year = year;
    state.page = 1;
    state.filteredRows = (year === 'all')
      ? rows.slice()
      : rows.filter(function (tr) { return tr.getAttribute('data-tahun') === year; });

    // update label & dropdown
    document.getElementById('tableYearLabel').innerText =
      year === 'all' ? 'Semua Tahun' : 'Tahun ' + year;
    document.getElementById('filterYear').value = year;

    // update bar highlight
    bars.forEach(function (b) {
      var by = b.getAttribute('data-year');
      if (year === 'all' || by === year) {
        b.classList.remove('dim');
        b.classList.toggle('active', year !== 'all' && by === year);
      } else {
        b.classList.add('dim');
        b.classList.remove('active');
      }
    });

    // update statistik aggregat (semua hasil filter, bukan hanya per page)
    var totalIssues = state.filteredRows.length;
    var totalArticles = 0;
    state.filteredRows.forEach(function (tr) {
      totalArticles += parseInt(tr.cells[5].innerText, 10) || 0;
    });
    document.getElementById('statIssues').innerText   = totalIssues;
    document.getElementById('statArticles').innerText = totalArticles;
    document.getElementById('emptyHint').style.display = totalIssues === 0 ? '' : 'none';

    renderPage();
  }

  function renderPage() {
    var fr = state.filteredRows;
    var size = state.pageSize;
    var totalPages = Math.max(1, Math.ceil(fr.length / size));
    if (state.page > totalPages) state.page = totalPages;

    // sembunyikan semua, kemudian tampilkan slice halaman saat ini
    rows.forEach(function (tr) { tr.style.display = 'none'; });
    var start = (state.page - 1) * size;
    var end = start + size;
    fr.slice(start, end).forEach(function (tr) { tr.style.display = ''; });

    // info ringkasan
    var summary = document.getElementById('pgSummary');
    if (fr.length === 0) {
      summary.innerText = '0 data';
    } else {
      summary.innerText = 'Menampilkan ' + (start + 1) + '–' +
        Math.min(end, fr.length) + ' dari ' + fr.length + ' data';
    }

    // tombol halaman
    var btnBox = document.getElementById('pgButtons');
    btnBox.innerHTML = '';
    if (totalPages <= 1) return;

    function makeBtn(label, page, opts) {
      opts = opts || {};
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'pg-btn' + (opts.active ? ' active' : '') + (opts.disabled ? ' disabled' : '');
      b.innerHTML = label;
      if (!opts.disabled) {
        b.addEventListener('click', function () { state.page = page; renderPage(); });
      } else {
        b.disabled = true;
      }
      btnBox.appendChild(b);
    }

    makeBtn('&laquo;', state.page - 1, { disabled: state.page === 1 });

    // window halaman: tampilkan max 7 tombol (current ± 3, dengan ellipsis)
    var maxBtns = 7;
    var startP = Math.max(1, state.page - 3);
    var endP = Math.min(totalPages, startP + maxBtns - 1);
    if (endP - startP < maxBtns - 1) startP = Math.max(1, endP - maxBtns + 1);

    if (startP > 1) {
      makeBtn('1', 1);
      if (startP > 2) {
        var dots = document.createElement('span');
        dots.className = 'pg-dots';
        dots.innerText = '…';
        btnBox.appendChild(dots);
      }
    }
    for (var p = startP; p <= endP; p++) {
      makeBtn(String(p), p, { active: p === state.page });
    }
    if (endP < totalPages) {
      if (endP < totalPages - 1) {
        var dots2 = document.createElement('span');
        dots2.className = 'pg-dots';
        dots2.innerText = '…';
        btnBox.appendChild(dots2);
      }
      makeBtn(String(totalPages), totalPages);
    }

    makeBtn('&raquo;', state.page + 1, { disabled: state.page === totalPages });
  }

  // event handlers
  document.getElementById('filterYear').addEventListener('change', function (e) {
    applyFilter(e.target.value);
  });
  document.getElementById('pageSize').addEventListener('change', function (e) {
    state.pageSize = parseInt(e.target.value, 10);
    state.page = 1;
    renderPage();
  });
  bars.forEach(function (b) {
    var year = b.getAttribute('data-year');
    b.addEventListener('click', function () { applyFilter(year); });
    b.addEventListener('keypress', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); applyFilter(year); }
    });
  });

  // initial render
  applyFilter('all');
})();
</script>

<?php endif; /* ada terbitan */ ?>

<?php if (!empty($logs)): ?>
<h2><span class="ico">&#128221;</span> Log Crawler (10 terakhir)</h2>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Waktu</th><th>Trigger</th><th>Status</th><th class="num">Found</th><th class="num">New</th><th>Pesan</th></tr></thead>
  <tbody>
  <?php foreach ($logs as $l): ?>
    <tr>
      <td><?= h($l['executed_at']) ?></td>
      <td><?= h($l['trigger_type']) ?></td>
      <td><span class="badge badge-<?= h($l['status']) ?>"><?= h($l['status']) ?></span></td>
      <td class="num"><?= (int)$l['issues_found'] ?></td>
      <td class="num"><?= (int)$l['issues_new'] ?></td>
      <td class="small"><?= h($l['message']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if (!empty($judol_logs)): ?>
<h2>🛡️ Log Scan Judol (10 terakhir)</h2>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Waktu</th><th>Status</th><th class="num">Skor</th><th>Label</th><th>Normal</th><th>Bot</th><th>Cloaking</th></tr></thead>
  <tbody>
  <?php foreach ($judol_logs as $jl):
    $jsc = (int)$jl['risk_score'];
    $jcls = 'badge-success';
    if ($jsc >= 50) $jcls = 'badge-failed';
    elseif ($jsc >= 25) $jcls = 'badge-partial';
  ?>
    <tr>
      <td><?= h($jl['scanned_at']) ?></td>
      <td><span class="badge badge-<?= $jl['scan_status']==='ok'?'success':'failed' ?>"><?= h($jl['scan_status']) ?></span></td>
      <td class="num"><span class="badge <?= $jcls ?>"><?= $jsc ?>/100</span></td>
      <td><?= h($jl['risk_label'] ?? '—') ?></td>
      <td class="small"><?= h($jl['normal_type'] ?? '—') ?></td>
      <td class="small"><?= h($jl['bot_type'] ?? '—') ?></td>
      <td><?= $jl['cloaking_detected'] ? '<span class="badge badge-failed">YA</span>' : '<span class="muted">—</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
