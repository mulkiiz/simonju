<?php
/**
 * Lightweight SMTP mailer via SSL socket (port 465 / SMTPS).
 * No external dependencies. Loads config.smtp.php jika constants belum terdefinisi.
 */
if (!defined('SMTP_HOST')) {
    require_once __DIR__ . '/config.smtp.php';
}

/**
 * @param string $to        Recipient email
 * @param string $to_name   Recipient display name
 * @param string $subject   Email subject
 * @param string $body      Plain-text body (UTF-8)
 * @return array [bool $ok, string $message]
 */
function send_smtp_mail(string $to, string $to_name, string $subject, string $body, bool $is_html = false): array
{
    $host      = SMTP_HOST;
    $port      = SMTP_PORT;
    $username  = SMTP_USER;
    $password  = SMTP_PASS;
    $from      = SMTP_FROM;
    $from_name = SMTP_FROM_NAME;

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    $sock = @stream_socket_client(
        "ssl://{$host}:{$port}", $errno, $errstr, 30,
        STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$sock) {
        return [false, "Gagal koneksi SMTP ({$host}:{$port}): {$errstr} ({$errno})"];
    }

    stream_set_timeout($sock, 15);

    // Read multi-line response, return last line
    $read = function () use ($sock): string {
        $out = '';
        while (($line = fgets($sock, 512)) !== false) {
            $out = $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $out;
    };

    $cmd = function (string $c) use ($sock, $read): string {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    $greeting = $read();
    if (substr($greeting, 0, 3) !== '220') {
        fclose($sock);
        return [false, "SMTP greeting error: {$greeting}"];
    }

    $ehlo = $cmd('EHLO localhost');
    // EHLO may return multi-line 250; read additional lines
    while (strlen($ehlo) >= 4 && $ehlo[3] === '-') {
        $ehlo = $read();
    }
    if (substr($ehlo, 0, 3) !== '250') {
        fclose($sock);
        return [false, "EHLO gagal: {$ehlo}"];
    }

    $cmd('AUTH LOGIN');
    $cmd(base64_encode($username));
    $auth = $cmd(base64_encode($password));
    if (substr($auth, 0, 3) !== '235') {
        fclose($sock);
        return [false, "AUTH gagal (cek username/password SMTP): {$auth}"];
    }

    $cmd("MAIL FROM:<{$from}>");

    $rcpt = $cmd("RCPT TO:<{$to}>");
    if (substr($rcpt, 0, 3) !== '250') {
        fclose($sock);
        return [false, "RCPT gagal untuk {$to}: {$rcpt}"];
    }

    $data_prompt = $cmd('DATA');
    if (substr($data_prompt, 0, 3) !== '354') {
        fclose($sock);
        return [false, "DATA gagal: {$data_prompt}"];
    }

    $enc_from    = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
    $enc_to      = '=?UTF-8?B?' . base64_encode($to_name)   . '?=';
    $enc_subject = '=?UTF-8?B?' . base64_encode($subject)   . '?=';
    $date        = date('r');

    $msg  = "Date: {$date}\r\n";
    $msg .= "From: {$enc_from} <{$from}>\r\n";
    $msg .= "To: {$enc_to} <{$to}>\r\n";
    $msg .= "Subject: {$enc_subject}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";

    if ($is_html) {
        // multipart/alternative: plain fallback + HTML
        $boundary = 'bnd_' . bin2hex(random_bytes(8));
        $plain = trim(html_entity_decode(
            strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $body)),
            ENT_QUOTES | ENT_HTML5, 'UTF-8'
        ));
        $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($plain)) . "\r\n";
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($body)) . "\r\n";
        $msg .= "--{$boundary}--\r\n";
    } else {
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body));
    }

    fwrite($sock, $msg . "\r\n.\r\n");
    $send_resp = $read();

    $cmd('QUIT');
    fclose($sock);

    if (substr($send_resp, 0, 3) !== '250') {
        return [false, "Pengiriman gagal: {$send_resp}"];
    }
    return [true, "Email berhasil dikirim ke {$to}."];
}

/**
 * Build the standard jurnal invitation email body (HTML).
 * Kirim via send_smtp_mail(..., is_html: true).
 */
function build_jurnal_email(string $nama_jurnal, string $username, string $password): string
{
    $nj   = htmlspecialchars($nama_jurnal, ENT_QUOTES, 'UTF-8');
    $usr  = htmlspecialchars($username,    ENT_QUOTES, 'UTF-8');
    $pwd  = htmlspecialchars($password,    ENT_QUOTES, 'UTF-8');
    $url  = htmlspecialchars(rtrim(APP_URL, '/') . '/', ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!doctype html>
<html lang="id">
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:28px 32px;text-align:center;">
            <div style="font-size:26px;">📚</div>
            <div style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:.5px;">SIMONJU</div>
            <div style="color:#bfdbfe;font-size:12px;font-style:italic;">Sistem Monitoring Jurnal &middot; LPPM Unsoed</div>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:28px 32px;">
            <p style="margin:0 0 4px;">Yth. <strong>Ketua Editor</strong></p>
            <p style="margin:0 0 2px;font-size:17px;font-weight:700;color:#1e3a8a;">{$nj}</p>
            <p style="margin:0 0 20px;font-style:italic;color:#64748b;">di tempat</p>

            <p style="margin:0 0 14px;">Berikut <strong>akun login</strong> Anda untuk aplikasi <strong>SIMONJU</strong>:</p>

            <!-- Credential box -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin:0 0 22px;">
              <tr>
                <td style="padding:16px 20px;">
                  <p style="margin:0 0 8px;font-size:13px;">👤 <span style="color:#64748b;">Username</span><br>
                    <code style="font-size:16px;font-weight:700;color:#0f172a;letter-spacing:.5px;">{$usr}</code></p>
                  <p style="margin:0;font-size:13px;">🔑 <span style="color:#64748b;">Password</span><br>
                    <code style="font-size:16px;font-weight:700;color:#0f172a;letter-spacing:.5px;">{$pwd}</code></p>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 22px;text-align:center;">
              <a href="{$url}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 28px;border-radius:8px;">🚀 Masuk ke SIMONJU</a>
            </p>

            <!-- Todo -->
            <p style="margin:0 0 8px;font-weight:700;">📌 Yang perlu dilakukan:</p>
            <ul style="margin:0 0 20px;padding-left:20px;color:#334155;">
              <li style="margin-bottom:4px;">Upload <em>sertifikat akreditasi</em> jurnal terakhir</li>
              <li>Upload <em>cover jurnal</em> terkini</li>
            </ul>

            <!-- Fitur -->
            <p style="margin:0 0 8px;font-weight:700;">✨ Fitur tersedia:</p>
            <ul style="margin:0 0 8px;padding-left:20px;color:#334155;">
              <li style="margin-bottom:6px;">🔄 <strong>Crawl Sekarang</strong> &mdash; <em>ambil statistik volume terbit jurnal Anda</em></li>
              <li style="margin-bottom:6px;">🛡️ <strong>Scan Judul</strong> &mdash; <em>deteksi injeksi judi online di jurnal Anda</em></li>
              <li>✏️ <strong>Edit</strong> &mdash; <em>perbarui data ketua editor</em></li>
            </ul>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 32px;">
            <p style="margin:0;font-size:13px;color:#64748b;">Hormat kami,</p>
            <p style="margin:2px 0 0;font-size:13px;"><strong>Pusat Pengelolaan Jurnal</strong><br>
              <span style="font-style:italic;color:#64748b;">LPPM, Universitas Jenderal Soedirman</span></p>
          </td>
        </tr>

      </table>
      <p style="margin:14px 0 0;font-size:11px;color:#94a3b8;">Email otomatis dari SIMONJU. Mohon tidak membalas email ini.</p>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
