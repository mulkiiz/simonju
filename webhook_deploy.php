<?php
/**
 * Deploy webhook — dipanggil GitHub Actions setelah push.
 * Token disimpan di /home/jurz2196/.deploy_token (luar public_html).
 */

// Baca secret dari file di luar web root
$token_file = '/home/jurz2196/.deploy_token';
if (!is_readable($token_file)) {
    http_response_code(500);
    die(json_encode(['error' => 'Token file tidak ditemukan di server.']));
}

$expected = trim(file_get_contents($token_file));
$provided = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

if (empty($expected) || !hash_equals($expected, $provided)) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden.']));
}

// Jalankan deploy
$repo = '/home/jurz2196/repo/simonju';
$dest = '/home/jurz2196/public_html/ppj.jurnalsinta.id';
$log  = [];

exec("cd {$repo} && git pull origin master 2>&1", $log, $code);

if ($code !== 0) {
    http_response_code(500);
    die(json_encode(['error' => 'git pull gagal.', 'log' => $log]));
}

// Copy ke production (sama dengan .cpanel.yml)
$dirs = ['admin', 'assets', 'cron', 'docs', 'includes', 'jurnal', 'konfirmasi', 'lib'];
foreach ($dirs as $d) {
    if (is_dir("{$repo}/{$d}")) {
        exec("/bin/cp -R {$repo}/{$d}/. {$dest}/{$d}/ 2>&1", $log);
    }
}
foreach (['index.php', 'logout.php', '.htaccess', 'webhook_deploy.php'] as $f) {
    if (file_exists("{$repo}/{$f}")) {
        exec("/bin/cp {$repo}/{$f} {$dest}/{$f} 2>&1", $log);
    }
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'log' => $log]);
