<?php
/**
 * /konfirmasi/index.php
 * Halaman publik: pengantar + dashboard ringkas + daftar jurnal + tombol "Konfirmasi".
 *
 * Mode:
 *  - tanpa ?token  → tampilkan daftar jurnal (read-only, info only)
 *  - ?token=XXX    → redirect ke form.php (akses langsung dari link bertoken)
 */
require_once __DIR__ . '/_konf.php';

// Akses langsung via link bertoken → lempar ke form
if (!empty($_GET['token'])) {
    $j = konf_get_jurnal_by_token($_GET['token']);
    if ($j) {
        header('Location: form.php?token=' . urlencode($_GET['token']));
        exit;
    }
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) > 100) $q = substr($q, 0, 100);

/* ------------------------------------------------------------------
 * STATISTIK GLOBAL (selalu dihitung dari seluruh data, bukan hasil cari)
 * ------------------------------------------------------------------ */

// === Reusable SQL (sama persis dg statistik.php & dashboard.php) ===
$BELUM_ISSN_SQL = "
  (p_issn IS NULL OR p_issn = '' OR LOWER(p_issn) LIKE '%x%')
  AND (e_issn IS NULL OR e_issn = '' OR LOWER(e_issn) LIKE '%x%')
";

// === Top 4 cards: hitung SEMUA jurnal ===
$stat = fetch_one(
    "SELECT
        COUNT(*)                                                   AS total,
        SUM(konfirmasi_status='terkonfirmasi')                     AS terkonfirmasi,
        SUM(konfirmasi_status='pending')                           AS pending,
        SUM(konfirmasi_status IS NULL OR konfirmasi_status='' OR konfirmasi_status='belum') AS belum
     FROM jurnals"
) ?: [];

$pending_baru = (int)(fetch_one(
    "SELECT COUNT(*) AS n FROM jurnal_baru WHERE status='pending'"
)['n'] ?? 0);

$s_total        = (int)($stat['total'] ?? 0);
$s_terkonf      = (int)($stat['terkonfirmasi'] ?? 0);
$s_pending      = (int)($stat['pending'] ?? 0);
$s_belum        = (int)($stat['belum'] ?? 0);

$s_total += $pending_baru;
$s_review = $s_pending + $pending_baru;

// === Profil Akreditasi & ISSN: hitung TERKONFIRMASI saja ===
// (konsisten dg statistik.php & dashboard.php)
$tf = fetch_one(
    "SELECT
        SUM(is_scopus=1)                                               AS scopus,
        SUM(akreditasi_jenis='sinta')                                  AS sinta,
        SUM(akreditasi_peringkat IS NOT NULL AND akreditasi_peringkat<>'') AS akreditasi,
        SUM(akreditasi_jenis IS NULL OR akreditasi_jenis='' OR akreditasi_jenis='belum') AS belum_akr,
        SUM((akreditasi_jenis IS NULL OR akreditasi_jenis='' OR akreditasi_jenis='belum')
            AND NOT ($BELUM_ISSN_SQL)) AS punya_issn_blm_akred
     FROM jurnals WHERE konfirmasi_status='terkonfirmasi'"
) ?: [];

$tf_issn = fetch_one("SELECT COUNT(*) AS n FROM jurnals WHERE konfirmasi_status='terkonfirmasi' AND $BELUM_ISSN_SQL");

$s_akred        = (int)($tf['akreditasi'] ?? 0);
$s_scopus       = (int)($tf['scopus'] ?? 0);
$s_sinta        = (int)($tf['sinta'] ?? 0);
$s_belum_akr    = (int)($tf['belum_akr'] ?? 0);
$s_issn_blm     = (int)($tf['punya_issn_blm_akred'] ?? 0);
$s_belum_issn   = (int)($tf_issn['n'] ?? 0);

// Rincian peringkat Sinta (terkonfirmasi saja)
$sinta_rows = fetch_all(
    "SELECT akreditasi_peringkat AS p, COUNT(*) AS n
       FROM jurnals
      WHERE konfirmasi_status='terkonfirmasi'
        AND akreditasi_jenis='sinta'
        AND akreditasi_peringkat IS NOT NULL AND akreditasi_peringkat<>''
      GROUP BY akreditasi_peringkat"
) ?: [];
$sinta_map = [];
foreach ($sinta_rows as $r) {
    $sinta_map[trim($r['p'])] = (int)$r['n'];
}

// Pie chart: Scopus + Sinta 1..6 + Belum Terakreditasi (sama dg statistik.php)
$pie = [
    ['label' => 'Scopus',              'n' => $s_scopus,                              'color' => '#1c4f9c'],
    ['label' => 'Sinta 1',             'n' => (int)($sinta_map['Sinta 1'] ?? 0),      'color' => '#1c7a47'],
    ['label' => 'Sinta 2',             'n' => (int)($sinta_map['Sinta 2'] ?? 0),      'color' => '#2bb56b'],
    ['label' => 'Sinta 3',             'n' => (int)($sinta_map['Sinta 3'] ?? 0),      'color' => '#5cc98c'],
    ['label' => 'Sinta 4',             'n' => (int)($sinta_map['Sinta 4'] ?? 0),      'color' => '#e0a91d'],
    ['label' => 'Sinta 5',             'n' => (int)($sinta_map['Sinta 5'] ?? 0),      'color' => '#e8852b'],
    ['label' => 'Sinta 6',             'n' => (int)($sinta_map['Sinta 6'] ?? 0),      'color' => '#d9603a'],
    ['label' => 'Belum Akreditasi',    'n' => $s_belum_akr,                            'color' => '#aab3c0'],
];

/* ------------------------------------------------------------------
 * DAFTAR JURNAL (tabel jurnals + pengajuan jurnal_baru yang pending)
 * Dengan paginasi: default 15 jurnal per halaman.
 * Mendukung pencarian nama (q) + filter status (f).
 * ------------------------------------------------------------------ */
define('KONF_PER_PAGE', 15);

$page = max(1, (int)($_GET['page'] ?? 1));

// Filter status: '' = semua, 'terkonfirmasi', 'belum'
$f = $_GET['f'] ?? '';
if (!in_array($f, ['terkonfirmasi', 'belum'], true)) $f = '';

/* Susun query dasar.
 * - Tabel jurnals: punya kolom konfirmasi_status (belum/pending/terkonfirmasi).
 * - Tabel jurnal_baru pending: dianggap status 'pending' (belum terkonfirmasi).
 * Filter:
 *   terkonfirmasi -> hanya jurnals dengan konfirmasi_status='terkonfirmasi'
 *   belum         -> jurnals yg BELUM terkonfirmasi (belum/pending) + jurnal_baru pending
 */
$cond_jurnals    = [];   // kondisi tambahan utk tabel jurnals
$cond_jurnalbaru = [];   // kondisi tambahan utk tabel jurnal_baru
$inc_jurnalbaru  = true; // apakah baris jurnal_baru ikut ditampilkan
$types = '';
$args  = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $cond_jurnals[]    = 'nama_jurnal LIKE ?';
    $cond_jurnalbaru[] = 'nama_jurnal LIKE ?';
}

if ($f === 'terkonfirmasi') {
    $cond_jurnals[]  = "konfirmasi_status = 'terkonfirmasi'";
    $inc_jurnalbaru  = false;          // jurnal baru pasti belum terkonfirmasi
} elseif ($f === 'belum') {
    $cond_jurnals[]  = "(konfirmasi_status IS NULL OR konfirmasi_status <> 'terkonfirmasi')";
    $inc_jurnalbaru  = true;
}

$where_j  = $cond_jurnals    ? ('WHERE ' . implode(' AND ', $cond_jurnals))    : '';
$where_jb_extra = $cond_jurnalbaru ? (' AND ' . implode(' AND ', $cond_jurnalbaru)) : '';

$sql_jurnals =
    "SELECT id, nama_jurnal, unit_kerja, konfirmasi_status, 0 AS is_baru
       FROM jurnals
       $where_j";

$sql_jurnalbaru =
    "SELECT id, nama_jurnal, unit_kerja, 'pending' AS konfirmasi_status, 1 AS is_baru
       FROM jurnal_baru
      WHERE status = 'pending'" . $where_jb_extra;

if ($inc_jurnalbaru) {
    $base_sql = $sql_jurnals . " UNION ALL " . $sql_jurnalbaru;
} else {
    $base_sql = $sql_jurnals;
}

/* Susun parameter sesuai urutan kemunculan '?' di base_sql.
 * Urutan: kondisi jurnals dulu (q), lalu kondisi jurnal_baru (q). */
if ($q !== '') {
    $types  .= 's';  $args[] = $like;                 // utk jurnals
    if ($inc_jurnalbaru) { $types .= 's'; $args[] = $like; }  // utk jurnal_baru
}

// Total baris (untuk hitung jumlah halaman)
$row_cnt = fetch_one(
    "SELECT COUNT(*) AS n FROM ( $base_sql ) AS t",
    $types, $args
);
$total_rows  = (int)($row_cnt['n'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / KONF_PER_PAGE));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * KONF_PER_PAGE;

// Ambil satu halaman data
$page_sql  = $base_sql . " ORDER BY nama_jurnal ASC LIMIT ? OFFSET ?";
$jurnals   = fetch_all(
    $page_sql,
    $types . 'ii',
    array_merge($args, [KONF_PER_PAGE, $offset])
);

// Helper: bangun URL halaman dengan tetap mempertahankan q & filter
function konf_page_url($p, $q, $f = '') {
    $u = 'index.php?page=' . (int)$p;
    if ($q !== '') $u .= '&q=' . urlencode($q);
    if ($f !== '') $u .= '&f=' . urlencode($f);
    return $u;
}

$status_label = [
    'belum'          => ['Belum Konfirmasi', 'st-belum'],
    'pending'        => ['Menunggu Review',  'st-pending'],
    'terkonfirmasi'  => ['Terkonfirmasi',    'st-terkonfirmasi'],
];

konf_header('Konfirmasi Data Jurnal');
?>
<style>
  /* ---- Lebarkan halaman index ini saja (override _konf.php) ---- */
  .konf-wrap{max-width:1140px}

  /* ---- Pengantar ---- */
  .konf-intro{background:linear-gradient(135deg,#1c3a6e 0%,#264f96 60%,#2f63b8 100%);
              border:none;color:#fff;position:relative;overflow:hidden}
  .konf-intro::after{content:"";position:absolute;right:-40px;top:-40px;width:180px;
              height:180px;background:rgba(255,255,255,.07);border-radius:50%}
  .konf-intro::before{content:"";position:absolute;right:60px;bottom:-70px;width:150px;
              height:150px;background:rgba(255,255,255,.05);border-radius:50%}
  .konf-intro h2{color:#fff;margin:0 0 4px;font-size:1.16rem;letter-spacing:.3px}
  .konf-intro .konf-intro-sub{color:#cfe0ff;font-weight:600;font-size:.92rem;margin-bottom:14px}
  .konf-intro p{color:#e7eefc;font-size:.92rem;line-height:1.7;margin:0 0 10px;position:relative}
  .konf-intro .konf-sign{color:#fff;font-weight:600;margin-top:14px}

  /* ---- Kartu statistik ---- */
  .konf-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
  .konf-stat{background:#fff;border:1px solid #e3e8ef;border-radius:13px;padding:16px 14px;
             text-align:center;box-shadow:0 2px 10px rgba(20,40,80,.05);
             transition:transform .15s ease,box-shadow .15s ease}
  .konf-stat:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(20,40,80,.1)}
  .konf-stat .ic{font-size:1.5rem;line-height:1}
  .konf-stat .num{font-size:1.85rem;font-weight:800;margin:6px 0 2px;line-height:1}
  .konf-stat .lbl{font-size:.74rem;color:#6b7785;font-weight:600;letter-spacing:.2px}
  .konf-stat.s-total .num{color:#1c3a6e}
  .konf-stat.s-ok    .num{color:#1c7a47}
  .konf-stat.s-wait  .num{color:#9a6b00}
  .konf-stat.s-rev   .num{color:#c0392b}
  .konf-stat.s-total{border-top:3px solid #1c3a6e}
  .konf-stat.s-ok   {border-top:3px solid #1c7a47}
  .konf-stat.s-wait {border-top:3px solid #d9a300}
  .konf-stat.s-rev  {border-top:3px solid #c0392b}

  /* ---- Panel "Profil Akreditasi & ISSN" : 2 kolom ---- */
  .konf-prof-head{display:flex;align-items:center;gap:8px;margin:0 0 4px}
  .konf-prof-head h3{margin:0;font-size:1.05rem;color:#1c3a6e}
  .konf-prof-sub{font-size:.8rem;color:#8a94a3;margin:0 0 16px}
  .konf-prof-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start}
  .konf-prof-grid h4{margin:0 0 12px;font-size:.85rem;color:#33415c;
                     letter-spacing:.3px;text-transform:uppercase}

  /* kartu rincian (kolom kiri) */
  .konf-mini{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .konf-mini-item{display:flex;align-items:center;gap:11px;
                  background:#f7f9fc;border:1px solid #e7ebf2;border-radius:11px;
                  padding:12px 13px}
  .konf-mini-item .mi-ic{width:38px;height:38px;flex-shrink:0;border-radius:9px;
                  display:flex;align-items:center;justify-content:center;font-size:1.1rem}
  .konf-mini-item .mi-num{font-size:1.4rem;font-weight:800;line-height:1;color:#1c2b46}
  .konf-mini-item .mi-lbl{font-size:.73rem;color:#6b7785;font-weight:600;
                  line-height:1.25;margin-top:3px}
  .mi-scopus  .mi-ic{background:#e6effb;color:#1c4f9c}
  .mi-akred   .mi-ic{background:#e1f3e8;color:#1c7a47}
  .mi-issn    .mi-ic{background:#fdf1d6;color:#9a6b00}
  .mi-noissn  .mi-ic{background:#eef1f5;color:#6b7785}

  /* pie chart (kolom kanan) */
  .konf-pie-wrap{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
  .konf-pie{width:148px;height:148px;border-radius:50%;flex-shrink:0;
            position:relative}
  .konf-pie::after{content:"";position:absolute;inset:30px;background:#fff;
            border-radius:50%;box-shadow:inset 0 1px 4px rgba(20,40,80,.08)}
  .konf-pie-center{position:absolute;inset:0;display:flex;flex-direction:column;
            align-items:center;justify-content:center;z-index:1}
  .konf-pie-center b{font-size:1.35rem;color:#1c3a6e;line-height:1}
  .konf-pie-center span{font-size:.66rem;color:#8a94a3;font-weight:600;margin-top:2px}
  .konf-pie-legend{list-style:none;margin:0;padding:0;flex:1;min-width:150px}
  .konf-pie-legend li{display:flex;align-items:center;gap:8px;
            font-size:.8rem;color:#46546b;padding:3px 0}
  .konf-pie-legend i{width:11px;height:11px;border-radius:3px;flex-shrink:0}
  .konf-pie-legend .pl-n{margin-left:auto;font-weight:700;color:#1c2b46}
  .konf-pie-legend .pl-pct{color:#8a94a3;font-weight:600;font-size:.74rem;
            min-width:42px;text-align:right}

  /* ---- CTA daftar ---- */
  .konf-list-head{display:flex;align-items:center;justify-content:space-between;
                  gap:10px;flex-wrap:wrap;margin-bottom:6px}
  .konf-list-head h3{margin:0;font-size:1.05rem;color:#1c3a6e}

  /* ---- Daftar kontak WA ---- */
  .konf-wa{list-style:none;margin:10px 0 4px;padding:0;
           display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
  .konf-wa li{margin:0}
  .konf-wa a{display:flex;align-items:center;gap:9px;text-decoration:none;
             background:#f1f8f3;border:1px solid #cde8d6;border-radius:9px;
             padding:8px 11px;transition:background .15s ease,border-color .15s ease}
  .konf-wa a:hover{background:#e3f3e9;border-color:#9ed4b3}
  .konf-wa .wa-ic{width:26px;height:26px;flex-shrink:0;border-radius:50%;
                  background:#25d366;color:#fff;font-size:.85rem;font-weight:700;
                  display:flex;align-items:center;justify-content:center}
  .konf-wa .wa-info{line-height:1.3;min-width:0}
  .konf-wa .wa-nama{font-size:.85rem;font-weight:600;color:#1c2b46}
  .konf-wa .wa-nomor{font-size:.78rem;color:#1c7a47;font-weight:600}

  /* ---- Paginasi ---- */
  .konf-pager{display:flex;justify-content:center;align-items:center;
              gap:6px;flex-wrap:wrap;margin-top:18px}
  .konf-pager a,.konf-pager span{min-width:34px;height:34px;padding:0 9px;
              display:flex;align-items:center;justify-content:center;
              border-radius:8px;font-size:.85rem;font-weight:600;
              text-decoration:none;border:1px solid #d5dde8}
  .konf-pager a{color:#1c3a6e;background:#fff;transition:background .15s ease}
  .konf-pager a:hover{background:#eef2f9}
  .konf-pager .pg-cur{background:#1c3a6e;color:#fff;border-color:#1c3a6e}
  .konf-pager .pg-dis{color:#b3bcc8;background:#f5f7fa;border-color:#e3e8ef}
  .konf-pager .pg-gap{border:none;background:none;color:#8a94a3}

  /* ---- Filter status pills ---- */
  .konf-filter{display:flex;align-items:center;gap:8px;flex-wrap:wrap;
               margin-bottom:14px}
  .konf-filter-label{font-size:.82rem;font-weight:600;color:#6b7785;
                     margin-right:2px}
  .konf-fpill{display:inline-flex;align-items:center;padding:6px 14px;
              border-radius:20px;font-size:.83rem;font-weight:600;
              text-decoration:none;border:1px solid #d5dde8;
              color:#46546b;background:#fff;transition:all .15s ease}
  .konf-fpill:hover{background:#eef2f9}
  .konf-fpill.active{background:#1c3a6e;color:#fff;border-color:#1c3a6e}
  .konf-fpill-ok.active{background:#1c7a47;border-color:#1c7a47}
  .konf-fpill-wait.active{background:#9a6b00;border-color:#9a6b00}

  @media(max-width:640px){
    .konf-stats{grid-template-columns:repeat(2,1fr)}
    .konf-wa{grid-template-columns:1fr}
    .konf-prof-grid{grid-template-columns:1fr}
    .konf-mini{grid-template-columns:1fr 1fr}
  }
</style>

  <!-- ====================== PENGANTAR ====================== -->
  <div class="konf-card konf-intro">
    <h2>Yth. Para Pengelola Jurnal</h2>
    <div class="konf-intro-sub">Universitas Jenderal Soedirman</div>
    <p>
      Mohon perwakilan pengelola jurnal berkenan untuk konfirmasi data
      jurnal berikut ini. Saat ini pengelolaan jurnal di Unsoed dilayani
      oleh <strong>Puskor Pengelolaan Jurnal</strong> di LPPM. Berdasarkan
      konfirmasi data berikut ini, kami berharap dapat merencanakan
      kegiatan pengelolaan jurnal yang lebih tepat sasaran sehingga dapat
      meningkatkan kualitas jurnal di Unsoed. Mohon dukungan dan
      kerjasamanya.
    </p>
    <div class="konf-sign">Terima kasih.</div>
  </div>

  <!-- ====================== KARTU STATISTIK ====================== -->
  <div class="konf-stats">
    <div class="konf-stat s-total">
      <div class="ic">📚</div>
      <div class="num"><?= $s_total ?></div>
      <div class="lbl">Total Jurnal</div>
    </div>
    <div class="konf-stat s-wait">
      <div class="ic">⏳</div>
      <div class="num"><?= $s_belum ?></div>
      <div class="lbl">Belum Konfirmasi</div>
    </div>
    <div class="konf-stat s-ok">
      <div class="ic">✅</div>
      <div class="num"><?= $s_terkonf ?></div>
      <div class="lbl">Terkonfirmasi</div>
    </div>
    <div class="konf-stat s-rev">
      <div class="ic">🔍</div>
      <div class="num"><?= $s_review ?></div>
      <div class="lbl">Menunggu Direview</div>
    </div>
  </div>

  <!-- ====================== PROFIL AKREDITASI & ISSN ====================== -->
  <?php
    // Hitung sudut pie (conic-gradient). Segmen kosong dilewati.
    $pie_total = 0;
    foreach ($pie as $seg) $pie_total += $seg['n'];
    $cg = [];           // potongan conic-gradient
    $acc = 0;           // akumulasi persen
    foreach ($pie as $seg) {
        if ($seg['n'] <= 0) continue;
        $p   = $pie_total > 0 ? $seg['n'] / $pie_total * 100 : 0;
        $from = round($acc, 3);
        $acc += $p;
        $to   = round($acc, 3);
        $cg[] = $seg['color'] . ' ' . $from . '% ' . $to . '%';
    }
    $cg_str = $cg ? implode(',', $cg) : '#eef1f5 0% 100%';
  ?>
  <div class="konf-card">
    <div class="konf-prof-head">
      <span style="font-size:1.15rem">📊</span>
      <h3>Profil Akreditasi &amp; ISSN</h3>
    </div>
    <p class="konf-prof-sub">
      Gambaran kondisi jurnal Unsoed yang sudah <strong>terkonfirmasi</strong>
      — angka akan terus diperbarui seiring masuknya konfirmasi data.
    </p>

    <div class="konf-prof-grid">

      <!-- (1) Rincian data jurnal -->
      <div>
        <h4>Rincian Data Jurnal</h4>
        <div class="konf-mini">
          <div class="konf-mini-item mi-scopus">
            <div class="mi-ic">🌐</div>
            <div>
              <div class="mi-num"><?= $s_scopus ?></div>
              <div class="mi-lbl">Terindeks Scopus</div>
            </div>
          </div>
          <div class="konf-mini-item mi-akred">
            <div class="mi-ic">🏅</div>
            <div>
              <div class="mi-num"><?= $s_akred ?></div>
              <div class="mi-lbl">Terakreditasi SINTA</div>
            </div>
          </div>
          <div class="konf-mini-item mi-issn">
            <div class="mi-ic">🔖</div>
            <div>
              <div class="mi-num"><?= $s_issn_blm ?></div>
              <div class="mi-lbl">Ber-ISSN, Belum Akreditasi</div>
            </div>
          </div>
          <div class="konf-mini-item mi-noissn">
            <div class="mi-ic">📄</div>
            <div>
              <div class="mi-num"><?= $s_belum_issn ?></div>
              <div class="mi-lbl">Belum Memiliki ISSN</div>
            </div>
          </div>
        </div>
      </div>

      <!-- (2) Pie chart rasio peringkat akreditasi -->
      <div>
        <h4>Rasio Peringkat Akreditasi</h4>
        <div class="konf-pie-wrap">
          <div class="konf-pie"
               style="background:conic-gradient(<?= h($cg_str) ?>)">
            <div class="konf-pie-center">
              <b><?= $s_akred ?></b>
              <span>TERAKREDITASI</span>
            </div>
          </div>
          <ul class="konf-pie-legend">
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

  <!-- ====================== DAFTAR JURNAL ====================== -->
  <div class="konf-card">
    <div class="konf-list-head">
      <h3>Daftar Jurnal</h3>
      <a href="jurnal_baru.php" class="btn btn-primary btn-sm">+ Tambah Jurnal Baru</a>
    </div>
    <p style="margin:0 0 6px;color:#46546b;font-size:.88rem;line-height:1.6">
      Klik tombol <em>Konfirmasi</em> pada baris jurnal Anda, lalu masukkan
      <strong>kode akses</strong> yang dapat diminta melalui japri salah satu
      nomor berikut:
    </p>
    <ul class="konf-wa">
      <li>
        <a href="https://wa.me/6289534129503" target="_blank" rel="noopener">
          <span class="wa-ic">WA</span>
          <span class="wa-info">
            <span class="wa-nama">Dr. Ir. Mulki Indana Zulfa</span><br>
            <span class="wa-nomor">0895-3412-95031</span>
          </span>
        </a>
      </li>
      <li>
        <a href="https://wa.me/6281383819698" target="_blank" rel="noopener">
          <span class="wa-ic">WA</span>
          <span class="wa-info">
            <span class="wa-nama">Dr. Ir. Ari Fadli</span><br>
            <span class="wa-nomor">0813-8381-9698</span>
          </span>
        </a>
      </li>
      <li>
        <a href="https://wa.me/6285726431144" target="_blank" rel="noopener">
          <span class="wa-ic">WA</span>
          <span class="wa-info">
            <span class="wa-nama">Galih Noor Alivian, M.Kep</span><br>
            <span class="wa-nomor">0857-2643-1144</span>
          </span>
        </a>
      </li>
      <li>
        <a href="https://wa.me/6287733466600" target="_blank" rel="noopener">
          <span class="wa-ic">WA</span>
          <span class="wa-info">
            <span class="wa-nama">Anzar Alfat Firdaus, M.E.</span><br>
            <span class="wa-nomor">0877-3346-6600</span>
          </span>
        </a>
      </li>
    </ul>
    <p style="margin:8px 0 14px;color:#46546b;font-size:.88rem;line-height:1.6">
      Jika jurnal Anda <strong>belum terdaftar</strong>, gunakan tombol
      <em>"Tambah Jurnal Baru"</em> untuk menambahkannya.
    </p>

    <form method="get" action="index.php"
          style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
      <input type="text" name="q" class="konf-search"
             placeholder="🔍 Cari nama jurnal…" value="<?= h($q) ?>"
             autocomplete="off" style="flex:1;min-width:200px;margin-bottom:0">
      <?php if ($f !== ''): ?>
        <input type="hidden" name="f" value="<?= h($f) ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Cari</button>
      <?php if ($q !== '' || $f !== ''): ?>
        <a href="index.php" class="btn">× Reset</a>
      <?php endif; ?>
    </form>

    <!-- Filter status -->
    <div class="konf-filter">
      <span class="konf-filter-label">Filter:</span>
      <a href="<?= h(konf_page_url(1, $q, '')) ?>"
         class="konf-fpill <?= $f === '' ? 'active' : '' ?>">Semua</a>
      <a href="<?= h(konf_page_url(1, $q, 'terkonfirmasi')) ?>"
         class="konf-fpill konf-fpill-ok <?= $f === 'terkonfirmasi' ? 'active' : '' ?>">
         Terkonfirmasi</a>
      <a href="<?= h(konf_page_url(1, $q, 'belum')) ?>"
         class="konf-fpill konf-fpill-wait <?= $f === 'belum' ? 'active' : '' ?>">
         Belum Konfirmasi</a>
    </div>

    <?php if (empty($jurnals)): ?>
      <p style="color:#8a94a3;text-align:center;padding:20px">
        <?php if ($q !== '' || $f !== ''): ?>
          Tidak ada jurnal yang cocok dengan filter
          <?php if ($q !== ''): ?>/ pencarian "<strong><?= h($q) ?></strong>"<?php endif; ?>.<br>
          <span style="font-size:.85rem">Coba ubah filter, atau jika jurnal Anda
          belum terdaftar klik <em>+ Tambah Jurnal Baru</em> di atas.</span>
        <?php else: ?>
          Belum ada jurnal terdaftar.
        <?php endif; ?>
      </p>
    <?php else: ?>
      <ul class="konf-jlist">
        <?php foreach ($jurnals as $j):
          $st  = $j['konfirmasi_status'] ?: 'belum';
          [$lbl, $cls] = $status_label[$st] ?? $status_label['belum'];
        ?>
          <li>
            <div>
              <div class="konf-jname"><?= h($j['nama_jurnal']) ?></div>
              <?php if (!empty($j['unit_kerja'])): ?>
                <div class="konf-jmeta"><?= h($j['unit_kerja']) ?></div>
              <?php endif; ?>
              <?php if (!empty($j['is_baru'])): ?>
                <div class="konf-jmeta" style="color:#9a6b00">
                  Jurnal baru &mdash; menunggu persetujuan admin
                </div>
              <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
              <span class="konf-status <?= h($cls) ?>"><?= h($lbl) ?></span>
              <?php if (empty($j['is_baru'])): ?>
                <a href="akses.php?id=<?= (int)$j['id'] ?>" class="btn btn-sm btn-primary">
                  Konfirmasi
                </a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="konf-jmeta" style="margin-top:14px">
        Menampilkan <?= count($jurnals) ?> dari <?= $total_rows ?> jurnal<?php
          $ket = [];
          if ($f === 'terkonfirmasi') $ket[] = 'terkonfirmasi';
          if ($f === 'belum')         $ket[] = 'belum konfirmasi';
          if ($q !== '')              $ket[] = 'hasil pencarian';
          if ($ket) echo ' (' . implode(', ', $ket) . ')';
        ?>
        &middot; Halaman <?= $page ?> / <?= $total_pages ?>.
      </p>

      <?php if ($total_pages > 1): ?>
        <nav class="konf-pager" aria-label="Navigasi halaman">
          <?php
            // Tombol Sebelumnya
            if ($page > 1) {
                echo '<a href="' . h(konf_page_url($page - 1, $q, $f)) . '">&laquo;</a>';
            } else {
                echo '<span class="pg-dis">&laquo;</span>';
            }

            // Rentang nomor halaman yang ditampilkan (maks 5 di sekitar halaman aktif)
            $win   = 2;
            $start = max(1, $page - $win);
            $end   = min($total_pages, $page + $win);

            if ($start > 1) {
                echo '<a href="' . h(konf_page_url(1, $q, $f)) . '">1</a>';
                if ($start > 2) echo '<span class="pg-gap">…</span>';
            }
            for ($p = $start; $p <= $end; $p++) {
                if ($p == $page) {
                    echo '<span class="pg-cur">' . $p . '</span>';
                } else {
                    echo '<a href="' . h(konf_page_url($p, $q, $f)) . '">' . $p . '</a>';
                }
            }
            if ($end < $total_pages) {
                if ($end < $total_pages - 1) echo '<span class="pg-gap">…</span>';
                echo '<a href="' . h(konf_page_url($total_pages, $q, $f)) . '">' . $total_pages . '</a>';
            }

            // Tombol Berikutnya
            if ($page < $total_pages) {
                echo '<a href="' . h(konf_page_url($page + 1, $q, $f)) . '">&raquo;</a>';
            } else {
                echo '<span class="pg-dis">&raquo;</span>';
            }
          ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php konf_footer(); ?>
