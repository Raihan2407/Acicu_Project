<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Pembuat file .xlsx sederhana tanpa paket tambahan (hanya butuh ekstensi PHP "zip").
 * Cukup untuk laporan tabel: judul, header berwarna, angka berformat ribuan.
 */
class XlsxWriter
{
    /**
     * @param  string  $sheetName  nama sheet (maks 31 karakter)
     * @param  array<int,string>  $headers  judul kolom
     * @param  iterable<array<int,mixed>>  $rows  baris data (angka int/float tetap numerik)
     * @param  array<int,string>  $titleLines  baris judul di atas tabel (opsional)
     * @param  array<int,int>  $currencyColumns  indeks kolom (mulai 0) berformat #,##0
     * @return string path file sementara (hapus setelah dikirim)
     */
    public static function build(string $sheetName, array $headers, iterable $rows, array $titleLines = [], array $currencyColumns = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'acicu_xlsx_');
        if ($path === false) {
            throw new RuntimeException('Tidak dapat membuat file sementara.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak dapat membuat file XLSX.');
        }

        $xmlRows = [];
        $widths = array_map(fn ($h) => mb_strlen((string) $h) + 2, $headers);
        $rowNumber = 0;

        foreach ($titleLines as $line) {
            $rowNumber++;
            $xmlRows[] = '<row r="'.$rowNumber.'">'.self::cell(0, $rowNumber, (string) $line, 3).'</row>';
        }
        if ($titleLines) {
            $rowNumber++; // baris kosong pemisah
        }

        $rowNumber++;
        $headerRow = $rowNumber;
        $cells = '';
        foreach (array_values($headers) as $i => $header) {
            $cells .= self::cell($i, $rowNumber, (string) $header, 1);
        }
        $xmlRows[] = '<row r="'.$rowNumber.'" ht="22" customHeight="1">'.$cells.'</row>';

        foreach ($rows as $row) {
            $rowNumber++;
            $cells = '';
            foreach (array_values($row) as $i => $value) {
                $isMoney = in_array($i, $currencyColumns, true);
                $cells .= self::cell($i, $rowNumber, $value, $isMoney ? 2 : 0);
                $len = is_numeric($value) ? strlen(number_format((float) $value, 0, ',', '.')) : mb_strlen((string) $value);
                $widths[$i] = max($widths[$i] ?? 0, min(50, $len + 2));
            }
            $xmlRows[] = '<row r="'.$rowNumber.'">'.$cells.'</row>';
        }

        $cols = '';
        foreach ($widths as $i => $w) {
            $n = $i + 1;
            $cols .= '<col min="'.$n.'" max="'.$n.'" width="'.max(8, min(50, $w)).'" customWidth="1"/>';
        }

        $lastCol = self::columnName(max(0, count($headers) - 1));
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/>'
            .'<selection pane="bottomLeft"/></sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .'<autoFilter ref="A'.$headerRow.':'.$lastCol.max($headerRow, $rowNumber).'"/>'
            .'</worksheet>';

        $name = self::escape(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheetName) ?: 'Sheet1', 0, 31));

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $path;
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FFB3114B"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFF5C8A"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private static function cell(int $col, int $row, mixed $value, int $style): string
    {
        $ref = self::columnName($col).$row;
        $s = $style > 0 ? ' s="'.$style.'"' : '';

        if ($value === null || $value === '') {
            return '<c r="'.$ref.'"'.$s.'/>';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$ref.'"'.$s.'><v>'.$value.'</v></c>';
        }

        return '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'.self::escape((string) $value).'</t></is></c>';
    }

    private static function columnName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        // buang karakter kontrol yang tidak valid di XML 1.0
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
