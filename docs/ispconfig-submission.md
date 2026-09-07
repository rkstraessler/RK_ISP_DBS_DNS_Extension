# Einreichung beim ISPConfig-Extension-Repository

Das öffentliche Repository bietet derzeit keinen dokumentierten Self-Service-
Upload für neue Extensions. Die Veröffentlichung wird deshalb vorbereitet und
anschließend mit einer kurzen Anfrage an das ISPConfig-Entwicklerteam
eingereicht.

Dieser Stand bereitet Version 1.0.1 vor. Er veröffentlicht weder einen
GitHub-Release noch den Tag `v1.0.1`; beides erfolgt erst nach der
Abnahmefreigabe.

## Vor der Anfrage

1. Änderungen in das öffentliche GitHub-Repository pushen.
2. VERSION, CHANGELOG.md und
   packaging/ispconfig/repository-metadata.json auf dieselbe Version bringen.
3. Einen GitHub-Release mit dem exakten Tag v<VERSION> veröffentlichen.
4. Den GitHub-Action-Lauf abwarten. Er muss erfolgreich sein und diese Assets
   am Release erzeugen:

   - dbsdns-<VERSION>.pkg
   - dbsdns.pkg
   - dbsdns-<VERSION>-SHA256SUMS

5. Beide Pakete und die Prüfsumme von einem unabhängigen Rechner herunterladen
   und prüfen:

```bash
VERSION=1.0.1
BASE_URL="https://github.com/rkstraessler/RK_ISP_DBS_DNS_Extension/releases/download/v$VERSION"

curl -fL "$BASE_URL/dbsdns-$VERSION.pkg" -o "dbsdns-$VERSION.pkg"
curl -fL "$BASE_URL/dbsdns.pkg" -o "dbsdns.pkg"
curl -fL "$BASE_URL/dbsdns-$VERSION-SHA256SUMS" \
  -o "dbsdns-$VERSION-SHA256SUMS"
sha256sum -c "dbsdns-$VERSION-SHA256SUMS"
```

Der Workflow kann vorher über workflow_dispatch manuell gestartet werden. In
diesem Modus werden nur Tests ausgeführt; das Release-Paket wird erst nach dem
Veröffentlichen eines passenden GitHub-Releases gebaut und hochgeladen.

## Einreichung

Die offizielle ISPConfig-Entwicklerseite nennt dev@ispconfig.org als
Kontaktadresse. Eine Nachricht kann so aussehen:

```text
Subject: New ISPConfig 3.3 extension package: DBS DNS (dbsdns)

Hello ISPConfig team,

please review and, if accepted, publish the following ISPConfig 3.3 extension:

Name: dbsdns
Title: DBS DNS
Version: <VERSION>
Repository: https://github.com/rkstraessler/RK_ISP_DBS_DNS_Extension
Release: https://github.com/rkstraessler/RK_ISP_DBS_DNS_Extension/releases/tag/v<VERSION>
Package: dbsdns-<VERSION>.pkg
Latest-package alias: dbsdns.pkg
Checksum: dbsdns-<VERSION>-SHA256SUMS

The package targets ISPConfig 3.3 and rejects runtime versions other than
ISPConfig 3.3.1p1. The release includes installation, update, disable/enable,
uninstall/reinstall and role/SOAP acceptance documentation.

The metadata JSON is attached to this message. Please let me know if the
repository requires another package name, metadata field or test environment.

Regards
<NAME>
```

Keine Zugangsdaten, privaten Schlüssel, Session-Cookies oder produktiven
Providerdaten mitsenden.

## Nach der Veröffentlichung

Erst wenn die Maintainer die Einträge veröffentlicht haben, auf einer frischen
ISPConfig-3.3.1p1-Testinstallation prüfen:

```bash
ispc extension available
ispc extension list
ispc extension install dbsdns
```

Danach die vollständige [Abnahme auf der Testinstallation](production-acceptance-test.md)
durchführen. Wenn dbsdns nicht in der verfügbaren Liste erscheint, ist die
Einreichung noch nicht live oder die Metadaten-/Paketnamen müssen mit dem
ISPConfig-Team geklärt werden.
