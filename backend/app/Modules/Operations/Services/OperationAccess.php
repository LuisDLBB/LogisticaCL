<?php

namespace App\Modules\Operations\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationAccess
{
    public static function tenant(Request $request): int
    {
        return (int) $request->attributes->get('portal_tenant')->id;
    }

    public static function supervisor(Request $request): bool
    {
        return in_array(self::key((string) $request->user()?->profile_name), ['administrador', 'supervisor', 'supervisor operaciones'], true)
            || in_array(self::key((string) $request->attributes->get('portal_tenant')?->pivot?->role_code), ['admin', 'administrator', 'supervisor'], true);
    }

    public static function requireSupervisor(Request $request): void
    {
        abort_unless(self::supervisor($request), 403, 'Esta acción requiere un supervisor o administrador.');
    }

    public static function lot(Request $request, int $id): object
    {
        return DB::table('Ope_Lotes')->where('tenant_id', self::tenant($request))->where('id', $id)->firstOrFail();
    }

    public static function departures(Request $request): Builder
    {
        return DB::table('Ope_ProgramacionSalidas')->whereIn('lot_id', DB::table('Ope_Lotes')->where('tenant_id', self::tenant($request))->select('id'));
    }

    public static function key(string $value): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim(Str::ascii($value))));
    }

    public static function literalKey(string $value): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    public static function validRut(string $rut): bool
    {
        if (preg_match('/^([1-9]\d{6,7})-([0-9K])$/D', $rut, $parts) !== 1) {
            return false;
        }

        $sum = 0;
        $multiplier = 2;
        foreach (str_split(strrev($parts[1])) as $digit) {
            $sum += (int) $digit * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }
        $check = 11 - $sum % 11;

        return ($check === 11 ? '0' : ($check === 10 ? 'K' : (string) $check)) === $parts[2];
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function audit(int $tenant, int $user, string $action, string $entity, int $id, mixed $after, mixed $before = null): void
    {
        DB::table('Ope_Auditoria')->insert(['tenant_id' => $tenant, 'user_id' => $user, 'action' => $action, 'entity' => $entity, 'entity_id' => $id, 'before' => $before === null ? null : self::json($before), 'after' => self::json($after), 'created_at' => now()]);
    }
}
