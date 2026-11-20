<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests\Tool;

use Jacomeit\LexwarePhpMcp\Lexware\LexwareClient;
use Jacomeit\LexwarePhpMcp\Lexware\RequestThrottle;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetail;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetailCache;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;
use Jacomeit\LexwarePhpMcp\Tool\RevenueTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RevenueToolTest extends TestCase
{
    /** @var list<MockResponse> */
    private array $responses = [];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'german format' => ['01.09.2026', '2026-09-30'];
        yield 'impossible day' => ['2026-09-01', '2026-02-30'];
        yield 'empty' => ['', '2026-09-30'];
        yield 'from after to' => ['2026-09-30', '2026-09-01'];
        yield 'one day more than 366' => ['2026-01-01', '2027-01-02'];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidRangesBeforeAnyRequest(string $dateFrom, string $dateTo): void
    {
        $tool = $this->createTool([]);

        try {
            $tool($dateFrom, $dateTo);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException) {
            self::assertCount(0, $this->responses);
        }
    }

    public function testAcceptsRangeOfExactly366Days(): void
    {
        $result = $this->createTool([$this->emptyList()])('2026-01-01', '2027-01-01');

        self::assertSame([], $result['totals']);
        self::assertTrue($result['complete']);
    }

    public function testSendsVoucherListQuery(): void
    {
        $this->createTool([$this->emptyList()])('2026-07-01', '2026-09-30');

        self::assertSame('/v1/voucherlist', parse_url($this->responses[0]->getRequestUrl(), PHP_URL_PATH));
        self::assertSame('invoice,salesinvoice,creditnote,salescreditnote', $this->queryValue(0, 'voucherType'));
        self::assertSame('open,paid,paidoff,transferred,sepadebit', $this->queryValue(0, 'voucherStatus'));
        self::assertSame('2026-07-01', $this->queryValue(0, 'voucherDateFrom'));
        self::assertSame('2026-09-30', $this->queryValue(0, 'voucherDateTo'));
    }

    public function testSubtractsCreditNotesAndKeepsCurrenciesApart(): void
    {
        $result = $this->createTool($this->revenueResponses())('2026-09-01', '2026-09-30');

        self::assertSame('2026-09-01', $result['dateFrom']);
        self::assertSame('2026-09-30', $result['dateTo']);
        self::assertTrue($result['complete']);
        self::assertSame([
            [
                'currency' => 'CHF',
                'invoiceCount' => 1,
                'creditNoteCount' => 0,
                'invoicesNet' => 500.0,
                'creditNotesNet' => 0.0,
                'netRevenue' => 500.0,
                'taxAmount' => 0.0,
                'grossRevenue' => 500.0,
            ],
            [
                'currency' => 'EUR',
                'invoiceCount' => 2,
                'creditNoteCount' => 2,
                'invoicesNet' => 1230.0,
                'creditNotesNet' => 250.0,
                'netRevenue' => 980.0,
                'taxAmount' => 181.3,
                'grossRevenue' => 1161.3,
            ],
        ], $result['totals']);
        self::assertStringContainsString('not counted', $result['note']);
    }

    public function testReadsOneDetailPerVoucherFromItsEndpoint(): void
    {
        $this->createTool($this->revenueResponses())('2026-09-01', '2026-09-30');

        $paths = array_map(static fn(MockResponse $response): string => (string) parse_url($response->getRequestUrl(), PHP_URL_PATH), $this->responses);
        self::assertSame([
            '/v1/voucherlist',
            '/v1/invoices/e9066f04-8cc7-4616-93f8-3d24f2a43d3a',
            '/v1/vouchers/4f1e8a7c-92d3-4b05-a6c8-1d3e5f7a9b02',
            '/v1/credit-notes/c2a5d0f1-3b7e-4c1a-9d2e-6f8a1b4c7d90',
            '/v1/vouchers/7a6b5c4d-3e2f-4a1b-8c9d-0e1f2a3b4c5d',
            '/v1/invoices/5b4a3c2d-1e0f-4b9a-8d7c-6e5f4a3b2c1d',
        ], $paths);
    }

    public function testDownPaymentInvoiceIsNotCounted(): void
    {
        $list = $this->listOf([
            ['id' => '0d7b3e52-8f14-4a6c-b1d9-2e5c9a7f4b36', 'voucherType' => 'downpaymentinvoice'],
            ['id' => 'e9066f04-8cc7-4616-93f8-3d24f2a43d3a', 'voucherType' => 'invoice'],
        ]);

        $result = $this->createTool([$list, $this->fixture('voucher-invoice')])('2026-09-01', '2026-09-30');

        self::assertCount(2, $this->responses);
        self::assertSame(1040.0, $result['totals'][0]['netRevenue']);
        self::assertSame(1, $result['totals'][0]['invoiceCount']);
    }

    public function testRoundsOnlyTheFinalSums(): void
    {
        $list = $this->listOf([
            ['id' => 'e9066f04-8cc7-4616-93f8-3d24f2a43d3a', 'voucherType' => 'invoice'],
            ['id' => 'e9066f04-8cc7-4616-93f8-3d24f2a43d3b', 'voucherType' => 'invoice'],
        ]);

        $result = $this->createTool([$list, $this->smallInvoice(0.004), $this->smallInvoice(0.004)])('2026-09-01', '2026-09-30');

        self::assertSame(0.01, $result['totals'][0]['netRevenue']);
        self::assertSame(0.01, $result['totals'][0]['invoicesNet']);
    }

    public function testOutputContainsNoContactNamesOrBankData(): void
    {
        $json = (string) json_encode($this->createTool($this->revenueResponses())('2026-09-01', '2026-09-30'));

        foreach (['Beispiel AG', 'Muster Bau GmbH', 'Swiss Kunde AG', 'iban', 'DE02120300000000202051'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $json);
        }
    }

    public function testStopsAtDayBoundaryWhenCapIsReached(): void
    {
        $tool = $this->createTool($this->revenueResponses(), maxDetailRequests: 4);

        $result = $tool('2026-09-01', '2026-09-30');

        self::assertFalse($result['complete']);
        self::assertSame('2026-09-25', $result['coveredUntil']);
        self::assertSame('EUR', $result['totals'][0]['currency']);
        self::assertSame(2, $result['totals'][0]['invoiceCount']);
        self::assertSame(2, $result['totals'][0]['creditNoteCount']);
        self::assertStringContainsString('day after coveredUntil', $result['note']);
    }

    public function testCoveredUntilIsDateToWhenEverythingWasRead(): void
    {
        $result = $this->createTool($this->revenueResponses())('2026-09-01', '2026-09-30');

        self::assertSame('2026-09-30', $result['coveredUntil']);
    }

    public function testFollowUpFromDayAfterCoveredUntilContinuesWithoutGaps(): void
    {
        $days = ['2026-09-01', '2026-09-01', '2026-09-02', '2026-09-02', '2026-09-03'];
        $tool = $this->createTool([
            $this->listOf($this->vouchersOn($days)),
            ...array_map(fn(): MockResponse => $this->smallInvoice(10.0), \array_slice($days, 0, 4)),
            $this->listOf(array_slice($this->vouchersOn($days), 4)),
            $this->smallInvoice(10.0),
        ], maxDetailRequests: 4);

        $first = $tool('2026-09-01', '2026-09-30');
        $second = $tool('2026-09-03', '2026-09-30');

        self::assertFalse($first['complete']);
        self::assertSame('2026-09-02', $first['coveredUntil']);
        self::assertSame(40.0, $first['totals'][0]['netRevenue']);
        self::assertTrue($second['complete']);
        self::assertSame(10.0, $second['totals'][0]['netRevenue']);
        self::assertSame('2026-09-03', $this->queryValue(5, 'voucherDateFrom'));
    }

    public function testProcessesVouchersOldestFirstByDateThenNumber(): void
    {
        $vouchers = $this->vouchersOn(['2026-09-02', '2026-09-01', '2026-09-01']);
        $vouchers[1] = ['voucherNumber' => 'RE-B'] + $vouchers[1];
        $vouchers[2] = ['voucherNumber' => 'RE-A'] + $vouchers[2];

        $this->createTool([$this->listOf($vouchers), ...$this->smallInvoices(3)])('2026-09-01', '2026-09-30');

        $ids = array_map(static fn(MockResponse $response): string => basename((string) parse_url($response->getRequestUrl(), PHP_URL_PATH)), \array_slice($this->responses, 1));
        self::assertSame([$vouchers[2]['id'], $vouchers[1]['id'], $vouchers[0]['id']], $ids);
    }

    public function testFirstDayAboveCapIsToolError(): void
    {
        $tool = $this->createTool([$this->listOf($this->vouchersOn(['2026-09-01', '2026-09-01', '2026-09-01']))], maxDetailRequests: 2);

        try {
            $tool('2026-09-01', '2026-09-30');
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $exception) {
            self::assertSame('3 vouchers on 2026-09-01; at most 2 per call.', $exception->getMessage());
            self::assertCount(1, $this->responses);
        }
    }

    public function testCoveredUntilIsLastCompleteDayWhenPageCapIsHit(): void
    {
        $result = $this->createTool([
            $this->fixture('voucherlist-page-0'),
            $this->fixture('voucher-invoice'),
        ], maxPages: 1)('2026-08-01', '2026-09-30');

        self::assertFalse($result['complete']);
        self::assertSame('2026-08-01', $result['coveredUntil']);
        self::assertSame(1040.0, $result['totals'][0]['netRevenue']);
        self::assertStringContainsString('complete is false', $result['note']);
        self::assertSame('voucherDate,ASC', $this->queryValue(0, 'sort'));
    }

    public function testSingleDayOnPageCapIsToolError(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('More vouchers on 2026-09-01');

        $list = new MockResponse(json_encode(['content' => $this->vouchersOn(['2026-09-01']), 'first' => true, 'last' => false, 'number' => 0], \JSON_THROW_ON_ERROR));
        $this->createTool([$list], maxPages: 1)('2026-09-01', '2026-09-30');
    }

    public function testGroupsByMonth(): void
    {
        $days = ['2026-08-31', '2026-09-01', '2026-09-02'];
        $result = $this->createTool([$this->listOf($this->vouchersOn($days)), ...$this->smallInvoices(3)])('2026-08-01', '2026-09-30', 'month');

        self::assertSame(30.0, $result['totals'][0]['netRevenue']);
        self::assertSame(['2026-08', '2026-09'], array_column($result['periods'] ?? [], 'period'));
        self::assertSame(10.0, $result['periods'][0]['totals'][0]['netRevenue'] ?? null);
        self::assertSame(20.0, $result['periods'][1]['totals'][0]['netRevenue'] ?? null);
        self::assertSame(2, $result['periods'][1]['totals'][0]['invoiceCount']);
    }

    public function testNoPeriodsWithoutGrouping(): void
    {
        $result = $this->createTool([$this->emptyList()])('2026-09-01', '2026-09-30', 'none');

        self::assertArrayNotHasKey('periods', $result);
    }

    public function testRejectsInvalidGroupByBeforeAnyRequest(): void
    {
        $tool = $this->createTool([]);

        try {
            $tool('2026-09-01', '2026-09-30', 'week');
            self::fail('Expected ToolCallException');
        } catch (ToolCallException) {
            self::assertCount(0, $this->responses);
        }
    }

    public function testCacheHitSkipsSecondDetailRequest(): void
    {
        $vouchers = $this->vouchersOn(['2026-09-01']);
        $tool = $this->createTool([$this->listOf($vouchers), $this->smallInvoice(10.0), $this->listOf($vouchers)]);

        $first = $tool('2026-09-01', '2026-09-30');
        $second = $tool('2026-09-01', '2026-09-30');

        self::assertSame($first['totals'], $second['totals']);
        self::assertCount(3, $this->responses);
        self::assertSame('/v1/voucherlist', parse_url($this->responses[2]->getRequestUrl(), PHP_URL_PATH));
    }

    public function testCacheMissAfterChangedUpdatedDate(): void
    {
        $vouchers = $this->vouchersOn(['2026-09-01']);
        $changed = [['updatedDate' => '2026-09-05T10:00:00.000+02:00'] + $vouchers[0]];
        $tool = $this->createTool([$this->listOf($vouchers), $this->smallInvoice(10.0), $this->listOf($changed), $this->smallInvoice(12.0)]);

        $tool('2026-09-01', '2026-09-30');
        $result = $tool('2026-09-01', '2026-09-30');

        self::assertSame(12.0, $result['totals'][0]['netRevenue']);
    }

    public function testCacheEvictsOldestEntryAtBound(): void
    {
        $vouchers = $this->vouchersOn(['2026-09-01', '2026-09-02']);
        $tool = $this->createTool([
            $this->listOf($vouchers),
            ...$this->smallInvoices(2),
            $this->listOf([$vouchers[1]]),
            $this->listOf([$vouchers[0]]),
            $this->smallInvoice(10.0),
        ], cache: new VoucherDetailCache(1));

        $tool('2026-09-01', '2026-09-30');
        $tool('2026-09-02', '2026-09-30');
        $tool('2026-09-01', '2026-09-01');

        // The second voucher is still cached (no request); the first one was evicted and is read again.
        self::assertSame('/v1/invoices/' . $vouchers[0]['id'], parse_url($this->responses[5]->getRequestUrl(), PHP_URL_PATH));
    }

    public function testCachedVouchersDoNotCountAgainstCap(): void
    {
        $days = ['2026-09-01', '2026-09-02', '2026-09-03'];
        $vouchers = $this->vouchersOn($days);
        $tool = $this->createTool([
            $this->listOf(\array_slice($vouchers, 0, 2)),
            ...$this->smallInvoices(2),
            $this->listOf($vouchers),
            $this->smallInvoice(10.0),
        ], maxDetailRequests: 2);

        $tool('2026-09-01', '2026-09-02');
        $result = $tool('2026-09-01', '2026-09-30');

        self::assertTrue($result['complete']);
        self::assertSame(30.0, $result['totals'][0]['netRevenue']);
    }

    public function testEvictionWhileReadingADayDoesNotExceedCap(): void
    {
        $vouchers = $this->vouchersOn(['2026-09-01', '2026-09-01']);
        $tool = $this->createTool([
            $this->listOf([$vouchers[1]]),
            $this->smallInvoice(10.0),
            $this->listOf($vouchers),
            $this->smallInvoice(10.0),
        ], maxDetailRequests: 1, cache: new VoucherDetailCache(1));

        $tool('2026-09-01', '2026-09-30');
        $result = $tool('2026-09-01', '2026-09-30');

        self::assertCount(4, $this->responses);
        self::assertTrue($result['complete']);
        self::assertSame(20.0, $result['totals'][0]['netRevenue']);
    }

    public function testMonthGroupingWithoutCountedVouchersHasEmptyPeriods(): void
    {
        $result = $this->createTool([$this->emptyList()])('2026-09-01', '2026-09-30', 'month');

        self::assertSame([], $result['periods'] ?? null);
    }

    public function testListPageCapWithoutUsableVouchersIsToolError(): void
    {
        $list = new MockResponse(json_encode(['content' => [], 'first' => true, 'last' => false, 'number' => 0], \JSON_THROW_ON_ERROR));

        $this->expectException(ToolCallException::class);
        $this->createTool([$list], maxPages: 1)('2026-09-01', '2026-09-30');
    }

    public function testLexwareErrorFromListBecomesToolError(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/400.*invalid voucherDateFrom/');

        $this->createTool([$this->error(400, 'invalid voucherDateFrom')])('2026-09-01', '2026-09-30');
    }

    public function testLexwareErrorFromDetailBecomesToolErrorWithoutPartialTotals(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/404.*voucher not found/');

        $this->createTool([
            $this->fixture('voucherlist-revenue'),
            $this->fixture('voucher-invoice'),
            $this->error(404, 'voucher not found'),
        ])('2026-09-01', '2026-09-30');
    }

    /**
     * @return list<MockResponse>
     */
    private function revenueResponses(): array
    {
        return [
            $this->fixture('voucherlist-revenue'),
            $this->fixture('voucher-invoice'),
            $this->fixture('voucher-bookkeeping'),
            $this->fixture('voucher-creditnote'),
            $this->fixture('voucher-salescreditnote'),
            $this->fixture('voucher-invoice-chf'),
        ];
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function createTool(array $responses, int $maxPages = 10, int $maxDetailRequests = 100, ?VoucherDetailCache $cache = null): RevenueTool
    {
        $this->responses = $responses;
        $clock = new MockClock('2026-10-10 09:00:00', 'Europe/Berlin');
        $client = new LexwareClient(new MockHttpClient($responses, 'https://api.example.test'), new RequestThrottle($clock), $clock);
        $filter = new SensitiveDataFilter();

        return new RevenueTool(new VoucherList($client, $filter), new VoucherDetail($client, $filter), $cache ?? new VoucherDetailCache(), $maxPages, $maxDetailRequests);
    }

    private function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/../Fixtures/' . $name . '.json'));
    }

    private function emptyList(): MockResponse
    {
        return $this->listOf([]);
    }

    /**
     * @param list<array<string, string>> $vouchers
     */
    private function listOf(array $vouchers): MockResponse
    {
        return new MockResponse(json_encode(['content' => $vouchers, 'first' => true, 'last' => true, 'number' => 0], \JSON_THROW_ON_ERROR));
    }

    /**
     * Synthetic invoices, one per given voucher date, with ids in list order.
     *
     * @param list<string> $days
     *
     * @return list<array{id: string, voucherType: string, voucherNumber: string, voucherDate: string, updatedDate: string}>
     */
    private function vouchersOn(array $days): array
    {
        $vouchers = [];
        foreach ($days as $index => $day) {
            $vouchers[] = [
                'id' => \sprintf('00000000-0000-4000-8000-%012d', $index + 1),
                'voucherType' => 'invoice',
                'voucherNumber' => \sprintf('RE-%04d', $index + 1),
                'voucherDate' => $day . 'T00:00:00.000+02:00',
                'updatedDate' => $day . 'T10:00:00.000+02:00',
            ];
        }

        return $vouchers;
    }

    /**
     * @return list<MockResponse>
     */
    private function smallInvoices(int $count): array
    {
        return array_map(fn(): MockResponse => $this->smallInvoice(10.0), range(1, $count));
    }
    private function smallInvoice(float $net): MockResponse
    {
        return new MockResponse(json_encode([
            'voucherNumber' => 'RE-SMALL',
            'voucherDate' => '2026-09-01T00:00:00.000+02:00',
            'totalPrice' => ['currency' => 'EUR', 'totalNetAmount' => $net, 'totalGrossAmount' => $net, 'totalTaxAmount' => 0.0],
        ], \JSON_THROW_ON_ERROR));
    }

    private function error(int $status, string $message): MockResponse
    {
        return new MockResponse(json_encode(['status' => $status, 'error' => 'Error', 'message' => $message], \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    private function queryValue(int $request, string $key): string
    {
        $query = [];
        parse_str((string) parse_url($this->responses[$request]->getRequestUrl(), PHP_URL_QUERY), $query);
        $value = $query[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
