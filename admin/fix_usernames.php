<?php
/**
 * admin/fix_usernames.php
 * Alat sekali-jalan: perbaiki username akun jurnal yang < 4 karakter
 * dan buatkan akun untuk jurnal terkonfirmasi yang belum punya akun.
 * Username baru: slug link_editor, dipastikan min 4 char & unik.
 */
$page_title = 'Perbaiki Username Jurnal';
require_once __DIR__ . '/../includes/header_admin.php';

// Kandidat: username terlalu pendek (< 4 char)
$short = fetch_all(
    "SELECT ja.id, ja.jurnal_id, ja.username, j.nama_jurnal, j.link_editor
       FROM jurnal_accounts ja
       JOIN jurnals j ON j.id = ja.jurnal_id
      WHERE CHAR_LENGTH(TRIM(ja.username)) < 4
      ORDER BY j.nama_jurnal ASC"
);

// Kandidat: jurnal terkonfirmasi tanpa akun login
$missing = fetch_all(
    "SELECT j.id AS jurnal_id, j.nama_jurnal, j.link_editor, j.konfirmasi_token
       FROM jurnals j
       LEFT JOIN jurnal_accounts ja ON ja.jurnal_id = j.id
      WHERE ja.id IS NULL
        AND j.konfirmasi_status = 'terkonfirmasi'
      ORDER BY j.nama_jurnal ASC"
);

$results = [];
$applied = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $applied = true;

    foreach ($short as $s) {
        $old = trim((string)$s['username']);
        $new = generate_jurnal_username((int)$s['jurnal_id'], $s['link_editor']);
        if ($new !== $old) {
            exec_q("UPDATE jurnal_accounts SET username=? WHERE id=?", 'si', [$new, (int)$s['id']]);
        }
        $results[] = ['jurnal' => $s['nama_jurnal'], 'old' => $old, 'new' => $new, 'act' => 'rename'];
    }

    foreach ($missing as $m) {
        $res = ensure_jurnal_account((int)$m['jurnal_id'], $m['link_editor'], $m['konfirmasi_token'] ?? null);
        $results[] = ['jurnal' => $m['nama_jurnal'], 'old' => '—', 'new' => $res['username'], 'act' => 'buat'];
    }
}
?>
<div class="page-head">
  <h1>🔧 Perbaiki Username Jurnal</h1>
  <a href="account.php?tab=jurnal" class="btn">&larr; Akun</a>
</div>

<?php if ($applied): ?>
  <div class="alert alert-info">Selesai. <?= count($results) ?> akun diproses.</div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Jurnal</th><th>Username Lama</th><th>Username Baru</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($results as $r): ?>
      <tr>
        <td><?= h($r['jurnal']) ?></td>
        <td class="mono"><?= h($r['old']) ?></td>
        <td class="mono"><strong><?= h($r['new']) ?></strong></td>
        <td><?= h($r['act']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="margin-top:10px">
    Username yang berubah perlu diinfokan ke editor jurnal (kirim ulang email login dari halaman Akun).
  </p>
<?php else: ?>
  <p>Username login minimal <strong>4 karakter</strong>. Alat ini memperbaiki yang lebih pendek
     dan membuat akun untuk jurnal terkonfirmasi yang belum punya akun.</p>

  <h3>Username &lt; 4 karakter (<?= count($short) ?>)</h3>
  <?php if (empty($short)): ?>
    <p class="muted">Tidak ada.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Jurnal</th><th>Username</th><th>Preview Baru</th></tr></thead>
      <tbody>
      <?php foreach ($short as $s):
        $preview = generate_jurnal_username((int)$s['jurnal_id'], $s['link_editor']); ?>
        <tr>
          <td><?= h($s['nama_jurnal']) ?></td>
          <td class="mono"><?= h($s['username']) ?></td>
          <td class="mono"><strong><?= h($preview) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <h3 style="margin-top:18px">Jurnal terkonfirmasi tanpa akun (<?= count($missing) ?>)</h3>
  <?php if (empty($missing)): ?>
    <p class="muted">Tidak ada.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Jurnal</th><th>Username Akan Dibuat</th></tr></thead>
      <tbody>
      <?php foreach ($missing as $m):
        $preview = generate_jurnal_username((int)$m['jurnal_id'], $m['link_editor']); ?>
        <tr>
          <td><?= h($m['nama_jurnal']) ?></td>
          <td class="mono"><strong><?= h($preview) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($short) || !empty($missing)): ?>
    <form method="post" style="margin-top:18px">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary"
              onclick="return confirm('Terapkan perbaikan username? Username yang berubah harus diinfokan ke editor.')">
        🔧 Perbaiki Sekarang
      </button>
    </form>
  <?php else: ?>
    <div class="alert alert-info" style="margin-top:14px">Semua username sudah valid. Tidak ada yang perlu diperbaiki.</div>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
