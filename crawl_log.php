<?php
$page_title = 'Log Crawler';
require_once __DIR__ . '/_header.php';

$logs = fetch_all("
  SELECT cl.*, j.nama_jurnal
  FROM crawl_log cl
  LEFT JOIN jurnals j ON j.id = cl.jurnal_id
  ORDER BY cl.executed_at DESC
  LIMIT 200
");
?>
<div class="page-head">
  <h1>Log Crawler</h1>
  <a href="dashboard.php" class="btn">&larr; Dashboard</a>
</div>

<?php if (empty($logs)): ?>
  <p class="muted">Belum ada aktivitas crawler.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead>
    <tr><th>Waktu</th><th>Jurnal</th><th>Trigger</th><th>Status</th><th class="num">Found</th><th class="num">New</th><th>Pesan</th></tr>
  </thead>
  <tbody>
  <?php foreach ($logs as $l): ?>
    <tr>
      <td class="small"><?= h($l['executed_at']) ?></td>
      <td>
        <?php if ($l['jurnal_id']): ?>
          <a href="jurnal_view.php?id=<?= (int)$l['jurnal_id'] ?>"><?= h($l['nama_jurnal'] ?: '#'.$l['jurnal_id']) ?></a>
        <?php else: ?>—<?php endif; ?>
      </td>
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

<?php require_once __DIR__ . '/_footer.php'; ?>
