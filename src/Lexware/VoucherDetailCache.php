<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Lexware;

/**
 * In-memory cache of voucher details for the lifetime of the server process.
 *
 * The key is the voucher id plus the updatedDate of the voucher list entry, so a changed voucher is read again.
 * When the cache is full, the oldest entry is dropped.
 *
 * @phpstan-import-type Detail from VoucherDetail
 */
final class VoucherDetailCache
{
    public const int DEFAULT_MAX_ENTRIES = 2000;

    /** @var array<string, Detail> */
    private array $entries = [];

    public function __construct(private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES) {}

    /**
     * @return ?Detail
     */
    public function get(string $id, string $updatedDate): ?array
    {
        return $this->entries[self::key($id, $updatedDate)] ?? null;
    }

    /**
     * @param Detail $detail
     */
    public function put(string $id, string $updatedDate, array $detail): void
    {
        $key = self::key($id, $updatedDate);
        unset($this->entries[$key]);
        $this->entries[$key] = $detail;

        while (\count($this->entries) > $this->maxEntries) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }

    private static function key(string $id, string $updatedDate): string
    {
        return $id . '|' . $updatedDate;
    }
}
