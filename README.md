# ISPConfig DBS DNS

ISPConfig-Erweiterung zur Verwaltung von DNS-Zonen und DNS-Records über das Domain-Bestellsystem (DBS), ohne produktive ISPConfig-Core-Dateien dauerhaft zu verändern.

## Features

- Eigene DNS-Oberfläche für Administratoren, Reseller und berechtigte Kunden
- Live-Abgleich mit DBS und lokaler Zoneninventar-Ablage
- Bearbeitung von A-, AAAA-, CNAME-, MX-, NS-, SRV- und TXT-Records
- Verschlüsselte DBS-Zugangsdaten außerhalb des Webroots
- Idempotente Installation mit Dry-Run und sicherer Migration älterer Core-Anpassungen

## Voraussetzungen / unterstützte ISPConfig-Version

- ISPConfig **3.3.1p1** (exakt; andere Versionen werden sicher abgewiesen)
- Linux mit Root-Zugriff, Bash, MariaDB-Client, `unzip` und üblichen GNU-Werkzeugen
- PHP 7.4 oder neuer mit SOAP und Sodium/XChaCha20-Poly1305
- Gültige DBS-Zugangsdaten und Zugriff auf den konfigurierten SOAP-Endpunkt

Die Repository-Metadaten zielen auf ISPConfig 3.3; der Installer dieser Version
akzeptiert zur Laufzeit ausschließlich ISPConfig 3.3.1p1.

## Installation

Nach Aufnahme in das offizielle ISPConfig Extension Repository:

```bash
sudo ispc extension install dbsdns
```

Für eine direkte Installation des GitHub-Release-Pakets dieses entpacken und den enthaltenen Installer ausführen:

```bash
release_directory="$(mktemp -d)"
unzip dbsdns-1.0.0.pkg -d "${release_directory}"
chmod 0755 "${release_directory}"
sudo bash "${release_directory}/dbsdns/scripts/install.sh" --dry-run
sudo bash "${release_directory}/dbsdns/scripts/install.sh"
```

Danach neu am Panel anmelden und unter **DBS DNS Verwaltung → Einstellungen** die Verbindung speichern und testen.

## Deployment

Vor Installation oder Update die ISPConfig-Datenbank und `/usr/local/ispconfig/security/dbsdns/credentials.key` gemeinsam sichern. Offizielle Repository-Installationen werden mit `sudo ispc extension update dbsdns` aktualisiert; bei direktem Deployment wird der Installer aus dem neuen, frisch entpackten `.pkg` erneut ausgeführt. Anschließend Verbindungstest, Zonensynchronisierung und Kundenzuweisungen prüfen.

## Abnahmetest

Die vollständige, reproduzierbare Anleitung für eine isolierte Testinstallation
steht in [docs/production-acceptance-test.md](docs/production-acceptance-test.md).
Sie deckt Installation, Update, Disable/Enable, Deinstallation, Neuinstallation,
Rollenrechte und echte DBS-SOAP-Anfragen ab.

## Release

Der Workflow setzt ein GitHub-Repository mit aktivierten Actions voraus. Ein Release auf dem bisherigen GitLab-Host löst ihn nicht aus.

1. `VERSION`, den Versionsabschnitt in `CHANGELOG.md` sowie Version und Datum in `packaging/ispconfig/repository-metadata.json` gemeinsam aktualisieren und mergen.
2. Im GitHub-Repository einen Release mit dem exakten Tag `v<VERSION>` veröffentlichen, zum Beispiel `v1.0.0`.
3. GitHub Actions prüft Tag, Tests und Paketinhalt, baut `dbsdns-<VERSION>.pkg` reproduzierbar, erzeugt den stabilen Alias `dbsdns.pkg` sowie eine SHA-256-Prüfsummendatei und hängt alle drei Assets an den Release an. Über `workflow_dispatch` können die Prüfungen vor dem Veröffentlichen manuell ausgeführt werden.
4. `dbsdns-<VERSION>.pkg`, `dbsdns.pkg`, die Prüfsummendatei und die Repository-Metadaten anschließend beim ISPConfig Extension Repository einreichen; ein öffentlicher automatisierter Einreichungsendpunkt ist derzeit nicht dokumentiert.

Ein lokaler Paket-Build ist für Releases weder vorgesehen noch erforderlich.
Die konkrete Einreichungsnachricht steht in
[docs/ispconfig-submission.md](docs/ispconfig-submission.md).

## Deinstallation

Repository-Installation: `sudo ispc extension uninstall dbsdns`. Bei direktem Deployment `scripts/uninstall.sh` aus dem passenden Release zuerst mit `--dry-run`, danach ohne Option ausführen. Moduldateien und Zuweisungen werden entfernt; Tabellen, Einstellungen und der externe Schlüssel bleiben für eine sichere Neuinstallation erhalten.

`sudo ispc extension disable dbsdns` entfernt ebenfalls Modul und Zuweisungen; `sudo ispc extension enable dbsdns` stellt sie wieder her.

## Lizenz

BSD-3-Clause, siehe [LICENSE](LICENSE).
