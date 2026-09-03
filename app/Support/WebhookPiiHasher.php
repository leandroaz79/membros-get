<?php

namespace App\Support;

/**
 * Customer payload e hashes SHA-256 para webhooks de integração (CRM + Meta CAPI).
 */
class WebhookPiiHasher
{
    public static function includesPlainCustomerPii(): bool
    {
        return (bool) config('getfy.webhooks.include_plain_customer_pii', true);
    }

    public static function includesCustomerHashes(): bool
    {
        return (bool) config('getfy.webhooks.include_customer_hashes', false);
    }

    /**
     * Customer para webhooks de integração (CRM/Zapier): sempre em texto claro, estilo Cakto.
     *
     * @return array<string, mixed>
     */
    public static function integrationCustomerPayload(
        ?string $email,
        ?string $phone,
        ?string $document,
        ?string $name,
    ): array {
        $out = self::plainCustomerFields($email, $phone, $document, $name);

        if (! self::includesCustomerHashes()) {
            return $out;
        }

        $emailHash = self::hashEmail($email);
        if ($emailHash !== null) {
            $out['email_hash'] = $emailHash;
        }
        $phoneHash = self::hashPhone($phone);
        if ($phoneHash !== null) {
            $out['phone_hash'] = $phoneHash;
        }
        $docHash = self::hashDocument($document);
        if ($docHash !== null) {
            $out['cpf_hash'] = $docHash;
        }
        $nameHash = self::hashName($name);
        if ($nameHash !== null) {
            $out['name_hash'] = $nameHash;
        }

        return $out;
    }

    /**
     * @deprecated Use integrationCustomerPayload() para webhooks HTTP de integração.
     *
     * @return array<string, mixed>
     */
    public static function customerPayload(
        ?string $email,
        ?string $phone,
        ?string $document,
        ?string $name,
    ): array {
        return self::integrationCustomerPayload($email, $phone, $document, $name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerIdentifiers(
        ?string $email,
        ?string $phone,
        ?string $document,
        ?string $name,
    ): array {
        return self::integrationCustomerPayload($email, $phone, $document, $name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function plainCustomerFields(
        ?string $email,
        ?string $phone,
        ?string $document,
        ?string $name,
    ): array {
        $out = [];

        if (is_string($name) && trim($name) !== '') {
            $out['name'] = trim($name);
        }
        if (is_string($email) && trim($email) !== '') {
            $out['email'] = trim($email);
        }

        $phoneDigits = self::normalizePhoneDigits($phone);
        if ($phoneDigits !== null) {
            $out['phone'] = $phoneDigits;
        }

        $docDigits = preg_replace('/\D/', '', (string) $document);
        if ($docDigits !== '' && strlen($docDigits) >= 11) {
            $out['docNumber'] = $docDigits;
            $out['docType'] = strlen($docDigits) === 14 ? 'cnpj' : 'cpf';
        }

        $out['birthDate'] = null;

        return $out;
    }

    public static function normalizePhoneDigits(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '' || strlen($digits) < 10) {
            return null;
        }
        if (! str_starts_with($digits, '55')) {
            $digits = '55'.$digits;
        }

        return $digits;
    }

    public static function hashEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($email)));
    }

    public static function hashPhone(?string $phone): ?string
    {
        $digits = self::normalizePhoneDigits($phone);
        if ($digits === null) {
            return null;
        }

        return hash('sha256', $digits);
    }

    public static function hashDocument(?string $document): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $document);
        if ($digits === '' || strlen($digits) < 11) {
            return null;
        }

        return hash('sha256', $digits);
    }

    public static function hashName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? trim($name));

        return hash('sha256', $normalized);
    }
}
