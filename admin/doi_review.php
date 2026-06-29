<?php
require_once __DIR__ . '/../includes/auth.php';
require_doi();
require_once __DIR__ . '/../lib/doi.php';

$jurnal_id = (int)($_GET['jurnal'] ?? 0);
$j = fetch_one("SELECT id, nama_jurnal, crossref_title, doi_sample, doi_sample_valid FROM jurnals WHERE id=?", 'i', [$jurnal_id]);

// POST -> proses -> redirect (PRG, supaya refresh tidak resubmit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $rid = (int)($_POST['request_id'] ?? 0);
    $flash = '';
    if (!$j) { header('Location: doi_requests.php'); exit; }

    if ($act === 'crosscek') {
        $locked = trim((string)$j['crossref_title']);
        if ($locked === '') {
            $flash = '⚠️ Jurnal belum mengisi/memvalidasi "Contoh DOI aktif". Nama terkunci belum ada.';
        } else {
            $req = fetch_one("SELECT full_title_xml FROM doi_request WHERE id=? AND jurnal_id=?", 'ii', [$rid, $jurnal_id]);
            if ($req) {
                $mis = (strcasecmp($locked, (string)$req['full_title_xml']) !== 0) ? 1 : 0;
                exec_q("UPDATE doi_request SET name_mismatch=?, status='reviewed' WHERE id=?", 'ii', [$mis, $rid]);
                $flash = $mis ? 'BELUM SESUAI — nama jurnal di XML beda dengan terkunci Crossref.'
                              : 'SESUAI — nama jurnal cocok.';
            }
        }

    } elseif ($act === 'minta_revisi') {
        exec_q("UPDATE doi_request SET status='revisi' WHERE id=? AND jurnal_id=?", 'ii', [$rid, $jurnal_id]);

    } elseif ($act === 'update_status') {
        $arts = fetch_all("SELECT id, doi FROM doi_article WHERE request_id=?", 'i', [$rid]);
        $active = 0;
        foreach ($arts as $a) {
            $ok = doi_is_active($a['doi']);
            exec_q("UPDATE doi_article SET crossref_active=?, checked_at=NOW() WHERE id=?", 'ii', [$ok?1:0, (int)$a['id']]);
            if ($ok) $active++;
        }
        $total = count($arts);
        $newStatus = ($total > 0 && $active === $total) ? 'done' : 'deposited';
        exec_q("UPDATE doi_request SET n_active=?, status=? WHERE id=?", 'isi', [$active, $newStatus, $rid]);
        $flash = "Update status: {$active}/{$total} DOI aktif di Crossref.";

    } elseif ($act === 'delete_request') {
        exec_q("DELETE FROM doi_article WHERE request_id=?", 'i', [$rid]);
        exec_q("DELETE FROM doi_request WHERE id=? AND jurnal_id=?", 'ii', [$rid, $jurnal_id]);
        $flash = 'Usulan dihapus.';

    } elseif ($act === 'proses') {
        // Skema 1: deposit langsung ke Crossref (web-service)
        $req = fetch_one("SELECT xml_original, xml_fixed, name_mismatch, status FROM doi_request WHERE id=? AND jurnal_id=?", 'ii', [$rid, $jurnal_id]);
        if (!$req) { $flash = 'Usulan tidak ditemukan.'; }
        elseif ($req['name_mismatch']) { $flash = 'Crosscek belum sesuai. Perbaiki nama/revisi dulu sebelum Proses.'; }
        else {
            $xml = $req['xml_fixed'] ?: $req['xml_original'];
            $res = doi_crossref_deposit($xml);
            if ($res['ok']) {
                exec_q("UPDATE doi_request SET status='deposited', admin_note=? WHERE id=?", 'si', [mb_substr($res['msg'],0,255), $rid]);
            }
            $flash = $res['msg'];
        }
    }

    $_SESSION['doi_rflash'] = $flash;
    header('Location: doi_review.php?jurnal=' . $jurnal_id . ($rid ? '&req=' . $rid : ''));
    exit;
}

// GET
$flash = $_SESSION['doi_rflash'] ?? '';
unset($_SESSION['doi_rflash']);

$allow_doi = true;
$page_title = 'Review DOI';
require_once __DIR__ . '/../includes/header_admin.php';
if (!$j) { echo '<p>Jurnal tidak ditemukan.</p>'; require_once __DIR__.'/../includes/footer.php'; exit; }

$requests = fetch_all("SELECT * FROM doi_request WHERE jurnal_id=? ORDER BY created_at DESC", 'i', [$jurnal_id]);
$locked = trim((string)$j['crossref_title']);

$sel = (int)($_GET['req'] ?? 0);
if (!$sel && $requests) $sel = (int)$requests[0]['id'];
$sel_req = null;
foreach ($requests as $rr) if ((int)$rr['id'] === $sel) $sel_req = $rr;
$articles = $sel ? fetch_all("SELECT * FROM doi_article WHERE request_id=? ORDER BY id ASC", 'i', [$sel]) : [];
?>
<div class="page-head">
  <h1>Review DOI — <?= h($j['nama_jurnal']) ?></h1>
  <a href="doi_requests.php" class="btn">&larr; Daftar</a>
</div>

<?php if ($flash): ?><div class="alert alert-info"><?= h($flash) ?></div><?php endif; ?>

<section class="card-grid" style="grid-template-columns:1fr 1.8fr;align-items:start">
  <!-- KIRI: Info Crossref (read-only) -->
  <div class="card" style="padding:16px">
    <h3>ℹ️ Info CROSSREF</h3>
    <p class="small" style="margin:4px 0"><strong>Contoh DOI aktif:</strong><br>
      <?= !empty($j['doi_sample']) ? '<a href="https://doi.org/'.h($j['doi_sample']).'" target="_blank" rel="noopener">'.h($j['doi_sample']).'</a>' : '<span class="muted">belum diisi jurnal</span>' ?>
      <?= !empty($j['doi_sample_valid']) ? ' <span class="badge badge-success">valid</span>' : '' ?>
    </p>
    <p class="small" style="margin:8px 0">
      <?= $locked !== '' ? '🔒 <strong>'.h($locked).'</strong>' : '<span class="muted">🔒 — (jurnal belum validasi contoh DOI)</span>' ?>
    </p>
    <p class="muted small" style="margin-top:8px">Read-only. Diisi otomatis dari validasi contoh DOI oleh operator jurnal.</p>
  </div>

  <!-- KANAN: Daftar Unggah XML -->
  <div class="card" style="padding:16px">
    <h3>📥 Daftar Unggah XML</h3>
    <?php if (empty($requests)): ?>
      <p class="muted">Belum ada unggahan.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Keterangan</th><th class="num">Aktif</th><th>Hasil Crosscek</th><th>Hasil Update</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $rr):
        $isSel    = ((int)$rr['id'] === $sel);
        $reviewed = in_array($rr['status'], ['reviewed','revisi','deposited','done'], true);
        $sesuai   = ($reviewed && !$rr['name_mismatch']);
      ?>
        <tr style="<?= $isSel ? 'background:#eef2f9' : '' ?>">
          <td>
            <a href="?jurnal=<?= $jurnal_id ?>&req=<?= (int)$rr['id'] ?>"><?= h($rr['terbitan_label'] ?: '#'.$rr['id']) ?></a>
          </td>
          <td class="num"><?= (int)$rr['n_active'] ?>/<?= (int)$rr['n_articles'] ?></td>
          <td>
            <?php if (!$reviewed): ?><span class="muted small">belum</span>
            <?php elseif ($rr['name_mismatch']): ?><span class="badge badge-failed">belum sesuai</span>
            <?php else: ?><span class="badge badge-success">sesuai</span><?php endif; ?>
            <?php if ($rr['status'] === 'revisi'): ?><br><span class="badge badge-partial" style="margin-top:3px">⏳ Revisi diminta</span><?php endif; ?>
          </td>
          <td>
            <?php if ($rr['status'] === 'done'): ?><span class="badge badge-success">Validated</span>
            <?php elseif ($rr['status'] === 'deposited'): ?><span class="badge badge-failed">Failed</span>
            <?php else: ?><span class="muted small">—</span><?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="act" value="crosscek"><input type="hidden" name="request_id" value="<?= (int)$rr['id'] ?>">
              <button class="btn btn-sm btn-scan" type="submit" title="Cek nama XML vs terkunci">🔎 Crosscek</button>
            </form>
            <a href="doi_download.php?id=<?= (int)$rr['id'] ?>&type=original" class="btn btn-sm" title="Unduh XML asli">📄 File Asli</a>
            <?php
              // snippet tombol
              $btn_revisi = '<form method="post" style="display:inline">'.csrf_field().'<input type="hidden" name="act" value="minta_revisi"><input type="hidden" name="request_id" value="'.(int)$rr['id'].'"><button class="btn btn-sm btn-edit" type="submit" title="Minta jurnal unggah revisi">✏️ Minta Revisi</button></form>';
              $btn_hapus  = '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus usulan ini?\')">'.csrf_field().'<input type="hidden" name="act" value="delete_request"><input type="hidden" name="request_id" value="'.(int)$rr['id'].'"><button class="btn btn-sm btn-danger" type="submit">🗑️ Hapus Usulan</button></form>';
              $btn_update = '<form method="post" style="display:inline">'.csrf_field().'<input type="hidden" name="act" value="update_status"><input type="hidden" name="request_id" value="'.(int)$rr['id'].'"><button class="btn btn-sm" type="submit" title="Cek status DOI ke Crossref (proses manual di luar sistem)">🔄 Update Status</button></form>';
              $btn_proses = '<form method="post" style="display:inline" onsubmit="return confirm(\'Proses DEPOSIT langsung ke Crossref sekarang? Aksi ini mendaftarkan DOI secara nyata.\')">'.csrf_field().'<input type="hidden" name="act" value="proses"><input type="hidden" name="request_id" value="'.(int)$rr['id'].'"><button class="btn btn-sm btn-primary" type="submit" title="Deposit XML langsung ke Crossref">🚀 Proses</button></form>';
            ?>
            <?php if ($rr['status'] === 'revisi'): ?>
            <?php elseif ($rr['status'] === 'deposited'): /* Failed: belum semua aktif */ ?>
              <?= $btn_update . ' ' . $btn_revisi . ' ' . $btn_hapus ?>
            <?php elseif ($rr['status'] === 'done'): ?>
            <?php elseif ($sesuai): ?>
              <?= $btn_proses . ' ' . $btn_update ?>
            <?php elseif ($reviewed && $rr['name_mismatch']): ?>
              <?= $btn_revisi ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($isSel && $reviewed && $rr['name_mismatch']): ?>
          <tr><td colspan="5">
            <div class="alert alert-error" style="margin:0;padding:8px 10px">
              ⚠️ <strong>Beda nama:</strong> XML "<em><?= h($rr['full_title_xml']) ?></em>" vs terkunci "<em><?= h($locked) ?></em>".
              <?php if ($rr['status'] === 'revisi'): ?><br>✏️ <strong>Revisi sudah diminta</strong> ke jurnal. Menunggu unggahan revisi XML.<?php endif; ?>
            </div>
          </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- Detail artikel -->
<div class="card" style="padding:16px;margin-top:16px">
  <h3>📄 Daftar DOI <?= $sel_req ? '— '.h($sel_req['terbitan_label'] ?: '#'.$sel_req['id']) : '' ?></h3>
  <?php if (empty($articles)): ?>
    <p class="muted">Pilih unggahan di atas untuk melihat daftar DOI.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>Judul</th><th>DOI</th><th>Aktif</th><th>Dicek</th></tr></thead>
    <tbody>
    <?php $no=1; foreach ($articles as $a): ?>
      <tr>
        <td><?= $no++ ?></td>
        <td class="small"><?= h($a['judul'] ?: '—') ?></td>
        <td class="small"><a href="https://doi.org/<?= h($a['doi']) ?>" target="_blank" rel="noopener"><?= h($a['doi']) ?></a></td>
        <td><?= $a['crossref_active'] ? '<span class="badge badge-success">aktif</span>' : '<span class="badge badge-failed">belum</span>' ?></td>
        <td class="small muted"><?= h($a['checked_at'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
