<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests;

use Jacomeit\LexwarePhpMcp\ServerFactory;
use Jacomeit\LexwarePhpMcp\Tool\OpenItemsTool;
use Jacomeit\LexwarePhpMcp\Tool\SearchVouchersTool;
use PHPUnit\Framework\TestCase;

/**
 * The Claude Desktop extension manifest is maintained by hand; keep it in sync with the server.
 */
final class McpbManifestTest extends TestCase
{
    private const string MANIFEST = __DIR__ . '/../build-tools/mcpb/manifest.json';

    public function testManifestMatchesServer(): void
    {
        $manifest = json_decode((string) file_get_contents(self::MANIFEST), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        self::assertSame(ServerFactory::NAME, $manifest['name'] ?? null);
        self::assertSame(ServerFactory::VERSION, $manifest['version'] ?? null);
        self::assertSame([OpenItemsTool::NAME, SearchVouchersTool::NAME], array_column($manifest['tools'] ?? [], 'name'));
    }

    public function testApiKeyIsStoredAsSecretAndPassedViaEnvironment(): void
    {
        $manifest = json_decode((string) file_get_contents(self::MANIFEST), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        self::assertTrue($manifest['user_config']['api_key']['sensitive'] ?? false);
        self::assertSame('${user_config.api_key}', $manifest['server']['mcp_config']['env']['LEXWARE_API_KEY'] ?? null);
        self::assertSame([], $manifest['server']['mcp_config']['args'] ?? null);
    }
}
