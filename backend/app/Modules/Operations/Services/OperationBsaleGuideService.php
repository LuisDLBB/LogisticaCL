<?php

namespace App\Modules\Operations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class OperationBsaleGuideService
{
    private const ENDPOINT = 'https://api.bsale.io/v1/shippings.json';

    public const LINES_PER_SHEET = 15;

    public const UNCERTAIN_MESSAGE = 'Revisa en Bsale si la guía fue creada antes de reintentar.';

    public static function downloadablePdfUrl(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), ['app2.bsale.io', 'app2.bsale.cl'], true)
            && ! isset($parts['port'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && str_ends_with(strtolower($parts['path'] ?? ''), '.pdf');
    }

    public function emit(int $tenantId, int $userId, int $guideId): object
    {
        $token = config('services.bsale.production_token');
        if (! is_string($token) || $token === '') {
            throw ValidationException::withMessages(['bsale' => 'Falta configurar el token de producción de Bsale.']);
        }

        $sheetNumber = 1;
        $newlyGenerated = 0;
        while (true) {
            $prepared = $this->prepareSheet($tenantId, $userId, $guideId, $sheetNumber);
            if ($prepared['complete']) {
                if ($newlyGenerated === 0) {
                    throw ValidationException::withMessages(['bsale' => 'Todas las hojas de esta guía y versión ya fueron generadas en Bsale.']);
                }

                return (object) ['estado' => 'generada', 'sheet_count' => $prepared['sheet_count'], 'generated_count' => $newlyGenerated];
            }
            if ($prepared['already_generated']) {
                $sheetNumber++;

                continue;
            }

            $emission = $this->sendSheet($prepared['record_id'], $token, $prepared['payload']);
            if ($emission->estado !== 'generada') {
                return (object) ['estado' => 'incierta', 'sheet_count' => $prepared['sheet_count'], 'generated_count' => $newlyGenerated];
            }
            $newlyGenerated++;
            $sheetNumber++;
        }
    }

    private function prepareSheet(int $tenantId, int $userId, int $guideId, int $sheetNumber): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $guideId, $sheetNumber): array {
            DB::table('MBA_tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
            $guide = DB::table('Ope_Guias as guide')
                ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'guide.departure_id')
                ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
                ->where('guide.id', $guideId)
                ->where('lot.tenant_id', $tenantId)
                ->select('guide.id', 'guide.version', 'guide.snapshot', 'departure.status', 'departure.version as current_version')
                ->lockForUpdate()
                ->firstOrFail();

            if ($guide->status !== 'approved' || (int) $guide->version !== (int) $guide->current_version) {
                throw ValidationException::withMessages(['bsale' => 'Solo se puede emitir la versión vigente de una guía interna aprobada.']);
            }

            $snapshot = json_decode($guide->snapshot, true);
            if (! is_array($snapshot)) {
                throw ValidationException::withMessages(['bsale' => 'El snapshot de la guía interna no es válido.']);
            }
            $this->validateSnapshot($snapshot);
            $lines = array_values($snapshot['lines']);
            $sheetCount = (int) ceil(count($lines) / self::LINES_PER_SHEET);
            $existing = DB::table('Ope_GuiasBsale')
                ->where(['guide_id' => $guideId, 'version' => $guide->version])
                ->orderBy('sheet_number')->lockForUpdate()->get();
            foreach ($existing as $record) {
                $start = ((int) $record->sheet_number - 1) * self::LINES_PER_SHEET + 1;
                $end = min((int) $record->sheet_number * self::LINES_PER_SHEET, count($lines));
                if ((int) $record->sheet_count !== $sheetCount || (int) $record->sheet_number > $sheetCount
                    || ($record->line_start !== null && (int) $record->line_start !== $start)
                    || ($record->line_end !== null && (int) $record->line_end !== $end)) {
                    throw ValidationException::withMessages(['bsale' => 'Una emisión anterior no coincide con la división de 15 líneas. Revísala en Bsale antes de continuar.']);
                }
            }

            if ($sheetNumber > $sheetCount) {
                return ['complete' => true, 'sheet_count' => $sheetCount];
            }

            $record = $existing->firstWhere('sheet_number', $sheetNumber);
            if ($record?->estado === 'generada') {
                return ['complete' => false, 'already_generated' => true, 'sheet_count' => $sheetCount];
            }
            if ($record && $record->estado !== 'error') {
                throw ValidationException::withMessages(['bsale' => $record->estado === 'incierta'
                    ? self::UNCERTAIN_MESSAGE
                    : 'Esta hoja ya tiene un envío en curso.']);
            }

            $lineStart = ($sheetNumber - 1) * self::LINES_PER_SHEET + 1;
            $lineEnd = min($sheetNumber * self::LINES_PER_SHEET, count($lines));
            $values = [
                'tenant_id' => $tenantId,
                'guide_id' => $guideId,
                'version' => $guide->version,
                'sheet_number' => $sheetNumber,
                'sheet_count' => $sheetCount,
                'line_start' => $lineStart,
                'line_end' => $lineEnd,
                'estado' => 'enviando',
                'shipping_id' => null,
                'document_id' => null,
                'numero' => null,
                'url_pdf' => null,
                'url_publica' => null,
                'respuesta' => null,
                'user_id' => $userId,
                'updated_at' => now(),
            ];
            if ($record) {
                DB::table('Ope_GuiasBsale')->where('id', $record->id)->update($values);
                $recordId = $record->id;
            } else {
                $recordId = DB::table('Ope_GuiasBsale')->insertGetId([...$values, 'created_at' => now()]);
            }

            foreach (array_slice($lines, $lineStart - 1, self::LINES_PER_SHEET) as $index => $line) {
                $lineNumber = $lineStart + $index;
                DB::table('Ope_GuiasBsaleLineas')->insertOrIgnore([
                    'emission_id' => $recordId,
                    'line_number' => $lineNumber,
                    'line_snapshot' => OperationAccess::json($line),
                ]);
                foreach (array_unique($line['packages'] ?? []) as $tracking) {
                    DB::table('Ope_GuiasBsaleBultos')->insertOrIgnore([
                        'emission_id' => $recordId,
                        'line_number' => $lineNumber,
                        'tracking' => $tracking,
                    ]);
                }
            }

            return [
                'complete' => false,
                'already_generated' => false,
                'sheet_count' => $sheetCount,
                'record_id' => $recordId,
                'payload' => $this->payload($snapshot, $sheetNumber, $sheetCount),
            ];
        });
    }

    private function sendSheet(int $recordId, string $token, array $payload): object
    {

        try {
            $response = Http::connectTimeout(10)->timeout(60)
                ->withHeaders(['access_token' => $token])
                ->post(self::ENDPOINT, $payload);
            $body = $response->json();
            $documentId = data_get($body, 'guide.id');
            $generated = $response->successful() && filled($documentId);
            DB::table('Ope_GuiasBsale')->where('id', $recordId)->update([
                'estado' => $generated ? 'generada' : 'incierta',
                'shipping_id' => $generated ? data_get($body, 'id') : null,
                'document_id' => $generated ? $documentId : null,
                'numero' => $generated ? data_get($body, 'guide.number') : null,
                'url_pdf' => $generated ? data_get($body, 'guide.urlPdf') : null,
                'url_publica' => $generated ? data_get($body, 'guide.urlPublicView') : null,
                'respuesta' => $body === null ? null : str_replace($token, '[REDACTED]', OperationAccess::json($body)),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            DB::table('Ope_GuiasBsale')->where('id', $recordId)->update([
                'estado' => 'incierta',
                'updated_at' => now(),
            ]);
        }

        return DB::table('Ope_GuiasBsale')->where('id', $recordId)->firstOrFail();
    }

    public function reconcile(int $tenantId, int $userId, int $emissionId, string $outcome, array $data = []): void
    {
        DB::transaction(function () use ($tenantId, $userId, $emissionId, $outcome, $data): void {
            $emission = DB::table('Ope_GuiasBsale')
                ->where(['tenant_id' => $tenantId, 'id' => $emissionId])
                ->lockForUpdate()->firstOrFail();
            if ($emission->estado !== 'incierta') {
                throw ValidationException::withMessages(['bsale' => 'Solo se puede conciliar una emisión incierta.']);
            }

            $values = $outcome === 'created'
                ? [
                    'estado' => 'generada',
                    'shipping_id' => $data['shipping_id'],
                    'document_id' => $data['document_id'],
                    'numero' => $data['numero'],
                    'url_pdf' => $data['url_pdf'],
                    'url_publica' => $data['url_publica'],
                ]
                : ['estado' => 'error'];
            DB::table('Ope_GuiasBsale')->where('id', $emissionId)->update([...$values, 'updated_at' => now()]);
            OperationAccess::audit($tenantId, $userId, 'Conciliar Bsale', 'bsale_emission', $emissionId,
                ['outcome' => $outcome, ...$values], ['estado' => $emission->estado]);
        });
    }

    private function validateSnapshot(array $snapshot): void
    {
        $validation = Validator::make($snapshot, [
            'departure.departure_date' => ['required', 'date_format:Y-m-d'],
            'departure.plate' => ['required', 'string'],
            'departure.driver_name' => ['required', 'string'],
            'departure.driver_rut' => ['required', 'string'],
            'destination.address' => ['required', 'string'],
            'destination.commune' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['present', 'string'],
            'lines.*.count' => ['required', 'integer'],
        ]);
        if ($validation->fails()) {
            throw ValidationException::withMessages(['bsale' => 'El snapshot de la guía interna no contiene todos los datos requeridos por Bsale.']);
        }
    }

    private function payload(array $snapshot, int $sheetNumber, int $sheetCount): array
    {
        $departure = $snapshot['departure'];
        $destination = $snapshot['destination'];
        $details = array_map(fn (array $line): array => [
            'comment' => $line['description'],
            'quantity' => $line['count'],
        ], array_slice($snapshot['lines'], ($sheetNumber - 1) * self::LINES_PER_SHEET, self::LINES_PER_SHEET));
        $details[0]['comment'] .= "\nHoja {$sheetNumber} de {$sheetCount}";

        return [
            'documentTypeId' => 7,
            'shippingTypeId' => 6,
            'declareSii' => 0,
            'sendEmail' => 0,
            'emissionDate' => CarbonImmutable::parse($departure['departure_date'], 'UTC')->startOfDay()->timestamp,
            'client' => [
                'code' => '77346078-7',
                'company' => '4 NORTES LOGISTICA SPA',
                'activity' => 'Servicios De Logistica Y Distribucion',
                'address' => 'Galvarino 8481, Bodega 17',
                'municipality' => 'Quilicura',
                'city' => 'Santiago',
            ],
            'address' => $destination['address'],
            'municipality' => $destination['commune'],
            'city' => $destination['commune'],
            'details' => $details,
            'dynamicAttributes' => [
                ['dynamicAttributeId' => 30, 'description' => $departure['plate']],
                ['dynamicAttributeId' => 31, 'description' => $departure['driver_name']],
                ['dynamicAttributeId' => 32, 'description' => $departure['driver_rut']],
            ],
        ];
    }
}
