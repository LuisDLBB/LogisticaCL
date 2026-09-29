<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Client;
use App\Models\Coverage;
use App\Models\Provider;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CourierSpecialPaymentMatcher
{
    /**
     * @param  Collection<int, Coverage>  $coverages
     * @param  Collection<int, Provider>  $providers
     * @return array{id: ?int, score: int, uncertain: bool, matched: ?string}
     */
    public function provider(string $agent, Collection $coverages, Collection $providers): array
    {
        $commune = preg_replace('/^operador\s+/iu', '', trim($agent)) ?? '';
        $scores = [];
        $matches = [];
        $providersByTaxId = $providers->keyBy('tax_id');

        foreach ($coverages as $coverage) {
            $providerId = $coverage->provider_id
                ?? $providersByTaxId->get($coverage->provider_tax_id)?->id;
            if ($providerId === null) {
                continue;
            }

            $score = $this->score($commune, $coverage->commune_name);
            if ($score > ($scores[$providerId] ?? 0)) {
                $scores[$providerId] = $score;
                $matches[$providerId] = $coverage->commune_name;
            }
        }

        return $this->best($scores, $matches);
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @return array{id: ?int, score: int, uncertain: bool, matched: ?string}
     */
    public function client(?string $name, Collection $clients): array
    {
        $scores = [];
        $matches = [];

        foreach ($clients as $client) {
            foreach (['source_merchant_name', 'commercial_name', 'legal_name'] as $field) {
                $score = $this->score((string) $name, (string) $client->{$field});
                if ($score > ($scores[$client->id] ?? 0)) {
                    $scores[$client->id] = $score;
                    $matches[$client->id] = $client->{$field};
                }
            }
        }

        return $this->best($scores, $matches);
    }

    /**
     * @param  array<int, int>  $scores
     * @param  array<int, string>  $matches
     * @return array{id: ?int, score: int, uncertain: bool, matched: ?string}
     */
    private function best(array $scores, array $matches): array
    {
        arsort($scores);
        $ids = array_keys($scores);
        $id = $ids[0] ?? null;
        $score = $id === null ? 0 : $scores[$id];
        if ($score < 55) {
            return ['id' => null, 'score' => 0, 'uncertain' => true, 'matched' => null];
        }

        $runnerUp = isset($ids[1]) ? $scores[$ids[1]] : 0;

        return [
            'id' => $id,
            'score' => $score,
            'uncertain' => $score < 85 || $runnerUp >= $score - 10,
            'matched' => $matches[$id],
        ];
    }

    private function score(string $input, string $candidate): int
    {
        $left = $this->normalize($input);
        $right = $this->normalize($candidate);
        if ($left === '' || $right === '') {
            return 0;
        }
        if ($left === $right) {
            return 100;
        }
        if (strlen($left) >= 4 && (str_contains($right, $left) || str_contains($left, $right))) {
            return 85;
        }

        $words = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $candidate) ?? $candidate;
        $initials = implode('', array_map(fn (string $word): string => substr($word, 0, 1), explode(' ', $this->normalize($words))));
        if (strlen($left) >= 2 && $left === $initials) {
            return 75;
        }

        return max(0, (int) round(100 * (1 - levenshtein($left, $right) / max(strlen($left), strlen($right)))));
    }

    private function normalize(string $value): string
    {
        $normalized = Str::of($value)->ascii()->lower()->toString();
        $normalized = preg_replace('/\bpto\b/', 'puerto', $normalized) ?? $normalized;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '');
    }
}
