# lexware-php-mcp

[English](README.md) | [Deutsch](README.de.md)

[![CI](https://github.com/Stefan39/lexware-php-mcp/actions/workflows/ci.yml/badge.svg)](https://github.com/Stefan39/lexware-php-mcp/actions/workflows/ci.yml)
[![Lizenz: MIT](https://img.shields.io/badge/lizenz-MIT-blue.svg)](LICENSE)
![PHP 8.4+](https://img.shields.io/badge/php-8.4%2B-777bb4.svg)

> **Inoffiziell.** Keine Verbindung zur Haufe Group. Lexware und lexoffice sind Marken der Haufe Group.

lexware-php-mcp ist ein inoffizieller, rein lesender [Model-Context-Protocol](https://modelcontextprotocol.io)-Server
für [Lexware Office](https://www.lexware.de/) (früher lexoffice). Er ist in PHP geschrieben und baut auf dem
offiziellen [PHP-MCP-SDK](https://github.com/modelcontextprotocol/php-sdk) auf.

Fragen Sie Claude zum Beispiel *„Welche Rechnungen sind überfällig?“* und erhalten Sie die Antwort direkt aus Ihrem Konto.

**Kein PHP nötig:** Für Claude Desktop unter macOS und Windows gibt es eine Erweiterung zum Doppelklicken, siehe
[Schnellstart](#schnellstart-claude-desktop-ohne-php).

## Warum ein PHP-Server?

Die vorhandenen Lexware-MCP-Server sind in TypeScript oder Python geschrieben. Dieser ist die PHP-native
Variante. Das heißt nicht, dass PHP technisch besser wäre. Es geht darum, dass PHP-Betriebe keine zweite
Laufzeitumgebung brauchen sollen, um einem KI-Assistenten Zugriff auf ihre Buchhaltung zu geben.

- **Eine Laufzeit, die Sie ohnehin betreiben, oder gar keine.** PHP 8.4 und Composer, mehr nicht. Keine
  Node.js- oder Python-Toolchain, kein `npx`, das beim Start Code nachlädt. Ohne PHP nehmen Sie den
  eigenständigen Download, in den PHP schon eingebaut ist.
- **Klein genug zum Prüfen.** Der Server hat drei Laufzeit-Abhängigkeiten (`mcp/sdk`, `symfony/http-client`,
  `symfony/clock`) und nur wenige Klassen. Wer einer KI Zugriff auf Buchhaltungsdaten gibt, kann den gesamten
  Code an einem Nachmittag lesen. Geprüft wird er mit PHPStan Level 8 samt Strict Rules.
- **Passend zum deutschen Mittelstands-Stack.** Viele Agenturen, Freiberufler und interne IT-Abteilungen rund um
  Lexware arbeiten mit PHP (Symfony, Laravel, WordPress, Shopware). Die Werkzeuge sind einfache Klassen und
  lassen sich später über `symfony/mcp-bundle` in eine Symfony-Anwendung übernehmen
  (siehe [ADR 0001](docs/adr/0001-architecture.md), englisch).
- **Lexware-Fallstricke sind abgefangen.** In Lexware geschriebene Rechnungen und gebuchte Verkaufsbelege sind
  zwei verschiedene Belegarten; der Server fragt beide ab. Das Limit von 2 Anfragen pro Sekunde wird
  eingehalten, und Fehlermeldungen von Lexware werden durchgereicht, damit ein Fehler erklärbar bleibt.

## Beispielfragen

- *Welche Rechnungen sind überfällig, und um wie viele Tage?*
- *Zeige die offenen Rechnungen von Kunde Müller mit Summen je Währung.*

## Status

v0.3.0, früh, aber nutzbar. Siehe das [Changelog](CHANGELOG.md) (englisch). Aktuell verfügbare Werkzeuge:

| Werkzeug | Was es tut |
|---|---|
| `open_items` | Unbezahlte Ausgangsrechnungen (offen und überfällig), älteste Fälligkeit zuerst, mit Tagen überfällig und Summen je Währung. Optionale Filter: `overdueOnly`, `contact`. Beträge sind brutto. |
| `search_vouchers` | Belege nach Typ, Status, Zeitraum, Kontakt und Nummer suchen, neueste zuerst. Optionale Parameter: `voucherType`, `voucherStatus`, `dateFrom`, `dateTo`, `voucherNumber`, `contact`, `limit` (Standard 50, max. 250). Beträge sind brutto. |

Geplant: Beleg-Detail mit Nettobeträgen, Kontaktsuche, Umsatz je Zeitraum.
Siehe [ADR 0001](docs/adr/0001-architecture.md).

## Sicherheit

- **Nur lesend.** Der Server sendet ausschließlich `GET`-Anfragen; es gibt keine Werkzeuge, die Daten anlegen oder ändern.
- Der API-Schlüssel wird nur aus der Umgebung gelesen und erscheint nie in Ausgaben oder Fehlermeldungen.
- Bankdaten (IBAN, BIC, Kontodaten) werden aus jeder Antwort entfernt, bevor sie das Modell erreicht.
- Anfragen werden auf das Lexware-Limit von 2 pro Sekunde gedrosselt, mit Wiederholung und Backoff bei 429/5xx.
- Was ein Werkzeug zurückgibt, geht an den Modellanbieter Ihres MCP-Clients (z. B. Anthropic bei
  Claude Desktop). Nutzen Sie den Server nur mit Konten, deren Daten Sie an diesen Anbieter weitergeben dürfen.

Sicherheitsproblem entdeckt? Bitte melden Sie es vertraulich, siehe [SECURITY.md](SECURITY.md).

Für die Nutzung der Lexware-API gelten die
[Public API Lizenz- und Nutzungsbedingungen](https://agb.lexware.de/lexware-office/public-api-lizenz--und-nutzungsbedingungen).

## Schnellstart: Claude Desktop, ohne PHP

Sie brauchen einen öffentlichen API-Schlüssel für Lexware Office: <https://app.lexware.de/addons/public-api>.
Sonst müssen Sie nichts installieren; der Download enthält alles, auch PHP.

1. Laden Sie die Erweiterung für Ihr System aus dem [aktuellen Release](https://github.com/Stefan39/lexware-php-mcp/releases/latest):
   - macOS (Apple-Chip und Intel): [`lexware-php-mcp-macos.mcpb`](https://github.com/Stefan39/lexware-php-mcp/releases/latest/download/lexware-php-mcp-macos.mcpb)
   - Windows: [`lexware-php-mcp-windows.mcpb`](https://github.com/Stefan39/lexware-php-mcp/releases/latest/download/lexware-php-mcp-windows.mcpb)
2. Doppelklicken Sie die Datei. Claude Desktop öffnet sich und zeigt die Erweiterung an. Klappt der Doppelklick
   nicht, öffnen Sie in Claude Desktop *Einstellungen > Erweiterungen > Erweiterte Einstellungen > Erweiterung
   installieren…* und wählen die Datei aus.
3. Klicken Sie auf *Installieren* und fügen Sie Ihren API-Schlüssel ein, wenn danach gefragt wird. Claude Desktop
   legt ihn in seinem sicheren Speicher ab; er landet in keiner Konfigurationsdatei.
   Vor der Installation zeigt Claude Desktop eine Warnung, weil die Erweiterung nicht signiert ist. Eine Signatur
   kostet Geld für ein Zertifikat, darauf verzichtet dieses kostenlose Projekt; der Code ist auf GitHub offen
   einsehbar. Bestätigen Sie mit *Installieren*.
4. Achten Sie darauf, dass die Erweiterung eingeschaltet ist, und fragen Sie: *„Welche Rechnungen sind überfällig?“*

Zum Aktualisieren laden Sie die neue `.mcpb` herunter und installieren sie auf dieselbe Weise.

Die Windows-Erweiterung wird automatisch gebaut und getestet (Start und TLS-Verbindung zur Lexware-API),
der Autor hat sie aber nicht selbst auf einem Windows-PC ausprobiert. Rückmeldungen gern als
[Issue](https://github.com/Stefan39/lexware-php-mcp/issues).

## Einzelne Programmdatei: Claude Code und andere MCP-Clients

Jedes Release enthält den Server außerdem als einzelne ausführbare Datei, ebenfalls mit eingebautem PHP:

| System | Datei |
|---|---|
| macOS, Apple-Chip | `lexware-php-mcp-macos-arm64` |
| macOS, Intel | `lexware-php-mcp-macos-x86_64` |
| Linux, x86_64 | `lexware-php-mcp-linux-x86_64` |
| Linux, ARM64 | `lexware-php-mcp-linux-arm64` |
| Windows, x86_64 | `lexware-php-mcp-windows-x86_64.exe` |

Mit `SHA256SUMS` aus dem Release prüfen Sie den Download (`shasum -a 256 -c SHA256SUMS --ignore-missing`).

Unter macOS und Linux machen Sie die Datei ausführbar. Die Dateien sind nicht signiert; unter macOS entfernen Sie
deshalb auch die Download-Quarantäne, sonst verweigert Gatekeeper den Start:

```bash
chmod +x lexware-php-mcp-macos-arm64
xattr -d com.apple.quarantine lexware-php-mcp-macos-arm64   # nur macOS
```

Registrieren in Claude Code:

```bash
claude mcp add --env LEXWARE_API_KEY=ihr-api-schluessel lexware-php-mcp -- /absoluter/pfad/zu/lexware-php-mcp-macos-arm64
```

Standardmäßig gilt der Eintrag nur für das aktuelle Projekt. Mit `--scope user` ist er überall verfügbar.
Prüfen Sie mit `claude mcp list` oder mit `/mcp` in einer Sitzung. Der Schlüssel liegt im Klartext in der
Konfiguration von Claude Code; halten Sie sie privat.

Unter Linux braucht HTTPS die üblichen CA-Zertifikate (Paket `ca-certificates`, auf Desktop-Systemen ohnehin
installiert).

## Installation aus dem Quellcode (für PHP-Entwickler)

Wer ohnehin mit PHP arbeitet, kann den Server auch aus dem Quellcode betreiben.

Voraussetzungen:

- PHP 8.4 oder neuer (CLI) mit `ext-json`
- Composer

```bash
git clone --branch v0.3.0 https://github.com/Stefan39/lexware-php-mcp.git
cd lexware-php-mcp
composer install --no-dev
```

Aktualisieren später: `git fetch --tags && git checkout <neuer-tag> && composer install --no-dev`.

Jedem Release liegt außerdem `lexware-php-mcp.phar` bei; es läuft mit `php lexware-php-mcp.phar`.

### Claude Desktop mit eigener Konfiguration

Die Erweiterung oben ist der einfachere Weg. Wenn Sie den Server lieber selbst eintragen, ergänzen Sie
**einen Eintrag** unter `mcpServers` in der `claude_desktop_config.json`:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

Die Datei enthält meist schon andere Einstellungen. Hängen Sie keinen zweiten `{ ... }`-Block ans Ende an:
Tragen Sie den Eintrag `"lexware-php-mcp"` in das vorhandene Objekt `"mcpServers"` ein, oder legen Sie
`"mcpServers"` als neuen Schlüssel auf oberster Ebene an, falls er fehlt. Prüfen Sie das Ergebnis mit
`python3 -m json.tool claude_desktop_config.json`, bevor Sie Claude Desktop neu starten.

#### macOS: API-Schlüssel aus dem Schlüsselbund

Speichern Sie den Schlüssel einmal; der Befehl fragt ihn ab, er landet also nicht in Ihrer Shell-Historie:

```bash
security add-generic-password -s lexware-php-mcp -a lexware -w
```

Eintrag für `mcpServers` (beide Pfade anpassen; `which php` zeigt Ihren). Mit der einzelnen Programmdatei
ersetzen Sie `/opt/homebrew/bin/php /absoluter/pfad/zu/lexware-php-mcp/bin/lexware-php-mcp` durch deren Pfad:

```json
"lexware-php-mcp": {
  "command": "/bin/sh",
  "args": ["-c", "LEXWARE_API_KEY=$(security find-generic-password -s lexware-php-mcp -w) exec /opt/homebrew/bin/php /absoluter/pfad/zu/lexware-php-mcp/bin/lexware-php-mcp"]
}
```

#### Andere Systeme: API-Schlüssel in der Konfiguration

```json
"lexware-php-mcp": {
  "command": "php",
  "args": ["/absoluter/pfad/zu/lexware-php-mcp/bin/lexware-php-mcp"],
  "env": {
    "LEXWARE_API_KEY": "ihr-api-schluessel"
  }
}
```

Der Schlüssel steht dann im Klartext in dieser Datei; halten Sie sie privat.

Beenden Sie Claude Desktop vollständig, starten Sie es neu und fragen Sie: *„Welche Rechnungen sind überfällig?“*
Erscheint der Server nicht, hilft das Log
(macOS: `~/Library/Logs/Claude/mcp-server-lexware-php-mcp.log`).

### Claude Code aus dem Quellcode

```bash
claude mcp add --env LEXWARE_API_KEY=ihr-api-schluessel lexware-php-mcp -- php /absoluter/pfad/zu/lexware-php-mcp/bin/lexware-php-mcp
```

## Konfiguration

| Variable | Pflicht | Standard |
|---|---|---|
| `LEXWARE_API_KEY` | ja | keiner |
| `LEXWARE_API_BASE_URL` | nein | `https://api.lexware.io` |

## Entwicklung

```bash
composer install
composer check   # php-cs-fixer (Dry-Run), PHPStan Level 8, PHPUnit
make cpd         # Duplikatprüfung (braucht Node/npx)
```

Den Server interaktiv debuggen Sie mit dem MCP Inspector. Der Inspector startet den Server mit einer
minimalen Umgebung, übergeben Sie den Schlüssel daher mit `-e`; eine in Ihrer Shell gesetzte Variable kommt dort nicht an:

```bash
npx @modelcontextprotocol/inspector -e LEXWARE_API_KEY=ihr-api-schluessel php bin/lexware-php-mcp
```

Die Tests greifen nie auf die echte API zu; sie nutzen `MockHttpClient` und `MockClock` von Symfony.

### Eigenständige Builds

Der Workflow `.github/workflows/release.yml` baut das PHAR ([Box](https://github.com/box-project/box),
Konfiguration `box.json`), die statischen Programmdateien ([static-php-cli](https://github.com/crazywhalecc/static-php-cli):
PHP und das PHAR in einer Datei) und die Claude-Desktop-Erweiterungen (`build-tools/package-mcpb.sh`, Manifest
in `build-tools/mcpb/`). Er läuft bei Pull Requests, die den Build ändern, und hängt bei einem Versions-Tag alles
an einen Release-Entwurf. Jeden Build prüfen Sie mit `build-tools/smoke-test.sh <befehl>`; mit `SMOKE_TEST_TLS=1`
schickt er zusätzlich eine Anfrage mit ungültigem Schlüssel an die echte API und erwartet HTTP 401.
Halten Sie `version` in `build-tools/mcpb/manifest.json` gleich mit `ServerFactory::VERSION` (ein Test prüft das).

## Lizenz

MIT, siehe [LICENSE](LICENSE).
