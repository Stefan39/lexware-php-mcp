<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests\Tool;

use Jacomeit\LexwarePhpMcp\Lexware\LexwareClient;
use Jacomeit\LexwarePhpMcp\Lexware\RequestThrottle;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetail;
use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;
use Jacomeit\LexwarePhpMcp\Tool\VoucherDetailTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VoucherDetailToolTest extends TestCase
{
    private const string ID = 'e9066f04-8cc7-4616-93f8-3d24f2a43d3a';

    /** @var list<MockResponse> */
    private array $responses = [];

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function endpoints(): iterable
    {
        yield 'invoice' => ['invoice', 'invoice', '/v1/invoices/'];
        yield 'creditnote' => ['creditnote', 'creditnote', '/v1/credit-notes/'];
        yield 'downpaymentinvoice' => ['downpaymentinvoice', 'downpaymentinvoice', '/v1/down-payment-invoices/'];
        yield 'salesinvoice' => ['salesinvoice', 'bookkeeping', '/v1/vouchers/'];
        yield 'salescreditnote' => ['salescreditnote', 'bookkeeping', '/v1/vouchers/'];
        yield 'purchaseinvoice' => ['purchaseinvoice', 'bookkeeping', '/v1/vouchers/'];
        yield 'purchasecreditnote' => ['purchasecreditnote', 'bookkeeping', '/v1/vouchers/'];
    }

    #[DataProvider('endpoints')]
    public function testRequestsTheEndpointOfTheVoucherType(string $voucherType, string $fixture, string $path): void
    {
        $detail = $this->createTool([$this->fixture($fixture)])(self::ID, $voucherType);

        self::assertSame($path . self::ID, parse_url($this->responses[0]->getRequestUrl(), PHP_URL_PATH));
        self::assertSame($voucherType, $detail['voucherType']);
    }

    public function testNormalisesInvoiceWithTotalsTaxBreakdownAndLineItems(): void
    {
        $detail = $this->createTool([$this->fixture('invoice')])(self::ID, 'invoice');

        self::assertSame([
            'id' => 'e9066f04-8cc7-4616-93f8-3d24f2a43d3a',
            'voucherType' => 'invoice',
            'voucherNumber' => 'RE-2026-0060',
            'voucherDate' => '2026-09-01',
            'contactName' => 'Beispiel AG',
            'currency' => 'EUR',
            'totalNetAmount' => 1040.0,
            'totalTaxAmount' => 192.8,
            'totalGrossAmount' => 1232.8,
            'taxAmounts' => [
                ['taxRatePercentage' => 19.0, 'netAmount' => 1000.0, 'taxAmount' => 190.0],
                ['taxRatePercentage' => 7.0, 'netAmount' => 40.0, 'taxAmount' => 2.8],
            ],
            'lineItems' => [
                ['name' => 'Beratung', 'quantity' => 10.0, 'unitName' => 'Stunde', 'netAmount' => 1000.0, 'grossAmount' => 1190.0, 'taxRatePercentage' => 19.0],
                ['name' => 'Fachbuch', 'quantity' => 2.0, 'unitName' => 'Stück', 'netAmount' => 40.0, 'grossAmount' => 42.8, 'taxRatePercentage' => 7.0],
                ['name' => 'Vielen Dank für Ihren Auftrag.', 'quantity' => null, 'unitName' => null, 'netAmount' => null, 'grossAmount' => null, 'taxRatePercentage' => null],
            ],
        ], $detail);
    }

    public function testNormalisesCreditNoteAndDownPaymentInvoice(): void
    {
        $creditNote = $this->createTool([$this->fixture('creditnote')])(self::ID, 'creditnote');
        self::assertSame('GS-2026-0003', $creditNote['voucherNumber']);
        self::assertSame(150.0, $creditNote['totalNetAmount']);
        self::assertSame(178.5, $creditNote['totalGrossAmount']);
        self::assertSame(1.5, $creditNote['lineItems'][0]['quantity']);

        $downPayment = $this->createTool([$this->fixture('downpaymentinvoice')])(self::ID, 'downpaymentinvoice');
        self::assertSame('AR-2026-0001', $downPayment['voucherNumber']);
        self::assertSame(570.0, $downPayment['totalTaxAmount']);
        self::assertSame([['taxRatePercentage' => 19.0, 'netAmount' => 3000.0, 'taxAmount' => 570.0]], $downPayment['taxAmounts']);
    }

    public function testNormalisesBookkeepingVoucherWithGrossItems(): void
    {
        $detail = $this->createTool([$this->fixture('bookkeeping')])(self::ID, 'salesinvoice');

        self::assertSame('ER-4711', $detail['voucherNumber']);
        self::assertSame('2026-10-02', $detail['voucherDate']);
        self::assertSame('Lieferant Muster KG', $detail['contactName'] ?? null);
        self::assertSame('EUR', $detail['currency']);
        self::assertSame(190.0, $detail['totalNetAmount']);
        self::assertSame(36.0, $detail['totalTaxAmount']);
        self::assertSame(226.0, $detail['totalGrossAmount']);
        self::assertSame([
            ['taxRatePercentage' => 19.0, 'netAmount' => 100.0, 'taxAmount' => 19.0],
            ['taxRatePercentage' => 7.0, 'netAmount' => 100.0, 'taxAmount' => 7.0],
        ], $detail['taxAmounts']);
        self::assertSame(
            ['name' => null, 'quantity' => null, 'unitName' => null, 'netAmount' => 100.0, 'grossAmount' => 119.0, 'taxRatePercentage' => 19.0],
            $detail['lineItems'][0],
        );
    }

    public function testOmitsContactNameWhenAbsent(): void
    {
        $detail = $this->createTool([new MockResponse('{"id":"' . self::ID . '","voucherNumber":"X-1"}')])(self::ID, 'invoice');

        self::assertArrayNotHasKey('contactName', $detail);
        self::assertSame([], $detail['lineItems']);
        self::assertNull($detail['voucherDate']);
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidIdBeforeAnyRequest(string $id): void
    {
        $tool = $this->createTool([]);

        try {
            $tool($id, 'invoice');
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('UUID', $exception->getMessage());
            self::assertSame([], $this->responses);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'path traversal' => ['../contacts'];
        yield 'query injection' => [self::ID . '?x=1'];
        yield 'trailing newline' => [self::ID . "\n"];
        yield 'not hex' => ['zzzzzzzz-8cc7-4616-93f8-3d24f2a43d3a'];
        yield 'too short' => ['e9066f04-8cc7-4616-93f8'];
    }

    public function testRejectsUnsupportedTypeAndNamesSupportedOnes(): void
    {
        $tool = $this->createTool([]);

        try {
            $tool(self::ID, 'quotation');
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('"quotation" is not supported', $exception->getMessage());
            foreach (['invoice', 'creditnote', 'downpaymentinvoice', 'salesinvoice', 'salescreditnote', 'purchaseinvoice', 'purchasecreditnote'] as $type) {
                self::assertStringContainsString($type, $exception->getMessage());
            }
            self::assertSame([], $this->responses);
        }
    }

    public function testLexwareErrorMessageShowsInToolError(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/404.*Voucher not found for id/');

        $this->createTool([new MockResponse(
            '{"status":404,"error":"Not Found","message":"Voucher not found for id","traceId":"abc"}',
            ['http_code' => 404],
        )])(self::ID, 'salesinvoice');
    }

    public function testOutputContainsNoBankData(): void
    {
        foreach (['invoice' => 'invoice', 'downpaymentinvoice' => 'downpaymentinvoice', 'salesinvoice' => 'bookkeeping'] as $type => $fixture) {
            $json = (string) json_encode($this->createTool([$this->fixture($fixture)])(self::ID, $type));

            self::assertStringNotContainsStringIgnoringCase('iban', $json);
            self::assertStringNotContainsStringIgnoringCase('bankAccount', $json);
            self::assertStringNotContainsString('DE02120300000000202051', $json);
            self::assertStringNotContainsString('BYLADEM1001', $json);
        }
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function createTool(array $responses): VoucherDetailTool
    {
        $this->responses = $responses;
        $clock = new MockClock('2026-10-10 09:00:00', 'Europe/Berlin');
        $client = new LexwareClient(new MockHttpClient($responses, 'https://api.example.test'), new RequestThrottle($clock), $clock);

        return new VoucherDetailTool(new VoucherDetail($client, new SensitiveDataFilter()));
    }

    private function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/../Fixtures/voucher-' . $name . '.json'));
    }
}
