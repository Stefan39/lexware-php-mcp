# lexware-php-mcp

[English](README.md) | [Deutsch](README.de.md)

[![CI](https://github.com/Stefan39/lexware-php-mcp/actions/workflows/ci.yml/badge.svg)](https://github.com/Stefan39/lexware-php-mcp/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP 8.4+](https://img.shields.io/badge/php-8.4%2B-777bb4.svg)

> **Unofficial.** Not affiliated with Haufe Group. Lexware and lexoffice are trademarks of Haufe Group.

lexware-php-mcp is an unofficial, read-only [Model Context Protocol](https://modelcontextprotocol.io) server for
[Lexware Office](https://www.lexware.de/) (formerly lexoffice), written in PHP on top of the
official [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk).

Ask Claude things like *"Which invoices are overdue?"* and get answers straight from your account.

**No PHP needed:** for Claude Desktop on macOS and Windows there is a one-click extension, see
[Quick start](#quick-start-claude-desktop-no-php-needed).

## Why a PHP server?

The existing Lexware MCP servers are written in TypeScript or Python. This one is the PHP-native option, and
that is the point: it is not a claim that PHP is technically better, but that PHP shops should not need a second
runtime to give an AI assistant access to their bookkeeping.

- **One runtime you already run, or none at all.** PHP 8.4 and Composer, nothing else. No Node.js or Python
  toolchain, no `npx` downloading code at startup. Without PHP, use the standalone download, which has PHP
  built in.
- **Small enough to audit.** The server has three runtime dependencies (`mcp/sdk`, `symfony/http-client`,
  `symfony/clock`) and a handful of classes. Anyone handing an AI access to accounting data can read all of it
  in an afternoon. It is checked with PHPStan level 8 including strict rules.
- **Built for the German Mittelstand stack.** Many agencies, freelancers and in-house IT teams around Lexware
  work in PHP (Symfony, Laravel, WordPress, Shopware). Tools are plain classes, so they can later move into a
  Symfony application via `symfony/mcp-bundle` (see [ADR 0001](docs/adr/0001-architecture.md)).
- **Lexware pitfalls handled, not left to you.** Invoices written in Lexware and booked sales vouchers are two
  different voucher types; the server queries both. Lexware's 2 requests per second limit is throttled, and
  Lexware's error messages are passed through so a failure is explainable.

## Example questions

- *Which invoices are overdue, and by how many days?*
- *Show open invoices for customer Müller, with totals per currency.*

## Status

v0.3.0, early but usable. See the [changelog](CHANGELOG.md). Currently available tools:

| Tool | What it does |
|---|---|
| `open_items` | Unpaid sales invoices (open and overdue), oldest due date first, with days overdue and totals per currency. Optional filters: `overdueOnly`, `contact`. Amounts are gross. |
| `search_vouchers` | Search vouchers by type, status, date range, contact and number, newest first. Optional parameters: `voucherType`, `voucherStatus`, `dateFrom`, `dateTo`, `voucherNumber`, `contact`, `limit` (default 50, max 250). Amounts are gross. |

Planned: voucher detail with net amounts, contact search, revenue per period.
See [ADR 0001](docs/adr/0001-architecture.md).

## Safety

- **Read-only.** The server only sends `GET` requests; there are no tools that create or change data.
- The API key is read from the environment only and never appears in output or error messages.
- Bank details (IBAN, BIC, account data) are stripped from every response before it reaches the model.
- Requests are throttled to the Lexware limit of 2 per second, with retry and backoff on 429/5xx.
- Data returned by a tool is sent to the model provider of your MCP client (e.g. Anthropic for
  Claude Desktop). Only use it with accounts whose data you may share with that provider.

Found a security problem? Please report it privately, see [SECURITY.md](SECURITY.md).

Use of the Lexware API is governed by the
[Public API Lizenz- und Nutzungsbedingungen](https://agb.lexware.de/lexware-office/public-api-lizenz--und-nutzungsbedingungen).

## Quick start: Claude Desktop, no PHP needed

You need a Lexware Office public API key: <https://app.lexware.de/addons/public-api>.
Nothing else has to be installed; the download contains everything, PHP included.

1. Download the extension for your system from the [latest release](https://github.com/Stefan39/lexware-php-mcp/releases/latest):
   - macOS (Apple silicon and Intel): [`lexware-php-mcp-macos.mcpb`](https://github.com/Stefan39/lexware-php-mcp/releases/latest/download/lexware-php-mcp-macos.mcpb)
   - Windows: [`lexware-php-mcp-windows.mcpb`](https://github.com/Stefan39/lexware-php-mcp/releases/latest/download/lexware-php-mcp-windows.mcpb)
2. Double-click the file. Claude Desktop opens and shows the extension. If double-clicking does not work,
   open *Settings > Extensions > Advanced settings > Install Extension…* in Claude Desktop and pick the file.
3. Click *Install* and paste your API key when asked. Claude Desktop stores it in its secure storage;
   it does not end up in a configuration file.
   Claude Desktop shows a warning before installing because the extension is not signed. Signing costs
   money for a certificate, which this free project does without; the code is open for review on GitHub.
   Confirm with *Install*.
4. Make sure the extension is switched on, then ask: *"Which invoices are overdue?"*

To update, download the new `.mcpb` and install it the same way.

The Windows extension is built and tested automatically (start-up and a TLS connection to the Lexware API),
but the maintainer has not tried it on a Windows PC. Reports are welcome as an
[issue](https://github.com/Stefan39/lexware-php-mcp/issues).

## Standalone binary: Claude Code and other MCP clients

Every release also contains the server as a single executable file, again with PHP built in:

| System | File |
|---|---|
| macOS, Apple silicon | `lexware-php-mcp-macos-arm64` |
| macOS, Intel | `lexware-php-mcp-macos-x86_64` |
| Linux, x86_64 | `lexware-php-mcp-linux-x86_64` |
| Linux, ARM64 | `lexware-php-mcp-linux-arm64` |
| Windows, x86_64 | `lexware-php-mcp-windows-x86_64.exe` |

`SHA256SUMS` in the release lets you verify the download (`shasum -a 256 -c SHA256SUMS --ignore-missing`).

On macOS and Linux, make the file executable. The binaries are not signed, so on macOS also remove the
download quarantine, otherwise Gatekeeper refuses to start the file:

```bash
chmod +x lexware-php-mcp-macos-arm64
xattr -d com.apple.quarantine lexware-php-mcp-macos-arm64   # macOS only
```

Register it with Claude Code:

```bash
claude mcp add --env LEXWARE_API_KEY=your-api-key lexware-php-mcp -- /absolute/path/to/lexware-php-mcp-macos-arm64
```

By default the entry applies to the current project only. Add `--scope user` to make it available
everywhere. Check with `claude mcp list`, or run `/mcp` inside a session. The key is stored in plain text in
Claude Code's configuration; keep it private.

Linux needs the usual CA certificates for HTTPS (package `ca-certificates`, installed on desktop
systems anyway).

## Installation from source (PHP developers)

If you already work with PHP, you can run the server from source instead.

Requirements:

- PHP 8.4 or newer (CLI) with `ext-json`
- Composer

```bash
git clone --branch v0.3.0 https://github.com/Stefan39/lexware-php-mcp.git
cd lexware-php-mcp
composer install --no-dev
```

To update later: `git fetch --tags && git checkout <new-tag> && composer install --no-dev`.

A `lexware-php-mcp.phar` is attached to every release as well; it runs with `php lexware-php-mcp.phar`.

### Claude Desktop with a manual configuration

The extension above is the easier way. If you prefer to configure the server yourself, add **one entry**
under `mcpServers` in `claude_desktop_config.json`:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

The file usually contains other settings already. Do not paste a second `{ ... }` block at the end:
add the `"lexware-php-mcp"` entry inside the existing `"mcpServers"` object, or create
`"mcpServers"` as a new top-level key if it is missing. Check the result with
`python3 -m json.tool claude_desktop_config.json` before restarting Claude Desktop.

#### macOS: API key from the Keychain

Store the key once; the command prompts for it, so it never ends up in your shell history:

```bash
security add-generic-password -s lexware-php-mcp -a lexware -w
```

Entry for `mcpServers` (adjust both paths; `which php` shows yours). With a standalone binary, replace
`/opt/homebrew/bin/php /absolute/path/to/lexware-php-mcp/bin/lexware-php-mcp` by the path of the binary:

```json
"lexware-php-mcp": {
  "command": "/bin/sh",
  "args": ["-c", "LEXWARE_API_KEY=$(security find-generic-password -s lexware-php-mcp -w) exec /opt/homebrew/bin/php /absolute/path/to/lexware-php-mcp/bin/lexware-php-mcp"]
}
```

#### Other systems: API key in the config

```json
"lexware-php-mcp": {
  "command": "php",
  "args": ["/absolute/path/to/lexware-php-mcp/bin/lexware-php-mcp"],
  "env": {
    "LEXWARE_API_KEY": "your-api-key"
  }
}
```

The key is then stored in plain text in that file; keep it private.

Quit Claude Desktop completely, start it again and ask: *"Which invoices are overdue?"*
If the server does not show up, check the log
(macOS: `~/Library/Logs/Claude/mcp-server-lexware-php-mcp.log`).

### Claude Code from source

```bash
claude mcp add --env LEXWARE_API_KEY=your-api-key lexware-php-mcp -- php /absolute/path/to/lexware-php-mcp/bin/lexware-php-mcp
```

## Configuration

| Variable | Required | Default |
|---|---|---|
| `LEXWARE_API_KEY` | yes | none |
| `LEXWARE_API_BASE_URL` | no | `https://api.lexware.io` |

## Development

```bash
composer install
composer check   # php-cs-fixer (dry run), PHPStan level 8, PHPUnit
make cpd         # duplication check (needs Node/npx)
```

Debug the server interactively with the MCP Inspector. The Inspector starts the server with a
minimal environment, so pass the key with `-e`; a variable set in your shell does not reach it:

```bash
npx @modelcontextprotocol/inspector -e LEXWARE_API_KEY=your-api-key php bin/lexware-php-mcp
```

Tests never touch the real API; they use Symfony's `MockHttpClient` and `MockClock`.

### Standalone builds

The workflow `.github/workflows/release.yml` builds the PHAR ([Box](https://github.com/box-project/box),
config `box.json`), the static binaries ([static-php-cli](https://github.com/crazywhalecc/static-php-cli):
PHP plus the PHAR in one file) and the Claude Desktop extensions (`build-tools/package-mcpb.sh`, manifest
in `build-tools/mcpb/`). It runs on pull requests that touch the build, and on a version tag it attaches
everything to a draft release. Check any build with `build-tools/smoke-test.sh <command>`; with
`SMOKE_TEST_TLS=1` it also sends one request with an invalid key to the real API and expects HTTP 401.
Keep `version` in `build-tools/mcpb/manifest.json` in line with `ServerFactory::VERSION` (a test checks it).

## License

MIT, see [LICENSE](LICENSE).
