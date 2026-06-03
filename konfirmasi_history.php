<?php
/**
 * konfirmasi_history.php?jurnal=ID
 * Halaman admin: RIWAYAT perubahan data konfirmasi untuk satu jurnal.
 *
 * Menampilkan seluruh submission konfirmasi jurnal tsb (terbaru → terlama),
 * dengan penanda field yang berubah antar submission. Read-only — proses
 * approve/tolak tetap dilakukan di konfirmasi_review.php.
 *
 * Terintegrasi ke dashboard SIMONJU (pakai _header.php → wajib login).
 */
$page_title = 'Riwayat Konfirmasi';
require_once __DIR__ . '/_header.php';

$jid    = (int)($_GET['jurnal'] ?? 0);
$jurnal = $jid ? fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jid]) : null;
if (!$jurnal) {
    echo '<div class="empty"><p>Jurnal tidak ditemukan. '
       . '<a href="konfirmasi_admin.php">Kembali ke daftar konfirmasi</a>.</p></div>';
    require_once __DIR__ . '/_footer.php';
    exit;
}

// Seluruh konfirmasi jurnal ini, terbaru dulu.
$riwayat = fetch_all(
    "SELECT * FROM konfirmasi WHERE jurnal_id=? ORDER BY id DESC",
    'i', [$jid]
);
$total_konf = count($riwayat);

// Nomor urut submit (id menaik = urutan kronologis).
$seq_of = [];
$tmp = 0;
foreach (array_reverse($riwayat) as $h) { $tmp++; $seq_of[(int)$h['id']] = $tmp; }

// Field yang ditampilkan & dibandingkan antar submission.
$FIELDS = [
    'nama_jurnal'      => 'Nama Jurnal',
    'url_jurnal'       => 'URL Jurnal',
    'unit_kerja'       => 'Unit Kerja',
    'volume_per_tahun' => 'Volume / Tahun',
    'apc'              => 'APC',
    'p_issn'           => 'P-ISSN',
    'e_issn'           => 'E-ISSN',
    'akreditasi'       => 'Akreditasi',
    'is_scopus'        => 'Scopus',
    'link_sinta'       => 'Link SINTA',
    'link_garuda'      => 'Link Garuda',
    'link_gscholar'    => 'Link Google Scholar',
    'link_editor'      => 'Link Editorial Team',
    'link_arsip'       => 'Link Arsip',
    'editor_nama'      => 'Ketua Editor',
    'editor_email'     => 'Email Editor',
    'editor_no_hp'     => 'No. HP Editor',
];

function kh_show($val, $is_url = false, $is_bool = false) {
    if ($is_bool) return !empty($val) ? 'Ya' : 'Tidak';
    $val = trim((string)$val);
    if ($val === '') return '<span class="muted">— kosong —</span>';
    if ($is_url) {
        return '<a href="' . h($val) . '" target="_blank" rel="noopener">'
             . h($val) . '</a>';
    }
    return h($val);
}
?>
<style>
  .kh-item{border:1px solid #e3e8ef;border-radius:11px;margin-bottom:12px;
           overflow:hidden}
  .kh-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;
           padding:11px 14px;background:#f7f9fc;cursor:pointer}
  .kh-head .rev{font-size:.7rem;font-weight:700;background:#e6effb;
           color:#1c4f9c;padding:2px 9px;border-radius:20px}
  .kh-head .rev.first{background:#eef1f5;color:#6b7785}
  .kh-head .tm{font-size:.8rem;color:#8a94a3;margin-left:auto}
  .kh-body{padding:12px 14px;display:none}
  .kh-body.open{display:block}
  .kh-body table{width:100%;border-collapse:collapse}
  .kh-body th,.kh-body td{text-align:left;padding:6px 9px;
        border-bottom:1px solid #f0f2f5;font-size:.84rem;vertical-align:top}
  .kh-body th{width:36%;color:#8a94a3;font-weight:600}
  .kh-body td{color:#1c2b46;word-break:break-word}
  .kh-changed{background:#fffaf0}
  .kh-changed td{font-weight:600}
  .badge-mini{font-size:.68rem;padding:1px 8px;border-radius:20px;font-weight:700}
  .kh-toolbar{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}
</style>

<div class="page-head">
  <h1>Riwayat Konfirmasi Jurnal</h1>
  <a href="konfirmasi_admin.php" class="btn">&larr; Daftar Konfirmasi</a>
</div>

<div class="alert alert-info">
  <strong><?= h($jurnal['nama_jurnal']) ?></strong>
  &middot; <?= $total_konf ?> submission konfirmasi
  &middot; Status jurnal saat ini:
  <span class="badge badge-<?=
      ['terkonfirmasi'=>'success','pending'=>'partial','belum'=>'failed'][$jurnal['konfirmasi_status'] ?? 'belum'] ?? 'partial'
  ?>"><?= h($jurnal['konfirmasi_status'] ?: 'belum') ?></span>
</div>

<?php if (empty($riwayat)): ?>
  <div class="empty"><p>Jurnal ini belum pernah mengirim konfirmasi.</p></div>
<?php else: ?>

  <p class="small muted" style="margin:0 0 12px">
    Submission terbaru di atas. Klik tiap baris untuk membuka/menutup detail.
    Sel berwarna <span style="background:#fffaf0;padding:1px 5px;border:1px solid #f0e2c0">kuning</span>
    menandakan nilai yang berbeda dari submission sebelumnya (lebih lama).
  </p>

  <div class="kh-toolbar">
    <button type="button" class="btn btn-sm" onclick="khAll(true)">Buka Semua</button>
    <button type="button" class="btn btn-sm" onclick="khAll(false)">Tutup Semua</button>
  </div>

  <?php
  for ($i = 0; $i < count($riwayat); $i++):
      $h    = $riwayat[$i];
      $hid  = (int)$h['id'];
      $prev = $riwayat[$i + 1] ?? null;          // submission lebih lama
      $hseq = $seq_of[$hid] ?? 1;
      $hst  = $h['status'];
      $hcls = ['pending'=>'partial','approved'=>'success','rejected'=>'failed'][$hst] ?? 'partial';
      $open = ($i === 0) ? 'open' : '';          // submission terbaru terbuka
  ?>
    <div class="kh-item">
      <div class="kh-head" onclick="khToggle(<?= $hid ?>)">
        <?php if ($hseq <= 1): ?>
          <span class="rev first">Konfirmasi Awal</span>
        <?php else: ?>
          <span class="rev">Revisi ke-<?= $hseq - 1 ?></span>
        <?php endif; ?>
        <span class="badge badge-<?= $hcls ?> badge-mini"><?= h($hst) ?></span>
        <a href="konfirmasi_review.php?id=<?= $hid ?>"
           class="btn btn-sm btn-primary" onclick="event.stopPropagation()">
          Tinjau
        </a>
        <span class="tm">🕒 <?= h($h['submitted_at']) ?></span>
      </div>
      <div class="kh-body <?= $open ?>" id="khb-<?= $hid ?>">
        <table>
          <?php foreach ($FIELDS as $fk => $flabel):
            $is_url  = (strpos($fk, 'link_') === 0 || $fk === 'url_jurnal');
            $is_bool = ($fk === 'is_scopus');
            $cur_v   = trim((string)($h[$fk] ?? ''));
            $prev_v  = $prev ? trim((string)($prev[$fk] ?? '')) : null;
            $changed = ($prev !== null && $cur_v !== $prev_v);
          ?>
            <tr class="<?= $changed ? 'kh-changed' : '' ?>">
              <th><?= h($flabel) ?></th>
              <td>
                <?= kh_show($h[$fk] ?? '', $is_url, $is_bool) ?>
                <?php if ($changed): ?>
                  <span class="muted small" style="display:block">
                    sebelumnya: <?= $prev_v === '' ? '— kosong —' : h($prev_v) ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!empty(trim((string)($h['catatan_editor'] ?? '')))): ?>
            <tr><th>Catatan Editor</th><td><?= nl2br(h($h['catatan_editor'])) ?></td></tr>
          <?php endif; ?>
          <?php if (!empty($h['submit_ip'])): ?>
            <tr><th>IP Pengirim</th><td><?= h($h['submit_ip']) ?></td></tr>
          <?php endif; ?>
          <?php if (!empty($h['reviewed_at'])): ?>
            <tr><th>Hasil Review</th><td>
              <strong><?= h($hst) ?></strong>
              oleh <?= h($h['reviewed_by'] ?: '—') ?>,
              <?= h($h['reviewed_at']) ?>
              <?php if (!empty($h['admin_note'])): ?>
                <br><span class="muted">Catatan admin: <?= h($h['admin_note']) ?></span>
              <?php endif; ?>
            </td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>
  <?php endfor; ?>

<?php endif; ?>

<script>
  function khToggle(id){
    var el = document.getElementById('khb-' + id);
    if (el) el.classList.toggle('open');
  }
  function khAll(open){
    document.querySelectorAll('.kh-body').forEach(function(el){
      el.classList.toggle('open', open);
    });
  }
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
