<?php
$allow_doi = true;
$page_title = 'Rekues DOI';
require_once __DIR__ . '/../includes/header_admin.php';

// Kelompokkan per jurnal
$rows = fetch_all(
    "SELECT j.id AS jurnal_id, j.nama_jurnal, j.doi_sample,
            COUNT(dr.id) AS n_upload,
            SUM(dr.n_articles) AS total_doi,
            SUM(dr.n_active)   AS total_aktif,
            MAX(dr.created_at) AS last_upload
       FROM doi_request dr
       JOIN jurnals j ON j.id = dr.jurnal_id
      GROUP BY j.id, j.nama_jurnal, j.doi_sample
      ORDER BY last_upload DESC"
);
?>
<div class="page-head">
  <h1>🔗 Rekues DOI</h1>
</div>

<?php if (empty($rows)): ?>
  <p class="muted">Belum ada rekues DOI.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Jurnal</th><th>Contoh DOI</th><th class="num">Unggahan</th><th class="num">DOI</th><th class="num">Aktif</th><th>Terakhir</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['nama_jurnal']) ?></td>
      <td><?= !empty($r['doi_sample'])
              ? '<span class="badge badge-success" title="'.h($r['doi_sample']).'">ada</span>'
              : '<span class="badge badge-failed">belum</span>' ?></td>
      <td class="num"><?= (int)$r['n_upload'] ?></td>
      <td class="num"><?= (int)$r['total_doi'] ?></td>
      <td class="num"><strong><?= (int)$r['total_aktif'] ?></strong></td>
      <td class="small muted"><?= h($r['last_upload']) ?></td>
      <td><a href="doi_review.php?jurnal=<?= (int)$r['jurnal_id'] ?>" class="btn btn-sm">Buka</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
