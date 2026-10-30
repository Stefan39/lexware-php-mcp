<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests;

use Jacomeit\LexwarePhpMcp\ServerFactory;
use PHPUnit\Framework\TestCase;

final class ServerSmokeTest extends TestCase
{
    private const string BINARY = __DIR__ . '/../bin/lexware-php-mcp';

    public function testStdioServerAnnouncesReadOnlyTools(): void
    {
        $responses = $this->exchange(['LEXWARE_API_KEY' => 'dummy-key-for-smoke-test'], [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'smoke-test', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ], 2);

        self::assertSame('lexware-php-mcp', $responses[1]['result']['serverInfo']['name'] ?? null);
        self::assertSame(ServerFactory::VERSION, $responses[1]['result']['serverInfo']['version'] ?? null);

        $tools = array_column($responses[2]['result']['tools'] ?? [], null, 'name');
        self::assertSame(['open_items', 'search_vouchers'], array_keys($tools));
        foreach ($tools as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint'] ?? false);
            self::assertFalse($tool['annotations']['destructiveHint'] ?? true);
        }
        self::assertStringNotContainsString('dummy-key-for-smoke-test', (string) json_encode($responses));
    }

    public function testRefusesToStartWithoutApiKey(): void
    {
        $process = proc_open([\PHP_BINARY, self::BINARY], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        self::assertSame(1, proc_close($process));
        self::assertSame('', $stdout);
        self::assertStringContainsString('LEXWARE_API_KEY', $stderr);
    }

    /**
     * @param array<string, string>       $environment
     * @param list<array<string, mixed>>  $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function exchange(array $environment, array $messages, int $expectedResponses): array
    {
        $environment['PATH'] = (string) getenv('PATH');
        $process = proc_open([\PHP_BINARY, self::BINARY], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);

        foreach ($messages as $message) {
            fwrite($pipes[0], json_encode($message, \JSON_THROW_ON_ERROR) . "\n");
        }

        $responses = [];
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 10;
        $buffer = '';
        while (\count($responses) < $expectedResponses && microtime(true) < $deadline) {
            $buffer .= (string) fread($pipes[1], 65536);
            while (false !== $newline = strpos($buffer, "\n")) {
                $decoded = json_decode(substr($buffer, 0, $newline), true);
                $buffer = substr($buffer, $newline + 1);
                if (\is_array($decoded) && isset($decoded['id']) && \is_int($decoded['id'])) {
                    $responses[$decoded['id']] = $decoded;
                }
            }
            usleep(10000);
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);

        self::assertCount($expectedResponses, $responses, 'Server did not answer in time.');

        return $responses;
    }
}
