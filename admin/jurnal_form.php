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

    // --- Validasi akreditasi ---
    $valid_jenis = ['sinta','scopus','belum'];
    if (!in_array($data['akreditasi_jenis'], $valid_jenis, true)) {
        $errors[] = 'Jenis akreditasi tidak valid.';
    }
    if ($data['akreditasi_jenis'] === 'sinta') {
        $valid_sinta = ['Sinta 1','Sinta 2','Sinta 3','Sinta 4','Sinta 5','Sinta 6'];
        if (!in_array($data['akreditasi_peringkat'], $valid_sinta, true)) {
            $errors[] = 'Pilih peringkat Sinta 1–6.';
        }
        if ($data['akreditasi_url'] === '') {
            $errors[] = 'URL profil Sinta wajib diisi untuk jurnal terakreditasi Sinta.';
        } elseif (!filter_var($data['akreditasi_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'URL Sinta tidak valid.';
        }
    } elseif ($data['akreditasi_jenis'] === 'scopus') {
        $valid_q = ['Q1','Q2','Q3','Q4'];
        if (!in_array($data['akreditasi_peringkat'], $valid_q, true)) {
            $errors[] = 'Pilih kuartil Q1–Q4.';
        }
        if ($data['akreditasi_url'] === '') {
            $errors[] = 'URL Scimago wajib diisi untuk jurnal terindeks Scopus.';
        } elseif (!filter_var($data['akreditasi_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'URL Scimago tidak valid.';
        }
    } else {
        $data['akreditasi_peringkat'] = '';
        $data['akreditasi_url'] = '';
    }

    // is_scopus otomatis sinkron dengan jenis akreditasi
    if ($data['akreditasi_jenis'] === 'scopus') $data['is_scopus'] = 1;

    if (empty($errors)) {
        if ($is_edit) {
            exec_q(
                "UPDATE jurnals SET
                    nama_jurnal=?, unit_kerja=?, url_archive=?,
                    frekuensi_terbit=?, volume_per_tahun=?, apc=?,
                    doi=?, issn=?, p_issn=?, e_issn=?,
                    akreditasi_jenis=?, akreditasi_peringkat=?, akreditasi_url=?, is_scopus=?,
                    link_gscholar=?, link_garuda=?, link_editor=?, link_sinta=?
                 WHERE id=?",
                'sssssssssssssissssi',
                [$data['nama_jurnal'], $data['unit_kerja'], $data['url_archive'],
                 $data['frekuensi_terbit'], $data['volume_per_tahun'], $data['apc'],
                 $data['doi'], $data['issn'], $data['p_issn'], $data['e_issn'],
                 $data['akreditasi_jenis'], $data['akreditasi_peringkat'], $data['akreditasi_url'],
                 $data['is_scopus'],
                 $data['link_gscholar'], $data['link_garuda'], $data['link_editor'], $data['link_sinta'],
                 $id]
            );
            $jid = $id;
        } else {
            $r = exec_q(
                "INSERT INTO jurnals
                    (nama_jurnal, unit_kerja, url_archive,
                     frekuensi_terbit, volume_per_tahun, apc,
                     doi, issn, p_issn, e_issn,
                     akreditasi_jenis, akreditasi_peringkat, akreditasi_url, is_scopus,
                     link_gscholar, link_garuda, link_editor, link_sinta)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                'sssssssssssssissss',
                [$data['nama_jurnal'], $data['unit_kerja'], $data['url_archive'],
                 $data['frekuensi_terbit'], $data['volume_per_tahun'], $data['apc'],
                 $data['doi'], $data['issn'], $data['p_issn'], $data['e_issn'],
                 $data['akreditasi_jenis'], $data['akreditasi_peringkat'], $data['akreditasi_url'],
                 $data['is_scopus'],
                 $data['link_gscholar'], $data['link_garuda'], $data['link_editor'], $data['link_sinta']]
            );
            $jid = (int)$r['insert_id'];
        }

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
      <input type="text" name="unit_kerja" value="<?= h($data['unit_kerja']) ?>"
             placeholder="contoh: Fakultas Hukum (FH) / LPPM">
    </label>
    <label>URL Archive (halaman issue/archive) *
      <input type="url" name="url_archive" value="<?= h($data['url_archive']) ?>"
             placeholder="https://jos.unsoed.ac.id/index.php/JIM/issue/archive" required>
    </label>
    <div class="row-2">
      <label>Frekuensi Terbit
        <input type="text" name="frekuensi_terbit" value="<?= h($data['frekuensi_terbit']) ?>"
               placeholder="contoh: 2 kali setahun (Juni & Desember)">
      </label>
      <label>Volume Terbit per Tahun
        <input type="text" name="volume_per_tahun" value="<?= h($data['volume_per_tahun']) ?>"
               placeholder="contoh: 2">
      </label>
    </div>
    <label>APC (Article Processing Charge)
      <input type="text" name="apc" value="<?= h($data['apc']) ?>"
             placeholder="contoh: Gratis / Rp 500.000">
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
    <label>ISSN <span class="muted small">(kolom lama — opsional, gunakan P-ISSN/E-ISSN di atas)</span>
      <input type="text" name="issn" value="<?= h($data['issn']) ?>" placeholder="2086-xxxx">
    </label>
  </fieldset>

  <fieldset>
    <legend>Akreditasi / Indeksasi</legend>
    <label>Jenis Akreditasi *
      <select name="akreditasi_jenis" id="akrJenis">
        <option value="belum"  <?= $data['akreditasi_jenis']==='belum'  ? 'selected' : '' ?>>Belum Terakreditasi</option>
        <option value="sinta"  <?= $data['akreditasi_jenis']==='sinta'  ? 'selected' : '' ?>>Sinta (Akreditasi Nasional)</option>
        <option value="scopus" <?= $data['akreditasi_jenis']==='scopus' ? 'selected' : '' ?>>Scopus / Scimago (Internasional)</option>
      </select>
    </label>

    <div id="boxSinta" class="akr-box" style="display:none">
      <div class="row-2">
        <label>Peringkat Sinta *
          <select name="akreditasi_peringkat_sinta" id="peringkatSinta">
            <option value="">— pilih —</option>
            <?php foreach (['Sinta 1','Sinta 2','Sinta 3','Sinta 4','Sinta 5','Sinta 6'] as $s): ?>
              <option value="<?= h($s) ?>"
                <?= ($data['akreditasi_jenis']==='sinta' && $data['akreditasi_peringkat']===$s) ? 'selected' : '' ?>>
                <?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>URL Profil Sinta *
          <input type="url" name="akreditasi_url_sinta" id="urlSinta"
                 value="<?= $data['akreditasi_jenis']==='sinta' ? h($data['akreditasi_url']) : '' ?>"
                 placeholder="https://sinta.kemdiktisaintek.go.id/journals/profile/14871">
        </label>
      </div>
    </div>

    <div id="boxScopus" class="akr-box" style="display:none">
      <div class="row-2">
        <label>Kuartil Scopus *
          <select name="akreditasi_peringkat_scopus" id="peringkatScopus">
            <option value="">— pilih —</option>
            <?php foreach (['Q1','Q2','Q3','Q4'] as $q): ?>
              <option value="<?= h($q) ?>"
                <?= ($data['akreditasi_jenis']==='scopus' && $data['akreditasi_peringkat']===$q) ? 'selected' : '' ?>>
                <?= h($q) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>URL Scimago *
          <input type="url" name="akreditasi_url_scopus" id="urlScopus"
                 value="<?= $data['akreditasi_jenis']==='scopus' ? h($data['akreditasi_url']) : '' ?>"
                 placeholder="https://www.scimagojr.com/journalsearch.php?q=...">
        </label>
      </div>
    </div>

    <input type="hidden" name="akreditasi_peringkat" id="akrPeringkat" value="<?= h($data['akreditasi_peringkat']) ?>">
    <input type="hidden" name="akreditasi_url"       id="akrUrl"       value="<?= h($data['akreditasi_url']) ?>">
  </fieldset>

  <fieldset>
    <legend>Tautan Pendukung</legend>
    <label>Link Profil SINTA
      <input type="url" name="link_sinta" value="<?= h($data['link_sinta']) ?>"
             placeholder="https://sinta.kemdiktisaintek.go.id/journals/profile/...">
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
    <?php if ($is_edit): ?>
      <button type="submit" formaction="jurnal_delete.php" class="btn btn-danger"
              onclick="return confirm('Yakin hapus jurnal ini beserta semua data terbitannya?')">Hapus</button>
    <?php endif; ?>
  </div>
</form>

<script>
(function () {
  var sel       = document.getElementById('akrJenis');
  var boxSinta  = document.getElementById('boxSinta');
  var boxScopus = document.getElementById('boxScopus');
  var hPer      = document.getElementById('akrPeringkat');
  var hUrl      = document.getElementById('akrUrl');
  var pSinta    = document.getElementById('peringkatSinta');
  var pScopus   = document.getElementById('peringkatScopus');
  var uSinta    = document.getElementById('urlSinta');
  var uScopus   = document.getElementById('urlScopus');

  function refresh() {
    var v = sel.value;
    boxSinta.style.display  = (v === 'sinta')  ? '' : 'none';
    boxScopus.style.display = (v === 'scopus') ? '' : 'none';
    if (v === 'sinta') {
      hPer.value = pSinta.value || '';
      hUrl.value = uSinta.value || '';
    } else if (v === 'scopus') {
      hPer.value = pScopus.value || '';
      hUrl.value = uScopus.value || '';
    } else {
      hPer.value = '';
      hUrl.value = '';
    }
  }

  sel.addEventListener('change', refresh);
  pSinta.addEventListener('change', refresh);
  pScopus.addEventListener('change', refresh);
  uSinta.addEventListener('input', refresh);
  uScopus.addEventListener('input', refresh);

  refresh();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
