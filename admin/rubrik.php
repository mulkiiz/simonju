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
        $nama = $cut($_POST['nama'] ?? '', 200);
        if ($kode !== '' && $nama !== '') {
            $ord = (int)(fetch_one("SELECT COALESCE(MAX(urutan),0)+1 AS n FROM rubrik_unsur WHERE kategori=?", 's', [$kat])['n'] ?? 1);
            exec_q("INSERT INTO rubrik_unsur (kategori,kode,nama,urutan) VALUES (?,?,?,?)", 'sssi', [$kat, $kode, $nama, $ord]);
            $msg = 'Unsur ditambahkan.';
        } else { $msg = 'Kode & nama unsur wajib diisi.'; }
    } elseif ($act === 'unsur_edit') {
        $id = (int)($_POST['id'] ?? 0);
        $kode = $cut($_POST['kode'] ?? '', 10);
        $nama = $cut($_POST['nama'] ?? '', 200);
        $catatan = $cut($_POST['catatan'] ?? '', 2000);
        if ($id && $kode !== '' && $nama !== '') {
            exec_q("UPDATE rubrik_unsur SET kode=?, nama=?, catatan=? WHERE id=?", 'sssi', [$kode, $nama, ($catatan === '' ? null : $catatan), $id]);
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

$view = ($_GET['view'] ?? 'rubrik') === 'hasil' ? 'hasil' : 'rubrik';

/* Peta unsur_id -> [kategori, max] untuk hitung skor dari draft. */
$umap = [];
foreach ($RB as $kat => $c) {
    foreach ($c['unsur'] as $u) $umap[(int)$u['id']] = ['kat' => $kat, 'max' => (float)$u['max']];
}
$RB_MAX_A = (float)$RB['tata_kelola']['max'];
$RB_MAX_B = (float)$RB['mutu_artikel']['max'];

/** Prediksi peringkat dari total & disinsentif. */
function ed_band($total, $disfail) {
    if ($disfail) return ['Tidak Terakreditasi (disinsentif)', '#dc2626'];
    if ($total >= 90) return ['Peringkat 1', '#15803d'];
    if ($total >= 80) return ['Peringkat 2', '#16a34a'];
    if ($total >= 70) return ['Peringkat 3', '#ca8a04'];
    if ($total >= 60) return ['Peringkat 4', '#ea580c'];
    return ['Tidak Terakreditasi', '#dc2626'];
}

/** Hitung skor 3A/3B + disinsentif dari data draft (array). */
function ed_score($data, $umap) {
    $a = $b = 0.0;
    foreach ($data as $k => $v) {
        if (strpos($k, 'r_unsur_') !== 0) continue;
        $id = (int)substr($k, 8);
        if (!isset($umap[$id])) continue;
        $val = (float)$v;
        if ($umap[$id]['kat'] === 'tata_kelola') $a += $val; else $b += $val;
    }
    $d1 = $data['d1'] ?? 'tidak';
    $d2 = $data['d2'] ?? 'ada';
    $disfail = ($d1 === 'ya') || ($d2 === 'tidak');
    return ['a' => round($a, 2), 'b' => round($b, 2), 'total' => round($a + $b, 2), 'disfail' => $disfail, 'd1' => $d1, 'd2' => $d2];
}

$STEP_LABEL = [0=>'Info Umum',1=>'Pengajuan',2=>'Pemeriksaan Awal',3=>'Kelayakan',4=>'3A Tata Kelola',5=>'3B Mutu Artikel',6=>'3C Disinsentif',7=>'Selesai'];

// Data untuk view hasil (list) & detail (jika ?jid=).
$hasil_rows = [];
$detail = null;
if ($view === 'hasil') {
    $drafts = fetch_all(
        "SELECT d.jurnal_id, d.step, d.data, d.updated_at, j.nama_jurnal, j.unit_kerja
         FROM evaluasi_draft d JOIN jurnals j ON j.id=d.jurnal_id
         ORDER BY d.updated_at DESC"
    );
    foreach ($drafts as $d) {
        $data = json_decode($d['data'] ?? '', true) ?: [];
        $sc = ed_score($data, $umap);
        $d['_sc'] = $sc; $d['_data'] = $data;
        $hasil_rows[] = $d;
    }
    $jid = (int)($_GET['jid'] ?? 0);
    if ($jid) {
        foreach ($hasil_rows as $r) if ((int)$r['jurnal_id'] === $jid) { $detail = $r; break; }
    }
}
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
.rb-uhead form.uedit{flex-wrap:wrap;flex:1;min-width:280px}
.rb-uhead .unote{flex-basis:100%;width:100%;font-size:12px;resize:vertical;font-family:inherit}
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
  <h1>📐 ED Akreditasi</h1>
</div>

<?php if ($flash): ?><div class="alert alert-info"><?= h($flash) ?></div><?php endif; ?>

<div class="rb-tabs">
  <?php foreach ($valid_kat as $k): ?>
    <a href="?tab=<?= $k ?>" class="<?= ($view==='rubrik' && $tab===$k)?'active':'' ?>"><?= h(rubrik_kategori_label($k)) ?></a>
  <?php endforeach; ?>
  <a href="?view=hasil" class="<?= $view==='hasil'?'active':'' ?>">📊 Hasil ED Jurnal</a>
</div>

<?php if ($view === 'rubrik'): ?>

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
    <form method="post" class="uedit">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="unsur_edit">
      <input type="hidden" name="tab" value="<?= $tab ?>">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <input class="rb-inp" type="text" name="kode" value="<?= h($u['kode']) ?>" style="width:60px" maxlength="10" title="Kode">
      <input class="rb-inp" type="text" name="nama" value="<?= h($u['nama']) ?>" style="width:260px" maxlength="200" title="Nama unsur">
      <button class="rb-mini" title="Simpan kode/nama/catatan">💾</button>
      <textarea class="rb-inp unote" name="catatan" rows="2" maxlength="2000" placeholder="Catatan/panduan penilaian (opsional) — tampil sebagai info di form jurnal"><?= h($u['catatan'] ?? '') ?></textarea>
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

<?php else: /* ===================== VIEW: HASIL ED JURNAL ===================== */ ?>

<style>
.ed-tab{width:100%;border-collapse:collapse;font-size:13px}
.ed-tab th{background:#f8fafc;text-align:left;padding:9px 10px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#475569;white-space:nowrap}
.ed-tab td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.ed-tab tr:hover td{background:#f8fafc}
.ed-band{display:inline-block;padding:2px 10px;border-radius:99px;color:#fff;font-weight:700;font-size:12px;white-space:nowrap}
.ed-num{text-align:right;font-variant-numeric:tabular-nums;font-weight:700}
.ed-prog{display:inline-block;font-size:11px;padding:2px 8px;border-radius:99px;background:#eef2f7;color:#475569}
.ed-prog.done{background:#dcfce7;color:#15803d}
.ed-dl{margin:0;display:grid;grid-template-columns:170px 1fr;gap:6px 12px;font-size:13px}
.ed-dl dt{color:#64748b}.ed-dl dd{margin:0}
.ed-det-tab{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px}
.ed-det-tab td{padding:6px 9px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.ed-det-tab .c{width:44px;font-weight:700;color:#1e3a8a}
.ed-det-tab .v{width:66px;text-align:right;font-weight:700;color:#0c1e4a;white-space:nowrap}
.ed-krit{color:#64748b;font-size:11.5px;margin-top:2px}
</style>

<?php if ($detail): $sc=$detail['_sc']; $data=$detail['_data']; [$bl,$bc]=ed_band($sc['total'],$sc['disfail']); ?>
  <p><a href="?view=hasil" class="btn btn-sm">&larr; Daftar Hasil</a></p>
  <div class="card" style="padding:20px;max-width:860px">
    <h2 style="margin:0 0 4px"><?= h($detail['nama_jurnal']) ?></h2>
    <p class="muted small" style="margin:0 0 14px"><?= h($detail['unit_kerja'] ?: '-') ?> · diperbarui <?= h($detail['updated_at']) ?> · langkah: <?= h($STEP_LABEL[(int)$detail['step']] ?? $detail['step']) ?></p>

    <dl class="ed-dl" style="margin-bottom:14px">
      <dt>Jenis usulan</dt><dd><?= h($data['jenis_usulan'] ?? '-') ?></dd>
      <dt>Username OJS</dt><dd class="mono"><?= h($data['ojs_user'] ?? '-') ?></dd>
      <dt>URL login OJS</dt><dd><?= !empty($data['ojs_login_url']) ? '<a href="'.h($data['ojs_login_url']).'" target="_blank" rel="noopener">'.h($data['ojs_login_url']).'</a>' : '-' ?></dd>
      <dt>Skor 3A / 3B</dt><dd><strong><?= rubrik_num($sc['a']) ?></strong> / <?= rubrik_num($RB_MAX_A) ?> &nbsp;·&nbsp; <strong><?= rubrik_num($sc['b']) ?></strong> / <?= rubrik_num($RB_MAX_B) ?></dd>
      <dt>Total</dt><dd><strong style="font-size:16px"><?= rubrik_num($sc['total']) ?></strong> / 100 &nbsp; <span class="ed-band" style="background:<?= $bc ?>"><?= h($bl) ?></span></dd>
      <dt>Disinsentif</dt><dd>Pelanggaran: <strong><?= $sc['d1']==='ya'?'Ya':'Tidak' ?></strong> · Ethical Clearance: <strong><?= $sc['d2']==='tidak'?'Tidak ada':'Ada' ?></strong></dd>
    </dl>

    <?php foreach (['tata_kelola'=>'STEP 3A — Tata Kelola','mutu_artikel'=>'STEP 3B — Mutu Artikel'] as $kat=>$title): ?>
      <h3 style="font-size:14px;color:#0c1e4a;margin:16px 0 4px;border-bottom:2px solid #eef2f7;padding-bottom:4px"><?= $title ?></h3>
      <table class="ed-det-tab"><tbody>
      <?php foreach ($RB[$kat]['unsur'] as $u):
          $picked = $data['r_unsur_'.(int)$u['id']] ?? null;
          $ktext = '(belum dipilih)';
          if ($picked !== null) { foreach ($u['kriteria'] as $k) if (rubrik_num($k['nilai'])===rubrik_num($picked)) { $ktext=$k['kriteria']; break; } }
      ?>
        <tr>
          <td class="c"><?= h($u['kode']) ?></td>
          <td><strong><?= h($u['nama']) ?></strong><div class="ed-krit"><?= h($ktext) ?></div></td>
          <td class="v"><?= $picked!==null ? rubrik_num($picked) : '–' ?> / <?= rubrik_num($u['max']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endforeach; ?>
  </div>

<?php else: /* list */ ?>
  <?php if (empty($hasil_rows)): ?>
    <p class="muted">Belum ada jurnal yang mengisi evaluasi diri.</p>
  <?php else: ?>
  <p class="muted small" style="margin:0 0 12px"><?= count($hasil_rows) ?> jurnal telah mengisi / menyimpan draft evaluasi diri.</p>
  <div class="tbl-wrap" style="overflow-x:auto">
  <table class="ed-tab">
    <thead><tr>
      <th>#</th><th>Nama Jurnal</th><th>Unit</th><th>Langkah</th>
      <th class="ed-num">3A</th><th class="ed-num">3B</th><th class="ed-num">Total</th>
      <th>Prediksi</th><th>Diperbarui</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($hasil_rows as $i=>$r): $sc=$r['_sc']; [$bl,$bc]=ed_band($sc['total'],$sc['disfail']); $done=(int)$r['step']>=7; ?>
      <tr>
        <td class="muted"><?= $i+1 ?></td>
        <td><strong><?= h($r['nama_jurnal']) ?></strong></td>
        <td class="muted"><?= h($r['unit_kerja'] ?: '-') ?></td>
        <td><span class="ed-prog <?= $done?'done':'' ?>"><?= h($STEP_LABEL[(int)$r['step']] ?? $r['step']) ?></span></td>
        <td class="ed-num"><?= rubrik_num($sc['a']) ?></td>
        <td class="ed-num"><?= rubrik_num($sc['b']) ?></td>
        <td class="ed-num"><?= rubrik_num($sc['total']) ?></td>
        <td><span class="ed-band" style="background:<?= $bc ?>"><?= h($bl) ?></span></td>
        <td class="muted small"><?= h($r['updated_at']) ?></td>
        <td><a class="btn btn-sm" href="?view=hasil&jid=<?= (int)$r['jurnal_id'] ?>">Detail</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php endif; /* view */ ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
