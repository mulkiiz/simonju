<?php
/**
 * /konfirmasi/jurnal_baru.php
 * Form publik: ajukan jurnal yang BELUM ada di tabel `jurnals`.
 * Submit -> tabel `jurnal_baru` status 'pending' -> admin approve di dashboard.
 */
require_once __DIR__ . '/_konf.php';

// Gerbang anti-bot: wajib verifikasi email @unsoed.ac.id via OTP dulu
konf_require_verified();
$verified_email = konf_verified_email();

$data = [
    'nama_jurnal'=>'', 'url_jurnal'=>'', 'unit_kerja'=>'', 'volume_per_tahun'=>'',
    'apc'=>'', 'p_issn'=>'', 'e_issn'=>'', 'akreditasi'=>'', 'is_scopus'=>0,
    'link_gscholar'=>'', 'link_garuda'=>'', 'link_editor'=>'', 'link_arsip'=>'',
    'link_sinta'=>'', 'editor_nama'=>'', 'editor_email'=>'', 'editor_no_hp'=>'',
    'catatan_editor'=>'',
];
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    konf_csrf_check();

    foreach ($data as $k => $_) {
        $data[$k] = ($k === 'is_scopus')
            ? (!empty($_POST['is_scopus']) ? 1 : 0)
            : trim($_POST[$k] ?? '');
    }

    // --- Validasi ---
    if ($data['nama_jurnal'] === '')  $errors[] = 'Nama jurnal wajib diisi.';
    if ($data['url_jurnal'] === '') {
        $errors[] = 'URL jurnal wajib diisi.';
    } elseif (!filter_var($data['url_jurnal'], FILTER_VALIDATE_URL)) {
        $errors[] = 'URL jurnal tidak valid.';
    }
    if ($data['editor_nama'] === '') $errors[] = 'Nama ketua editor wajib diisi.';
    if ($data['editor_email'] !== '' && !filter_var($data['editor_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email ketua editor tidak valid.';
    }
    foreach (['link_gscholar','link_garuda','link_editor','link_arsip','link_sinta'] as $lk) {
        if ($data[$lk] !== '' && !filter_var($data[$lk], FILTER_VALIDATE_URL)) {
            $errors[] = 'Link tidak valid: ' . str_replace('_',' ',$lk) . '.';
        }
    }

    // --- Cek duplikat: jurnal dgn URL/nama mirip sudah terdaftar? ---
    if (empty($errors)) {
        $dup = fetch_one(
            "SELECT nama_jurnal FROM jurnals
             WHERE url_archive = ? OR nama_jurnal = ? LIMIT 1",
            'ss', [$data['url_jurnal'], $data['nama_jurnal']]
        );
        if ($dup) {
            $errors[] = 'Jurnal "' . $dup['nama_jurnal'] . '" sudah terdaftar. '
                      . 'Silakan gunakan tombol Konfirmasi pada daftar jurnal.';
        }
    }

    // --- Rate limit ---
    if (empty($errors) && !konf_rate_ok()) {
        $errors[] = 'Terlalu banyak pengiriman dari jaringan Anda. Coba lagi dalam 1 jam.';
    }

    if (empty($errors)) {
        $r = exec_q(
            "INSERT INTO jurnal_baru
               (status, nama_jurnal, url_jurnal, unit_kerja, volume_per_tahun, apc,
                p_issn, e_issn, akreditasi, is_scopus,
                link_gscholar, link_garuda, link_editor, link_arsip, link_sinta,
                editor_nama, editor_email, editor_no_hp, catatan_editor, submit_ip)
             VALUES ('pending', ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            'sssssssssssssssssss',
            [$data['nama_jurnal'], $data['url_jurnal'], $data['unit_kerja'],
             $data['volume_per_tahun'], $data['apc'], $data['p_issn'], $data['e_issn'],
             $data['akreditasi'], $data['is_scopus'],
             $data['link_gscholar'], $data['link_garuda'], $data['link_editor'],
             $data['link_arsip'], $data['link_sinta'],
             $data['editor_nama'], $data['editor_email'], $data['editor_no_hp'],
             $data['catatan_editor'], konf_client_ip()]
        );
        if ($r) {
            konf_rate_hit();
            $success = true;
        } else {
            $errors[] = 'Gagal menyimpan data. Silakan coba lagi.';
        }
    }
}

// Prefill email editor dgn email terverifikasi (sekali, saat GET)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $data['editor_email'] === '') {
    $data['editor_email'] = (string) $verified_email;
}

konf_header('Tambah Jurnal Baru');

if ($success):
?>
  <div class="konf-card" style="text-align:center;max-width:520px;margin:0 auto">
    <div style="font-size:3rem;line-height:1">✅</div>
    <h2 style="color:#1c7a47;margin:10px 0 6px">Pengajuan Terkirim</h2>
    <p style="color:#46546b;font-size:.93rem;line-height:1.6">
      Terima kasih. Pengajuan jurnal baru telah kami terima dan sedang
      <strong>menunggu peninjauan admin</strong>. Setelah disetujui, jurnal
      akan muncul di daftar dan dapat dikelola lebih lanjut.
    </p>
    <a href="index.php" class="btn btn-primary" style="margin-top:8px">
      Kembali ke Daftar Jurnal
    </a>
  </div>
<?php
else:
?>
  <div class="konf-card">
    <h2 style="margin-top:0;font-size:1.1rem;color:#1c3a6e">Tambah Jurnal Baru</h2>
    <p style="color:#5a6675;font-size:.88rem;margin-bottom:8px">
      Gunakan formulir ini hanya jika jurnal Anda <strong>belum terdaftar</strong>
      pada daftar jurnal. Pengajuan akan ditinjau admin sebelum ditambahkan.
      Tanda <span class="req">*</span> wajib diisi.
    </p>
    <p style="margin:0;font-size:.82rem;color:#1c7a47;background:#d8f3e3;border:1px solid #b6e4c9;border-radius:8px;padding:7px 11px;display:inline-block">
      &#10003; Terverifikasi sebagai <strong><?= h((string)$verified_email) ?></strong>
    </p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="konf-form" action="jurnal_baru.php">
    <?= konf_csrf_field() ?>

    <fieldset>
      <legend>Identitas Jurnal</legend>
      <label>Nama Jurnal <span class="req">*</span>
        <input type="text" name="nama_jurnal" required value="<?= h($data['nama_jurnal']) ?>">
      </label>
      <label>URL Utama Jurnal <span class="req">*</span>
        <input type="url" name="url_jurnal" required value="<?= h($data['url_jurnal']) ?>"
               placeholder="https://jos.unsoed.ac.id/index.php/...">
      </label>
      <label>Unit Kerja Pengelola
        <select name="unit_kerja">
          <option value="">— Pilih Unit Kerja —</option>
          <?php foreach (konf_unit_kerja_list() as $uk): ?>
            <option value="<?= h($uk) ?>" <?= $data['unit_kerja']===$uk?'selected':'' ?>>
              <?= h($uk) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="row-2">
        <label>Volume Terbit per Tahun
          <input type="text" name="volume_per_tahun" value="<?= h($data['volume_per_tahun']) ?>"
                 placeholder="contoh: 2">
        </label>
        <label>APC (Article Processing Charge) <span class="muted small">(angka saja, kosongkan bila tidak ada)</span>
          <input type="text" name="apc" value="<?= h($data['apc']) ?>"
                 placeholder="contoh: 500000">
        </label>
      </div>
      <div class="row-2">
        <label>P-ISSN
          <input type="text" name="p_issn" value="<?= h($data['p_issn']) ?>" placeholder="xxxx-xxxx">
        </label>
        <label>E-ISSN
          <input type="text" name="e_issn" value="<?= h($data['e_issn']) ?>" placeholder="xxxx-xxxx">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Akreditasi &amp; Indeksasi</legend>
      <div class="row-2">
        <label>Peringkat Akreditasi
          <select name="akreditasi">
            <option value="">— Belum Terakreditasi —</option>
            <?php foreach (['Sinta 1','Sinta 2','Sinta 3','Sinta 4','Sinta 5','Sinta 6'] as $s): ?>
              <option value="<?= h($s) ?>" <?= $data['akreditasi']===$s?'selected':'' ?>>
                <?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="display:flex;align-items:center;gap:8px;margin-top:24px;font-weight:600">
          <input type="checkbox" name="is_scopus" value="1"
                 <?= $data['is_scopus']?'checked':'' ?> style="width:auto;margin:0">
          Terindeks Scopus
        </label>
      </div>
      <label>URL Profil SINTA <span class="konf-jmeta">(kosongkan jika belum terakreditasi)</span>
        <input type="url" name="link_sinta" value="<?= h($data['link_sinta']) ?>"
               placeholder="https://sinta.kemdiktisaintek.go.id/journals/profile/...">
      </label>
    </fieldset>

    <fieldset>
      <legend>Tautan Pendukung</legend>
      <label>Link Arsip / Issue Archive
        <input type="url" name="link_arsip" value="<?= h($data['link_arsip']) ?>"
               placeholder="https://.../issue/archive">
      </label>
      <label>Link Google Scholar
        <input type="url" name="link_gscholar" value="<?= h($data['link_gscholar']) ?>"
               placeholder="https://scholar.google.com/citations?user=...">
      </label>
      <label>Link Garuda
        <input type="url" name="link_garuda" value="<?= h($data['link_garuda']) ?>"
               placeholder="https://garuda.kemdiktisaintek.go.id/journal/view/...">
      </label>
      <label>Link Editorial Team
        <input type="url" name="link_editor" value="<?= h($data['link_editor']) ?>"
               placeholder="https://.../about/editorialTeam">
      </label>
    </fieldset>

    <fieldset>
      <legend>Ketua Editor</legend>
      <label>Nama Ketua Editor <span class="req">*</span>
        <input type="text" name="editor_nama" required value="<?= h($data['editor_nama']) ?>">
      </label>
      <div class="row-2">
        <label>Email Ketua Editor
          <input type="email" name="editor_email" value="<?= h($data['editor_email']) ?>">
        </label>
        <label>No. HP Ketua Editor
          <input type="text" name="editor_no_hp" value="<?= h($data['editor_no_hp']) ?>"
                 placeholder="08xxxxxxxxxx">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Catatan (Opsional)</legend>
      <label>Catatan untuk Admin
        <textarea name="catatan_editor" rows="3"
                  placeholder="Informasi tambahan jika ada…"><?= h($data['catatan_editor']) ?></textarea>
      </label>
    </fieldset>

    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary">Kirim Pengajuan</button>
      <a href="index.php" class="btn">Batal</a>
    </div>
  </form>
<?php
endif;
konf_footer();
?>
