<?php
/**
 * /konfirmasi/form.php
 * Formulir konfirmasi data jurnal. Field mengikuti struktur kolom Excel.
 *
 * Mendukung 2 mode (otomatis terdeteksi):
 *  - KONFIRMASI BARU : jurnal belum pernah submit konfirmasi.
 *  - PERBARUI DATA   : jurnal sudah pernah submit. Form di-prefill dari
 *                      konfirmasi TERAKHIR. Submit → baris konfirmasi BARU
 *                      (riwayat lama tetap tersimpan untuk admin).
 *
 * Setiap submit selalu jadi baris baru di tabel `konfirmasi` → admin dapat
 * melihat seluruh riwayat perubahan data per jurnal.
 */
require_once __DIR__ . '/_konf.php';

$jurnal = konf_get_jurnal_by_token($_GET['token'] ?? ($_POST['token'] ?? ''));
if (!$jurnal) {
    konf_header('Akses Ditolak');
    echo '<div class="konf-card"><p>Kode akses tidak valid atau kedaluwarsa. '
       . '<a href="index.php">Kembali ke daftar jurnal</a>.</p></div>';
    konf_footer();
    exit;
}
$jid   = (int)$jurnal['id'];
$token = $jurnal['konfirmasi_token'];

// --- Riwayat konfirmasi jurnal ini ---
$last_konf = fetch_one(
    "SELECT * FROM konfirmasi WHERE jurnal_id=? ORDER BY id DESC LIMIT 1",
    'i', [$jid]
);
$konf_count = (int)(fetch_one(
    "SELECT COUNT(*) AS n FROM konfirmasi WHERE jurnal_id=?", 'i', [$jid]
)['n'] ?? 0);

$is_update = ($last_konf !== null);   // true = mode perbarui data

$editor = fetch_one("SELECT * FROM editor WHERE jurnal_id=?", 'i', [$jid]);

/* Nilai awal form. Prioritas prefill: konfirmasi terakhir → data jurnals/editor. */
if ($is_update) {
    $data = [
        'nama_jurnal'      => $last_konf['nama_jurnal']      ?? '',
        'url_jurnal'       => $last_konf['url_jurnal']       ?? '',
        'unit_kerja'       => $last_konf['unit_kerja']       ?? '',
        'volume_per_tahun' => $last_konf['volume_per_tahun'] ?? '',
        'apc'              => $last_konf['apc']              ?? '',
        'p_issn'           => $last_konf['p_issn']           ?? '',
        'e_issn'           => $last_konf['e_issn']           ?? '',
        'akreditasi'       => $last_konf['akreditasi']       ?? '',
        'is_scopus'        => (int)($last_konf['is_scopus']  ?? 0),
        'link_gscholar'    => $last_konf['link_gscholar']    ?? '',
        'link_garuda'      => $last_konf['link_garuda']      ?? '',
        'link_editor'      => $last_konf['link_editor']      ?? '',
        'link_arsip'       => $last_konf['link_arsip']       ?? '',
        'link_sinta'       => $last_konf['link_sinta']       ?? '',
        'editor_nama'      => $last_konf['editor_nama']      ?? '',
        'editor_email'     => $last_konf['editor_email']     ?? '',
        'editor_no_hp'     => $last_konf['editor_no_hp']     ?? '',
        'catatan_editor'   => '',
    ];
} else {
    $data = [
        'nama_jurnal'      => $jurnal['nama_jurnal'] ?? '',
        'url_jurnal'       => $jurnal['url_archive'] ?? '',
        'unit_kerja'       => $jurnal['unit_kerja'] ?? '',
        'volume_per_tahun' => $jurnal['volume_per_tahun'] ?? '',
        'apc'              => $jurnal['apc'] ?? '',
        'p_issn'           => $jurnal['p_issn'] ?? '',
        'e_issn'           => $jurnal['e_issn'] ?? '',
        'akreditasi'       => $jurnal['akreditasi_peringkat'] ?? '',
        'is_scopus'        => (int)($jurnal['is_scopus'] ?? 0),
        'link_gscholar'    => $jurnal['link_gscholar'] ?? '',
        'link_garuda'      => $jurnal['link_garuda'] ?? '',
        'link_editor'      => $jurnal['link_editor'] ?? '',
        'link_arsip'       => $jurnal['url_archive'] ?? '',
        'link_sinta'       => $jurnal['link_sinta'] ?? '',
        'editor_nama'      => $editor['nama'] ?? '',
        'editor_email'     => $editor['email'] ?? '',
        'editor_no_hp'     => $editor['no_hp'] ?? '',
        'catatan_editor'   => '',
    ];
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    konf_csrf_check();

    foreach ($data as $k => $_) {
        if ($k === 'is_scopus') {
            $data[$k] = !empty($_POST['is_scopus']) ? 1 : 0;
        } else {
            $data[$k] = trim($_POST[$k] ?? '');
        }
    }

    if ($data['nama_jurnal'] === '')  $errors[] = 'Nama jurnal wajib diisi.';
    if ($data['url_jurnal'] === '') {
        $errors[] = 'URL jurnal wajib diisi.';
    } elseif (!filter_var($data['url_jurnal'], FILTER_VALIDATE_URL)) {
        $errors[] = 'URL jurnal tidak valid.';
    }
    if ($data['link_arsip'] !== '' && !filter_var($data['link_arsip'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Link Arsip tidak valid.';
    }
    if ($data['editor_email'] !== '' && !filter_var($data['editor_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email ketua editor tidak valid.';
    }
    if ($data['editor_nama'] === '')  $errors[] = 'Nama ketua editor wajib diisi.';
    foreach (['link_gscholar','link_garuda','link_editor','link_sinta'] as $lk) {
        if ($data[$lk] !== '' && !filter_var($data[$lk], FILTER_VALIDATE_URL)) {
            $errors[] = 'Link tidak valid: ' . str_replace('_',' ',$lk) . '.';
        }
    }

    if (empty($errors) && !konf_rate_ok()) {
        $errors[] = 'Terlalu banyak pengiriman dari jaringan Anda. Coba lagi dalam 1 jam.';
    }

    if (empty($errors)) {
        // Selalu INSERT baris baru → riwayat tersimpan utuh.
        $r = exec_q(
            "INSERT INTO konfirmasi
               (jurnal_id, status, nama_jurnal, url_jurnal, unit_kerja,
                volume_per_tahun, apc, p_issn, e_issn, akreditasi, is_scopus,
                link_gscholar, link_garuda, link_editor, link_arsip, link_sinta,
                editor_nama, editor_email, editor_no_hp, catatan_editor, submit_ip)
             VALUES (?, 'pending', ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            'issssssssissssssssss',
            [$jid,
             $data['nama_jurnal'], $data['url_jurnal'], $data['unit_kerja'],
             $data['volume_per_tahun'], $data['apc'], $data['p_issn'], $data['e_issn'],
             $data['akreditasi'], $data['is_scopus'],
             $data['link_gscholar'], $data['link_garuda'], $data['link_editor'],
             $data['link_arsip'], $data['link_sinta'],
             $data['editor_nama'], $data['editor_email'], $data['editor_no_hp'],
             $data['catatan_editor'], konf_client_ip()]
        );
        if ($r) {
            exec_q("UPDATE jurnals SET konfirmasi_status='pending' WHERE id=?", 'i', [$jid]);
            konf_rate_hit();
            $success = true;
        } else {
            $errors[] = 'Gagal menyimpan data. Silakan coba lagi.';
        }
    }
}

konf_header($is_update ? 'Perbarui Data Konfirmasi' : 'Formulir Konfirmasi');

if ($success):
?>
  <div class="konf-card" style="text-align:center;max-width:520px;margin:0 auto">
    <div style="font-size:3rem;line-height:1">✅</div>
    <h2 style="color:#1c7a47;margin:10px 0 6px">
      <?= $is_update ? 'Pembaruan Terkirim' : 'Konfirmasi Terkirim' ?>
    </h2>
    <p style="color:#46546b;font-size:.93rem;line-height:1.6">
      Terima kasih. <?= $is_update ? 'Pembaruan data' : 'Data konfirmasi' ?>
      untuk jurnal <strong><?= h($jurnal['nama_jurnal']) ?></strong> telah
      kami terima dan sedang <strong>menunggu peninjauan admin</strong>.
      Data akan diperbarui setelah disetujui.
      <?php if ($is_update): ?>
        <br><span style="font-size:.85rem;color:#8a94a3">
          Riwayat konfirmasi sebelumnya tetap tersimpan.
        </span>
      <?php endif; ?>
    </p>
    <a href="index.php" class="btn btn-primary" style="margin-top:8px">
      Kembali ke Daftar Jurnal
    </a>
  </div>
<?php
else:
?>
  <div class="konf-card">
    <h2 style="margin-top:0;font-size:1.1rem;color:#1c3a6e">
      <?= h($jurnal['nama_jurnal']) ?>
    </h2>
    <?php if ($is_update): ?>
      <div style="background:#e6effb;border:1px solid #c2d6f0;border-radius:9px;
                  padding:11px 14px;margin:10px 0 4px">
        <strong style="color:#1c4f9c;font-size:.9rem">✎ Mode Perbarui Data</strong>
        <p style="margin:4px 0 0;color:#33415c;font-size:.85rem;line-height:1.6">
          Jurnal ini sudah pernah dikonfirmasi
          <?= $konf_count > 1 ? '(' . $konf_count . ' kali) ' : '' ?>.
          Form di bawah telah diisi dengan data konfirmasi
          <strong>terakhir</strong> — silakan ubah seperlunya. Data lama tetap
          tersimpan sebagai riwayat dan dapat dilihat oleh admin.
        </p>
      </div>
    <?php endif; ?>
    <p style="color:#5a6675;font-size:.88rem;margin-bottom:0">
      Periksa dan lengkapi data di bawah ini. Setelah dikirim, data akan
      ditinjau admin sebelum dipublikasikan. Tanda <span class="req">*</span> wajib diisi.
    </p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="konf-form" action="form.php">
    <?= konf_csrf_field() ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">

    <fieldset>
      <legend>Identitas Jurnal</legend>
      <label>Nama Jurnal <span class="req">*</span>
        <input type="text" name="nama_jurnal" required
               value="<?= h($data['nama_jurnal']) ?>">
      </label>
      <label>URL Utama Jurnal <span class="req">*</span>
        <input type="url" name="url_jurnal" required
               value="<?= h($data['url_jurnal']) ?>"
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
          <input type="text" name="volume_per_tahun"
                 value="<?= h($data['volume_per_tahun']) ?>"
                 placeholder="contoh: 2">
        </label>
        <label>APC (Article Processing Charge)
          <input type="text" name="apc"
                 value="<?= h($data['apc']) ?>"
                 placeholder="contoh: Gratis / Rp 500.000">
        </label>
      </div>
      <div class="row-2">
        <label>P-ISSN
          <input type="text" name="p_issn"
                 value="<?= h($data['p_issn']) ?>" placeholder="xxxx-xxxx">
        </label>
        <label>E-ISSN
          <input type="text" name="e_issn"
                 value="<?= h($data['e_issn']) ?>" placeholder="xxxx-xxxx">
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
                 <?= $data['is_scopus']?'checked':'' ?>
                 style="width:auto;margin:0">
          Terindeks Scopus
        </label>
      </div>
      <label>URL Profil SINTA <span class="konf-jmeta">(kosongkan jika belum terakreditasi)</span>
        <input type="url" name="link_sinta"
               value="<?= h($data['link_sinta']) ?>"
               placeholder="https://sinta.kemdiktisaintek.go.id/journals/profile/...">
      </label>
    </fieldset>

    <fieldset>
      <legend>Tautan Pendukung</legend>
      <label>Link Arsip / Issue Archive
        <input type="url" name="link_arsip"
               value="<?= h($data['link_arsip']) ?>"
               placeholder="https://.../issue/archive">
      </label>
      <label>Link Google Scholar
        <input type="url" name="link_gscholar"
               value="<?= h($data['link_gscholar']) ?>"
               placeholder="https://scholar.google.com/citations?user=...">
      </label>
      <label>Link Garuda
        <input type="url" name="link_garuda"
               value="<?= h($data['link_garuda']) ?>"
               placeholder="https://garuda.kemdiktisaintek.go.id/journal/view/...">
      </label>
      <label>Link Editorial Team
        <input type="url" name="link_editor"
               value="<?= h($data['link_editor']) ?>"
               placeholder="https://.../about/editorialTeam">
      </label>
    </fieldset>

    <fieldset>
      <legend>Ketua Editor</legend>
      <label>Nama Ketua Editor <span class="req">*</span>
        <input type="text" name="editor_nama" required
               value="<?= h($data['editor_nama']) ?>">
      </label>
      <div class="row-2">
        <label>Email Ketua Editor
          <input type="email" name="editor_email"
                 value="<?= h($data['editor_email']) ?>">
        </label>
        <label>No. HP Ketua Editor
          <input type="text" name="editor_no_hp"
                 value="<?= h($data['editor_no_hp']) ?>"
                 placeholder="08xxxxxxxxxx">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Catatan (Opsional)</legend>
      <label><?= $is_update ? 'Catatan Perubahan' : 'Catatan' ?> untuk Admin
        <textarea name="catatan_editor" rows="3"
                  placeholder="<?= $is_update
                    ? 'Jelaskan perubahan apa yang Anda lakukan, jika perlu…'
                    : 'Tuliskan informasi tambahan atau koreksi data jika ada…' ?>"><?= h($data['catatan_editor']) ?></textarea>
      </label>
    </fieldset>

    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary">
        <?= $is_update ? 'Perbarui Data Konfirmasi' : 'Kirim Konfirmasi' ?>
      </button>
      <a href="index.php" class="btn">Batal</a>
    </div>
  </form>
<?php
endif;
konf_footer();
?>
