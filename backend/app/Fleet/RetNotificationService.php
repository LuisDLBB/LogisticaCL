<?php

namespace App\Fleet;

use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RetNotificationService
{
    private const INTERNAL_DETAILS_SEPARATOR = "\n\nRegistro operativo interno:\n";

    public function __construct(private readonly CoordinationService $coordination) {}

    public function prepare(ServiceRequest $ret): void
    {
        $emails = $this->emails($ret->operational_emails_snapshot);
        if ($emails === []) {
            $ret->update(['email_status' => 'not_prepared', 'email_to_snapshot' => null, 'email_cc_snapshot' => null, 'email_subject' => null, 'email_body' => null, 'email_prepared_at' => null, 'driver_token_hash' => null, 'driver_token_expires_at' => null]);

            return;
        }
        $settings = DB::table('ret_settings')->where('tenant_id', $ret->tenant_id)->first();
        $cc = $this->emails($ret->branch?->postventa_cc ?: $settings?->postventa_cc);
        $sender = $settings?->sender ?: config('mail.from.address');
        $key = hash('sha256', json_encode([$ret->id, $ret->ret_code, $ret->status, $ret->service_date->toDateString(), $ret->window_start, $ret->window_end, $ret->driver_user_id, $ret->vehicle_id, $ret->packages, $ret->effective_address, $emails, $cc, $sender]));
        $previous = DB::table('ret_notifications')->where('service_request_id', $ret->id)->orderByDesc('id')->first();
        if ($previous && $previous->fingerprint === $key && $ret->driver_token_hash) {
            return;
        }
        // A later return to a previous assignment must issue a fresh, valid link.
        $fingerprint = $key;
        $key = hash('sha256', $fingerprint.'|'.($previous?->id ?? 0));
        $token = bin2hex(random_bytes(32));
        $ret->update(['driver_token_hash' => hash('sha256', $token), 'driver_token_expires_at' => $ret->service_date->copy()->endOfDay()->addDays(2)]);
        $preview = $this->coordination->confirmationPreview($ret, $ret->driver->name, $ret->vehicle->plate);
        $body = $preview['body'].self::INTERNAL_DETAILS_SEPARATOR.'Retiro: '.($ret->pickup_name ?: $ret->point_name_snapshot)."\nRazón social: {$ret->legal_name_snapshot}\nRegistro del conductor: ".route('fleet.driver.show', ['token' => $token]);
        $ret->update(['email_status' => 'prepared', 'email_to_snapshot' => implode('; ', $emails), 'email_cc_snapshot' => $cc === [] ? null : implode('; ', $cc), 'email_subject' => $preview['subject'], 'email_body' => $body, 'email_prepared_at' => now()]);
        $id = DB::table('ret_notifications')->insertGetId([
            'service_request_id' => $ret->id, 'dedupe_key' => $key, 'fingerprint' => $fingerprint, 'recipients' => implode('; ', $emails), 'cc' => implode('; ', $cc), 'sender' => $sender, 'subject' => $preview['subject'], 'body' => $body, 'status' => 'prepared', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(fn () => $this->capture($id));
    }

    public function emails(?string $value): array
    {
        $emails = array_values(array_unique(array_filter(array_map('trim', preg_split('/[;,]/', (string) $value)))));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [];
            }
        }

        return $emails;
    }

    public function emailHtml(string $body, ?string $merchant = null): string
    {
        $customerBody = explode(self::INTERNAL_DETAILS_SEPARATOR, $body, 2)[0];
        if ($merchant !== null && str_starts_with($customerBody, '**Hola 👋**')) {
            $greeting = str_replace(["\r", "\n", '*', '_', '[', ']'], ' ', $merchant);
            $customerBody = str_replace('**Hola 👋**', '**Hola, '.$greeting.' 👋**', $customerBody);
        }
        if (! str_contains($customerBody, '**Documentación y trazabilidad:**')) {
            $customerBody = str_replace('Saludos cordiales,', '**Documentación y trazabilidad:** antes del retiro, asegúrate de que las guías de despacho de tus envíos estén correctamente emitidas y registradas en la plataforma de **4N Logística**, con información completa y consistente con la carga que entregarás.'."\n\n".'Saludos cordiales,', $customerBody);
        }
        $paragraphs = preg_split('/\R\s*\R/', trim($customerBody));
        $details = [];
        $sections = [];
        foreach ($paragraphs as $paragraph) {
            if (str_starts_with($paragraph, '- **Fecha:**')) {
                foreach (preg_split('/\R/', $paragraph) as $line) {
                    if (preg_match('/^- \*\*(.+?):\*\* (.*)$/u', $line, $matches)) {
                        $details[$matches[1]] = $matches[2];
                    }
                }
            } else {
                $sections[] = Str::markdown($paragraph, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
            }
        }
        preg_match('/4N-RET-[0-9]+-[0-9K]/', $customerBody, $code);

        return view('fleet::emails.pickup-confirmation', [
            'details' => $details, 'sections' => $sections, 'retCode' => $code[0] ?? 'Retiro agendado',
        ])->render();
    }

    private function sendAutomaticTest(int $id): void
    {
        $pending = DB::transaction(function () use ($id): ?array {
            $notice = DB::table('ret_notifications')->lockForUpdate()->find($id);
            if (! $notice || ! in_array($notice->status, ['prepared', 'captured', 'error'], true)) {
                return null;
            }
            $ret = ServiceRequest::findOrFail($notice->service_request_id);
            if (! in_array($ret->status, ['scheduled', 'rescheduled', 'reprogramming_pending'], true) || $ret->email_body !== $notice->body) {
                DB::table('ret_notifications')->where('id', $id)->update(['status' => 'superseded', 'updated_at' => now()]);

                return null;
            }
            DB::table('ret_notifications')->where('id', $id)->update(['status' => 'sending', 'attempts' => $notice->attempts + 1, 'updated_at' => now()]);

            return [$notice, $ret];
        }, 3);
        if (! $pending) {
            return;
        }
        [$notice, $ret] = $pending;
        try {
            app(MicrosoftRetMail::class)->sendTest($ret->tenant_id, $notice->subject, $this->emailHtml($notice->body, $ret->client_name_snapshot));
            $status = 'test_accepted';
            $error = null;
        } catch (\Throwable $exception) {
            $definitive = $exception instanceof ValidationException && ! str_contains(implode(' ', array_merge(...array_values($exception->errors()))), 'No pudimos confirmar');
            $status = $definitive ? 'error' : 'uncertain';
            $error = $definitive ? 'No se envió la confirmación de prueba. Revisa Microsoft 365.' : 'No se confirmó el envío. Revisa Enviados antes de repetir.';
            report($exception);
        }
        DB::table('ret_notifications')->where('id', $id)->update(['status' => $status, 'last_error' => $error, 'updated_at' => now()]);
        $this->coordination->event($ret, null, 'notification_'.$status, ['notification_id' => $id, 'attempt' => $notice->attempts + 1, 'recipient' => MicrosoftRetMail::TEST_RECIPIENT]);
    }

    public function capture(int $id): void
    {
        if (app()->environment('local') && config('fleet.auto_test_mode')) {
            $candidate = DB::table('ret_notifications')->find($id);
            $ret = $candidate ? ServiceRequest::find($candidate->service_request_id) : null;
            if ($ret?->is_test && (app(MicrosoftRetMail::class)->settings($ret->tenant_id)['automatic_receipts'] ?? false)) {
                $this->sendAutomaticTest($id);

                return;
            }
        }
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        DB::transaction(function () use ($id): void {
            $mail = DB::table('ret_notifications')->lockForUpdate()->find($id);
            if (! $mail || ! in_array($mail->status, ['prepared', 'error'], true)) {
                return;
            }
            $ret = ServiceRequest::findOrFail($mail->service_request_id);
            if (! in_array($ret->status, ['scheduled', 'rescheduled', 'reprogramming_pending'], true) || $ret->email_body !== $mail->body) {
                DB::table('ret_notifications')->where('id', $id)->update(['status' => 'superseded', 'updated_at' => now()]);

                return;
            }
            try {
                $customerBody = explode(self::INTERNAL_DETAILS_SEPARATOR, $mail->body, 2)[0];
                $html = $this->emailHtml($customerBody, $ret->client_name_snapshot);
                Mail::mailer('log')->html($html, function ($message) use ($mail): void {
                    $message->from($mail->sender)->to($this->emails($mail->recipients))->subject($mail->subject);
                    if ($this->emails($mail->cc) !== []) {
                        $message->cc($this->emails($mail->cc));
                    }
                });
                $status = 'captured';
                $error = null;
            } catch (\Throwable $exception) {
                report($exception);
                $status = 'error';
                $error = 'No se pudo capturar el correo. Revisa la configuración del transporte local.';
            }
            DB::table('ret_notifications')->where('id', $id)->update(['status' => $status, 'attempts' => $mail->attempts + 1, 'last_error' => $error, 'updated_at' => now()]);
            $this->coordination->event($ret, null, 'notification_'.$status, ['notification_id' => $id, 'attempt' => $mail->attempts + 1]);
        }, 3);
    }
}
