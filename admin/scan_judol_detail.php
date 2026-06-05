<?php
$page_title = 'Detail Scan Judol';
$body_class = 'theme-scanner';
require_once __DIR__ . '/../includes/header_admin.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: scan_judol_log.php'); exit; }

$row = fetch_one("
    SELECT l.*, j.nama_jurnal, j.url_archive
    FROM judol_scan_log l
    JOIN jurnals j ON j.id = l.jurnal_id
    WHERE l.id = ?
", 'i', [$id]);

if (!$row) {
    echo '<div class="alert alert-error">Hasil scan tidak ditemukan.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$detail = json_decode($row['detail_json'] ?? '{}', true) ?: [];
$normal = $detail['normal']  ?? [];
$bot    = $detail['bot']     ?? [];
$cloak  = $detail['cloaking']?? [];
$risk   = $detail['risk']    ?? [];

function fmt_kw_list($arr) {
    if (empty($arr)) return '<span class="muted">—</span>';
    $items = [];
    foreach ($arr as $kw => $cnt) {
        $items[] = h($kw) . ' <span class="muted small">×' . (int)$cnt . '</span>';
    }
    return implode(', ', $items);
}
?>
<div class="page-head">
  <h1>Detail Scan Judol</h1>
  <a href="scan_judol_log.php" class="btn">&laquo; Kembali</a>
</div>

<div class="card">
  <h3>Jurnal</h3>
  <dl>
    <dt>Nama:</dt> <dd><strong><?= h($row['nama_jurnal']) ?></strong></dd>
    <dt>URL:</dt>  <dd><a href="<?= h($row['url_archive']) ?>" target="_blank" rel="noopener"><?= h($row['url_archive']) ?></a></dd>
    <dt>Scan:</dt> <dd><?= h($row['scanned_at']) ?></dd>
  </dl>
</div>

<div class="card" style="margin-top:14px">
  <h3>Skor Risiko</h3>
  <p style="font-size:32px;font-weight:700;margin:8px 0">
    <?php if ($row['risk_score'] === null): ?>
      <span class="muted">—</span>
    <?php else: ?>
      <?= (int)$row['risk_score'] ?>/100
    <?php endif; ?>
    <?php
      $lbl = $row['risk_label'];
      $bg2 = ['HACKED'=>'#fee2e2','SUSPICIOUS'=>'#ffedd5','WARN'=>'#fef9c3','CLEAN'=>'#dcfce7','PARTIAL'=>'#ede9fe','UNREACHABLE'=>'#f3f4f6'][$lbl] ?? '#f3f4f6';
      $fg2 = ['HACKED'=>'#991b1b','SUSPICIOUS'=>'#9a3412','WARN'=>'#854d0e','CLEAN'=>'#166534','PARTIAL'=>'#5b21b6','UNREACHABLE'=>'#374151'][$lbl] ?? '#6b7280';
    ?>
    <span style="font-size:18px;padding:4px 12px;border-radius:8px;background:<?= $bg2 ?>;color:<?= $fg2 ?>;margin-left:10px">
      <?= h($lbl) ?>
    </span>
  </p>

  <?php if ($lbl === 'UNREACHABLE'): ?>
    <div class="alert alert-error" style="margin-top:12px;background:#f3f4f6;color:#374151;border-color:#9ca3af">
      <strong>✕ Site tidak bisa diakses dari scanner.</strong><br>
      Kedua fetch (browser biasa & Googlebot) gagal. Status keamanan jurnal <strong>tidak diketahui</strong>.<br>
      Penyebab umum: server jurnal sedang down, dimatikan oleh admin jaringan, firewall blokir IP scanner, DNS error,
      atau SSL handshake gagal. <em>Bukan berarti aman, dan bukan berarti hack — sekadar tidak bisa diverifikasi saat ini.</em>
    </div>
  <?php elseif ($lbl === 'PARTIAL'): ?>
    <div class="alert" style="margin-top:12px;background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd">
      <strong>◐ Verdict parsial.</strong> Hanya satu dari dua fetch yang berhasil — <strong>cloaking detection tidak bisa dilakukan</strong>.
      Hasil ini bersifat indikatif. Coba scan ulang nanti saat site stabil.
    </div>
  <?php endif; ?>

  <?php if (!empty($risk['reasons'])): ?>
    <p style="margin-top:12px"><strong>Alasan:</strong></p>
    <ul>
      <?php foreach ($risk['reasons'] as $reason): ?>
        <li><?= h($reason) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<h2 style="margin-top:24px">Hasil Fetch Dual UA</h2>

<?php
// HTTP Debug — kunci untuk audit kenapa fetch gagal
$dbg = $detail['http_debug'] ?? null;
if ($dbg):
    function fmt_curl_errno($n) {
        $map = [
            6  => 'CURLE_COULDNT_RESOLVE_HOST (DNS gagal)',
            7  => 'CURLE_COULDNT_CONNECT (server tidak menjawab / firewall)',
            28 => 'CURLE_OPERATION_TIMEDOUT (timeout)',
            35 => 'CURLE_SSL_CONNECT_ERROR (SSL handshake gagal)',
            51 => 'CURLE_PEER_FAILED_VERIFICATION (cert tidak valid)',
            52 => 'CURLE_GOT_NOTHING (server menutup koneksi tanpa response)',
            56 => 'CURLE_RECV_ERROR (gangguan di tengah jalan)',
            60 => 'CURLE_SSL_CACERT (CA cert tidak ditemukan)',
        ];
        return $map[$n] ?? "errno {$n}";
    }
?>
<div class="card" style="margin-bottom:14px;background:#fafafa">
  <h3>HTTP Debug Info</h3>
  <table style="width:100%;font-size:13px;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1px solid #d1d5db">
        <th style="text-align:left;padding:6px 8px">Aspek</th>
        <th style="text-align:left;padding:6px 8px">📱 Browser biasa</th>
        <th style="text-align:left;padding:6px 8px">🤖 Googlebot</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td style="padding:6px 8px;color:#6b7280">Fetch berhasil?</td>
        <td style="padding:6px 8px"><strong style="color:<?= !empty($dbg['normal']['ok']) ? '#16a34a' : '#dc2626' ?>"><?= !empty($dbg['normal']['ok']) ? 'YA' : 'TIDAK' ?></strong></td>
        <td style="padding:6px 8px"><strong style="color:<?= !empty($dbg['bot']['ok']) ? '#16a34a' : '#dc2626' ?>"><?= !empty($dbg['bot']['ok']) ? 'YA' : 'TIDAK' ?></strong></td>
      </tr>
      <tr>
        <td style="padding:6px 8px;color:#6b7280">HTTP code</td>
        <td style="padding:6px 8px"><?= (int)($dbg['normal']['http_code'] ?? 0) ?></td>
        <td style="padding:6px 8px"><?= (int)($dbg['bot']['http_code'] ?? 0) ?></td>
      </tr>
      <tr>
        <td style="padding:6px 8px;color:#6b7280">Error cURL</td>
        <td style="padding:6px 8px;color:#991b1b">
          <?php if (!empty($dbg['normal']['curl_errno'])): ?>
            <?= h(fmt_curl_errno($dbg['normal']['curl_errno'])) ?><br>
            <span class="muted small"><?= h($dbg['normal']['curl_error']) ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="padding:6px 8px;color:#991b1b">
          <?php if (!empty($dbg['bot']['curl_errno'])): ?>
            <?= h(fmt_curl_errno($dbg['bot']['curl_errno'])) ?><br>
            <span class="muted small"><?= h($dbg['bot']['curl_error']) ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <tr>
        <td style="padding:6px 8px;color:#6b7280">DNS lookup</td>
        <td style="padding:6px 8px"><?= (int)($dbg['normal']['dns_time_ms'] ?? 0) ?> ms</td>
        <td style="padding:6px 8px"><?= (int)($dbg['bot']['dns_time_ms'] ?? 0) ?> ms</td>
      </tr>
      <tr>
        <td style="padding:6px 8px;color:#6b7280">Connect</td>
        <td style="padding:6px 8px"><?= (int)($dbg['normal']['connect_ms'] ?? 0) ?> ms</td>
        <td style="padding:6px 8px"><?= (int)($dbg['bot']['connect_ms'] ?? 0) ?> ms</td>
      </tr>
      <tr>
        <td style="padding:6px 8px;color:#6b7280">Total durasi</td>
        <td style="padding:6px 8px"><?= (int)($dbg['normal']['duration_ms'] ?? 0) ?> ms</td>
        <td style="padding:6px 8px"><?= (int)($dbg['bot']['duration_ms'] ?? 0) ?> ms</td>
      </tr>
      <tr>
        <td style="padding:6px 8px;color:#6b7280">Body size</td>
        <td style="padding:6px 8px"><?= number_format((int)($dbg['normal']['body_size'] ?? 0)) ?> bytes</td>
        <td style="padding:6px 8px"><?= number_format((int)($dbg['bot']['body_size'] ?? 0)) ?> bytes</td>
      </tr>
    </tbody>
  </table>
</div>
<?php endif; ?>
<div class="card-grid">
  <!-- Browser biasa -->
  <div class="card">
    <h3>📱 Browser Biasa (Mozilla)</h3>
    <dl>
      <dt>Tipe:</dt>  <dd><strong><?= h($normal['type'] ?? '-') ?></strong></dd>
      <dt>Title:</dt> <dd><span class="small"><?= h(mb_substr($normal['title'] ?? '-', 0, 200)) ?></span></dd>
      <dt>OJS markers:</dt> <dd><?= (int)($normal['ojs_markers'] ?? 0) ?>/6</dd>
      <dt>Total anchor:</dt> <dd><?= (int)($normal['anchor_count'] ?? 0) ?></dd>
      <dt>Anchor judol:</dt> <dd><?= (int)($normal['judol_anchor'] ?? 0) ?></dd>
      <dt>Total keyword hit:</dt> <dd><?= (int)($normal['total_hits'] ?? 0) ?></dd>
      <dt>Body size:</dt> <dd><?= number_format((int)($normal['body_size'] ?? 0)) ?> bytes</dd>
      <dt>Top keywords:</dt> <dd><?= fmt_kw_list($normal['kw_detail']['hits_high'] ?? []) ?></dd>
    </dl>
  </div>

  <!-- Googlebot -->
  <div class="card">
    <h3>🤖 Googlebot</h3>
    <dl>
      <dt>Tipe:</dt>  <dd><strong><?= h($bot['type'] ?? '-') ?></strong></dd>
      <dt>Title:</dt> <dd><span class="small"><?= h(mb_substr($bot['title'] ?? '-', 0, 200)) ?></span></dd>
      <dt>OJS markers:</dt> <dd><?= (int)($bot['ojs_markers'] ?? 0) ?>/6</dd>
      <dt>Total anchor:</dt> <dd><?= (int)($bot['anchor_count'] ?? 0) ?></dd>
      <dt>Anchor judol:</dt> <dd><?= (int)($bot['judol_anchor'] ?? 0) ?></dd>
      <dt>Total keyword hit:</dt> <dd><?= (int)($bot['total_hits'] ?? 0) ?></dd>
      <dt>Body size:</dt> <dd><?= number_format((int)($bot['body_size'] ?? 0)) ?> bytes</dd>
      <dt>Top keywords:</dt> <dd><?= fmt_kw_list($bot['kw_detail']['hits_high'] ?? []) ?></dd>
    </dl>
  </div>
</div>

<?php if (!empty($cloak['cloaking'])): ?>
  <div class="alert alert-error" style="margin-top:18px">
    <strong>🚨 CLOAKING TERDETEKSI</strong><br>
    Severity: <?= h($cloak['severity'] ?? '-') ?> &middot;
    Alasan: <?= h($cloak['detail'] ?? $cloak['reason'] ?? '-') ?>
    <p style="margin-top:8px">Halaman menampilkan konten berbeda untuk Googlebot vs browser biasa.
    Ini ciri khas hack judol yang mengincar SEO poisoning. Pengelola jurnal sering
    tidak menyadari karena halaman terlihat normal di browser, tapi Google meng-index versi
    yang sudah di-injection link judol.</p>
  </div>
<?php endif; ?>

<details style="margin-top:18px">
  <summary class="muted small" style="cursor:pointer">Detail teknis (JSON mentah)</summary>
  <pre style="background:#f9fafb;padding:12px;border-radius:6px;overflow:auto;font-size:12px;max-height:400px"><?= h(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</details>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
