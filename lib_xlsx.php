<?php
/**
 * lib_xlsx.php — Minimal multi-sheet XLSX writer.
 * Pure PHP 7.3, tanpa Composer / library.
 * Menghasilkan .xlsx valid (ZIP of XML) via ZipArchive.
 *
 * Pemakaian:
 *   $x = new SimpleXLSX();
 *   $x->addSheet('Nama Sheet', $headers, $rows);
 *   $x->download('nama_file.xlsx');
 */
class SimpleXLSX {
    private $sheets = [];

    /**
     * Tambah sheet.
     * @param string   $title   Nama sheet (max 31 char)
     * @param string[] $headers Baris header
     * @param array[]  $rows    Array of rows (each row = array of values)
     */
    public function addSheet($title, $headers, $rows) {
        $title = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', '', $title), 0, 31);
        $this->sheets[] = compact('title', 'headers', 'rows');
    }

    /** Download langsung ke browser */
    public function download($filename) {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /** Simpan ke file */
    public function save($path) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Gagal membuat XLSX: $path");
        }

        // Shared strings
        $strings = [];
        $strIndex = [];
        $addStr = function($s) use (&$strings, &$strIndex) {
            $s = (string)$s;
            if (!isset($strIndex[$s])) {
                $strIndex[$s] = count($strings);
                $strings[] = $s;
            }
            return $strIndex[$s];
        };

        // Pre-scan semua string
        foreach ($this->sheets as $sh) {
            foreach ($sh['headers'] as $h) $addStr($h);
            foreach ($sh['rows'] as $row) {
                foreach ($row as $v) {
                    if (!is_numeric($v) || $v === '') $addStr((string)$v);
                }
            }
        }

        // [Content_Types].xml
        $ct  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $ct .= '<Default Extension="xml" ContentType="application/xml"/>';
        $ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $ct .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        for ($i = 0; $i < count($this->sheets); $i++) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        // _rels/.rels
        $rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $rels .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // xl/_rels/workbook.xml.rels
        $wbr  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $wbr .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 0; $i < count($this->sheets); $i++) {
            $n = $i + 1;
            $wbr .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $rid = count($this->sheets) + 1;
        $wbr .= '<Relationship Id="rId' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rid++;
        $wbr .= '<Relationship Id="rId' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $wbr .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);

        // xl/workbook.xml
        $wb  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $wb .= '<sheets>';
        for ($i = 0; $i < count($this->sheets); $i++) {
            $n = $i + 1;
            $wb .= '<sheet name="' . $this->xmlEsc($this->sheets[$i]['title']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        }
        $wb .= '</sheets></workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        // xl/styles.xml  (minimal: header bold)
        $st  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $st .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $st .= '<fonts count="2">';
        $st .= '<font><sz val="11"/><name val="Arial"/></font>';
        $st .= '<font><b/><sz val="11"/><name val="Arial"/></font>';
        $st .= '</fonts>';
        $st .= '<fills count="3">';
        $st .= '<fill><patternFill patternType="none"/></fill>';
        $st .= '<fill><patternFill patternType="gray125"/></fill>';
        $st .= '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E1F2"/></patternFill></fill>';
        $st .= '</fills>';
        $st .= '<borders count="1"><border/></borders>';
        $st .= '<cellStyleXfs count="1"><xf/></cellStyleXfs>';
        $st .= '<cellXfs count="2">';
        $st .= '<xf fontId="0" fillId="0" borderId="0" xfId="0"/>';
        $st .= '<xf fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>';
        $st .= '</cellXfs>';
        $st .= '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $st);

        // xl/sharedStrings.xml
        $ss  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ss .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
        foreach ($strings as $s) {
            $ss .= '<si><t>' . $this->xmlEsc($s) . '</t></si>';
        }
        $ss .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ss);

        // xl/worksheets/sheet{N}.xml
        foreach ($this->sheets as $idx => $sh) {
            $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
            $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

            // Auto-width hint: cols
            $ncols = max(count($sh['headers']), 1);
            $xml .= '<cols>';
            for ($c = 1; $c <= $ncols; $c++) {
                $xml .= '<col min="' . $c . '" max="' . $c . '" width="22" customWidth="1"/>';
            }
            $xml .= '</cols>';

            $xml .= '<sheetData>';

            // Header row (style=1 → bold+fill)
            $xml .= '<row r="1">';
            foreach ($sh['headers'] as $ci => $h) {
                $col = $this->colLetter($ci);
                $si  = $strIndex[(string)$h];
                $xml .= '<c r="' . $col . '1" t="s" s="1"><v>' . $si . '</v></c>';
            }
            $xml .= '</row>';

            // Data rows
            $rowNum = 2;
            foreach ($sh['rows'] as $row) {
                $xml .= '<row r="' . $rowNum . '">';
                $ci = 0;
                foreach ($row as $v) {
                    $col = $this->colLetter($ci);
                    $ref = $col . $rowNum;
                    if (is_numeric($v) && $v !== '') {
                        $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                    } else {
                        $si = $strIndex[(string)$v];
                        $xml .= '<c r="' . $ref . '" t="s"><v>' . $si . '</v></c>';
                    }
                    $ci++;
                }
                $xml .= '</row>';
                $rowNum++;
            }

            $xml .= '</sheetData>';
            $xml .= '<autoFilter ref="A1:' . $this->colLetter($ncols - 1) . '1"/>';
            $xml .= '</worksheet>';

            $zip->addFromString('xl/worksheets/sheet' . ($idx + 1) . '.xml', $xml);
        }

        $zip->close();
    }

    private function colLetter($idx) {
        $l = '';
        $idx++;
        while ($idx > 0) {
            $idx--;
            $l = chr(65 + ($idx % 26)) . $l;
            $idx = intdiv($idx, 26);
        }
        return $l;
    }

    private function xmlEsc($s) {
        return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
