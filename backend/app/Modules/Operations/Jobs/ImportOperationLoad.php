<?php

namespace App\Modules\Operations\Jobs;

use App\Modules\Operations\Services\OperationImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportOperationLoad implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $loadId) {}

    public function handle(OperationImporter $importer): void
    {
        $load = DB::table('Ope_Cargas')->where('id', $this->loadId)->firstOrFail();
        if ($load->status === 'completed') {
            return;
        }
        if (! DB::table('Ope_Cargas')->where(['id' => $load->id, 'status' => 'queued'])->update(['status' => 'processing', 'updated_at' => now()])) {
            return;
        }
        try {
            $importer->import(Storage::disk('local')->path($load->path), $load->path, $load->filename, $load->tenant_id, $load->user_id, $load->source_type, $load->sheet, json_decode($load->mapping, true), $load->id);
        } catch (ValidationException $error) {
            $message = implode(' ', array_merge(...array_values($error->errors())));
            DB::table('Ope_Cargas')->where('id', $load->id)->update(['status' => 'failed', 'error' => $message, 'updated_at' => now()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('Ope_Cargas')->where('id', $this->loadId)->where('status', '<>', 'completed')->update(['status' => 'failed', 'error' => 'La importación se interrumpió. Vuelve a cargar el mismo archivo para reintentar.', 'updated_at' => now()]);
    }
}
