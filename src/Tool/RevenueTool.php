<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tool;

use Jacomeit\LexwarePhpMcp\Exception\LexwareApiException;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetail;
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
 * @phpstan-type RevenueResult array{dateFrom: string, dateTo: string, complete: bool, note: string, totals: list<RevenueTotal>}
 */
final readonly class RevenueTool
{
    public const string NAME = 'revenue';

    public const string DESCRIPTION = 'Net revenue (invoices minus credit notes) of a voucher date range from Lexware Office, '
        . 'per currency, with tax and gross. Reads one voucher detail per voucher; at most 100 vouchers per call.';

    private const string NOTE = 'Amounts are net by voucher date. Drafts, voided vouchers and down-payment invoices are not counted. '
        . 'Currencies are not converted. When complete is false, not all vouchers were read.';

    private const int MAX_DAYS = 366;

    private const array INVOICE_TYPES = ['invoice', 'salesinvoice'];

    private const array CREDIT_NOTE_TYPES = ['creditnote', 'salescreditnote'];

    public function __construct(
        private VoucherList $vouchers,
        private VoucherDetail $details,
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
    ): array {
        $from = DateParameter::parse($dateFrom, 'dateFrom');
        $to = DateParameter::parse($dateTo, 'dateTo');
        if ($from > $to) {
            throw new ToolCallException('dateFrom must not be after dateTo.');
        }
        if ($from->diff($to)->days + 1 > self::MAX_DAYS) {
            throw new ToolCallException(\sprintf('The date range must not be longer than %d days.', self::MAX_DAYS));
        }

        try {
            return $this->calculate($dateFrom, $dateTo);
        } catch (LexwareApiException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return RevenueResult
     *
     * @throws LexwareApiException
     */
    private function calculate(string $dateFrom, string $dateTo): array
    {
        [$list, $complete] = $this->vouchers->fetch([
            'voucherType' => implode(',', [...self::INVOICE_TYPES, ...self::CREDIT_NOTE_TYPES]),
            'voucherStatus' => 'open,paid,paidoff,transferred,sepadebit',
            'voucherDateFrom' => $dateFrom,
            'voucherDateTo' => $dateTo,
        ], $this->maxPages);

        if (\count($list) > $this->maxDetailRequests) {
            throw new ToolCallException(\sprintf('%d vouchers in this range; at most %d per call. Use a shorter date range.', \count($list), $this->maxDetailRequests));
        }

        /** @var array<string, array{invoiceCount: int, creditNoteCount: int, invoices: array{float, float, float}, creditNotes: array{float, float, float}}> $sums */
        $sums = [];
        foreach ($list as $voucher) {
            $type = VoucherFields::string($voucher, 'voucherType');
            $id = VoucherFields::string($voucher, 'id');
            $path = VoucherDetail::endpoint($type, $id);
            $isCreditNote = \in_array($type, self::CREDIT_NOTE_TYPES, true);
            if (null === $path || (!$isCreditNote && !\in_array($type, self::INVOICE_TYPES, true))) {
                continue;
            }

            $detail = $this->details->fetch($type, $id, $path, roundTotals: false);
            $sum = $sums[$detail['currency']] ?? ['invoiceCount' => 0, 'creditNoteCount' => 0, 'invoices' => [0.0, 0.0, 0.0], 'creditNotes' => [0.0, 0.0, 0.0]];
            $key = $isCreditNote ? 'creditNotes' : 'invoices';
            $sum[$isCreditNote ? 'creditNoteCount' : 'invoiceCount']++;
            $sum[$key] = [
                $sum[$key][0] + $detail['totalNetAmount'],
                $sum[$key][1] + $detail['totalTaxAmount'],
                $sum[$key][2] + $detail['totalGrossAmount'],
            ];
            $sums[$detail['currency']] = $sum;
        }
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

        return ['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'complete' => $complete, 'note' => self::NOTE, 'totals' => $totals];
    }
}
