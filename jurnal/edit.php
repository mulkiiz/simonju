<?php
/**
 * jurnal/edit.php — Edit data jurnal sendiri (tanpa hapus).
 */
$page_title = 'Edit Data Jurnal';
require_once __DIR__ . '/../includes/header_jurnal.php';

$jid = current_jurnal_id();
$j   = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jid]);
if (!$j) { echo '<p>Jurnal tidak ditemukan.</p>'; require_once __DIR__.'/../includes/footer.php'; exit; }
$editor = fetch_one("SELECT * FROM editor WHERE jurnal_id=? LIMIT 1", 'i', [$jid]) ?: [];

$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Sanitize input
    $nama         = trim($_POST['nama_jurnal'] ?? '');
    $unit_kerja   = trim($_POST['unit_kerja'] ?? '');
    $url_archive  = trim($_POST['url_archive'] ?? '');
    $frekuensi    = trim($_POST['frekuensi_terbit'] ?? '');
    $vol_per_thn  = trim($_POST['volume_per_tahun'] ?? '');
    $apc          = trim($_POST['apc'] ?? '');
    $doi          = trim($_POST['doi'] ?? '');
    $p_issn       = trim($_POST['p_issn'] ?? '');
    $e_issn       = trim($_POST['e_issn'] ?? '');
    $link_gscholar = trim($_POST['link_gscholar'] ?? '');
    $link_garuda   = trim($_POST['link_garuda'] ?? '');
    $link_editor   = trim($_POST['link_editor'] ?? '');
    $link_sinta    = trim($_POST['link_sinta'] ?? '');

    // Editor fields
    $ed_nama       = trim($_POST['editor_nama'] ?? '');
    $ed_email      = trim($_POST['editor_email'] ?? '');
    $ed_no_hp      = trim($_POST['editor_no_hp'] ?? '');
    $ed_scopus     = trim($_POST['editor_scopus_id'] ?? '');
    $ed_sinta      = trim($_POST['editor_sinta_id'] ?? '');
    $ed_gscholar   = trim($_POST['editor_gscholar_id'] ?? '');

    if ($nama === '') {
        $err = 'Nama jurnal wajib diisi.';
    } else {
        // Update jurnals
        exec_q(
            "UPDATE jurnals SET
                nama_jurnal=?, unit_kerja=?, url_archive=?, frekuensi_terbit=?,
                volume_per_tahun=?, apc=?, doi=?, p_issn=?, e_issn=?,
                link_gscholar=?, link_garuda=?, link_editor=?, link_sinta=?,
                updated_at=NOW()
             WHERE id=?",
            'sssssssssssssi',
            [$nama, $unit_kerja, $url_archive, $frekuensi,
             $vol_per_thn, $apc, $doi, $p_issn, $e_issn,
             $link_gscholar, $link_garuda, $link_editor, $link_sinta,
             $jid]
        );

        // Update or insert editor
        if (!empty($editor)) {
            exec_q(
                "UPDATE editor SET nama=?, email=?, no_hp=?, scopus_id=?, sinta_id=?, gscholar_id=? WHERE jurnal_id=?",
                'ssssssi', [$ed_nama, $ed_email, $ed_no_hp, $ed_scopus, $ed_sinta, $ed_gscholar, $jid]
            );
        } else {
            exec_q(
                "INSERT INTO editor (jurnal_id, nama, email, no_hp, scopus_id, sinta_id, gscholar_id) VALUES (?,?,?,?,?,?,?)",
                'issssss', [$jid, $ed_nama, $ed_email, $ed_no_hp, $ed_scopus, $ed_sinta, $ed_gscholar]
            );
        }

        header('Location: index.php?saved=1');
        exit;
    }
}
?>
<div class="page-head">
  <h1>✏️ Edit Data Jurnal</h1>
  <a href="index.php" class="btn">&larr; Dashboard</a>
</div>

<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="form-grid" style="max-width:720px">
  <?= csrf_field() ?>

  <fieldset>
    <legend>📚 Info Jurnal</legend>
    <label>Nama Jurnal *
      <input type="text" name="nama_jurnal" value="<?= h($j['nama_jurnal']) ?>" required>
    </label>
    <label>Unit Kerja
      <input type="text" name="unit_kerja" value="<?= h($j['unit_kerja']) ?>">
    </label>
    <label>URL Archive (OJS)
      <input type="url" name="url_archive" value="<?= h($j['url_archive']) ?>">
    </label>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label>Frekuensi Terbit
        <input type="text" name="frekuensi_terbit" value="<?= h($j['frekuensi_terbit']) ?>">
      </label>
      <label>Volume per Tahun
        <input type="text" name="volume_per_tahun" value="<?= h($j['volume_per_tahun']) ?>">
      </label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label>p-ISSN
        <input type="text" name="p_issn" value="<?= h($j['p_issn']) ?>">
      </label>
      <label>e-ISSN
        <input type="text" name="e_issn" value="<?= h($j['e_issn']) ?>">
      </label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label>APC (angka Rupiah, 0 = gratis)
        <input type="text" name="apc" value="<?= h($j['apc']) ?>">
      </label>
      <label>DOI Prefix
        <input type="text" name="doi" value="<?= h($j['doi']) ?>">
      </label>
    </div>
  </fieldset>

  <fieldset>
    <legend>👤 Ketua Editor</legend>
    <label>Nama
      <input type="text" name="editor_nama" value="<?= h($editor['nama'] ?? '') ?>">
    </label>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label>Email
        <input type="email" name="editor_email" value="<?= h($editor['email'] ?? '') ?>">
      </label>
      <label>No. HP
        <input type="text" name="editor_no_hp" value="<?= h($editor['no_hp'] ?? '') ?>">
      </label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
      <label>Scopus ID
        <input type="text" name="editor_scopus_id" value="<?= h($editor['scopus_id'] ?? '') ?>">
      </label>
      <label>Sinta ID
        <input type="text" name="editor_sinta_id" value="<?= h($editor['sinta_id'] ?? '') ?>">
      </label>
      <label>GScholar ID
        <input type="text" name="editor_gscholar_id" value="<?= h($editor['gscholar_id'] ?? '') ?>">
      </label>
    </div>
  </fieldset>

  <fieldset>
    <legend>🔗 Link Eksternal</legend>
    <label>Link Google Scholar
      <input type="url" name="link_gscholar" value="<?= h($j['link_gscholar']) ?>">
    </label>
    <label>Link Garuda
      <input type="url" name="link_garuda" value="<?= h($j['link_garuda']) ?>">
    </label>
    <label>Link Editorial Team
      <input type="url" name="link_editor" value="<?= h($j['link_editor']) ?>">
    </label>
    <label>Link Sinta
      <input type="url" name="link_sinta" value="<?= h($j['link_sinta']) ?>">
    </label>
  </fieldset>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">💾 Simpan</button>
    <a href="index.php" class="btn">Batal</a>
  </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
