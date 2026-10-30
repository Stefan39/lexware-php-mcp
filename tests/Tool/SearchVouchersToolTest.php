<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests\Tool;

use Jacomeit\LexwarePhpMcp\Lexware\LexwareClient;
use Jacomeit\LexwarePhpMcp\Lexware\RequestThrottle;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;
use Jacomeit\LexwarePhpMcp\Tool\SearchVouchersTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchVouchersToolTest extends TestCase
{
    /** @var list<MockResponse> */
    private array $responses = [];

    public function testSendsFiltersAsQueryParameters(): void
    {
        $this->createTool([$this->fixture('search')])(
            voucherType: 'invoice,creditnote',
            voucherStatus: 'open,paid',
            dateFrom: '2026-06-01',
            dateTo: '2026-10-31',
            voucherNumber: 'RE-2026-0060',
        );

        self::assertSame('/v1/voucherlist', parse_url($this->responses[0]->getRequestUrl(), PHP_URL_PATH));
        self::assertSame('invoice,creditnote', $this->queryValue(0, 'voucherType'));
        self::assertSame('open,paid', $this->queryValue(0, 'voucherStatus'));
        self::assertSame('2026-06-01', $this->queryValue(0, 'voucherDateFrom'));
        self::assertSame('2026-10-31', $this->queryValue(0, 'voucherDateTo'));
        self::assertSame('RE-2026-0060', $this->queryValue(0, 'voucherNumber'));
        self::assertSame('0', $this->queryValue(0, 'page'));
    }

    public function testDefaultsToAnyTypeAndStatusWithoutOptionalFilters(): void
    {
        $this->createTool([$this->fixture('search')])();

        self::assertSame('any', $this->queryValue(0, 'voucherType'));
        self::assertSame('any', $this->queryValue(0, 'voucherStatus'));
        self::assertStringNotContainsString('voucherDate', (string) parse_url($this->responses[0]->getRequestUrl(), PHP_URL_QUERY));
        self::assertStringNotContainsString('voucherNumber', (string) parse_url($this->responses[0]->getRequestUrl(), PHP_URL_QUERY));
    }

    public function testReturnsItemsSortedByDateDescendingThenNumber(): void
    {
        $result = $this->createTool([$this->fixture('search')])();

        self::assertSame(4, $result['count']);
        self::assertTrue($result['complete']);
        self::assertFalse($result['truncated']);
        self::assertSame(['RE-2026-0070', 'GS-2026-0003', 'RE-2026-0060', 'RE-2026-0050'], array_column($result['items'], 'voucherNumber'));
        self::assertSame([
            'id' => 'b1b2c3d4-0000-0000-0000-000000000003',
            'voucherType' => 'invoice',
            'voucherNumber' => 'RE-2026-0060',
            'contactName' => 'Beispiel AG',
            'voucherDate' => '2026-09-01',
            'dueDate' => '2026-09-15',
            'totalAmount' => 595.0,
            'openAmount' => 595.0,
            'currency' => 'EUR',
            'status' => 'open',
        ], $result['items'][2]);
        self::assertNull($result['items'][1]['dueDate']);
        self::assertStringContainsString('gross', $result['note']);
    }

    public function testRejectsOverdueCombinedWithOtherStatus(): void
    {
        $tool = $this->createTool([]);

        try {
            $tool(voucherStatus: 'open, overdue');
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('overdue', $exception->getMessage());
            self::assertStringContainsString('on its own', $exception->getMessage());
        }
    }

    public function testAcceptsOverdueOnItsOwn(): void
    {
        $this->createTool([$this->fixture('search')])(voucherStatus: 'overdue');

        self::assertSame('overdue', $this->queryValue(0, 'voucherStatus'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'german format' => ['01.09.2026'];
        yield 'no leading zeros' => ['2026-9-1'];
        yield 'impossible day' => ['2026-02-30'];
        yield 'with time' => ['2026-09-01T10:00:00'];
        yield 'text' => ['yesterday'];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidDates(string $date): void
    {
        foreach (['dateFrom', 'dateTo'] as $parameter) {
            try {
                $this->createTool([])(...[$parameter => $date]);
                self::fail('Expected ToolCallException');
            } catch (ToolCallException $exception) {
                self::assertStringContainsString($parameter, $exception->getMessage());
                self::assertStringContainsString('YYYY-MM-DD', $exception->getMessage());
            }
        }
    }

    public function testRejectsLimitOutsideAllowedRange(): void
    {
        foreach ([0, -1, 251] as $limit) {
            try {
                $this->createTool([])(limit: $limit);
                self::fail('Expected ToolCallException');
            } catch (ToolCallException $exception) {
                self::assertStringContainsString('between 1 and 250', $exception->getMessage());
            }
        }
    }

    public function testFiltersByContactNameCaseInsensitively(): void
    {
        $result = $this->createTool([$this->fixture('search')])(contact: 'acme');

        self::assertSame(2, $result['count']);
        self::assertSame(['RE-2026-0070', 'RE-2026-0050'], array_column($result['items'], 'voucherNumber'));
    }

    public function testLimitCutsItemsAndFlagsTruncation(): void
    {
        $result = $this->createTool([$this->fixture('search')])(limit: 2);

        self::assertSame(2, $result['count']);
        self::assertTrue($result['truncated']);
        self::assertSame(['RE-2026-0070', 'GS-2026-0003'], array_column($result['items'], 'voucherNumber'));

        $exact = $this->createTool([$this->fixture('search')])(limit: 4);
        self::assertFalse($exact['truncated']);
    }

    public function testMarksResultIncompleteWhenPageCapIsHit(): void
    {
        $result = $this->createTool([$this->fixture('page-0')], maxPages: 1)();

        self::assertFalse($result['complete']);
        self::assertSame(2, $result['count']);
        self::assertCount(1, $this->responses);
    }

    public function testOutputContainsNoBankData(): void
    {
        $json = (string) json_encode($this->createTool([$this->fixture('search')])());

        self::assertStringNotContainsStringIgnoringCase('iban', $json);
        self::assertStringNotContainsString('DE02120300000000202051', $json);
    }

    public function testApiFailureBecomesToolError(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/400.*invalid voucherType/');

        $this->createTool([new MockResponse(
            '{"status":400,"error":"Bad Request","path":"/v1/voucherlist","message":"invalid voucherType"}',
            ['http_code' => 400],
        )])(voucherType: 'bogus');
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function createTool(array $responses, int $maxPages = 10): SearchVouchersTool
    {
        $this->responses = $responses;
        $clock = new MockClock('2026-10-10 09:00:00', 'Europe/Berlin');
        $client = new LexwareClient(new MockHttpClient($responses, 'https://api.example.test'), new RequestThrottle($clock), $clock);

        return new SearchVouchersTool(new VoucherList($client, new SensitiveDataFilter()), $maxPages);
    }

    private function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/../Fixtures/voucherlist-' . $name . '.json'));
    }

    private function queryValue(int $request, string $key): string
    {
        $query = [];
        parse_str((string) parse_url($this->responses[$request]->getRequestUrl(), PHP_URL_QUERY), $query);
        $value = $query[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
