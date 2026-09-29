<?php

namespace App\Modules\ProviderPayments\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaidTrackingReport
{
    /** @param array<int, array{tracking: string, source_row: int, paid_period: string, paid_process: string, provider: ?string, amount: ?int}> $rows */
    public function store(array $rows, string $fileName, string $processName): ?string
    {
        if ($rows === []) {
            return null;
        }

        $token = (string) Str::uuid();
        Storage::disk('local')->put($this->path($token), json_encode([
            'file' => $fileName,
            'process' => $processName,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $token;
    }

    public function download(string $token): StreamedResponse
    {
        $contents = Storage::disk('local')->get($this->path($token));
        $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return response()->streamDownload(function () use ($report): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            try {
                $writer->addRow(Row::fromValues([
                    'Archivo de carga', 'Fila Excel', 'Seguimiento paquete', 'Proceso intentado',
                    'Período pagado', 'Proceso pagado', 'Proveedor pagado', 'Valor pagado ($)', 'Estado',
                ]));
                foreach ($report['rows'] as $row) {
                    $writer->addRow(new Row([
                        new StringCell($report['file']), new NumericCell($row['source_row']),
                        new StringCell($row['tracking']), new StringCell($report['process']),
                        new StringCell($row['paid_period']), new StringCell($row['paid_process']),
                        new StringCell($row['provider'] ?? ''), new NumericCell($row['amount'] ?? 0),
                        new StringCell('YA PAGADO - NO CARGAR'),
                    ]));
                }
            } finally {
                $writer->close();
            }
        }, 'Registros_ya_pagados_'.$token.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function path(string $token): string
    {
        return 'paid-tracking-reports/'.$token.'.json';
    }
}
