<?php

namespace App\Fleet;

use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PickupCustomerService
{
    public const REASONS = ['customer' => 'Solicitud del cliente', 'unavailable_goods' => 'Mercadería no disponible', 'incomplete_information' => 'Información incompleta', 'driver' => 'Falta de conductor', 'vehicle' => 'Falta o cambio de vehículo', 'overload' => 'Sobrecarga operacional', 'mechanical' => 'Contingencia mecánica', 'weather' => 'Clima o condición de ruta', 'closed_point' => 'Punto cerrado o sin acceso', 'scheduling_error' => 'Error de programación', 'other' => 'Otro'];

    public function receipt(ServiceRequest $ret): void
    {
        if (DB::table('ret_customer_notifications')->where('service_request_id', $ret->id)->where('kind', 'receipt')->exists()) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $this->prepare($ret, 'receipt', view('fleet::emails.pickup-received', ['ret' => $ret, 'token' => $token])->render(), $token);
    }

    public function prepare(ServiceRequest $ret, string $kind, string $html, ?string $token = null): void
    {
        $renderer = app(RetNotificationService::class);
        $recipients = array_values(array_unique(array_map('strtolower', $renderer->emails($ret->operational_emails_snapshot))));
        $reply = $renderer->emails($ret->branch?->postventa_cc ?: DB::table('ret_settings')->where('tenant_id', $ret->tenant_id)->value('postventa_cc'));
        $requester = $ret->requester?->email;
        if ($requester && filter_var($requester, FILTER_VALIDATE_EMAIL) && ! str_ends_with(strtolower($requester), '.local')) {
            array_unshift($reply, $requester);
        }
        $reply = array_values(array_unique(array_map('strtolower', $reply)));
        $cc = $reply;
        if ($kind === 'cancellation') {
            $operations = DB::table('tenant_users')->join('fleet_profiles', function ($join): void {
                $join->on('fleet_profiles.code', '=', 'tenant_users.role_code')->on('fleet_profiles.tenant_id', '=', 'tenant_users.tenant_id');
            })
                ->join('fleet_profile_permissions', 'fleet_profile_permissions.fleet_profile_id', '=', 'fleet_profiles.id')->join('users', 'users.id', '=', 'tenant_users.user_id')
                ->where('tenant_users.tenant_id', $ret->tenant_id)->where('tenant_users.is_active', true)->where('fleet_profiles.is_active', true)
                ->where('fleet_profile_permissions.permission_code', 'operations.scheduling')->where('fleet_profile_permissions.access_level', '>=', 2)->pluck('users.email')->all();
            $cc = array_values(array_unique(array_merge($cc, array_filter($operations, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) && ! str_ends_with(strtolower($email), '.local')))));
        }
        $cc = array_values(array_diff(array_unique(array_map('strtolower', $cc)), $recipients));
        $id = DB::table('ret_customer_notifications')->insertGetId(['service_request_id' => $ret->id, 'kind' => $kind, 'subject' => $ret->ret_code.' | '.($kind === 'receipt' ? 'Solicitud de retiro recibida' : 'Retiro cancelado por el cliente'), 'html' => $html, 'recipients' => implode('; ', $recipients), 'cc' => implode('; ', $cc), 'reply_to' => $reply[0] ?? MicrosoftRetMail::SENDER,
            'token_hash' => $token ? hash('sha256', $token) : null, 'expires_at' => $token ? $ret->service_date->copy()->endOfDay()->addDay() : null, 'status' => $recipients ? 'prepared' : 'missing_recipient', 'created_at' => now(), 'updated_at' => now()]);
        DB::afterCommit(fn () => $this->deliver($id));
    }

    public function deliver(int $id, bool $test = false, bool $explicit = false): void
    {
        $row = DB::table('ret_customer_notifications')->find($id);
        if (! $row) {
            return;
        }
        $ret = ServiceRequest::findOrFail($row->service_request_id);
        if ($row->kind === 'receipt' && in_array($ret->status, ['cancelled', 'retired'], true)) {
            if ($explicit || $test) {
                throw ValidationException::withMessages(['mail' => 'Este aviso ya no corresponde al estado del retiro.']);
            }
            DB::table('ret_customer_notifications')->where('id', $id)->whereNotIn('status', ['accepted', 'test_accepted', 'sending', 'uncertain'])->update(['status' => 'superseded', 'updated_at' => now()]);

            return;
        }
        $mail = app(MicrosoftRetMail::class);
        $settings = app()->environment('testing') ? [] : $mail->settings($ret->tenant_id);
        if (! $test && in_array($row->status, ['accepted', 'test_accepted', 'sending', 'uncertain'], true)) {
            return;
        }
        $automaticTest = ! $test && $ret->is_test && app()->environment('local') && config('fleet.auto_test_mode') && ($settings['automatic_receipts'] ?? false);
        $real = ! $test && ! $ret->is_test && ($explicit || ($settings['automatic_receipts'] ?? false));
        try {
            if ($test) {
                $mail->sendTest($ret->tenant_id, $row->subject, $row->html);

                return;
            }
            if ($automaticTest) {
                $claimed = DB::table('ret_customer_notifications')->where('id', $id)->whereNotIn('status', ['accepted', 'test_accepted', 'sending', 'uncertain'])->update(['status' => 'sending', 'updated_at' => now()]);
                if (! $claimed) {
                    return;
                }
                $mail->sendTest($ret->tenant_id, $row->subject, $row->html);
                $status = 'test_accepted';
            } elseif ($real) {
                $claimed = DB::table('ret_customer_notifications')->where('id', $id)->whereNotIn('status', ['accepted', 'test_accepted', 'sending', 'uncertain'])->update(['status' => 'sending', 'updated_at' => now()]);
                if (! $claimed) {
                    return;
                }
                $to = app(RetNotificationService::class)->emails($row->recipients);
                if (! $to) {
                    throw ValidationException::withMessages(['mail' => 'Falta el correo del cliente.']);
                }
                $mail->sendCustomer($ret->tenant_id, $row->subject, $row->html, $to, app(RetNotificationService::class)->emails($row->cc), $row->reply_to);
                $status = 'accepted';
            } else {
                if ($row->status === 'missing_recipient') {
                    return;
                }
                Mail::mailer('log')->html($row->html, function ($message) use ($row): void {
                    $message->from(MicrosoftRetMail::SENDER)->to(app(RetNotificationService::class)->emails($row->recipients))->subject($row->subject);
                });
                $status = 'captured';
            }
            DB::table('ret_customer_notifications')->where('id', $id)->update(['status' => $status, 'attempts' => $row->attempts + 1, 'last_error' => null, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            if ($test) {
                throw $exception;
            }
            $ambiguous = ($real || $automaticTest) && ! ($exception instanceof ValidationException && ! str_contains(implode(' ', array_merge(...array_values($exception->errors()))), 'No pudimos confirmar'));
            DB::table('ret_customer_notifications')->where('id', $id)->update(['status' => $ambiguous ? 'uncertain' : 'error', 'attempts' => $row->attempts + 1, 'last_error' => $ambiguous ? 'No se confirmó el envío. Revisa Enviados antes de repetir.' : 'No se envió el aviso. Revisa la conexión Microsoft y los destinatarios.', 'updated_at' => now()]);
            if ($explicit || $test) {
                throw $exception;
            }
            report($exception);
        }
    }
}
