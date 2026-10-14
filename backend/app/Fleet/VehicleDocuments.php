<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VehicleDocuments
{
    public function query(Vehicle $vehicle, Tenant $owner): Builder
    {
        return DB::table('VEH_documents as documents')->where(function (Builder $query) use ($vehicle, $owner): void {
            $query->whereExists(function (Builder $links) use ($vehicle): void {
                $links->selectRaw('1')->from('VEH_document_vehicles')->whereColumn('document_id', 'documents.id')->where('vehicle_id', $vehicle->id);
            })->orWhere(function (Builder $company) use ($owner): void {
                $company->where('documents.tenant_id', $owner->id)->whereNotExists(function (Builder $links): void {
                    $links->selectRaw('1')->from('VEH_document_vehicles')->whereColumn('document_id', 'documents.id');
                });
            });
        });
    }

    public function forVehicle(Vehicle $vehicle, Tenant $owner): Collection
    {
        $links = DB::table('VEH_document_vehicles')->where('vehicle_id', $vehicle->id)->get()->keyBy('document_id');

        return $this->query($vehicle, $owner)->orderByDesc('documents.expires_at')->orderBy('documents.kind')->get()->map(function ($document) use ($links) {
            $document->details = json_decode($document->details, true) ?? [];
            $document->vehicle_details = json_decode($links->get($document->id)?->details ?? '{}', true) ?? [];
            $document->company_only = ! $links->has($document->id);

            return $document;
        });
    }
}
