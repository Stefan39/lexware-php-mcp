<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tool;

use Jacomeit\LexwarePhpMcp\Exception\LexwareApiException;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetail;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetailCache;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherFields;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Net revenue per currency for a voucher date range: invoices minus credit notes.
 *
 * The voucher list only carries gross amounts, so every voucher is read once through its detail endpoint.
 *
 * @phpstan-type RevenueTotal array{currency: string, invoiceCount: int, creditNoteCount: int, invoicesNet: float, creditNotesNet: float, netRevenue: float, taxAmount: float, grossRevenue: float}
 * @phpstan-type RevenuePeriod array{period: string, totals: list<RevenueTotal>}
 * @phpstan-type RevenueResult array{dateFrom: string, dateTo: string, complete: bool, coveredUntil: string, note: string, totals: list<RevenueTotal>, periods?: list<RevenuePeriod>}
 * @phpstan-type Sum array{invoiceCount: int, creditNoteCount: int, invoices: array{float, float, float}, creditNotes: array{float, float, float}}
 * @phpstan-type ListedVoucher array{id: string, type: string, path: string, updatedDate: string, isCreditNote: bool}
 * @phpstan-import-type Detail from VoucherDetail
 */
final readonly class RevenueTool
{
    public const string NAME = 'revenue';

    public const string DESCRIPTION = 'Net revenue (invoices minus credit notes) of a voucher date range from Lexware Office, '
        . 'per currency, with tax and gross. Reads one voucher detail per voucher (cached); at most 100 detail requests per call, larger ranges return complete=false and coveredUntil for a follow-up call. '
        . 'groupBy=month adds totals per calendar month.';

    private const string NOTE = 'Amounts are net by voucher date. Drafts, voided vouchers and down-payment invoices are not counted. '
        . 'Currencies are not converted. When complete is false, not all vouchers were read: call again with dateFrom set to the day after coveredUntil.';

    private const int MAX_DAYS = 366;

    private const array INVOICE_TYPES = ['invoice', 'salesinvoice'];

    private const array CREDIT_NOTE_TYPES = ['creditnote', 'salescreditnote'];

    public function __construct(
        private VoucherList $vouchers,
        private VoucherDetail $details,
        private VoucherDetailCache $cache,
        private int $maxPages = 10,
        private int $maxDetailRequests = 100,
    ) {}

    /**
     * @param string $dateFrom Earliest voucher date, YYYY-MM-DD
     * @param string $dateTo   Latest voucher date, YYYY-MM-DD
     *
     * @return RevenueResult
     */
    public function __invoke(
        #[Schema(description: 'First voucher date (inclusive) as YYYY-MM-DD.')]
        string $dateFrom,
        #[Schema(description: 'Last voucher date (inclusive) as YYYY-MM-DD, at most 366 days after dateFrom.')]
        string $dateTo,
        #[Schema(description: 'Grouping of the result: none (default) or month, which adds totals per calendar month.', enum: ['none', 'month'])]
        string $groupBy = 'none',
    ): array {
        if (!\in_array($groupBy, ['none', 'month'], true)) {
            throw new ToolCallException('groupBy must be "none" or "month".');
        }

        $from = DateParameter::parse($dateFrom, 'dateFrom');
        $to = DateParameter::parse($dateTo, 'dateTo');
        if ($from > $to) {
            throw new ToolCallException('dateFrom must not be after dateTo.');
        }
        if ($from->diff($to)->days + 1 > self::MAX_DAYS) {
            throw new ToolCallException(\sprintf('The date range must not be longer than %d days.', self::MAX_DAYS));
        }

        try {
            return $this->calculate($dateFrom, $dateTo, 'month' === $groupBy);
        } catch (LexwareApiException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return RevenueResult
     *
     * @throws LexwareApiException
     */
    private function calculate(string $dateFrom, string $dateTo, bool $byMonth): array
    {
        [$list, $listComplete] = $this->vouchers->fetch([
            'voucherType' => implode(',', [...self::INVOICE_TYPES, ...self::CREDIT_NOTE_TYPES]),
            'voucherStatus' => 'open,paid,paidoff,transferred,sepadebit',
            'voucherDateFrom' => $dateFrom,
            'voucherDateTo' => $dateTo,
            'sort' => 'voucherDate,ASC',
        ], $this->maxPages);

        $days = $this->groupByDay($list, $dateFrom);
        if (!$listComplete) {
            // The last day on the pages read may continue on the next page, so it is left for the follow-up call.
            $partialDay = array_key_last($days);
            unset($days[$partialDay]);
            if ([] === $days) {
                throw new ToolCallException(\sprintf('More vouchers on %s than fit into the voucher list pages read per call.', $partialDay ?? $dateFrom));
            }
        }

        $complete = $listComplete;
        $coveredUntil = $listComplete ? $dateTo : array_key_last($days);
        $budget = $this->maxDetailRequests;
        $lastDay = null;
        $sums = [];
        $periodSums = [];
        foreach ($days as $day => $vouchers) {
            // Cache hits are held here, because storing the missing details could evict them from the cache.
            $cached = array_map(fn(array $voucher): ?array => $this->cache->get($voucher['id'], $voucher['updatedDate']), $vouchers);
            $uncached = \count(array_filter($cached, static fn(?array $detail): bool => null === $detail));
            if ($uncached > $budget) {
                if (null === $lastDay) {
                    throw new ToolCallException(\sprintf('%d vouchers on %s; at most %d per call.', \count($vouchers), $day, $this->maxDetailRequests));
                }
                $complete = false;
                $coveredUntil = $lastDay;
                break;
            }
            $budget -= $uncached;
            $lastDay = $day;

            foreach ($vouchers as $index => $voucher) {
                $detail = $cached[$index] ?? $this->fetchDetail($voucher);
                $sums = self::add($sums, $voucher['isCreditNote'], $detail);
                if ($byMonth) {
                    $month = substr($day, 0, 7);
                    $periodSums[$month] = self::add($periodSums[$month] ?? [], $voucher['isCreditNote'], $detail);
                }
            }
        }

        $result = [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'complete' => $complete,
            'coveredUntil' => $coveredUntil,
            'note' => self::NOTE,
            'totals' => self::totals($sums),
        ];
        if ($byMonth) {
            $result['periods'] = [];
            ksort($periodSums, SORT_STRING);
            foreach ($periodSums as $period => $periodSum) {
                $result['periods'][] = ['period' => $period, 'totals' => self::totals($periodSum)];
            }
        }

        return $result;
    }

    /**
     * Counted vouchers by voucher day, oldest first (voucher date, then voucher number).
     *
     * @param list<array<mixed>> $list
     *
     * @return array<string, list<ListedVoucher>>
     */
    private function groupByDay(array $list, string $fallbackDay): array
    {
        $sortable = [];
        foreach ($list as $voucher) {
            $type = VoucherFields::string($voucher, 'voucherType');
            $id = VoucherFields::string($voucher, 'id');
            $path = VoucherDetail::endpoint($type, $id);
            $isCreditNote = \in_array($type, self::CREDIT_NOTE_TYPES, true);
            if (null === $path || (!$isCreditNote && !\in_array($type, self::INVOICE_TYPES, true))) {
                continue;
            }
            $sortable[] = [
                VoucherFields::date($voucher, 'voucherDate')?->format('Y-m-d') ?? $fallbackDay,
                VoucherFields::string($voucher, 'voucherNumber'),
                ['id' => $id, 'type' => $type, 'path' => $path, 'updatedDate' => VoucherFields::string($voucher, 'updatedDate'), 'isCreditNote' => $isCreditNote],
            ];
        }
        usort($sortable, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $days = [];
        foreach ($sortable as [$day, , $voucher]) {
            $days[$day][] = $voucher;
        }

        return $days;
    }

    /**
     * @param ListedVoucher $voucher
     *
     * @return Detail
     *
     * @throws LexwareApiException
     */
    private function fetchDetail(array $voucher): array
    {
        $detail = $this->details->fetch($voucher['type'], $voucher['id'], $voucher['path'], roundTotals: false);
        $this->cache->put($voucher['id'], $voucher['updatedDate'], $detail);

        return $detail;
    }

    /**
     * @param array<string, Sum> $sums
     * @param Detail             $detail
     *
     * @return array<string, Sum>
     */
    private static function add(array $sums, bool $isCreditNote, array $detail): array
    {
        $sum = $sums[$detail['currency']] ?? ['invoiceCount' => 0, 'creditNoteCount' => 0, 'invoices' => [0.0, 0.0, 0.0], 'creditNotes' => [0.0, 0.0, 0.0]];
        $key = $isCreditNote ? 'creditNotes' : 'invoices';
        $sum[$isCreditNote ? 'creditNoteCount' : 'invoiceCount']++;
        $sum[$key] = [
            $sum[$key][0] + $detail['totalNetAmount'],
            $sum[$key][1] + $detail['totalTaxAmount'],
            $sum[$key][2] + $detail['totalGrossAmount'],
        ];
        $sums[$detail['currency']] = $sum;

        return $sums;
    }

    /**
     * @param array<string, Sum> $sums
     *
     * @return list<RevenueTotal>
     */
    private static function totals(array $sums): array
    {
        ksort($sums, SORT_STRING);

        $totals = [];
        foreach ($sums as $currency => $sum) {
            $totals[] = [
                'currency' => $currency,
                'invoiceCount' => $sum['invoiceCount'],
                'creditNoteCount' => $sum['creditNoteCount'],
                'invoicesNet' => round($sum['invoices'][0], 2),
                'creditNotesNet' => round($sum['creditNotes'][0], 2),
                'netRevenue' => round($sum['invoices'][0] - $sum['creditNotes'][0], 2),
                'taxAmount' => round($sum['invoices'][1] - $sum['creditNotes'][1], 2),
                'grossRevenue' => round($sum['invoices'][2] - $sum['creditNotes'][2], 2),
            ];
        }

        return $totals;
    }
}
