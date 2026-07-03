<?php
/**
 * /konfirmasi/verifikasi.php
 * Gerbang anti-bot form publik "Konfirmasi Jurnal Baru".
 * Wajib verifikasi email @unsoed.ac.id via OTP sebelum boleh isi form.
 */
require_once __DIR__ . '/_konf.php';

// Sudah terverifikasi → langsung ke form
if (konf_verified_email() !== null) {
    header('Location: jurnal_baru.php');
    exit;
}

$errors = [];
$notice = '';
$otp    = $_SESSION['konf_otp'] ?? null;
$step   = ($otp && time() < ($otp['exp'] ?? 0)) ? 'otp' : 'email';
$email  = $otp['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    konf_csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'send' || $act === 'resend') {
        $email = trim($_POST['email'] ?? ($otp['email'] ?? ''));
        [$ok, $m] = konf_otp_send($email);
        if ($ok) { $step = 'otp'; $notice = $m; }
        else     { $errors[] = $m; $step = ($act === 'resend') ? 'otp' : 'email'; }
    } elseif ($act === 'verify') {
        [$ok, $m] = konf_otp_verify($_POST['code'] ?? '');
        if ($ok) { header('Location: jurnal_baru.php'); exit; }
        $errors[] = $m;
        $step  = isset($_SESSION['konf_otp']) ? 'otp' : 'email';
        $email = $_SESSION['konf_otp']['email'] ?? $email;
    }
}

konf_header('Verifikasi Email');
?>
  <div class="konf-card" style="max-width:480px;margin:0 auto">
    <h2 style="margin-top:0;font-size:1.1rem;color:#1c3a6e">Konfirmasi Jurnal Baru</h2>
    <p style="color:#5a6675;font-size:.88rem;margin:0 0 4px">
      Verifikasi wajib menggunakan <strong>email resmi @unsoed.ac.id</strong>
      yang aktif. Kode OTP akan dikirim ke email tersebut.
    </p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="max-width:480px;margin:0 auto 14px">
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($notice): ?>
    <div style="max-width:480px;margin:0 auto 14px;padding:11px 14px;border-radius:9px;background:#d8f3e3;color:#1c7a47;border:1px solid #b6e4c9;font-size:.9rem">
      <?= h($notice) ?>
    </div>
  <?php endif; ?>

  <div class="konf-card" style="max-width:480px;margin:0 auto">
  <?php if ($step === 'email'): ?>
    <form method="post" class="konf-form" action="verifikasi.php">
      <?= konf_csrf_field() ?>
      <input type="hidden" name="action" value="send">
      <label>Email @unsoed.ac.id <span class="req">*</span>
        <input type="email" name="email" required autofocus
               value="<?= h($email) ?>" placeholder="nama@unsoed.ac.id">
      </label>
      <button type="submit" class="btn btn-primary btn-block">Kirim Kode Verifikasi</button>
    </form>
  <?php else: ?>
    <p style="color:#5a6675;font-size:.88rem;margin:0 0 14px">
      Kode 6 digit dikirim ke <strong><?= h($email) ?></strong>.
      Masukkan kode di bawah (berlaku 10 menit).
    </p>
    <form method="post" class="konf-form" action="verifikasi.php">
      <?= konf_csrf_field() ?>
      <input type="hidden" name="action" value="verify">
      <label>Kode Verifikasi <span class="req">*</span>
        <input type="text" name="code" required autofocus inputmode="numeric"
               pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
               placeholder="######"
               style="letter-spacing:8px;text-align:center;font-size:1.3rem;font-weight:700">
      </label>
      <button type="submit" class="btn btn-primary btn-block">Verifikasi &amp; Lanjut</button>
    </form>
    <form method="post" action="verifikasi.php" style="margin-top:10px;text-align:center">
      <?= konf_csrf_field() ?>
      <input type="hidden" name="action" value="resend">
      <button type="submit" class="btn btn-link" style="background:none;border:none;color:#1c3a6e;text-decoration:underline;cursor:pointer;font-size:.85rem;padding:0">
        Kirim ulang kode / ganti email
      </button>
    </form>
  <?php endif; ?>
  </div>

  <p class="konf-note" style="margin-top:18px">
    <a href="../index.php">&larr; Kembali ke beranda</a>
  </p>
<?php
konf_footer();
