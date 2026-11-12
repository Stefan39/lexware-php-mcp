<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Lexware;

use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;

/**
 * Reads one voucher from its type-specific detail endpoint and normalises it to one shape.
 *
 * @phpstan-type TaxAmount array{taxRatePercentage: float, netAmount: float, taxAmount: float}
 * @phpstan-type LineItem array{name: ?string, quantity: ?float, unitName: ?string, netAmount: ?float, grossAmount: ?float, taxRatePercentage: ?float}
 * @phpstan-type Detail array{id: string, voucherType: string, voucherNumber: string, voucherDate: ?string, contactName?: string, currency: string, totalNetAmount: float, totalTaxAmount: float, totalGrossAmount: float, taxAmounts: list<TaxAmount>, lineItems: list<LineItem>}
 */
final readonly class VoucherDetail
{
    /** Bookkeeping vouchers (booked in Lexware, not written there) share one endpoint. */
    private const string BOOKKEEPING_PATH = '/v1/vouchers/';

    /** The one place that maps a voucherType to its detail endpoint. */
    private const array PATHS = [
        'invoice' => '/v1/invoices/',
        'creditnote' => '/v1/credit-notes/',
        'downpaymentinvoice' => '/v1/down-payment-invoices/',
        'salesinvoice' => self::BOOKKEEPING_PATH,
        'salescreditnote' => self::BOOKKEEPING_PATH,
        'purchaseinvoice' => self::BOOKKEEPING_PATH,
        'purchasecreditnote' => self::BOOKKEEPING_PATH,
    ];

    public function __construct(
        private LexwareClient $client,
        private SensitiveDataFilter $filter,
    ) {}

    /**
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::PATHS);
    }

    public static function endpoint(string $voucherType, string $id): ?string
    {
        return isset(self::PATHS[$voucherType]) ? self::PATHS[$voucherType] . $id : null;
    }

    /**
     * @return Detail
     */
    public function fetch(string $voucherType, string $id, string $path): array
    {
        $voucher = $this->filter->filter($this->client->getJson($path));

        return str_starts_with($path, self::BOOKKEEPING_PATH)
            ? self::fromBookkeepingVoucher($voucher, $voucherType, $id)
            : self::fromSalesVoucher($voucher, $voucherType, $id);
    }

    /**
     * Invoices, credit notes and down-payment invoices written in Lexware.
     *
     * @param array<mixed> $voucher
     *
     * @return Detail
     */
    private static function fromSalesVoucher(array $voucher, string $voucherType, string $id): array
    {
        $total = self::map($voucher, 'totalPrice');
        $gross = 'gross' === VoucherFields::string(self::map($voucher, 'taxConditions'), 'taxType');

        $lineItems = [];
        foreach (self::maps($voucher, 'lineItems') as $item) {
            $rate = self::optionalNumber(self::map($item, 'unitPrice'), 'taxRatePercentage');
            $amount = self::optionalNumber($item, 'lineItemAmount');
            $lineItems[] = [
                'name' => self::optionalString($item, 'name'),
                'quantity' => self::optionalNumber($item, 'quantity', 4),
                'unitName' => self::optionalString($item, 'unitName'),
                ...self::netAndGross($amount, $rate, $gross),
                'taxRatePercentage' => $rate,
            ];
        }

        $taxAmounts = array_map(static fn(array $tax): array => [
            'taxRatePercentage' => VoucherFields::amount($tax, 'taxRatePercentage'),
            'netAmount' => VoucherFields::amount($tax, 'netAmount'),
            'taxAmount' => VoucherFields::amount($tax, 'taxAmount'),
        ], self::maps($voucher, 'taxAmounts'));

        return self::result($voucher, $voucherType, $id, VoucherFields::string(self::map($voucher, 'address'), 'name'), [
            'currency' => VoucherFields::string($total, 'currency', 'EUR'),
            'totalNetAmount' => VoucherFields::amount($total, 'totalNetAmount'),
            'totalTaxAmount' => VoucherFields::amount($total, 'totalTaxAmount'),
            'totalGrossAmount' => VoucherFields::amount($total, 'totalGrossAmount'),
            'taxAmounts' => $taxAmounts,
            'lineItems' => $lineItems,
        ]);
    }

    /**
     * Bookkeeping vouchers carry voucher items with amount and tax rate, but no names or quantities.
     *
     * @param array<mixed> $voucher
     *
     * @return Detail
     */
    private static function fromBookkeepingVoucher(array $voucher, string $voucherType, string $id): array
    {
        $gross = 'gross' === VoucherFields::string($voucher, 'taxType');

        $lineItems = [];
        $byRate = [];
        foreach (self::maps($voucher, 'voucherItems') as $item) {
            $rate = self::optionalNumber($item, 'taxRatePercentage');
            $amount = self::optionalNumber($item, 'amount');
            $amounts = self::netAndGross($amount, $rate, $gross);
            $lineItems[] = ['name' => null, 'quantity' => null, 'unitName' => null, ...$amounts, 'taxRatePercentage' => $rate];

            $key = (string) ($rate ?? 0.0);
            $entry = $byRate[$key] ?? ['taxRatePercentage' => $rate ?? 0.0, 'netAmount' => 0.0, 'taxAmount' => 0.0];
            $entry['netAmount'] = round($entry['netAmount'] + ($amounts['netAmount'] ?? 0.0), 2);
            $entry['taxAmount'] = round($entry['taxAmount'] + (self::optionalNumber($item, 'taxAmount') ?? (($amounts['grossAmount'] ?? 0.0) - ($amounts['netAmount'] ?? 0.0))), 2);
            $byRate[$key] = $entry;
        }

        $totalGross = VoucherFields::amount($voucher, 'totalGrossAmount');
        $totalTax = VoucherFields::amount($voucher, 'totalTaxAmount');

        return self::result($voucher, $voucherType, $id, VoucherFields::string($voucher, 'contactName'), [
            'currency' => VoucherFields::string($voucher, 'currency', 'EUR'),
            'totalNetAmount' => round($totalGross - $totalTax, 2),
            'totalTaxAmount' => $totalTax,
            'totalGrossAmount' => $totalGross,
            'taxAmounts' => array_values($byRate),
            'lineItems' => $lineItems,
        ]);
    }

    /**
     * @param array<mixed>                                                                                                                                                         $voucher
     * @param array{currency: string, totalNetAmount: float, totalTaxAmount: float, totalGrossAmount: float, taxAmounts: list<TaxAmount>, lineItems: list<LineItem>} $figures
     *
     * @return Detail
     */
    private static function result(array $voucher, string $voucherType, string $id, string $contactName, array $figures): array
    {
        return [
            'id' => VoucherFields::string($voucher, 'id', $id),
            'voucherType' => $voucherType,
            'voucherNumber' => VoucherFields::string($voucher, 'voucherNumber'),
            'voucherDate' => VoucherFields::date($voucher, 'voucherDate')?->format('Y-m-d'),
            ...('' === $contactName ? [] : ['contactName' => $contactName]),
            ...$figures,
        ];
    }

    /**
     * Lexware states line amounts net or gross depending on the voucher's tax type; derive the other side from the rate.
     *
     * @return array{netAmount: ?float, grossAmount: ?float}
     */
    private static function netAndGross(?float $amount, ?float $rate, bool $isGross): array
    {
        if (null === $amount) {
            return ['netAmount' => null, 'grossAmount' => null];
        }
        if (null === $rate) {
            return $isGross ? ['netAmount' => null, 'grossAmount' => $amount] : ['netAmount' => $amount, 'grossAmount' => null];
        }

        $factor = 1 + $rate / 100;

        return $isGross
            ? ['netAmount' => round($amount / $factor, 2), 'grossAmount' => $amount]
            : ['netAmount' => $amount, 'grossAmount' => round($amount * $factor, 2)];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function map(array $data, string $key): array
    {
        return \is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    private static function maps(array $data, string $key): array
    {
        return array_values(array_filter(self::map($data, $key), \is_array(...)));
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : null;
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalNumber(array $data, string $key, int $precision = 2): ?float
    {
        $value = $data[$key] ?? null;

        return \is_int($value) || \is_float($value) ? round((float) $value, $precision) : null;
    }
}
