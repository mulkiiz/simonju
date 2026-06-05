<?php
/**
 * admin/_jurnal_files.php — Partial: tampilkan & upload sertifikat + cover.
 * Include dari admin/jurnal_view.php setelah section info cards.
 *
 * Variabel yang harus tersedia:
 *   $j   = row dari tabel jurnals (harus punya file_sertifikat, file_cover)
 *   csrf_field() harus tersedia
 */
$upload_base = '../uploads/jurnal/';
?>
<style>
.file-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin:24px 0; }
@media(max-width:640px) { .file-grid { grid-template-columns:1fr; } }
.file-card {
    border:1px solid var(--border, #eaecf0); border-radius:10px; padding:20px;
    background:var(--bg, #fff);
}
.file-card h3 { margin:0 0 12px; font-size:15px; }
.file-preview { margin-bottom:12px; }
.file-preview img {
    max-width:200px; max-height:220px; border-radius:8px;
    border:1px solid var(--border, #eaecf0); object-fit:contain;
}
.file-preview .pdf-badge {
    display:inline-flex; align-items:center; gap:6px; padding:10px 16px;
    background:#fef3f2; border-radius:8px; color:#b42318; text-decoration:none;
    font-weight:600; font-size:13px;
}
.file-preview .pdf-badge:hover { background:#fee4e2; }
.file-none { font-size:13px; color:var(--text-muted, #667085); font-style:italic; }
.file-upload { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:8px; }
.file-upload input[type=file] { font-size:13px; }
.file-hint { font-size:11px; color:var(--text-muted, #667085); margin-top:4px; }
</style>

<h2 style="margin-top:32px">📎 File Jurnal</h2>
<div class="file-grid">
  <!-- Sertifikat -->
  <div class="file-card">
    <h3>📜 Sertifikat Akreditasi / Scopus</h3>
    <div class="file-preview">
      <?php if (!empty($j['file_sertifikat'])): ?>
        <a href="<?= h($upload_base . $j['file_sertifikat']) ?>" target="_blank" class="pdf-badge">
          📄 <?= h($j['file_sertifikat']) ?> ↗
        </a>
      <?php else: ?>
        <p class="file-none">Belum diupload oleh editor jurnal.</p>
      <?php endif; ?>
    </div>
    <form method="post" action="../jurnal/upload.php" enctype="multipart/form-data" class="file-upload">
      <?= csrf_field() ?>
      <input type="hidden" name="upload_type" value="sertifikat">
      <input type="hidden" name="jurnal_id" value="<?= (int)$j['id'] ?>">
      <input type="file" name="file" accept=".pdf">
      <button type="submit" class="btn btn-sm">⬆️ Upload</button>
    </form>
    <p class="file-hint">PDF · Maks 2MB</p>
  </div>

  <!-- Cover -->
  <div class="file-card">
    <h3>🖼️ Cover Depan Jurnal</h3>
    <div class="file-preview">
      <?php if (!empty($j['file_cover'])): ?>
        <img src="<?= h($upload_base . $j['file_cover']) ?>" alt="Cover">
      <?php else: ?>
        <p class="file-none">Belum diupload oleh editor jurnal.</p>
      <?php endif; ?>
    </div>
    <form method="post" action="../jurnal/upload.php" enctype="multipart/form-data" class="file-upload">
      <?= csrf_field() ?>
      <input type="hidden" name="upload_type" value="cover">
      <input type="hidden" name="jurnal_id" value="<?= (int)$j['id'] ?>">
      <input type="file" name="file" accept=".jpg,.jpeg,.png,.webp">
      <button type="submit" class="btn btn-sm">⬆️ Upload</button>
    </form>
    <p class="file-hint">JPG / PNG / WebP · Maks 2MB</p>
  </div>
</div>
