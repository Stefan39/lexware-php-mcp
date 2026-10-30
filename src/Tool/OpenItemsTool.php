<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tool;

use Jacomeit\LexwarePhpMcp\Exception\LexwareApiException;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherFields;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Psr\Clock\ClockInterface;

/**
 * Lists unpaid sales invoices (open and overdue) from the Lexware Office voucher list.
 *
 * Covers both invoice types: "invoice" (written in Lexware) and "salesinvoice" (booked
 * bookkeeping voucher, e.g. an uploaded PDF). Only status "open" is requested: Lexware rejects
 * "overdue" combined with other statuses (HTTP 400) and returns overdue invoices as "overdue"
 * within "open" anyway.
 *
 * @phpstan-type OpenItem array{id: string, voucherType: string, voucherNumber: string, contactName: string, voucherDate: ?string, dueDate: ?string, totalAmount: float, openAmount: float, currency: string, status: string, overdue: bool, daysOverdue: int}
 * @phpstan-type OpenItemsResult array{asOf: string, count: int, overdueCount: int, totalsByCurrency: array<string, array{open: float, overdue: float}>, complete: bool, note: string, items: list<OpenItem>}
 */
final readonly class OpenItemsTool
{
    public const string NAME = 'open_items';

    public const string DESCRIPTION = 'Lists unpaid sales invoices (open and overdue receivables) from Lexware Office, '
        . 'oldest due date first, with totals per currency. Amounts are gross; net amounts need the voucher detail.';

    private const string NOTE = 'Amounts are gross (incl. VAT) as delivered by the Lexware voucher list. '
        . 'Includes invoices written in Lexware and booked sales vouchers; down payment invoices, credit notes '
        . 'and invoices collected by SEPA direct debit are not included.';

    public function __construct(
        private VoucherList $vouchers,
        private ClockInterface $clock,
        private int $maxPages = 10,
    ) {}

    /**
     * @param bool    $overdueOnly Only return invoices whose due date has passed
     * @param ?string $contact     Case-insensitive part of the customer name to filter by
     *
     * @return OpenItemsResult
     */
    public function __invoke(
        #[Schema(description: 'Only return invoices whose due date has passed.')]
        bool $overdueOnly = false,
        #[Schema(description: 'Case-insensitive part of the customer name to filter by.')]
        ?string $contact = null,
    ): array {
        try {
            [$vouchers, $complete] = $this->vouchers->fetch([
                'voucherType' => 'invoice,salesinvoice',
                'voucherStatus' => 'open',
            ], $this->maxPages);
        } catch (LexwareApiException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }

        $today = self::toDate($this->clock->now()->format('Y-m-d'));
        $items = array_map(fn(array $voucher): array => $this->toItem($voucher, $today), $vouchers);
        $items = array_values(array_filter(
            $items,
            static fn(array $item): bool => (!$overdueOnly || $item['overdue']) && self::matchesContact($item, $contact),
        ));
        usort($items, static fn(array $a, array $b): int => [$a['dueDate'] ?? '9999', $a['voucherNumber']] <=> [$b['dueDate'] ?? '9999', $b['voucherNumber']]);

        return [
            'asOf' => $today->format('Y-m-d'),
            'count' => \count($items),
            'overdueCount' => \count(array_filter($items, static fn(array $item): bool => $item['overdue'])),
            'totalsByCurrency' => self::totals($items),
            'complete' => $complete,
            'note' => self::NOTE,
            'items' => $items,
        ];
    }

    /**
     * @param array<mixed> $voucher
     *
     * @return OpenItem
     */
    private function toItem(array $voucher, \DateTimeImmutable $today): array
    {
        $status = VoucherFields::string($voucher, 'voucherStatus');
        $dueDate = VoucherFields::date($voucher, 'dueDate');
        $daysOverdue = null === $dueDate ? 0 : max(0, (int) $dueDate->diff($today)->format('%r%a'));

        return [
            'id' => VoucherFields::string($voucher, 'id'),
            'voucherType' => VoucherFields::string($voucher, 'voucherType'),
            'voucherNumber' => VoucherFields::string($voucher, 'voucherNumber'),
            'contactName' => VoucherFields::string($voucher, 'contactName'),
            'voucherDate' => VoucherFields::date($voucher, 'voucherDate')?->format('Y-m-d'),
            'dueDate' => $dueDate?->format('Y-m-d'),
            'totalAmount' => VoucherFields::amount($voucher, 'totalAmount'),
            'openAmount' => VoucherFields::amount($voucher, 'openAmount'),
            'currency' => VoucherFields::string($voucher, 'currency', 'EUR'),
            'status' => $status,
            'overdue' => 'overdue' === $status || $daysOverdue > 0,
            'daysOverdue' => $daysOverdue,
        ];
    }

    /**
     * @param OpenItem $item
     */
    private static function matchesContact(array $item, ?string $contact): bool
    {
        $contact = trim($contact ?? '');

        return '' === $contact || false !== mb_stripos($item['contactName'], $contact);
    }

    /**
     * Sums in cents to avoid float drift across many invoices.
     *
     * @param list<OpenItem> $items
     *
     * @return array<string, array{open: float, overdue: float}>
     */
    private static function totals(array $items): array
    {
        $cents = [];
        foreach ($items as $item) {
            $cents[$item['currency']] ??= ['open' => 0, 'overdue' => 0];
            $amount = (int) round($item['openAmount'] * 100);
            $cents[$item['currency']]['open'] += $amount;
            $cents[$item['currency']]['overdue'] += $item['overdue'] ? $amount : 0;
        }

        return array_map(static fn(array $sum): array => ['open' => $sum['open'] / 100.0, 'overdue' => $sum['overdue'] / 100.0], $cents);
    }

    private static function toDate(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
