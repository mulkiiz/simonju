<?php
// Included only by the authenticated admin rubrik page.
if (!function_exists('is_admin_area') || !is_admin_area()) { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/sinta_history.php';
$historyError = '';
try {
    $historyState = sinta_history_load();
} catch (Throwable $e) {
    $historyState = sinta_history_empty();
    $historyError = 'History belum dapat dimuat. Periksa koneksi database dan log server.';
    error_log('SINTA history view: ' . $e->getMessage());
}
$historyRecords = array_values(array_filter($historyState['records'], function ($record) {
    return !empty($record['years']);
}));
$historyLocalIds = sinta_history_local_journal_ids($historyRecords);
usort($historyRecords, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
$historyYears = [];
foreach ($historyRecords as $record) {
    foreach ($record['years'] as $year => $rank) $historyYears[(int)$year] = (int)$year;
}
sort($historyYears, SORT_NUMERIC);
$expiryYear = (int)date('Y');
?>
<style>
.ha-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:16px 0}
.ha-toolbar [hidden]{display:none}
.ha-export{position:relative}
.ha-export summary{list-style:none}
.ha-export summary::-webkit-details-marker{display:none}
.ha-status{padding:12px 14px;border-radius:8px;background:#eff6ff;color:#1e40af;margin:12px 0;overflow-wrap:anywhere}
.ha-status.error{background:#fef2f2;color:#b42318}
.ha-table{width:100%;border-collapse:collapse;font-size:13px}
.ha-table th,.ha-table td{padding:10px;border-bottom:1px solid #e2e8f0;vertical-align:top}
.ha-table th{background:#f8fafc;text-align:left;white-space:nowrap}
.ha-table .ha-year{text-align:center;min-width:65px}
.ha-name{min-width:260px;max-width:380px}
.ha-rank{display:inline-block;padding:3px 7px;border-radius:5px;font-weight:700;white-space:nowrap;background:#e2e8f0;color:#334155}
.ha-s1{background:#dcfce7;color:#166534}.ha-s2{background:#dbeafe;color:#1e40af}
.ha-s3{background:#e0e7ff;color:#3730a3}.ha-s4{background:#fef3c7;color:#92400e}
.ha-s5{background:#ffedd5;color:#9a3412}.ha-s6{background:#fce7f3;color:#9d174d}
.ha-warning{color:#b45309;margin-top:5px}.ha-table small{display:block;margin-top:4px}
#ha-progress{width:100%;max-width:540px;height:16px}
</style>
<section class="card" style="padding:20px">
  <h2 style="margin:0 0 8px"><a href="<?= h(sinta_history_source()) ?>" target="_blank" rel="noopener">History Akreditasi Jurnal Unsoed</a></h2>
  <form id="ha-sync-form" class="ha-toolbar" method="post" action="sinta_history_sync.php">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary" id="ha-start"><?= $historyState['job'] ? 'Lanjutkan sinkronisasi' : 'Sinkronkan dari SINTA' ?></button>
    <button type="button" class="btn" id="ha-pause" hidden>Jeda</button>
    <button type="button" class="btn" id="ha-restart" <?= $historyState['job'] ? '' : 'hidden' ?>>Ulangi dari awal</button>
    <?php if ($historyState['errors']): ?><button type="button" class="btn" id="ha-retry">Coba ulang profil gagal</button><?php endif; ?>
    <span class="muted small">Terakhir selesai: <?= h($historyState['completed_at'] ?: 'Belum pernah') ?><?php if ($historyState['completed_at']): ?> — Hasil tersimpan ditampilkan di bawah. Sinkronkan untuk mengambil data terbaru.<?php endif; ?></span>
  </form>
  <noscript><p class="alert alert-info">Aktifkan JavaScript untuk menjalankan sinkronisasi bertahap.</p></noscript>
  <div id="ha-status" class="ha-status<?= $historyError ? ' error' : '' ?>" role="status" aria-live="polite" <?= !$historyError && !$historyState['job'] && $historyState['completed_at'] ? 'hidden' : '' ?>><?= h($historyError ?: ($historyState['job'] ? 'Sinkronisasi belum selesai. Klik Lanjutkan untuk meneruskan proses tersimpan.' : 'Belum ada data. Pengambilan pertama akan dimulai otomatis.')) ?></div>
  <progress id="ha-progress" max="1" value="0" hidden aria-label="Kemajuan sinkronisasi"></progress>
  <?php if ($historyState['errors']): ?>
    <details class="ha-warning"><summary><?= count($historyState['errors']) ?> profil gagal diperbarui — hasil belum lengkap</summary>
      <p>Data lama dipertahankan jika tersedia. Jalankan sinkronisasi kembali untuk mencoba profil yang gagal.</p>
      <ul><?php foreach ($historyState['errors'] as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
  <?php if ($historyRecords): ?>
    <div class="ha-toolbar">
      <label for="ha-expiry-filter"><strong>Habis akreditasi</strong>
        <select id="ha-expiry-filter" class="rb-inp">
          <option value="all">Semua tahun</option>
          <?php for ($year = $expiryYear; $year <= $expiryYear + 4; $year++): ?>
            <option value="<?= $year ?>"><?= $year ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <a class="btn btn-primary" href="sinta_history_export.php">📥 Export Excel (<?= $expiryYear ?>–<?= $expiryYear + 4 ?>)</a>
    </div>
    <div class="tbl-wrap" style="overflow-x:auto">
      <table class="ha-table">
        <thead><tr><th scope="col">Jurnal / Penerbit</th><th scope="col">Peringkat saat ini*</th><th scope="col">Tahun history</th>
          <?php foreach ($historyYears as $year): ?><th scope="col" class="ha-year"><?= $year ?></th><?php endforeach; ?>
          <th scope="col">Diperbarui</th>
        </tr></thead>
        <tbody id="ha-rows">
        <?php foreach ($historyRecords as $record): $years = array_keys($record['years']); $endsAt = max(array_map('intval', $years)); ?>
          <tr data-expiry-year="<?= $endsAt ?>">
            <td class="ha-name"><strong><?= h($record['name']) ?></strong>
              <small class="muted">Link:
                <?php if (isset($historyLocalIds[(int)$record['id']])): ?><a href="jurnal_view.php?id=<?= (int)$historyLocalIds[(int)$record['id']] ?>">[detail]</a><?php else: ?><span>[detail tidak tersedia]</span><?php endif; ?>
                · <a href="<?= h(sinta_history_profile_url($record['id'])) ?>" target="_blank" rel="noopener">[sinta]</a>
              </small>
            </td>
            <td><?php if ($record['rank']): ?><span class="ha-rank ha-s<?= (int)$record['rank'] ?>">Sinta <?= (int)$record['rank'] ?></span><?php else: ?><span class="muted">Tidak tercantum</span><?php endif; ?></td>
            <td><?php if ($years): ?><strong><?= min($years) ?>–<?= max($years) ?></strong><small><?= count($years) ?> tahun tercantum</small><?php else: ?><?= $record['error'] ? 'Belum diperoleh' : 'Tidak tersedia di SINTA' ?><?php endif; ?></td>
            <?php foreach ($historyYears as $year): ?>
              <td class="ha-year"><?php if (array_key_exists($year, $record['years'])): $rank = $record['years'][$year]; ?>
                <?php if ($rank): ?><span class="ha-rank ha-s<?= (int)$rank ?>" title="<?= $year ?>: Sinta <?= (int)$rank ?>">S<?= (int)$rank ?></span><?php else: ?><span title="Tahun tercantum; peringkat tidak diketahui">?</span><?php endif; ?>
              <?php else: ?><span class="muted" title="Tahun tidak tercantum di history">—</span><?php endif; ?></td>
            <?php endforeach; ?>
            <td class="muted small"><?= h($record['fetched_at'] ?: 'Belum berhasil') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p id="ha-empty-filter" class="muted" hidden>Tidak ada jurnal yang habis akreditasi pada tahun tersebut.</p>
    <p class="muted small">S1–S6 = peringkat SINTA per tahun; — = tahun tidak tercantum; ? = tahun tercantum tanpa peringkat yang dikenali.
      Tahun ditampilkan persis dari history, tanpa mengisi tahun yang kosong. *Peringkat saat data terakhir diambil, bukan penetapan masa berlaku SK.</p>
  <?php endif; ?>
</section>
<script>
(function () {
  'use strict';
  var form = document.getElementById('ha-sync-form');
  var start = document.getElementById('ha-start');
  var pause = document.getElementById('ha-pause');
  var restart = document.getElementById('ha-restart');
  var retry = document.getElementById('ha-retry');
  var status = document.getElementById('ha-status');
  var progress = document.getElementById('ha-progress');
  var running = false, paused = false;
  var hasJob = <?= $historyState['job'] ? 'true' : 'false' ?>;
  async function request(action) {
    var body = new FormData();
    body.append('_csrf', form.querySelector('[name="_csrf"]').value);
    body.append('action', action);
    var response = await fetch(form.action, {method: 'POST', body: body, credentials: 'same-origin'});
    if (response.redirected || !(response.headers.get('content-type') || '').includes('application/json')) {
      throw new Error('Sesi berakhir atau respons server tidak valid. Muat ulang halaman dan login kembali bila diperlukan.');
    }
    var result = await response.json();
    if (!response.ok || result.error) throw new Error(result.error || 'Sinkronisasi gagal. Coba lagi.');
    return result;
  }
  function describe(result) {
    progress.hidden = false;
    if (result.phase === 'list') {
      progress.max = result.pages || 1;
      progress.value = Math.max(0, result.page - 1);
      status.textContent = 'Mengambil daftar Unsoed: halaman ' + result.page + (result.pages ? ' dari ' + result.pages : '') + (result.list_round > 1 ? ' (pemeriksaan kelengkapan ke-' + result.list_round + ')' : '') + '…';
    } else {
      progress.max = result.total || 1;
      progress.value = result.processed;
      status.textContent = 'Mengambil history: ' + result.processed + ' dari ' + result.total + ' jurnal. ' + result.errors + ' gagal diperbarui.';
    }
  }
  async function run(action) {
    if (running) return;
    running = true; paused = false;
    start.disabled = true; restart.disabled = true; pause.hidden = false; pause.disabled = false;
    if (retry) retry.disabled = true;
    status.hidden = false;
    status.classList.remove('error');
    status.textContent = 'Menghubungi SINTA…';
    try {
      var result = await request(action);
      hasJob = result.running;
      describe(result);
      while (result.running && !paused) {
        result = await request('step');
        hasJob = result.running;
        describe(result);
      }
      if (!result.running) {
        status.textContent = 'Sinkronisasi selesai. Memuat hasil…';
        window.location.reload();
        return;
      }
      status.textContent += ' Dijeda; klik Lanjutkan untuk meneruskan.';
    } catch (error) {
      status.classList.add('error');
      status.textContent = error.message + ' Hasil tersimpan tetap tersedia.';
      // A timed-out response may already have saved its step; start resumes on the server.
      hasJob = true;
    } finally {
      running = false; start.disabled = false; restart.disabled = false; pause.hidden = true;
      if (retry) retry.disabled = false;
      start.textContent = hasJob ? 'Lanjutkan sinkronisasi' : 'Sinkronkan dari SINTA';
      restart.hidden = !hasJob;
    }
  }
  form.addEventListener('submit', function (event) { event.preventDefault(); run('start'); });
  restart.addEventListener('click', function () { run('restart'); });
  if (retry) retry.addEventListener('click', function () { run('retry'); });
  pause.addEventListener('click', function () { paused = true; pause.disabled = true; status.textContent = 'Menunggu permintaan aktif selesai, lalu menjeda…'; });
  var expiryFilter = document.getElementById('ha-expiry-filter');
  if (expiryFilter) {
    var rows = Array.from(document.querySelectorAll('#ha-rows tr'));
    var applyExpiryFilter = function () {
      var selectedYear = expiryFilter.value, count = 0;
      rows.forEach(function (row) {
        var matches = selectedYear === 'all' || row.dataset.expiryYear === selectedYear;
        row.hidden = !matches;
        if (matches) count++;
      });
      document.getElementById('ha-empty-filter').hidden = count !== 0;
    };
    expiryFilter.addEventListener('change', applyExpiryFilter);
    applyExpiryFilter();
  }
  <?php if (!$historyState['completed_at'] && !$historyState['job'] && !$historyError): ?>run('start');<?php endif; ?>
}());
</script>
