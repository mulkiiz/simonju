<?php
require_once __DIR__ . '/../includes/auth.php';
require_jurnal();
require_once __DIR__ . '/../lib/doi.php';

$jid = current_jurnal_id();
$j   = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jid]);

// =========================================================
// POST (Post-Redirect-Get supaya refresh tidak resubmit/duplikat)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? 'upload';
    $f = ['up_msg'=>'','up_err'=>'','sm_msg'=>'','sm_err'=>'','rq_msg'=>'','rq_err'=>''];

    if ($act === 'sample') {
        $sample = trim($_POST['doi_sample'] ?? '');
        $sample = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $sample);
        $title = ($sample !== '') ? doi_locked_title_from_doi($sample) : '';
        $valid = ($title !== '') ? 1 : 0;
        exec_q("UPDATE jurnals SET doi_sample=?, doi_sample_valid=?, crossref_title=IF(?=1,?,crossref_title) WHERE id=?",
            'siisi', [$sample, $valid, $valid, $title, $jid]);
        if ($sample === '')      $f['sm_err'] = 'DOI contoh kosong.';
        elseif ($valid)          $f['sm_msg'] = '✅ DOI valid & aktif. Jurnal terdaftar Crossref: "' . $title . '". Unggah XML kini aktif.';
        else                     $f['sm_err'] = '❌ DOI belum aktif / tidak ditemukan di Crossref. Pakai DOI artikel volume lama yang sudah terbit.';

    } elseif ($act === 'reset_sample') {
        exec_q("UPDATE jurnals SET doi_sample=NULL, doi_sample_valid=0 WHERE id=?", 'i', [$jid]);
        $f['sm_msg'] = 'Contoh DOI direset. Silakan isi DOI aktif lain.';

    } elseif ($act === 'delete_request') {
        $rid = (int)($_POST['request_id'] ?? 0);
        // hanya boleh hapus milik sendiri & masih status uploaded
        $own = fetch_one("SELECT id FROM doi_request WHERE id=? AND jurnal_id=? AND status='uploaded'", 'ii', [$rid, $jid]);
        if ($own) {
            exec_q("DELETE FROM doi_article WHERE request_id=?", 'i', [$rid]);
            exec_q("DELETE FROM doi_request WHERE id=?", 'i', [$rid]);
            $f['rq_msg'] = 'Usulan dihapus.';
        } else { $f['rq_err'] = 'Usulan tidak bisa dihapus (sudah diproses admin).'; }

    } elseif ($act === 'upload_revisi') {
        $rid = (int)($_POST['request_id'] ?? 0);
        $own = fetch_one("SELECT id FROM doi_request WHERE id=? AND jurnal_id=? AND status='revisi'", 'ii', [$rid, $jid]);
        if (!$own) { $f['rq_err'] = 'Usulan tidak menunggu revisi.'; }
        elseif (empty($_FILES['xml']) || ($_FILES['xml']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $f['rq_err'] = 'File revisi wajib diunggah.'; }
        else {
            $xml = file_get_contents($_FILES['xml']['tmp_name']);
            $p = doi_parse_xml($xml);
            if (empty($p['articles'])) { $f['rq_err'] = 'XML revisi tidak dikenali.'; }
            else {
                exec_q("UPDATE doi_request SET xml_original=?, full_title_xml=?, issn_xml=?, n_articles=?, n_active=0, name_mismatch=0, status='uploaded' WHERE id=?",
                    'sssii', [$xml, $p['full_title'], $p['issn'], count($p['articles']), $rid]);
                exec_q("DELETE FROM doi_article WHERE request_id=?", 'i', [$rid]);
                foreach ($p['articles'] as $a)
                    exec_q("INSERT INTO doi_article (request_id, jurnal_id, judul, doi) VALUES (?,?,?,?)", 'iiss', [$rid, $jid, mb_substr($a['title'],0,500), $a['doi']]);
                $f['rq_msg'] = 'Revisi XML terunggah (' . count($p['articles']) . ' DOI). Menunggu review ulang.';
            }
        }

    } else { // upload
        if (empty($j['doi_sample_valid'])) {
            $f['up_err'] = 'Isi & validasi "Contoh DOI aktif" dulu sebelum mengunggah XML.';
        } else {
            $ket = trim($_POST['keterangan'] ?? '');
            if (empty($_FILES['xml']) || ($_FILES['xml']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $f['up_err'] = 'File XML wajib diunggah.';
            } elseif (($_FILES['xml']['size'] ?? 0) > 5 * 1024 * 1024) {
                $f['up_err'] = 'Ukuran file maksimal 5 MB.';
            } else {
                $xml = file_get_contents($_FILES['xml']['tmp_name']);
                $p   = doi_parse_xml($xml);
                if (empty($p['articles'])) {
                    $f['up_err'] = 'XML tidak dikenali / tidak ada DOI artikel.';
                } else {
                    $r = exec_q(
                        "INSERT INTO doi_request (jurnal_id, terbitan_label, jenis, status, xml_original, full_title_xml, issn_xml, n_articles)
                         VALUES (?,?,'terkini','uploaded',?,?,?,?)",
                        'issssi', [$jid, $ket, $xml, $p['full_title'], $p['issn'], count($p['articles'])]
                    );
                    $rid = (int)($r['insert_id'] ?? 0);
                    if ($rid > 0) {
                        foreach ($p['articles'] as $a)
                            exec_q("INSERT INTO doi_article (request_id, jurnal_id, judul, doi) VALUES (?,?,?,?)", 'iiss', [$rid, $jid, mb_substr($a['title'],0,500), $a['doi']]);
                        $f['up_msg'] = 'Berhasil unggah ' . count($p['articles']) . ' DOI. Menunggu review admin SIMONJU.';
                    } else { $f['up_err'] = 'Gagal menyimpan usulan.'; }
                }
            }
        }
    }

    $_SESSION['doi_flash'] = $f;
    header('Location: doi.php');
    exit;
}

// GET: ambil flash
$f = $_SESSION['doi_flash'] ?? [];
unset($_SESSION['doi_flash']);
$up_msg = $f['up_msg'] ?? ''; $up_err = $f['up_err'] ?? '';
$sm_msg = $f['sm_msg'] ?? ''; $sm_err = $f['sm_err'] ?? '';
$rq_msg = $f['rq_msg'] ?? ''; $rq_err = $f['rq_err'] ?? '';

$valid_sample = !empty($j['doi_sample_valid']);
$requests = fetch_all("SELECT * FROM doi_request WHERE jurnal_id=? ORDER BY created_at DESC", 'i', [$jid]);
$st_badge = function($s) {
    $map = ['uploaded'=>'partial','reviewed'=>'partial','revisi'=>'failed','fixed'=>'partial',
            'deposited'=>'partial','done'=>'success','failed'=>'failed'];
    return $map[$s] ?? 'partial';
};
$st_label = function($s) {
    $map = ['uploaded'=>'Menunggu review','reviewed'=>'Sedang diproses','revisi'=>'Perlu revisi',
            'fixed'=>'Sedang diproses','deposited'=>'Diproses (belum aktif)','done'=>'Aktif','failed'=>'Gagal'];
    return $map[$s] ?? $s;
};

$page_title = 'Aktivasi DOI';
require_once __DIR__ . '/../includes/header_jurnal.php';
?>
<div class="page-head"><h1>🔗 Aktivasi DOI</h1></div>

<section class="card-grid" style="grid-template-columns:repeat(2,1fr)">
  <!-- KIRI: contoh DOI aktif (prasyarat) -->
  <div class="card" style="padding:20px">
    <h3>1️⃣ Unggah contoh DOI aktif</h3>
    <p class="muted small" style="margin:0 0 12px">
      Isi <strong>1 DOI</strong> artikel dari volume sebelumnya yang sudah terbit &amp; aktif.
      Wajib valid sebelum bisa unggah XML.
    </p>
    <?php if ($sm_msg): ?><div class="alert alert-info"><?= h($sm_msg) ?></div><?php endif; ?>
    <?php if ($sm_err): ?><div class="alert alert-error"><?= h($sm_err) ?></div><?php endif; ?>
    <?php if ($valid_sample): ?>
      <p class="small">Status: <span class="badge badge-success">valid</span>
        <a href="https://doi.org/<?= h($j['doi_sample']) ?>" target="_blank" rel="noopener"><?= h($j['doi_sample']) ?></a></p>
      <p class="small"><strong>DOI contoh:</strong> <span class="mono"><?= h($j['doi_sample']) ?></span> <span class="muted">(terkunci)</span></p>
      <form method="post" style="margin-top:6px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="reset_sample">
        <button type="submit" class="btn btn-sm" onclick="return confirm('Reset contoh DOI? Unggah XML akan terkunci sampai isi DOI valid lagi.')">🔁 Reset / ganti contoh DOI</button>
      </form>
    <?php else: ?>
      <?php if (!empty($j['doi_sample'])): ?>
        <p class="small">Status: <span class="badge badge-failed">belum valid</span></p>
      <?php endif; ?>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="sample">
        <label>DOI contoh
          <input type="text" name="doi_sample" value="<?= h($j['doi_sample'] ?? '') ?>" placeholder="10.xxxxx/xxxx atau https://doi.org/...">
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">💾 Simpan &amp; Validasi Contoh DOI</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- KANAN: unggah XML (terkunci jika sample belum valid) -->
  <div class="card" style="padding:20px">
    <h3>2️⃣ Unggah XML DOI yang akan diaktivasi</h3>
    <p class="muted small" style="margin:0 0 12px">Export Crossref dari OJS. Boleh lebih dari satu file.</p>
    <?php if ($up_msg): ?><div class="alert alert-info"><?= h($up_msg) ?></div><?php endif; ?>
    <?php if ($up_err): ?><div class="alert alert-error"><?= h($up_err) ?></div><?php endif; ?>
    <?php if (!$valid_sample): ?>
      <div class="alert alert-error">🔒 Terkunci. Isi <strong>Contoh DOI aktif</strong> yang valid (kiri) dulu.</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="upload">
      <fieldset <?= $valid_sample ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;min-width:0">
        <label>Keterangan <span class="muted small">(isi dgn vol terbitan atau keterangan lain)</span>
          <input type="text" name="keterangan" placeholder="contoh: Vol 21 No 1 (2025)">
        </label>
        <label>File XML
          <input type="file" name="xml" accept=".xml" <?= $valid_sample ? 'required' : '' ?>>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">⬆️ Unggah</button>
        </div>
      </fieldset>
    </form>
  </div>
</section>

<h2 style="margin-top:22px">Daftar Usulan DOI</h2>
<?php if ($rq_msg): ?><div class="alert alert-info"><?= h($rq_msg) ?></div><?php endif; ?>
<?php if ($rq_err): ?><div class="alert alert-error"><?= h($rq_err) ?></div><?php endif; ?>
<?php if (empty($requests)): ?>
  <p class="muted">Belum ada usulan DOI.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Tanggal</th><th>Keterangan</th><th class="num">DOI</th><th class="num">Aktif</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($requests as $r): ?>
    <tr>
      <td class="small"><?= h($r['created_at']) ?></td>
      <td><?= h($r['terbitan_label'] ?: '—') ?></td>
      <td class="num"><?= (int)$r['n_articles'] ?></td>
      <td class="num"><strong><?= (int)$r['n_active'] ?></strong></td>
      <td><span class="badge badge-<?= $st_badge($r['status']) ?>"><?= h($st_label($r['status'])) ?></span></td>
      <td style="white-space:nowrap">
        <?php if ($r['status'] === 'revisi'): ?>
          <form method="post" enctype="multipart/form-data" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="upload_revisi">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="file" name="xml" accept=".xml" required style="font-size:11px;width:140px">
            <button type="submit" class="btn btn-sm btn-edit">⬆️ Unggah Revisi</button>
          </form>
        <?php elseif ($r['status'] === 'uploaded'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Hapus usulan ini?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="delete_request">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">🗑️ Hapus</button>
          </form>
        <?php else: ?>
          <span class="muted small">—</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
