<?php

namespace App\Modules\ProviderPayments\Services;

use Illuminate\Support\Str;

class PeumoRateResolver
{
    private ?array $rates = null;

    /** @return array{status:string,first?:int,rest?:int,locality?:string} */
    public function resolve(string $commune): array
    {
        $key = $this->key($commune);
        if ($key === '') {
            return ['status' => 'missing'];
        }

        $matches = $this->rates()[$key] ?? [];
        $valid = collect($matches)->filter(fn (array $row): bool => $row['first'] > 0 && $row['rest'] > 0);
        $distinct = $valid->unique(fn (array $row): string => $row['first'].'|'.$row['rest'])->values();
        if ($distinct->count() > 1) {
            return ['status' => 'ambiguous'];
        }
        if ($distinct->isEmpty()) {
            return ['status' => 'missing'];
        }

        return ['status' => 'ok', ...$distinct->first()];
    }

    private function rates(): array
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        $this->rates = [];
        $file = fopen(base_path('database/data/peumo_tariffs.tsv'), 'r');
        if ($file === false) {
            return $this->rates;
        }
        fgetcsv($file, 0, "\t");
        while (($row = fgetcsv($file, 0, "\t")) !== false) {
            $locality = trim((string) ($row[0] ?? ''));
            $first = filter_var(trim((string) ($row[1] ?? '')), FILTER_VALIDATE_INT);
            $rest = filter_var(trim((string) ($row[2] ?? '')), FILTER_VALIDATE_INT);
            $key = $this->key($locality);
            if ($key === '') {
                continue;
            }
            $this->rates[$key][] = [
                'first' => $first === false ? 0 : $first,
                'rest' => $rest === false ? 0 : $rest,
                'locality' => $locality,
            ];
        }
        fclose($file);

        return $this->rates;
    }

    private function key(string $commune): string
    {
        $key = Str::of($commune)->squish()->lower()->ascii()->toString();

        return preg_match('/^los\s+.*ngeles$/u', $key) === 1 ? 'los angeles' : $key;
    }
}
