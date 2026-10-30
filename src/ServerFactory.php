<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp;

use Jacomeit\LexwarePhpMcp\Config\CaBundle;
use Jacomeit\LexwarePhpMcp\Config\Configuration;
use Jacomeit\LexwarePhpMcp\Lexware\LexwareClient;
use Jacomeit\LexwarePhpMcp\Lexware\RequestThrottle;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;
use Jacomeit\LexwarePhpMcp\Tool\OpenItemsTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;

final class ServerFactory
{
    public const string NAME = 'lexware-php-mcp';

    public const string VERSION = '0.3.0';

    private const string INSTRUCTIONS = 'lexware-php-mcp: unofficial, read-only access to a Lexware Office (formerly lexoffice) account. '
        . 'Not affiliated with Haufe Group. Bank details are never returned.';

    public static function create(Configuration $configuration): Server
    {
        $clock = new NativeClock('Europe/Berlin');
        $httpClient = HttpClient::createForBaseUri($configuration->baseUrl, [
            'auth_bearer' => $configuration->apiKey,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => self::NAME . '/' . self::VERSION],
            'timeout' => 20,
        ] + CaBundle::detectHttpClientOptions());
        $client = new LexwareClient($httpClient, new RequestThrottle($clock), $clock);
        $openItems = new OpenItemsTool(new VoucherList($client, new SensitiveDataFilter()), $clock);

        return Server::builder()
            ->setServerInfo(self::NAME, self::VERSION)
            ->setInstructions(self::INSTRUCTIONS)
            ->addTool(
                // The SDK accepts [object, method] pairs but not invokable objects.
                [$openItems, '__invoke'],
                name: OpenItemsTool::NAME,
                title: 'Open items (unpaid sales invoices)',
                description: OpenItemsTool::DESCRIPTION,
                annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true),
            )
            ->build();
    }
}
