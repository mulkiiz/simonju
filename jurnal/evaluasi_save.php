<?php
/**
 * jurnal/evaluasi_save.php — Autosave draft evaluasi diri (per step).
 *
 * POST: _csrf, step (int), data (JSON string)
 * Upsert 1 baris per jurnal ke tabel evaluasi_draft.
 * Dipanggil via fetch() dari evaluasi.php setiap pindah step.
 */
require_once __DIR__ . '/../includes/auth.php';
require_jurnal();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
csrf_check();

$jid  = current_jurnal_id();
$step = max(0, min(7, (int)($_POST['step'] ?? 0)));
$data = (string)($_POST['data'] ?? '');

// Validasi: harus JSON valid & tak berlebihan.
if (strlen($data) > 200000) {
    echo json_encode(['ok' => false, 'error' => 'Data terlalu besar.']);
    exit;
}
$decoded = json_decode($data, true);
if ($data !== '' && $decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['ok' => false, 'error' => 'Payload bukan JSON valid.']);
    exit;
}

exec_q(
    "INSERT INTO evaluasi_draft (jurnal_id, step, data)
     VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE step=VALUES(step), data=VALUES(data)",
    'iis',
    [$jid, $step, $data]
);

echo json_encode(['ok' => true, 'step' => $step, 'saved_at' => date('H:i:s')]);
