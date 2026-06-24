<?php
/**
 * admin/export_katalog.php
 * Katalog Jurnal Ilmiah — halaman cetak (print-to-PDF).
 * Cover + 1 halaman per profil jurnal. Hanya jurnal terkonfirmasi.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$rows = fetch_all("
  SELECT j.*,
    (SELECT e.nama  FROM editor e WHERE e.jurnal_id=j.id LIMIT 1) AS editor_nama,
    (SELECT e.email FROM editor e WHERE e.jurnal_id=j.id LIMIT 1) AS editor_email,
    (SELECT e.no_hp FROM editor e WHERE e.jurnal_id=j.id LIMIT 1) AS editor_hp,
    (SELECT COUNT(*) FROM terbitan t WHERE t.jurnal_id=j.id) AS total_terbitan,
    (SELECT COALESCE(SUM(jumlah_artikel),0) FROM terbitan t WHERE t.jurnal_id=j.id) AS total_artikel,
    ap.peringkat AS ap_peringkat, ap.no_sk,
    ap.mulai_volume, ap.mulai_nomor, ap.mulai_tahun,
    ap.sampai_volume, ap.sampai_nomor, ap.sampai_tahun
  FROM jurnals j
  LEFT JOIN akreditasi_periode ap ON ap.jurnal_id=j.id
  WHERE j.konfirmasi_status='terkonfirmasi'
  ORDER BY
    CASE
      WHEN j.is_scopus=1 THEN 1
      WHEN j.akreditasi_jenis='sinta' THEN 2
      WHEN (j.akreditasi_jenis IS NULL OR j.akreditasi_jenis='' OR j.akreditasi_jenis='belum')
           AND j.p_issn IS NOT NULL AND j.p_issn<>'' THEN 3
      ELSE 4
    END,
    j.akreditasi_peringkat ASC,
    j.nama_jurnal ASC
");

$tahun_terbit = date('Y');

// ── Helpers ──────────────────────────────────────────────
function kat_apc($v) {
    $v = trim((string)$v);
    if (!preg_match('/^[1-9][0-9]*$/', $v)) return '-';
    return 'Rp ' . number_format((int)$v, 0, ',', '.');
}
function kat_akreditasi($r) {
    // Sinta & Scopus independen; tampilkan keduanya bila berlaku.
    $parts = [];
    $cls = 'belum';
    if (($r['akreditasi_jenis'] ?? '') === 'sinta') {
        $p = trim((string)$r['akreditasi_peringkat']);
        if ($p !== '') {
            $parts[] = $p;
            $cls = 's' . preg_replace('/[^0-9]/', '', $p);
        }
    }
    if ((int)$r['is_scopus'] === 1) {
        $q = trim((string)($r['scopus_q'] ?? ''));
        $parts[] = 'Scopus' . ($q !== '' ? " {$q}" : '');
        $cls = 'sc';
    }
    if (!$parts) return ['Belum Terakreditasi', 'belum'];
    return [implode(' · ', $parts), $cls];
}
function kat_row($label, $val) {
    $val = trim((string)$val);
    if ($val === '') $val = '—';
    return '<tr><th>' . h($label) . '</th><td>' . h($val) . '</td></tr>';
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Katalog Jurnal Ilmiah — SIMONJU</title>
<style>
  @page { size: A4 portrait; margin: 0; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:"Segoe UI",Arial,sans-serif; color:#1e293b; background:#e2e8f0; }

  /* Toolbar (tidak ikut cetak) */
  .toolbar { position:sticky; top:0; background:#0f172a; color:#fff; padding:12px 20px;
             display:flex; gap:12px; align-items:center; z-index:10; }
  .toolbar .btn { background:#2563eb; color:#fff; border:none; padding:8px 18px; border-radius:7px;
                  font-weight:700; cursor:pointer; text-decoration:none; font-size:14px; }
  .toolbar .btn.secondary { background:#334155; }
  .toolbar .muted { color:#94a3b8; font-size:13px; margin-left:auto; }

  /* Halaman — tinggi TETAP 296mm agar identik screen & print */
  .page { width:210mm; height:296mm; overflow:hidden; background:#fff; margin:18px auto; padding:18mm 16mm 24mm;
          box-shadow:0 4px 18px rgba(0,0,0,.18); page-break-after:always; break-after:page; position:relative; }
  .page:last-child { page-break-after:auto; break-after:auto; }

  /* Cover */
  .cover { display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center;
           background:linear-gradient(160deg,#1e3a8a,#2563eb); -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .cover .band-btm { position:absolute; left:0; right:0; height:34mm; bottom:0; background:#0f172a; }
  .cover-inner { position:relative; z-index:1; margin-top:26mm; }
  .cover h1 { font-size:34px; color:#fff; margin:0 0 6px; letter-spacing:.5px; }
  .cover h2 { font-size:17px; font-weight:500; color:#dbeafe; margin:0 0 30mm; font-style:italic; }
  .cover .logo { width:120px; height:120px; object-fit:contain; background:#fff; border-radius:50%;
                 padding:14px; box-shadow:0 6px 20px rgba(0,0,0,.2); margin:0 auto 10px; display:block; }
  .cover .gen { color:#dbeafe; font-size:13px; font-style:italic; margin:6px 0 38mm; }
  .cover .foot { position:absolute; bottom:8mm; left:0; right:0; color:#fff; z-index:2; line-height:1.5; }
  .cover .foot strong { display:block; font-size:16px; }
  .cover .foot span { display:block; font-size:13px; color:#cbd5e1; }

  /* Profil jurnal */
  .j-head { border-left:6px solid #2563eb; padding:4px 0 4px 14px; margin-bottom:4px; }
  .j-no { font-size:12px; color:#94a3b8; font-weight:700; letter-spacing:1px; }
  .j-title { font-size:24px; font-weight:800; color:#0f172a; line-height:1.2; margin:2px 0 6px; }
  .j-unit { color:#475569; font-size:14px; }
  .badge { display:inline-block; padding:4px 14px; border-radius:99px; font-size:13px; font-weight:700; color:#fff; margin-top:8px; }
  .b-sc { background:#7c3aed; } .b-s1{background:#15803d;} .b-s2{background:#16a34a;}
  .b-s3 { background:#65a30d; } .b-s4{background:#ca8a04;} .b-s5{background:#ea580c;} .b-s6{background:#dc2626;}
  .b-belum { background:#94a3b8; }

  .j-body { display:flex; gap:16mm; margin-top:10mm; }
  .j-cover-wrap { flex:0 0 55mm; text-align:center; }
  .j-cover { width:55mm; height:75mm; object-fit:cover; border-radius:8px; border:1px solid #cbd5e1;
             box-shadow:0 4px 14px rgba(0,0,0,.12); }
  .j-cover.empty { display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:13px;
                   background:#f1f5f9; }
  .j-detail { flex:1; }
  table.kv { width:100%; border-collapse:collapse; font-size:13px; }
  table.kv th { text-align:left; color:#64748b; font-weight:600; width:38%; padding:6px 8px; vertical-align:top;
                border-bottom:1px solid #f1f5f9; }
  table.kv td { padding:6px 8px; border-bottom:1px solid #f1f5f9; color:#0f172a; word-break:break-word; }

  /* Grid kartu info */
  .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:5mm; }
  .info-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:11px 13px; }
  .info-card .ic-h { display:flex; align-items:center; gap:7px; font-size:11px; font-weight:700;
                     text-transform:uppercase; letter-spacing:.5px; color:#64748b; margin-bottom:5px; }
  .info-card .ic-h .emoji { font-size:15px; }
  .info-card .ic-b { font-size:13px; color:#0f172a; line-height:1.45; word-break:break-word; }
  .info-card .ic-b a { color:#2563eb; text-decoration:none; }
  .info-card .ic-b .sub { color:#64748b; font-size:12px; }
  .info-card.span2 { grid-column:1 / -1; }
  .pill-sinta { display:inline-block; padding:2px 10px; border-radius:99px; font-size:12px; font-weight:700;
                background:#dbeafe; color:#1e40af; }

  /* Tabel terbitan */
  .sec-title { display:flex; align-items:center; gap:8px; font-size:15px; font-weight:800; color:#0f172a;
               margin:9mm 0 4mm; padding-bottom:5px; border-bottom:2px solid #2563eb; }
  table.terbitan { width:100%; border-collapse:collapse; font-size:12.5px; }
  table.terbitan thead th { background:#1e3a8a; color:#fff; text-align:left; padding:7px 10px; font-weight:600; }
  table.terbitan thead th.num { text-align:center; }
  table.terbitan tbody td { padding:6px 10px; border-bottom:1px solid #eef2f7; }
  table.terbitan tbody td.num { text-align:center; }
  table.terbitan tbody tr:nth-child(even) td { background:#f8fafc; }
  .empty-note { color:#94a3b8; font-size:13px; font-style:italic; padding:6px 2px; }

  .j-foot { position:absolute; bottom:12mm; left:16mm; right:16mm; border-top:1px solid #e2e8f0;
            padding-top:6px; font-size:11px; color:#94a3b8; display:flex; justify-content:space-between; }

  @media print {
    * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    html, body { background:#fff; margin:0; padding:0; }
    .toolbar { display:none; }
    /* Dimensi & padding sudah tetap di .page; @page margin 0 -> padding = margin cetak. */
    .page { margin:0; box-shadow:none; border-radius:0; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn" onclick="window.print()">🖨️ Cetak / Simpan PDF</button>
  <a class="btn secondary" href="dashboard.php">← Kembali</a>
  <span class="muted"><?= count($rows) ?> jurnal · pakai "Save as PDF" pada dialog cetak</span>
</div>

<!-- ============ COVER ============ -->
<div class="page cover">
  <div class="band-btm"></div>
  <div class="cover-inner">
    <h1>Katalog Jurnal Ilmiah</h1>
    <h2>di Lingkungan Universitas Jenderal Soedirman</h2>
    <img class="logo" src="../assets/logo_unsoed.png" alt="Logo Unsoed">
    <p class="gen">generated by Simonju</p>
  </div>
  <div class="foot">
    <strong>Pusat Pengelolaan Jurnal</strong>
    <span>Lembaga Penelitian dan Pengabdian Kepada Masyarakat</span>
    <span>Universitas Jenderal Soedirman</span>
    <span><?= h($tahun_terbit) ?></span>
  </div>
</div>

<!-- ============ PROFIL JURNAL ============ -->
<?php $no = 1; foreach ($rows as $r):
  [$akr_label, $akr_cls] = kat_akreditasi($r);
  $cover = trim((string)($r['file_cover'] ?? ''));
  $issn  = trim((string)($r['p_issn'] ?? '')) . (trim((string)$r['e_issn']) !== '' ? '  /  ' . trim((string)$r['e_issn']) : '');
  // Masa berlaku akreditasi
  $tmt = '';
  if (trim((string)$r['mulai_tahun'].$r['sampai_tahun'].$r['mulai_volume']) !== '') {
      $tmt = sprintf('Vol %s No %s (%s) s/d Vol %s No %s (%s)',
          $r['mulai_volume'] ?: '—', $r['mulai_nomor'] ?: '—', $r['mulai_tahun'] ?: '—',
          $r['sampai_volume'] ?: '—', $r['sampai_nomor'] ?: '—', $r['sampai_tahun'] ?: '—');
  }
  // Link portal Sinta
  $sinta_link = trim((string)($r['link_sinta'] ?? ''));
  if ($sinta_link === '') $sinta_link = 'https://sinta.kemdiktisaintek.go.id';
  // Terbitan 3 tahun terakhir
  $terb_all = fetch_all(
      "SELECT volume, nomor, tahun, jumlah_artikel FROM terbitan WHERE jurnal_id=?
       ORDER BY tahun DESC, CAST(volume AS UNSIGNED) DESC, CAST(nomor AS UNSIGNED) DESC",
      'i', [$r['id']]
  );
  $years3 = [];
  foreach ($terb_all as $t) { $y = trim((string)$t['tahun']); if ($y !== '') $years3[$y] = true; }
  $years3 = array_map('strval', array_slice(array_keys($years3), 0, 3));
  $terb = array_values(array_filter($terb_all, function($t) use ($years3){
      return in_array(trim((string)$t['tahun']), $years3, true);
  }));
?>
<div class="page">
  <div class="j-head">
    <div class="j-no">PROFIL JURNAL #<?= $no ?></div>
    <div class="j-title"><?= h($r['nama_jurnal']) ?></div>
    <div class="j-unit">🏛️ <?= h($r['unit_kerja'] ?: '—') ?></div>
    <span class="badge b-<?= h($akr_cls) ?>"><?= h($akr_label) ?></span>
  </div>

  <div class="j-body">
    <div class="j-cover-wrap">
      <?php if ($cover !== '' && file_exists(__DIR__ . '/../uploads/jurnal/' . $cover)): ?>
        <img class="j-cover" src="../uploads/jurnal/<?= h($cover) ?>" alt="Cover">
      <?php else: ?>
        <div class="j-cover empty">Tanpa cover</div>
      <?php endif; ?>
      <div class="info-card" style="margin-top:5mm;text-align:left">
        <div class="ic-h"><span class="emoji">🏛️</span> Penerbit</div>
        <div class="ic-b"><?= h($r['unit_kerja'] ?: '—') ?></div>
      </div>
    </div>
    <div class="j-detail">
      <div class="info-grid">

        <div class="info-card span2">
          <div class="ic-h"><span class="emoji">🔗</span> Website Jurnal</div>
          <div class="ic-b">
            <?php $u = trim((string)$r['url_archive']); if ($u !== ''): ?>
              <a href="<?= h($u) ?>"><?= h($u) ?></a>
            <?php else: ?>—<?php endif; ?>
          </div>
        </div>

        <div class="info-card span2">
          <div class="ic-h"><span class="emoji">👤</span> Ketua Editor</div>
          <div class="ic-b">
            <?= h($r['editor_nama'] ?: '—') ?>
            <?php if (trim((string)$r['editor_email']) !== ''): ?>
              <div class="sub">✉️ <?= h($r['editor_email']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="info-card">
          <div class="ic-h"><span class="emoji">🏅</span> Akreditasi</div>
          <div class="ic-b">
            <span class="pill-sinta"><?= h($akr_label) ?></span>
            <?php if ($tmt !== ''): ?><div class="sub" style="margin-top:5px">📅 <?= h($tmt) ?></div><?php endif; ?>
            <?php if (trim((string)$r['no_sk']) !== ''): ?><div class="sub">📄 SK: <?= h($r['no_sk']) ?></div><?php endif; ?>
            <div class="sub" style="margin-top:4px">🔎 <a href="<?= h($sinta_link) ?>">[Link]</a></div>
          </div>
        </div>

        <div class="info-card">
          <div class="ic-h"><span class="emoji">🏷️</span> ISSN</div>
          <div class="ic-b">
            <div>p-ISSN: <strong><?= h(trim((string)$r['p_issn']) ?: '—') ?></strong></div>
            <div>e-ISSN: <strong><?= h(trim((string)$r['e_issn']) ?: '—') ?></strong></div>
          </div>
        </div>

        <div class="info-card">
          <div class="ic-h"><span class="emoji">💰</span> APC</div>
          <div class="ic-b">
            <strong><?= h(kat_apc($r['apc'] ?? '')) ?></strong>
            <?php if (trim((string)$r['doi']) !== ''): ?><div class="sub">🔣 DOI: <?= h($r['doi']) ?></div><?php endif; ?>
          </div>
        </div>

        <div class="info-card">
          <div class="ic-h"><span class="emoji">📅</span> Frekuensi Terbit</div>
          <div class="ic-b">
            <strong><?= h(trim((string)$r['frekuensi_terbit']) ?: ((trim((string)$r['volume_per_tahun']) !== '') ? $r['volume_per_tahun'].' terbit/tahun' : '—')) ?></strong>
          </div>
        </div>

      </div>
    </div>
  </div>

  <div class="sec-title"><span>📚</span> Daftar Terbitan — 3 Tahun Terakhir</div>
  <?php if (empty($terb)): ?>
    <div class="empty-note">Belum ada data terbitan.</div>
  <?php else: ?>
    <table class="terbitan">
      <thead>
        <tr><th class="num">No</th><th class="num">Volume</th><th class="num">Nomor</th><th class="num">Tahun</th><th class="num">Jumlah Artikel</th></tr>
      </thead>
      <tbody>
        <?php $tn = 1; foreach ($terb as $t): ?>
          <tr>
            <td class="num"><?= $tn++ ?></td>
            <td class="num"><?= h(trim((string)$t['volume']) ?: '—') ?></td>
            <td class="num"><?= h(trim((string)$t['nomor']) ?: '—') ?></td>
            <td class="num"><?= h(trim((string)$t['tahun']) ?: '—') ?></td>
            <td class="num"><?= (int)$t['jumlah_artikel'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="j-foot">
    <span>Katalog Jurnal Ilmiah · Universitas Jenderal Soedirman</span>
    <span>Hal. <?= $no + 1 ?></span>
  </div>
</div>
<?php $no++; endforeach; ?>

<script>
  // Auto-buka dialog cetak (boleh dibatalkan)
  window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });
</script>
</body>
</html>
