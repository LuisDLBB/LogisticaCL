<?php

namespace App\Fleet;

use App\Models\ServiceRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LatePickupAuthorization
{
    public function settings(int $tenantId): object
    {
        $query = DB::table('fleet_scheduling_settings')->where('tenant_id', $tenantId);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function cutoff(int $tenantId): string
    {
        return substr($this->settings($tenantId)->pickup_cutoff_time, 0, 5);
    }

    public function afterCutoff(string $date, int $tenantId): bool
    {
        $now = now('America/Santiago');
        $cutoff = $this->cutoff($tenantId);

        return $date === $now->toDateString() && $now->format('H:i:s') > $cutoff.':00';
    }

    public function canDecide(User $actor, Tenant $tenant, string $area): bool
    {
        if (! $tenant->is_active || ! in_array($area, ['operations', 'commercial'], true)) {
            return false;
        }

        return DB::table('fleet_pickup_approvers')->join('tenant_users', function ($join): void {
            $join->on('tenant_users.user_id', '=', 'fleet_pickup_approvers.user_id')
                ->on('tenant_users.tenant_id', '=', 'fleet_pickup_approvers.tenant_id');
        })->join('fleet_profiles', function ($join): void {
            $join->on('fleet_profiles.tenant_id', '=', 'tenant_users.tenant_id')->on('fleet_profiles.code', '=', 'tenant_users.role_code');
        })->where('fleet_pickup_approvers.tenant_id', $tenant->id)->where('fleet_pickup_approvers.user_id', $actor->id)
            ->where('fleet_pickup_approvers.area', $area)->where('fleet_pickup_approvers.is_active', true)
            ->where('tenant_users.is_active', true)->where('fleet_profiles.is_active', true)->exists();
    }

    public function current(ServiceRequest $ret): bool
    {
        if ((int) ($ret->late_pickup_authorization['revision'] ?? 0) !== (int) $this->settings($ret->tenant_id)->pickup_authorization_revision) {
            return false;
        }
        foreach (['operations', 'commercial'] as $area) {
            $decision = $ret->late_pickup_authorization[$area] ?? [];
            if (($decision['status'] ?? null) === 'approved') {
                $actor = User::find($decision['actor_id'] ?? null);
                if ($actor === null || ! $this->canDecide($actor, Tenant::findOrFail($ret->tenant_id), $area)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function request(ServiceRequest $ret, User $actor, string $date, string $reason): void
    {
        DB::transaction(function () use ($ret, $actor, $date, $reason): void {
            $current = ServiceRequest::query()->lockForUpdate()->findOrFail($ret->id);
            if (! in_array($current->status, ['requested', 'correction_requested', 'reprogramming_pending'], true) || ! $this->afterCutoff($date, $current->tenant_id)) {
                throw ValidationException::withMessages(['late_pickup' => 'La excepción solo corresponde a solicitudes para hoy después del horario de corte '.$this->cutoff($current->tenant_id).'.']);
            }
            $previous = $current->late_pickup_authorization;
            if (($previous['date'] ?? null) === $date && ($this->current($current) || ($previous['operations']['status'] ?? null) === 'rejected' || ($previous['commercial']['status'] ?? null) === 'rejected')) {
                throw ValidationException::withMessages(['late_pickup' => 'Ya existe una decisión o solicitud para esta fecha. Un rechazo impide programar hoy.']);
            }
            $current->update(['late_pickup_authorization' => [
                'revision' => (int) $this->settings($current->tenant_id)->pickup_authorization_revision, 'cutoff_snapshot' => $this->cutoff($current->tenant_id),
                'date' => $date, 'reason' => $reason, 'requested_by' => $actor->id, 'requested_at' => now()->toIso8601String(),
                'operations' => ['status' => 'pending'], 'commercial' => ['status' => 'pending'],
            ]]);
            app(CoordinationService::class)->event($current, $actor, 'late_pickup_requested', ['date' => $date, 'reason' => $reason, 'previous_authorization' => $previous]);
        }, 3);
    }

    public function decide(ServiceRequest $ret, User $actor, Tenant $tenant, string $area, string $decision, string $notes): void
    {
        abort_unless($ret->tenant_id === $tenant->id, 404);
        abort_unless($this->canDecide($actor, $tenant, $area), 403);
        DB::transaction(function () use ($ret, $actor, $tenant, $area, $decision, $notes): void {
            $current = ServiceRequest::query()->lockForUpdate()->findOrFail($ret->id);
            $this->settings($tenant->id);
            abort_unless($this->canDecide($actor, $tenant, $area), 403);
            $authorization = $current->late_pickup_authorization;
            if (! $this->current($current) || ! in_array($current->status, ['requested', 'correction_requested', 'reprogramming_pending'], true)
                || ($authorization['date'] ?? null) !== now('America/Santiago')->toDateString()
                || ($authorization[$area]['status'] ?? null) !== 'pending'
                || ($authorization['operations']['status'] ?? null) === 'rejected'
                || ($authorization['commercial']['status'] ?? null) === 'rejected') {
                throw ValidationException::withMessages(['late_pickup' => 'La autorización ya fue decidida, rechazada o venció.']);
            }
            $other = $area === 'operations' ? 'commercial' : 'operations';
            if (($authorization[$other]['actor_id'] ?? null) === $actor->id) {
                throw ValidationException::withMessages(['late_pickup' => 'Las dos áreas deben registrar su decisión con cuentas distintas.']);
            }
            $authorization[$area] = ['status' => $decision, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'decided_at' => now()->toIso8601String(), 'notes' => $notes];
            $current->update(['late_pickup_authorization' => $authorization]);
            app(CoordinationService::class)->event($current, $actor, 'late_pickup_'.$decision, ['area' => $area, 'date' => $authorization['date'], 'notes' => $notes]);
        }, 3);
    }

    public function assertAllowed(ServiceRequest $ret, string $date): void
    {
        $authorization = $ret->late_pickup_authorization;
        $rejected = ($authorization['operations']['status'] ?? null) === 'rejected' || ($authorization['commercial']['status'] ?? null) === 'rejected';
        if ($rejected && $date !== $authorization['date'] && ($authorization['customer_date_confirmation']['date'] ?? null) !== $date) {
            throw ValidationException::withMessages(['customer_date_confirmed' => 'Confirma con el cliente la nueva fecha antes de agendar después de un rechazo.']);
        }
        if ($rejected && $date === $authorization['date']) {
            throw ValidationException::withMessages(['late_pickup' => 'El retiro fue rechazado para esta fecha. Acuerda otra fecha con el cliente.']);
        }
        if (! $this->afterCutoff($date, $ret->tenant_id)) {
            return;
        }
        if ($this->current($ret) && ($authorization['date'] ?? null) === $date
            && ($authorization['operations']['status'] ?? null) === 'approved'
            && ($authorization['commercial']['status'] ?? null) === 'approved') {
            return;
        }
        throw ValidationException::withMessages(['late_pickup' => 'Para programar hoy después de '.$this->cutoff($ret->tenant_id).' necesitas aprobación de Operaciones y del gerente comercial. Si existe rechazo, acuerda otra fecha con el cliente.']);
    }
}
