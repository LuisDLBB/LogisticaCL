<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentAlertSettingsController extends Controller
{
    public function update(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $request->merge(['recipients' => array_values(array_unique(array_filter(array_map(fn ($email): string => mb_strtolower(trim($email)), preg_split('/[,;\s]+/', (string) $request->input('emails', ''))))))]);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'warning_days' => ['required', 'integer', 'between:1,60'], 'recipients' => ['array', 'max:20', 'required_if:enabled,1'], 'recipients.*' => ['required', 'email', 'max:254']]);
        DB::transaction(function () use ($request, $access, $tenant, $data): void {
            $before = DB::table('VEH_document_alert_settings')->where('tenant_id', $tenant->id)->first();
            DB::table('VEH_document_alert_settings')->updateOrInsert(['tenant_id' => $tenant->id], [...$data, 'recipients' => json_encode($data['recipients']), 'created_at' => $before?->created_at ?? now(), 'updated_at' => now()]);
            $access->audit($request->user(), $tenant, null, 'document_alert_settings_updated', $before ? (array) $before : null, $data);
        });

        return back()->with('status', 'Avisos de documentos guardados para '.$tenant->name.'.');
    }
}
