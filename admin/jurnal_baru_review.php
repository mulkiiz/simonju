<?php
/**
 * jurnal_baru_review.php
 * Admin meninjau 1 pengajuan jurnal baru.
 *
 * ALUR APPROVE = UPSERT (disederhanakan):
 *  - Jika jurnal SUDAH ADA di tabel `jurnals` (cocok berdasarkan url_archive,
 *    lalu fallback nama_jurnal) -> data pengajuan MENIMPA record itu (UPDATE).
 *  - Jika BELUM ADA -> INSERT record baru (+ token konfirmasi).
 *  Hasil akhir: tabel `jurnals` selalu berisi 1 jurnal unik per nama/URL,
 *  tidak ada duplikasi, dan approve tidak akan gagal karena bentrok UNIQUE.
 *
 * Reject -> status 'rejected', tidak ada perubahan di `jurnals`.
 */
$page_title = 'Tinjau Jurnal Baru';
require_once __DIR__ . '/../includes/header_admin.php';

$id = (int)($_GET['id'] ?? 0);
$jb = fetch_one("SELECT * FROM jurnal_baru WHERE id=?", 'i', [$id]);
if (!$jb) { echo '<p>Pengajuan tidak ditemukan.</p>'; require_once __DIR__.'/../includes/footer.php'; exit; }

/**
 * Cari jurnal yang sudah ada yang dianggap "sama" dengan pengajuan ini.
 * Prioritas: url_archive (UNIQUE & identitas teknis) -> nama_jurnal.
 * Return row jurnals atau null.
 */
function cari_jurnal_existing($url_archive, $nama_jurnal) {
    $url_archive = trim((string)$url_archive);
    $nama_jurnal = trim((string)$nama_jurnal);
    if ($url_archive !== '') {
        $j = fetch_one("SELECT * FROM jurnals WHERE url_archive=? LIMIT 1",
                       's', [$url_archive]);
        if ($j) return $j;
    }
    if ($nama_jurnal !== '') {
        $j = fetch_one("SELECT * FROM jurnals WHERE nama_jurnal=? LIMIT 1",
                       's', [$nama_jurnal]);
        if ($j) return $j;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act  = $_POST['act'] ?? '';
    $note = trim($_POST['admin_note'] ?? '');
    $by   = $_SESSION['uname'] ?? 'admin';

    if ($act === 'approve' && $jb['status'] === 'pending') {
        // URL yang akan masuk / menimpa kolom jurnals.url_archive
        $url_archive = trim((string)($jb['link_arsip'] ?: $jb['url_jurnal']));

        // Jenis akreditasi
        $is_scopus = (int)$jb['is_scopus'];
        $peringkat = trim((string)$jb['akreditasi']);
        $jenis = $is_scopus ? 'scopus' : ($peringkat !== '' ? 'sinta' : 'belum');

        if ($url_archive === '') {
            $err = 'URL arsip / URL jurnal kosong — tidak bisa disimpan '
                 . '(kolom url_archive wajib diisi).';
        } else {
            // Apakah jurnal ini sudah ada? -> tentukan UPDATE atau INSERT.
            $existing = cari_jurnal_existing($url_archive, $jb['nama_jurnal']);

            if ($existing) {
                /* ---------- JURNAL SUDAH ADA: TIMPA (UPDATE) ---------- */
                $jid = (int)$existing['id'];

                $r = exec_q(
                    "UPDATE jurnals SET
                        nama_jurnal=?, url_archive=?, unit_kerja=?,
                        volume_per_tahun=?, apc=?, p_issn=?, e_issn=?,
                        akreditasi_jenis=?, akreditasi_peringkat=?, is_scopus=?,
                        akreditasi_url=?, link_gscholar=?, link_garuda=?,
                        link_editor=?, link_sinta=?,
                        konfirmasi_status='terkonfirmasi', konfirmasi_at=NOW()
                      WHERE id=?",
                    'sssssssssisssssi',
                    [$jb['nama_jurnal'], $url_archive, $jb['unit_kerja'],
                     $jb['volume_per_tahun'], $jb['apc'], $jb['p_issn'], $jb['e_issn'],
                     $jenis, $peringkat, $is_scopus, $jb['link_sinta'],
                     $jb['link_gscholar'], $jb['link_garuda'], $jb['link_editor'],
                     $jb['link_sinta'], $jid]
                );
                $ok = is_array($r);   // UPDATE: affected bisa 0 bila data sama persis

                if (!$ok) {
                    $mysql_err = db()->error;
                    $err = 'Gagal memperbarui data jurnal.'
                         . ($mysql_err !== '' ? ' Pesan MySQL: ' . $mysql_err : '');
                } else {
                    // Upsert editor untuk jurnal ini
                    $ed = fetch_one("SELECT id FROM editor WHERE jurnal_id=?", 'i', [$jid]);
                    if ($ed) {
                        exec_q("UPDATE editor SET nama=?, email=?, no_hp=? WHERE jurnal_id=?",
                               'sssi', [$jb['editor_nama'], $jb['editor_email'],
                                        $jb['editor_no_hp'], $jid]);
                    } else {
                        exec_q("INSERT INTO editor (jurnal_id, nama, email, no_hp) VALUES (?,?,?,?)",
                               'isss', [$jid, $jb['editor_nama'], $jb['editor_email'],
                                        $jb['editor_no_hp']]);
                    }
                    exec_q(
                        "UPDATE jurnal_baru SET status='approved', reviewed_at=NOW(),
                         reviewed_by=?, admin_note=?, jurnal_id=? WHERE id=?",
                        'ssii', [$by, $note, $jid, $id]
                    );
                    header("Location: jurnal_baru_admin.php?st=approved&done=1&msg="
                         . urlencode('Pengajuan disetujui — data jurnal "'
                           . $jb['nama_jurnal'] . '" diperbarui (menimpa data lama).'));
                    exit;
                }

            } else {
                /* ---------- JURNAL BARU: INSERT ---------- */
                // Token unik 16 hex
                $token = '';
                for ($try = 0; $try < 5; $try++) {
                    $cand = bin2hex(random_bytes(8));
                    if (!fetch_one("SELECT id FROM jurnals WHERE konfirmasi_token=? LIMIT 1",
                                   's', [$cand])) { $token = $cand; break; }
                }
                if ($token === '') {
                    $err = 'Gagal membuat token konfirmasi unik. Coba lagi.';
                } else {
                    $r = exec_q(
                        "INSERT INTO jurnals
                           (nama_jurnal, url_archive, unit_kerja, volume_per_tahun, apc,
                            p_issn, e_issn, akreditasi_jenis, akreditasi_peringkat, is_scopus,
                            akreditasi_url, link_gscholar, link_garuda, link_editor, link_sinta,
                            konfirmasi_token, konfirmasi_status, konfirmasi_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'terkonfirmasi',NOW())",
                        'sssssssssissssss',
                        [$jb['nama_jurnal'], $url_archive, $jb['unit_kerja'],
                         $jb['volume_per_tahun'], $jb['apc'], $jb['p_issn'], $jb['e_issn'],
                         $jenis, $peringkat, $is_scopus, $jb['link_sinta'],
                         $jb['link_gscholar'], $jb['link_garuda'], $jb['link_editor'],
                         $jb['link_sinta'], $token]
                    );
                    $ok = is_array($r)
                          && (int)($r['affected'] ?? 0) === 1
                          && (int)($r['insert_id'] ?? 0) > 0;

                    if (!$ok) {
                        $mysql_err = db()->error;
                        $err = 'Gagal menyimpan jurnal baru.'
                             . ($mysql_err !== '' ? ' Pesan MySQL: ' . $mysql_err : '');
                    } else {
                        $jid = (int)$r['insert_id'];
                        exec_q("INSERT INTO editor (jurnal_id, nama, email, no_hp) VALUES (?,?,?,?)",
                               'isss', [$jid, $jb['editor_nama'], $jb['editor_email'],
                                        $jb['editor_no_hp']]);
                        exec_q(
                            "UPDATE jurnal_baru SET status='approved', reviewed_at=NOW(),
                             reviewed_by=?, admin_note=?, jurnal_id=? WHERE id=?",
                            'ssii', [$by, $note, $jid, $id]
                        );
                        header("Location: jurnal_baru_admin.php?st=approved&done=1&msg="
                             . urlencode('Jurnal baru "' . $jb['nama_jurnal']
                               . '" disetujui & ditambahkan ke daftar.'));
                        exit;
                    }
                }
            }
        }
    }

    if ($act === 'reject' && $jb['status'] === 'pending') {
        exec_q(
            "UPDATE jurnal_baru SET status='rejected', reviewed_at=NOW(),
             reviewed_by=?, admin_note=? WHERE id=?",
            'ssi', [$by, $note, $id]
        );
        header("Location: jurnal_baru_admin.php?st=rejected&done=1&msg="
             . urlencode('Pengajuan jurnal baru ditolak.'));
        exit;
    }
}

$fields = [
    ['Nama Jurnal',         $jb['nama_jurnal']],
    ['URL Jurnal',          $jb['url_jurnal']],
    ['Unit Kerja',          $jb['unit_kerja']],
    ['Volume / Tahun',      $jb['volume_per_tahun']],
    ['APC',                 $jb['apc']],
    ['P-ISSN',              $jb['p_issn']],
    ['E-ISSN',              $jb['e_issn']],
    ['Akreditasi',          $jb['akreditasi']],
    ['Scopus',              ((int)$jb['is_scopus'] ? 'Ya' : 'Tidak')],
    ['URL SINTA',           $jb['link_sinta']],
    ['Link Arsip',          $jb['link_arsip']],
    ['Link Google Scholar', $jb['link_gscholar']],
    ['Link Garuda',         $jb['link_garuda']],
    ['Link Editorial Team', $jb['link_editor']],
    ['Ketua Editor',        $jb['editor_nama']],
    ['Email Editor',        $jb['editor_email']],
    ['No. HP Editor',       $jb['editor_no_hp']],
];
$st = $jb['status'];
$stcls = ['pending'=>'partial','approved'=>'success','rejected'=>'failed'][$st] ?? 'partial';

// Untuk info di layar: apakah pengajuan ini akan menimpa atau menambah baru?
$url_archive_preview = trim((string)($jb['link_arsip'] ?: $jb['url_jurnal']));
$existing_preview = ($st === 'pending')
    ? cari_jurnal_existing($url_archive_preview, $jb['nama_jurnal'])
    : null;
?>
<div class="page-head">
  <div>
    <h1>Tinjau Pengajuan Jurnal Baru</h1>
    <div class="muted small"><?= h($jb['nama_jurnal']) ?> &middot;
      diajukan <?= h($jb['submitted_at']) ?> &middot; IP <?= h($jb['submit_ip']) ?></div>
  </div>
  <a href="jurnal_baru_admin.php?st=<?= h($st) ?>" class="btn">&larr; Daftar Pengajuan</a>
</div>

<?php if (!empty($err)): ?>
  <div class="alert alert-error"><?= h($err) ?></div>
<?php endif; ?>

<p>Status: <span class="badge badge-<?= $stcls ?>"><?= h($st) ?></span>
<?php if ($st !== 'pending'): ?>
  &middot; ditinjau oleh <?= h($jb['reviewed_by']) ?> pada <?= h($jb['reviewed_at']) ?>
  <?php if ($jb['jurnal_id']): ?>
    &middot; <a href="jurnal_view.php?id=<?= (int)$jb['jurnal_id'] ?>">lihat jurnal &rarr;</a>
  <?php endif; ?>
  <?php if ($jb['admin_note']): ?><br><span class="muted small">Catatan admin: <?= h($jb['admin_note']) ?></span><?php endif; ?>
<?php endif; ?>
</p>

<?php if ($st === 'pending'): ?>
  <?php if ($existing_preview): ?>
    <div class="alert alert-info">
      ♻️ Jurnal ini <strong>sudah ada</strong> di daftar:
      <strong><?= h($existing_preview['nama_jurnal']) ?></strong>
      (id <?= (int)$existing_preview['id'] ?>).
      Jika disetujui, data pengajuan ini akan <strong>menimpa</strong> data
      jurnal tersebut — tidak membuat duplikat.
    </div>
  <?php else: ?>
    <div class="alert alert-info">
      ✨ Jurnal ini <strong>belum ada</strong> di daftar. Jika disetujui, akan
      <strong>ditambahkan</strong> sebagai jurnal baru beserta token konfirmasi.
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="table-wrap">
<table class="table">
  <thead><tr><th>Field</th><th>Data Diajukan</th></tr></thead>
  <tbody>
  <?php foreach ($fields as [$label, $val]): ?>
    <tr>
      <td><strong><?= h($label) ?></strong></td>
      <td class="small"><?= trim((string)$val) !== '' ? h($val) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  <tr>
    <td><strong>&rarr; Disimpan sebagai <code>url_archive</code></strong></td>
    <td class="small"><?= $url_archive_preview !== '' ? h($url_archive_preview) : '—' ?></td>
  </tr>
  </tbody>
</table>
</div>

<?php if ($jb['catatan_editor']): ?>
  <div class="card" style="margin-top:14px">
    <h3><span class="ico">&#128221;</span> Catatan dari Pengaju</h3>
    <p><?= nl2br(h($jb['catatan_editor'])) ?></p>
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
              onclick="return confirm('<?= $existing_preview
                  ? 'Setujui? Data jurnal yang sudah ada akan ditimpa dengan data pengajuan ini.'
                  : 'Setujui? Jurnal akan ditambahkan ke tabel jurnals beserta token konfirmasi.' ?>')">
        <?= $existing_preview ? '✓ Setujui &amp; Timpa Data' : '✓ Setujui &amp; Tambahkan' ?>
      </button>
      <button type="submit" name="act" value="reject" class="btn btn-danger"
              onclick="return confirm('Tolak pengajuan ini?')">
        ✕ Tolak
      </button>
    </div>
    <p class="muted small" style="margin-top:10px">
      Menyetujui akan menyimpan data ke tabel <code>jurnals</code> &amp;
      <code>editor</code>. Bila jurnal sudah ada, datanya diperbarui — tabel
      jurnals tetap berisi 1 record unik per jurnal.
    </p>
  </form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
