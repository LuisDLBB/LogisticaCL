<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Fleet\VehicleCatalog;
use App\Fleet\VehicleMaintenanceReference;
use App\Fleet\WeeklyVehicleControl;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as SpreadsheetReadException;

class VehicleKilometerController extends Controller
{
    private function week(Request $request): Carbon
    {
        $data = $request->validate(['semana' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.today('America/Santiago')->toDateString()]]);

        return Carbon::parse($data['semana'] ?? today('America/Santiago')->toDateString(), 'America/Santiago')->startOfWeek();
    }

    public function index(Request $request, FleetAccess $access, VehicleCatalog $catalog, WeeklyVehicleControl $control): View
    {
        $week = $this->week($request);
        $vehicles = $control->vehicles($request, $access, $catalog);
        $rows = $control->rows($vehicles, $week, $request, $access);
        if ($request->query('captura') === 'all') {
            $rows = $rows->sortBy(function (array $row): array {
                $priority = match (true) {
                    $row['in_workshop'] => 4,
                    $row['label'] === 'Con margen' => 0,
                    in_array($row['label'], ['Verificar kilometraje', 'Completar intervalo / última mantención'], true) => 1,
                    $row['maintenance_needed'] => 2,
                    default => 3,
                };

                return [$priority, $row['label'], $row['vehicle']->plate];
            })->values();
            $history = DB::table('VEH_weekly_readings')->whereIn('vehicle_id', $vehicles->pluck('vehicle.id'))
                ->whereBetween('week_start', [$week->copy()->subWeeks(11)->toDateString(), $week->toDateString()])
                ->orderByDesc('id')->get()->groupBy('vehicle_id');
            $weeklyBars = $vehicles->mapWithKeys(function (array $entry) use ($history, $week): array {
                $readings = $history->get($entry['vehicle']->id, collect())->unique('week_start')->keyBy('week_start');
                $bars = collect(range(7, 0))->map(function (int $offset) use ($readings, $week): array {
                    $date = $week->copy()->subWeeks($offset);
                    $current = $readings->get($date->toDateString());
                    $previous = $readings->get($date->copy()->subWeek()->toDateString());
                    $km = null;
                    $status = 'Sin lectura esta semana';
                    $review = false;
                    if ($current && ! $previous) {
                        $status = 'Falta lectura de la semana anterior';
                    } elseif ($current && $previous) {
                        if ($current->odometer_km <= 0) {
                            $status = 'Cero informado; no permite calcular recorrido';
                        } elseif ($previous->odometer_km <= 0) {
                            $status = 'Lectura anterior en cero; no permite calcular recorrido';
                        } elseif ($current->odometer_km < $previous->odometer_km) {
                            $status = 'KM menor que la lectura anterior; revisar';
                        } else {
                            $km = $current->odometer_km - $previous->odometer_km;
                            $review = ! $current->accepted || ! $previous->accepted;
                            $status = number_format($km, 0, ',', '.').' km recorridos'.($review ? ' · Lectura con observación; ver detalle' : '');
                        }
                    }

                    return ['week' => $date->toDateString(), 'label' => $date->format('d/m'), 'km' => $km, 'status' => $status, 'review' => $review];
                });

                return [$entry['vehicle']->id => $bars];
            });
            $barMaximums = $weeklyBars->map(fn ($bars): int => max(1, $bars->max('km') ?? 0));

            return view('fleet::vehicles.km-capture', compact('week', 'rows', 'weeklyBars', 'barMaximums', 'history'));
        }
        $workshopRows = $rows->where('in_workshop', true)->values();
        $operatingRows = $rows->where('in_workshop', false)->values();
        $previewIds = array_keys((array) $request->old('readings', []));
        $captureRows = $operatingRows->filter(fn (array $row): bool => $row['capture_required'] || (! $row['maintenance_needed'] && in_array($row['vehicle']->id, $previewIds)))->values();
        $maintenanceRows = $operatingRows->where('maintenance_needed', true)->values();
        $completedRows = $operatingRows->filter(fn (array $row): bool => ! $row['maintenance_needed'] && ! $captureRows->contains('vehicle.id', $row['vehicle']->id))->values();
        $planningWeek = $week->copy()->addWeek();
        $tenant = $access->tenant($request);
        $maintenanceOwners = $vehicles->pluck('owner')->unique('id')->filter(fn ($owner): bool => $access->allows($request->user(), $owner, 'operations.maintenance'))->pluck('id');
        $calendar = VehicleMaintenance::query()->with(['vehicle', 'provider'])->whereIn('vehicle_id', $vehicles->pluck('vehicle.id'))->whereIn('tenant_id', $maintenanceOwners)->whereIn('status', ['pending', 'scheduled', 'in_progress'])
            ->where(function ($query) use ($week): void {
                $query->whereBetween('scheduled_at', [$week->copy()->utc(), $week->copy()->endOfWeek()->utc()])->orWhere('status', 'in_progress');
            })->orderBy('scheduled_at')->get();
        $nextCalendar = VehicleMaintenance::query()->with(['vehicle', 'provider'])->whereIn('vehicle_id', $vehicles->pluck('vehicle.id'))->whereIn('tenant_id', $maintenanceOwners)->whereIn('status', ['pending', 'scheduled', 'in_progress'])
            ->whereBetween('scheduled_at', [$planningWeek->copy()->utc(), $planningWeek->copy()->endOfWeek()->utc()])->orderBy('scheduled_at')->get();
        $pending = VehicleMaintenance::query()->with(['vehicle', 'provider'])->whereIn('vehicle_id', $vehicles->pluck('vehicle.id'))->whereIn('tenant_id', $maintenanceOwners)->whereIn('status', ['pending', 'scheduled'])->whereNull('scheduled_at')->get();
        $trendReadings = DB::table('VEH_weekly_readings')->whereIn('vehicle_id', $vehicles->pluck('vehicle.id'))
            ->whereBetween('week_start', [$week->copy()->subWeeks(11)->toDateString(), $week->toDateString()])
            ->orderByDesc('id')->get()->groupBy('vehicle_id');
        $trends = $vehicles->mapWithKeys(function (array $entry) use ($trendReadings, $week): array {
            $weekly = $trendReadings->get($entry['vehicle']->id, collect())->unique('week_start')->keyBy('week_start');
            $series = collect(range(11, 0))->map(function (int $offset) use ($weekly, $week): array {
                $date = $week->copy()->subWeeks($offset);
                $record = $weekly->get($date->toDateString());

                return ['date' => $date->toDateString(), 'label' => $date->format('d/m'), 'km' => $record?->odometer_km, 'accepted' => $record ? (bool) $record->accepted : false];
            });
            $current = $weekly->get($week->toDateString());
            $previous = $weekly->get($week->copy()->subWeek()->toDateString());
            $delta = $current !== null && $previous !== null && $current->odometer_km !== null && $previous->odometer_km !== null ? $current->odometer_km - $previous->odometer_km : null;

            return [$entry['vehicle']->id => ['series' => $series, 'current' => $current, 'previous' => $previous, 'delta' => $delta]];
        });

        return view('fleet::vehicles.kilometers', compact('week', 'rows', 'captureRows', 'maintenanceRows', 'completedRows', 'workshopRows', 'planningWeek', 'calendar', 'nextCalendar', 'pending', 'trends', 'tenant'));
    }

    public function store(Request $request, FleetAccess $access, VehicleCatalog $catalog, WeeklyVehicleControl $control): RedirectResponse
    {
        $week = $this->week($request);
        $data = $request->validate([
            'readings' => ['required', 'array', 'max:500'],
            'readings.*.vehicle_id' => ['required', 'integer', 'distinct'],
            'readings.*.odometer_km' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'readings.*.last_maintenance_km' => ['prohibited'],
            'readings.*.interval_km' => ['prohibited'],
            'readings.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $visible = $control->vehicles($request, $access, $catalog)->keyBy('vehicle.id');
        $entries = collect($data['readings'])->filter(fn ($row): bool => ($row['odometer_km'] ?? null) !== null);
        if ($entries->isEmpty()) {
            throw ValidationException::withMessages(['readings' => 'Ingresa al menos un kilometraje.']);
        }
        foreach ($entries as $entry) {
            $owner = $visible->get($entry['vehicle_id'])['owner'] ?? null;
            abort_unless($owner && $access->allows($request->user(), $owner, 'operations.fleet', 2), 403);
        }
        $flagged = 0;
        DB::transaction(function () use ($entries, $visible, $week, $request, &$flagged): void {
            foreach ($entries as $entry) {
                $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($entry['vehicle_id']);
                abort_if(in_array(mb_strtolower(trim($vehicle->operational_status ?? '')), ['robado', 'stolen', 'vendido', 'sold'], true), 403);
                $km = $entry['odometer_km'];
                $reference = app(VehicleMaintenanceReference::class)->forVehicles(collect([$vehicle->id]), $week)->get($vehicle->id);
                $baseline = $reference['baseline'];
                $issues = [];
                if ($km === 0) {
                    $issues[] = 'Lectura cero: pendiente de verificar';
                }
                if ($baseline !== null && $km < $baseline) {
                    $issues[] = 'KM actual menor a la última mantención';
                }
                $latestWeek = DB::table('VEH_weekly_readings')->where('vehicle_id', $vehicle->id)->where('accepted', true)->max('week_start');
                if (($latestWeek === null || $week->toDateString() >= $latestWeek) && $vehicle->odometer_km !== null && $km < $vehicle->odometer_km) {
                    $issues[] = 'Lectura menor al kilometraje vigente; no se redujo el vehículo';
                }
                $accepted = $issues === [];
                $isCurrent = $accepted && ($latestWeek === null || $week->toDateString() >= $latestWeek);
                DB::table('VEH_weekly_readings')->insert([
                    'is_test' => app()->environment(['local', 'testing']) && config('fleet.auto_test_mode'),
                    'vehicle_id' => $vehicle->id, 'tenant_id' => $visible[$vehicle->id]['owner']->id, 'actor_user_id' => $request->user()->id,
                    'week_start' => $week->toDateString(), 'odometer_km' => $km, 'last_maintenance_km' => $baseline, 'interval_km' => $reference['interval'],
                    'accepted' => $accepted, 'issues' => json_encode($issues, JSON_UNESCAPED_UNICODE), 'notes' => $entry['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($isCurrent) {
                    $vehicle->update(['odometer_km' => $km]);
                }
                if (! $accepted) {
                    $flagged++;
                }
            }
        });

        $parameters = $request->input('return_to') === 'fleet' ? [] : ['semana' => $week->toDateString()];
        if ($request->input('return_to') === 'km_capture') {
            $parameters['captura'] = 'all';
        }

        return redirect()->route($request->input('return_to') === 'fleet' ? 'fleet.page.operations-fleet' : 'fleet.kilometers.index', $parameters)->with('status', $entries->count().' lecturas guardadas. '.$flagged.' pendientes de verificar.');
    }

    public function preview(Request $request, FleetAccess $access, VehicleCatalog $catalog, WeeklyVehicleControl $control): RedirectResponse
    {
        $week = $this->week($request);
        $request->validate(['archivo' => ['nullable', 'file', 'mimes:xlsx,csv,txt', 'max:5120'], 'pegado' => ['nullable', 'string', 'max:200000']]);
        if ($request->hasFile('archivo')) {
            try {
                $reader = IOFactory::createReaderForFile($request->file('archivo')->getRealPath());
                $reader->setReadDataOnly(true);
                $sheet = $reader->load($request->file('archivo')->getRealPath());
                if ($sheet->getActiveSheet()->getHighestDataRow() > 501) {
                    throw ValidationException::withMessages(['archivo' => 'La carga admite hasta 500 vehículos.']);
                }
                $table = $sheet->getActiveSheet()->rangeToArray('A1:O501', null, false, false, false);
                $sheet->disconnectWorksheets();
            } catch (SpreadsheetReadException) {
                throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Revisa que sea Excel XLSX o CSV válido.']);
            }
        } else {
            $text = trim((string) $request->input('pegado'));
            if ($text === '') {
                throw ValidationException::withMessages(['pegado' => 'Pega una tabla de Excel o selecciona un archivo.']);
            }
            $separator = str_contains(strtok($text, "\n"), "\t") ? "\t" : (str_contains(strtok($text, "\n"), ';') ? ';' : ',');
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $text);
            rewind($stream);
            $table = [];
            while (($row = fgetcsv($stream, 0, $separator, '"', '')) !== false) {
                $table[] = $row;
            }
            fclose($stream);
        }
        if (count($table) > 501) {
            throw ValidationException::withMessages(['archivo' => 'La carga admite hasta 500 vehículos.']);
        }
        $header = collect(array_shift($table) ?? [])->map(fn ($value): string => preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim((string) $value)))))->all();
        $position = fn (array $names): int|false => collect($header)->search(fn ($name): bool => in_array($name, $names, true));
        $plateCol = $position(['patente']);
        $kmCol = $position(['km', 'kmactual', 'kilometraje', 'odometerkm']);
        if ($plateCol === false || $kmCol === false) {
            throw ValidationException::withMessages(['archivo' => 'La tabla debe tener las columnas Patente y KM Actual.']);
        }
        $noteCol = $position(['obs', 'observaciones']);
        $visible = $control->vehicles($request, $access, $catalog)->keyBy(fn ($entry): string => strtoupper(preg_replace('/[^a-z0-9]/i', '', $entry['vehicle']->plate)));
        $preview = [];
        $skipped = 0;
        foreach ($table as $row) {
            $plate = strtoupper(preg_replace('/[^a-z0-9]/i', '', (string) ($row[$plateCol] ?? '')));
            if ($plate === '') {
                continue;
            }
            $entry = $visible->get($plate);
            if (! $entry || ! $access->allows($request->user(), $entry['owner'], 'operations.fleet', 2)) {
                $skipped++;

                continue;
            }
            $number = function ($value) use ($plate): ?int {
                $value = trim(str_replace(["\xc2\xa0", '.', ' '], '', (string) $value));
                if ($value === '') {
                    return null;
                }
                if (! ctype_digit($value) || strlen($value) > 10 || (int) $value > 2000000000) {
                    throw ValidationException::withMessages(['archivo' => 'Kilometraje inválido en '.$plate.'. Usa kilómetros enteros.']);
                }

                return (int) $value;
            };
            if (isset($preview[$entry['vehicle']->id])) {
                throw ValidationException::withMessages(['archivo' => 'Patente repetida en la carga: '.$plate]);
            }
            $values = ['vehicle_id' => $entry['vehicle']->id, 'odometer_km' => $number($row[$kmCol] ?? null)];
            if ($noteCol !== false) {
                $values['notes'] = (string) ($row[$noteCol] ?? '');
            }
            $preview[$entry['vehicle']->id] = $values;
        }
        if ($preview === []) {
            throw ValidationException::withMessages(['archivo' => 'No hay vehículos autorizados para incorporar en esta tabla.']);
        }

        $parameters = ['semana' => $week->toDateString()];
        if ($request->input('return_to') === 'km_capture') {
            $parameters['captura'] = 'all';
        }

        return redirect()->route('fleet.kilometers.index', $parameters)->withInput(['readings' => $preview])->with('status', 'Vista previa: '.$skipped.' filas omitidas (incluye vendidos, robados o fuera de tu acceso). Revisa los datos y guarda las lecturas.');
    }
}
