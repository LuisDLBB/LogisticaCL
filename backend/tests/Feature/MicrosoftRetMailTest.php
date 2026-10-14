<?php

namespace Tests\Feature;

use App\Fleet\MicrosoftRetMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MicrosoftRetMailTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): Tenant
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $actor = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->actingAs($actor)->withSession(['fleet_tenant_id' => $tenant->id]);

        return $tenant;
    }

    public function test_admin_configuration_is_encrypted_and_tokens_are_not_rendered(): void
    {
        $tenant = $this->administrator();
        $data = ['client_id' => '11111111-1111-4111-8111-111111111111', 'directory_id' => '22222222-2222-4222-8222-222222222222'];
        $this->put(route('fleet.microsoft-mail.configure'), $data)->assertSessionHasNoErrors();
        $mail = app(MicrosoftRetMail::class);
        $this->assertEquals($data, $mail->settings($tenant->id));
        $mail->save($tenant->id, $data + ['access_token' => 'private-access-token', 'refresh_token' => 'private-refresh-token', 'account' => MicrosoftRetMail::SENDER]);
        $file = Storage::disk('local')->get("microsoft-ret-mail/{$tenant->id}.encrypted");
        $this->assertStringNotContainsString('private-access-token', $file);
        $this->get(route('fleet.microsoft-mail.index'))->assertOk()->assertSee('Cuenta conectada')->assertDontSee('private-access-token')->assertDontSee('private-refresh-token');
        $this->post(route('fleet.microsoft-mail.disconnect'))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('access_token', $mail->settings($tenant->id));
    }

    public function test_configuration_requires_administrator_and_valid_identifiers(): void
    {
        $tenant = $this->administrator();
        $this->put(route('fleet.microsoft-mail.configure'), ['directory_id' => 'https://other.example', 'client_id' => 'invalid'])->assertSessionHasErrors(['directory_id', 'client_id']);
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'app_conductor', 'is_active' => true]);
        $this->actingAs($user)->get(route('fleet.microsoft-mail.index'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_device_connection_verifies_sender_and_stores_private_tokens(): void
    {
        $tenant = $this->administrator();
        $settings = ['client_id' => '11111111-1111-4111-8111-111111111111', 'directory_id' => '22222222-2222-4222-8222-222222222222'];
        $mail = app(MicrosoftRetMail::class);
        $mail->save($tenant->id, $settings);
        Http::fake([
            '*oauth2/v2.0/devicecode' => Http::response(['device_code' => 'private-device-code', 'user_code' => 'ABCD-EFGH', 'expires_in' => 900, 'interval' => 5]),
            '*oauth2/v2.0/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
            '*graph.microsoft.com/v1.0/me*' => Http::response(['mail' => MicrosoftRetMail::SENDER]),
        ]);
        $this->post(route('fleet.microsoft-mail.connect'))->assertSessionHasNoErrors();
        $this->get(route('fleet.microsoft-mail.index'))->assertOk()->assertSee('ABCD-EFGH')->assertDontSee('private-device-code');
        $this->travel(6)->seconds();
        $this->post(route('fleet.microsoft-mail.check'))->assertSessionHasNoErrors();
        $this->assertSame(MicrosoftRetMail::SENDER, $mail->settings($tenant->id)['account']);
        $this->assertSame('new-refresh', $mail->settings($tenant->id)['refresh_token']);
    }

    public function test_test_email_is_html_has_fixed_recipient_and_no_customer_copies(): void
    {
        $tenant = $this->administrator();
        $mail = app(MicrosoftRetMail::class);
        $mail->save($tenant->id, ['access_token' => 'private-token', 'expires_at' => now()->timestamp + 3600, 'account' => MicrosoftRetMail::SENDER]);
        Http::fake(['https://graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202)]);
        $mail->sendTest($tenant->id, '4N-RET-00017-5', '<h1>Retiro agendado</h1>');
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.microsoft.com/v1.0/me/sendMail'
            && $request['message']['body']['contentType'] === 'HTML'
            && $request['message']['body']['content'] === '<h1>Retiro agendado</h1>'
            && $request['message']['toRecipients'] === [['emailAddress' => ['address' => MicrosoftRetMail::TEST_RECIPIENT]]]
            && ! isset($request['message']['ccRecipients']));
    }

    public function test_logo_is_embedded_so_recipients_do_not_need_access_to_localhost(): void
    {
        $tenant = $this->administrator();
        $mail = app(MicrosoftRetMail::class);
        $mail->save($tenant->id, ['access_token' => 'token', 'expires_at' => now()->timestamp + 3600, 'account' => MicrosoftRetMail::SENDER]);
        Http::fake(['https://graph.microsoft.com/v1.0/me/sendMail' => Http::response([], 202)]);

        $mail->sendCustomer($tenant->id, 'Solicitud', '<img src="'.asset('images/logo4n.png').'">', ['customer@example.test']);

        Http::assertSent(function ($request): bool {
            $message = $request['message'];
            $attachment = $message['attachments'][0];

            return $message['body']['content'] === '<img src="cid:logo4n">'
                && $attachment['isInline'] === true
                && $attachment['contentId'] === 'logo4n'
                && base64_decode($attachment['contentBytes']) === file_get_contents(public_path('images/logo4n.png'));
        });
        Http::assertSentCount(1);
    }

    public function test_wrong_account_cannot_be_connected(): void
    {
        $this->administrator();
        Http::fake(['*graph.microsoft.com/v1.0/me*' => Http::response(['mail' => 'other@example.test'])]);
        $this->expectException(ValidationException::class);
        app(MicrosoftRetMail::class)->tokens([], ['access_token' => 'wrong-account-token', 'expires_in' => 3600]);
    }

    public function test_send_failure_is_not_reported_as_delivered_and_never_retries(): void
    {
        $tenant = $this->administrator();
        $mail = app(MicrosoftRetMail::class);
        $mail->save($tenant->id, ['access_token' => 'token', 'expires_at' => now()->timestamp + 3600, 'account' => MicrosoftRetMail::SENDER]);
        Http::fake(['*sendMail' => Http::response(['error' => ['message' => 'Forbidden']], 403)]);
        try {
            $mail->sendTest($tenant->id, 'Subject', '<p>Test</p>');
            $this->fail('Microsoft debe rechazar el envío.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('microsoft', $exception->errors());
        }
        Http::assertSentCount(1);
    }
}
