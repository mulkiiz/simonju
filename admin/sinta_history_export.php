<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../lib/sinta_history.php';
require_once __DIR__ . '/../includes/lib_xlsx.php';

$currentYear = (int)date('Y');
try {
    $state = sinta_history_load();
} catch (Throwable $e) {
    error_log('SINTA history workbook: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Data history belum dapat dibaca.');
}

$headers = [
    'No', 'Nama Jurnal', 'Penerbit', 'P-ISSN', 'E-ISSN',
    'Peringkat Saat Ini', 'Tahun Habis Akreditasi', 'Tahun History',
    'Peringkat pada Tahun Habis Akreditasi', 'Terakhir Diperbarui',
];
$xlsx = new SimpleXLSX();
for ($year = $currentYear; $year <= $currentYear + 4; $year++) {
    $records = array_filter($state['records'], function ($record) use ($year) {
        if (empty($record['years'])) return false;
        return max(array_map('intval', array_keys($record['years']))) === $year;
    });
    usort($records, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

    $rows = [];
    foreach ($records as $record) {
        $years = $record['years'];
        $rank = array_key_exists($year, $years) && $years[$year] !== null
            ? 'Sinta ' . (int)$years[$year]
            : '';
        $rows[] = [
            count($rows) + 1,
            $record['name'] ?? '',
            $record['publisher'] ?? '',
            $record['p_issn'] ?? '',
            $record['e_issn'] ?? '',
            !empty($record['rank']) ? 'Sinta ' . (int)$record['rank'] : '',
            $year,
            implode(', ', array_keys($years)),
            $rank,
            $record['fetched_at'] ?? '',
        ];
    }
    $xlsx->addSheet((string)$year, $headers, $rows);
}

$xlsx->download('History_Akreditasi_Unsoed_' . $currentYear . '-' . ($currentYear + 4) . '.xlsx');
