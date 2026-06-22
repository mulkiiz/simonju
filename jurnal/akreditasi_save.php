<?php
/**
 * jurnal/akreditasi_save.php — Simpan masa berlaku akreditasi (tmt_akreditasi).
 * Format SK: "mulai Vol .. No .. Th .. sampai Vol .. No .. Th .."
 * Dipakai akun jurnal maupun admin. Upsert 1 baris per jurnal.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_jurnal_user()) {
    require_jurnal();
    $jid  = current_jurnal_id();
    $back = 'index.php';
} elseif (is_admin()) {
    require_admin();
    $jid  = (int)($_POST['jurnal_id'] ?? 0);
    $back = 'jurnal_view.php?id=' . $jid;
} else {
    header('Location: /'); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: {$back}"); exit; }
csrf_check();

$j = fetch_one("SELECT id, akreditasi_jenis, is_scopus FROM jurnals WHERE id=?", 'i', [$jid]);
if (!$j) { header("Location: {$back}"); exit; }

// Hanya jurnal terakreditasi yang boleh isi masa berlaku
$is_akreditasi = !in_array((string)($j['akreditasi_jenis'] ?? ''), ['', 'belum'], true)
              || (int)($j['is_scopus'] ?? 0) === 1;
if (!$is_akreditasi) {
    header("Location: {$back}?akr=fail&msg=" . urlencode('Jurnal belum terakreditasi.')); exit;
}

// Ambil & rapikan input (maks panjang sesuai kolom)
$cut = function ($v, $n) { return mb_substr(trim((string)$v), 0, $n); };
$no_sk     = $cut($_POST['no_sk']     ?? '', 150);
$peringkat = $cut($_POST['peringkat'] ?? '', 50);
$mv = $cut($_POST['mulai_volume']  ?? '', 20);
$mn = $cut($_POST['mulai_nomor']   ?? '', 20);
$mt = $cut($_POST['mulai_tahun']   ?? '', 10);
$sv = $cut($_POST['sampai_volume'] ?? '', 20);
$sn = $cut($_POST['sampai_nomor']  ?? '', 20);
$st = $cut($_POST['sampai_tahun']  ?? '', 10);

// Validasi ringan: tahun harus angka bila diisi
foreach (['mulai_tahun'=>$mt, 'sampai_tahun'=>$st] as $lbl => $val) {
    if ($val !== '' && !preg_match('/^\d{4}$/', $val)) {
        header("Location: {$back}?akr=fail&msg=" . urlencode('Tahun harus 4 digit angka.')); exit;
    }
}

$exists = fetch_one("SELECT id FROM akreditasi_periode WHERE jurnal_id=? LIMIT 1", 'i', [$jid]);

if ($exists) {
    exec_q(
        "UPDATE akreditasi_periode SET
            no_sk=?, peringkat=?,
            mulai_volume=?, mulai_nomor=?, mulai_tahun=?,
            sampai_volume=?, sampai_nomor=?, sampai_tahun=?
         WHERE jurnal_id=?",
        'ssssssssi',
        [$no_sk, $peringkat, $mv, $mn, $mt, $sv, $sn, $st, $jid]
    );
} else {
    exec_q(
        "INSERT INTO akreditasi_periode
            (jurnal_id, no_sk, peringkat,
             mulai_volume, mulai_nomor, mulai_tahun,
             sampai_volume, sampai_nomor, sampai_tahun)
         VALUES (?,?,?,?,?,?,?,?,?)",
        'issssssss',
        [$jid, $no_sk, $peringkat, $mv, $mn, $mt, $sv, $sn, $st]
    );
}

// Sinkron peringkat ke tabel jurnals (perbaiki bila salah pilih saat konfirmasi)
if ($peringkat !== '') {
    exec_q("UPDATE jurnals SET akreditasi_peringkat=? WHERE id=?", 'si', [$peringkat, $jid]);
}

header("Location: {$back}?akr=ok&msg=" . urlencode('Masa berlaku akreditasi tersimpan.'));
exit;
