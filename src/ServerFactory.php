<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp;

use Jacomeit\LexwarePhpMcp\Config\CaBundle;
use Jacomeit\LexwarePhpMcp\Config\Configuration;
use Jacomeit\LexwarePhpMcp\Lexware\LexwareClient;
use Jacomeit\LexwarePhpMcp\Lexware\PagedList;
use Jacomeit\LexwarePhpMcp\Lexware\RequestThrottle;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetail;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetailCache;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherList;
use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;
use Jacomeit\LexwarePhpMcp\Tool\OpenItemsTool;
use Jacomeit\LexwarePhpMcp\Tool\RevenueTool;
use Jacomeit\LexwarePhpMcp\Tool\SearchContactsTool;
use Jacomeit\LexwarePhpMcp\Tool\SearchVouchersTool;
use Jacomeit\LexwarePhpMcp\Tool\VoucherDetailTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;

final class ServerFactory
{
    public const string NAME = 'lexware-php-mcp';

    public const string VERSION = '0.4.0';

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
        $vouchers = new VoucherList($client, new SensitiveDataFilter());
        $openItems = new OpenItemsTool($vouchers, $clock);
        $searchVouchers = new SearchVouchersTool($vouchers);
        $searchContacts = new SearchContactsTool(new PagedList($client, new SensitiveDataFilter(), '/v1/contacts'));
        $voucherDetails = new VoucherDetail($client, new SensitiveDataFilter());
        $voucherDetail = new VoucherDetailTool($voucherDetails);
        $revenue = new RevenueTool($vouchers, $voucherDetails, new VoucherDetailCache());

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
            ->addTool(
                [$searchVouchers, '__invoke'],
                name: SearchVouchersTool::NAME,
                title: 'Search vouchers',
                description: SearchVouchersTool::DESCRIPTION,
                annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true),
            )
            ->addTool(
                [$voucherDetail, '__invoke'],
                name: VoucherDetailTool::NAME,
                title: 'Voucher detail',
                description: VoucherDetailTool::DESCRIPTION,
                annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true),
            )
            ->addTool(
                [$searchContacts, '__invoke'],
                name: SearchContactsTool::NAME,
                title: 'Search contacts',
                description: SearchContactsTool::DESCRIPTION,
                annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true),
            )
            ->addTool(
                [$revenue, '__invoke'],
                name: RevenueTool::NAME,
                title: 'Revenue (invoices minus credit notes)',
                description: RevenueTool::DESCRIPTION,
                annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true),
            )
            ->build();
    }
}
