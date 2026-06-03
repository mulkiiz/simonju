<?php
/**
 * konfirmasi_admin.php
 * Halaman admin: daftar konfirmasi dari editor (pending/approved/rejected).
 * + Filter "belum konfirmasi" (jurnal yg belum pernah mengirim konfirmasi)
 * + Export CSV
 */
$page_title = 'Konfirmasi Editor';
require_once __DIR__ . '/_header.php';

$filter = $_GET['st'] ?? 'pending';
if (!in_array($filter, ['pending','approved','rejected','belum_konfirmasi','all'], true)) $filter = 'pending';

/* ------------------------------------------------------------------
 * DATA: tergantung filter
 * ------------------------------------------------------------------ */
if ($filter === 'belum_konfirmasi') {
    // Jurnal yang BELUM pernah mengirim konfirmasi
    $rows = fetch_all(
        "SELECT j.id, j.nama_jurnal, j.url_archive, j.unit_kerja,
                j.konfirmasi_status, j.akreditasi_jenis, j.akreditasi_peringkat,
                (SELECT e.nama  FROM editor e WHERE e.jurnal_id=j.id LIMIT 1) AS editor_nama,
                (SELECT e.email FROM editor e WHERE e.jurnal_id=j.id LIMIT 1) AS editor_email
         FROM jurnals j
         WHERE j.konfirmasi_status='belum'
            OR j.konfirmasi_status IS NULL
            OR j.konfirmasi_status=''
         ORDER BY j.nama_jurnal ASC"
    );
} else {
    $latest_sql =
        "(SELECT MAX(id) AS id FROM konfirmasi GROUP BY jurnal_id) latest";

    if ($filter === 'all') {
        $rows = fetch_all(
            "SELECT k.*, j.nama_jurnal AS jurnal_nama
             FROM konfirmasi k
             JOIN $latest_sql ON latest.id = k.id
             JOIN jurnals j ON j.id = k.jurnal_id
             ORDER BY k.submitted_at DESC"
        );
    } else {
        $rows = fetch_all(
            "SELECT k.*, j.nama_jurnal AS jurnal_nama
             FROM konfirmasi k
             JOIN $latest_sql ON latest.id = k.id
             JOIN jurnals j ON j.id = k.jurnal_id
             WHERE k.status = ?
             ORDER BY k.submitted_at DESC",
            's', [$filter]
        );
    }
}

/* Counter pills */
$latest_sql2 = "(SELECT MAX(id) AS id FROM konfirmasi GROUP BY jurnal_id) latest";
$counts = fetch_all(
    "SELECT k.status, COUNT(*) n
       FROM konfirmasi k
       JOIN $latest_sql2 ON latest.id = k.id
      GROUP BY k.status"
);
$cmap = ['pending'=>0,'approved'=>0,'rejected'=>0];
foreach ($counts as $c) {
    if (isset($cmap[$c['status']])) $cmap[$c['status']] = (int)$c['n'];
}

// Jumlah jurnal belum konfirmasi
$blm = fetch_one("SELECT COUNT(*) n FROM jurnals WHERE konfirmasi_status='belum' OR konfirmasi_status IS NULL OR konfirmasi_status=''");
$belum_count = (int)($blm['n'] ?? 0);

// Jumlah pengajuan jurnal baru pending
$jb = fetch_one("SELECT COUNT(*) n FROM jurnal_baru WHERE status='pending'");
$jb_pending = (int)($jb['n'] ?? 0);
$ctotal = array_sum($cmap);

/* Info tambahan per jurnal (hanya jika bukan filter belum_konfirmasi) */
$konf_per_jurnal = [];
$dari_pengajuan  = [];
if ($filter !== 'belum_konfirmasi') {
    foreach (fetch_all("SELECT jurnal_id, COUNT(*) n FROM konfirmasi GROUP BY jurnal_id") as $c) {
        $konf_per_jurnal[(int)$c['jurnal_id']] = (int)$c['n'];
    }
    $jbcolset = array_map(function ($c) { return $c['Field']; },
                          fetch_all("SHOW COLUMNS FROM jurnal_baru"));
    if (in_array('jurnal_id', $jbcolset, true)) {
        foreach (fetch_all("SELECT jurnal_id FROM jurnal_baru WHERE jurnal_id IS NOT NULL") as $c) {
            $dari_pengajuan[(int)$c['jurnal_id']] = true;
        }
    }
}
?>
<div class="page-head">
  <h1>Konfirmasi Data dari Editor</h1>
  <div class="page-head-actions">
    <a href="export_konfirmasi.php?st=<?= h($filter) ?>" class="btn btn-export" title="Download CSV (filter aktif)">📥 Export CSV</a>
    <a href="dashboard.php" class="btn">&larr; Dashboard</a>
  </div>
</div>

<?php if (isset($_GET['done'])): ?>
  <div class="alert alert-info"><?= h($_GET['msg'] ?? 'Aksi berhasil.') ?></div>
<?php endif; ?>

<div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
  <span>
    📥 Pengajuan jurnal baru menunggu review:
    <strong><?= $jb_pending ?></strong>
  </span>
  <a href="jurnal_baru_admin.php" class="btn btn-sm btn-primary">
    Konfirmasi Jurnal Baru
  </a>
</div>

<div class="filter-pills">
  <a href="?st=pending"  class="pill <?= $filter==='pending' ?'active':'' ?>">
    Menunggu Review <span class="pill-count"><?= $cmap['pending'] ?></span></a>
  <a href="?st=approved" class="pill <?= $filter==='approved'?'active':'' ?>">
    Disetujui <span class="pill-count"><?= $cmap['approved'] ?></span></a>
  <a href="?st=rejected" class="pill <?= $filter==='rejected'?'active':'' ?>">
    Ditolak <span class="pill-count"><?= $cmap['rejected'] ?></span></a>
  <a href="?st=belum_konfirmasi" class="pill <?= $filter==='belum_konfirmasi'?'active':'' ?>">
    <span class="ico">⏳</span> Belum Konfirmasi <span class="pill-count"><?= $belum_count ?></span></a>
  <a href="?st=all"      class="pill <?= $filter==='all'     ?'active':'' ?>">
    Semua <span class="pill-count"><?= $ctotal ?></span></a>
</div>

<?php if (empty($rows)): ?>
  <div class="empty"><p>Tidak ada data pada filter ini.</p></div>
<?php elseif ($filter === 'belum_konfirmasi'): ?>
  <!-- Tabel khusus: jurnal yang belum pernah konfirmasi -->
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Jurnal</th>
        <th>Unit Kerja</th>
        <th>Akreditasi</th>
        <th>Editor</th>
        <th>Status</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <strong><?= h($r['nama_jurnal']) ?></strong>
          <div class="muted small"><?= h($r['url_archive'] ?? '') ?></div>
        </td>
        <td class="small"><?= h($r['unit_kerja'] ?? '—') ?></td>
        <td>
          <?php
            $aj = $r['akreditasi_jenis'] ?? 'belum';
            $ap = $r['akreditasi_peringkat'] ?? '';
            if ($aj === 'sinta' && $ap): ?>
              <span class="akr-badge akr-sinta-<?= preg_replace('/[^0-9]/','',$ap) ?>"><?= h($ap) ?></span>
            <?php elseif ($aj === 'scopus' && $ap): ?>
              <span class="akr-badge akr-scopus-<?= strtolower($ap) ?>">Scopus <?= h($ap) ?></span>
            <?php else: ?>
              <span class="akr-badge akr-belum">Belum</span>
          <?php endif; ?>
        </td>
        <td>
          <?= h($r['editor_nama'] ?: '—') ?>
          <?php if (!empty($r['editor_email'])): ?>
            <div class="muted small"><?= h($r['editor_email']) ?></div>
          <?php endif; ?>
        </td>
        <td><span class="badge badge-failed">Belum Konfirmasi</span></td>
        <td class="actions">
          <a href="jurnal_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Lihat</a>
          <a href="jurnal_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Edit</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php else: ?>
  <!-- Tabel konfirmasi submission -->
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Jurnal</th>
        <th>Jenis</th>
        <th>Editor</th>
        <th>Dikirim</th>
        <th>Status</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $st = $r['status'];
      $stcls = ['pending'=>'partial','approved'=>'success','rejected'=>'failed'][$st] ?? 'partial';
      $jid  = (int)$r['jurnal_id'];
      $kid  = (int)$r['id'];
      $njur = $konf_per_jurnal[$jid] ?? 1;
      $is_baru = !empty($dari_pengajuan[$jid]);
    ?>
      <tr>
        <td>
          <strong><?= h($r['jurnal_nama']) ?></strong>
          <div class="muted small"><?= h($r['url_jurnal']) ?></div>
        </td>
        <td>
          <?php if ($is_baru): ?>
            <span class="badge badge-partial">Jurnal Baru</span>
          <?php else: ?>
            <span class="badge" style="background:#e6effb;color:#1c4f9c">Jurnal Lama</span>
          <?php endif; ?>
          <?php if ($njur > 1): ?>
            <div class="muted small" style="margin-top:3px">
              <?= $njur ?>× dikonfirmasi
            </div>
          <?php endif; ?>
        </td>
        <td>
          <?= h($r['editor_nama'] ?: '—') ?>
          <div class="muted small"><?= h($r['editor_email'] ?: '') ?></div>
        </td>
        <td class="small"><?= h($r['submitted_at']) ?></td>
        <td><span class="badge badge-<?= $stcls ?>"><?= h($st) ?></span></td>
        <td class="actions">
          <a href="konfirmasi_review.php?id=<?= $kid ?>"
             class="btn btn-sm btn-primary">Tinjau</a>
          <?php if ($njur > 1): ?>
            <a href="konfirmasi_history.php?jurnal=<?= $jid ?>"
               class="btn btn-sm">Riwayat (<?= $njur ?>)</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
