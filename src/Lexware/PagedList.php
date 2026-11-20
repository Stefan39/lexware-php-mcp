<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Lexware;

use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;

/**
 * Reads a paged Lexware Office list endpoint (e.g. GET /v1/voucherlist, GET /v1/contacts) page by page.
 */
final readonly class PagedList
{
    private const int PAGE_SIZE = 250;

    public function __construct(
        private LexwareClient $client,
        private SensitiveDataFilter $filter,
        private string $path,
    ) {}

    /**
     * @param array<string, scalar> $query    Filter parameters; "page" and "size" are set here
     * @param int                   $maxPages Upper bound of requested pages
     *
     * @return array{list<array<mixed>>, bool} The entries and whether all pages were read (false when the page cap was hit)
     */
    public function fetch(array $query, int $maxPages): array
    {
        $entries = [];
        for ($page = 0; $page < $maxPages; ++$page) {
            $response = $this->filter->filter($this->client->getJson($this->path, [
                ...$query,
                'page' => $page,
                'size' => self::PAGE_SIZE,
            ]));

            $content = \is_array($response['content'] ?? null) ? $response['content'] : [];
            array_push($entries, ...array_values(array_filter($content, \is_array(...))));

            if (true === ($response['last'] ?? true)) {
                return [$entries, true];
            }
        }

        return [$entries, false];
    }
}
