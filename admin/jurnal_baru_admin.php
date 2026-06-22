<?php
/**
 * jurnal_baru_admin.php
 * Admin: daftar pengajuan jurnal baru (pending/approved/rejected).
 */
$page_title = 'Pengajuan Jurnal Baru';
require_once __DIR__ . '/../includes/header_admin.php';

$filter = $_GET['st'] ?? 'pending';
if (!in_array($filter, ['pending','approved','rejected','all'], true)) $filter = 'pending';

if ($filter === 'all') {
    $rows = fetch_all("SELECT * FROM jurnal_baru ORDER BY submitted_at DESC");
} else {
    $rows = fetch_all(
        "SELECT * FROM jurnal_baru WHERE status=? ORDER BY submitted_at DESC",
        's', [$filter]
    );
}

$counts = fetch_all("SELECT status, COUNT(*) n FROM jurnal_baru GROUP BY status");
$cmap = ['pending'=>0,'approved'=>0,'rejected'=>0];
foreach ($counts as $c) $cmap[$c['status']] = (int)$c['n'];
$ctotal = array_sum($cmap);
?>
<div class="page-head">
  <h1>Pengajuan Jurnal Baru</h1>
  <a href="konfirmasi_admin.php" class="btn">&larr; Konfirmasi Editor</a>
</div>

<?php if (isset($_GET['done'])): ?>
  <div class="alert alert-info"><?= h($_GET['msg'] ?? 'Aksi berhasil.') ?></div>
<?php endif; ?>

<div class="filter-pills">
  <a href="?st=pending"  class="pill <?= $filter==='pending' ?'active':'' ?>">
    Menunggu Review <span class="pill-count"><?= $cmap['pending'] ?></span></a>
  <a href="?st=approved" class="pill <?= $filter==='approved'?'active':'' ?>">
    Disetujui <span class="pill-count"><?= $cmap['approved'] ?></span></a>
  <a href="?st=rejected" class="pill <?= $filter==='rejected'?'active':'' ?>">
    Ditolak <span class="pill-count"><?= $cmap['rejected'] ?></span></a>
  <a href="?st=all"      class="pill <?= $filter==='all'     ?'active':'' ?>">
    Semua <span class="pill-count"><?= $ctotal ?></span></a>
</div>

<?php if (empty($rows)): ?>
  <div class="empty"><p>Tidak ada pengajuan pada filter ini.</p></div>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Jurnal Diajukan</th>
        <th>Editor</th>
        <th>Diajukan</th>
        <th>Status</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $st = $r['status'];
      $stcls = ['pending'=>'partial','approved'=>'success','rejected'=>'failed'][$st] ?? 'partial';
    ?>
      <tr>
        <td>
          <strong><?= h($r['nama_jurnal']) ?></strong>
          <div class="muted small"><?= h($r['url_jurnal']) ?></div>
        </td>
        <td>
          <?= h($r['editor_nama'] ?: '—') ?>
          <div class="muted small"><?= h($r['editor_email'] ?: '') ?></div>
        </td>
        <td class="small"><?= h($r['submitted_at']) ?></td>
        <td><span class="badge badge-<?= $stcls ?>"><?= h($st) ?></span></td>
        <td class="actions">
          <a href="jurnal_baru_review.php?id=<?= (int)$r['id'] ?>"
             class="btn btn-sm btn-primary">Tinjau</a>
          <?php if ($st === 'rejected'): ?>
            <form method="post" action="jurnal_baru_review.php?id=<?= (int)$r['id'] ?>"
                  style="display:inline">
              <?= csrf_field() ?>
              <button type="submit" name="act" value="cancel" class="btn btn-sm"
                      onclick="return confirm('Batalkan penolakan? Pengajuan kembali ke Review.')">
                ↩ Batalkan
              </button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
