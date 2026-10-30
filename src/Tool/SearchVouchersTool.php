<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tool;

use Jacomeit\LexwarePhpMcp\Exception\LexwareApiException;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherFields;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Searches the Lexware Office voucher list by type, status, date range, number and contact.
 *
 * @phpstan-type VoucherItem array{id: string, voucherType: string, voucherNumber: string, contactName: string, voucherDate: ?string, dueDate: ?string, totalAmount: float, openAmount: float, currency: string, status: string}
 * @phpstan-type SearchVouchersResult array{count: int, complete: bool, truncated: bool, note: string, items: list<VoucherItem>}
 */
final readonly class SearchVouchersTool
{
    public const string NAME = 'search_vouchers';

    public const string DESCRIPTION = 'Searches Lexware Office vouchers (invoices, credit notes, quotations, ...) by type, status, '
        . 'date range, voucher number and contact name, newest first. Amounts are gross; net amounts need the voucher detail.';

    private const string NOTE = 'Amounts are gross (incl. VAT) as delivered by the Lexware voucher list; net amounts need the voucher detail.';

    private const int MAX_LIMIT = 250;

    public function __construct(
        private VoucherList $vouchers,
        private int $maxPages = 10,
    ) {}

    /**
     * @param string  $voucherType   Comma-separated Lexware voucher types or "any"
     * @param string  $voucherStatus Comma-separated Lexware voucher statuses or "any"
     * @param ?string $dateFrom      Earliest voucher date, YYYY-MM-DD
     * @param ?string $dateTo        Latest voucher date, YYYY-MM-DD
     * @param ?string $voucherNumber Voucher number as shown in Lexware
     * @param ?string $contact       Case-insensitive part of the contact name to filter by
     * @param int     $limit         Number of vouchers returned (1 to 250)
     *
     * @return SearchVouchersResult
     */
    public function __invoke(
        #[Schema(description: 'Comma-separated Lexware voucher types, e.g. "invoice,creditnote", or "any".')]
        string $voucherType = 'any',
        #[Schema(description: 'Comma-separated Lexware voucher statuses, e.g. "open,paid", or "any". "overdue" cannot be combined with other statuses.')]
        string $voucherStatus = 'any',
        #[Schema(description: 'Earliest voucher date as YYYY-MM-DD.')]
        ?string $dateFrom = null,
        #[Schema(description: 'Latest voucher date as YYYY-MM-DD.')]
        ?string $dateTo = null,
        #[Schema(description: 'Voucher number as shown in Lexware.')]
        ?string $voucherNumber = null,
        #[Schema(description: 'Case-insensitive part of the contact name to filter by.')]
        ?string $contact = null,
        #[Schema(description: 'Number of vouchers returned, 1 to 250.')]
        int $limit = 50,
    ): array {
        $statuses = array_map(trim(...), explode(',', $voucherStatus));
        if (\in_array('overdue', $statuses, true) && \count($statuses) > 1) {
            throw new ToolCallException('Lexware only accepts voucherStatus "overdue" on its own (HTTP 400 otherwise). Query "overdue" separately or use "open" and check the due date.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new ToolCallException(\sprintf('limit must be between 1 and %d, got %d.', self::MAX_LIMIT, $limit));
        }

        $query = array_filter([
            'voucherType' => $this->value($voucherType, 'any'),
            'voucherStatus' => $this->value($voucherStatus, 'any'),
            'voucherDateFrom' => self::validDate($dateFrom, 'dateFrom'),
            'voucherDateTo' => self::validDate($dateTo, 'dateTo'),
            'voucherNumber' => self::blankToNull($voucherNumber),
        ], static fn(?string $value): bool => null !== $value);

        try {
            [$vouchers, $complete] = $this->vouchers->fetch($query, $this->maxPages);
        } catch (LexwareApiException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }

        $items = array_values(array_filter(
            array_map(self::toItem(...), $vouchers),
            static fn(array $item): bool => self::matchesContact($item, $contact),
        ));
        usort($items, static fn(array $a, array $b): int => [$b['voucherDate'] ?? '', $a['voucherNumber']] <=> [$a['voucherDate'] ?? '', $b['voucherNumber']]);

        return [
            'count' => min($limit, \count($items)),
            'complete' => $complete,
            'truncated' => \count($items) > $limit,
            'note' => self::NOTE,
            'items' => \array_slice($items, 0, $limit),
        ];
    }

    private function value(string $value, string $default): string
    {
        return '' === trim($value) ? $default : trim($value);
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    private static function validDate(?string $value, string $parameter): ?string
    {
        if (null === $value) {
            return null;
        }

        $date = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new ToolCallException(\sprintf('%s must be a date in the format YYYY-MM-DD, got %s.', $parameter, json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)));
        }

        return $value;
    }

    /**
     * @param array<mixed> $voucher
     *
     * @return VoucherItem
     */
    private static function toItem(array $voucher): array
    {
        return [
            'id' => VoucherFields::string($voucher, 'id'),
            'voucherType' => VoucherFields::string($voucher, 'voucherType'),
            'voucherNumber' => VoucherFields::string($voucher, 'voucherNumber'),
            'contactName' => VoucherFields::string($voucher, 'contactName'),
            'voucherDate' => VoucherFields::date($voucher, 'voucherDate')?->format('Y-m-d'),
            'dueDate' => VoucherFields::date($voucher, 'dueDate')?->format('Y-m-d'),
            'totalAmount' => VoucherFields::amount($voucher, 'totalAmount'),
            'openAmount' => VoucherFields::amount($voucher, 'openAmount'),
            'currency' => VoucherFields::string($voucher, 'currency', 'EUR'),
            'status' => VoucherFields::string($voucher, 'voucherStatus'),
        ];
    }

    /**
     * @param VoucherItem $item
     */
    private static function matchesContact(array $item, ?string $contact): bool
    {
        $contact = trim($contact ?? '');

        return '' === $contact || false !== mb_stripos($item['contactName'], $contact);
    }
}
