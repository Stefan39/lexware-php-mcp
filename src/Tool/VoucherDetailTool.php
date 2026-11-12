<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tool;

use Jacomeit\LexwarePhpMcp\Exception\LexwareApiException;
use Jacomeit\LexwarePhpMcp\Lexware\VoucherDetail;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Returns line items and net, tax and gross amounts of one voucher.
 *
 * @phpstan-import-type Detail from VoucherDetail
 */
final readonly class VoucherDetailTool
{
    public const string NAME = 'voucher_detail';

    public const string DESCRIPTION = 'Line items and net, tax and gross amounts of one Lexware Office voucher. '
        . 'Needs the id and voucherType as returned by search_vouchers or open_items.';

    public function __construct(
        private VoucherDetail $details,
    ) {}

    /**
     * @param string $id          Voucher id (UUID)
     * @param string $voucherType Lexware voucher type, e.g. "invoice" or "salesinvoice"
     *
     * @return Detail
     */
    public function __invoke(
        #[Schema(description: 'Voucher id (UUID) as returned by search_vouchers or open_items.')]
        string $id,
        #[Schema(description: 'Lexware voucher type as returned by search_vouchers, e.g. "invoice", "creditnote", "salesinvoice".')]
        string $voucherType,
    ): array {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $id)) {
            throw new ToolCallException('id must be a voucher UUID like 8-4-4-4-12 hex digits, as returned by search_vouchers.');
        }

        $path = VoucherDetail::endpoint($voucherType, $id);
        if (null === $path) {
            throw new ToolCallException(\sprintf(
                'voucherType %s is not supported. Supported types: %s.',
                json_encode($voucherType, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE),
                implode(', ', VoucherDetail::supportedTypes()),
            ));
        }

        try {
            return $this->details->fetch($voucherType, $id, $path);
        } catch (LexwareApiException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }
    }
}
