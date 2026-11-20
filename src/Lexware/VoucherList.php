<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Lexware;

use Jacomeit\LexwarePhpMcp\Output\SensitiveDataFilter;

/**
 * Reads vouchers from the Lexware Office voucher list (GET /v1/voucherlist), page by page.
 */
final readonly class VoucherList
{
    private PagedList $pages;

    public function __construct(LexwareClient $client, SensitiveDataFilter $filter)
    {
        $this->pages = new PagedList($client, $filter, '/v1/voucherlist');
    }

    /**
     * @param array<string, scalar> $query    Filter parameters; "page" and "size" are set here
     * @param int                   $maxPages Upper bound of requested pages
     *
     * @return array{list<array<mixed>>, bool} The vouchers and whether all pages were read (false when the page cap was hit)
     */
    public function fetch(array $query, int $maxPages): array
    {
        return $this->pages->fetch($query, $maxPages);
    }
}
