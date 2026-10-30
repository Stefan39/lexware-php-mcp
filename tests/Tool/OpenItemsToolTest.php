<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests\Tool;

use Jacomeit\LexwarePhpMcp\Lexware\LexwareClient;
use Jacomeit\LexwarePhpMcp\Lexware\RequestThrottle;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;
use Jacomeit\LexwarePhpMcp\Tool\OpenItemsTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenItemsToolTest extends TestCase
{
    /** @var list<MockResponse> */
    private array $responses = [];

    public function testListsOpenSalesInvoicesAcrossPagesSortedByDueDate(): void
    {
        $result = $this->createTool($this->bothPages())();

        self::assertSame(3, $result['count']);
        self::assertSame(2, $result['overdueCount']);
        self::assertTrue($result['complete']);
        self::assertSame('2026-10-10', $result['asOf']);
        self::assertSame(['EUR' => ['open' => 1485.40, 'overdue' => 1190.30]], $result['totalsByCurrency']);
        self::assertSame(['RE-2026-0101', 'RE-2026-0090', 'RE-2026-0102'], array_column($result['items'], 'voucherNumber'));
        self::assertStringContainsString('gross', $result['note']);
    }

    public function testRequestsOpenInvoicesOfBothInvoiceTypes(): void
    {
        $this->createTool($this->bothPages())();

        self::assertSame('/v1/voucherlist', parse_url($this->responses[0]->getRequestUrl(), PHP_URL_PATH));
        self::assertSame('invoice,salesinvoice', $this->queryValue(0, 'voucherType'));
        self::assertSame('open', $this->queryValue(0, 'voucherStatus'));
        self::assertSame('0', $this->queryValue(0, 'page'));
        self::assertSame('1', $this->queryValue(1, 'page'));
    }

    public function testFlagsOverdueByStatusOrPastDueDate(): void
    {
        $items = $this->indexByNumber($this->createTool($this->bothPages())()['items']);

        self::assertTrue($items['RE-2026-0101']['overdue']);
        self::assertSame(10, $items['RE-2026-0101']['daysOverdue']);
        self::assertTrue($items['RE-2026-0090']['overdue']);
        self::assertSame(5, $items['RE-2026-0090']['daysOverdue']);
        self::assertFalse($items['RE-2026-0102']['overdue']);
        self::assertSame(0, $items['RE-2026-0102']['daysOverdue']);
        self::assertSame('2026-10-20', $items['RE-2026-0102']['dueDate']);
    }

    public function testKeepsVoucherTypeBecauseDetailEndpointsDifferPerType(): void
    {
        $items = $this->indexByNumber($this->createTool($this->bothPages())()['items']);

        self::assertSame('invoice', $items['RE-2026-0101']['voucherType']);
        self::assertSame('salesinvoice', $items['RE-2026-0102']['voucherType']);
    }

    public function testFiltersOverdueOnlyAndByContactName(): void
    {
        $overdue = $this->createTool($this->bothPages())(overdueOnly: true);
        self::assertSame(['RE-2026-0101', 'RE-2026-0090'], array_column($overdue['items'], 'voucherNumber'));

        $byContact = $this->createTool($this->bothPages())(contact: 'beispiel');
        self::assertSame(['RE-2026-0102'], array_column($byContact['items'], 'voucherNumber'));
        self::assertSame(['EUR' => ['open' => 295.10, 'overdue' => 0.0]], $byContact['totalsByCurrency']);
    }

    public function testOutputContainsNoBankData(): void
    {
        $json = (string) json_encode($this->createTool($this->bothPages())());

        self::assertStringNotContainsStringIgnoringCase('iban', $json);
        self::assertStringNotContainsString('DE02120300000000202051', $json);
    }

    public function testMarksResultIncompleteWhenPageLimitIsReached(): void
    {
        $result = $this->createTool([$this->fixture(0)], maxPages: 1)();

        self::assertFalse($result['complete']);
        self::assertSame(2, $result['count']);
    }

    public function testApiFailureBecomesToolError(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/400.*status overdue can not be combined/');

        $this->createTool([new MockResponse(
            '{"status":400,"error":"Bad Request","path":"/v1/voucherlist","message":"status overdue can not be combined"}',
            ['http_code' => 400],
        )])();
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function createTool(array $responses, int $maxPages = 10): OpenItemsTool
    {
        $this->responses = $responses;
        $clock = new MockClock('2026-10-10 09:00:00', 'Europe/Berlin');
        $client = new LexwareClient(new MockHttpClient($responses, 'https://api.example.test'), new RequestThrottle($clock), $clock);

        return new OpenItemsTool(new VoucherList($client, new SensitiveDataFilter()), $clock, $maxPages);
    }

    /**
     * @return list<MockResponse>
     */
    private function bothPages(): array
    {
        return [$this->fixture(0), $this->fixture(1)];
    }

    private function fixture(int $page): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/../Fixtures/voucherlist-page-' . $page . '.json'));
    }

    private function queryValue(int $request, string $key): string
    {
        $query = [];
        parse_str((string) parse_url($this->responses[$request]->getRequestUrl(), PHP_URL_QUERY), $query);
        $value = $query[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexByNumber(array $items): array
    {
        return array_column($items, null, 'voucherNumber');
    }
}
