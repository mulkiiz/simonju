<?php
/**
 * jurnal_form.php
 * Form tambah/edit jurnal — mencakup SELURUH kolom tabel `jurnals`
 * dan tabel `editor` (data editor disimpan terpisah di tabel `editor`).
 */
$page_title = 'Form Jurnal';
require_once __DIR__ . '/../includes/header_admin.php';

$id     = (int)($_GET['id'] ?? 0);
$row    = $id ? fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$id]) : null;
$is_edit = (bool)$row;

// Data editor (tabel terpisah)
$editor = $id ? fetch_one("SELECT * FROM editor WHERE jurnal_id=?", 'i', [$id]) : null;

$errors = [];

// --- Nilai awal: gabungan kolom jurnals + editor ---
$data = [
    // Tabel jurnals
    'nama_jurnal'          => $row['nama_jurnal']          ?? '',
    'unit_kerja'           => $row['unit_kerja']           ?? '',
    'url_archive'          => $row['url_archive']          ?? '',
    'frekuensi_terbit'     => $row['frekuensi_terbit']     ?? '',
    'volume_per_tahun'     => $row['volume_per_tahun']     ?? '',
    'apc'                  => $row['apc']                  ?? '',
    'doi'                  => $row['doi']                  ?? '',
    'issn'                 => $row['issn']                 ?? '',
    'p_issn'               => $row['p_issn']               ?? '',
    'e_issn'               => $row['e_issn']               ?? '',
    'akreditasi_jenis'     => $row['akreditasi_jenis']     ?? 'belum',
    'akreditasi_peringkat' => $row['akreditasi_peringkat'] ?? '',
    'akreditasi_url'       => $row['akreditasi_url']       ?? '',
    'is_scopus'            => (int)($row['is_scopus']      ?? 0),
    'scopus_q'             => $row['scopus_q']             ?? '',
    'scopus_url'           => $row['scopus_url']           ?? '',
    'link_gscholar'        => $row['link_gscholar']        ?? '',
    'link_garuda'          => $row['link_garuda']          ?? '',
    'link_editor'          => $row['link_editor']          ?? '',
    'link_sinta'           => $row['link_sinta']           ?? '',
    // Tabel editor
    'editor_nama'          => $editor['nama']        ?? '',
    'editor_email'         => $editor['email']       ?? '',
    'editor_no_hp'         => $editor['no_hp']       ?? '',
    'editor_scopus_id'     => $editor['scopus_id']   ?? '',
    'editor_sinta_id'      => $editor['sinta_id']    ?? '',
    'editor_gscholar_id'   => $editor['gscholar_id'] ?? '',
];
if ($data['akreditasi_jenis'] === '') $data['akreditasi_jenis'] = 'belum';

// =====================================================================
// POST
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($data as $k => $_) {
        $data[$k] = ($k === 'is_scopus')
            ? (!empty($_POST['is_scopus']) ? 1 : 0)
            : trim($_POST[$k] ?? '');
    }
    if ($data['akreditasi_jenis'] === '') $data['akreditasi_jenis'] = 'belum';

    // Normalisasi ISSN: format valid xxxx-xxxx (digit, digit cek boleh X).
    // Selain itu (kosong/'0'/teks) -> simbol strip '-'.
    foreach (['p_issn','e_issn'] as $ik) {
        $v = strtoupper(trim($data[$ik]));
        $data[$ik] = preg_match('/^\d{4}-\d{3}[\dX]$/', $v) ? $v : '-';
    }

    // Normalisasi APC: hanya angka positif yang disimpan; selain itu
    // (0/kosong/'Gratis'/teks) dianggap tidak ada APC -> '-'.
    $apc_v = preg_replace('/[^0-9]/', '', $data['apc']); // buang Rp/titik/teks
    $data['apc'] = ($apc_v !== '' && (int)$apc_v > 0) ? $apc_v : '-';

    // --- Validasi jurnal ---
    if ($data['nama_jurnal'] === '') $errors[] = 'Nama jurnal wajib diisi.';
    if ($data['url_archive'] === '') {
        $errors[] = 'URL archive wajib diisi.';
    } elseif (!filter_var($data['url_archive'], FILTER_VALIDATE_URL)) {
        $errors[] = 'URL archive tidak valid.';
    }
    foreach (['link_gscholar','link_garuda','link_editor','link_sinta'] as $lk) {
        if ($data[$lk] !== '' && !filter_var($data[$lk], FILTER_VALIDATE_URL)) {
            $errors[] = 'Link tidak valid: ' . str_replace('_',' ',$lk) . '.';
        }
    }

    // --- Validasi editor ---
    if ($data['editor_email'] !== '' && !filter_var($data['editor_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email editor tidak valid.';
    }

    // --- Cek duplikat url_archive (kolom UNIQUE) ---
    // Tanpa cek ini, INSERT bentrok UNIQUE gagal diam-diam -> insert_id 0 ->
    // redirect ke id=0 -> pesan menyesatkan "Jurnal tidak ditemukan".
    if ($data['url_archive'] !== '') {
        $dup = fetch_one(
            "SELECT id, nama_jurnal FROM jurnals WHERE url_archive=? AND id<>? LIMIT 1",
            'si', [$data['url_archive'], $id]
        );
        if ($dup) {
            $errors[] = 'URL Archive sudah dipakai jurnal "' . $dup['nama_jurnal']
                      . '". Gunakan URL yang unik.';
        }
    }

    // --- Akreditasi: dua dimensi INDEPENDEN (Sinta & Scopus) ---
    $sinta_on    = !empty($_POST['sinta_on']);
    $sinta_level = trim($_POST['sinta_level'] ?? '');
    $sinta_url   = trim($_POST['sinta_url'] ?? '');
    $scopus_on   = !empty($_POST['is_scopus']);
    $scopus_q    = trim($_POST['scopus_q'] ?? '');
    $scopus_url  = trim($_POST['scopus_url'] ?? '');

    // Sinta -> akreditasi_jenis/peringkat/url (kolom lama, dipakai dashboard)
    if ($sinta_on) {
        $data['akreditasi_jenis']     = 'sinta';
        $data['akreditasi_peringkat'] = $sinta_level;
        $data['akreditasi_url']       = $sinta_url;
    } else {
        $data['akreditasi_jenis']     = 'belum';
        $data['akreditasi_peringkat'] = '';
        $data['akreditasi_url']       = '';
    }
    // link_sinta = URL Profil Sinta (satu sumber; dipakai bot/statistik)
    $data['link_sinta'] = $data['akreditasi_url'];
    // Scopus -> is_scopus + scopus_q + scopus_url (kolom terpisah)
    $data['is_scopus']  = $scopus_on ? 1 : 0;
    $data['scopus_q']   = $scopus_on ? $scopus_q : '';
    $data['scopus_url'] = $scopus_on ? $scopus_url : '';

    // Validasi Sinta
    if ($sinta_on) {
        $valid_sinta = ['Sinta 1','Sinta 2','Sinta 3','Sinta 4','Sinta 5','Sinta 6'];
        if (!in_array($data['akreditasi_peringkat'], $valid_sinta, true)) {
            $errors[] = 'Pilih peringkat Sinta 1–6.';
        }
        if ($data['akreditasi_url'] === '') {
            $errors[] = 'URL profil Sinta wajib diisi untuk jurnal terakreditasi Sinta.';
        } elseif (!filter_var($data['akreditasi_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'URL Sinta tidak valid.';
        }
    }
    // Validasi Scopus
    if ($scopus_on) {
        $valid_q = ['Q1','Q2','Q3','Q4'];
        if (!in_array($data['scopus_q'], $valid_q, true)) {
            $errors[] = 'Pilih kuartil Scopus Q1–Q4.';
        }
        if ($data['scopus_url'] === '') {
            $errors[] = 'URL Scimago wajib diisi untuk jurnal terindeks Scopus.';
        } elseif (!filter_var($data['scopus_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'URL Scimago tidak valid.';
        }
    }

    if (empty($errors)) {
        if ($is_edit) {
            exec_q(
                "UPDATE jurnals SET
                    nama_jurnal=?, unit_kerja=?, url_archive=?,
                    frekuensi_terbit=?, volume_per_tahun=?, apc=?,
                    doi=?, issn=?, p_issn=?, e_issn=?,
                    akreditasi_jenis=?, akreditasi_peringkat=?, akreditasi_url=?,
                    is_scopus=?, scopus_q=?, scopus_url=?,
                    link_gscholar=?, link_garuda=?, link_editor=?, link_sinta=?
                 WHERE id=?",
                'sssssssssssssissssssi',
                [$data['nama_jurnal'], $data['unit_kerja'], $data['url_archive'],
                 $data['frekuensi_terbit'], $data['volume_per_tahun'], $data['apc'],
                 $data['doi'], $data['issn'], $data['p_issn'], $data['e_issn'],
                 $data['akreditasi_jenis'], $data['akreditasi_peringkat'], $data['akreditasi_url'],
                 $data['is_scopus'], $data['scopus_q'], $data['scopus_url'],
                 $data['link_gscholar'], $data['link_garuda'], $data['link_editor'], $data['link_sinta'],
                 $id]
            );
            $jid = $id;
        } else {
            // Jurnal baru via admin: langsung terkonfirmasi + buat token
            // sebagai password awal akun login.
            $konf_token = bin2hex(random_bytes(8)); // 16 char
            $r = exec_q(
                "INSERT INTO jurnals
                    (nama_jurnal, unit_kerja, url_archive,
                     frekuensi_terbit, volume_per_tahun, apc,
                     doi, issn, p_issn, e_issn,
                     akreditasi_jenis, akreditasi_peringkat, akreditasi_url,
                     is_scopus, scopus_q, scopus_url,
                     link_gscholar, link_garuda, link_editor, link_sinta,
                     konfirmasi_status, konfirmasi_token, konfirmasi_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'terkonfirmasi',?,NOW())",
                'sssssssssssssisssssss',
                [$data['nama_jurnal'], $data['unit_kerja'], $data['url_archive'],
                 $data['frekuensi_terbit'], $data['volume_per_tahun'], $data['apc'],
                 $data['doi'], $data['issn'], $data['p_issn'], $data['e_issn'],
                 $data['akreditasi_jenis'], $data['akreditasi_peringkat'], $data['akreditasi_url'],
                 $data['is_scopus'], $data['scopus_q'], $data['scopus_url'],
                 $data['link_gscholar'], $data['link_garuda'], $data['link_editor'], $data['link_sinta'],
                 $konf_token]
            );
            $jid = is_array($r) ? (int)$r['insert_id'] : 0;
            // Buat akun login dengan password awal = token.
            if ($jid > 0 && function_exists('ensure_jurnal_account')) {
                ensure_jurnal_account($jid, $data['link_editor'] ?: null, $konf_token);
            }
            if ($jid <= 0) {
                $errors[] = 'Gagal menyimpan jurnal (kemungkinan URL Archive sudah terdaftar).';
            }
        }

        // Lanjut simpan editor & redirect hanya bila jurnal tersimpan.
        if ($jid > 0):

        // --- Upsert editor (tabel terpisah) ---
        $punya_editor_data = ($data['editor_nama'] !== '' || $data['editor_email'] !== ''
            || $data['editor_no_hp'] !== '' || $data['editor_scopus_id'] !== ''
            || $data['editor_sinta_id'] !== '' || $data['editor_gscholar_id'] !== '');

        $ada = fetch_one("SELECT id FROM editor WHERE jurnal_id=?", 'i', [$jid]);
        if ($ada) {
            exec_q(
                "UPDATE editor SET nama=?, email=?, no_hp=?,
                    scopus_id=?, sinta_id=?, gscholar_id=? WHERE jurnal_id=?",
                'ssssssi',
                [$data['editor_nama'], $data['editor_email'], $data['editor_no_hp'],
                 $data['editor_scopus_id'], $data['editor_sinta_id'], $data['editor_gscholar_id'],
                 $jid]
            );
        } elseif ($punya_editor_data) {
            exec_q(
                "INSERT INTO editor (jurnal_id, nama, email, no_hp,
                    scopus_id, sinta_id, gscholar_id) VALUES (?,?,?,?,?,?,?)",
                'issssss',
                [$jid, $data['editor_nama'], $data['editor_email'], $data['editor_no_hp'],
                 $data['editor_scopus_id'], $data['editor_sinta_id'], $data['editor_gscholar_id']]
            );
        }

        header("Location: jurnal_view.php?id={$jid}&saved=1");
        exit;

        endif; // $jid > 0
    }
}
?>
<div class="page-head">
  <h1><?= $is_edit ? 'Edit Jurnal' : 'Tambah Jurnal' ?></h1>
  <a href="<?= $is_edit ? 'jurnal_view.php?id=' . (int)$id : 'dashboard.php' ?>" class="btn">&larr; Kembali</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error">
    <ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="form-grid">
  <?= csrf_field() ?>

  <fieldset>
    <legend>Identitas Jurnal</legend>
    <label>Nama Jurnal *
      <input type="text" name="nama_jurnal" value="<?= h($data['nama_jurnal']) ?>" required>
    </label>
    <label>Unit Kerja Pengelola
      <select name="unit_kerja">
        <option value="">— Pilih Unit Kerja —</option>
        <?php
          $uk_list = [
            'Fakultas Pertanian (Faperta)', 'Fakultas Biologi (Fabio)',
            'Fakultas Ekonomi dan Bisnis (FEB)', 'Fakultas Peternakan (Fapet)',
            'Fakultas Hukum (FH)', 'Fakultas Ilmu Sosial dan Ilmu Politik (FISIP)',
            'Fakultas Kedokteran (FK)', 'Fakultas Teknik (FT)',
            'Fakultas Ilmu-Ilmu Kesehatan (FIKES)', 'Fakultas Ilmu Budaya (FIB)',
            'Fakultas Matematika dan Ilmu Pengetahuan Alam (FMIPA)',
            'Fakultas Perikanan dan Ilmu Kelautan (FPIK)', 'LPPM', 'Unit kerja lainnya',
          ];
          $cur_uk = $data['unit_kerja'];
          if ($cur_uk !== '' && !in_array($cur_uk, $uk_list, true)) array_unshift($uk_list, $cur_uk);
          foreach ($uk_list as $uk):
        ?>
          <option value="<?= h($uk) ?>" <?= $cur_uk === $uk ? 'selected' : '' ?>><?= h($uk) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>URL Archive (halaman issue/archive) *
      <input type="url" name="url_archive" value="<?= h($data['url_archive']) ?>"
             placeholder="https://jos.unsoed.ac.id/index.php/JIM/issue/archive" required>
    </label>
    <label>Volume Terbit per Tahun <span class="muted small">(berapa kali terbit dalam setahun)</span>
      <input type="text" name="volume_per_tahun" value="<?= h($data['volume_per_tahun']) ?>"
             placeholder="contoh: 2">
    </label>
    <!-- Frekuensi terbit dihapus (sama dengan Volume per Tahun); kolom lama dipertahankan tersembunyi -->
    <input type="hidden" name="frekuensi_terbit" value="<?= h($data['frekuensi_terbit']) ?>">
    <label>APC (Article Processing Charge) <span class="muted small">(angka saja, kosongkan bila tidak ada)</span>
      <input type="text" name="apc" value="<?= h($data['apc']) ?>"
             placeholder="contoh: 500000">
    </label>
  </fieldset>

  <fieldset>
    <legend>Identifier</legend>
    <div class="row-3">
      <label>P-ISSN
        <input type="text" name="p_issn" value="<?= h($data['p_issn']) ?>" placeholder="xxxx-xxxx">
      </label>
      <label>E-ISSN
        <input type="text" name="e_issn" value="<?= h($data['e_issn']) ?>" placeholder="xxxx-xxxx">
      </label>
      <label>DOI Prefix
        <input type="text" name="doi" value="<?= h($data['doi']) ?>" placeholder="10.20884/...">
      </label>
    </div>
    <!-- Kolom 'issn' lama dipertahankan tersembunyi agar data tak hilang;
         input ISSN cukup P-ISSN & E-ISSN di atas. -->
    <input type="hidden" name="issn" value="<?= h($data['issn']) ?>">
  </fieldset>

  <fieldset>
    <legend>Akreditasi / Indeksasi</legend>
    <p class="muted small" style="margin:0 0 10px">
      Centang sesuai status. Sebuah jurnal bisa <strong>terakreditasi Sinta</strong> sekaligus <strong>terindeks Scopus</strong>.
      <br><em>Abaikan (jangan dicentang) jika belum terakreditasi.</em>
    </p>

    <!-- SINTA -->
    <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin:0 0 6px;cursor:pointer">
      <input type="checkbox" name="sinta_on" id="sintaOn" value="1"
             <?= $data['akreditasi_jenis']==='sinta' ? 'checked' : '' ?>>
      🏅 Terakreditasi SINTA
    </label>
    <div id="boxSinta" class="akr-box" style="<?= $data['akreditasi_jenis']==='sinta' ? '' : 'display:none' ?>">
      <div class="row-2">
        <label>Peringkat Sinta *
          <select name="sinta_level" id="sintaLevel">
            <option value="">— pilih —</option>
            <?php foreach (['Sinta 1','Sinta 2','Sinta 3','Sinta 4','Sinta 5','Sinta 6'] as $s): ?>
              <option value="<?= h($s) ?>"
                <?= ($data['akreditasi_jenis']==='sinta' && $data['akreditasi_peringkat']===$s) ? 'selected' : '' ?>>
                <?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>URL Profil Sinta *
          <input type="url" name="sinta_url" id="sintaUrl"
                 value="<?= $data['akreditasi_jenis']==='sinta' ? h($data['akreditasi_url']) : '' ?>"
                 placeholder="https://sinta.kemdiktisaintek.go.id/journals/profile/14871">
        </label>
      </div>
    </div>

    <!-- SCOPUS -->
    <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin:14px 0 6px;cursor:pointer">
      <input type="checkbox" name="is_scopus" id="scopusOn" value="1"
             <?= $data['is_scopus'] ? 'checked' : '' ?>>
      🌐 Terindeks Scopus
    </label>
    <div id="boxScopus" class="akr-box" style="<?= $data['is_scopus'] ? '' : 'display:none' ?>">
      <div class="row-2">
        <label>Kuartil Scopus *
          <select name="scopus_q" id="scopusQ">
            <option value="">— pilih —</option>
            <?php foreach (['Q1','Q2','Q3','Q4'] as $q): ?>
              <option value="<?= h($q) ?>" <?= $data['scopus_q']===$q ? 'selected' : '' ?>><?= h($q) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>URL Scimago *
          <input type="url" name="scopus_url" id="scopusUrl"
                 value="<?= h($data['scopus_url']) ?>"
                 placeholder="https://www.scimagojr.com/journalsearch.php?q=...">
        </label>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Tautan Pendukung</legend>
    <!-- Link Profil SINTA dihapus: cukup diisi di "URL Profil Sinta"
         pada bagian Akreditasi (saat status Sinta dicentang). -->
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
    <div class="row-2">
      <label>Nama
        <input type="text" name="editor_nama" value="<?= h($data['editor_nama']) ?>">
      </label>
      <label>Email
        <input type="email" name="editor_email" value="<?= h($data['editor_email']) ?>">
      </label>
    </div>
    <label>No. HP / WhatsApp
      <input type="text" name="editor_no_hp" value="<?= h($data['editor_no_hp']) ?>"
             placeholder="08xxxxxxxxxx">
    </label>
    <div class="row-3">
      <label>Scopus ID
        <input type="text" name="editor_scopus_id" value="<?= h($data['editor_scopus_id']) ?>">
      </label>
      <label>Sinta ID
        <input type="text" name="editor_sinta_id" value="<?= h($data['editor_sinta_id']) ?>">
      </label>
      <label>Google Scholar ID
        <input type="text" name="editor_gscholar_id" value="<?= h($data['editor_gscholar_id']) ?>">
      </label>
    </div>
  </fieldset>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Simpan</button>
    <a href="<?= $is_edit ? 'jurnal_view.php?id=' . (int)$id : 'dashboard.php' ?>" class="btn">Batal</a>
    <?php if ($is_edit && is_admin()): ?>
      <button type="button" class="btn btn-danger" id="deleteJurnalBtn">🗑️ Hapus Jurnal</button>
    <?php endif; ?>
  </div>
</form>

<?php if ($is_edit && is_admin()): ?>
<!-- Form delete terpisah (tak boleh nested di dalam form edit) -->
<form id="deleteJurnalForm" method="post" action="jurnal_delete.php" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="jurnal_id" value="<?= (int)$id ?>">
  <input type="hidden" name="confirm_name" id="deleteConfirmName" value="">
</form>
<script>
(function () {
  var btn  = document.getElementById('deleteJurnalBtn');
  var form = document.getElementById('deleteJurnalForm');
  var hid  = document.getElementById('deleteConfirmName');
  if (!btn) return;
  var nama = <?= json_encode($data['nama_jurnal'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
  btn.addEventListener('click', function () {
    var msg = 'PERINGATAN: Hapus jurnal ini PERMANEN beserta SEMUA data terkait ' +
      '(terbitan, log crawl, log scan judol, akreditasi, konfirmasi, akun login, editor, file cover/sertifikat).\n\n' +
      'Ketik nama jurnal persis untuk konfirmasi:\n' + nama;
    var typed = window.prompt(msg, '');
    if (typed === null) return;
    if (typed.trim() !== nama.trim()) { alert('Nama tidak cocok. Hapus dibatalkan.'); return; }
    hid.value = typed.trim();
    form.submit();
  });
})();
</script>
<?php endif; ?>

<script>
(function () {
  // Toggle box Sinta & Scopus independen via checkbox
  var sintaOn   = document.getElementById('sintaOn');
  var scopusOn  = document.getElementById('scopusOn');
  var boxSinta  = document.getElementById('boxSinta');
  var boxScopus = document.getElementById('boxScopus');

  function sync() {
    boxSinta.style.display  = sintaOn.checked  ? '' : 'none';
    boxScopus.style.display = scopusOn.checked ? '' : 'none';
  }
  sintaOn.addEventListener('change', sync);
  scopusOn.addEventListener('change', sync);
  sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
