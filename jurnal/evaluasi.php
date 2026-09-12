<?php
/**
 * jurnal/evaluasi.php — Evaluasi Diri Akreditasi (self-assessment wizard).
 *
 * Form multi-langkah (next-next) untuk jurnal yang akan mengajukan
 * akreditasi / reakreditasi. STEP 0 prefill dari data jurnal, lalu
 * pengajuan, pemeriksaan awal, kelayakan, standar (skor), dan prediksi
 * peringkat. Perhitungan skor & prediksi dilakukan di sisi klien.
 *
 * Catatan: rubrik terperinci STEP 3 (Tata Kelola / Mutu Artikel) menyusul;
 * struktur skor sudah disiapkan (0-46 & 0-54).
 */
$page_title = 'Evaluasi Diri Akreditasi';
require_once __DIR__ . '/../includes/header_jurnal.php';

$jid = current_jurnal_id();
$j   = fetch_one("SELECT * FROM jurnals WHERE id=? LIMIT 1", 'i', [$jid]);
$ap  = fetch_one("SELECT * FROM akreditasi_periode WHERE jurnal_id=? LIMIT 1", 'i', [$jid]);
$ja  = fetch_one("SELECT username FROM jurnal_accounts WHERE jurnal_id=? LIMIT 1", 'i', [$jid]);
$ed  = fetch_one("SELECT nama, email FROM editor WHERE jurnal_id=? LIMIT 1", 'i', [$jid]);
$dr  = fetch_one("SELECT step, data FROM evaluasi_draft WHERE jurnal_id=? LIMIT 1", 'i', [$jid]);

// ---- Prefill (STEP 0) ------------------------------------------------------
$is_terakred = !in_array((string)($j['akreditasi_jenis'] ?? ''), ['', 'belum'], true)
            || (int)($j['is_scopus'] ?? 0) === 1;
$pf_nama   = $j['nama_jurnal'] ?? '';
$pf_url    = $j['url_archive'] ?? '';
$pf_sinta  = $j['akreditasi_peringkat'] ?: ($is_terakred ? '-' : 'Belum terakreditasi');
$pf_sinta_url = $is_terakred ? ($j['link_sinta'] ?: ($j['akreditasi_url'] ?? '')) : '';
// Editor dari tabel `editor` (sumber utama), fallback kolom lama di jurnals.
$pf_ketua  = ($ed['nama'] ?? '') ?: ($j['ketua_nama'] ?? '');
$pf_email  = ($ed['email'] ?? '') ?: ($j['ketua_email'] ?? '');
$pf_doi    = $j['doi'] ?? '';
$pf_issn   = $j['e_issn'] ?: ($j['issn'] ?: ($j['p_issn'] ?? ''));
$pf_vpt    = (int)preg_replace('/\D/', '', (string)($j['volume_per_tahun'] ?? '')) ?: 0;
$pf_jenis  = ($j['akreditasi_jenis'] === 'belum' || $j['akreditasi_jenis'] === '') ? 'baru' : 'reakreditasi';
$pf_user   = $ja['username'] ?? '';

// Masa berlaku (dari akreditasi_periode) untuk STEP 2A #9
$pf_masa = '';
if ($ap) {
    $a = array_map(function ($k) use ($ap) { return trim((string)($ap[$k] ?? '')); },
        ['mulai_volume','mulai_nomor','mulai_tahun','sampai_volume','sampai_nomor','sampai_tahun']);
    if (implode('', $a) !== '') {
        $pf_masa = "Vol {$a[0]} No {$a[1]} Th {$a[2]} s.d. Vol {$a[3]} No {$a[4]} Th {$a[5]}";
    }
}

$sinta_opts = ['Sinta 1','Sinta 2','Sinta 3','Sinta 4','Sinta 5','Sinta 6','Belum terakreditasi'];

// Master rubrik dari DB (dikelola admin lewat admin/rubrik.php).
require_once __DIR__ . '/../includes/rubrik.php';
$RB = rubrik_load();

// Peta kategori -> grup UI (3a/3b) + skor maksimum aktual.
$grp_map = ['tata_kelola' => '3a', 'mutu_artikel' => '3b'];

/** Render satu standar sebagai daftar accordion; tiap unsur berisi kriteria
 *  bertingkat (radio) dengan teks penuh terbaca. */
function ev_render_rubrik($cat, $grp) {
    if (empty($cat['unsur'])) {
        echo '<p class="muted small">Rubrik belum tersedia. Hubungi admin.</p>';
        return;
    }
    echo '<div class="acc" data-grp="' . $grp . '">';
    foreach ($cat['unsur'] as $u) {
        $uid  = (int)$u['id'];
        $name = 'r_unsur_' . $uid;
        $max  = rubrik_num($u['max']);
        $note = trim((string)($u['catatan'] ?? ''));
        echo '<div class="acc-item" data-uid="' . $uid . '">';
        echo '<button type="button" class="acc-head" onclick="evAcc(this)">'
           . '<span class="ucode">' . h($u['kode']) . '</span>'
           . '<span class="acc-title">' . h($u['nama']) . '</span>'
           . '<span class="acc-score" id="sc_' . $uid . '">–&nbsp;/&nbsp;' . $max . '</span>'
           . '<span class="acc-caret">▾</span></button>';
        echo '<div class="acc-body">';
        if ($note !== '') echo '<div class="acc-note">&#9432; ' . h($note) . '</div>';
        foreach ($u['kriteria'] as $k) {
            $val = rubrik_num($k['nilai']);
            echo '<label class="acc-opt">'
               . '<input type="radio" name="' . $name . '" value="' . $val . '" data-grp="' . $grp . '" data-uid="' . $uid . '" data-max="' . $max . '" onchange="evPick(this)">'
               . '<span class="acc-val">' . $val . '</span>'
               . '<span class="acc-txt">' . h($k['kriteria']) . '</span></label>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}
?>
<style>
/* ============== Evaluasi wizard (scoped) ============== */
.evwiz{max-width:920px;margin:0 auto}
.evwiz .steps{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 18px;padding:0;list-style:none;font-size:12px}
.evwiz .steps li{flex:1 1 auto;min-width:70px;text-align:center;padding:8px 4px;border-radius:6px;background:#eef2f7;color:#64748b;font-weight:600;position:relative;line-height:1.25}
.evwiz .steps li.done{background:#dcfce7;color:#15803d}
.evwiz .steps li.active{background:#1e3a8a;color:#fff;box-shadow:0 2px 6px rgba(30,58,138,.3)}
.evwiz .steps li b{display:block;font-size:15px}
.evwiz .progress{height:6px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-bottom:22px}
.evwiz .progress span{display:block;height:100%;background:linear-gradient(90deg,#1e3a8a,#3b82f6);width:0;transition:width .3s}
.evwiz .panel{display:none;animation:evfade .25s ease}
.evwiz .panel.active{display:block}
@keyframes evfade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.evwiz .panel h2{margin:0 0 4px;font-size:19px;color:#0c1e4a}
.evwiz .panel .lead{color:#6b7280;font-size:14px;margin:0 0 18px}
.evwiz .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:640px){.evwiz .grid2{grid-template-columns:1fr}}
.evwiz label.fld{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:12px}
.evwiz label.fld .hint{display:block;font-weight:400;color:#9ca3af;font-size:12px;margin-top:2px}
.evwiz input[type=text],.evwiz input[type=url],.evwiz input[type=number],.evwiz input[type=password],.evwiz select{
  width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit;margin-top:5px;background:#fff}
.evwiz input:focus,.evwiz select:focus{outline:none;border-color:#1d4ed8;box-shadow:0 0 0 3px rgba(29,78,216,.12)}
.evwiz .ck-row{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:10px;background:#fff}
.evwiz .ck-row .no{flex:0 0 26px;height:26px;border-radius:50%;background:#eef2f7;color:#1e3a8a;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center}
.evwiz .ck-row .body{flex:1;min-width:0}
.evwiz .ck-row .body .q{font-size:14px;font-weight:600;color:#1f2937;margin-bottom:6px}
.evwiz .yn{display:inline-flex;border:1px solid #d1d5db;border-radius:6px;overflow:hidden}
.evwiz .yn label{padding:5px 14px;font-size:13px;cursor:pointer;background:#fff;user-select:none}
.evwiz .yn label:first-child{border-right:1px solid #d1d5db}
.evwiz .yn input{position:absolute;opacity:0;pointer-events:none}
.evwiz .yn input:checked+span{}
.evwiz .yn label:has(input:checked){background:#1e3a8a;color:#fff;font-weight:600}
.evwiz .yn.neg label:has(input:checked){background:#dc2626}
.evwiz .issn-box{display:flex;gap:8px;align-items:flex-start;margin-top:5px}
.evwiz .issn-box input{margin-top:0}
.evwiz .issn-res{font-size:13px;margin-top:6px;min-height:18px}
.evwiz .issn-res.ok{color:#15803d}
.evwiz .issn-res.bad{color:#b91c1c}
.evwiz .info-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px}
.evwiz .info-card dl{margin:0;display:grid;grid-template-columns:170px 1fr;gap:10px 14px;font-size:14px}
.evwiz .info-card dt{color:#64748b;font-weight:600}
.evwiz .info-card dd{margin:0;font-weight:600;color:#111827;word-break:break-word}
@media(max-width:560px){.evwiz .info-card dl{grid-template-columns:1fr;gap:2px 0}.evwiz .info-card dd{margin-bottom:8px}}
.evwiz .note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:13px;padding:10px 12px;border-radius:8px;margin:0 0 16px}
.evwiz .issue-links{display:grid;gap:8px}
.evwiz .issue-links .il{display:flex;align-items:center;gap:8px}
.evwiz .issue-links .il .tag{flex:0 0 auto;font-size:12px;color:#64748b;font-weight:600;min-width:64px}
.evwiz .actions{display:flex;justify-content:space-between;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid #e5e7eb}
.evwiz .score-group{border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-bottom:16px;background:#fff}
.evwiz .score-group .sg-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:12px}
.evwiz .score-group .sg-head h3{margin:0;font-size:15px;color:#0c1e4a}
.evwiz .score-group .sg-val{font-size:15px;font-weight:800;color:#1e3a8a;font-variant-numeric:tabular-nums}
.evwiz .score-group input[type=range]{width:100%;accent-color:#1e3a8a}
.evwiz .rubrik-table{overflow-x:auto}
.evwiz .rtab{width:100%;border-collapse:collapse;font-size:13.5px}
.evwiz .rtab th{background:#f1f5f9;color:#475569;font-weight:700;text-align:left;padding:8px 10px;border-bottom:1px solid #e2e8f0;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
.evwiz .rtab td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.evwiz .rtab td.num{text-align:center;color:#94a3b8;font-weight:600}
.evwiz .rtab tr:last-child td{border-bottom:none}
.evwiz .rtab .ucode{display:inline-block;background:#eef2f7;color:#1e3a8a;font-weight:700;font-size:12px;padding:2px 7px;border-radius:5px}
.evwiz .rtab .uinfo{color:#2563eb;cursor:help;font-size:14px}
/* Accordion rubrik */
.evwiz .sticky-sum{position:sticky;top:0;z-index:5;background:#0c1e4a;color:#fff;border-radius:8px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.12)}
.evwiz .sticky-sum .sg-val{color:#fff}
.evwiz .acc{display:flex;flex-direction:column;gap:8px;margin-bottom:8px}
.evwiz .acc-item{border:1px solid #e5e7eb;border-radius:9px;overflow:hidden;background:#fff}
.evwiz .acc-item.picked{border-color:#93c5fd}
.evwiz .acc-head{display:flex;align-items:center;gap:10px;width:100%;text-align:left;background:#f8fafc;border:none;padding:11px 14px;cursor:pointer;font-family:inherit}
.evwiz .acc-item.picked .acc-head{background:#eff6ff}
.evwiz .acc-head:hover{background:#eef2f7}
.evwiz .acc-head .acc-title{flex:1;min-width:0;font-size:13.5px;font-weight:600;color:#1f2937;line-height:1.35}
.evwiz .acc-head .acc-score{flex:0 0 auto;font-size:12.5px;font-weight:800;color:#1e3a8a;font-variant-numeric:tabular-nums;background:#e0e7ff;padding:3px 9px;border-radius:99px;white-space:nowrap}
.evwiz .acc-item.picked .acc-score{background:#1e3a8a;color:#fff}
.evwiz .acc-head .acc-caret{flex:0 0 auto;color:#94a3b8;transition:transform .2s}
.evwiz .acc-item.open .acc-caret{transform:rotate(180deg)}
.evwiz .acc-body{display:none;padding:8px 14px 12px;border-top:1px solid #eef2f7}
.evwiz .acc-item.open .acc-body{display:block}
.evwiz .acc-note{background:#eff6ff;border:1px solid #dbeafe;color:#1e40af;font-size:12.5px;padding:8px 10px;border-radius:7px;margin-bottom:8px;line-height:1.45}
.evwiz .acc-opt{display:flex;gap:10px;align-items:flex-start;padding:8px 10px;border-radius:7px;cursor:pointer;font-size:13px;line-height:1.4}
.evwiz .acc-opt:hover{background:#f8fafc}
.evwiz .acc-opt input{margin-top:2px;flex:0 0 auto}
.evwiz .acc-opt .acc-val{flex:0 0 auto;font-weight:800;color:#1e3a8a;min-width:28px}
.evwiz .acc-opt .acc-txt{flex:1;color:#374151}
.evwiz .acc-opt input:checked~.acc-txt{color:#0c1e4a;font-weight:600}
/* Result card (untuk PDF) */
.evwiz #resCard{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px}
.evwiz .res-ident{text-align:center;border-bottom:1px solid #eef2f7;padding-bottom:14px;margin-bottom:8px}
.evwiz .res-ident .res-jname{font-size:18px;font-weight:800;color:#0c1e4a;line-height:1.3}
.evwiz .res-ident .res-jmeta{font-size:12.5px;color:#64748b;margin-top:3px}
/* Rincian rubrik pada hasil/PDF */
.evwiz .res-detail{margin-top:22px}
.evwiz .rd-h{font-size:14px;color:#0c1e4a;margin:16px 0 6px;padding-bottom:4px;border-bottom:2px solid #e2e8f0}
.evwiz .rd-tab{width:100%;border-collapse:collapse;font-size:12.5px}
.evwiz .rd-tab td{padding:7px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.evwiz .rd-code{width:44px;font-weight:700;color:#1e3a8a}
.evwiz .rd-nm{font-weight:600;color:#1f2937}
.evwiz .rd-krit{font-weight:400;color:#64748b;font-size:11.5px;margin-top:2px;line-height:1.4}
.evwiz .rd-val{width:66px;text-align:right;font-weight:800;color:#0c1e4a;font-variant-numeric:tabular-nums;white-space:nowrap}
/* Tanda tangan */
.evwiz .res-sign{display:flex;gap:24px;margin-top:28px;page-break-inside:avoid}
.evwiz .sign-col{flex:1;text-align:center;font-size:13px}
.evwiz .sign-role{font-weight:600;color:#1f2937;margin-bottom:2px}
.evwiz .sign-space{height:70px}
.evwiz .sign-name{border-top:1px dotted transparent;color:#111827;font-weight:600}
.evwiz .rubrik-sel{width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;background:#fff}
.evwiz .rubrik-sel:focus{outline:none;border-color:#1d4ed8}
.evwiz .result{text-align:center;padding:10px 0}
.evwiz .result .big{font-size:56px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.evwiz .result .band{display:inline-block;margin-top:14px;padding:10px 22px;border-radius:99px;font-size:18px;font-weight:700;color:#fff}
.evwiz .result .sub{color:#6b7280;font-size:13px;margin-top:8px}
.evwiz .breakdown{max-width:420px;margin:22px auto 0;text-align:left;font-size:14px}
.evwiz .breakdown div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed #e5e7eb}
.evwiz .breakdown .tot{font-weight:800;border-bottom:2px solid #cbd5e1}
.evwiz .warn-dis{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:8px;font-size:13px;margin-top:16px}
@media print{.topbar,.evwiz .steps,.evwiz .progress,.evwiz .actions{display:none!important}.evwiz .panel{display:block!important}}
</style>

<div class="page-head">
  <h1>🎯 Evaluasi Diri Akreditasi</h1>
</div>

<form class="evwiz" id="evForm" autocomplete="off" onsubmit="return false">
  <?= csrf_field() ?>

  <ol class="steps" id="evSteps">
    <li data-s="0"><b>0</b>Info Umum</li>
    <li data-s="1"><b>1</b>Pengajuan</li>
    <li data-s="2"><b>2A</b>Pemeriksaan Awal</li>
    <li data-s="3"><b>2B</b>Kelayakan</li>
    <li data-s="4"><b>3A</b>Tata Kelola</li>
    <li data-s="5"><b>3B</b>Mutu Artikel</li>
    <li data-s="6"><b>3C</b>Disinsentif</li>
    <li data-s="7"><b>✓</b>Hasil</li>
  </ol>
  <div class="progress"><span id="evBar"></span></div>

  <!-- ============ STEP 0: INFO UMUM ============ -->
  <section class="panel active" data-step="0">
    <h2>Informasi Umum Jurnal</h2>
    <p class="lead">Data dimuat ulang dari profil jurnal Anda. Bila ada yang keliru, perbarui lewat menu <strong>Edit Data</strong>.</p>
    <div class="info-card">
      <dl>
        <dt>Nama Jurnal</dt><dd><?= h($pf_nama ?: '—') ?></dd>
        <dt>URL Jurnal</dt><dd><?= $pf_url ? '<a href="'.h($pf_url).'" target="_blank" rel="noopener">'.h($pf_url).'</a>' : '—' ?></dd>
        <dt>Peringkat SINTA Terakhir</dt><dd><?= h($pf_sinta ?: '—') ?></dd>
        <?php if ($is_terakred && $pf_sinta_url): ?>
        <dt>Link Profil SINTA</dt><dd><a href="<?= h($pf_sinta_url) ?>" target="_blank" rel="noopener"><?= h($pf_sinta_url) ?></a></dd>
        <?php endif; ?>
        <dt>Ketua Editor</dt><dd><?= h($pf_ketua ?: '—') ?></dd>
        <dt>Email Ketua Editor</dt><dd><?= h($pf_email ?: '—') ?></dd>
      </dl>
    </div>
    <div class="actions">
      <span></span>
      <button type="button" class="btn btn-primary" data-next>Mulai &rarr;</button>
    </div>
  </section>

  <!-- ============ STEP 1: PENGAJUAN ============ -->
  <section class="panel" data-step="1">
    <h2>Pengajuan</h2>
    <p class="lead">Tentukan jenis usulan dan lengkapi tautan terbitan serta akun OJS.</p>

    <label class="fld">Jenis usulan
      <div class="yn" style="margin-top:6px" data-req>
        <label><input type="radio" name="jenis_usulan" value="baru" <?= $pf_jenis==='baru'?'checked':'' ?> onchange="evRenderIssues()"><span>Akreditasi Baru</span></label>
        <label><input type="radio" name="jenis_usulan" value="reakreditasi" <?= $pf_jenis==='reakreditasi'?'checked':'' ?> onchange="evRenderIssues()"><span>Reakreditasi</span></label>
      </div>
    </label>

    <div id="baruBox" style="margin-bottom:14px">
      <label class="fld" style="max-width:320px">Frekuensi terbit per tahun
        <span class="hint">Jumlah tautan isu = frekuensi &times; 3.</span>
        <input type="number" id="terbitPerTahun" min="1" max="12" value="<?= $pf_vpt ?: 2 ?>" onchange="evRenderIssues()" oninput="evRenderIssues()">
      </label>
    </div>

    <label class="fld" id="issuesLabel">Tautan isu terbit</label>
    <div class="issue-links" id="issueLinks"></div>

    <hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0">

    <h3 style="margin:0 0 4px;font-size:15px;color:#0c1e4a">Akun Login OJS</h3>
    <p class="lead" style="margin-bottom:12px">Kredensial ini dipakai tim reviewer untuk masuk ke dashboard OJS jurnal Anda.</p>
    <div class="grid2">
      <label class="fld">Username OJS
        <input type="text" name="ojs_user" value="<?= h($pf_user) ?>" data-req>
      </label>
      <label class="fld">Password OJS
        <input type="password" name="ojs_pass" data-req>
      </label>
    </div>
    <label class="fld">URL halaman login jurnal
      <input type="url" name="ojs_login_url" placeholder="https://jos.unsoed.ac.id/index.php/xxx/login" data-req>
    </label>

    <div class="actions">
      <button type="button" class="btn" data-prev>&larr; Kembali</button>
      <button type="button" class="btn btn-primary" data-next>Lanjut &rarr;</button>
    </div>
  </section>

  <!-- ============ STEP 2A: PEMERIKSAAN AWAL ============ -->
  <section class="panel" data-step="2">
    <h2>Pemeriksaan Awal — Kelengkapan Administrasi &amp; Teknis</h2>
    <p class="lead">13 butir kelengkapan. Isi apa adanya; hasil dipakai sebagai catatan kesiapan.</p>

    <div class="ck-row"><div class="no">1</div><div class="body">
      <div class="q">Validitas laman jurnal</div>
      <input type="url" name="p1_url" value="<?= h($pf_url) ?>" placeholder="URL laman jurnal">
    </div></div>

    <div class="ck-row"><div class="no">2</div><div class="body">
      <div class="q">Validitas ISSN <span class="muted small">(cek nama jurnal via ISSN)</span></div>
      <div class="issn-box">
        <input type="text" id="issnInput" name="p2_issn" value="<?= h($pf_issn) ?>" placeholder="1234-5678">
        <button type="button" class="btn btn-sm" onclick="evCheckIssn()">Cek</button>
      </div>
      <div class="issn-res" id="issnRes"></div>
    </div></div>

    <div class="ck-row"><div class="no">3</div><div class="body">
      <div class="q">Umur jurnal</div>
      <input type="text" name="p3_umur" placeholder="mis. 6 tahun (terbit sejak 2019)">
    </div></div>

    <div class="ck-row"><div class="no">4</div><div class="body">
      <div class="q">Kesesuaian jenis usulan</div>
      <div class="yn">
        <label><input type="radio" name="p4_jenis" value="baru" <?= $pf_jenis==='baru'?'checked':'' ?>><span>Baru</span></label>
        <label><input type="radio" name="p4_jenis" value="reakreditasi" <?= $pf_jenis==='reakreditasi'?'checked':'' ?>><span>Reakreditasi</span></label>
      </div>
    </div></div>

    <div class="ck-row"><div class="no">5</div><div class="body">
      <div class="q">Ketersediaan DOI</div>
      <input type="text" name="p5_doi" value="<?= h($pf_doi) ?>" placeholder="Prefix DOI, mis. 10.20884/1.xxx">
    </div></div>

    <?php
    // Butir radio ya/tidak sederhana
    $yn2a = [
      6  => 'Ketersediaan teks penuh (full text)',
      7  => 'Kesesuaian frekuensi dan keberkalaan terbit',
      8  => 'Kecukupan keberagaman afiliasi editor dan mitra bestari',
    ];
    foreach ($yn2a as $n => $q): ?>
    <div class="ck-row"><div class="no"><?= $n ?></div><div class="body">
      <div class="q"><?= h($q) ?></div>
      <div class="yn">
        <label><input type="radio" name="p<?= $n ?>" value="ya"><span>Ya</span></label>
        <label><input type="radio" name="p<?= $n ?>" value="tidak"><span>Tidak</span></label>
      </div>
    </div></div>
    <?php endforeach; ?>

    <div class="ck-row"><div class="no">9</div><div class="body">
      <div class="q">Pencantuman peringkat dan masa berlaku</div>
      <div class="grid2">
        <label class="fld" style="margin:0">Peringkat akreditasi SINTA
          <select name="p9_peringkat">
            <?php foreach ($sinta_opts as $o): ?>
              <option value="<?= h($o) ?>" <?= ($pf_sinta===$o)?'selected':'' ?>><?= h($o) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="fld" style="margin:0">Masa berlaku
          <input type="text" name="p9_masa" value="<?= h($pf_masa) ?>" placeholder="mis. Vol 1 No 1 Th 2021 s.d. ...">
        </label>
      </div>
    </div></div>

    <div class="ck-row"><div class="no">10</div><div class="body">
      <div class="q">Laman etika publikasi</div>
      <input type="url" name="p10_etika" placeholder="URL halaman Publication Ethics">
    </div></div>

    <div class="ck-row"><div class="no">11</div><div class="body">
      <div class="q">Laman biaya pemrosesan artikel (APC)</div>
      <input type="url" name="p11_apc" placeholder="URL halaman Author Fees / APC">
    </div></div>

    <div class="ck-row"><div class="no">12</div><div class="body">
      <div class="q">Verifikasi username dan password OJS</div>
      <p class="muted small" style="margin:0 0 6px">Diambil dari STEP 1 — Username: <strong id="echoUser">—</strong>, Password: <strong id="echoPass">—</strong></p>
      <div class="yn">
        <label><input type="radio" name="p12" value="ya"><span>Sudah benar</span></label>
        <label><input type="radio" name="p12" value="tidak"><span>Belum</span></label>
      </div>
    </div></div>

    <div class="ck-row"><div class="no">13</div><div class="body">
      <div class="q">Ketersediaan kredensial / peran editor pada OJS</div>
      <div class="yn">
        <label><input type="radio" name="p13" value="ya"><span>Ya</span></label>
        <label><input type="radio" name="p13" value="tidak"><span>Tidak</span></label>
      </div>
    </div></div>

    <div class="actions">
      <button type="button" class="btn" data-prev>&larr; Kembali</button>
      <button type="button" class="btn btn-primary" data-next>Lanjut &rarr;</button>
    </div>
  </section>

  <!-- ============ STEP 2B: KELAYAKAN ============ -->
  <section class="panel" data-step="3">
    <h2>Pemeriksaan Kelayakan</h2>
    <p class="lead">Dua butir penentu kelayakan substantif.</p>

    <div class="ck-row"><div class="no">1</div><div class="body">
      <div class="q">Kecukupan penelaahan artikel oleh mitra bestari</div>
      <div class="yn">
        <label><input type="radio" name="k1" value="ya"><span>Ya</span></label>
        <label><input type="radio" name="k1" value="tidak"><span>Tidak</span></label>
      </div>
    </div></div>

    <div class="ck-row"><div class="no">2</div><div class="body">
      <div class="q">Validitas dan integritas penerbit</div>
      <div class="yn">
        <label><input type="radio" name="k2" value="ya"><span>Ya</span></label>
        <label><input type="radio" name="k2" value="tidak"><span>Tidak</span></label>
      </div>
    </div></div>

    <div class="actions">
      <button type="button" class="btn" data-prev>&larr; Kembali</button>
      <button type="button" class="btn btn-primary" data-next>Lanjut &rarr;</button>
    </div>
  </section>

  <!-- ============ STEP 3A: TATA KELOLA ============ -->
  <section class="panel" data-step="4">
    <h2>STEP 3A — <?= h($RB['tata_kelola']['label']) ?></h2>
    <p class="lead">Klik tiap unsur untuk membuka rubrik, lalu pilih tingkat skor yang sesuai.</p>
    <div class="note">📜 Rubrik penilaian telah disesuaikan dengan Kepdirjen 374/2026. Nilai minimal 60 untuk mendapatkan akreditasi minimal yaitu SINTA-4.</div>

    <input type="hidden" id="s3a" value="0">
    <div class="sg-head sticky-sum">
      <strong>Subtotal Tata Kelola</strong>
      <div class="sg-val"><span id="s3aOut">0</span> / <?= rubrik_num($RB['tata_kelola']['max']) ?></div>
    </div>
    <?php ev_render_rubrik($RB['tata_kelola'], '3a'); ?>

    <div class="actions">
      <button type="button" class="btn" data-prev>&larr; Kembali</button>
      <button type="button" class="btn btn-primary" data-next>Lanjut ke 3B &rarr;</button>
    </div>
  </section>

  <!-- ============ STEP 3B: MUTU ARTIKEL ============ -->
  <section class="panel" data-step="5">
    <h2>STEP 3B — <?= h($RB['mutu_artikel']['label']) ?></h2>
    <p class="lead">Klik tiap unsur untuk membuka rubrik, lalu pilih tingkat skor yang sesuai.</p>

    <input type="hidden" id="s3b" value="0">
    <div class="sg-head sticky-sum">
      <strong>Subtotal Mutu Artikel</strong>
      <div class="sg-val"><span id="s3bOut">0</span> / <?= rubrik_num($RB['mutu_artikel']['max']) ?></div>
    </div>
    <?php ev_render_rubrik($RB['mutu_artikel'], '3b'); ?>

    <div class="actions">
      <button type="button" class="btn" data-prev>&larr; Kembali</button>
      <button type="button" class="btn btn-primary" data-next>Lanjut ke 3C &rarr;</button>
    </div>
  </section>

  <!-- ============ STEP 3C: DISINSENTIF ============ -->
  <section class="panel" data-step="6">
    <h2>STEP 3C — Disinsentif</h2>
    <p class="lead">Faktor yang dapat menggugurkan/menurunkan peringkat terlepas dari skor.</p>

    <div class="ck-row" style="margin-bottom:10px"><div class="no">1</div><div class="body">
      <div class="q">Pelanggaran integritas akademik</div>
      <div class="yn neg">
        <label><input type="radio" name="d1" value="ya"><span>Ya</span></label>
        <label><input type="radio" name="d1" value="tidak" checked><span>Tidak</span></label>
      </div>
    </div></div>
    <div class="ck-row" style="margin:0"><div class="no">2</div><div class="body">
      <div class="q">Ethical Clearance</div>
      <div class="yn">
        <label><input type="radio" name="d2" value="ya" checked><span>Ada</span></label>
        <label><input type="radio" name="d2" value="tidak"><span>Tidak ada</span></label>
      </div>
    </div></div>

    <div class="actions">
      <button type="button" class="btn" data-prev>&larr; Kembali</button>
      <button type="button" class="btn btn-primary" data-next onclick="evCompute()">Lihat Hasil &rarr;</button>
    </div>
  </section>

  <!-- ============ RESULT ============ -->
  <section class="panel" data-step="7">
    <h2>Prediksi Peringkat Akreditasi</h2>
    <p class="lead">Berdasarkan total skor STEP 3 (Tata Kelola + Mutu Artikel).</p>

    <div id="resCard">
      <div class="res-ident">
        <div class="res-jname"><?= h($pf_nama ?: 'Jurnal') ?></div>
        <div class="res-jmeta">Evaluasi Diri Akreditasi &middot; <span id="resDate"></span></div>
      </div>

      <div class="result">
        <div class="big" id="resScore" style="color:#1e3a8a">0</div>
        <div class="muted small">Skor Akhir (maks 100)</div>
        <br>
        <span class="band" id="resBand" style="background:#6b7280">—</span>
        <div class="sub" id="resRange"></div>
      </div>

      <div class="breakdown">
        <div><span>STEP 3A — Tata Kelola</span><span><b id="bd3a">0</b> / <?= rubrik_num($RB['tata_kelola']['max']) ?></span></div>
        <div><span>STEP 3B — Mutu Artikel</span><span><b id="bd3b">0</b> / <?= rubrik_num($RB['mutu_artikel']['max']) ?></span></div>
        <div class="tot"><span>Total</span><span><b id="bdTot">0</b> / <?= rubrik_num($RB['tata_kelola']['max'] + $RB['mutu_artikel']['max']) ?></span></div>
      </div>

      <div class="warn-dis" id="disWarn" style="display:none"></div>

      <div class="res-detail" id="resDetail"></div>

      <div class="res-sign">
        <div class="sign-col">
          <div class="sign-role">Tim PPJ LPPM</div>
          <div class="sign-space"></div>
          <div class="sign-name">(…………………………………)</div>
        </div>
        <div class="sign-col">
          <div class="sign-role">Reviewer Internal</div>
          <div class="sign-space"></div>
          <div class="sign-name">(…………………………………)</div>
        </div>
      </div>
    </div>

    <div class="actions">
      <button type="button" class="btn" data-prev>&larr; Kembali</button>
      <button type="button" class="btn btn-primary" id="btnPdf" onclick="evDownloadPDF()">⬇️ Download PDF</button>
    </div>
  </section>
</form>

<div id="evSaved" style="position:fixed;right:16px;bottom:16px;background:#15803d;color:#fff;padding:8px 14px;border-radius:8px;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,.2);opacity:0;transform:translateY(8px);transition:.25s;pointer-events:none;z-index:50">💾 Tersimpan</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script>
window.__evDraft = <?= ($dr && $dr['data']) ? str_replace('</', '<\/', $dr['data']) : 'null' ?>;
window.__evStep  = <?= $dr ? (int)$dr['step'] : 0 ?>;
</script>
<script>
(function(){
  const form   = document.getElementById('evForm');
  const panels = [...form.querySelectorAll('.panel')];
  const stepsUI= [...document.querySelectorAll('#evSteps li')];
  const bar    = document.getElementById('evBar');
  let cur = 0;
  const LAST = panels.length - 1;

  function show(i){
    cur = Math.max(0, Math.min(LAST, i));
    panels.forEach((p,idx)=>p.classList.toggle('active', idx===cur));
    stepsUI.forEach((li,idx)=>{
      li.classList.toggle('active', idx===cur);
      li.classList.toggle('done', idx<cur);
    });
    bar.style.width = (cur/LAST*100)+'%';
    window.scrollTo({top:0,behavior:'smooth'});
  }

  // Validasi ringan: field ber-atribut data-req di panel aktif
  function validate(){
    const panel = panels[cur];
    for (const el of panel.querySelectorAll('[data-req]')){
      if (el.classList.contains('yn')){
        if (!el.querySelector('input:checked')){ alert('Lengkapi pilihan yang wajib diisi.'); return false; }
      } else if (!el.value.trim()){
        el.focus(); el.style.borderColor='#dc2626';
        setTimeout(()=>el.style.borderColor='',1500);
        alert('Kolom wajib diisi.'); return false;
      }
    }
    return true;
  }

  form.querySelectorAll('[data-next]').forEach(b=>b.addEventListener('click',()=>{
    if(validate()){ if(cur===2) syncEcho(); const to=cur+1; show(to); saveDraft(to); }
  }));

  // ---- Autosave draft (per step) ----
  const csrf = form.querySelector('[name=_csrf]').value;
  const savedTag = document.getElementById('evSaved');
  let saveTimer=null;
  function collect(){
    const d={};
    new FormData(form).forEach((v,k)=>{
      if(k==='_csrf') return;
      if(k.endsWith('[]')){ (d[k]=d[k]||[]).push(v); }
      else d[k]=v;
    });
    d.s3a=document.getElementById('s3a').value;
    d.s3b=document.getElementById('s3b').value;
    d.terbitPerTahun=document.getElementById('terbitPerTahun').value;
    return d;
  }
  function saveDraft(step){
    clearTimeout(saveTimer);
    saveTimer=setTimeout(()=>{
      const body=new URLSearchParams();
      body.set('_csrf',csrf); body.set('step',step);
      body.set('data',JSON.stringify(collect()));
      fetch('evaluasi_save.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body})
        .then(r=>r.json()).then(d=>{ if(d.ok){ savedTag.style.opacity='1';savedTag.style.transform='none';
          setTimeout(()=>{savedTag.style.opacity='0';savedTag.style.transform='translateY(8px)';},1600);} })
        .catch(()=>{});
    },250);
  }
  window.evSaveDraft=saveDraft;

  function hydrate(d){
    // 1) field non-array (skip skor & issue dulu)
    for(const k in d){
      if(k==='issue[]'||k==='s3a'||k==='s3b') continue;
      const els=form.querySelectorAll('[name="'+k.replace(/"/g,'\\"')+'"]');
      els.forEach(el=>{
        if(el.type==='radio'||el.type==='checkbox') el.checked=(el.value===d[k]);
        else el.value=d[k];
      });
    }
    if('terbitPerTahun' in d) document.getElementById('terbitPerTahun').value=d.terbitPerTahun;
    // 2) render ulang tautan isu lalu isi nilainya
    evRenderIssues();
    if(Array.isArray(d['issue[]'])){
      const inputs=document.querySelectorAll('#issueLinks input');
      d['issue[]'].forEach((val,i)=>{ if(inputs[i]) inputs[i].value=val; });
    }
    // 3) skor rubrik (radio sudah terpilih di langkah 1) -> badge + jumlah
    evRefreshBadges();
    evSumRubric('3a'); evSumRubric('3b');
    syncEcho();
  }
  form.querySelectorAll('[data-prev]').forEach(b=>b.addEventListener('click',()=>show(cur-1)));
  stepsUI.forEach((li,idx)=>li.addEventListener('click',()=>{ if(idx<=cur) show(idx); }));

  // ---- STEP 1: render tautan isu ----
  window.evRenderIssues = function(){
    const jenis = form.querySelector('[name=jenis_usulan]:checked')?.value || 'baru';
    const baruBox = document.getElementById('baruBox');
    const label = document.getElementById('issuesLabel');
    const box = document.getElementById('issueLinks');
    let n, tag;
    if (jenis === 'baru'){
      baruBox.style.display='';
      const per = Math.max(1, parseInt(document.getElementById('terbitPerTahun').value)||2);
      n = per*3; tag='Isu';
      label.textContent = `Tautan isu terbit (${per}×/tahun × 3 = ${n} isu)`;
    } else {
      baruBox.style.display='none';
      n = 3; tag='Isu terakhir';
      label.textContent = '3 tautan isu terbit terakhir';
    }
    const old = [...box.querySelectorAll('input')].map(i=>i.value);
    box.innerHTML='';
    for(let i=0;i<n;i++){
      const row=document.createElement('div'); row.className='il';
      row.innerHTML=`<span class="tag">${tag} ${i+1}</span><input type="url" name="issue[]" placeholder="https://..." value="${old[i]?old[i].replace(/"/g,'&quot;'):''}">`;
      box.appendChild(row);
    }
  };

  // ---- STEP 2A #12: echo kredensial ----
  function syncEcho(){
    const u = form.querySelector('[name=ojs_user]').value.trim();
    const p = form.querySelector('[name=ojs_pass]').value;
    document.getElementById('echoUser').textContent = u || '—';
    document.getElementById('echoPass').textContent = p ? '•'.repeat(Math.min(p.length,10)) : '—';
  }

  // ---- STEP 2A #2: cek ISSN via Crossref ----
  window.evCheckIssn = function(){
    const v = document.getElementById('issnInput').value.trim();
    const res = document.getElementById('issnRes');
    if(!v){ res.className='issn-res bad'; res.textContent='Isi ISSN dulu.'; return; }
    res.className='issn-res'; res.textContent='Memeriksa…';
    fetch('../api/issn.php?issn='+encodeURIComponent(v))
      .then(r=>r.json())
      .then(d=>{
        if(d.ok){ res.className='issn-res ok'; res.textContent='✓ '+d.title+(d.publisher?' — '+d.publisher:''); }
        else { res.className='issn-res bad'; res.textContent='✗ '+(d.error||'Tidak ditemukan.'); }
      })
      .catch(()=>{ res.className='issn-res bad'; res.textContent='Gagal menghubungi server.'; });
  };

  // ---- STEP 3: accordion rubrik ----
  window.evAcc = function(btn){ btn.parentElement.classList.toggle('open'); };

  window.evPick = function(el){
    const b=document.getElementById('sc_'+el.dataset.uid);
    if(b) b.innerHTML=el.value+'&nbsp;/&nbsp;'+el.dataset.max;
    const item=el.closest('.acc-item');
    if(item){ item.classList.add('picked'); item.classList.remove('open'); }
    evSumRubric(el.dataset.grp);
  };

  window.evSumRubric = function(grp){
    let sum=0;
    form.querySelectorAll('input[type=radio][data-grp="'+grp+'"]:checked').forEach(s=>{ sum+=parseFloat(s.value)||0; });
    sum=Math.round(sum*100)/100;
    document.getElementById('s'+grp).value=sum;
    document.getElementById('s'+grp+'Out').textContent=sum;
  };

  // Segarkan badge skor tiap unsur (dipakai setelah hydrate).
  function evRefreshBadges(){
    form.querySelectorAll('input[type=radio][data-uid]:checked').forEach(el=>{
      const b=document.getElementById('sc_'+el.dataset.uid);
      if(b) b.innerHTML=el.value+'&nbsp;/&nbsp;'+el.dataset.max;
      const item=el.closest('.acc-item'); if(item) item.classList.add('picked');
    });
  }

  // ---- Rincian rubrik untuk hasil/PDF ----
  function evEsc(s){ return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function evBuildDetail(){
    const wrap=document.getElementById('resDetail');
    if(!wrap) return;
    let html='';
    [['3a','STEP 3A — Tata Kelola'],['3b','STEP 3B — Mutu Artikel']].forEach(([grp,title])=>{
      html+='<h4 class="rd-h">'+title+'</h4><table class="rd-tab"><tbody>';
      form.querySelectorAll('.acc[data-grp="'+grp+'"] .acc-item').forEach(item=>{
        const code=item.querySelector('.ucode').textContent.trim();
        const nm=item.querySelector('.acc-title').textContent.trim();
        const ch=item.querySelector('input:checked');
        const max=item.querySelector('input[data-max]')?.dataset.max||'';
        const val=ch?ch.value:'–';
        const txt=ch?ch.closest('.acc-opt').querySelector('.acc-txt').textContent.trim():'(belum dipilih)';
        html+='<tr><td class="rd-code">'+evEsc(code)+'</td><td class="rd-nm">'+evEsc(nm)
             +'<div class="rd-krit">'+evEsc(txt)+'</div></td><td class="rd-val">'+val+' / '+max+'</td></tr>';
      });
      html+='</tbody></table>';
    });
    const d1=form.querySelector('[name=d1]:checked')?.value, d2=form.querySelector('[name=d2]:checked')?.value;
    html+='<h4 class="rd-h">STEP 3C — Disinsentif</h4><table class="rd-tab"><tbody>'
        +'<tr><td class="rd-code">1</td><td class="rd-nm">Pelanggaran integritas akademik</td><td class="rd-val">'+(d1==='ya'?'Ya':'Tidak')+'</td></tr>'
        +'<tr><td class="rd-code">2</td><td class="rd-nm">Ethical Clearance</td><td class="rd-val">'+(d2==='ya'?'Ada':'Tidak ada')+'</td></tr>'
        +'</tbody></table>';
    wrap.innerHTML=html;
  }

  // ---- Hitung skor akhir + prediksi ----
  window.evCompute = function(){
    const a=parseFloat(document.getElementById('s3a').value)||0;
    const b=parseFloat(document.getElementById('s3b').value)||0;
    const total=Math.round((a+b)*100)/100;
    document.getElementById('bd3a').textContent=a;
    document.getElementById('bd3b').textContent=b;
    document.getElementById('bdTot').textContent=total;
    document.getElementById('resScore').textContent=total;

    const d1=form.querySelector('[name=d1]:checked')?.value; // pelanggaran
    const d2=form.querySelector('[name=d2]:checked')?.value; // ethical clearance
    const disFail = (d1==='ya') || (d2==='tidak');

    let label,color,range;
    if(total>=90){label='Terakreditasi Peringkat 1';color='#15803d';range='90 ≤ n ≤ 100';}
    else if(total>=80){label='Terakreditasi Peringkat 2';color='#16a34a';range='80 ≤ n < 90';}
    else if(total>=70){label='Terakreditasi Peringkat 3';color='#ca8a04';range='70 ≤ n < 80';}
    else if(total>=60){label='Terakreditasi Peringkat 4';color='#ea580c';range='60 ≤ n < 70';}
    else {label='Tidak Terakreditasi';color='#dc2626';range='n < 60';}

    const band=document.getElementById('resBand');
    band.textContent=label; band.style.background=color;
    document.getElementById('resRange').textContent='Rentang: '+range;
    document.getElementById('resScore').style.color=color;

    const w=document.getElementById('disWarn');
    if(disFail){
      const reasons=[];
      if(d1==='ya') reasons.push('terdapat pelanggaran integritas akademik');
      if(d2==='tidak') reasons.push('tidak ada Ethical Clearance');
      w.style.display='';
      w.innerHTML='⚠️ <strong>Disinsentif terdeteksi</strong> ('+reasons.join(' & ')+'). '+
        'Kondisi ini dapat menggugurkan atau menurunkan peringkat terlepas dari skor. Perbaiki sebelum mengajukan.';
    } else { w.style.display='none'; }

    const dt=document.getElementById('resDate');
    if(dt) dt.textContent=new Date().toLocaleDateString('id-ID',{day:'numeric',month:'long',year:'numeric'});

    evBuildDetail();
  };

  // ---- Download hasil sebagai PDF ----
  window.evDownloadPDF = function(){
    const card=document.getElementById('resCard');
    const fname='Evaluasi_Diri_'+<?= json_encode(preg_replace('/[^A-Za-z0-9]+/', '_', $pf_nama ?: 'Jurnal')) ?>+'.pdf';
    if(!(window.jspdf && window.html2canvas)){ window.print(); return; }
    const btn=document.getElementById('btnPdf'); const old=btn.textContent; btn.disabled=true; btn.textContent='Menyiapkan…';
    window.html2canvas(card,{scale:2,backgroundColor:'#ffffff',windowWidth:card.scrollWidth}).then(cv=>{
      const {jsPDF}=window.jspdf;
      const pdf=new jsPDF('p','mm','a4');
      const m=10, pw=pdf.internal.pageSize.getWidth(), ph=pdf.internal.pageSize.getHeight();
      const iw=pw-m*2;
      const pxPerMm=cv.width/iw;             // canvas px per mm pada lebar target
      const pageHpx=Math.floor((ph-m*2)*pxPerMm); // tinggi 1 halaman (px kanvas)
      let y=0, page=0;
      while(y<cv.height){
        const sliceH=Math.min(pageHpx, cv.height-y);
        const c=document.createElement('canvas'); c.width=cv.width; c.height=sliceH;
        const ctx=c.getContext('2d'); ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,c.width,sliceH);
        ctx.drawImage(cv,0,y,cv.width,sliceH,0,0,cv.width,sliceH);
        if(page>0) pdf.addPage();
        pdf.addImage(c.toDataURL('image/png'),'PNG',m,m,iw,sliceH/pxPerMm);
        y+=sliceH; page++;
      }
      pdf.save(fname);
      btn.disabled=false; btn.textContent=old;
    }).catch(()=>{ btn.disabled=false; btn.textContent=old; window.print(); });
  };

  // ---- Init: resume draft bila ada ----
  if (window.__evDraft){ hydrate(window.__evDraft); } else { evRenderIssues(); }
  const startStep = (typeof window.__evStep==='number') ? window.__evStep : 0;
  if (startStep===7) evCompute();
  show(startStep);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
