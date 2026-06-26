<?php
$page_title = 'Statistik Jurnal';
require_once __DIR__ . '/../includes/header_admin.php';

// =========================================================
// Reusable SQL
// =========================================================
// Belum ISSN = P-ISSN & E-ISSN dua-duanya bukan format valid xxxx-xxxx.
$BELUM_ISSN_SQL = "
  (COALESCE(p_issn,'') NOT REGEXP '^[0-9]{4}-[0-9]{3}[0-9Xx]$')
  AND (COALESCE(e_issn,'') NOT REGEXP '^[0-9]{4}-[0-9]{3}[0-9Xx]$')
";
$BER_APC_SQL = "apc REGEXP '^[0-9]+$' AND CAST(apc AS UNSIGNED) > 0";

// =========================================================
// Statistik SEMUA jurnal (untuk top cards konfirmasi)
// =========================================================
$all = fetch_one(
    "SELECT
        COUNT(*)                                                       AS total,
        SUM(konfirmasi_status='terkonfirmasi')                         AS terkonfirmasi,
        SUM(konfirmasi_status='pending')                               AS pending,
        SUM(konfirmasi_status IS NULL OR konfirmasi_status='' OR konfirmasi_status='belum') AS belum_konf
     FROM jurnals"
) ?: [];

$pending_baru = (int)(fetch_one("SELECT COUNT(*) AS n FROM jurnal_baru WHERE status='pending'")['n'] ?? 0);

$s_total      = (int)($all['total'] ?? 0) + $pending_baru;
$s_terkonf    = (int)($all['terkonfirmasi'] ?? 0);
$s_belum_konf = (int)($all['belum_conk'] ?? $all['belum_konf'] ?? 0);
$s_review     = (int)($all['pending'] ?? 0) + $pending_baru;

// =========================================================
// Statistik TERKONFIRMASI saja (untuk profil & link ke dashboard)
// =========================================================
$tf = fetch_one(
    "SELECT
        SUM(is_scopus=1)                                               AS scopus,
        SUM(akreditasi_jenis='sinta')                                  AS sinta,
        SUM(akreditasi_peringkat IS NOT NULL AND akreditasi_peringkat<>'') AS terakreditasi,
        SUM(akreditasi_jenis IS NULL OR akreditasi_jenis='' OR akreditasi_jenis='belum') AS belum_akr,
        SUM((akreditasi_jenis IS NULL OR akreditasi_jenis='' OR akreditasi_jenis='belum')
            AND NOT ($BELUM_ISSN_SQL)) AS issn_blm_akred,
        SUM($BER_APC_SQL)                                              AS ber_apc
     FROM jurnals WHERE konfirmasi_status='terkonfirmasi'"
) ?: [];

$tf_issn = fetch_one("SELECT COUNT(*) AS n FROM jurnals WHERE konfirmasi_status='terkonfirmasi' AND $BELUM_ISSN_SQL");

$s_scopus     = (int)($tf['scopus'] ?? 0);
$s_sinta      = (int)($tf['sinta'] ?? 0);
$s_akred      = (int)($tf['terakreditasi'] ?? 0);
// Belum Akreditasi = belum akreditasi TAPI sudah punya ISSN valid
// (yang belum punya ISSN dihitung di tag Belum ISSN, bukan di sini).
$s_belum_akr  = (int)($tf['issn_blm_akred'] ?? 0);
$s_issn_blm   = (int)($tf['issn_blm_akred'] ?? 0);
$s_belum_issn = (int)($tf_issn['n'] ?? 0);
$s_ber_apc    = (int)($tf['ber_apc'] ?? 0);

// Sinta breakdown (terkonfirmasi only)
$sinta_rows = fetch_all(
    "SELECT akreditasi_peringkat AS p, COUNT(*) AS n
       FROM jurnals
      WHERE konfirmasi_status='terkonfirmasi'
        AND akreditasi_jenis='sinta'
        AND akreditasi_peringkat IS NOT NULL AND akreditasi_peringkat<>''
      GROUP BY akreditasi_peringkat
      ORDER BY akreditasi_peringkat"
) ?: [];
$sinta_map = [];
foreach ($sinta_rows as $r) $sinta_map[trim($r['p'])] = (int)$r['n'];

// Scopus breakdown (terkonfirmasi, is_scopus=1, kuartil scopus_q Q1-Q4)
$scopus_rows = fetch_all(
    "SELECT scopus_q AS p, COUNT(*) AS n
       FROM jurnals
      WHERE konfirmasi_status='terkonfirmasi'
        AND is_scopus = 1
        AND scopus_q IN ('Q1','Q2','Q3','Q4')
      GROUP BY scopus_q
      ORDER BY scopus_q"
) ?: [];
$scopus_map = [];
foreach ($scopus_rows as $r) $scopus_map[trim($r['p'])] = (int)$r['n'];

// Pie chart data
$pie = [
    ['label'=>'Scopus', 'n'=>$s_scopus, 'color'=>'#1c4f9c'],
    ['label'=>'Sinta 1', 'n'=>(int)($sinta_map['Sinta 1']??0), 'color'=>'#1c7a47'],
    ['label'=>'Sinta 2', 'n'=>(int)($sinta_map['Sinta 2']??0), 'color'=>'#2bb56b'],
    ['label'=>'Sinta 3', 'n'=>(int)($sinta_map['Sinta 3']??0), 'color'=>'#5cc98c'],
    ['label'=>'Sinta 4', 'n'=>(int)($sinta_map['Sinta 4']??0), 'color'=>'#e0a91d'],
    ['label'=>'Sinta 5', 'n'=>(int)($sinta_map['Sinta 5']??0), 'color'=>'#e8852b'],
    ['label'=>'Sinta 6', 'n'=>(int)($sinta_map['Sinta 6']??0), 'color'=>'#d9603a'],
    ['label'=>'Belum Akreditasi', 'n'=>$s_belum_akr, 'color'=>'#aab3c0'],
];
$pie_total = 0;
foreach ($pie as $seg) $pie_total += $seg['n'];
$cg = []; $acc = 0;
foreach ($pie as $seg) {
    if ($seg['n'] <= 0) continue;
    $p = $pie_total > 0 ? $seg['n'] / $pie_total * 100 : 0;
    $from = round($acc, 3); $acc += $p; $to = round($acc, 3);
    $cg[] = $seg['color'] . ' ' . $from . '% ' . $to . '%';
}
$cg_str = $cg ? implode(',', $cg) : '#eef1f5 0% 100%';
?>
<style>
  .stat-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
  .stat-card{background:#fff;border:1px solid #e3e8ef;border-radius:13px;padding:18px 16px;text-align:center;box-shadow:0 2px 10px rgba(20,40,80,.05);transition:transform .15s,box-shadow .15s;text-decoration:none;color:inherit;display:block;cursor:pointer}
  .stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(20,40,80,.1);text-decoration:none}
  .stat-card .ic{font-size:1.5rem;line-height:1}
  .stat-card .num{font-size:1.85rem;font-weight:800;margin:6px 0 2px;line-height:1}
  .stat-card .lbl{font-size:.74rem;color:#6b7785;font-weight:600;letter-spacing:.2px}
  .s-total .num{color:#1c3a6e} .s-total{border-top:3px solid #1c3a6e}
  .s-ok .num{color:#1c7a47}    .s-ok{border-top:3px solid #1c7a47}
  .s-wait .num{color:#9a6b00}  .s-wait{border-top:3px solid #d9a300}
  .s-rev .num{color:#c0392b}   .s-rev{border-top:3px solid #c0392b}

  .prof-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start}
  .prof-head{display:flex;align-items:center;gap:8px;margin:0 0 4px}
  .prof-head h3{margin:0;font-size:1.05rem;color:#1c3a6e}
  .prof-sub{font-size:.8rem;color:#8a94a3;margin:0 0 16px}

  .mini-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .mini-item{display:flex;align-items:center;gap:11px;background:#f7f9fc;border:1px solid #e7ebf2;border-radius:11px;padding:12px 13px;text-decoration:none;color:inherit;transition:background .15s,border-color .15s,transform .15s}
  .mini-item:hover{background:#eef2f9;border-color:#b8c4d6;transform:translateY(-2px);text-decoration:none}
  .mini-item .mi-ic{width:38px;height:38px;flex-shrink:0;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem}
  .mini-item .mi-num{font-size:1.4rem;font-weight:800;line-height:1;color:#1c2b46}
  .mini-item .mi-lbl{font-size:.73rem;color:#6b7785;font-weight:600;line-height:1.25;margin-top:3px}
  .mi-scopus .mi-ic{background:#e6effb;color:#1c4f9c}
  .mi-sinta .mi-ic{background:#e1f3e8;color:#1c7a47}
  .mi-belum .mi-ic{background:#fef3c7;color:#9a6b00}
  .mi-noissn .mi-ic{background:#eef1f5;color:#6b7785}
  .mi-apc .mi-ic{background:#fef3c7;color:#78350f}
  .mi-issn .mi-ic{background:#fdf1d6;color:#9a6b00}

  .pie-wrap{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
  .pie{width:148px;height:148px;border-radius:50%;flex-shrink:0;position:relative}
  .pie::after{content:"";position:absolute;inset:30px;background:#fff;border-radius:50%;box-shadow:inset 0 1px 4px rgba(20,40,80,.08)}
  .pie-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:1}
  .pie-center b{font-size:1.35rem;color:#1c3a6e;line-height:1}
  .pie-center span{font-size:.66rem;color:#8a94a3;font-weight:600;margin-top:2px}
  .pie-legend{list-style:none;margin:0;padding:0;flex:1;min-width:150px}
  .pie-legend li{display:flex;align-items:center;gap:8px;font-size:.8rem;color:#46546b;padding:3px 0}
  .pie-legend i{width:11px;height:11px;border-radius:3px;flex-shrink:0}
  .pie-legend .pl-n{margin-left:auto;font-weight:700;color:#1c2b46}
  .pie-legend .pl-pct{color:#8a94a3;font-weight:600;font-size:.74rem;min-width:42px;text-align:right}

  .section-card{background:#fff;border:1px solid #e3e8ef;border-radius:13px;padding:20px;margin-bottom:20px;box-shadow:0 2px 10px rgba(20,40,80,.05)}
  .rincian-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
  .rincian-row .section-card{margin-bottom:0}
  .mini-grid-3{display:grid;grid-template-columns:1fr 1fr;gap:10px}

  @media(max-width:768px){.stat-cards{grid-template-columns:repeat(2,1fr)}.prof-grid{grid-template-columns:1fr}.mini-grid,.mini-grid-3{grid-template-columns:1fr 1fr}.rincian-row{grid-template-columns:1fr}}
  @media(max-width:480px){.stat-cards{grid-template-columns:1fr 1fr}.mini-grid,.mini-grid-3{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <h1>📊 Statistik Jurnal</h1>
  <div class="page-head-actions">
    <a href="export_dashboard.php" class="btn btn-export" title="Download XLSX lengkap">📥 Export XLSX</a>
    <a href="dashboard.php" class="btn">&larr; Dashboard</a>
  </div>
</div>

<!-- ====================== KARTU KONFIRMASI (semua jurnal) ====================== -->
<div class="stat-cards">
  <a href="dashboard.php" class="stat-card s-total">
    <div class="ic">📚</div>
    <div class="num"><?= $s_total ?></div>
    <div class="lbl">Total Jurnal</div>
  </a>
  <a href="konfirmasi_admin.php?st=belum_konfirmasi" class="stat-card s-wait">
    <div class="ic">⏳</div>
    <div class="num"><?= $s_belum_konf ?></div>
    <div class="lbl">Belum Konfirmasi</div>
  </a>
  <a href="dashboard.php?akr=all" class="stat-card s-ok">
    <div class="ic">✅</div>
    <div class="num"><?= $s_terkonf ?></div>
    <div class="lbl">Terkonfirmasi</div>
  </a>
  <a href="konfirmasi_admin.php?st=pending" class="stat-card s-rev">
    <div class="ic">🔍</div>
    <div class="num"><?= $s_review ?></div>
    <div class="lbl">Menunggu Direview</div>
  </a>
</div>

<!-- ====================== PROFIL AKREDITASI & ISSN (terkonfirmasi saja) ====================== -->
<div class="section-card">
  <div class="prof-head">
    <span style="font-size:1.15rem">📊</span>
    <h3>Profil Akreditasi &amp; ISSN</h3>
  </div>
  <p class="prof-sub">
    Data jurnal yang sudah <strong>terkonfirmasi</strong>. Klik kartu untuk melihat daftarnya di dashboard.
  </p>

  <div class="prof-grid">
    <div>
      <h4 style="margin:0 0 12px;font-size:.85rem;color:#33415c;letter-spacing:.3px;text-transform:uppercase">Rincian Data Jurnal</h4>
      <div class="mini-grid">
        <a href="dashboard.php?akr=scopus" class="mini-item mi-scopus">
          <div class="mi-ic">🌐</div>
          <div><div class="mi-num"><?= $s_scopus ?></div><div class="mi-lbl">Terindeks Scopus</div></div>
        </a>
        <a href="dashboard.php?akr=sinta" class="mini-item mi-sinta">
          <div class="mi-ic">🏅</div>
          <div><div class="mi-num"><?= $s_sinta ?></div><div class="mi-lbl">Terakreditasi SINTA</div></div>
        </a>
        <a href="dashboard.php?akr=belum" class="mini-item mi-belum">
          <div class="mi-ic">🔖</div>
          <div><div class="mi-num"><?= $s_belum_akr ?></div><div class="mi-lbl">Belum Akreditasi</div></div>
        </a>
        <a href="dashboard.php?akr=belum_issn" class="mini-item mi-noissn">
          <div class="mi-ic">📄</div>
          <div><div class="mi-num"><?= $s_belum_issn ?></div><div class="mi-lbl">Belum Memiliki ISSN</div></div>
        </a>
        <a href="dashboard.php?akr=apc" class="mini-item mi-apc">
          <div class="mi-ic">💰</div>
          <div><div class="mi-num"><?= $s_ber_apc ?></div><div class="mi-lbl">Ber-APC</div></div>
        </a>
      </div>
    </div>

    <div>
      <h4 style="margin:0 0 12px;font-size:.85rem;color:#33415c;letter-spacing:.3px;text-transform:uppercase">Rasio Peringkat Akreditasi</h4>
      <div class="pie-wrap">
        <div class="pie" style="background:conic-gradient(<?= h($cg_str) ?>)">
          <div class="pie-center">
            <b><?= $s_akred ?></b>
            <span>TERAKREDITASI</span>
          </div>
        </div>
        <ul class="pie-legend">
          <?php foreach ($pie as $seg):
            $p = $pie_total > 0 ? round($seg['n'] / $pie_total * 100, 1) : 0;
          ?>
            <li>
              <i style="background:<?= h($seg['color']) ?>"></i>
              <?= h($seg['label']) ?>
              <span class="pl-n"><?= (int)$seg['n'] ?></span>
              <span class="pl-pct"><?= $p ?>%</span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- ====================== RINCIAN SINTA & SCOPUS (1 baris) ====================== -->
<div class="rincian-row">

  <!-- SINTA -->
  <div class="section-card">
    <div class="prof-head">
      <span style="font-size:1.15rem">🏅</span>
      <h3>Peringkat SINTA</h3>
    </div>
    <div class="mini-grid-3">
      <?php for ($i = 1; $i <= 6; $i++):
        $key = "Sinta $i";
        $n = (int)($sinta_map[$key] ?? 0);
        $colors = [1=>'#1c7a47',2=>'#2bb56b',3=>'#5cc98c',4=>'#e0a91d',5=>'#e8852b',6=>'#d9603a'];
      ?>
        <a href="dashboard.php?akr=sinta&pr=<?= urlencode($key) ?>" class="mini-item">
          <div class="mi-ic" style="background:<?= $colors[$i] ?>20;color:<?= $colors[$i] ?>">S<?= $i ?></div>
          <div>
            <div class="mi-num"><?= $n ?></div>
            <div class="mi-lbl">Sinta <?= $i ?><?= $i === 1 ? ' (Scopus)' : '' ?></div>
          </div>
        </a>
      <?php endfor; ?>
    </div>
  </div>

  <!-- SCOPUS -->
  <div class="section-card">
    <div class="prof-head">
      <span style="font-size:1.15rem">🌐</span>
      <h3>Peringkat Scopus</h3>
    </div>
    <div class="mini-grid-3">
      <?php foreach (['Q1','Q2','Q3','Q4'] as $qx):
        $n = (int)($scopus_map[$qx] ?? 0);
        $colors = ['Q1'=>'#14532d','Q2'=>'#064e3b','Q3'=>'#713f12','Q4'=>'#7c2d12'];
        $bgs    = ['Q1'=>'#dcfce7','Q2'=>'#a7f3d0','Q3'=>'#fef9c3','Q4'=>'#fed7aa'];
      ?>
        <a href="dashboard.php?akr=scopus&pr=<?= urlencode($qx) ?>" class="mini-item">
          <div class="mi-ic" style="background:<?= $bgs[$qx] ?>;color:<?= $colors[$qx] ?>"><?= $qx ?></div>
          <div>
            <div class="mi-num"><?= $n ?></div>
            <div class="mi-lbl">Scopus <?= $qx ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<?php
// =========================================================
// Analitik produktivitas (3 tahun terakhir)
// =========================================================
require_once __DIR__ . '/../includes/stat_analytics.php';
[$cy, $cy1, $cy2] = stat_years();
$top    = stat_top_artikel(20);
$no_cy  = stat_tanpa_terbitan_tahun($cy);
$no_cy1 = stat_tanpa_terbitan_tahun($cy1);
$no_cy2 = stat_tanpa_terbitan_tahun($cy2);
$no_3th = stat_tanpa_terbitan_3th();

// Badge akreditasi (sinta + scopus)
function stat_badge_html($r) {
    $out = '';
    if (($r['akreditasi_jenis'] ?? '') === 'sinta' && trim((string)$r['akreditasi_peringkat']) !== '') {
        $cls = 'akr-sinta-' . preg_replace('/[^0-9]/', '', $r['akreditasi_peringkat']);
        $out .= '<span class="akr-badge ' . h($cls) . '">' . h($r['akreditasi_peringkat']) . '</span> ';
    }
    if ((int)($r['is_scopus'] ?? 0) === 1) {
        $q = trim((string)($r['scopus_q'] ?? ''));
        $out .= '<span class="akr-badge akr-scopus-blue">Scopus' . ($q !== '' ? ' ' . h($q) : '') . '</span>';
    }
    if ($out === '') $out = '<span class="akr-badge akr-belum">Belum</span>';
    return $out;
}
// Link: detail internal, portal asli (url_archive), index Sinta
function stat_links_html($r) {
    $out = '<a href="jurnal_view.php?id=' . (int)$r['id'] . '" class="btn btn-sm" title="Detail">📄</a> ';
    if (!empty($r['url_archive'])) {
        $out .= '<a href="' . h($r['url_archive']) . '" target="_blank" rel="noopener" class="btn btn-sm" title="Portal jurnal">🌐</a> ';
    }
    if (!empty($r['link_sinta'])) {
        $out .= '<a href="' . h($r['link_sinta']) . '" target="_blank" rel="noopener" class="btn btn-sm" title="Sinta">🏅</a>';
    }
    return $out;
}
?>

<div class="section-card">
  <div class="prof-head">
    <span style="font-size:1.15rem">🏆</span>
    <h3>Jurnal Paling Produktif (<?= $cy2 ?>–<?= $cy ?>)</h3>
  </div>
  <p class="prof-sub">Total artikel & terbitan dalam 3 tahun terakhir. Klik ikon: 📄 detail · 🌐 portal jurnal · 🏅 Sinta.</p>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>Jurnal</th><th>Akreditasi</th><th>Vol. Terkini</th><th class="num">Terbitan</th><th class="num">Artikel</th><th>Tautan</th></tr></thead>
    <tbody>
    <?php $no=1; foreach ($top as $r): ?>
      <tr>
        <td><?= $no++ ?></td>
        <td><a href="jurnal_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['nama_jurnal']) ?></a></td>
        <td><?= stat_badge_html($r) ?></td>
        <td class="small"><?= h(stat_cur_vol_text($r)) ?></td>
        <td class="num"><?= (int)$r['issues'] ?></td>
        <td class="num"><strong><?= (int)$r['artikel'] ?></strong></td>
        <td class="small"><?= stat_links_html($r) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($top)): ?><tr><td colspan="7" class="muted">Belum ada data terbitan 3 tahun terakhir.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php
$blocks = [
    ["Belum Ada Terbitan {$cy} (tahun berjalan)", $no_cy],
    ["Belum Ada Terbitan {$cy1}", $no_cy1],
    ["Belum Ada Terbitan {$cy2}", $no_cy2],
    ["Tidak Ada Terbitan 3 Tahun Terakhir ({$cy2}–{$cy})", $no_3th],
];
foreach ($blocks as [$judul, $rows]):
?>
<div class="section-card">
  <div class="prof-head">
    <span style="font-size:1.15rem">⚠️</span>
    <h3><?= h($judul) ?> <span class="muted" style="font-weight:400">(<?= count($rows) ?>)</span></h3>
  </div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>Jurnal</th><th>Akreditasi</th><th>Vol. Terkini</th><th>Crawl Terakhir</th><th>Tautan</th></tr></thead>
    <tbody>
    <?php $no=1; foreach ($rows as $r): ?>
      <tr>
        <td><?= $no++ ?></td>
        <td><a href="jurnal_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['nama_jurnal']) ?></a></td>
        <td><?= stat_badge_html($r) ?></td>
        <td class="small"><?= h(stat_cur_vol_text($r)) ?></td>
        <td class="small muted"><?= h($r['last_crawled_at'] ?: '—') ?></td>
        <td class="small"><?= stat_links_html($r) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="6" class="muted">Tidak ada — semua jurnal punya terbitan.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
