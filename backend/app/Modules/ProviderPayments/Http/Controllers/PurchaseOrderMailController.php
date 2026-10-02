<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Mail\PurchaseOrdersMail;
use App\Models\Provider;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\PurchaseOrderDocument;
use App\Modules\ProviderPayments\Services\PurchaseOrderExcelExport;
use App\Modules\ProviderPayments\Services\PurchaseOrderFilename;
use App\Modules\ProviderPayments\Services\PurchaseOrderPdfExport;
use App\Modules\ProviderPayments\Services\PurchaseOrderProviderName;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PurchaseOrderMailController
{
    private const SENDER = 'proveedores@4nlogistica.cl';

    private const TEST_RECIPIENT = 'luisdelabarra@gmail.com';

    private const TEST_PROVIDER = '77201525-9';

    private const COPY_RECIPIENTS = [
        'marcelo@4nlogistica.cl',
        'hansdelabarra@4nlogistica.cl',
        'natalialeyton@4nlogistica.cl',
        'luisdelabarra@4nlogistica.cl',
    ];

    public function index(Request $request): View
    {
        $period = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']])['period'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->assertClosed($tenant->id, $period);
        $orders = DB::table('PPR_Maestro_Pagos')->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->selectRaw('rut_proveedor, MIN(razon_social_proveedor) AS legal_name, MIN(empresa_mandante) AS company, oc')
            ->groupBy('rut_proveedor', 'oc')->orderBy('rut_proveedor')->orderBy('oc')->get();
        $providers = Provider::query()->where('tenant_id', $tenant->id)
            ->whereIn('tax_id', $orders->pluck('rut_proveedor')->unique())->get()->keyBy('tax_id');
        $sent = DB::table('PPR_purchase_order_mailings')->where('tenant_id', $tenant->id)
            ->where('periodo', $period)->where('mode', 'provider')->where('status', 'sent')
            ->pluck('sent_at', 'rut_proveedor');
        $groups = $orders->groupBy('rut_proveedor')->map(function ($rows, $rut) use ($providers, $sent): array {
            $provider = $providers->get($rut);

            return [
                'rut' => $rut,
                'name' => PurchaseOrderProviderName::display($rut, $provider?->legal_name ?: $rows->first()->legal_name),
                'emails' => $this->registeredEmails($provider),
                'ocs' => $rows->pluck('oc')->all(),
                'mandantes' => $rows->pluck('company')->map(fn (string $company): string => match (strtoupper(trim($company))) {
                    'PMBC', 'PMCB' => 'PMCB',
                    '4 NORTES LOGISTICA SPA', '4N' => '4N',
                    default => strtoupper(trim($company)),
                })->unique()->sort()->implode(' y '),
                'sent_at' => $sent->get($rut),
            ];
        })->values();
        $bulkGroups = $groups->filter(fn (array $group): bool => ! $group['sent_at'] && $group['emails'] !== [])
            ->map(fn (array $group): array => ['rut' => $group['rut'], 'name' => $group['name']])->values();
        $missingCount = $groups->filter(fn (array $group): bool => $group['emails'] === [])->count();
        $sentCount = $groups->filter(fn (array $group): bool => (bool) $group['sent_at'])->count();
        $test = $this->testRecord($tenant->id, $period);
        $configured = DB::table('PPR_purchase_order_mail_settings')->where('tenant_id', $tenant->id)->exists();
        $periodDate = CarbonImmutable::createFromFormat('Ymd', $period.'01')->locale('es');
        $periodLabel = Str::ucfirst($periodDate->translatedFormat('F Y'));
        $periodUpper = Str::upper($periodLabel);
        $deadline = $periodDate->endOfMonth()->format('d/m/Y');

        return view('provider-payments::purchase-order-mail', compact('period', 'periodLabel', 'periodUpper', 'deadline', 'groups', 'bulkGroups', 'missingCount', 'sentCount', 'test', 'configured'));
    }

    public function configure(Request $request): RedirectResponse
    {
        $this->assertLocal($request);
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'max:500'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        DB::table('PPR_purchase_order_mail_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id],
            ['username' => self::SENDER, 'encrypted_password' => Crypt::encryptString($validated['password']),
                'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('PPR_purchase_order_mailings')->where('tenant_id', $tenant->id)->where('mode', 'test')
            ->update(['confirmed_at' => null, 'updated_at' => now()]);

        return $this->back($validated['period'])->with('status', 'Credencial guardada cifrada. Ya puedes enviar la prueba.');
    }

    public function test(Request $request, PurchaseOrderDocument $documents, PurchaseOrderPdfExport $pdf,
        PurchaseOrderExcelExport $excel, PurchaseOrderFilename $filenames): RedirectResponse
    {
        $this->assertLocal($request);
        $period = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']])['period'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->dispatch($tenant->id, $period, self::TEST_PROVIDER, 'test', [self::TEST_RECIPIENT],
            'Prueba de prefacturas {periodo} - {proveedor}',
            "Esta es una prueba de las prefacturas de {proveedor}.\n\nÓrdenes de compra: {ocs}\n\nConfirma que recibiste todos los PDF y Excel antes de enviar a los proveedores.",
            $documents, $pdf, $excel, $filenames);

        return $this->back($period)->with('status', 'Prueba aceptada para '.self::TEST_RECIPIENT.'. Revisa los adjuntos y confirma su recepción aquí.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $this->assertLocal($request);
        $period = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']])['period'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $updated = DB::table('PPR_purchase_order_mailings')->where('tenant_id', $tenant->id)
            ->where('periodo', $period)->where('rut_proveedor', self::TEST_PROVIDER)
            ->where('mode', 'test')->where('status', 'sent')->update(['confirmed_at' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['period' => 'Primero envía una prueba real de DS GROUP.']);
        }

        return $this->back($period)->with('status', 'Recepción de prueba confirmada. Ya puedes enviar individualmente o a todos.');
    }

    public function send(Request $request, PurchaseOrderDocument $documents, PurchaseOrderPdfExport $pdf,
        PurchaseOrderExcelExport $excel, PurchaseOrderFilename $filenames): RedirectResponse|JsonResponse
    {
        $this->assertLocal($request);
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{6}$/'],
            'rut_proveedor' => ['required', 'string', 'max:20'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'additional_emails' => ['nullable', 'string', 'max:1000'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        if (! $this->testRecord($tenant->id, $validated['period'])?->confirmed_at) {
            throw ValidationException::withMessages(['period' => 'Confirma primero que recibiste la prueba de DS GROUP.']);
        }
        $provider = Provider::query()->where('tenant_id', $tenant->id)
            ->where('tax_id', $validated['rut_proveedor'])->first();
        $recipients = $this->registeredEmails($provider);
        $additional = preg_split('/[;,\s]+/', (string) ($validated['additional_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($additional as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['additional_emails' => "El correo adicional {$email} no es válido."]);
            }
            $recipients[] = $email;
        }
        $recipients = array_values(array_unique(array_map('strtolower', $recipients)));
        if ($recipients === []) {
            throw ValidationException::withMessages(['rut_proveedor' => 'Este proveedor no tiene correo registrado. Agrégalo en Proveedores o indica uno adicional.']);
        }
        $this->dispatch($tenant->id, $validated['period'], $validated['rut_proveedor'], 'provider', $recipients,
            $validated['subject'], $validated['body'], $documents, $pdf, $excel, $filenames);
        $message = 'Prefacturas enviadas a '.implode(', ', $recipients).'.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : $this->back($validated['period'])->with('status', $message);
    }

    private function dispatch(int $tenantId, string $period, string $rut, string $mode, array $recipients,
        string $subjectTemplate, string $bodyTemplate, PurchaseOrderDocument $documents,
        PurchaseOrderPdfExport $pdf, PurchaseOrderExcelExport $excel, PurchaseOrderFilename $filenames): void
    {
        $this->assertClosed($tenantId, $period);
        $this->configureMailer($tenantId);
        $ocs = DB::table('PPR_Maestro_Pagos')->where('tenant_id', $tenantId)->where('periodo', $period)
            ->where('rut_proveedor', $rut)->whereNotNull('oc')->distinct()->orderBy('oc')->pluck('oc')->all();
        if ($ocs === []) {
            throw ValidationException::withMessages(['rut_proveedor' => 'No hay órdenes cerradas para este proveedor y período.']);
        }
        $attachments = [];
        $name = '';
        $companyCodes = [];
        foreach ($ocs as $oc) {
            $document = $documents->load($tenantId, $oc);
            $name = $document['proveedor'];
            $companyCodes[] = $document['company_code'];
            $attachments[] = [$filenames->forDocument($document, 'pdf'), $pdf->render($document), 'application/pdf'];
            $attachments[] = [$filenames->forDocument($document, 'xlsx'), $excel->render($document),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }
        if (array_sum(array_map(fn (array $attachment): int => strlen($attachment[1]), $attachments)) > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['rut_proveedor' => 'Los adjuntos superan 20 MB. Revisa las OC antes de enviar.']);
        }
        $periodDate = CarbonImmutable::createFromFormat('Ymd', $period.'01')->locale('es');
        $companyCodes = array_values(array_unique($companyCodes));
        sort($companyCodes);
        $replacements = [
            '{periodo}' => $period,
            '{mes}' => Str::ucfirst($periodDate->translatedFormat('F Y')),
            '{mes_mayusculas}' => Str::upper(Str::ucfirst($periodDate->translatedFormat('F Y'))),
            '{plazo}' => $periodDate->endOfMonth()->format('d/m/Y'),
            '{mandantes}' => implode(' y ', $companyCodes),
            '{proveedor}' => $name,
            '{ocs}' => implode(', ', $ocs),
        ];
        $subject = strtr($subjectTemplate, $replacements);
        $body = strtr($bodyTemplate, $replacements);
        if ($mode === 'test') {
            $subject = '[PRUEBA] '.$subject;
        }
        $copyRecipients = $mode === 'provider' ? array_values(array_diff(self::COPY_RECIPIENTS, $recipients)) : [];
        $key = ['tenant_id' => $tenantId, 'periodo' => $period, 'rut_proveedor' => $rut, 'mode' => $mode];
        $existing = DB::table('PPR_purchase_order_mailings')->where($key)->first();
        if ($mode === 'provider' && in_array($existing?->status, ['sent', 'sending'], true)) {
            throw ValidationException::withMessages(['rut_proveedor' => $existing?->status === 'sent'
                ? 'Este proveedor ya recibió las OC de este período. No se enviará dos veces.'
                : 'Ya hay un envío en curso para este proveedor. Revisa el resultado antes de reintentar.']);
        }
        $details = [
            'recipient' => implode(', ', $recipients), 'subject' => $subject, 'body' => $body,
            'cc' => implode(', ', $copyRecipients),
            'oc_list' => json_encode($ocs, JSON_THROW_ON_ERROR), 'status' => 'sending',
            'sent_at' => null, 'confirmed_at' => null, 'updated_at' => now(), 'created_at' => now(),
        ];
        if ($mode === 'provider') {
            $claimed = $existing === null
                ? DB::table('PPR_purchase_order_mailings')->insertOrIgnore([...$key, ...$details])
                : DB::table('PPR_purchase_order_mailings')->where($key)->whereIn('status', ['failed', 'failed_smtp_auth'])->update($details);
            if ($claimed !== 1) {
                throw ValidationException::withMessages(['rut_proveedor' => 'Otro envío acaba de iniciar. Revisa el estado antes de reintentar.']);
            }
        } else {
            DB::table('PPR_purchase_order_mailings')->updateOrInsert($key, $details);
        }
        try {
            Mail::mailer('smtp')->to($recipients)->cc($copyRecipients)->send(new PurchaseOrdersMail($subject, $body, $attachments));
        } catch (Throwable $exception) {
            $smtpDisabled = str_contains(strtolower($exception->getMessage()), 'smtpclientauthentication is disabled');
            DB::table('PPR_purchase_order_mailings')->where($key)->update([
                'status' => $smtpDisabled ? 'failed_smtp_auth' : 'failed', 'updated_at' => now(),
            ]);
            throw ValidationException::withMessages(['period' => $smtpDisabled
                ? 'Microsoft 365 rechazó la prueba: SMTP autenticado está desactivado para proveedores@4nlogistica.cl. Un administrador debe habilitar «Authenticated SMTP» para ese buzón antes de reintentar.'
                : 'No se pudo enviar. Revisa la credencial y la configuración de Microsoft 365.']);
        }
        DB::table('PPR_purchase_order_mailings')->where($key)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
    }

    private function configureMailer(int $tenantId): void
    {
        $setting = DB::table('PPR_purchase_order_mail_settings')->where('tenant_id', $tenantId)->first();
        if ($setting === null) {
            throw ValidationException::withMessages(['period' => 'Configura primero la clave de proveedores@4nlogistica.cl.']);
        }
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp.office365.com', 'port' => 587,
            'username' => $setting->username, 'password' => Crypt::decryptString($setting->encrypted_password),
            'timeout' => 60,
        ]);
    }

    private function assertClosed(int $tenantId, string $period): void
    {
        if (! DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenantId)->where('periodo', $period)->exists()) {
            throw ValidationException::withMessages(['period' => 'Las prefacturas solo se envían cuando el período está cerrado.']);
        }
    }

    private function assertLocal(Request $request): void
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
    }

    private function testRecord(int $tenantId, string $period): ?object
    {
        return DB::table('PPR_purchase_order_mailings')->where('tenant_id', $tenantId)
            ->where('periodo', $period)->where('rut_proveedor', self::TEST_PROVIDER)
            ->where('mode', 'test')->first();
    }

    /** @return array<int, string> */
    private function registeredEmails(?Provider $provider): array
    {
        return collect([$provider?->contact_email, $provider?->contact_email_secondary])
            ->filter(fn (?string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn (string $email): string => strtolower(trim($email)))->unique()->values()->all();
    }

    private function back(string $period): RedirectResponse
    {
        return redirect()->route('provider-payments.courier-movements.compile.purchase-orders.mail', ['period' => $period]);
    }
}
