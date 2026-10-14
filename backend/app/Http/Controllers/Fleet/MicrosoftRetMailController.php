<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\CoordinationService;
use App\Fleet\FleetAccess;
use App\Fleet\MicrosoftRetMail;
use App\Fleet\RetNotificationService;
use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MicrosoftRetMailController extends Controller
{
    private function local(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
    }

    public function index(Request $request, FleetAccess $access, MicrosoftRetMail $mail): View
    {
        $this->local();
        $tenant = $access->tenant($request);
        $settings = $mail->settings($tenant->id);
        $publicSettings = array_intersect_key($settings, array_flip(['directory_id', 'client_id', 'account', 'automatic_receipts']));
        $pending = $request->session()->get('ret_microsoft_pending');
        $connection = $pending && $pending['tenant_id'] === $tenant->id && $pending['actor_id'] === $request->user()->id && $pending['expires_at'] > now()->timestamp ? array_intersect_key($pending, array_flip(['user_code', 'expires_at'])) : null;
        $notifications = DB::table('ret_notifications')->join('service_requests', 'service_requests.id', '=', 'ret_notifications.service_request_id')
            ->where('service_requests.tenant_id', $tenant->id)->whereIn('service_requests.status', ['scheduled', 'rescheduled', 'reprogramming_pending'])
            ->select('ret_notifications.id', 'ret_notifications.subject')->orderByDesc('ret_notifications.id')->limit(20)->get();

        return view('fleet::coordination.microsoft-mail', compact('publicSettings', 'connection', 'notifications'));
    }

    public function configure(Request $request, FleetAccess $access, MicrosoftRetMail $mail): RedirectResponse
    {
        $this->local();
        $data = $request->validate(['directory_id' => ['required', 'uuid'], 'client_id' => ['required', 'uuid']]);
        $current = $mail->settings($access->tenant($request)->id);
        $unchanged = ($current['directory_id'] ?? '') === $data['directory_id'] && ($current['client_id'] ?? '') === $data['client_id'];
        $mail->save($access->tenant($request)->id, $unchanged ? array_replace($current, $data) : $data);
        $request->session()->forget('ret_microsoft_pending');

        return back()->with('status', 'Datos de aplicación guardados. Ahora conecta la cuenta de retiros.');
    }

    public function connect(Request $request, FleetAccess $access, MicrosoftRetMail $mail): RedirectResponse
    {
        $this->local();
        $tenantId = $access->tenant($request)->id;
        $settings = $mail->settings($tenantId);
        if (empty($settings['client_id']) || empty($settings['directory_id'])) {
            throw ValidationException::withMessages(['microsoft' => 'Guarda primero los dos identificadores de Microsoft Entra.']);
        }
        $response = $mail->begin($settings);
        if (empty($response['device_code']) || empty($response['user_code'])) {
            throw ValidationException::withMessages(['microsoft' => 'Microsoft no generó el código de conexión.']);
        }
        $request->session()->put('ret_microsoft_pending', [
            'device_code' => $response['device_code'], 'user_code' => $response['user_code'], 'client_id' => $settings['client_id'], 'directory_id' => $settings['directory_id'],
            'tenant_id' => $tenantId, 'actor_id' => $request->user()->id, 'expires_at' => now()->timestamp + (int) $response['expires_in'],
            'interval' => max(5, (int) ($response['interval'] ?? 5)), 'next_check' => now()->timestamp + max(5, (int) ($response['interval'] ?? 5)),
        ]);

        return back()->with('status', 'Abre Microsoft e ingresa el código mostrado para autorizar tu cuenta.');
    }

    public function check(Request $request, FleetAccess $access, MicrosoftRetMail $mail): RedirectResponse
    {
        $this->local();
        $pending = $request->session()->get('ret_microsoft_pending');
        $tenantId = $access->tenant($request)->id;
        $settings = $mail->settings($tenantId);
        if (! $pending || $pending['tenant_id'] !== $tenantId || $pending['actor_id'] !== $request->user()->id || $pending['expires_at'] <= now()->timestamp
            || ($settings['client_id'] ?? '') !== $pending['client_id'] || ($settings['directory_id'] ?? '') !== $pending['directory_id']) {
            throw ValidationException::withMessages(['microsoft' => 'Inicia nuevamente la conexión de esta empresa.']);
        }
        if ($pending['next_check'] > now()->timestamp) {
            return back()->with('status', 'Espera unos segundos antes de comprobar la conexión.');
        }
        $pending['next_check'] = now()->timestamp + $pending['interval'];
        $request->session()->put('ret_microsoft_pending', $pending);
        $response = $mail->oauth($settings, 'token', ['client_id' => $settings['client_id'], 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $pending['device_code']]);
        if (in_array($response['error'] ?? '', ['authorization_pending', 'slow_down'], true)) {
            if (($response['error'] ?? '') === 'slow_down') {
                $pending['interval'] += 5;
                $pending['next_check'] = now()->timestamp + $pending['interval'];
                $request->session()->put('ret_microsoft_pending', $pending);
            }

            return back()->with('status', 'Microsoft aún espera que completes la autorización.');
        }
        $mail->save($tenantId, $mail->tokens($settings, $response));
        $request->session()->forget('ret_microsoft_pending');

        return back()->with('status', 'Cuenta de retiros conectada. Puedes enviar la prueba HTML.');
    }

    public function disconnect(Request $request, FleetAccess $access, MicrosoftRetMail $mail): RedirectResponse
    {
        $this->local();
        $tenantId = $access->tenant($request)->id;
        $mail->save($tenantId, array_intersect_key($mail->settings($tenantId), array_flip(['client_id', 'directory_id'])));
        $request->session()->forget('ret_microsoft_pending');

        return back()->with('status', 'Conexión local eliminada. Puedes revocar el permiso también en Microsoft Entra.');
    }

    public function send(Request $request, FleetAccess $access, MicrosoftRetMail $mail, RetNotificationService $renderer, CoordinationService $coordination): RedirectResponse
    {
        $this->local();
        $data = $request->validate(['notification_id' => ['required', 'integer']]);
        $notification = DB::table('ret_notifications')->find($data['notification_id']);
        abort_unless($notification, 404);
        $ret = ServiceRequest::findOrFail($notification->service_request_id);
        abort_unless($ret->tenant_id === $access->tenant($request)->id, 404);
        if (! in_array($ret->status, ['scheduled', 'rescheduled', 'reprogramming_pending'], true) || $ret->email_body !== $notification->body) {
            throw ValidationException::withMessages(['notification_id' => 'Selecciona el aviso vigente de un retiro agendado.']);
        }
        $mail->sendTest($ret->tenant_id, $notification->subject, $renderer->emailHtml($notification->body, $ret->client_name_snapshot));
        $coordination->event($ret, $request->user(), 'notification_test_accepted', ['notification_id' => $notification->id, 'recipient' => MicrosoftRetMail::TEST_RECIPIENT, 'sender' => MicrosoftRetMail::SENDER]);

        return back()->with('status', 'Microsoft aceptó la prueba HTML para hansdelabarra@4nlogistica.cl. Revisa tu bandeja de entrada.');
    }
}
