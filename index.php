<?php
require_once __DIR__ . '/includes/auth.php';

// Sudah login → arahkan ke dashboard sesuai peran
if (is_logged_in()) {
    if (is_jurnal_user()) {
        header('Location: jurnal/');
    } elseif (function_exists('is_doi_admin') && is_doi_admin()) {
        header('Location: admin/doi_requests.php');
    } else {
        header('Location: admin/');
    }
    exit;
}

$error = '';
$msg = $_GET['msg'] ?? '';
if ($msg === 'timeout') $error = 'Sesi habis. Silakan login ulang.';
if ($msg === 'logout')  $error = 'Anda telah keluar.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u === '' || $p === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        [$ok, $result] = attempt_login($u, $p);
        if ($ok) {
            if ($result === 'jurnal') {
                header('Location: jurnal/');
            } elseif ($result === 'doi') {
                header('Location: admin/doi_requests.php');
            } else {
                header('Location: admin/');
            }
            exit;
        }
        $error = $result;
    }
}

// Modal login otomatis terbuka bila ada error / pesan / POST
$open_login = ($error !== '' || $msg !== '' || $_SERVER['REQUEST_METHOD'] === 'POST');

// --- Statistik publik (angka nyata untuk hero) ---
function _stat($sql) {
    $r = fetch_one($sql, '', []);
    return $r ? (int) array_values($r)[0] : 0;
}
$stat_jurnal = _stat("SELECT COUNT(*) c FROM jurnals WHERE konfirmasi_status='terkonfirmasi'");
$stat_sinta  = _stat("SELECT COUNT(*) c FROM jurnals WHERE konfirmasi_status='terkonfirmasi' AND akreditasi_jenis='sinta' AND akreditasi_peringkat<>''");
$stat_scopus = _stat("SELECT COUNT(*) c FROM jurnals WHERE konfirmasi_status='terkonfirmasi' AND is_scopus=1");
$stat_sinta2 = _stat("SELECT COUNT(*) c FROM jurnals WHERE konfirmasi_status='terkonfirmasi' AND akreditasi_jenis='sinta' AND akreditasi_peringkat='Sinta 2'");

// --- Data tim PPJ ---
$ketua = 'Dr. Ir. Mulki Indana Zulfa, S.T., M.T., IPM.';
$anggota = [
    ['nama' => 'Romanus Edy Prabowo, S.Si., M.Sc., Ph.D.', 'pic' => 'PIC DOI'],
    ['nama' => 'Dr. Ir. Ari Fadli, S.T., M.Eng., IPM.',     'pic' => 'PIC Manajemen OJS'],
    ['nama' => 'Galih Noor Alivian, S.Kep., Ners., M.Kep.', 'pic' => 'PIC Akreditasi'],
    ['nama' => 'Anzar Alfat Firdaus, S.Pd., M.E.',          'pic' => 'PIC ISSN'],
    ['nama' => 'Purwo Subroto, A.Md.',                      'pic' => 'PIC Server OJS'],
];
function _inisial($nama) {
    // Ambil huruf depan kata "asli" (lewati gelar), maks 2
    $skip = ['dr','ir','st','mt','msc','ssi','phd','meng','ipm','mkep','spd','me','amd','s','t','m'];
    $out = '';
    foreach (preg_split('/[\s.,]+/', $nama) as $w) {
        $lw = strtolower(trim($w));
        if ($lw === '' || in_array($lw, $skip, true) || mb_strlen($w) < 2) continue;
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($out) >= 2) break;
    }
    return $out ?: 'PPJ';
}

$layanan = [
    ['icon' => '📘', 'judul' => 'Pendampingan ISSN',            'desc' => 'Pengajuan dan pengelolaan nomor ISSN (cetak & elektronik) untuk jurnal baru di lingkungan Unsoed.'],
    ['icon' => '🏅', 'judul' => 'Akreditasi Jurnal Nasional',   'desc' => 'Pendampingan menuju akreditasi SINTA (Arjuna) — dari kesiapan tata kelola hingga pengajuan peringkat.'],
    ['icon' => '🌐', 'judul' => 'Pendampingan Scopus',          'desc' => 'Strategi internasionalisasi jurnal menuju indeksasi Scopus dan basis data bereputasi global.'],
    ['icon' => '🛠️', 'judul' => 'Workshop Manajemen OJS',       'desc' => 'Pelatihan pengelolaan Open Journal Systems: alur editorial, penerbitan, hingga deposit DOI.'],
];
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIMONJU &middot; Pusat Pengelolaan Jurnal LPPM Unsoed</title>
<meta name="description" content="Sistem Monitoring Jurnal (SIMONJU) — Pusat Pengelolaan Jurnal, LPPM Universitas Jenderal Soedirman. Pendampingan ISSN, akreditasi SINTA, Scopus, dan manajemen OJS.">
<link rel="icon" type="image/png" href="assets/logo_unsoed.png">
<style>
:root{
  --navy:#0c1e4a; --navy2:#1e3a8a; --blue:#1d4ed8; --gold:#fbbf24;
  --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --bg:#f5f7fa;
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);background:#fff;line-height:1.6}
a{color:var(--blue);text-decoration:none}
.wrap{max-width:1120px;margin:0 auto;padding:0 24px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:999px;font-size:15px;font-weight:600;cursor:pointer;border:1.5px solid transparent;transition:.18s;text-decoration:none}
.btn-primary{background:var(--gold);color:#111;border-color:var(--gold)}
.btn-primary:hover{background:#f5b301;transform:translateY(-1px)}
.btn-ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.5)}
.btn-ghost:hover{background:rgba(255,255,255,.12)}
.btn-dark{background:var(--navy2);color:#fff;border-color:var(--navy2)}
.btn-dark:hover{background:var(--navy)}

/* NAV */
.nav{position:sticky;top:0;z-index:40;background:rgba(12,30,74,.96);backdrop-filter:blur(8px);color:#fff;box-shadow:0 2px 12px rgba(0,0,0,.18)}
.nav-in{display:flex;align-items:center;justify-content:space-between;height:66px}
.brand{display:flex;align-items:center;gap:12px}
.brand img{height:46px;width:46px;object-fit:contain;background:#fff;border-radius:12px;padding:5px;box-shadow:0 2px 6px rgba(0,0,0,.2)}
.brand b{font-size:19px;letter-spacing:1.5px;font-weight:800}
.brand small{display:block;font-size:11px;color:#cbd5e1;letter-spacing:.3px;font-weight:400}
.nav-links{display:flex;align-items:center;gap:26px}
.nav-links a{color:#e2e8f0;font-size:14.5px;font-weight:500}
.nav-links a:hover{color:var(--gold)}
.nav-links a.nav-cta{padding:8px 18px;font-size:14px;color:#111}
.nav-links a.nav-cta:hover{color:#111}
@media(max-width:760px){.nav-links a:not(.nav-cta){display:none}}

/* HERO */
.hero{background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 100%);color:#fff;position:relative;overflow:hidden}
.hero::after{content:"";position:absolute;right:-120px;top:-120px;width:420px;height:420px;background:radial-gradient(circle,rgba(251,191,36,.18),transparent 70%);pointer-events:none}
.hero-in{display:grid;grid-template-columns:1.05fr .95fr;gap:48px;align-items:center;padding:72px 0 84px}
.pill{display:inline-flex;align-items:center;gap:7px;background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.4);color:var(--gold);padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;margin-bottom:20px}
.hero h1{font-size:42px;line-height:1.15;margin:0 0 18px;font-weight:800;letter-spacing:-.5px}
.hero h1 span{color:var(--gold)}
.hero p.lead{font-size:17.5px;color:#dbe4f3;margin:0 0 30px;max-width:520px}
.hero-actions{display:flex;gap:14px;flex-wrap:wrap}
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.stat{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:22px 20px;backdrop-filter:blur(4px)}
.stat b{display:block;font-size:38px;font-weight:800;color:#fff;line-height:1}
.stat span{display:block;margin-top:8px;font-size:13.5px;color:#c7d2e6;font-weight:500}
.stat.g b{color:var(--gold)}
@media(max-width:860px){.hero-in{grid-template-columns:1fr;gap:36px;padding:52px 0 60px}.hero h1{font-size:33px}}

/* SECTION */
section.blk{padding:78px 0}
.sec-head{text-align:center;max-width:640px;margin:0 auto 48px}
.sec-head .eyebrow{color:var(--blue);font-weight:700;font-size:13px;letter-spacing:1.5px;text-transform:uppercase}
.sec-head h2{font-size:31px;margin:10px 0 12px;font-weight:800;letter-spacing:-.4px}
.sec-head p{color:var(--muted);font-size:16.5px;margin:0}
.alt{background:var(--bg)}

/* LAYANAN */
.svc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:22px}
.svc{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px 22px;transition:.2s}
.svc:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(12,30,74,.10);border-color:#c9d6f0}
.svc .ic{width:54px;height:54px;display:flex;align-items:center;justify-content:center;font-size:26px;background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-radius:14px;margin-bottom:16px}
.svc h3{margin:0 0 8px;font-size:17px;font-weight:700}
.svc p{margin:0;color:var(--muted);font-size:14.5px}
@media(max-width:900px){.svc-grid{grid-template-columns:1fr 1fr}}
@media(max-width:520px){.svc-grid{grid-template-columns:1fr}}

/* TIM */
.lead-card{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;border-radius:20px;padding:34px;display:flex;align-items:center;gap:24px;margin-bottom:26px}
.lead-card .ava{width:76px;height:76px;border-radius:50%;background:var(--gold);color:var(--navy);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;flex-shrink:0}
.lead-card .role{color:var(--gold);font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase}
.lead-card h3{margin:6px 0 0;font-size:23px}
.team-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.member{display:flex;align-items:center;gap:15px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 20px}
.member .ava{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--navy2),var(--blue));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:17px;flex-shrink:0}
.member .nm{font-weight:600;font-size:15px;line-height:1.35}
.member .tag{display:inline-block;margin-top:5px;font-size:11.5px;font-weight:700;color:var(--blue);background:#eef2ff;padding:3px 9px;border-radius:999px}
@media(max-width:880px){.team-grid{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.team-grid{grid-template-columns:1fr}.lead-card{flex-direction:column;text-align:center}}

/* CTA */
.cta{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;text-align:center;border-radius:24px;padding:56px 28px;margin:0 24px}
.cta h2{font-size:29px;margin:0 0 12px;font-weight:800}
.cta p{color:#dbe4f3;font-size:16.5px;margin:0 0 26px}

/* FOOTER */
footer{background:var(--navy);color:#c7d2e6;padding:56px 0 26px;font-size:14.5px}
.foot-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:36px;margin-bottom:34px}
footer h4{color:#fff;font-size:15px;margin:0 0 14px;font-weight:700}
footer p{margin:0 0 8px;color:#b6c2da}
footer a{color:#fff}
.foot-brand{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.foot-brand img{width:42px;height:42px;background:#fff;border-radius:50%;padding:4px}
.foot-brand b{color:#fff;font-size:18px;letter-spacing:1.5px}
.foot-bot{border-top:1px solid rgba(255,255,255,.12);padding-top:20px;text-align:center;color:#94a3c4;font-size:13px}
@media(max-width:720px){.foot-grid{grid-template-columns:1fr}}

/* MODAL LOGIN */
.modal{position:fixed;inset:0;z-index:100;display:none;align-items:center;justify-content:center;background:rgba(8,15,35,.62);backdrop-filter:blur(3px);padding:20px}
.modal.open{display:flex}
.modal-card{background:#fff;border-radius:18px;width:100%;max-width:400px;padding:32px 30px;box-shadow:0 30px 60px rgba(0,0,0,.35);position:relative}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1}
.modal-head{text-align:center;margin-bottom:20px}
.modal-head img{width:56px;height:56px;margin-bottom:10px}
.modal-head h3{margin:0;font-size:20px;letter-spacing:2px;font-weight:800;color:var(--navy)}
.modal-head p{margin:4px 0 0;color:var(--muted);font-size:13.5px}
.alert{padding:10px 14px;border-radius:8px;font-size:14px;margin-bottom:16px}
.alert-error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.field{margin-bottom:14px}
.field label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px}
.field input{width:100%;padding:11px 13px;border:1px solid #d1d5db;border-radius:9px;font-size:15px;font-family:inherit}
.field input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(29,78,216,.15)}
.modal .btn-primary{background:var(--navy2);color:#fff;border-color:var(--navy2);width:100%;justify-content:center;padding:12px}
.modal .btn-primary:hover{background:var(--navy)}
</style>
</head>
<body>

<!-- NAV -->
<header class="nav">
  <div class="wrap nav-in">
    <div class="brand">
      <img src="assets/logo_unsoed.png" alt="Unsoed">
      <div><b>SIMONJU</b><small>Pusat Pengelolaan Jurnal · LPPM Unsoed</small></div>
    </div>
    <nav class="nav-links">
      <a href="#layanan">Layanan</a>
      <a href="#tim">Tim PPJ</a>
      <a href="#kontak">Kontak</a>
      <a href="#" class="btn btn-primary nav-cta" onclick="openLogin();return false">Masuk</a>
    </nav>
  </div>
</header>

<!-- HERO -->
<section class="hero">
  <div class="wrap hero-in">
    <div>
      <span class="pill">✦ Sistem Monitoring Jurnal Unsoed</span>
      <h1>Monitoring &amp; pengelolaan <span>jurnal ilmiah</span> Universitas Jenderal Soedirman</h1>
      <p class="lead">SIMONJU memantau tata kelola, akreditasi, dan indeksasi jurnal di lingkungan Unsoed — dikelola oleh Pusat Pengelolaan Jurnal LPPM.</p>
      <div class="hero-actions">
        <a href="#layanan" class="btn btn-ghost">Lihat Layanan</a>
      </div>
    </div>
    <div class="stat-grid">
      <div class="stat g"><b><?= $stat_jurnal ?></b><span>Jurnal terkelola</span></div>
      <div class="stat"><b><?= $stat_sinta ?></b><span>Terakreditasi SINTA</span></div>
      <div class="stat g"><b><?= $stat_scopus ?></b><span>Terindeks Scopus</span></div>
      <div class="stat"><b><?= $stat_sinta2 ?></b><span>Terakreditasi SINTA 2</span></div>
    </div>
  </div>
</section>

<!-- LAYANAN -->
<section class="blk" id="layanan">
  <div class="wrap">
    <div class="sec-head">
      <div class="eyebrow">Layanan PPJ</div>
      <h2>Pendampingan penuh pengelola jurnal</h2>
      <p>Pusat Pengelolaan Jurnal mendampingi jurnal Unsoed dari awal terbit, terakreditasi nasional, hingga bereputasi internasional.</p>
    </div>
    <div class="svc-grid">
      <?php foreach ($layanan as $s): ?>
      <div class="svc">
        <div class="ic"><?= $s['icon'] ?></div>
        <h3><?= h($s['judul']) ?></h3>
        <p><?= h($s['desc']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- TIM -->
<section class="blk alt" id="tim">
  <div class="wrap">
    <div class="sec-head">
      <div class="eyebrow">Tim PPJ</div>
      <h2>Pengelola Pusat Pengelolaan Jurnal</h2>
      <p>Tim penanggung jawab layanan pengelolaan jurnal LPPM Unsoed.</p>
    </div>
    <div class="lead-card">
      <div class="ava"><?= h(_inisial($ketua)) ?></div>
      <div>
        <div class="role">Ketua PPJ</div>
        <h3><?= h($ketua) ?></h3>
      </div>
    </div>
    <div class="team-grid">
      <?php foreach ($anggota as $m): ?>
      <div class="member">
        <div class="ava"><?= h(_inisial($m['nama'])) ?></div>
        <div>
          <div class="nm"><?= h($m['nama']) ?></div>
          <span class="tag"><?= h($m['pic']) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="blk">
  <div class="wrap">
    <div class="cta">
      <h2>Jurnal Online Soedirman (JOS)</h2>
      <p>Masuk ke jurnal ilmiah di lingkungan Universitas Jenderal Soedirman.</p>
      <a href="https://jos.unsoed.ac.id" target="_blank" rel="noopener" class="btn btn-primary">EXPLORE JOS</a>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer id="kontak">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <div class="foot-brand">
          <img src="assets/logo_unsoed.png" alt="Unsoed">
          <b>SIMONJU</b>
        </div>
        <p>Sistem Monitoring Jurnal — dikelola Pusat Pengelolaan Jurnal (PPJ), Lembaga Penelitian dan Pengabdian kepada Masyarakat (LPPM), Universitas Jenderal Soedirman.</p>
      </div>
      <div>
        <h4>Sekretariat PPJ</h4>
        <p>Gedung LPPM Universitas Jenderal Soedirman</p>
        <p>Jl. Dr. Soeparno, Karangwangkal, Purwokerto</p>
        <p>📧 <a href="mailto:pjurnal.unsoed@gmail.com">pjurnal.unsoed@gmail.com</a></p>
      </div>
      <div>
        <h4>Portal &amp; Tautan</h4>
        <p>🌐 <a href="https://unsoed.ac.id" target="_blank" rel="noopener">unsoed.ac.id</a></p>
        <p>🌐 <a href="https://lppm.unsoed.ac.id" target="_blank" rel="noopener">lppm.unsoed.ac.id</a></p>
        <p>🌐 <a href="https://rju.unsoed.ac.id" target="_blank" rel="noopener">rju.unsoed.ac.id</a></p>
        <p>🌐 <a href="https://jos.unsoed.ac.id" target="_blank" rel="noopener">jos.unsoed.ac.id</a></p>
      </div>
    </div>
    <div class="foot-bot">
      &copy; <?= date('Y') ?> SIMONJU — Pusat Pengelolaan Jurnal (PPJ), LPPM Universitas Jenderal Soedirman. Hak cipta dilindungi.
    </div>
  </div>
</footer>

<!-- MODAL LOGIN -->
<div class="modal<?= $open_login ? ' open' : '' ?>" id="loginModal">
  <div class="modal-card">
    <button class="modal-close" onclick="closeLogin()" aria-label="Tutup">&times;</button>
    <div class="modal-head">
      <img src="assets/logo_unsoed.png" alt="Unsoed">
      <h3>SIMONJU</h3>
      <p>Masuk ke dashboard</p>
    </div>
    <?php if ($error): ?>
      <div class="alert <?= ($msg==='logout'?'alert-info':'alert-error') ?>"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" required <?= $open_login ? 'autofocus' : '' ?> placeholder="Username admin atau slug jurnal">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required placeholder="Password">
      </div>
      <button type="submit" class="btn btn-primary">Masuk</button>
    </form>
  </div>
</div>

<script>
function openLogin(){document.getElementById('loginModal').classList.add('open');}
function closeLogin(){document.getElementById('loginModal').classList.remove('open');}
document.getElementById('loginModal').addEventListener('click',function(e){if(e.target===this)closeLogin();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeLogin();});
</script>
</body>
</html>
