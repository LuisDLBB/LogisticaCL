<?php

namespace App\Fleet;

use Carbon\Carbon;
use Illuminate\Support\Str;

class VehicleCertificateParser
{
    private const MONTHS = ['ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6, 'JULIO' => 7, 'AGOSTO' => 8, 'SEPTIEMBRE' => 9, 'SETIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12, 'ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8, 'SEPT' => 9, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12];

    public function parse(array $pages, string $plate): array
    {
        $fields = [];
        $expected = preg_replace('/[^A-Z0-9]/', '', strtoupper($plate));
        foreach ($pages as $page => $raw) {
            $text = preg_replace('/\s+/', ' ', strtoupper(Str::ascii($raw)));
            preg_match_all('/CERTIFICADO (?:DE )?(?:REVISION TECNICA|EMISIONES CONTAMINANTES|HOMOLOGACION)/', $text, $headers, PREG_OFFSET_CAPTURE);
            if (empty($headers[0])) {
                continue;
            }
            preg_match_all('/\b[A-Z]{4}(?:\s*-\s*)?\d{2}\b/', $text, $plates);
            $detected = array_unique(array_map(fn ($value) => preg_replace('/[^A-Z0-9]/', '', $value), $plates[0]));
            if (count($detected) !== 1 || reset($detected) !== $expected) {
                return [];
            }
            foreach ($headers[0] as $index => [$header, $offset]) {
                $end = $headers[0][$index + 1][1] ?? strlen($text);
                $section = substr($text, $offset, $end - $offset);
                $homologation = str_contains($header, 'HOMOLOGACION');
                if (! $homologation && ! preg_match('/\bAPROBA[DB]O\b/', $section)) {
                    continue;
                }
                if (! $homologation && str_contains($section, 'RECHAZADO') && ! preg_match('/RESULTADO\s*:?\s*APROBA[DB]O\b/', $section)) {
                    continue;
                }
                if (! preg_match('/(?:V[ A]*LID[AO] HASTA)\s*:?\s*(.{0,180})/', $section, $validity)) {
                    continue;
                }
                preg_match('/\b(?:\d{1,2}[\/.-]\d{1,2}[\/.-]20\d{2}|(?:\d{1,2}[\s\/-]+)?(?:'.implode('|', array_keys(self::MONTHS)).')[\s\/-]+20\d{2})\b/', $validity[1], $expiryText);
                $expiry = isset($expiryText[0]) ? $this->date($expiryText[0]) : null;
                if (! preg_match('/FECHA(?: (?:DE )?(?:REVISION|EMISION))?\s*:?\s*(.{0,50})/', $section, $issuedText) && ! preg_match('/FECHA(?: (?:DE )?(?:REVISION|EMISION))?\s*:?\s*(.{0,50})/', $text, $issuedText)) {
                    continue;
                }
                $issued = $this->date($issuedText[1]);
                if (! $expiry || ! $issued || $issued['precision'] !== 'day' || $issued['date'] > today('America/Santiago')->toDateString() || $expiry['date'] < $issued['date']) {
                    continue;
                }
                $gas = str_contains($header, 'EMISIONES');
                $field = $gas ? 'gas_certificate_expires_at' : 'technical_inspection_expires_at';
                $value = [...$expiry, 'type' => $homologation ? 'homologation' : ($gas ? 'gas_certificate' : 'technical_inspection'), 'issued_at' => $issued['date'], 'page' => $page + 1];
                if (isset($fields[$field]) && ($fields[$field]['date'] !== $value['date'] || $fields[$field]['issued_at'] !== $value['issued_at'])) {
                    return [];
                }
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    private function date(string $text): ?array
    {
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](20\d{2})\b/', trim($text), $parts)) {
            [$day, $month, $year] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return ['date' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'display' => sprintf('%02d/%02d/%04d', $day, $month, $year), 'precision' => 'day'];
        }
        if (! preg_match('/^(?:(\d{1,2})[\s\/-]+)?('.implode('|', array_keys(self::MONTHS)).')[\s\/-]+(20\d{2})\b/', trim($text), $parts)) {
            return null;
        }
        $month = self::MONTHS[$parts[2]];
        $year = (int) $parts[3];
        $day = $parts[1] !== '' ? (int) $parts[1] : null;
        if ($day !== null && ! checkdate($month, $day, $year)) {
            return null;
        }
        $date = Carbon::create($year, $month, $day ?? 1, 0, 0, 0, 'America/Santiago');
        if ($day === null) {
            $date->endOfMonth();
        }

        return ['date' => $date->toDateString(), 'display' => $day === null ? strtolower($parts[2]).' '.$year : $date->format('d/m/Y'), 'precision' => $day === null ? 'month' : 'day'];
    }
}
