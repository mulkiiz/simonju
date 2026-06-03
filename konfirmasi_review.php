<?php
/**
 * konfirmasi_review.php
 * Admin meninjau 1 konfirmasi: bandingkan data lama vs data submit,
 * lalu Setujui (apply ke jurnals + editor) atau Tolak.
 */
$page_title = 'Tinjau Konfirmasi';
require_once __DIR__ . '/_header.php';

$id = (int)($_GET['id'] ?? 0);
$k  = fetch_one("SELECT * FROM konfirmasi WHERE id=?", 'i', [$id]);
if (!$k) { echo '<p>Data konfirmasi tidak ditemukan.</p>'; require_once __DIR__.'/_footer.php'; exit; }

$jid    = (int)$k['jurnal_id'];
$jurnal = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jid]);
$editor = fetch_one("SELECT * FROM editor WHERE jurnal_id=?", 'i', [$jid]);

// =========================================================
// POST: approve / reject
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act  = $_POST['act'] ?? '';
    $note = trim($_POST['admin_note'] ?? '');
    $by   = $_SESSION['uname'] ?? 'admin';

    if ($act === 'approve' && $k['status'] === 'pending') {
        // Tentukan jenis akreditasi dari peringkat + is_scopus
        $is_scopus = (int)$k['is_scopus'];
        $peringkat = trim((string)$k['akreditasi']);
        if ($is_scopus) {
            $jenis = 'scopus';
        } elseif ($peringkat !== '') {
            $jenis = 'sinta';
        } else {
            $jenis = 'belum';
        }

        // --- Update tabel jurnals (hanya kolom jurnal) ---
        exec_q(
            "UPDATE jurnals SET
               nama_jurnal=?, url_archive=?, unit_kerja=?,
               volume_per_tahun=?, apc=?, p_issn=?, e_issn=?,
               akreditasi_jenis=?, akreditasi_peringkat=?, is_scopus=?, akreditasi_url=?,
               link_gscholar=?, link_garuda=?, link_editor=?, link_sinta=?,
               konfirmasi_status='terkonfirmasi', konfirmasi_at=NOW()
             WHERE id=?",
            'sssssssssisssssi',
            [$k['nama_jurnal'],
             ($k['link_arsip'] ?: $k['url_jurnal']),
             $k['unit_kerja'], $k['volume_per_tahun'], $k['apc'],
             $k['p_issn'], $k['e_issn'],
             $jenis, $peringkat, $is_scopus, $k['link_sinta'],
             $k['link_gscholar'], $k['link_garuda'], $k['link_editor'], $k['link_sinta'],
             $jid]
        );

        // --- Upsert tabel editor ---
        if ($editor) {
            exec_q(
                "UPDATE editor SET nama=?, email=?, no_hp=? WHERE jurnal_id=?",
                'sssi', [$k['editor_nama'], $k['editor_email'], $k['editor_no_hp'], $jid]
            );
        } else {
            exec_q(
                "INSERT INTO editor (jurnal_id, nama, email, no_hp) VALUES (?,?,?,?)",
                'isss', [$jid, $k['editor_nama'], $k['editor_email'], $k['editor_no_hp']]
            );
        }

        exec_q(
            "UPDATE konfirmasi SET status='approved', reviewed_at=NOW(),
             reviewed_by=?, admin_note=? WHERE id=?",
            'ssi', [$by, $note, $id]
        );
        header("Location: konfirmasi_admin.php?st=approved&done=1&msg="
             . urlencode('Konfirmasi disetujui & data jurnal diperbarui.'));
        exit;
    }

    if ($act === 'reject' && $k['status'] === 'pending') {
        exec_q(
            "UPDATE konfirmasi SET status='rejected', reviewed_at=NOW(),
             reviewed_by=?, admin_note=? WHERE id=?",
            'ssi', [$by, $note, $id]
        );
        // Kembalikan status jurnal: ada konfirmasi pending lain?
        $sisa = fetch_one(
            "SELECT COUNT(*) n FROM konfirmasi WHERE jurnal_id=? AND status='pending'",
            'i', [$jid]
        );
        $newst = ((int)$sisa['n'] > 0) ? 'pending'
               : ($jurnal['konfirmasi_at'] ? 'terkonfirmasi' : 'belum');
        exec_q("UPDATE jurnals SET konfirmasi_status=? WHERE id=?", 'si', [$newst, $jid]);
        header("Location: konfirmasi_admin.php?st=rejected&done=1&msg="
             . urlencode('Konfirmasi ditolak.'));
        exit;
    }
}

// =========================================================
// Render: tabel perbandingan lama vs baru
// =========================================================
$old_editor_nama  = $editor['nama']  ?? '';
$old_editor_email = $editor['email'] ?? '';
$old_editor_hp    = $editor['no_hp'] ?? '';

$compare = [
    ['Nama Jurnal',         $jurnal['nama_jurnal'],          $k['nama_jurnal']],
    ['URL Jurnal / Arsip',  $jurnal['url_archive'],          ($k['link_arsip'] ?: $k['url_jurnal'])],
    ['Unit Kerja',          $jurnal['unit_kerja'],           $k['unit_kerja']],
    ['Volume / Tahun',      $jurnal['volume_per_tahun'],     $k['volume_per_tahun']],
    ['APC',                 $jurnal['apc'],                  $k['apc']],
    ['P-ISSN',              $jurnal['p_issn'],               $k['p_issn']],
    ['E-ISSN',              $jurnal['e_issn'],               $k['e_issn']],
    ['Akreditasi',          $jurnal['akreditasi_peringkat'], $k['akreditasi']],
    ['Scopus',              ((int)$jurnal['is_scopus']?'Ya':'Tidak'), ((int)$k['is_scopus']?'Ya':'Tidak')],
    ['URL SINTA',           $jurnal['link_sinta'],           $k['link_sinta']],
    ['Link Google Scholar', $jurnal['link_gscholar'],        $k['link_gscholar']],
    ['Link Garuda',         $jurnal['link_garuda'],          $k['link_garuda']],
    ['Link Editorial Team', $jurnal['link_editor'],          $k['link_editor']],
    ['Ketua Editor',        $old_editor_nama,                $k['editor_nama']],
    ['Email Editor',        $old_editor_email,               $k['editor_email']],
    ['No. HP Editor',       $old_editor_hp,                  $k['editor_no_hp']],
];
?>
<div class="page-head">
  <div>
    <h1>Tinjau Konfirmasi</h1>
    <div class="muted small"><?= h($k['nama_jurnal']) ?> &middot;
      dikirim <?= h($k['submitted_at']) ?> &middot; IP <?= h($k['submit_ip']) ?></div>
  </div>
  <a href="konfirmasi_admin.php?st=<?= h($k['status']) ?>" class="btn">&larr; Daftar Konfirmasi</a>
</div>

<?php
$st = $k['status'];
$stcls = ['pending'=>'partial','approved'=>'success','rejected'=>'failed'][$st] ?? 'partial';
?>
<p>Status: <span class="badge badge-<?= $stcls ?>"><?= h($st) ?></span>
<?php if ($st !== 'pending'): ?>
  &middot; ditinjau oleh <?= h($k['reviewed_by']) ?> pada <?= h($k['reviewed_at']) ?>
  <?php if ($k['admin_note']): ?><br><span class="muted small">Catatan admin: <?= h($k['admin_note']) ?></span><?php endif; ?>
<?php endif; ?>
</p>

<div class="table-wrap">
<table class="table">
  <thead>
    <tr><th>Field</th><th>Data Saat Ini</th><th>Data Konfirmasi Editor</th></tr>
  </thead>
  <tbody>
  <?php foreach ($compare as [$label, $old, $new]):
    $old = trim((string)$old); $new = trim((string)$new);
    $changed = ($old !== $new);
  ?>
    <tr<?= $changed ? ' style="background:#fff8e6"' : '' ?>>
      <td><strong><?= h($label) ?></strong></td>
      <td class="small muted"><?= $old !== '' ? h($old) : '—' ?></td>
      <td class="small">
        <?= $new !== '' ? h($new) : '—' ?>
        <?php if ($changed): ?>
          <span class="badge badge-partial" style="margin-left:6px">berubah</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($k['catatan_editor']): ?>
  <div class="card" style="margin-top:14px">
    <h3><span class="ico">&#128221;</span> Catatan dari Editor</h3>
    <p><?= nl2br(h($k['catatan_editor'])) ?></p>
  </div>
<?php endif; ?>

<?php if ($st === 'pending'): ?>
  <form method="post" style="margin-top:18px">
    <?= csrf_field() ?>
    <label style="display:block;margin-bottom:10px;font-weight:600">
      Catatan Admin (opsional)
      <input type="text" name="admin_note" maxlength="255"
             style="width:100%;margin-top:5px;padding:9px 11px;border:1px solid #cdd5e0;border-radius:8px"
             placeholder="Alasan / catatan peninjauan…">
    </label>
    <div style="display:flex;gap:10px">
      <button type="submit" name="act" value="approve" class="btn btn-primary"
              onclick="return confirm('Setujui konfirmasi ini? Data jurnal & editor akan diperbarui.')">
        ✓ Setujui &amp; Terapkan
      </button>
      <button type="submit" name="act" value="reject" class="btn btn-danger"
              onclick="return confirm('Tolak konfirmasi ini?')">
        ✕ Tolak
      </button>
    </div>
    <p class="muted small" style="margin-top:10px">
      Menyetujui akan menyalin data konfirmasi ke tabel <code>jurnals</code> dan
      <code>editor</code>. Fitur Crawl &amp; Scan Judol tetap berjalan normal
      karena <code>jurnal_id</code> tidak berubah.
    </p>
  </form>
<?php endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
