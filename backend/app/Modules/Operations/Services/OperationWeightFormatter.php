<?php

namespace App\Modules\Operations\Services;

class OperationWeightFormatter
{
    public static function canonical(int|float|string|null $weight): string
    {
        return rtrim(rtrim(number_format((float) $weight, 3, '.', ''), '0'), '.');
    }

    public static function display(int|float|string|null $weight): string
    {
        return str_replace('.', ',', self::canonical($weight));
    }

    public static function description(string $description, int|float|string|null $weight): string
    {
        return self::withWeight($description, $weight, self::display($weight));
    }

    public static function withWeight(string $description, int|float|string|null $weight, string $replacement): string
    {
        $storedWeight = (string) $weight;
        if ($storedWeight === '') {
            return $description;
        }

        $variants = array_unique([$storedWeight, str_replace('.', ',', $storedWeight), self::display($weight)]);
        usort($variants, fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return preg_replace(
            '/(?<![0-9])(?:'.implode('|', array_map(fn (string $value): string => preg_quote($value, '/'), $variants)).')(?=\s*kg\b)/iu',
            $replacement,
            $description,
            1
        ) ?? $description;
    }
}
