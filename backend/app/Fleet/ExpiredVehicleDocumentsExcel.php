<?php

namespace App\Fleet;

use Carbon\Carbon;
use RuntimeException;
use ZipArchive;

class ExpiredVehicleDocumentsExcel
{
    public function create(array $records, string $sheetName = 'Documentos vencidos', string $documentHeading = 'Documento vencido'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fleet-expired-');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo preparar el archivo Excel.');
        }
        try {
            $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="16" customWidth="1"/><col min="2" max="2" width="36" customWidth="1"/><col min="3" max="3" width="23" customWidth="1"/></cols><sheetData>';
            $sheet .= '<row r="1" ht="26" customHeight="1">';
            foreach (['A' => 'Patente', 'B' => $documentHeading, 'C' => 'Fecha de vencimiento'] as $column => $label) {
                $sheet .= $this->textCell($column.'1', $label, 1);
            }
            $sheet .= '</row>';
            foreach ($records as $index => $record) {
                $row = $index + 2;
                $serial = (int) Carbon::parse('1899-12-30', 'UTC')->diffInDays(Carbon::parse($record['date'], 'UTC'));
                $sheet .= '<row r="'.$row.'">'.$this->textCell('A'.$row, $record['plate']).$this->textCell('B'.$row, $record['document']).'<c r="C'.$row.'" s="2"><v>'.$serial.'</v></c></row>';
            }
            $sheet .= '</sheetData><autoFilter ref="A1:C'.(count($records) + 1).'"/></worksheet>';
            $parts = [
                '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
                '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
                'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF007F83"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
                'xl/worksheets/sheet1.xml' => $sheet,
            ];
            foreach ($parts as $name => $xml) {
                if (! $zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml)) {
                    throw new RuntimeException('No se pudo completar el archivo Excel.');
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('No se pudo guardar el archivo Excel.');
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return $path;
    }

    private function textCell(string $reference, string $value, int $style = 0): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return '<c r="'.$reference.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
    }
}
