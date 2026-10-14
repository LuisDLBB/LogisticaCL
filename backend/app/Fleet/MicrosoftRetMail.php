<?php

namespace App\Fleet;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MicrosoftRetMail
{
    public const SENDER = 'retiros@4nlogistica.cl';

    public const TEST_RECIPIENT = 'hansdelabarra@4nlogistica.cl';

    public function settings(int $tenantId): array
    {
        $disk = Storage::disk('local');
        $path = "microsoft-ret-mail/{$tenantId}.encrypted";

        return $disk->exists($path) ? json_decode(Crypt::decryptString($disk->get($path)), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function save(int $tenantId, array $settings): void
    {
        Storage::disk('local')->put("microsoft-ret-mail/{$tenantId}.encrypted", Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)));
    }

    public function begin(array $settings): array
    {
        return $this->oauth($settings, 'devicecode', ['client_id' => $settings['client_id'], 'scope' => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read offline_access']);
    }

    public function oauth(array $settings, string $endpoint, array $data): array
    {
        try {
            $response = Http::asForm()->timeout(25)->post("https://login.microsoftonline.com/{$settings['directory_id']}/oauth2/v2.0/{$endpoint}", $data);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['microsoft' => 'No fue posible conectar con Microsoft. Intenta nuevamente.']);
        }
        $result = $response->json();
        if (! is_array($result)) {
            throw ValidationException::withMessages(['microsoft' => 'Microsoft no devolvió una respuesta válida.']);
        }
        if ($response->failed() && ! in_array($result['error'] ?? '', ['authorization_pending', 'slow_down'], true)) {
            throw ValidationException::withMessages(['microsoft' => 'Microsoft no autorizó la conexión. Revisa el registro de aplicación, permisos y acceso de la cuenta.']);
        }

        return $result;
    }

    public function tokens(array $settings, array $response): array
    {
        if (empty($response['access_token'])) {
            throw ValidationException::withMessages(['microsoft' => 'La conexión no devolvió una autorización válida.']);
        }
        try {
            $profile = Http::withToken($response['access_token'])->timeout(25)->get('https://graph.microsoft.com/v1.0/me', ['$select' => 'mail,userPrincipalName']);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['microsoft' => 'No se pudo verificar la cuenta de correo.']);
        }
        $email = strtolower((string) ($profile->json('mail') ?: $profile->json('userPrincipalName')));
        if (! $profile->successful() || $email !== self::SENDER) {
            throw ValidationException::withMessages(['microsoft' => 'Inicia sesión con retiros@4nlogistica.cl para conectar el remitente aprobado.']);
        }

        return array_replace($settings, [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $settings['refresh_token'] ?? null,
            'expires_at' => now()->timestamp + (int) ($response['expires_in'] ?? 0),
            'account' => $email,
        ]);
    }

    public function sendTest(int $tenantId, string $subject, string $html): void
    {
        $this->sendCustomer($tenantId, '[PRUEBA HTML] '.$subject, $html, [self::TEST_RECIPIENT]);
    }

    public function sendCustomer(int $tenantId, string $subject, string $html, array $recipients, array $cc = [], ?string $replyTo = null): void
    {
        foreach (array_merge($recipients, $cc, $replyTo ? [$replyTo] : []) as $email) {
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['mail' => 'Revisa las direcciones del correo.']);
            }
        }
        if ($recipients === []) {
            throw ValidationException::withMessages(['mail' => 'Falta el destinatario.']);
        }
        $settings = $this->settings($tenantId);
        if (empty($settings['access_token']) || ($settings['account'] ?? '') !== self::SENDER) {
            throw ValidationException::withMessages(['microsoft' => 'Conecta primero la cuenta retiros@4nlogistica.cl.']);
        }
        if (($settings['expires_at'] ?? 0) <= now()->timestamp + 60) {
            if (empty($settings['refresh_token'])) {
                throw ValidationException::withMessages(['microsoft' => 'La autorización venció. Conecta nuevamente la cuenta.']);
            }
            $response = $this->oauth($settings, 'token', ['client_id' => $settings['client_id'], 'grant_type' => 'refresh_token', 'refresh_token' => $settings['refresh_token']]);
            $settings = $this->tokens($settings, $response);
            $this->save($tenantId, $settings);
        }
        $attachments = [];
        $logoUrl = asset('images/logo4n.png');
        if (str_contains($html, $logoUrl)) {
            $html = str_replace($logoUrl, 'cid:logo4n', $html);
            $attachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => 'logo4n.png',
                'contentType' => 'image/png',
                'contentBytes' => base64_encode(file_get_contents(public_path('images/logo4n.png'))),
                'isInline' => true,
                'contentId' => 'logo4n',
            ];
        }
        try {
            $response = Http::withToken($settings['access_token'])->timeout(30)->post('https://graph.microsoft.com/v1.0/me/sendMail', [
                'message' => [
                    ...($attachments ? ['attachments' => $attachments] : []),
                    'subject' => $subject,
                    'body' => ['contentType' => 'HTML', 'content' => $html],
                    'toRecipients' => array_map(fn ($address) => ['emailAddress' => ['address' => $address]], $recipients),
                    ...($cc ? ['ccRecipients' => array_map(fn ($address) => ['emailAddress' => ['address' => $address]], $cc)] : []),
                    ...($replyTo ? ['replyTo' => [['emailAddress' => ['address' => $replyTo]]]] : []),
                ],
                'saveToSentItems' => true,
            ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['microsoft' => 'No pudimos confirmar el envío. Revisa Enviados en Outlook antes de repetir la prueba.']);
        }
        if ($response->status() !== 202) {
            throw ValidationException::withMessages(['microsoft' => 'Microsoft no aceptó el envío. Revisa la autorización Mail.Send y conecta nuevamente la cuenta.']);
        }
    }
}
