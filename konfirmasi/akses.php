<?php
/**
 * /konfirmasi/akses.php
 * Gerbang kode akses: editor memasukkan token untuk membuka form jurnalnya.
 * Kode akses yang sama dipakai baik untuk konfirmasi pertama maupun
 * untuk MEMPERBARUI data yang sudah pernah dikirim.
 */
require_once __DIR__ . '/_konf.php';

$id = (int)($_GET['id'] ?? 0);
$jurnal = $id ? fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$id]) : null;
if (!$jurnal) {
    konf_header('Jurnal Tidak Ditemukan');
    echo '<div class="konf-card"><p>Jurnal tidak ditemukan. '
       . '<a href="index.php">Kembali ke daftar jurnal</a>.</p></div>';
    konf_footer();
    exit;
}

// Apakah jurnal ini sudah pernah mengirim konfirmasi?
$sudah_konfirmasi = (int)(fetch_one(
    "SELECT COUNT(*) AS n FROM konfirmasi WHERE jurnal_id=?", 'i', [$id]
)['n'] ?? 0) > 0;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    konf_csrf_check();
    $token = trim($_POST['token'] ?? '');
    $j = konf_get_jurnal_by_token($token);
    if (!$j) {
        $error = 'Kode akses tidak valid.';
    } elseif ((int)$j['id'] !== $id) {
        $error = 'Kode akses tidak cocok dengan jurnal ini.';
    } else {
        header('Location: form.php?token=' . urlencode($token));
        exit;
    }
}

konf_header('Masukkan Kode Akses');
?>
  <div class="konf-card" style="max-width:480px;margin:0 auto">
    <h2 style="margin-top:0;font-size:1.05rem;color:#1c3a6e">
      <?= h($jurnal['nama_jurnal']) ?>
    </h2>

    <?php if ($sudah_konfirmasi): ?>
      <div style="background:#e6effb;border:1px solid #c2d6f0;border-radius:9px;
                  padding:10px 13px;margin-bottom:12px">
        <p style="margin:0;color:#33415c;font-size:.85rem;line-height:1.6">
          ✎ Jurnal ini sudah pernah dikonfirmasi. Untuk
          <strong>memperbarui data</strong>, masukkan kembali kode akses yang
          sama — data terakhir akan otomatis dimuat ke dalam formulir.
        </p>
      </div>
    <?php endif; ?>

    <p style="color:#5a6675;font-size:.9rem;line-height:1.6">
      Masukkan <strong>kode akses</strong> yang telah dikirimkan kepada
      editor jurnal ini untuk membuka formulir
      <?= $sudah_konfirmasi ? 'pembaruan data' : 'konfirmasi data' ?>.
    </p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="konf-form" autocomplete="off">
      <?= konf_csrf_field() ?>
      <label>Kode Akses <span class="req">*</span>
        <input type="text" name="token" required autofocus
               placeholder="contoh: a1b2c3d4e5f6a7b8"
               pattern="[0-9a-fA-F]{16}" maxlength="16"
               style="letter-spacing:2px;font-family:monospace">
      </label>
      <button type="submit" class="btn btn-primary btn-block">
        <?= $sudah_konfirmasi ? 'Buka Formulir Pembaruan' : 'Buka Formulir' ?>
      </button>
    </form>

    <p style="margin-top:16px;text-align:center">
      <a href="index.php" class="konf-jmeta">&larr; Kembali ke daftar jurnal</a>
    </p>
  </div>
<?php konf_footer(); ?>
