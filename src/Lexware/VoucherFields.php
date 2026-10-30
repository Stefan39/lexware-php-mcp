<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Lexware;

/**
 * Typed access to the fields of a voucher list entry.
 */
final class VoucherFields
{
    /**
     * @param array<mixed> $voucher
     */
    public static function string(array $voucher, string $key, string $default = ''): string
    {
        return \is_string($voucher[$key] ?? null) ? $voucher[$key] : $default;
    }

    /**
     * @param array<mixed> $voucher
     */
    public static function amount(array $voucher, string $key): float
    {
        return \is_int($voucher[$key] ?? null) || \is_float($voucher[$key] ?? null) ? round((float) $voucher[$key], 2) : 0.0;
    }

    /**
     * Lexware sends local timestamps; only the calendar date is meaningful for invoices.
     *
     * @param array<mixed> $voucher
     */
    public static function date(array $voucher, string $key): ?\DateTimeImmutable
    {
        $value = self::string($voucher, $key);
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $match)) {
            return null;
        }

        return new \DateTimeImmutable($match[0], new \DateTimeZone('UTC'));
    }
}
