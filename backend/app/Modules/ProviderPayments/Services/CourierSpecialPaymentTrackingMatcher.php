<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CourierMovement;
use App\Models\CourierSpecialPayment;
use App\Models\ServiceType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CourierSpecialPaymentTrackingMatcher
{
    /**
     * @param  Collection<int, CourierSpecialPayment>  $payments
     * @return array<int, array{client_id: ?int, service_type_id: ?int, service_name: ?string, matched: bool}>
     */
    public function suggestions(int $tenantId, Collection $payments): array
    {
        $codes = $payments->pluck('codigo_seguimiento')
            ->map(fn ($code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '' && strtoupper($code) !== 'N/A')
            ->unique()->values()->all();
        if ($codes === []) {
            return [];
        }

        $movements = CourierMovement::query()->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereIn('tracking_number', $codes)
                ->orWhereIn('tracking_code', $codes))
            ->get(['id', 'tracking_number', 'tracking_code', 'client_id', 'service_name']);
        $services = ServiceType::query()->get(['id', 'name'])
            ->keyBy(fn (ServiceType $service): string => $this->nameKey($service->name));
        $suggestions = [];

        foreach ($payments as $payment) {
            $code = trim((string) $payment->codigo_seguimiento);
            if ($code === '' || strtoupper($code) === 'N/A') {
                continue;
            }

            $candidates = $movements->where('tracking_number', $code);
            if ($candidates->isEmpty()) {
                $candidates = $movements->where('tracking_code', $code);
            }
            $identities = $candidates->map(fn (CourierMovement $movement): string => ($movement->client_id ?? '').'|'.$this->nameKey((string) $movement->service_name))
                ->unique();
            if ($identities->count() !== 1) {
                continue;
            }

            $movement = $candidates->first();
            $suggestions[$payment->id] = [
                'client_id' => $movement->client_id,
                'service_type_id' => $services->get($this->nameKey((string) $movement->service_name))?->id,
                'service_name' => $movement->service_name,
                'matched' => true,
            ];
        }

        return $suggestions;
    }

    /** @return array{updated: int, matched: int} */
    public function sync(int $tenantId, ?string $period = null): array
    {
        $result = ['updated' => 0, 'matched' => 0];
        CourierSpecialPayment::query()->where('tenant_id', $tenantId)
            ->when($period !== null, fn ($query) => $query->where('periodo', $period))
            ->whereNotNull('codigo_seguimiento')
            ->where(fn ($query) => $query->whereNull('client_id')->orWhereNull('service_type_id'))
            ->chunkById(200, function (Collection $payments) use ($tenantId, &$result): void {
                $suggestions = $this->suggestions($tenantId, $payments);
                foreach ($payments as $payment) {
                    $suggestion = $suggestions[$payment->id] ?? null;
                    if ($suggestion === null) {
                        continue;
                    }
                    $result['matched']++;
                    $changes = [];
                    if ($payment->client_id === null && $suggestion['client_id'] !== null) {
                        $changes['client_id'] = $suggestion['client_id'];
                    }
                    if ($payment->service_type_id === null && $suggestion['service_type_id'] !== null) {
                        $changes['service_type_id'] = $suggestion['service_type_id'];
                    }
                    if ($changes !== []) {
                        $payment->update($changes);
                        $result['updated']++;
                    }
                }
            });

        return $result;
    }

    private function nameKey(string $name): string
    {
        return Str::of($name)->squish()->lower()->ascii()->toString();
    }
}
