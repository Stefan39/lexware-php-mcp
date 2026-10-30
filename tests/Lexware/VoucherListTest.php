<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests\Lexware;

use Jacomeit\LexwarePhpMcp\Lexware\LexwareClient;
use Jacomeit\LexwarePhpMcp\Lexware\RequestThrottle;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VoucherListTest extends TestCase
{
    /** @var list<MockResponse> */
    private array $responses = [];

    public function testReadsAllPagesUntilLast(): void
    {
        [$vouchers, $complete] = $this->createList([$this->fixture(0), $this->fixture(1)])->fetch(['voucherStatus' => 'open'], 10);

        self::assertTrue($complete);
        self::assertCount(3, $vouchers);
        self::assertCount(2, $this->responses);
    }

    public function testSendsQueryWithPageAndSize(): void
    {
        $this->createList([$this->fixture(0), $this->fixture(1)])->fetch(['voucherStatus' => 'open'], 10);

        self::assertSame('/v1/voucherlist', parse_url($this->responses[0]->getRequestUrl(), PHP_URL_PATH));
        self::assertSame(['voucherStatus' => 'open', 'page' => '0', 'size' => '250'], $this->query(0));
        self::assertSame(['voucherStatus' => 'open', 'page' => '1', 'size' => '250'], $this->query(1));
    }

    public function testReportsIncompleteWhenPageCapIsHit(): void
    {
        [$vouchers, $complete] = $this->createList([$this->fixture(0)])->fetch([], 1);

        self::assertFalse($complete);
        self::assertCount(2, $vouchers);
        self::assertCount(1, $this->responses);
    }

    public function testAppliesSensitiveDataFilterToEveryPage(): void
    {
        [$vouchers] = $this->createList([
            $this->page([['id' => 'v1', 'iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']], false),
            $this->page([['id' => 'v2', 'IBAN' => 'DE89370400440532013000', 'bankAccount' => ['accountHolder' => 'Max Muster']]], true),
        ])->fetch([], 10);

        self::assertSame([['id' => 'v1'], ['id' => 'v2']], $vouchers);
    }

    public function testIgnoresMissingContentAndTreatsMissingLastAsFinal(): void
    {
        [$vouchers, $complete] = $this->createList([new MockResponse('{}')])->fetch([], 10);

        self::assertSame([], $vouchers);
        self::assertTrue($complete);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function createList(array $responses): VoucherList
    {
        $this->responses = $responses;
        $clock = new MockClock('2026-10-10 09:00:00', 'Europe/Berlin');
        $client = new LexwareClient(new MockHttpClient($responses, 'https://api.example.test'), new RequestThrottle($clock), $clock);

        return new VoucherList($client, new SensitiveDataFilter());
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function page(array $content, bool $last): MockResponse
    {
        return new MockResponse((string) json_encode(['content' => $content, 'last' => $last]));
    }

    private function fixture(int $page): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/../Fixtures/voucherlist-page-' . $page . '.json'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function query(int $request): array
    {
        $query = [];
        parse_str((string) parse_url($this->responses[$request]->getRequestUrl(), PHP_URL_QUERY), $query);

        return $query;
    }
}
