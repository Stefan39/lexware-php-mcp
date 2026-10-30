# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/). Until 1.0, minor versions may
contain breaking changes.

## [Unreleased]

### Added

- Tool `search_vouchers`: search the voucher list by type, status, date range, contact and voucher
  number (amounts are gross, bank details are removed).

### Changed

- README: the quick start explains the Claude Desktop warning for the unsigned extension, so users do
  not abort the installation. ADR 0001 records the decision not to sign for now.

## [0.3.0] - 2026-10-16

### Added

- Setup without PHP: every release now contains a Claude Desktop extension (`.mcpb`) for macOS and
  Windows (one-click install, the API key is entered in Claude Desktop and stored securely), standalone
  binaries with PHP built in for macOS, Linux and Windows, a PHAR and `SHA256SUMS` (JAC-721).
- Release workflow that builds and smoke-tests these files and attaches them to a draft release.

- German README (`README.de.md`) and a language switch in both READMEs.
- README section "Why a PHP server?", CI/license/PHP badges and example questions.
- Installation guide for Claude Code (`claude mcp add`).

### Fixed

- HTTPS in the standalone binaries: the server finds the system's CA certificates on Linux
  distributions where the static OpenSSL default path is missing (Debian, Ubuntu, Fedora, openSUSE)
  and uses the certificate store on Windows. Regular PHP installations are unaffected.

## [0.2.0] - 2026-10-11

### Changed

- Project renamed to **lexware-php-mcp** (package `jacomeit/lexware-php-mcp`, repository
  `Stefan39/lexware-php-mcp`). Breaking for existing installations:
  - The binary is now `bin/lexware-php-mcp`; update the path in your MCP client configuration.
  - The MCP server reports its name as `lexware-php-mcp`; rename the `mcpServers` entry accordingly.
  - The PHP namespace is now `Jacomeit\LexwarePhpMcp`.
  - The README uses the Keychain service `lexware-php-mcp`; store the key again under that name or
    keep your own service name in the config.

## [0.1.0] - 2026-10-10

First release.

### Added

- MCP server over stdio for Claude Desktop and other MCP clients, built on the official PHP MCP SDK (`mcp/sdk` 0.8).
- Tool `open_items`: unpaid invoices (written in Lexware and booked sales vouchers), oldest due date
  first, with overdue flag, days overdue and totals per currency. Filters `overdueOnly` and `contact`.
  Amounts are gross.
- Read-only API client: GET only, throttled to 2 requests per second, retry with backoff on 429, 5xx
  and network errors, Lexware error messages surfaced in tool errors.
- Bank details (IBAN, BIC, account data) are removed from every response.
- API key only via the `LEXWARE_API_KEY` environment variable; setup with the macOS Keychain documented.
- Architecture decision record, security policy, CI (PHP 8.4 and 8.5, PHPStan level 8, php-cs-fixer, jscpd).

[Unreleased]: https://github.com/Stefan39/lexware-php-mcp/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/Stefan39/lexware-php-mcp/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Stefan39/lexware-php-mcp/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Stefan39/lexware-php-mcp/releases/tag/v0.1.0
