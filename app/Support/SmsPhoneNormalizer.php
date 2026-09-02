<?php

namespace App\Support;

final class SmsPhoneNormalizer
{
    public static function normalizeBrazil(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            return $digits;
        }

        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return '55'.$digits;
        }

        if (strlen($digits) >= 12 && strlen($digits) <= 13) {
            return $digits;
        }

        return null;
    }
}
