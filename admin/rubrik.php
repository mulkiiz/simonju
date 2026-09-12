<?php
/**
 * admin/rubrik.php — Editor master rubrik evaluasi diri akreditasi.
 * CRUD unsur & kriteria bertingkat untuk 2 standar (Tata Kelola & Mutu Artikel).
 * Dibaca oleh jurnal/evaluasi.php lewat includes/rubrik.php.
 */
$page_title = 'Master Rubrik Akreditasi';
require_once __DIR__ . '/../includes/header_admin.php';
require_once __DIR__ . '/../includes/rubrik.php';

$valid_kat = ['tata_kelola', 'mutu_artikel'];
$tab = in_array($_GET['tab'] ?? '', $valid_kat, true) ? $_GET['tab'] : 'tata_kelola';

/* ── Handler POST (PRG) ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $rt  = in_array($_POST['tab'] ?? '', $valid_kat, true) ? $_POST['tab'] : 'tata_kelola';
    $msg = '';

    $cut = function ($v, $n) { return mb_substr(trim((string)$v), 0, $n); };
    $num = function ($v) { $f = (float)str_replace(',', '.', (string)$v); return $f < 0 ? 0 : round($f, 2); };

    if ($act === 'unsur_add') {
        $kat = in_array($_POST['kategori'] ?? '', $valid_kat, true) ? $_POST['kategori'] : $rt;
        $kode = $cut($_POST['kode'] ?? '', 10);
        $nama = $cut($_POST['nama'] ?? '', 150);
        if ($kode !== '' && $nama !== '') {
            $ord = (int)(fetch_one("SELECT COALESCE(MAX(urutan),0)+1 AS n FROM rubrik_unsur WHERE kategori=?", 's', [$kat])['n'] ?? 1);
            exec_q("INSERT INTO rubrik_unsur (kategori,kode,nama,urutan) VALUES (?,?,?,?)", 'sssi', [$kat, $kode, $nama, $ord]);
            $msg = 'Unsur ditambahkan.';
        } else { $msg = 'Kode & nama unsur wajib diisi.'; }
    } elseif ($act === 'unsur_edit') {
        $id = (int)($_POST['id'] ?? 0);
        $kode = $cut($_POST['kode'] ?? '', 10);
        $nama = $cut($_POST['nama'] ?? '', 150);
        if ($id && $kode !== '' && $nama !== '') {
            exec_q("UPDATE rubrik_unsur SET kode=?, nama=? WHERE id=?", 'ssi', [$kode, $nama, $id]);
            $msg = 'Unsur diperbarui.';
        }
    } elseif ($act === 'unsur_del') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { exec_q("DELETE FROM rubrik_unsur WHERE id=?", 'i', [$id]); $msg = 'Unsur dihapus (beserta kriterianya).'; }
    } elseif ($act === 'krit_add') {
        $uid = (int)($_POST['unsur_id'] ?? 0);
        $krit = $cut($_POST['kriteria'] ?? '', 500);
        $nilai = $num($_POST['nilai'] ?? 0);
        if ($uid && $krit !== '') {
            $ord = (int)(fetch_one("SELECT COALESCE(MAX(urutan),0)+1 AS n FROM rubrik_kriteria WHERE unsur_id=?", 'i', [$uid])['n'] ?? 1);
            exec_q("INSERT INTO rubrik_kriteria (unsur_id,kriteria,nilai,urutan) VALUES (?,?,?,?)", 'isdi', [$uid, $krit, $nilai, $ord]);
            $msg = 'Kriteria ditambahkan.';
        }
    } elseif ($act === 'krit_edit') {
        $id = (int)($_POST['id'] ?? 0);
        $krit = $cut($_POST['kriteria'] ?? '', 500);
        $nilai = $num($_POST['nilai'] ?? 0);
        if ($id && $krit !== '') {
            exec_q("UPDATE rubrik_kriteria SET kriteria=?, nilai=? WHERE id=?", 'sdi', [$krit, $nilai, $id]);
            $msg = 'Kriteria diperbarui.';
        }
    } elseif ($act === 'krit_del') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { exec_q("DELETE FROM rubrik_kriteria WHERE id=?", 'i', [$id]); $msg = 'Kriteria dihapus.'; }
    }

    header('Location: rubrik.php?tab=' . $rt . '&msg=' . urlencode($msg));
    exit;
}

$RB = rubrik_load();
$flash = $_GET['msg'] ?? '';
?>
<style>
.rb-tabs{display:flex;gap:0;border-bottom:2px solid #d0d5dd;margin-bottom:22px}
.rb-tabs a{padding:10px 22px;text-decoration:none;font-weight:600;font-size:14px;color:#667085;border-bottom:2px solid transparent;margin-bottom:-2px}
.rb-tabs a.active{color:#1d4ed8;border-bottom-color:#1d4ed8}
.rb-sum{display:flex;align-items:center;gap:10px;margin-bottom:18px;font-size:14px}
.rb-badge{padding:3px 12px;border-radius:99px;font-weight:700;font-size:13px}
.rb-ok{background:#ecfdf3;color:#027a48}
.rb-warn{background:#fffaeb;color:#b54708}
.rb-unsur{border:1px solid #e5e7eb;border-radius:10px;margin-bottom:14px;overflow:hidden}
.rb-uhead{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #eef2f7;flex-wrap:wrap}
.rb-code{background:#1e3a8a;color:#fff;font-weight:700;font-size:12px;padding:3px 9px;border-radius:6px}
.rb-uhead .nm{font-weight:700;font-size:14px;color:#111827}
.rb-uhead .mx{margin-left:auto;font-size:12px;color:#64748b}
.rb-uhead form{display:inline-flex;gap:6px;align-items:center}
.rb-inp{padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;font-family:inherit}
.rb-krit{width:100%;border-collapse:collapse;font-size:13px}
.rb-krit td{padding:7px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.rb-krit tr:last-child td{border-bottom:none}
.rb-krit .knilai{width:70px;text-align:center}
.rb-krit .ktext{width:100%}
.rb-krit form.krow{display:flex;gap:6px;align-items:center;width:100%}
.rb-addk{padding:9px 14px;background:#fbfdff;border-top:1px dashed #dbe4f0}
.rb-addk form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.rb-mini{padding:4px 9px;font-size:12px;border-radius:5px;border:1px solid #d1d5db;background:#fff;cursor:pointer}
.rb-mini-danger{color:#b42318;border-color:#fda29b}
.rb-mini-danger:hover{background:#fef3f2}
.rb-mini-ok{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
.rb-newunsur{border:1px dashed #c7d2e0;border-radius:10px;padding:14px;margin-top:8px;background:#fbfdff}
.rb-newunsur form{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
.rb-newunsur label{font-size:12px;font-weight:600;color:#475569;display:flex;flex-direction:column;gap:3px}
</style>

<div class="page-head">
  <h1>📐 Master Rubrik Akreditasi</h1>
</div>

<?php if ($flash): ?><div class="alert alert-info"><?= h($flash) ?></div><?php endif; ?>

<div class="rb-tabs">
  <?php foreach ($valid_kat as $k): ?>
    <a href="?tab=<?= $k ?>" class="<?= $tab===$k?'active':'' ?>"><?= h(rubrik_kategori_label($k)) ?></a>
  <?php endforeach; ?>
</div>

<?php
$cat = $RB[$tab];
$target = $cat['target'];
$max = (float)$cat['max'];
$ok = abs($max - $target) < 0.001;
?>
<div class="rb-sum">
  <span>Total bobot maksimum:</span>
  <span class="rb-badge <?= $ok?'rb-ok':'rb-warn' ?>"><?= rubrik_num($max) ?> / <?= $target ?></span>
  <?php if (!$ok): ?><span class="muted small">⚠️ belum sama dengan target <?= $target ?> — sesuaikan bobot/kriteria.</span><?php endif; ?>
</div>

<?php foreach ($cat['unsur'] as $u): ?>
<div class="rb-unsur">
  <div class="rb-uhead">
    <span class="rb-code"><?= h($u['kode']) ?></span>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="unsur_edit">
      <input type="hidden" name="tab" value="<?= $tab ?>">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <input class="rb-inp" type="text" name="kode" value="<?= h($u['kode']) ?>" style="width:60px" maxlength="10" title="Kode">
      <input class="rb-inp" type="text" name="nama" value="<?= h($u['nama']) ?>" style="width:220px" maxlength="150" title="Nama unsur">
      <button class="rb-mini" title="Simpan nama/kode">💾</button>
    </form>
    <span class="mx">maks <strong><?= rubrik_num($u['max']) ?></strong></span>
    <form method="post" onsubmit="return confirm('Hapus unsur <?= h(addslashes($u['kode'])) ?> beserta semua kriterianya?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="unsur_del">
      <input type="hidden" name="tab" value="<?= $tab ?>">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <button class="rb-mini rb-mini-danger" title="Hapus unsur">🗑️</button>
    </form>
  </div>

  <table class="rb-krit"><tbody>
    <?php foreach ($u['kriteria'] as $k): ?>
    <tr><td>
      <form method="post" class="krow">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="krit_edit">
        <input type="hidden" name="tab" value="<?= $tab ?>">
        <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
        <input class="rb-inp knilai" type="number" name="nilai" value="<?= rubrik_num($k['nilai']) ?>" step="0.5" min="0" title="Nilai">
        <input class="rb-inp ktext" type="text" name="kriteria" value="<?= h($k['kriteria']) ?>" maxlength="500">
        <button class="rb-mini" title="Simpan">💾</button>
      </form>
    </td><td style="width:40px">
      <form method="post" onsubmit="return confirm('Hapus kriteria ini?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="krit_del">
        <input type="hidden" name="tab" value="<?= $tab ?>">
        <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
        <button class="rb-mini rb-mini-danger" title="Hapus">✕</button>
      </form>
    </td></tr>
    <?php endforeach; ?>
  </tbody></table>

  <div class="rb-addk">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="krit_add">
      <input type="hidden" name="tab" value="<?= $tab ?>">
      <input type="hidden" name="unsur_id" value="<?= (int)$u['id'] ?>">
      <input class="rb-inp knilai" type="number" name="nilai" value="0" step="0.5" min="0" title="Nilai" style="width:70px">
      <input class="rb-inp" type="text" name="kriteria" placeholder="+ Tambah kriteria/tingkat skor…" maxlength="500" style="flex:1;min-width:240px">
      <button class="rb-mini rb-mini-ok">Tambah</button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<div class="rb-newunsur">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="unsur_add">
    <input type="hidden" name="tab" value="<?= $tab ?>">
    <input type="hidden" name="kategori" value="<?= $tab ?>">
    <label>Kode <input class="rb-inp" type="text" name="kode" maxlength="10" placeholder="mis. F8" style="width:80px" required></label>
    <label>Nama unsur <input class="rb-inp" type="text" name="nama" maxlength="150" placeholder="mis. Sumber Sekunder" style="width:280px" required></label>
    <button class="btn btn-primary btn-sm">➕ Tambah Unsur</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
