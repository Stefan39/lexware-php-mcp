<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tool;

use Jacomeit\LexwarePhpMcp\Exception\LexwareApiException;
use Jacomeit\LexwarePhpMcp\Lexware\PagedList;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Searches Lexware Office contacts by name, e-mail, number and role. Private persons are reduced to
 * identification fields; contact details are only returned for companies.
 *
 * @phpstan-type PersonItem array{id: string, type: string, name: string, customerNumber: ?int, vendorNumber: ?int, archived: bool}
 * @phpstan-type CompanyItem array{id: string, type: string, name: string, customerNumber: ?int, vendorNumber: ?int, archived: bool, emailAddresses: list<string>, phoneNumbers: list<string>, billingAddress: ?array{street: string, zip: string, city: string, countryCode: string}, contactPersons: list<array{firstName: string, lastName: string}>}
 * @phpstan-type SearchContactsResult array{count: int, complete: bool, truncated: bool, items: list<PersonItem|CompanyItem>}
 */
final readonly class SearchContactsTool
{
    public const string NAME = 'search_contacts';

    public const string DESCRIPTION = 'Searches Lexware Office contacts by name, e-mail, customer/vendor number and role, sorted by name. '
        . 'Companies include e-mail addresses, phone numbers, billing address and contact person names; '
        . 'private persons are reduced to name, numbers and archived flag.';

    private const int MAX_LIMIT = 250;

    private const int MIN_TEXT_LENGTH = 3;

    public function __construct(
        private PagedList $contacts,
        private int $maxPages = 10,
    ) {}

    /**
     * @param ?string         $name   Part of the contact name (at least 3 characters)
     * @param ?string         $email  Part of an e-mail address (at least 3 characters)
     * @param int|string|null $number Customer or vendor number
     * @param string          $role   "customer", "vendor" or "any"
     * @param int             $limit  Number of contacts returned (1 to 250)
     *
     * @return SearchContactsResult
     */
    public function __invoke(
        #[Schema(description: 'Part of the contact name, at least 3 characters.')]
        ?string $name = null,
        #[Schema(description: 'Part of an e-mail address, at least 3 characters.')]
        ?string $email = null,
        #[Schema(description: 'Customer or vendor number (integer).')]
        int|string|null $number = null,
        #[Schema(description: 'Contact role: "customer", "vendor" or "any".')]
        string $role = 'any',
        #[Schema(description: 'Number of contacts returned, 1 to 250.')]
        int $limit = 50,
    ): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new ToolCallException(\sprintf('limit must be between 1 and %d, got %d.', self::MAX_LIMIT, $limit));
        }

        $query = array_filter([
            'name' => self::text($name, 'name'),
            'email' => self::text($email, 'email'),
            'number' => self::number($number),
            ...self::roleQuery($role),
        ], static fn(string|int|null $value): bool => null !== $value);
        if ([] === $query) {
            throw new ToolCallException('Provide at least one of name, email, number or role (customer or vendor).');
        }

        try {
            [$contacts, $complete] = $this->contacts->fetch($query, $this->maxPages);
        } catch (LexwareApiException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }

        $items = array_map(self::toItem(...), $contacts);
        usort($items, static fn(array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return [
            'count' => min($limit, \count($items)),
            'complete' => $complete,
            'truncated' => \count($items) > $limit,
            'items' => \array_slice($items, 0, $limit),
        ];
    }

    private static function text(?string $value, string $parameter): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);
        if (mb_strlen($value) < self::MIN_TEXT_LENGTH) {
            throw new ToolCallException(\sprintf('%s must have at least %d characters (Lexware requirement), got %d.', $parameter, self::MIN_TEXT_LENGTH, mb_strlen($value)));
        }

        return $value;
    }

    private static function number(int|string|null $value): ?int
    {
        if (\is_string($value)) {
            throw new ToolCallException(\sprintf('number must be an integer, got %s.', json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)));
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function roleQuery(string $role): array
    {
        return match ($role) {
            'any' => [],
            'customer' => ['customer' => 'true'],
            'vendor' => ['vendor' => 'true'],
            default => throw new ToolCallException(\sprintf('role must be "customer", "vendor" or "any", got %s.', json_encode($role, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE))),
        };
    }

    /**
     * @param array<mixed> $contact
     *
     * @return PersonItem|CompanyItem
     */
    private static function toItem(array $contact): array
    {
        $company = self::map($contact['company'] ?? null);
        $roles = self::map($contact['roles'] ?? null);

        $item = [
            'id' => self::string($contact, 'id'),
            'type' => [] !== $company ? 'company' : 'person',
            'name' => [] !== $company ? self::string($company, 'name') : self::fullName(self::map($contact['person'] ?? null)),
            'customerNumber' => self::roleNumber($roles, 'customer'),
            'vendorNumber' => self::roleNumber($roles, 'vendor'),
            'archived' => true === ($contact['archived'] ?? false),
        ];
        if ([] === $company) {
            return $item;
        }

        $billing = self::map(self::map(self::map($contact['addresses'] ?? null)['billing'] ?? null)[0] ?? null);

        return $item + [
            'emailAddresses' => self::flatStrings($contact['emailAddresses'] ?? null),
            'phoneNumbers' => self::flatStrings($contact['phoneNumbers'] ?? null),
            'billingAddress' => [] === $billing ? null : [
                'street' => self::string($billing, 'street'),
                'zip' => self::string($billing, 'zip'),
                'city' => self::string($billing, 'city'),
                'countryCode' => self::string($billing, 'countryCode'),
            ],
            'contactPersons' => array_values(array_map(
                static fn(array $contactPerson): array => [
                    'firstName' => self::string($contactPerson, 'firstName'),
                    'lastName' => self::string($contactPerson, 'lastName'),
                ],
                array_filter(self::map($company['contactPersons'] ?? null), \is_array(...)),
            )),
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<mixed> $person
     */
    private static function fullName(array $person): string
    {
        return trim(self::string($person, 'firstName') . ' ' . self::string($person, 'lastName'));
    }

    /**
     * @param array<mixed> $roles
     */
    private static function roleNumber(array $roles, string $role): ?int
    {
        $number = self::map($roles[$role] ?? null)['number'] ?? null;

        return \is_int($number) ? $number : null;
    }

    /**
     * Flattens Lexware's lists grouped by kind (business, private, ...) into one list without duplicates.
     *
     * @return list<string>
     */
    private static function flatStrings(mixed $grouped): array
    {
        $values = [];
        foreach (self::map($grouped) as $group) {
            foreach (self::map($group) as $value) {
                if (\is_string($value) && '' !== $value) {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }
}
