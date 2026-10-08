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

    public const UNCERTAIN_MESSAGE = 'Revisa en Bsale si la guía fue creada antes de reintentar.';

    public function emit(int $tenantId, int $userId, int $guideId): object
    {
        $token = config('services.bsale.production_token');
        if (! is_string($token) || $token === '') {
            throw ValidationException::withMessages(['bsale' => 'Falta configurar el token de producción de Bsale.']);
        }

        [$recordId, $payload] = DB::transaction(function () use ($tenantId, $userId, $guideId): array {
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
            $payload = $this->payload($snapshot);
            $existing = DB::table('Ope_GuiasBsale')->where(['guide_id' => $guideId, 'version' => $guide->version])->lockForUpdate()->first();
            if ($existing && $existing->estado !== 'error') {
                throw ValidationException::withMessages(['bsale' => $existing->estado === 'incierta'
                    ? self::UNCERTAIN_MESSAGE
                    : 'Esta guía y versión ya tiene una emisión en Bsale o un envío en curso.']);
            }

            $values = [
                'tenant_id' => $tenantId,
                'guide_id' => $guideId,
                'version' => $guide->version,
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
            if ($existing) {
                DB::table('Ope_GuiasBsale')->where('id', $existing->id)->update($values);
                $recordId = $existing->id;
            } else {
                $recordId = DB::table('Ope_GuiasBsale')->insertGetId([...$values, 'created_at' => now()]);
            }

            return [$recordId, $payload];
        });

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

    private function payload(array $snapshot): array
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

        $departure = $snapshot['departure'];
        $destination = $snapshot['destination'];

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
            'details' => array_map(fn (array $line): array => [
                'comment' => $line['description'],
                'quantity' => $line['count'],
            ], $snapshot['lines']),
            'dynamicAttributes' => [
                ['dynamicAttributeId' => 30, 'description' => $departure['plate']],
                ['dynamicAttributeId' => 31, 'description' => $departure['driver_name']],
                ['dynamicAttributeId' => 32, 'description' => $departure['driver_rut']],
            ],
        ];
    }
}
