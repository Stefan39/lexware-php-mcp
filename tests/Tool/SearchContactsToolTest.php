<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests\Tool;

use Jacomeit\LexwarePhpMcp\Lexware\LexwareClient;
use Jacomeit\LexwarePhpMcp\Lexware\PagedList;
use Jacomeit\LexwarePhpMcp\Lexware\RequestThrottle;
use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;
use Jacomeit\LexwarePhpMcp\Tool\SearchContactsTool;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchContactsToolTest extends TestCase
{
    /** @var list<MockResponse> */
    private array $responses = [];

    public function testSendsFiltersAsQueryParameters(): void
    {
        $this->createTool([$this->fixture('search')])(name: ' Acme ', email: 'info@acme', number: 10001);

        self::assertSame('/v1/contacts', parse_url($this->responses[0]->getRequestUrl(), PHP_URL_PATH));
        self::assertSame(['name' => 'Acme', 'email' => 'info@acme', 'number' => '10001', 'page' => '0', 'size' => '250'], $this->query(0));
    }

    public function testRoleCustomerSendsCustomerFlag(): void
    {
        $this->createTool([$this->fixture('search')])(role: 'customer');

        self::assertSame(['customer' => 'true', 'page' => '0', 'size' => '250'], $this->query(0));
    }

    public function testRoleVendorSendsVendorFlag(): void
    {
        $this->createTool([$this->fixture('search')])(name: 'Zeta', role: 'vendor');

        self::assertSame(['name' => 'Zeta', 'vendor' => 'true', 'page' => '0', 'size' => '250'], $this->query(0));
    }

    public function testRoleAnySendsNoRoleFlag(): void
    {
        $this->createTool([$this->fixture('search')])(name: 'Acme', role: 'any');

        self::assertArrayNotHasKey('customer', $this->query(0));
        self::assertArrayNotHasKey('vendor', $this->query(0));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRoles(): iterable
    {
        yield 'unknown' => ['supplier'];
        yield 'empty' => [''];
        yield 'blank' => [' '];
        yield 'upper case' => ['Customer'];
        yield 'padded' => [' vendor '];
    }

    #[DataProvider('invalidRoles')]
    public function testRejectsInvalidRole(string $role): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/role must be "customer", "vendor" or "any"/');

        $this->createTool([])(name: 'Acme', role: $role);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortTexts(): iterable
    {
        yield 'one character' => ['a'];
        yield 'two characters' => ['ab'];
        yield 'two characters with spaces' => ['  ab  '];
        yield 'two multibyte characters' => ['äö'];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
    }

    #[DataProvider('shortTexts')]
    public function testRejectsTooShortNameAndEmailBeforeAnyRequest(string $text): void
    {
        foreach (['name', 'email'] as $parameter) {
            try {
                $this->createTool([])(...[$parameter => $text]);
                self::fail('Expected ToolCallException');
            } catch (ToolCallException $exception) {
                self::assertStringContainsString($parameter . ' must have at least 3 characters', $exception->getMessage());
            }
            self::assertSame([], $this->responses);
        }
    }

    public function testAcceptsThreeCharacters(): void
    {
        $this->createTool([$this->fixture('search')])(name: 'äöü');

        self::assertSame('äöü', $this->query(0)['name']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'text' => ['K-1001'];
        yield 'decimal' => ['10.5'];
        yield 'negative' => ['-5'];
        yield 'embedded space' => ['1 001'];
        yield 'too long' => ['1234567890123456'];
        yield 'numeric string' => ['10001'];
        yield 'empty' => [''];
        yield 'blank' => [' '];
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsInvalidNumber(string $number): void
    {
        try {
            $this->createTool([])(number: $number);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('number must be an integer', $exception->getMessage());
        }
        self::assertSame([], $this->responses);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonIntegerNumbersFromClient(): iterable
    {
        yield 'boolean' => [true];
        yield 'fraction' => [1.5];
        yield 'numeric string' => ['10001'];
        yield 'array' => [[1]];
    }

    #[DataProvider('nonIntegerNumbersFromClient')]
    public function testSdkCallRejectsNonIntegerNumberWithoutCoercion(mixed $number): void
    {
        $tool = $this->createTool([]);

        try {
            new ReferenceHandler()->handle(new ElementReference([$tool, '__invoke']), ['number' => $number, '_session' => new Session(new InMemorySessionStore())]);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('number must be an integer', $exception->getMessage());
        }
        self::assertSame([], $this->responses);
    }

    public function testSdkCallPassesIntegerNumber(): void
    {
        $tool = $this->createTool([$this->fixture('search')]);

        new ReferenceHandler()->handle(new ElementReference([$tool, '__invoke']), ['number' => 10001, '_session' => new Session(new InMemorySessionStore())]);

        self::assertSame('10001', $this->query(0)['number']);
    }

    public function testRejectsMissingFilter(): void
    {
        foreach ([[], ['role' => 'any']] as $arguments) {
            try {
                $this->createTool([])(...$arguments);
                self::fail('Expected ToolCallException');
            } catch (ToolCallException $exception) {
                self::assertStringContainsString('at least one of name, email, number or role', $exception->getMessage());
            }
        }
    }

    public function testRejectsLimitOutsideAllowedRange(): void
    {
        foreach ([0, -1, 251] as $limit) {
            try {
                $this->createTool([])(name: 'Acme', limit: $limit);
                self::fail('Expected ToolCallException');
            } catch (ToolCallException $exception) {
                self::assertStringContainsString('between 1 and 250', $exception->getMessage());
            }
        }
    }

    public function testReturnsItemsSortedByName(): void
    {
        $result = $this->createTool([$this->fixture('search')])(role: 'customer');

        self::assertSame(4, $result['count']);
        self::assertTrue($result['complete']);
        self::assertFalse($result['truncated']);
        self::assertSame(['Acme Beispiel GmbH', 'Anna Ohnerolle', 'Hans Privatkunde', 'Zeta Lieferant KG'], array_column($result['items'], 'name'));
    }

    public function testCompanyContainsContactDetailsAndContactPersonNames(): void
    {
        $result = $this->createTool([$this->fixture('search')])(name: 'Acme');

        self::assertSame([
            'id' => 'e9066f04-0000-0000-0000-000000000001',
            'type' => 'company',
            'name' => 'Acme Beispiel GmbH',
            'customerNumber' => 10001,
            'vendorNumber' => 70002,
            'archived' => false,
            'emailAddresses' => ['info@acme-beispiel.example', 'buchhaltung@acme-beispiel.example'],
            'phoneNumbers' => ['+49 40 111111', '+49 170 2222222', '+49 40 333333'],
            'billingAddress' => ['street' => 'Musterstraße 1', 'zip' => '20095', 'city' => 'Hamburg', 'countryCode' => 'DE'],
            'contactPersons' => [
                ['firstName' => 'Erika', 'lastName' => 'Musterfrau'],
                ['firstName' => 'Max', 'lastName' => 'Beispiel'],
            ],
        ], $result['items'][0]);
    }

    public function testVendorOnlyCompanyHasNoCustomerNumber(): void
    {
        $item = $this->createTool([$this->fixture('search')])(role: 'vendor')['items'][3];

        self::assertSame([
            'id' => 'e9066f04-0000-0000-0000-000000000003',
            'type' => 'company',
            'name' => 'Zeta Lieferant KG',
            'customerNumber' => null,
            'vendorNumber' => 70001,
            'archived' => true,
            'emailAddresses' => ['einkauf@zeta-lieferant.example'],
            'phoneNumbers' => [],
            'billingAddress' => ['street' => 'Lagerweg 3', 'zip' => '20095', 'city' => 'Hamburg', 'countryCode' => 'DE'],
            'contactPersons' => [],
        ], $item);
    }

    public function testPrivatePersonIsReducedToAllowedFields(): void
    {
        $result = $this->createTool([$this->fixture('search')])(name: 'Privat');

        self::assertSame([
            'id' => 'e9066f04-0000-0000-0000-000000000002',
            'type' => 'person',
            'name' => 'Hans Privatkunde',
            'customerNumber' => 10002,
            'vendorNumber' => null,
            'archived' => false,
        ], $result['items'][2]);

        $json = (string) json_encode($result);
        foreach (['hans.privatkunde@example.test', '5555555', '6666666', 'Privatweg', 'Allergie', 'München'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function testPersonWithoutRolesHasNullNumbers(): void
    {
        $item = $this->createTool([$this->fixture('search')])(name: 'Anna')['items'][1];

        self::assertSame('Anna Ohnerolle', $item['name']);
        self::assertNull($item['customerNumber']);
        self::assertNull($item['vendorNumber']);
    }

    public function testOutputContainsNoBankNoteVersionOrXRechnungData(): void
    {
        $response = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/contacts-search.json'), true);
        self::assertIsArray($response);
        $response['content'][1]['bankAccounts'] = [['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'accountHolder' => 'Acme']];
        $response['content'][1]['company']['iban'] = 'DE89370400440532013000';

        $json = (string) json_encode($this->createTool([new MockResponse((string) json_encode($response))])(name: 'Acme'));

        foreach (['iban', 'bic', 'bank', 'DE02120300000000202051', 'DE89370400440532013000', 'note', 'Notiz', 'version', 'xRechnung', 'buyerReference', 'taxNumber', 'Rabatt'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $json);
        }
    }

    public function testLimitCutsItemsAndFlagsTruncation(): void
    {
        $result = $this->createTool([$this->fixture('search')])(name: 'Acme', limit: 2);

        self::assertSame(2, $result['count']);
        self::assertTrue($result['truncated']);
        self::assertSame(['Acme Beispiel GmbH', 'Anna Ohnerolle'], array_column($result['items'], 'name'));

        $exact = $this->createTool([$this->fixture('search')])(name: 'Acme', limit: 4);
        self::assertSame(4, $exact['count']);
        self::assertFalse($exact['truncated']);
    }

    public function testReadsAllPagesAndSortsAcrossThem(): void
    {
        $result = $this->createTool([$this->fixture('page-0'), $this->fixture('page-1')])(role: 'customer');

        self::assertTrue($result['complete']);
        self::assertSame(['Anton Muster GmbH', 'Berta Beispiel AG', 'Cäsar Handel OHG'], array_column($result['items'], 'name'));
        self::assertSame('1', $this->query(1)['page']);
    }

    public function testMarksResultIncompleteWhenPageCapIsHit(): void
    {
        $result = $this->createTool([$this->fixture('page-0')], maxPages: 1)(role: 'customer');

        self::assertFalse($result['complete']);
        self::assertSame(2, $result['count']);
        self::assertCount(1, $this->responses);
    }

    public function testApiFailureBecomesToolErrorWithLexwareMessage(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/400.*invalid number/');

        $this->createTool([new MockResponse(
            '{"status":400,"error":"Bad Request","path":"/v1/contacts","message":"invalid number"}',
            ['http_code' => 400],
        )])(number: 1);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function createTool(array $responses, int $maxPages = 10): SearchContactsTool
    {
        $this->responses = $responses;
        $clock = new MockClock('2026-10-10 09:00:00', 'Europe/Berlin');
        $client = new LexwareClient(new MockHttpClient($responses, 'https://api.example.test'), new RequestThrottle($clock), $clock);

        return new SearchContactsTool(new PagedList($client, new SensitiveDataFilter(), '/v1/contacts'), $maxPages);
    }

    private function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/../Fixtures/contacts-' . $name . '.json'));
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
