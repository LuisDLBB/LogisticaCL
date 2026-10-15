<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class FuelImport
{
    public const HEADERS = ['Empresa', 'Patente', 'Producto', 'Tarjeta', 'Fecha Transacción', 'Hora Transacción', 'Guía de Despacho', 'Precio', 'Volumen', 'Monto', 'Odómetro (Kms.)'];

    public function read(string $path, string $extension): array
    {
        if ($extension === 'xlsx') {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw ValidationException::withMessages(['file' => 'El Excel no se pudo abrir.']);
            }
            try {
                $total = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $total += $zip->statIndex($i)['size'];
                }
                if ($total > 50000000) {
                    throw ValidationException::withMessages(['file' => 'El contenido del Excel es demasiado grande.']);
                }
                $parse = function (string $xml): \SimpleXMLElement {
                    if (stripos($xml, '<!DOCTYPE') !== false) {
                        throw ValidationException::withMessages(['file' => 'Formato XML no admitido.']);
                    }
                    $value = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
                    if (! $value) {
                        throw ValidationException::withMessages(['file' => 'El Excel contiene datos inválidos.']);
                    }

                    return $value;
                };
                $strings = [];
                $shared = $zip->getFromName('xl/sharedStrings.xml');
                if ($shared !== false) {
                    foreach ($parse($shared)->si as $item) {
                        $strings[] = isset($item->t) ? (string) $item->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($item->r)));
                    }
                }
                $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
                if ($xml === false) {
                    throw ValidationException::withMessages(['file' => 'No se encontró la primera hoja del Excel.']);
                }
                $rows = [];
                foreach ($parse($xml)->sheetData->row as $row) {
                    $values = [];
                    foreach ($row->c as $cell) {
                        preg_match('/^([A-Z]+)/', (string) $cell['r'], $reference);
                        $column = 0;
                        foreach (str_split($reference[1]) as $letter) {
                            $column = $column * 26 + ord($letter) - 64;
                        }
                        $type = (string) $cell['t'];
                        $values[$column - 1] = $type === 's' ? ($strings[(int) $cell->v] ?? '') : ($type === 'inlineStr' ? (string) $cell->is->t : (string) $cell->v);
                        if (in_array($type, ['', 'n'], true) && str_contains($values[$column - 1], '.')) {
                            $values[$column - 1] = str_replace('.', ',', $values[$column - 1]);
                        }
                    }
                    if ($values) {
                        $rows[] = array_replace(array_fill(0, max(array_keys($values)) + 1, ''), $values);
                    }
                }
            } finally {
                $zip->close();
            }
        } else {
            $text = file_get_contents($path);
            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            }
            $first = strtok($text, "\r\n");
            $separator = str_contains($first, "\t") ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $text);
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, 0, $separator, '"', '')) !== false) {
                if (array_filter($row, fn ($v) => trim((string) $v) !== '')) {
                    $rows[] = $row;
                }
            }
            fclose($stream);
        }
        $headers = array_map(fn ($v) => trim(ltrim((string) $v, "\xEF\xBB\xBF")), array_shift($rows) ?? []);
        if (array_diff(self::HEADERS, $headers) || count($rows) > 20000) {
            throw ValidationException::withMessages(['file' => 'Usa el encabezado de la planilla de combustible, con hasta 20.000 transacciones.']);
        }

        return array_map(function (array $row) use ($headers): array {
            if (count($row) > count($headers)) {
                throw ValidationException::withMessages(['file' => 'Hay filas con más columnas que el encabezado.']);
            }

            return array_combine($headers, array_pad($row, count($headers), ''));
        }, $rows);
    }

    public function import(array $rows, int $actorId, array $allowedTenantIds, string $filename, bool $isTest): array
    {
        $tenants = Tenant::whereIn('id', $allowedTenantIds)->get()->keyBy('code');
        $vehicles = Vehicle::all()->keyBy('plate');
        $normalized = [];
        foreach ($rows as $index => $raw) {
            $raw = array_map(fn ($value) => trim((string) $value), $raw);
            $fail = function (string $message) use ($index): never {
                throw ValidationException::withMessages(['file' => 'Fila '.($index + 2).': '.$message]);
            };
            $tenant = $tenants->get(strtoupper($raw['Empresa'] ?? ''));
            if (! $tenant) {
                $fail('empresa sin acceso o no reconocida.');
            }
            $plate = preg_replace('/[^A-Z0-9]/', '', strtoupper($raw['Patente'] ?? ''));
            if (! preg_match('/^[A-Z]{4}[0-9]{2}$/', $plate) || empty($raw['Guía de Despacho']) || empty($raw['Tarjeta']) || empty($raw['Producto'])) {
                $fail('revisa patente, guía, tarjeta y producto.');
            }
            try {
                $dateText = str_replace(',', '.', $raw['Fecha Transacción']);
                $date = is_numeric($dateText) ? Carbon::parse('1899-12-30')->addDays((int) $dateText) : Carbon::createFromFormat('!d-m-Y', str_replace('/', '-', $dateText), 'America/Santiago');
                if (! is_numeric($dateText) && $date->format('d-m-Y') !== str_replace('/', '-', $dateText)) {
                    $fail('fecha inválida.');
                }
                $timeText = str_replace(',', '.', $raw['Hora Transacción']);
                if ((is_numeric($timeText) && ((float) $timeText < 0 || (float) $timeText >= 1)) || (! is_numeric($timeText) && ! preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d:[0-5]\d$/', $timeText))) {
                    $fail('hora inválida.');
                }
                $time = is_numeric($timeText) ? gmdate('H:i:s', (int) round((float) $timeText * 86400)) : Carbon::createFromFormat('!H:i:s', $timeText)->format('H:i:s');
            } catch (\Throwable) {
                $fail('fecha u hora inválida.');
            }
            $numbers = [];
            foreach (['Precio', 'Volumen', 'Monto', 'Odómetro (Kms.)'] as $field) {
                $value = str_replace(['$', ' '], '', $raw[$field] ?? '');
                if (str_contains($value, ',')) {
                    $value = str_replace(',', '.', str_replace('.', '', $value));
                } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $value)) {
                    $value = str_replace('.', '', $value);
                }
                if ($value === '' && $field === 'Odómetro (Kms.)') {
                    $numbers[$field] = null;
                } elseif (! is_numeric($value) || (float) $value < 0 || (float) $value > 999999999) {
                    $fail('valor inválido en '.$field.'.');
                } else {
                    $numbers[$field] = (float) $value;
                }
            }
            $identity = [$tenant->id, $plate, $date->toDateString(), $time, $raw['Guía de Despacho'], $raw['Tarjeta'], mb_strtoupper($raw['Producto'])];
            $payload = [$identity, $numbers, $raw['Estación de Servicio'] ?? '', $raw['Rut Chofer'] ?? ''];
            $normalized[] = ['tenant_id' => $tenant->id, 'vehicle_id' => $vehicles->get($plate)?->id, 'source_key' => hash('sha256', json_encode($identity)), 'payload_hash' => hash('sha256', json_encode($payload)), 'plate' => $plate, 'transaction_date' => $date->toDateString(), 'transaction_time' => $time, 'guide' => mb_substr($raw['Guía de Despacho'], 0, 80), 'product' => mb_substr($raw['Producto'], 0, 100), 'station' => mb_substr($raw['Estación de Servicio'] ?? '', 0, 250), 'driver' => mb_substr($raw['Chofer'] ?? '', 0, 180), 'price' => $numbers['Precio'], 'volume' => $numbers['Volumen'], 'amount' => $numbers['Monto'], 'odometer_km' => $numbers['Odómetro (Kms.)'], 'source_data' => json_encode($raw, JSON_UNESCAPED_UNICODE), 'is_test' => $isTest, 'created_at' => now(), 'updated_at' => now()];
        }

        return DB::transaction(function () use ($normalized, $actorId, $filename, $isTest, $allowedTenantIds): array {
            Tenant::whereIn('id', $allowedTenantIds)->orderBy('id')->lockForUpdate()->get();
            $id = DB::table('VEH_fuel_imports')->insertGetId(['actor_user_id' => $actorId, 'filename' => mb_substr($filename, 0, 200), 'inserted_count' => 0, 'duplicate_count' => 0, 'is_test' => $isTest, 'created_at' => now(), 'updated_at' => now()]);
            $inserted = 0;
            $duplicates = 0;
            foreach ($normalized as $row) {
                $existing = DB::table('VEH_fuel_transactions')->where('tenant_id', $row['tenant_id'])->where('source_key', $row['source_key'])->first();
                if ($existing) {
                    if ($existing->payload_hash !== $row['payload_hash']) {
                        throw ValidationException::withMessages(['file' => 'La guía '.$row['guide'].' de '.$row['plate'].' ya existe con otros datos. Revisa la diferencia antes de cargarla. No se guardó esta carga.']);
                    }
                    $duplicates++;

                    continue;
                }
                DB::table('VEH_fuel_transactions')->insert($row + ['import_id' => $id]);
                $inserted++;
            }
            DB::table('VEH_fuel_imports')->where('id', $id)->update(['inserted_count' => $inserted, 'duplicate_count' => $duplicates]);

            return ['inserted' => $inserted, 'duplicates' => $duplicates];
        });
    }
}
