<?php
/**
 * includes/rubrik.php — Loader master rubrik evaluasi diri akreditasi.
 * Dipakai oleh jurnal/evaluasi.php (render form) & admin/rubrik.php (editor).
 */
require_once __DIR__ . '/db.php';

/** Target maksimum tiap kategori (sesuai instrumen ARJUNA). */
function rubrik_targets() {
    return ['tata_kelola' => 46, 'mutu_artikel' => 54];
}

function rubrik_kategori_label($k) {
    return $k === 'mutu_artikel' ? 'Standar Mutu Artikel' : 'Standar Tata Kelola';
}

/**
 * Muat seluruh rubrik, dikelompokkan per kategori.
 * @return array [kategori => ['label','target','max',(float),'unsur'=>[
 *                 ['id','kode','nama','max','kriteria'=>[['id','kriteria','nilai'],...]]]]]
 */
function rubrik_load() {
    $targets = rubrik_targets();
    $out = [];
    foreach ($targets as $kat => $target) {
        $out[$kat] = [
            'label'  => rubrik_kategori_label($kat),
            'target' => $target,
            'max'    => 0.0,
            'unsur'  => [],
        ];
    }

    $unsur = fetch_all("SELECT id, kategori, kode, nama FROM rubrik_unsur ORDER BY kategori, urutan, id");
    if (!$unsur) return $out;

    $byId = [];
    foreach ($unsur as $u) {
        $u['max'] = 0.0;
        $u['kriteria'] = [];
        $byId[(int)$u['id']] = $u;
    }

    $krit = fetch_all("SELECT id, unsur_id, kriteria, nilai FROM rubrik_kriteria ORDER BY unsur_id, urutan, id");
    foreach ($krit as $k) {
        $uid = (int)$k['unsur_id'];
        if (!isset($byId[$uid])) continue;
        $byId[$uid]['kriteria'][] = $k;
        $byId[$uid]['max'] = max($byId[$uid]['max'], (float)$k['nilai']);
    }

    foreach ($byId as $u) {
        $kat = $u['kategori'];
        if (!isset($out[$kat])) continue;
        $out[$kat]['unsur'][] = $u;
        $out[$kat]['max'] += (float)$u['max'];
    }
    return $out;
}

/** Format angka nilai: buang desimal .00 -> tampil rapi (2, 1.5, 0.5). */
function rubrik_num($v) {
    $v = (float)$v;
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}
