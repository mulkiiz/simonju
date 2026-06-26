<?php
$page_title = 'Cron Health';
require_once __DIR__ . '/../includes/header_admin.php';

// --- Ringkasan cron crawler (trigger_type='cron') ---
$last_cron = fetch_one(
    "SELECT MAX(executed_at) AS t FROM crawl_log WHERE trigger_type='cron'"
);
$last_cron_at = $last_cron['t'] ?? null;

// Hitung jeda dari run cron terakhir
$gap_txt = '—'; $gap_cls = 'muted';
if ($last_cron_at) {
    $mins = (int)round((time() - strtotime($last_cron_at)) / 60);
    if ($mins < 90)        { $gap_txt = "{$mins} menit lalu";  $gap_cls = 'badge-success'; }
    elseif ($mins < 1500)  { $gap_txt = round($mins/60,1)." jam lalu"; $gap_cls = 'badge-partial'; }
    else                   { $gap_txt = round($mins/1440,1)." hari lalu"; $gap_cls = 'badge-failed'; }
}

// Statistik cron 24 jam terakhir
$d1 = fetch_one(
    "SELECT
        COUNT(*) AS total,
        SUM(status='success') AS ok,
        SUM(status='partial') AS partial,
        SUM(status='failed')  AS failed
     FROM crawl_log
     WHERE trigger_type='cron' AND executed_at >= (NOW() - INTERVAL 24 HOUR)"
) ?: [];

// Jurnal belum pernah crawl + paling basi
$never = (int)(fetch_one("SELECT COUNT(*) AS n FROM jurnals WHERE last_crawled_at IS NULL")['n'] ?? 0);
$oldest = fetch_one(
    "SELECT id, nama_jurnal, last_crawled_at
       FROM jurnals
      WHERE last_crawled_at IS NOT NULL
      ORDER BY last_crawled_at ASC LIMIT 1"
);
$total_jurnal = (int)(fetch_one("SELECT COUNT(*) AS n FROM jurnals")['n'] ?? 0);

// Daftar antrian berikutnya (paling basi dulu — sama logika cron)
$queue = fetch_all(
    "SELECT id, nama_jurnal, last_crawled_at, last_crawl_status
       FROM jurnals
      ORDER BY (last_crawled_at IS NOT NULL), last_crawled_at ASC, id ASC
      LIMIT 10"
);

// Log cron terbaru
$logs = fetch_all(
    "SELECT cl.*, j.nama_jurnal
       FROM crawl_log cl
       LEFT JOIN jurnals j ON j.id = cl.jurnal_id
      WHERE cl.trigger_type='cron'
      ORDER BY cl.executed_at DESC LIMIT 30"
);

// --- Sync klien (rju) dari sync_log (kosong di ppj — wajar) ---
$last_sync   = fetch_one("SELECT * FROM sync_log ORDER BY run_at DESC LIMIT 1");
$sync_recent = fetch_all("SELECT * FROM sync_log ORDER BY run_at DESC LIMIT 10");
$sync_gap_txt = '—'; $sync_gap_cls = 'muted'; $sync_mins = null;
if ($last_sync) {
    $sync_mins = (int)round((time() - strtotime($last_sync['run_at'])) / 60);
    if ($sync_mins < 1500)      { $sync_gap_txt = $sync_mins < 90 ? "{$sync_mins} menit lalu" : round($sync_mins/60,1)." jam lalu"; $sync_gap_cls = 'badge-success'; }
    elseif ($sync_mins < 2880)  { $sync_gap_txt = round($sync_mins/60,1)." jam lalu"; $sync_gap_cls = 'badge-partial'; }
    else                        { $sync_gap_txt = round($sync_mins/1440,1)." hari lalu"; $sync_gap_cls = 'badge-failed'; }
}
?>
<style>
  .ch-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
  .ch-card{background:#fff;border:1px solid #e3e8ef;border-radius:12px;padding:16px;text-align:center}
  .ch-card .num{font-size:1.6rem;font-weight:800;margin:4px 0;line-height:1}
  .ch-card .lbl{font-size:.74rem;color:#6b7785;font-weight:600}
  @media(max-width:768px){.ch-cards{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="page-head">
  <h1>🩺 Cron Health</h1>
  <div class="page-head-actions">
    <a href="crawl_log.php" class="btn">📝 Log lengkap</a>
    <a href="dashboard.php" class="btn">&larr; Dashboard</a>
  </div>
</div>

<div class="ch-cards">
  <div class="ch-card">
    <div class="lbl">Cron terakhir</div>
    <div class="num"><span class="badge <?= $gap_cls ?>" style="font-size:.9rem"><?= h($gap_txt) ?></span></div>
    <div class="muted small"><?= h($last_cron_at ?: 'belum ada') ?></div>
  </div>
  <div class="ch-card">
    <div class="lbl">Run cron 24 jam</div>
    <div class="num"><?= (int)($d1['total'] ?? 0) ?></div>
    <div class="muted small">
      ✅ <?= (int)($d1['ok'] ?? 0) ?> &middot;
      ⚠️ <?= (int)($d1['partial'] ?? 0) ?> &middot;
      ❌ <?= (int)($d1['failed'] ?? 0) ?>
    </div>
  </div>
  <div class="ch-card">
    <div class="lbl">Belum pernah crawl</div>
    <div class="num" style="color:<?= $never>0?'#c0392b':'#1c7a47' ?>"><?= $never ?></div>
    <div class="muted small">dari <?= $total_jurnal ?> jurnal</div>
  </div>
  <div class="ch-card">
    <div class="lbl">Jurnal paling basi</div>
    <div class="num"><?= $oldest ? h(date('d/m H:i', strtotime($oldest['last_crawled_at']))) : '—' ?></div>
    <div class="muted small"><?= $oldest ? h(mb_substr($oldest['nama_jurnal'],0,22)) : '' ?></div>
  </div>
</div>

<?php if (!$last_cron_at): ?>
  <div class="alert alert-error">Belum ada run cron sama sekali. Pastikan cron cpanel aktif: <code>cron_crawl.php?token=...&amp;batch=4</code> tiap jam.</div>
<?php elseif (isset($mins) && $mins > 1500): ?>
  <div class="alert alert-error">Cron terakhir &gt; 24 jam lalu — kemungkinan cron mati. Cek cron cpanel.</div>
<?php endif; ?>

<h2>📥 Antrian berikutnya (paling basi dulu)</h2>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Jurnal</th><th>Terakhir crawl</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($queue as $q): ?>
    <tr>
      <td><a href="jurnal_view.php?id=<?= (int)$q['id'] ?>"><?= h($q['nama_jurnal']) ?></a></td>
      <td class="small"><?= $q['last_crawled_at'] ? h($q['last_crawled_at']) : '<span class="badge badge-failed">belum pernah</span>' ?></td>
      <td><?php if ($q['last_crawl_status']): ?><span class="badge badge-<?= h($q['last_crawl_status']) ?>"><?= h($q['last_crawl_status']) ?></span><?php else: ?>—<?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2 style="margin-top:22px">🕐 Log cron terbaru (30)</h2>
<?php if (empty($logs)): ?>
  <p class="muted">Belum ada aktivitas cron.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Waktu</th><th>Jurnal</th><th>Status</th><th class="num">Found</th><th class="num">New</th><th>Pesan</th></tr></thead>
  <tbody>
  <?php foreach ($logs as $l): ?>
    <tr>
      <td class="small"><?= h($l['executed_at']) ?></td>
      <td><?php if ($l['jurnal_id']): ?><a href="jurnal_view.php?id=<?= (int)$l['jurnal_id'] ?>"><?= h($l['nama_jurnal'] ?: '#'.$l['jurnal_id']) ?></a><?php else: ?>—<?php endif; ?></td>
      <td><span class="badge badge-<?= h($l['status']) ?>"><?= h($l['status']) ?></span></td>
      <td class="num"><?= (int)$l['issues_found'] ?></td>
      <td class="num"><?= (int)$l['issues_new'] ?></td>
      <td class="small"><?= h(mb_substr($l['message'] ?? '', 0, 90)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2 style="margin-top:26px">🔄 Sync Klien (rju.unsoed)</h2>
<p class="muted small">Diisi saat <code>cron/run.php</code> dijalankan (cron-job.org → tarik data dari ppj). Kosong di ppj — wajar.</p>

<?php if (!$last_sync): ?>
  <div class="alert alert-info">Belum ada catatan sync. Di <strong>ppj</strong> ini normal (ppj sumber data, bukan klien). Di <strong>rju</strong> berarti <code>cron/run.php</code> belum pernah jalan / tabel <code>sync_log</code> belum dibuat.</div>
<?php else: ?>
  <div class="ch-cards">
    <div class="ch-card">
      <div class="lbl">Sync terakhir</div>
      <div class="num"><span class="badge <?= $sync_gap_cls ?>" style="font-size:.9rem"><?= h($sync_gap_txt) ?></span></div>
      <div class="muted small"><?= h($last_sync['run_at']) ?></div>
    </div>
    <div class="ch-card">
      <div class="lbl">Status terakhir</div>
      <div class="num"><span class="badge badge-<?= $last_sync['status']==='success'?'success':'failed' ?>" style="font-size:.85rem"><?= h($last_sync['status']) ?></span></div>
    </div>
    <div class="ch-card">
      <div class="lbl">Jurnal (baru / update)</div>
      <div class="num"><?= (int)$last_sync['jurnal_baru'] ?> / <?= (int)$last_sync['jurnal_update'] ?></div>
    </div>
    <div class="ch-card">
      <div class="lbl">Terbitan diupsert</div>
      <div class="num"><?= (int)$last_sync['terbitan_upsert'] ?></div>
    </div>
  </div>

  <?php if ($sync_mins !== null && $sync_mins > 2880): ?>
    <div class="alert alert-error">Sync terakhir &gt; 2 hari lalu — cek cron-job.org / <code>cron/run.php</code> di rju.</div>
  <?php endif; ?>

  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Waktu</th><th>Status</th><th class="num">Baru</th><th class="num">Update</th><th class="num">Terbitan</th><th>Pesan</th></tr></thead>
    <tbody>
    <?php foreach ($sync_recent as $s): ?>
      <tr>
        <td class="small"><?= h($s['run_at']) ?></td>
        <td><span class="badge badge-<?= $s['status']==='success'?'success':'failed' ?>"><?= h($s['status']) ?></span></td>
        <td class="num"><?= (int)$s['jurnal_baru'] ?></td>
        <td class="num"><?= (int)$s['jurnal_update'] ?></td>
        <td class="num"><?= (int)$s['terbitan_upsert'] ?></td>
        <td class="small"><?= h($s['message']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
