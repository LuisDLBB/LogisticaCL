<?php

namespace App\Fleet;

class RetCode
{
    public static function format(int $number): string
    {
        return '4N-RET-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT).'-'.self::verifier($number);
    }

    public static function verifier(int $number): string
    {
        $sum = 0;
        $weight = 2;
        foreach (str_split(strrev((string) $number)) as $digit) {
            $sum += (int) $digit * $weight;
            $weight = $weight === 7 ? 2 : $weight + 1;
        }
        $result = 11 - ($sum % 11);

        return match ($result) {
            11 => '0', 10 => 'K', default => (string) $result,
        };
    }
}
