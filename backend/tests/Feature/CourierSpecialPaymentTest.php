<?php

namespace Tests\Feature;

use App\Models\CourierSpecialPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CourierSpecialPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_special_payments_are_imported_by_date_without_merging_repeated_ids(): void
    {
        $this->assertTrue(Schema::hasTable('tenants'));
        $path = $this->workbook([
            ['2026-07-29', 'Usuario', 'Jefe', 'Agente 1', 'REG', 'N/A', 'Concepción', 'Cliente A', 'Especial', 33000],
            ['2026-08-01', 'Usuario', 'Jefe', 'Agente 2', 'REG', 'N/A', 'Temuco', null, null, 4167],
        ]);

        try {
            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
            ])->assertRedirect(route('provider-payments.courier-movements.especiales'));

            $this->assertSame(2, CourierSpecialPayment::query()->count());
            $this->assertDatabaseHas('courier_special_payments', ['periodo' => '202607-Especiales', 'monto' => 33000]);
            $this->assertDatabaseHas('courier_special_payments', ['periodo' => '202608-Especiales', 'monto' => 4167]);
            $this->get(route('provider-payments.courier-movements.especiales'))
                ->assertOk()->assertSee('202608-Especiales')->assertSee('202607-Especiales');

            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
            ])->assertSessionHas('status', 'Se cargaron 0 pagos especiales; 2 filas ya estaban registradas.');
            $this->assertSame(2, CourierSpecialPayment::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_invalid_amount_does_not_import_any_row(): void
    {
        $path = $this->workbook([
            ['2026-07-29', 'Usuario', 'Jefe', 'Agente', 'REG', 'ABC', 'Concepción', 'Cliente', null, 33000],
            ['2026-07-30', 'Usuario', 'Jefe', 'Agente', 'REG', 'DEF', 'Concepción', 'Cliente', null, 'inválido'],
        ]);

        try {
            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
            ])->assertSessionHasErrors('file');
            $this->assertSame(0, CourierSpecialPayment::query()->count());
        } finally {
            @unlink($path);
        }
    }

    /** @param array<int, array<int, mixed>> $data */
    private function workbook(array $data): string
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['Fecha', 'Usuario Ingresa', 'Autoriza', 'Agente', 'Zona / Tipo', 'ID', 'Localidad', 'Cliente', 'Descripcion', 'Monto $$'],
            ...$data,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'special-payments-').'.xlsx';
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();

        return $path;
    }
}
