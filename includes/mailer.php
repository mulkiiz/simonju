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
function send_smtp_mail(string $to, string $to_name, string $subject, string $body): array
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
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "\r\n";
    $msg .= chunk_split(base64_encode($body));

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
 * Build the standard jurnal invitation email body.
 */
function build_jurnal_email(string $nama_jurnal, string $username, string $password): string
{
    return
"Yth. Ketua Editor
{$nama_jurnal}
di tempat

Berikut adalah login yang dapat digunakan di aplikasi simonju:
* username = {$username}
* password = {$password}

URL simonju: " . APP_URL . "/

todo:
- upload sertifikat akreditasi jurnal terakhir
- upload cover jurnal terkini

fitur:
- tombol \"crawl sekarang\" = untuk mendapatkan statistic volume terbit di jurnal anda
- tombol \"scan judul\" = untuk cek injeksi judi online di jurnal anda
- tombol \"edit\" = jika ada perubahan/penambahan data ketua editor

Hormat kami,
Pusat Pengelolaan Jurnal
LPPM, Unsoed.";
}
