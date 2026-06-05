<?php
$page_title = 'Scanner Judol';
$body_class = 'theme-scanner';
require_once __DIR__ . '/../includes/header_admin.php';

// Filter berdasarkan label
$filter = $_GET['label'] ?? 'all';
$valid = ['all', 'HACKED', 'SUSPICIOUS', 'WARN', 'CLEAN', 'PARTIAL', 'UNREACHABLE'];
if (!in_array($filter, $valid, true)) $filter = 'all';

// Pagination sederhana
$page = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Query: ambil scan terakhir per jurnal (subquery)
$where = '';
$types = '';
$params = [];
if ($filter !== 'all') {
    $where = "WHERE l.risk_label = ?";
    $types = 's';
    $params[] = $filter;
}

$sql_count = "
    SELECT COUNT(*) AS n FROM (
        SELECT jurnal_id, MAX(id) AS last_id
        FROM judol_scan_log
        GROUP BY jurnal_id
    ) latest
    JOIN judol_scan_log l ON l.id = latest.last_id
    $where
";
$total_rows = $types ? (int)(fetch_one($sql_count, $types, $params)['n'] ?? 0)
                     : (int)(fetch_one($sql_count)['n'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$sql = "
    SELECT j.id AS jurnal_id, j.nama_jurnal, j.url_archive,
           l.id AS scan_id, l.scanned_at, l.risk_score, l.risk_label,
           l.normal_type, l.bot_type, l.cloaking_detected
    FROM (
        SELECT jurnal_id, MAX(id) AS last_id
        FROM judol_scan_log
        GROUP BY jurnal_id
    ) latest
    JOIN judol_scan_log l ON l.id = latest.last_id
    JOIN jurnals j        ON j.id = l.jurnal_id
    $where
    ORDER BY l.risk_score DESC, l.scanned_at DESC
    LIMIT $per_page OFFSET $offset
";

$rows = $types ? fetch_all($sql, $types, $params) : fetch_all($sql);

// Statistik per label
$stats = fetch_all("
    SELECT l.risk_label, COUNT(*) AS n
    FROM (
        SELECT jurnal_id, MAX(id) AS last_id
        FROM judol_scan_log
        GROUP BY jurnal_id
    ) latest
    JOIN judol_scan_log l ON l.id = latest.last_id
    GROUP BY l.risk_label
");
$stat_map = ['CLEAN'=>0, 'WARN'=>0, 'SUSPICIOUS'=>0, 'HACKED'=>0, 'PARTIAL'=>0, 'UNREACHABLE'=>0];
foreach ($stats as $s) {
    if (isset($stat_map[$s['risk_label']])) $stat_map[$s['risk_label']] = (int)$s['n'];
}
$total_all = array_sum($stat_map);

function build_qs_judol($overrides = []) {
    $base = ['label' => $_GET['label'] ?? 'all', 'p' => $_GET['p'] ?? 1];
    $merged = array_merge($base, $overrides);
    if ($merged['label'] === 'all') unset($merged['label']);
    if ((int)$merged['p'] === 1)    unset($merged['p']);
    return $merged ? '?' . http_build_query($merged) : '?';
}
?>
<div class="page-head">
  <h1>Scanner Judol</h1>
  <div>
    <a href="dashboard.php" class="btn">&laquo; Dashboard</a>
    <form method="post" action="scan_judol_run_all.php" style="display:inline" onsubmit="return confirm('Scan ulang SEMUA jurnal sekarang? Bisa makan beberapa menit.');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary">Scan Semua Sekarang</button>
    </form>
  </div>
</div>

<?php
// Flash message setelah scan per-jurnal selesai
$flash_status = $_GET['scanned'] ?? '';
$flash_msg    = $_GET['msg'] ?? '';
$flash_jid    = (int)($_GET['jid'] ?? 0);
if ($flash_status && $flash_msg):
    $alert_cls = $flash_status === 'ok' ? 'alert-info' : 'alert-error';
    // Resolve scan_log.id untuk jurnal ini, supaya bisa link ke detail terbaru
    $latest_scan = $flash_jid
        ? fetch_one("SELECT MAX(id) AS sid FROM judol_scan_log WHERE jurnal_id=?", 'i', [$flash_jid])
        : null;
    $latest_sid = (int)($latest_scan['sid'] ?? 0);
?>
  <div class="alert <?= h($alert_cls) ?>" style="margin-bottom:14px">
    <?= h($flash_msg) ?>
    <?php if ($latest_sid): ?>
      &middot; <a href="scan_judol_detail.php?id=<?= $latest_sid ?>" style="text-decoration:underline">Lihat detail</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="filter-pills">
  <a href="<?= h(build_qs_judol(['label'=>'all','p'=>1])) ?>" class="pill <?= $filter==='all'?'active':'' ?>">
    Semua <span class="pill-count"><?= $total_all ?></span>
  </a>
  <a href="<?= h(build_qs_judol(['label'=>'HACKED','p'=>1])) ?>" class="pill <?= $filter==='HACKED'?'active':'' ?>" style="<?= $filter==='HACKED'?'':'border-color:#fecaca;color:#991b1b' ?>">
    🚨 HACKED <span class="pill-count"><?= $stat_map['HACKED'] ?></span>
  </a>
  <a href="<?= h(build_qs_judol(['label'=>'SUSPICIOUS','p'=>1])) ?>" class="pill <?= $filter==='SUSPICIOUS'?'active':'' ?>">
    ⚠ SUSPICIOUS <span class="pill-count"><?= $stat_map['SUSPICIOUS'] ?></span>
  </a>
  <a href="<?= h(build_qs_judol(['label'=>'WARN','p'=>1])) ?>" class="pill <?= $filter==='WARN'?'active':'' ?>">
    WARN <span class="pill-count"><?= $stat_map['WARN'] ?></span>
  </a>
  <a href="<?= h(build_qs_judol(['label'=>'CLEAN','p'=>1])) ?>" class="pill <?= $filter==='CLEAN'?'active':'' ?>">
    ✓ CLEAN <span class="pill-count"><?= $stat_map['CLEAN'] ?></span>
  </a>
  <a href="<?= h(build_qs_judol(['label'=>'PARTIAL','p'=>1])) ?>" class="pill <?= $filter==='PARTIAL'?'active':'' ?>">
    ◐ PARTIAL <span class="pill-count"><?= $stat_map['PARTIAL'] ?></span>
  </a>
  <a href="<?= h(build_qs_judol(['label'=>'UNREACHABLE','p'=>1])) ?>" class="pill <?= $filter==='UNREACHABLE'?'active':'' ?>">
    ✕ UNREACHABLE <span class="pill-count"><?= $stat_map['UNREACHABLE'] ?></span>
  </a>
</div>

<?php if (empty($rows)): ?>
  <div class="empty">
    <p>Belum ada hasil scan judol.</p>
    <p class="muted small">Jalankan cron <code>cron_scan_judol.php</code> atau klik "Scan Semua Sekarang".</p>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Jurnal</th>
          <th>Skor</th>
          <th>Label</th>
          <th>Browser</th>
          <th>Googlebot</th>
          <th>Cloaking</th>
          <th>Scan Terakhir</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $label = $r['risk_label'];
        $color = ['HACKED'=>'#dc2626','SUSPICIOUS'=>'#ea580c','WARN'=>'#ca8a04','CLEAN'=>'#16a34a','PARTIAL'=>'#7c3aed','UNREACHABLE'=>'#6b7280'][$label] ?? '#6b7280';
        $bg    = ['HACKED'=>'#fee2e2','SUSPICIOUS'=>'#ffedd5','WARN'=>'#fef9c3','CLEAN'=>'#dcfce7','PARTIAL'=>'#ede9fe','UNREACHABLE'=>'#f3f4f6'][$label] ?? '#f3f4f6';
      ?>
        <tr>
          <td>
            <strong><a href="jurnal_view.php?id=<?= (int)$r['jurnal_id'] ?>"><?= h($r['nama_jurnal']) ?></a></strong>
            <div class="muted small"><?= h($r['url_archive']) ?></div>
          </td>
          <td class="num">
            <?php if ($r['risk_score'] === null): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <strong><?= (int)$r['risk_score'] ?></strong>/100
            <?php endif; ?>
          </td>
          <td>
            <span class="akr-badge" style="background:<?= $bg ?>;color:<?= $color ?>;border-color:<?= $color ?>">
              <?= h($label) ?>
            </span>
          </td>
          <td><span class="muted small"><?= h($r['normal_type']) ?></span></td>
          <td><span class="muted small"><?= h($r['bot_type']) ?></span></td>
          <td>
            <?php if ($r['cloaking_detected']): ?>
              <span class="akr-badge" style="background:#fee2e2;color:#991b1b;border-color:#dc2626">YA</span>
            <?php else: ?>
              <span class="muted small">tidak</span>
            <?php endif; ?>
          </td>
          <td><span class="muted small"><?= h($r['scanned_at']) ?></span></td>
          <td class="actions">
            <a href="scan_judol_detail.php?id=<?= (int)$r['scan_id'] ?>" class="btn btn-sm">Detail</a>
            <form method="post" action="scan_judol_run.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="jurnal_id" value="<?= (int)$r['jurnal_id'] ?>">
              <button class="btn btn-sm btn-primary" type="submit">Scan</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
    <div class="pagination-wrap">
      <div class="pagination-info">Halaman <?= $page ?> dari <?= $total_pages ?> (<?= $total_rows ?> jurnal)</div>
      <nav class="pagination">
        <?php if ($page > 1): ?>
          <a class="page-link" href="<?= h(build_qs_judol(['p'=>$page-1])) ?>">&laquo; Prev</a>
        <?php endif; ?>
        <?php if ($page < $total_pages): ?>
          <a class="page-link" href="<?= h(build_qs_judol(['p'=>$page+1])) ?>">Next &raquo;</a>
        <?php endif; ?>
      </nav>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
