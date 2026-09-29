<?php

namespace App\Modules\ProviderPayments\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExternalShipmentIssueReport
{
    /** @param list<array<string, mixed>> $issues */
    public function store(array $issues, string $fileName): ?string
    {
        if ($issues === []) {
            return null;
        }

        $token = (string) Str::uuid();
        Storage::disk('local')->put($this->path($token), json_encode([
            'file' => basename($fileName),
            'issues' => $issues,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $token;
    }

    public function download(string $token): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($this->path($token)), 404);
        $report = json_decode(Storage::disk('local')->get($this->path($token)), true, 512, JSON_THROW_ON_ERROR);

        return response()->streamDownload(function () use ($report): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            try {
                $writer->addRow(Row::fromValues([
                    'Archivo', 'Fila Excel', 'Motivo para revisar', 'Fecha', 'ID / Seguimiento',
                    'OS Blue', 'Localidad Destino', 'Punto entrega', 'Cliente', 'Observacion',
                ]));
                foreach ($report['issues'] as $issue) {
                    $writer->addRow(Row::fromValues([
                        $report['file'], $issue['source_row'], $issue['reason'], $issue['fecha'],
                        $issue['tracking'], $issue['os_blue'], $issue['locality'],
                        $issue['delivery_point'], $issue['client'], $issue['observacion'],
                    ]));
                }
            } finally {
                $writer->close();
            }
        }, 'Envios_Externos_Pendientes_'.$token.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function path(string $token): string
    {
        return 'external-shipment-issue-reports/'.$token.'.json';
    }
}
