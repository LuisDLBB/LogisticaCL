<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\CoordinationService;
use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class RetDriverController extends Controller
{
    public function show(string $token): Response
    {
        return response()->view('fleet::coordination.driver', ['ret' => $this->ret($token), 'token' => $token])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, string $token, CoordinationService $service): RedirectResponse
    {
        $data = $request->validate(['guide_number' => ['required', 'string', 'max:100'], 'actual_packages' => ['required', 'integer', 'min:0', 'max:1000000']]);
        DB::transaction(function () use ($token, $data, $service): void {
            $ret = $this->ret($token, true);
            $ret->update($data + ['status' => 'retired', 'retired_at' => now(), 'driver_token_hash' => null, 'driver_token_expires_at' => null]);
            $service->event($ret, null, 'retired_driver_token', $data + ['driver_user_id' => $ret->driver_user_id]);
        }, 3);

        return redirect()->route('fleet.driver.done');
    }

    private function ret(string $token, bool $lock = false): ServiceRequest
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $query = ServiceRequest::where('driver_token_hash', hash('sha256', $token))->where('driver_token_expires_at', '>', now())->whereIn('status', ['scheduled', 'rescheduled', 'reprogramming_pending']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
