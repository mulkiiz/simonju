<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Gunakan POST.']);
    exit;
}
if (empty($_POST['_csrf']) || !is_string($_POST['_csrf']) || empty($_SESSION['csrf'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF tidak valid. Muat ulang halaman.']);
    exit;
}
csrf_check();
$action = $_POST['action'] ?? '';
if (!in_array($action, ['start', 'step', 'restart', 'retry'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Aksi tidak valid.']);
    exit;
}
session_write_close();
@set_time_limit(60);
require_once __DIR__ . '/../lib/sinta_history.php';
try {
    echo json_encode(sinta_history_sync($action), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(503);
    error_log('SINTA history: ' . $e->getMessage());
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Sinkronisasi gagal. Periksa koneksi/database dan log server.';
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
