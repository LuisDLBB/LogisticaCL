<?php

namespace App\Fleet;

use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

class MaintenanceHistoryExcel
{
    public function create(Collection $maintenances): string
    {
        $creators = User::whereIn('id', $maintenances->pluck('created_by_user_id')->filter())->pluck('name', 'id');
        $history = $events = $documents = $sources = [];
        foreach ($maintenances as $job) {
            $identity = ['ID mantención' => $job->id, 'Patente' => $job->vehicle?->plate, 'Empresa' => $job->tenant?->code];
            $history[] = [...$identity,
                'Vehículo' => trim($job->vehicle?->brand.' '.$job->vehicle?->model),
                'Tipo de mantención' => $job->maintenance_type,
                'Ejecución' => $job->execution_type === 'internal' ? 'Interna' : 'Externa',
                'Estado' => MaintenanceService::STATUSES[$job->status] ?? $job->status,
                'Fecha prevista' => $job->getRawOriginal('scheduled_at'),
                'KM informado' => $job->reported_odometer_km,
                'Proveedor' => $job->provider?->operational_name ?: $job->provider?->legal_name,
                'RUT proveedor' => $job->provider?->tax_id,
                'Presupuesto / cotización' => $job->quotation_number,
                'Costo estimado' => $job->estimated_cost === null ? null : (float) $job->estimated_cost,
                'Responsable interno' => $job->responsible?->name,
                'Observaciones' => $job->notes,
                'Inicio' => $job->getRawOriginal('started_at'),
                'Cierre' => $job->getRawOriginal('closed_at'),
                'KM de cierre' => $job->closed_odometer_km,
                'Costo final' => $job->actual_cost === null ? null : (float) $job->actual_cost,
                'Tipo documento de cobro' => $job->document_type,
                'Número factura / documento' => $job->document_number,
                'Detalle de cierre' => $job->closing_notes,
                'Próxima fecha' => $job->getRawOriginal('next_due_at'),
                'Próximo KM' => $job->next_due_km,
                'Registrado por' => $creators->get($job->created_by_user_id),
                'Creado' => $job->getRawOriginal('created_at'),
                'Actualizado' => $job->getRawOriginal('updated_at'),
                'Es prueba' => $job->is_test ? 'Sí' : 'No',
                'Fecha solicitud original' => $job->historicalImport?->getRawOriginal('requested_at'),
                'Fecha documento original' => $job->historicalImport?->getRawOriginal('document_issued_at'),
                'Monto neto original' => $job->historicalImport?->net_amount === null ? null : (float) $job->historicalImport->net_amount,
                'IVA original' => $job->historicalImport?->vat_amount === null ? null : (float) $job->historicalImport->vat_amount,
                'Empresa facturada' => $job->historicalImport?->billed_company,
                'Enlace ficha mantención' => route('fleet.maintenance.show', $job),
            ];
            foreach ($job->events as $event) {
                $events[] = [...$identity, 'ID evento' => $event->id, 'Usuario' => $event->actor?->name, 'Evento' => $event->event_type,
                    'Estado anterior' => $event->previous_status, 'Estado nuevo' => $event->new_status, 'KM' => $event->odometer_km,
                    'Fecha informada' => $event->getRawOriginal('recorded_at'), 'Creado' => $event->getRawOriginal('created_at'),
                    'Detalle completo' => json_encode($event->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            }
            foreach ($job->documents as $document) {
                $documents[] = [...$identity, 'ID documento' => $document->id, 'Tipo' => $document->kind,
                    'Nombre archivo' => $document->original_name, 'Formato' => $document->mime_type, 'Tamaño bytes' => $document->size_bytes,
                    'Usuario ID' => $document->uploaded_by_user_id, 'Adjuntado' => $document->getRawOriginal('created_at'),
                    'Enlace documento' => route('fleet.maintenance.documents.download', ['maintenance' => $job, 'document' => $document])];
            }
            if ($job->historicalImport) {
                $sources[] = [...$identity, 'Origen' => $job->historicalImport->source_key, 'Fila original' => $job->historicalImport->source_line,
                    ...collect($job->historicalImport->source_data ?? [])->mapWithKeys(fn ($value, $key) => ['Original: '.$key => $value])->all()];
            }
        }

        return $this->workbook(['Historial' => $history, 'Trazabilidad' => $events, 'Documentos' => $documents, 'Datos originales' => $sources]);
    }

    private function workbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fleet-maintenance-');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo preparar el archivo Excel.');
        }
        try {
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>';
            $book = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $relations = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $index = 0;
            foreach ($sheets as $name => $records) {
                $index++;
                $headers = array_values(array_unique(array_merge(['ID mantención', 'Patente', 'Empresa'], ...array_map('array_keys', $records))));
                $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="'.count($headers).'" width="24" customWidth="1"/></cols><sheetData>';
                foreach ([$headers, ...array_map(fn ($record) => array_map(fn ($header) => $record[$header] ?? null, $headers), $records)] as $rowIndex => $values) {
                    $sheet .= '<row r="'.($rowIndex + 1).'">';
                    foreach ($values as $columnIndex => $value) {
                        $reference = $this->column($columnIndex + 1).($rowIndex + 1);
                        if (is_int($value) || is_float($value)) {
                            $sheet .= '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                        } else {
                            $text = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) ($value ?? '');
                            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
                            $sheet .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                        }
                    }
                    $sheet .= '</row>';
                }
                $sheet .= '</sheetData><autoFilter ref="A1:'.$this->column(count($headers)).(count($records) + 1).'"/></worksheet>';
                $zip->addFromString('xl/worksheets/sheet'.$index.'.xml', $sheet);
                $types = str_replace('</Types>', '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>', $types);
                $book .= '<sheet name="'.$name.'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
                $relations .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            }
            $zip->addFromString('[Content_Types].xml', $types);
            $zip->addFromString('xl/workbook.xml', $book.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $relations.'</Relationships>');
            $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            if (! $zip->close()) {
                throw new RuntimeException('No se pudo guardar el archivo Excel.');
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return $path;
    }

    private function column(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $number--;
            $column = chr(65 + $number % 26).$column;
            $number = intdiv($number, 26);
        }

        return $column;
    }
}
