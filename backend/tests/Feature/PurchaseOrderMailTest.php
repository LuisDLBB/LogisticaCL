<?php

namespace Tests\Feature;

use App\Mail\PurchaseOrdersMail;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\ProviderPaymentsWorkflowTestCase;

class PurchaseOrderMailTest extends ProviderPaymentsWorkflowTestCase
{
    use RefreshDatabase;

    public function test_ds_group_test_and_individual_mail_include_all_three_orders_and_block_duplicates(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '77201525-9', 'tax_id_number' => '77201525',
            'tax_id_check_digit' => '9', 'legal_name' => 'DS GROUP SPA',
            'contact_email' => 'dsg@example.com', 'contact_email_secondary' => 'contabilidad@example.com',
        ]);
        foreach (['General' => 'DSG', 'Troncal Norte' => 'DSG_Troncal_Norte', 'Troncal V' => 'DSG_RUTA_V'] as $scope => $stem) {
            $provider->ocFilenames()->create(['company_code' => '4N', 'service_scope' => $scope, 'file_stem' => $stem]);
        }
        $this->payment($tenant->id, $provider, 'TEST-DS-GENERAL', 'Variable', 'Servicio Standar');
        $this->payment($tenant->id, $provider, 'TEST-DS-NORTE', 'Acuerdos', 'Troncal Norte');
        $this->payment($tenant->id, $provider, 'TEST-DS-V', 'Acuerdos', 'Fijo Mensual');
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202609'])
            ->assertSessionHas('status');
        $this->assertSame(3, DB::table('Maestro_Pagos')->distinct()->count('oc'));

        $this->get(route('provider-payments.courier-movements.compile.purchase-orders.mail', ['period' => '202609']))
            ->assertOk()->assertSee('DS GROUP SPA')->assertSee('6 archivos')
            ->assertSee('Pre-factura servicios {mandantes} {mes} - {proveedor}')
            ->assertSee('Adjuntamos las pre-facturas de servicios correspondientes a {mes}')
            ->assertSee('Vista previa para');
        $this->post(route('provider-payments.courier-movements.compile.purchase-orders.mail.send'), [
            'period' => '202609', 'rut_proveedor' => '77201525-9',
            'subject' => 'Prefacturas {periodo}', 'body' => 'OC {ocs}',
        ])->assertSessionHasErrors('period');
        $this->post(route('provider-payments.courier-movements.compile.purchase-orders.mail.test'), ['period' => '202609'])
            ->assertSessionHasErrors('period');
        Mail::fake();
        $this->post(route('provider-payments.courier-movements.compile.purchase-orders.mail.configure'), [
            'period' => '202609', 'password' => 'clave-de-prueba',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('purchase_order_mail_settings', ['tenant_id' => $tenant->id, 'username' => 'proveedores@4nlogistica.cl']);
        $this->assertDatabaseMissing('purchase_order_mail_settings', ['encrypted_password' => 'clave-de-prueba']);

        $this->post(route('provider-payments.courier-movements.compile.purchase-orders.mail.test'), ['period' => '202609'])
            ->assertSessionHas('status');
        Mail::assertSent(PurchaseOrdersMail::class, function (PurchaseOrdersMail $mail): bool {
            $files = $mail->attachments();

            return $mail->hasTo('luisdelabarra@gmail.com')
                && ! $mail->hasCc('marcelo@4nlogistica.cl')
                && count($files) === 6
                && count(array_filter($mail->files, fn (array $file): bool => str_ends_with($file[0], '.pdf'))) === 3
                && count(array_filter($mail->files, fn (array $file): bool => str_ends_with($file[0], '.xlsx'))) === 3
                && str_contains($mail->render(), 'Confirma que recibiste')
                && $mail->mailSubject === '[PRUEBA] Prueba de prefacturas 202609 - DS GROUP SPA';
        });
        $this->post(route('provider-payments.courier-movements.compile.purchase-orders.mail.confirm'), ['period' => '202609'])
            ->assertSessionHas('status');
        $this->post(route('provider-payments.courier-movements.compile.purchase-orders.mail.send'), [
            'period' => '202609', 'rut_proveedor' => '77201525-9',
            'subject' => 'Pre-factura servicios {mandantes} {mes} - {proveedor}',
            'body' => 'OC {ocs}; plazo {plazo}; mes {mes}',
            'additional_emails' => 'otro@example.com',
        ])->assertSessionHas('status');
        Mail::assertSent(PurchaseOrdersMail::class, function (PurchaseOrdersMail $mail): bool {
            return $mail->hasTo('dsg@example.com') && $mail->hasTo('contabilidad@example.com')
                && $mail->hasTo('otro@example.com') && count($mail->attachments()) === 6
                && $mail->hasCc('marcelo@4nlogistica.cl')
                && $mail->hasCc('hansdelabarra@4nlogistica.cl')
                && $mail->hasCc('natalialeyton@4nlogistica.cl')
                && $mail->hasCc('luisdelabarra@4nlogistica.cl')
                && $mail->mailSubject === 'Pre-factura servicios 4N Septiembre 2026 - DS GROUP SPA'
                && $mail->messageText === 'OC 2026090001, 2026090002, 2026090003; plazo 30/09/2026; mes Septiembre 2026';
        });
        $this->assertDatabaseHas('purchase_order_mailings', [
            'rut_proveedor' => '77201525-9', 'mode' => 'provider',
            'cc' => 'marcelo@4nlogistica.cl, hansdelabarra@4nlogistica.cl, natalialeyton@4nlogistica.cl, luisdelabarra@4nlogistica.cl',
        ]);
        $this->post(route('provider-payments.courier-movements.compile.purchase-orders.mail.send'), [
            'period' => '202609', 'rut_proveedor' => '77201525-9',
            'subject' => 'Repetir', 'body' => 'Repetir',
        ])->assertSessionHasErrors('rut_proveedor');
        Mail::assertSentCount(2);
    }

    private function payment(int $tenantId, Provider $provider, string $tracking, string $process, string $service): void
    {
        $movement = CourierMovement::query()->create([
            'tenant_id' => $tenantId, 'tracking_number' => $tracking,
            'nombre_proceso' => '202609-'.$process, 'tipo_pago' => $process,
        ]);
        CourierPaymentMovement::query()->create([
            'tenant_id' => $tenantId, 'courier_movement_id' => $movement->id,
            'periodo' => '202609', 'nombre_proceso' => $process, 'tipo_pago' => $process,
            'seguimiento_paquete' => $tracking, 'peso_final' => 1, 'direccion' => 'Calle de prueba',
            'condicion_pago' => 'SI', 'valor' => 1000, 'zona' => 'RM',
            'provider_id' => $provider->id, 'razon_social_proveedor' => $provider->legal_name,
            'rut_proveedor' => $provider->tax_id, 'empresa_mandante' => '4N',
            'tipo_documento' => 'Factura', 'service_name' => $service,
        ]);
    }
}
