<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tool;

use Mcp\Exception\ToolCallException;

/**
 * Validates date parameters of tools (YYYY-MM-DD).
 */
final class DateParameter
{
    /**
     * @throws ToolCallException
     */
    public static function parse(string $value, string $parameter): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new ToolCallException(\sprintf('%s must be a date in the format YYYY-MM-DD, got %s.', $parameter, json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)));
        }

        return $date;
    }
}
