<?php
/**
 * jurnal/index.php — Dashboard akun jurnal.
 * Desain mengikuti admin/jurnal_view.php + fitur upload sertifikat & cover.
 */
$page_title = 'Dashboard Jurnal';
require_once __DIR__ . '/../includes/header_jurnal.php';

$jid = current_jurnal_id();
$j   = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jid]);
if (!$j) { echo '<p>Jurnal tidak ditemukan.</p>'; require_once __DIR__.'/../includes/footer.php'; exit; }

$editor = fetch_one("SELECT * FROM editor WHERE jurnal_id=?", 'i', [$jid]) ?: [
    'nama'=>'','email'=>'','no_hp'=>'',
    'scopus_id'=>'','sinta_id'=>'','gscholar_id'=>'',
];

$akr = fetch_one("SELECT * FROM akreditasi_periode WHERE jurnal_id=? LIMIT 1", 'i', [$jid]) ?: [
    'no_sk'=>'','peringkat'=>'',
    'mulai_volume'=>'','mulai_nomor'=>'','mulai_tahun'=>'',
    'sampai_volume'=>'','sampai_nomor'=>'','sampai_tahun'=>'',
];

// Status akreditasi (form masa berlaku hanya aktif bila sudah terakreditasi)
$is_akreditasi = !in_array((string)($j['akreditasi_jenis'] ?? ''), ['', 'belum'], true)
              || (int)($j['is_scopus'] ?? 0) === 1;
// Peringkat efektif: dari tabel periode, fallback ke jurnals.akreditasi_peringkat
$cur_peringkat = trim((string)$akr['peringkat']) !== ''
    ? trim((string)$akr['peringkat'])
    : trim((string)($j['akreditasi_peringkat'] ?? ''));

$terbitan = fetch_all(
    "SELECT * FROM terbitan WHERE jurnal_id=? ORDER BY tahun DESC, volume DESC, nomor DESC",
    'i', [$jid]
);

$logs = fetch_all(
    "SELECT * FROM crawl_log WHERE jurnal_id=? ORDER BY executed_at DESC LIMIT 10",
    'i', [$jid]
);

$judol_logs = fetch_all(
    "SELECT * FROM judol_scan_log WHERE jurnal_id=? ORDER BY scanned_at DESC LIMIT 10",
    'i', [$jid]
);

// Helper hyperlink ID akademik
function ext_link($val, $url_pattern, $label_filled = 'lihat') {
    $val = trim((string)$val);
    if ($val === '') {
        return '<span class="link-empty">[belum diisi]</span>';
    }
    $url = str_replace('{ID}', rawurlencode($val), $url_pattern);
    return '<a href="' . h($url) . '" target="_blank" rel="noopener" class="link-ext">[' . h($label_filled) . '] <span class="ext-arrow">&#8599;</span></a>';
}

// Agregasi data terbitan untuk grafik
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

$max_issues = 0;
$max_articles = 0;
foreach ($by_year as $v) {
    if ($v['issues']   > $max_issues)   $max_issues   = $v['issues'];
    if ($v['articles'] > $max_articles) $max_articles = $v['articles'];
}

$upload_base = '../uploads/jurnal/';
?>
<div class="page-head">
  <div>
    <h1><?= h($j['nama_jurnal']) ?></h1>
    <a href="<?= h($j['url_archive']) ?>" target="_blank" rel="noopener" class="muted small">
      <span class="ico">&#128279;</span> <?= h($j['url_archive']) ?> &#8599;
    </a>
  </div>
  <div>
    <a href="edit.php" class="btn btn-edit">✏️ Edit Data</a>
    <form method="post" action="crawl_run.php" style="display:inline">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit"><span class="ico">&#128260;</span> Crawl Sekarang</button>
    </form>
    <span title="Coming soon" style="display:inline-block;cursor:not-allowed">
      <button class="btn btn-scan" type="button" disabled
              style="opacity:.55;cursor:not-allowed;pointer-events:none">🛡️ Scan Judol</button>
    </span>
  </div>
</div>

<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-info">Data tersimpan.</div>
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
<?php if (isset($_GET['akr'])): ?>
  <div class="alert alert-<?= $_GET['akr']==='ok'?'info':'error' ?>">
    <?= h($_GET['msg'] ?? 'Akreditasi tersimpan.') ?>
  </div>
<?php endif; ?>
<?php if (isset($_GET['upload'])): ?>
  <div class="alert alert-<?= $_GET['upload']==='ok'?'info':'error' ?>">
    <?= h($_GET['msg'] ?? 'Upload selesai.') ?>
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
      </dd>

      <dt><span class="ico">&#128197;</span> Frekuensi</dt>
      <dd><?= nl2br(h($j['frekuensi_terbit'] ?: '—')) ?></dd>
    </dl>
  </div>

  <!-- KETUA EDITOR -->
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
    </dl>
  </div>

</section>

<!-- BARIS KE-2: upload cards (lebar sama dengan baris di atas, 4 kolom dengan 2 slot kosong) -->
<section class="card-grid" style="grid-template-columns:repeat(2,1fr)">
  <!-- SERTIFIKAT AKREDITASI -->
  <div class="card">
    <h3>📜 Sertifikat Akreditasi</h3>
    <?php if (!empty($j['file_sertifikat'])): ?>
      <p>
        <a href="<?= h($upload_base . $j['file_sertifikat']) ?>" target="_blank" rel="noopener" class="link-ext">
          📄 Lihat sertifikat <span class="ext-arrow">&#8599;</span>
        </a>
      </p>
      <p class="muted small">File: <?= h($j['file_sertifikat']) ?></p>
    <?php else: ?>
      <p class="muted">Belum ada file sertifikat.</p>
    <?php endif; ?>
    <form method="post" action="upload.php" enctype="multipart/form-data" style="margin-top:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="upload_type" value="sertifikat">
      <input type="file" name="file" accept=".pdf" required style="font-size:13px;margin-bottom:6px;display:block">
      <button type="submit" class="btn btn-sm btn-primary">⬆️ Upload PDF</button>
      <span class="muted small" style="margin-left:6px">Maks 2MB</span>
    </form>

    <hr style="margin:14px 0;border:none;border-top:1px solid #eaecf0">

    <h4 style="margin:0 0 4px">🗓️ Masa Berlaku Akreditasi</h4>
    <?php
      $has_akr = trim($akr['mulai_tahun'].$akr['sampai_tahun'].$akr['mulai_volume'].$akr['sampai_volume']) !== '';
      $fmt = function($v,$n,$t){ return 'Vol ' . ($v!==''?h($v):'—') . ' No ' . ($n!==''?h($n):'—') . ' Th ' . ($t!==''?h($t):'—'); };
      $peringkat_opts = ['Sinta 1','Sinta 2','Sinta 3','Sinta 4','Sinta 5','Sinta 6'];
      $inp = 'flex:1;font-size:12px;padding:6px 8px;border:1px solid #cdd5e0;border-radius:6px';
    ?>
    <?php if (!$is_akreditasi): ?>
      <p class="muted small" style="margin:0 0 8px">⚪ Jurnal belum terakreditasi — isian masa berlaku dinonaktifkan.</p>
    <?php elseif ($has_akr): ?>
      <p class="muted small" style="margin:0 0 8px">
        Mulai <strong><?= $fmt($akr['mulai_volume'],$akr['mulai_nomor'],$akr['mulai_tahun']) ?></strong><br>
        sampai <strong><?= $fmt($akr['sampai_volume'],$akr['sampai_nomor'],$akr['sampai_tahun']) ?></strong>
        <?php if ($cur_peringkat !== ''): ?> &middot; <?= h($cur_peringkat) ?><?php endif; ?>
        <?php if (trim($akr['no_sk']) !== ''): ?><br>No. SK: <?= h($akr['no_sk']) ?><?php endif; ?>
      </p>
    <?php else: ?>
      <p class="muted small" style="margin:0 0 8px">Belum diisi.</p>
    <?php endif; ?>

    <form method="post" action="akreditasi_save.php" style="margin-top:4px">
      <?= csrf_field() ?>
      <fieldset <?= $is_akreditasi ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;min-width:0">
      <div style="display:flex;gap:6px;margin-bottom:6px">
        <select name="peringkat" style="<?= $inp ?>">
          <option value="">— Peringkat —</option>
          <?php
            $opts = $peringkat_opts;
            if ($cur_peringkat !== '' && !in_array($cur_peringkat, $opts, true)) {
                array_unshift($opts, $cur_peringkat);
            }
            foreach ($opts as $opt):
          ?>
            <option value="<?= h($opt) ?>" <?= $opt === $cur_peringkat ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="no_sk" value="<?= h($akr['no_sk']) ?>" placeholder="No. SK akreditasi"
               maxlength="150" style="<?= $inp ?>">
      </div>
      <div class="muted small" style="margin:4px 0 2px">Mulai</div>
      <div style="display:flex;gap:6px;margin-bottom:6px">
        <input type="text" name="mulai_volume" value="<?= h($akr['mulai_volume']) ?>" placeholder="Volume" maxlength="20" style="<?= $inp ?>">
        <input type="text" name="mulai_nomor" value="<?= h($akr['mulai_nomor']) ?>" placeholder="Nomor" maxlength="20" style="<?= $inp ?>">
        <input type="text" name="mulai_tahun" value="<?= h($akr['mulai_tahun']) ?>" placeholder="Tahun" inputmode="numeric" maxlength="4" style="<?= $inp ?>">
      </div>
      <div class="muted small" style="margin:4px 0 2px">Sampai</div>
      <div style="display:flex;gap:6px;margin-bottom:8px">
        <input type="text" name="sampai_volume" value="<?= h($akr['sampai_volume']) ?>" placeholder="Volume" maxlength="20" style="<?= $inp ?>">
        <input type="text" name="sampai_nomor" value="<?= h($akr['sampai_nomor']) ?>" placeholder="Nomor" maxlength="20" style="<?= $inp ?>">
        <input type="text" name="sampai_tahun" value="<?= h($akr['sampai_tahun']) ?>" placeholder="Tahun" inputmode="numeric" maxlength="4" style="<?= $inp ?>">
      </div>
      <button type="submit" class="btn btn-sm btn-primary">💾 Simpan Masa Berlaku</button>
      </fieldset>
    </form>
  </div>

  <!-- COVER JURNAL -->
  <div class="card" style="display:flex;flex-direction:column">
    <h3>🖼️ Cover Depan Jurnal</h3>
    <div style="display:flex;gap:14px;flex:1;align-items:stretch">
      <!-- Kolom kiri: kontrol upload -->
      <div style="flex:1;display:flex;flex-direction:column">
        <form method="post" action="upload.php" enctype="multipart/form-data" style="margin-top:4px">
          <?= csrf_field() ?>
          <input type="hidden" name="upload_type" value="cover">
          <input type="file" name="file" accept=".jpg,.jpeg,.png,.webp" required style="font-size:13px;margin-bottom:6px;display:block;max-width:100%">
          <button type="submit" class="btn btn-sm btn-primary">⬆️ Upload Gambar</button>
          <span class="muted small" style="display:block;margin-top:6px">JPG/PNG/WebP, maks 2MB</span>
        </form>
      </div>
      <!-- Kolom kanan: preview cover -->
      <div style="flex:1;display:flex;align-items:center;justify-content:center;background:#f8fafc;border:1px solid #eaecf0;border-radius:8px;min-height:160px;padding:8px">
        <?php if (!empty($j['file_cover'])): ?>
          <a href="<?= h($upload_base . $j['file_cover']) ?>" target="_blank" rel="noopener" style="display:block;line-height:0">
            <img src="<?= h($upload_base . $j['file_cover']) ?>" alt="Cover <?= h($j['nama_jurnal']) ?>"
                 width="250" height="350"
                 style="width:250px;height:350px;border-radius:6px;border:1px solid #eaecf0;object-fit:cover">
          </a>
        <?php else: ?>
          <span class="muted small">Belum ada cover.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<h2><span class="ico">&#128202;</span> Riwayat Terbitan</h2>

<?php if (empty($terbitan)): ?>
  <p class="muted">Belum ada data terbitan. Klik "Crawl Sekarang" untuk mengambil data.</p>
<?php else: ?>

<!-- ============== DASHBOARD GRAFIK (Pure CSS) ============== -->
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
      <span class="legend-item"><span class="legend-swatch sw-issues"></span> Jumlah Issue</span>
      <span class="legend-item"><span class="legend-swatch sw-articles"></span> Jumlah Artikel</span>
    </div>

    <div class="bar-chart" id="barChart">
      <?php foreach ($years_sorted as $y):
        $iss = $by_year[$y]['issues'];
        $art = $by_year[$y]['articles'];
        $h_iss = $max_issues   > 0 ? round($iss / $max_issues   * 100) : 0;
        $h_art = $max_articles > 0 ? round($art / $max_articles * 100) : 0;
      ?>
      <div class="bar-group" data-year="<?= h($y) ?>" role="button" tabindex="0"
           title="Klik untuk filter tahun <?= h($y) ?>">
        <div class="bars">
          <div class="bar bar-issues"   style="height: <?= $h_iss ?>%"
               data-value="<?= $iss ?>" title="Issue: <?= $iss ?>">
            <span class="bar-label"><?= $iss ?></span>
          </div>
          <div class="bar bar-articles" style="height: <?= $h_art ?>%"
               data-value="<?= $art ?>" title="Artikel: <?= $art ?>">
            <span class="bar-label"><?= $art ?></span>
          </div>
        </div>
        <div class="bar-year"><?= h($y) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="muted small chart-hint">Klik bar / tahun pada grafik untuk filter, atau gunakan dropdown di atas.</p>
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

<!-- ============== Filter + pagination logic ============== -->
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

    document.getElementById('tableYearLabel').innerText =
      year === 'all' ? 'Semua Tahun' : 'Tahun ' + year;
    document.getElementById('filterYear').value = year;

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

    rows.forEach(function (tr) { tr.style.display = 'none'; });
    var start = (state.page - 1) * size;
    var end = start + size;
    fr.slice(start, end).forEach(function (tr) { tr.style.display = ''; });

    var summary = document.getElementById('pgSummary');
    if (fr.length === 0) {
      summary.innerText = '0 data';
    } else {
      summary.innerText = 'Menampilkan ' + (start + 1) + '–' +
        Math.min(end, fr.length) + ' dari ' + fr.length + ' data';
    }

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

  applyFilter('all');
})();
</script>

<?php endif; /* ada terbitan */ ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
