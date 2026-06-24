<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header_admin.php';

// =========================================================
// Filter, search, pagination
// =========================================================
$filter = $_GET['akr'] ?? 'all';
$valid_filters = ['all','sinta','scopus','belum','belum_issn','apc'];
if (!in_array($filter, $valid_filters, true)) $filter = 'all';

$pr = trim($_GET['pr'] ?? '');
if (mb_strlen($pr) > 20) $pr = mb_substr($pr, 0, 20);

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) > 100) $q = mb_substr($q, 0, 100);

$per_page_opts = [10, 25, 50, 100];
$per_page = (int)($_GET['pp'] ?? 25);
if (!in_array($per_page, $per_page_opts, true)) $per_page = 25;

$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;

// =========================================================
// Reusable SQL fragments
// =========================================================
// Belum ISSN = P-ISSN & E-ISSN dua-duanya bukan format valid xxxx-xxxx
// (digit cek terakhir boleh X). Nilai kosong/'0'/'-'/teks dianggap belum.
$BELUM_ISSN_SQL = "
  (COALESCE(j.p_issn,'') NOT REGEXP '^[0-9]{4}-[0-9]{3}[0-9Xx]$')
  AND (COALESCE(j.e_issn,'') NOT REGEXP '^[0-9]{4}-[0-9]{3}[0-9Xx]$')
";

// APC ber-bayar: kolom sudah numeric after cleanup, cek > 0
$BER_APC_SQL = "j.apc REGEXP '^[0-9]+$' AND CAST(j.apc AS UNSIGNED) > 0";

// =========================================================
// ORDER BY
// =========================================================
$order_sql = "
  ORDER BY
    CASE
      WHEN j.is_scopus = 1 THEN 1
      WHEN j.akreditasi_jenis = 'sinta' THEN 2
      WHEN (j.akreditasi_jenis IS NULL OR j.akreditasi_jenis = '' OR j.akreditasi_jenis = 'belum')
           AND j.p_issn IS NOT NULL AND j.p_issn <> '' THEN 3
      ELSE 4
    END,
    CASE
      WHEN j.is_scopus = 1 AND j.akreditasi_peringkat LIKE 'Sinta%' THEN
        CAST(SUBSTRING(j.akreditasi_peringkat, -1) AS UNSIGNED)
      WHEN j.is_scopus = 1 THEN
        CASE UPPER(TRIM(j.akreditasi_peringkat))
          WHEN 'Q1' THEN 1 WHEN 'Q2' THEN 2 WHEN 'Q3' THEN 3 WHEN 'Q4' THEN 4 ELSE 5
        END
      WHEN j.akreditasi_jenis = 'sinta' THEN
        CAST(SUBSTRING(COALESCE(j.akreditasi_peringkat,''), -1) AS UNSIGNED)
      ELSE 0
    END,
    j.nama_jurnal ASC
";

// =========================================================
// WHERE: filter + peringkat + search
// =========================================================
$where_parts = [];
$types = '';
$params = [];

$where_parts[] = "j.konfirmasi_status = 'terkonfirmasi'";

if ($filter === 'sinta') {
    $where_parts[] = "j.akreditasi_jenis = 'sinta'";
} elseif ($filter === 'scopus') {
    // Scopus = is_scopus flag (termasuk Sinta 1)
    $where_parts[] = "j.is_scopus = 1";
} elseif ($filter === 'belum') {
    // Belum Akreditasi = belum akreditasi TAPI sudah punya >=1 ISSN valid.
    // Yang belum punya ISSN sama sekali masuk tag Belum ISSN.
    $where_parts[] = "(j.akreditasi_jenis IS NULL OR j.akreditasi_jenis = '' OR j.akreditasi_jenis = 'belum')";
    $where_parts[] = "NOT ($BELUM_ISSN_SQL)";
} elseif ($filter === 'belum_issn') {
    $where_parts[] = $BELUM_ISSN_SQL;
} elseif ($filter === 'apc') {
    $where_parts[] = $BER_APC_SQL;
}

if ($pr !== '') {
    // Sub-filter peringkat: scopus pakai kolom scopus_q, lainnya akreditasi_peringkat
    if ($filter === 'scopus') {
        $where_parts[] = "j.scopus_q = ?";
    } else {
        $where_parts[] = "j.akreditasi_peringkat = ?";
    }
    $types .= 's';
    $params[] = $pr;
}

if ($q !== '') {
    $where_parts[] = "(j.nama_jurnal LIKE ? OR j.p_issn LIKE ? OR j.e_issn LIKE ?
                       OR EXISTS(SELECT 1 FROM editor e WHERE e.jurnal_id=j.id AND e.nama LIKE ?)
                       OR j.url_archive LIKE ?
                       OR j.akreditasi_peringkat LIKE ?
                       OR j.unit_kerja LIKE ?)";
    $like = '%' . $q . '%';
    $types .= 'sssssss';
    for ($i = 0; $i < 7; $i++) $params[] = $like;
}

$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

// =========================================================
// Hitung total untuk pagination
// =========================================================
$count_sql = "SELECT COUNT(*) AS n FROM jurnals j $where_sql";
if ($types !== '') {
    $count_row = fetch_one($count_sql, $types, $params);
} else {
    $count_row = fetch_one($count_sql);
}
$total_rows = (int)($count_row['n'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

// =========================================================
// Ambil data jurnal
// =========================================================
$data_sql = "
  SELECT j.*,
         (SELECT e.nama FROM editor e WHERE e.jurnal_id = j.id) AS editor_nama,
         (SELECT COUNT(*)             FROM terbitan t WHERE t.jurnal_id = j.id) AS total_terbitan,
         (SELECT SUM(jumlah_artikel)  FROM terbitan t WHERE t.jurnal_id = j.id) AS total_artikel
  FROM jurnals j
  $where_sql
  $order_sql
  LIMIT $per_page OFFSET $offset
";
if ($types !== '') {
    $jurnals = fetch_all($data_sql, $types, $params);
} else {
    $jurnals = fetch_all($data_sql);
}

// =========================================================
// Statistik pill counters
// =========================================================
$base_where = "j.konfirmasi_status = 'terkonfirmasi'";
if ($q !== '') {
    $search_cond = "(j.nama_jurnal LIKE ? OR j.p_issn LIKE ? OR j.e_issn LIKE ?
                     OR EXISTS(SELECT 1 FROM editor e WHERE e.jurnal_id=j.id AND e.nama LIKE ?)
                     OR j.url_archive LIKE ?
                     OR j.akreditasi_peringkat LIKE ?
                     OR j.unit_kerja LIKE ?)";
    $base_where .= " AND $search_cond";
    $st_types = 'sssssss';
    $st_params = array_fill(0, 7, $like);
} else {
    $st_types = '';
    $st_params = [];
}

$stats = fetch_all("
  SELECT COALESCE(NULLIF(j.akreditasi_jenis,''),'belum') AS jenis, COUNT(*) AS n
  FROM jurnals j WHERE $base_where
  GROUP BY COALESCE(NULLIF(j.akreditasi_jenis,''),'belum')
", $st_types, $st_params);
$stat_map = ['sinta'=>0,'scopus'=>0,'belum'=>0];
foreach ($stats as $s) {
    $key = $s['jenis'] ?: 'belum';
    if (isset($stat_map[$key])) $stat_map[$key] = (int)$s['n'];
}
$total_all = array_sum($stat_map);

// Scopus count: pakai is_scopus=1 (termasuk Sinta 1)
$r_scopus = fetch_one("
  SELECT COUNT(*) AS n FROM jurnals j
  WHERE $base_where AND j.is_scopus = 1
", $st_types, $st_params);
$stat_scopus = (int)($r_scopus['n'] ?? 0);

$r_issn = fetch_one("
  SELECT COUNT(*) AS n FROM jurnals j
  WHERE $base_where AND $BELUM_ISSN_SQL
", $st_types, $st_params);
$stat_belum_issn = (int)($r_issn['n'] ?? 0);

// Belum Akreditasi = belum akreditasi TAPI sudah punya ISSN valid
$r_belum_akr = fetch_one("
  SELECT COUNT(*) AS n FROM jurnals j
  WHERE $base_where
    AND (j.akreditasi_jenis IS NULL OR j.akreditasi_jenis = '' OR j.akreditasi_jenis = 'belum')
    AND NOT ($BELUM_ISSN_SQL)
", $st_types, $st_params);
$stat_belum_akr = (int)($r_belum_akr['n'] ?? 0);

$r_apc = fetch_one("
  SELECT COUNT(*) AS n FROM jurnals j
  WHERE $base_where AND $BER_APC_SQL
", $st_types, $st_params);
$stat_apc = (int)($r_apc['n'] ?? 0);

// Helper: format APC untuk display
function fmt_apc($val) {
    // Tanpa konsep "Gratis": hanya angka positif yang ditampilkan Rp,
    // selain itu (0/kosong/'-'/teks) -> '-' (tidak ada APC).
    $val = trim((string)$val);
    if (!preg_match('/^[1-9][0-9]*$/', $val)) return '-';
    return 'Rp ' . number_format((int)$val, 0, ',', '.');
}

// Helper buat URL
function build_qs($override = []) {
    $base = [
        'akr' => $_GET['akr'] ?? 'all',
        'pr'  => $_GET['pr'] ?? '',
        'q'   => $_GET['q'] ?? '',
        'pp'  => $_GET['pp'] ?? 25,
        'p'   => $_GET['p'] ?? 1,
    ];
    $merged = array_merge($base, $override);
    if ($merged['akr'] === 'all') unset($merged['akr']);
    if ($merged['pr'] === '')     unset($merged['pr']);
    if ($merged['q'] === '')      unset($merged['q']);
    if ((int)$merged['pp'] === 25)unset($merged['pp']);
    if ((int)$merged['p'] === 1)  unset($merged['p']);
    return $merged ? ('?' . http_build_query($merged)) : '?';
}
?>
<?php if (isset($_GET['deleted'])): ?>
  <div class="alert alert-<?= $_GET['deleted']==='ok'?'info':'error' ?>">
    <?= h($_GET['msg'] ?? 'Selesai.') ?>
  </div>
<?php endif; ?>
<div class="page-head">
  <h1>Daftar Jurnal</h1>
  <div class="page-head-actions">
    <a href="export_dashboard.php" class="btn btn-export" title="Download XLSX semua filter">📥 Export XLSX</a>
    <a href="export_katalog.php" target="_blank" class="btn btn-export" title="Katalog jurnal siap cetak / PDF" style="background:#f97316;color:#fff;border-color:#ea580c">📖 Export Katalog</a>
    <a href="jurnal_form.php" class="btn btn-primary">+ Tambah Jurnal</a>
  </div>
</div>

<!-- Filter pills -->
<div class="filter-pills">
  <a href="<?= h(build_qs(['akr'=>'all','pr'=>'','p'=>1])) ?>" class="pill <?= $filter==='all' ? 'active' : '' ?>">
    Semua <span class="pill-count"><?= $total_all ?></span>
  </a>
  <a href="<?= h(build_qs(['akr'=>'scopus','pr'=>'','p'=>1])) ?>" class="pill <?= $filter==='scopus' ? 'active' : '' ?>">
    <span class="ico">🌐</span> Scopus <span class="pill-count"><?= $stat_scopus ?></span>
  </a>
  <a href="<?= h(build_qs(['akr'=>'sinta','pr'=>'','p'=>1])) ?>" class="pill <?= $filter==='sinta' ? 'active' : '' ?>">
    <span class="ico">🏅</span> Sinta <span class="pill-count"><?= $stat_map['sinta'] ?></span>
  </a>
  <a href="<?= h(build_qs(['akr'=>'belum','pr'=>'','p'=>1])) ?>" class="pill <?= $filter==='belum' ? 'active' : '' ?>">
    <span class="ico">⚪</span> Belum Akreditasi <span class="pill-count"><?= $stat_belum_akr ?></span>
  </a>
  <a href="<?= h(build_qs(['akr'=>'belum_issn','pr'=>'','p'=>1])) ?>" class="pill <?= $filter==='belum_issn' ? 'active' : '' ?>">
    <span class="ico">📄</span> Belum ISSN <span class="pill-count"><?= $stat_belum_issn ?></span>
  </a>
  <a href="<?= h(build_qs(['akr'=>'apc','pr'=>'','p'=>1])) ?>" class="pill <?= $filter==='apc' ? 'active' : '' ?>">
    <span class="ico">💰</span> Ber-APC <span class="pill-count"><?= $stat_apc ?></span>
  </a>
</div>

<?php if ($pr !== ''): ?>
  <div class="search-info">
    Sub-filter peringkat: <strong><?= h($pr) ?></strong>
    <a href="<?= h(build_qs(['pr'=>'','p'=>1])) ?>" class="muted small">(hapus sub-filter)</a>
  </div>
<?php endif; ?>

<!-- Toolbar: search + per-page -->
<form method="get" class="table-toolbar" action="dashboard.php">
  <?php if ($filter !== 'all'): ?>
    <input type="hidden" name="akr" value="<?= h($filter) ?>">
  <?php endif; ?>
  <?php if ($pr !== ''): ?>
    <input type="hidden" name="pr" value="<?= h($pr) ?>">
  <?php endif; ?>
  <div class="search-box">
    <span class="search-ico">🔍</span>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Cari nama jurnal, ISSN, editor, unit kerja, peringkat…" autocomplete="off">
    <?php if ($q !== ''): ?>
      <a href="<?= h(build_qs(['q'=>'','p'=>1])) ?>" class="search-clear" title="Hapus pencarian">&times;</a>
    <?php endif; ?>
  </div>
  <button type="submit" class="btn btn-primary">Cari</button>
  <label class="per-page">
    Tampil
    <select name="pp" onchange="this.form.submit()">
      <?php foreach ($per_page_opts as $opt): ?>
        <option value="<?= $opt ?>" <?= $per_page===$opt?'selected':'' ?>><?= $opt ?></option>
      <?php endforeach; ?>
    </select>
    per halaman
  </label>
</form>

<?php if ($q !== ''): ?>
  <div class="search-info">
    Hasil pencarian untuk: <strong>"<?= h($q) ?>"</strong> &mdash; <?= $total_rows ?> jurnal ditemukan
    <a href="<?= h(build_qs(['q'=>'','p'=>1])) ?>" class="muted small">(reset)</a>
  </div>
<?php endif; ?>

<?php if (empty($jurnals)): ?>
  <div class="empty">
    <?php if ($q !== ''): ?>
      <p>Tidak ada jurnal yang cocok dengan pencarian "<strong><?= h($q) ?></strong>".</p>
      <a href="<?= h(build_qs(['q'=>'','p'=>1])) ?>" class="btn">Reset pencarian</a>
    <?php elseif ($filter === 'all'): ?>
      <p>Belum ada jurnal terdaftar.</p>
      <a href="jurnal_form.php" class="btn btn-primary">Tambah jurnal pertama</a>
    <?php else: ?>
      <p>Tidak ada jurnal pada filter ini.</p>
      <a href="?akr=all" class="btn">Lihat semua</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Nama Jurnal</th>
        <th>Akreditasi</th>
        <th>p-ISSN</th>
        <th>e-ISSN</th>
        <th>Ketua Editor</th>
        <th>APC</th>
        <th class="num">Terbitan</th>
        <th class="num">Artikel</th>
        <th>Crawl Terakhir</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($jurnals as $j):
      $aj  = $j['akreditasi_jenis'] ?? 'belum';
      $ap  = $j['akreditasi_peringkat'] ?? '';
      $is_scopus = (int)($j['is_scopus'] ?? 0);
      $sq  = $j['scopus_q'] ?? '';
      $apc_raw = trim($j['apc'] ?? '');
      $apc_display = fmt_apc($apc_raw);
      $pissn = trim($j['p_issn'] ?? '');
      $eissn = trim($j['e_issn'] ?? '');
      $pissn_valid = ($pissn !== '');
      $eissn_valid = ($eissn !== '');
    ?>
      <tr>
        <td>
          <strong><a href="jurnal_view.php?id=<?= (int)$j['id'] ?>"><?= h($j['nama_jurnal']) ?></a></strong>
          <div class="muted small"><?= h($j['url_archive']) ?></div>
        </td>
        <td>
          <?php
            $sinta_ok  = ($aj === 'sinta' && $ap !== '');
            $scopus_ok = ($is_scopus && $sq !== '');
            if ($sinta_ok):
              $cls = 'akr-sinta-' . preg_replace('/[^0-9]/', '', $ap);
          ?>
            <span class="akr-badge <?= h($cls) ?>"><?= h($ap) ?></span>
          <?php endif; ?>
          <?php if ($scopus_ok): ?>
            <span class="akr-badge akr-scopus-<?= h(strtolower($sq)) ?>" style="font-size:.65rem;padding:2px 5px;<?= $sinta_ok ? 'margin-left:3px' : '' ?>">Scopus <?= h($sq) ?></span>
          <?php elseif ($is_scopus): ?>
            <span class="akr-badge akr-scopus-q1" style="font-size:.65rem;padding:2px 5px;<?= $sinta_ok ? 'margin-left:3px' : '' ?>">Scopus</span>
          <?php endif; ?>
          <?php if (!$sinta_ok && !$is_scopus): ?>
            <span class="akr-badge akr-belum">Belum</span>
          <?php endif; ?>
        </td>
        <td><?php if ($pissn_valid): ?><span><?= h($pissn) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?php if ($eissn_valid): ?><span><?= h($eissn) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= h($j['editor_nama'] ?: '—') ?></td>
        <td>
          <?php if ($apc_display !== '-'): ?>
            <span class="badge badge-partial"><?= h($apc_display) ?></span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="num"><?= (int)$j['total_terbitan'] ?></td>
        <td class="num"><?= (int)($j['total_artikel'] ?? 0) ?></td>
        <td>
          <?php if ($j['last_crawled_at']): ?>
            <span class="badge badge-<?= h($j['last_crawl_status']) ?>"><?= h($j['last_crawl_status']) ?></span>
            <div class="muted small"><?= h($j['last_crawled_at']) ?></div>
          <?php else: ?>
            <span class="muted">belum pernah</span>
          <?php endif; ?>
        </td>
        <td class="actions">
          <a href="jurnal_view.php?id=<?= (int)$j['id'] ?>" class="btn btn-sm">Lihat</a>
          <a href="jurnal_form.php?id=<?= (int)$j['id'] ?>" class="btn btn-sm">Edit</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- Pagination -->
  <div class="pagination-wrap">
    <div class="pagination-info">
      Menampilkan <strong><?= number_format(($offset + 1), 0, ',', '.') ?>–<?= number_format(min($offset + $per_page, $total_rows), 0, ',', '.') ?></strong>
      dari <strong><?= number_format($total_rows, 0, ',', '.') ?></strong> jurnal
    </div>
    <?php if ($total_pages > 1): ?>
      <nav class="pagination">
        <?php
          if ($page > 1) {
              echo '<a class="page-link" href="' . h(build_qs(['p' => $page - 1])) . '">&laquo; Sebelumnya</a>';
          } else {
              echo '<span class="page-link disabled">&laquo; Sebelumnya</span>';
          }

          $window = 2;
          $pages_to_show = [1, $total_pages];
          for ($i = $page - $window; $i <= $page + $window; $i++) {
              if ($i >= 1 && $i <= $total_pages) $pages_to_show[] = $i;
          }
          $pages_to_show = array_values(array_unique($pages_to_show));
          sort($pages_to_show);

          $prev = 0;
          foreach ($pages_to_show as $i) {
              if ($prev && $i - $prev > 1) {
                  echo '<span class="page-link disabled">…</span>';
              }
              if ($i === $page) {
                  echo '<span class="page-link active">' . $i . '</span>';
              } else {
                  echo '<a class="page-link" href="' . h(build_qs(['p' => $i])) . '">' . $i . '</a>';
              }
              $prev = $i;
          }

          if ($page < $total_pages) {
              echo '<a class="page-link" href="' . h(build_qs(['p' => $page + 1])) . '">Selanjutnya &raquo;</a>';
          } else {
              echo '<span class="page-link disabled">Selanjutnya &raquo;</span>';
          }
        ?>
      </nav>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
