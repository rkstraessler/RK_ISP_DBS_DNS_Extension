# Abnahme auf einer isolierten ISPConfig-Testinstallation

Diese Anleitung ist der manuelle Abnahmetest für DBS DNS. Sie ist für eine
wegwerfbare Test-VM oder einen Snapshot gedacht, nicht für eine produktive
ISPConfig- oder DBS-Installation.

Die native ispc-extension-Strecke kann erst geprüft werden, wenn dbsdns im
offiziellen ISPConfig-Extension-Repository veröffentlicht ist. Bis dahin wird
der identische Laufzeit-Installer direkt aus dem GitHub-Release-Paket getestet.

## Abnahmekriterien

Der Test ist nur bestanden, wenn alle Punkte erfüllt sind:

- Der Installer akzeptiert ISPConfig 3.3.1p1 und verändert nach der Installation
  keine der fünf verwalteten ISPConfig-Core-Dateien.
- Installation, Update, Disable, Enable, Deinstallation und Neuinstallation
  funktionieren ohne manuelle Reparatur.
- Einstellungen, verschlüsseltes Passwort, Cache und externer Schlüssel
  bleiben bei Disable und Deinstallation erhalten. Modulzuweisungen werden
  entfernt und bei der Neuinstallation anhand der Berechtigungen erneut gesetzt.
- Ein Admin, ein berechtigter Reseller und ein berechtigter Kunde sehen genau
  die erwarteten Zonen; ein nicht berechtigter Kunde erhält keinen Zugriff.
- Die UI löst echte DBS-SOAP-Anfragen aus und bestätigt den Zustand beim
  Provider. Ein Mock oder ausschließlich ein Unit-Test reicht hierfür nicht.
- Nach jedem Lifecycle-Schritt bleiben ISPConfig-Core-Dateien und Logs fehlerfrei.

## 1. Testsystem vorbereiten

Verwende eine frische VM mit Snapshot vor der ersten Installation. Nutze einen
DBS-Staging-Account oder eine ausdrücklich für Tests freigegebene Zone. Keine
produktiven Domains, keine produktiven Zugangsdaten und keine echten
Kundenkonten verwenden.

Als root oder mit sudo:

```bash
sudo -i
TEST_ROOT=/root/dbsdns-acceptance
mkdir -p "$TEST_ROOT"
chmod 700 "$TEST_ROOT"

php -v
php -m | grep -E '^(soap|sodium)$'
test -f /usr/local/ispconfig/interface/lib/config.inc.php
grep -nE 'db_name|database' /usr/local/ispconfig/interface/lib/config.inc.php
```

Die letzte Ausgabe dient nur dazu, den Namen der ISPConfig-Datenbank zu
ermitteln. Danach den Platzhalter durch den tatsächlich angezeigten Namen
ersetzen:

```bash
ISPC_DB_NAME='REPLACE_WITH_ISPCONFIG_DB_NAME'
```

Erwartet werden PHP 7.4 oder neuer sowie die Erweiterungen soap und sodium.
Passwörter niemals in Befehlszeilen, Screenshots, Tickets oder Git-Dateien
ablegen.

Die Testquelle darf unter `/root` liegen und dort Root-only (`0700`) bleiben.
Das ist der beabsichtigte Sicherheitszustand. Ein erfolgreicher
Installationslauf liest diese Quelle als Root. Kein
`chmod 0755` auf `/root`, das Release-Verzeichnis oder die Quellbäume
ausführen. Die bestätigte frühere Fehlerursache war ein `cp`, das die Quelle
unter `0700` über `runuser` als Panel-Benutzer lesen sollte; dadurch scheiterte
die Installation trotz korrekter Zielberechtigungen.

Vor dem Installationslauf festhalten, dass der geschützte Bereich und der
Webroot dasselbe Dateisystem verwenden:

```bash
SECURITY_ROOT='/usr/local/ispconfig/security'
WEB_ROOT='/usr/local/ispconfig/interface/web'
stat -c '%d %n' "$SECURITY_ROOT" "$WEB_ROOT"
test "$(stat -c '%d' "$SECURITY_ROOT")" = "$(stat -c '%d' "$WEB_ROOT")"
```

## 2. Baseline und Snapshot

Die Baseline muss vor der Erweiterung auf der unveränderten ISPConfig-Version
3.3.1p1 aufgenommen werden:

```bash
mysqldump --single-transaction --routines --triggers "$ISPC_DB_NAME" \
  > "$TEST_ROOT/ispconfig-before.sql"

if test -f /usr/local/ispconfig/security/dbsdns/credentials.key; then
  cp -a /usr/local/ispconfig/security/dbsdns/credentials.key \
    "$TEST_ROOT/credentials.key.before"
fi

sha256sum \
  /usr/local/ispconfig/interface/web/dns/dns_soa_list.php \
  /usr/local/ispconfig/interface/web/dns/dns_soa_edit.php \
  /usr/local/ispconfig/interface/web/dns/dns_a_edit.php \
  /usr/local/ispconfig/interface/web/dns/dns_rr_del.php \
  /usr/local/ispconfig/interface/web/dns/dns_edit_base.php \
  > "$TEST_ROOT/ispconfig-core.before.sha256"
```

Zusätzlich im Hypervisor einen Snapshot mit dem Namen
ispconfig-3.3.1p1-before-dbsdns anlegen. Wenn eine der fünf Dateien bereits
verändert ist, den Test nicht als Clean-Install-Abnahme werten, sondern zuerst
einen sauberen Snapshot herstellen.

## 2a. Patch vor Veröffentlichung auf config-dev prüfen

Solange `v1.0.1` noch nicht veröffentlicht wurde, kann der vorbereitete
Commit mit demselben Installer geprüft werden. Die vollständige Commit-SHA
aus dem Änderungsbericht einsetzen; einen frischen Snapshot und den
Root-only-Testpfad aus Abschnitt 1 verwenden:

```bash
PATCH_COMMIT='<vollständige Commit-SHA>'
PATCH_SOURCE="$TEST_ROOT/source-1.0.1"
git clone --no-checkout https://github.com/rkstraessler/RK_ISP_DBS_DNS_Extension.git "$PATCH_SOURCE"
git -C "$PATCH_SOURCE" checkout --detach "$PATCH_COMMIT"
test "$(stat -c '%a' "$TEST_ROOT")" = '700'
bash "$PATCH_SOURCE/scripts/install.sh" --dry-run
bash "$PATCH_SOURCE/scripts/install.sh"
```

Danach Abschnitt 3a und die UI-/SOAP-/Rollenprüfungen aus Abschnitt 4 und 5
ausführen. Für den Lebenszyklus denselben Installer erneut ausführen,
anschließend `scripts/uninstall.sh` aus dieser Quelle zuerst mit `--dry-run`,
dann ohne diese Option, und schließlich erneut installieren. Schlüsselhash,
gespeicherte Credentials und Core-Prüfsummen müssen erhalten bleiben.
Diese Quellcodeprüfung ersetzt nicht die spätere Abnahme des tatsächlichen
Release-Pakets aus Abschnitt 3. Einen lokalen Paket-Build oder Release-Tag
erzeugt sie nicht.

## 3. Release-Paket prüfen und direkt installieren

Für diesen Pfad wird das versionierte Paket verwendet. VERSION muss auf die
tatsächlich zu prüfende Release-Version zeigen:

```bash
VERSION=1.0.1
RELEASE_BASE_URL="https://github.com/rkstraessler/RK_ISP_DBS_DNS_Extension/releases/download/v$VERSION"

curl -fL "$RELEASE_BASE_URL/dbsdns-$VERSION.pkg" \
  -o "$TEST_ROOT/dbsdns-$VERSION.pkg"
curl -fL "$RELEASE_BASE_URL/dbsdns.pkg" \
  -o "$TEST_ROOT/dbsdns.pkg"
curl -fL "$RELEASE_BASE_URL/dbsdns-$VERSION-SHA256SUMS" \
  -o "$TEST_ROOT/dbsdns-$VERSION-SHA256SUMS"

cd "$TEST_ROOT"
sha256sum -c "dbsdns-$VERSION-SHA256SUMS"
unzip -t "dbsdns-$VERSION.pkg"
```

Das Ergebnis muss für das versionierte Paket und den Alias OK melden. Die
Prüfsummendatei darf nicht in das Repository oder in ein Support-Ticket mit
anderen geheimen Testartefakten kopiert werden.

Paket entpacken und zunächst den Dry-Run ausführen:

```bash
RELEASE_DIR="$TEST_ROOT/release-$VERSION"
mkdir -p "$RELEASE_DIR"
unzip -q "$TEST_ROOT/dbsdns-$VERSION.pkg" -d "$RELEASE_DIR"

RELEASE_SOURCE="$RELEASE_DIR/dbsdns/src/dbsdns"
stat -c '%a %U:%G %n' "$TEST_ROOT" "$RELEASE_DIR" "$RELEASE_SOURCE"
namei -l "$RELEASE_SOURCE"
test "$(stat -c '%a' "$TEST_ROOT")" = '700'

bash "$RELEASE_DIR/dbsdns/scripts/install.sh" --dry-run
bash "$RELEASE_DIR/dbsdns/scripts/install.sh"
```

Der Dry-Run ist eine reine Leseinspektion. Er darf weder einen
`.dbsdns-stage-*`-Ordner unter `security/` noch einen Modul- oder
Schlüsselzustand erzeugen und meldet ausdrücklich, dass Staging-, Kopier- und
Runtime-Prüfung erst bei der echten Installation erfolgen. Die echte
Installation legt den privaten Workspace unter
`security/.dbsdns-stage-XXXXXX` mit `0700` an, kopiert als Root in das Kind
`module`, prüft Manifest und Inhalte, normalisiert Besitz/Modi, testet das Kind
als Panel-Laufzeitbenutzer und aktiviert es anschließend per Rename auf
demselben Dateisystem.

Erwartungen nach der Installation:

```bash
test -d /usr/local/ispconfig/interface/web/dbsdns
test -f /usr/local/ispconfig/interface/web/dbsdns/.core-integration-none
test -f /usr/local/ispconfig/security/dbsdns/credentials.key
stat -c '%a %U:%G' /usr/local/ispconfig/security/dbsdns/credentials.key
sha256sum -c "$TEST_ROOT/ispconfig-core.before.sha256"
```

Der Schlüssel sollte als 0640 mit root und der ISPConfig-Panel-Gruppe
angezeigt werden. Der direkte Skriptpfad registriert das Paket nicht im
offiziellen ispc-Katalog; das ist bei diesem Vorabtest erwartbar.

Bei einem Fehler vor `Module staging verified; starting persistent
installation changes.` dürfen keine Datenbank-, Schlüssel- oder Moduldateien
geändert sein. Bei einem Fehler danach bleiben bereits erfolgreich angelegte
oder geprüfte Schema- und Schlüsseländerungen erhalten; ein bereits aktives
altes Modul wird bei einem Aktivierungs- oder Berechtigungsfehler automatisch
wiederhergestellt. Diese Reihenfolge muss im Installer-Log und anhand der
vorher/nachher gespeicherten Schlüssel-Hashes und Tabellenstände nachvollzogen
werden.

Danach aus der ISPConfig-Oberfläche ab- und wieder anmelden, damit Modul- und
Plugin-Caches neu geladen werden.

## 3a. Pfad-, Laufzeit- und Nginx-Prüfung

Die effektiven Benutzer und Gruppen werden aus der Installation und der
aktiven Nginx-Konfiguration ermittelt. Keinen festen Namen wie `www-data`
annehmen:

```bash
CONFIG='/usr/local/ispconfig/interface/lib/config.inc.php'
INTERFACE='/usr/local/ispconfig/interface'
WEB_ROOT="$INTERFACE/web"
SECURITY_ROOT='/usr/local/ispconfig/security'
ISPCONFIG_ROOT='/usr/local/ispconfig'
PANEL_USER="$(stat -c '%U' "$CONFIG")"
PANEL_GROUP="$(stat -c '%G' "$CONFIG")"
NGINX_ID="$(nginx -T 2>/dev/null | awk '$1 == "user" {gsub(";", "", $2); gsub(";", "", $3); print $2, $3; exit}')"
read -r NGINX_USER NGINX_GROUP <<< "$NGINX_ID"
test -n "$NGINX_USER"
if test -z "$NGINX_GROUP"; then NGINX_GROUP="$(id -gn "$NGINX_USER")"; fi
id -nG "$NGINX_USER" | tr ' ' '\n' | grep -Fx "$PANEL_GROUP"

stat -c '%a %U:%G %n' "$ISPCONFIG_ROOT" "$SECURITY_ROOT" "$SECURITY_ROOT/dbsdns" \
  "$WEB_ROOT" "$WEB_ROOT/dbsdns" "$WEB_ROOT/dbsdns/zone_list.php"
test "$(stat -c '%U' "$ISPCONFIG_ROOT")" = 'root'
test "$((8#$(stat -c '%a' "$ISPCONFIG_ROOT") & 8#022))" -eq 0
namei -l "$ISPCONFIG_ROOT"
namei -l "$SECURITY_ROOT/dbsdns/credentials.key"
namei -l "$WEB_ROOT/dbsdns/zone_list.php"
printf 'panel=%s:%s nginx=%s:%s\n' "$PANEL_USER" "$PANEL_GROUP" "$NGINX_USER" "$NGINX_GROUP"
runuser --user "$PANEL_USER" -- test -r "$WEB_ROOT/dbsdns/zone_list.php"
runuser --user "$PANEL_USER" -- test -x "$WEB_ROOT/dbsdns"
runuser --user "$PANEL_USER" -- test -r "$SECURITY_ROOT/dbsdns/credentials.key"
runuser --user "$NGINX_USER" -- test -r "$WEB_ROOT/dbsdns/zone_list.php"
runuser --user "$NGINX_USER" -- test -x "$WEB_ROOT/dbsdns"
```

Erwartet werden `security/dbsdns` mit `0750` und `root:panel_group`,
`credentials.key` mit `0640` und `root:panel_group` sowie Webmodul-Besitz aus
der geschützten ISPConfig-Konfiguration. Das DNS-Verzeichnis übernimmt die
nativen Bits `& 0755` (typischerweise `0750`), PHP-Dateien die Bits `& 0644`
(typischerweise `0640`). Der private Staging-Container darf für Panel- und
Nginx-Benutzer nicht traversierbar sein; nur sein normalisiertes Kind wird
aktiviert.

Die neun fachlichen Einstiegspunkte sind `zone_list.php`, `zone_view.php`,
`zone_settings.php`, `record_edit.php`, `record_delete.php`, `settings.php`,
`assignment_list.php`, `assignment_edit.php` und `domain_sync.php`.
`index.php` delegiert lediglich an die Zonenliste. Im Browser-Netzwerkfenster
für jeden Einstiegspunkt einen gültigen, angemeldeten Request aufzeichnen:
Seiten liefern bei vorhandener Berechtigung `200`, nicht authentifizierte
Requests dürfen zum Login umleiten, und berechtigte Verweigerungen müssen
`403` ergeben. Ungültige Zonen-/Record-IDs werden als `404` oder als
fachlich kontrollierte `403` behandelt. `500` ist in jedem Fall ein Fehler.
`record_delete.php` nur über die vorgesehene POST-Aktion mit frischem
CSRF-Token testen; ein GET muss hier `405` liefern. `domain_sync.php` muss
bereits per GET das Formular anzeigen; erst der POST mit frischem CSRF-Token
führt die Synchronisierung aus. Erfolgreiche Schreibaktionen können mit
einem Redirect zur Zonenansicht antworten. Nach Login, Rollenwechsel und erfolgreichem
Update den Browser-Cache leeren oder hart neu laden und erneut anmelden.

## 4. Admin-Einstellungen und echte DBS-SOAP-Anfragen

Mit einem ISPConfig-Admin anmelden und unter DBS DNS Verwaltung →
Einstellungen den vom DBS-Testsystem vorgegebenen WSDL-Endpunkt,
Benutzernamen und das Testpasswort eintragen. Anschließend:

1. Speichern.
2. Verbindung testen ausführen.
3. Nur bei Erfolg mit der Zonensynchronisierung fortfahren.

Erwartet wird ein erfolgreicher Verbindungstest. Technisch löst der Test eine
echte domainListExtended-SOAP-Anfrage aus. Ein leeres Ergebnis ist nur dann
ein Erfolg, wenn der DBS-Endpunkt den dafür vorgesehenen Return-Code liefert.

Danach die Domain-/Zoneninventur ausführen. Die Cache-Daten dürfen nur
erfolgreich ersetzt werden, wenn die DBS-Anfrage erfolgreich war:

```bash
mariadb "$ISPC_DB_NAME" -e \
  "SELECT domain, provider_status, provider_present, last_synced_at
     FROM dbsdns_zone_cache ORDER BY domain;"

mariadb "$ISPC_DB_NAME" -e \
  "SELECT settings_id, wsdl_url, username, connection_status,
          connection_source, last_tested_at,
          (password_ciphertext <> '') AS password_stored
     FROM dbsdns_settings;"
```

In der zweiten Abfrage darf nur password_stored = 1 erscheinen; niemals das
Klartextpasswort. WSDL und Benutzername in einem Testprotokoll redigieren,
wenn sie intern vertraulich sind.

Die folgenden UI-Aktionen erzeugen die eigentlichen SOAP-Anfragen:

| UI-Aktion | Erwartete DBS-Operation |
| --- | --- |
| Verbindung testen oder Inventur laden | domainListExtended |
| Zone öffnen | nameserverZoneInfo |
| Record anlegen | nameserverRRCreate |
| Record ändern | nameserverRRDelete und nameserverRRCreate |
| Record löschen | nameserverRRDelete |
| SOA-Zoneneinstellungen speichern | nameserverSOAUpdate |

Für jeden Schreibtest eine ausschließlich für die Abnahme angelegte Zone
verwenden. Nach jeder Aktion sowohl die Erfolgsmeldung in ISPConfig als auch
den tatsächlichen Zustand im DBS prüfen. Geeignete eindeutige Testwerte sind
zum Beispiel:

- A: acceptance → 192.0.2.10
- AAAA: acceptance6 → 2001:db8::10
- CNAME: alias → ein erlaubter Testname
- MX: @ → erlaubter Test-Mailserver mit Priorität
- NS: nur in einer vom Provider freigegebenen Testzone
- SRV: _service._tcp mit gültigem Ziel, Gewicht und Port
- TXT: @ → dbsdns-acceptance-<laufnummer>

Für jeden unterstützten Typ mindestens einmal anlegen, erneut laden, ändern
und löschen. Zusätzlich prüfen:

- doppelte Records werden sauber behandelt;
- ungültige Namen, Ziele, TTLs und typabhängige Felder werden abgewiesen;
- ein unveränderter Edit führt zu keinem unnötigen Provider-Write;
- ein veraltetes Formular überschreibt keine zwischenzeitliche Änderung;
- eine fehlgeschlagene Änderung stellt den vorherigen Zustand wieder her;
- ein Benutzer kann keine fremde Zone über eine manipulierte URL oder
  Record-ID öffnen.

Optional kann während der Aktion auf der Test-VM der TLS-Verkehr als
Verbindungsnachweis aufgezeichnet werden. Der Inhalt ist wegen HTTPS nicht
lesbar; der maßgebliche Nachweis bleibt die Provider-Antwort und die
Zustandsänderung im DBS:

```bash
DBS_HOST='REPLACE_WITH_DBS_SOAP_HOST'
tcpdump -ni any -s0 -w "$TEST_ROOT/dbsdns-soap.pcap" \
  "host $DBS_HOST and tcp port 443"
```

Auf einem zweiten Terminal die UI-Aktionen ausführen und tcpdump danach mit
Ctrl+C beenden. Die PCAP-Datei vertraulich behandeln und nicht committen.

## 5. Rollenrechte reproduzierbar prüfen

Vor dem Test drei Konten und mindestens zwei Domains/Zonen anlegen:

1. einen ISPConfig-Admin;
2. einen Reseller mit einem darunter angelegten Kunden;
3. den Kunden selbst;
4. eine dem Kunden zugeordnete Domain, die im DBS-Testkonto vorhanden ist;
5. eine lokale oder einem anderen Kunden zugeordnete Kontroll-Domain.

Nach erfolgreicher DBS-Konfiguration und Zonensynchronisierung jeweils ab- und
wieder anmelden. Die aktuelle Berechtigungslogik erwartet folgende Matrix:

| Benutzer | Erwartung |
| --- | --- |
| Admin | dbsdns ist sichtbar; die native dns-Berechtigung bleibt erhalten; Einstellungen und Zuweisungen sind erreichbar. |
| Berechtigter Reseller | dbsdns ist sichtbar und zeigt nur die für den Reseller bzw. seine Kunden erreichbaren Zonen. Keine globalen Einstellungen. |
| Berechtigter Kunde | dbsdns ist sichtbar und zeigt nur die zugeordneten DBS-Zonen; Records dürfen nur innerhalb dieser Zonen bearbeitet werden. |
| Kunde ohne DBS-Zuordnung | Kein DBS-DNS-Menü und kein Zugriff auf die DBS-Zonen-URL. |
| Nicht zugeordneter Reseller | Keine fremden Zonen und keine Möglichkeit, die Zuweisungsgrenzen zu umgehen. |

Die Zuordnung hängt von einer vorhandenen, beim Provider gefundenen Domain,
der ISPConfig-Domain-/Kundenbeziehung und einer erfolgreichen Provider-
Konfiguration ab. Wenn provider_present nicht Y ist, ist der Benutzer
absichtlich nicht berechtigt.

Die effektive Modulliste kann ohne Passwörter kontrolliert werden:

```bash
mariadb "$ISPC_DB_NAME" -e \
  "SELECT userid, typ, client_id, modules, startmodule
     FROM sys_user ORDER BY userid;"
```

Nach einer Änderung der Zuordnung einmal ab- und anmelden. Ein Admin darf
weiterhin dns besitzen; bei berechtigten Nicht-Admins wird die DNS-
Modulzuordnung gemäß Erweiterungslogik auf dbsdns umgestellt. Keine
manuellen SQL-Änderungen als Testabkürzung durchführen.

## 6. Native Installation über das ISPConfig-Repository

Diesen Abschnitt auf einem frischen Snapshot aus Abschnitt 2 starten. Die
native Strecke darf erst begonnen werden, wenn die Maintainer das Paket
veröffentlicht haben und es im Katalog auftaucht:

```bash
ispc extension available
ispc extension list
```

dbsdns muss in der verfügbaren Liste mit der erwarteten Version erscheinen.
Wenn es dort nicht erscheint, ist die Repository-Einreichung noch nicht
abgeschlossen; keinen selbst erfundenen Downloadpfad verwenden.

Installation:

```bash
ispc extension install dbsdns
ispc extension list
```

Danach die Prüfungen aus den Abschnitten 3 bis 5 erneut ausführen. Insbesondere
Verbindungstest, Inventur, Rollenmatrix und mindestens ein Record pro
Schreiboperation müssen erneut mit echten DBS-Daten funktionieren.

## 7. Update

Ein Update kann mit 1.0.0 allein nicht belastbar geprüft werden. Dafür zuerst
eine zweite Release-Version, zum Beispiel 1.0.1, mit eigenem Changelog-
Eintrag und Repository-Metadaten veröffentlichen.

Auf dem Snapshot:

1. dbsdns in Version 1.0.0 installieren.
2. Einstellungen speichern, Verbindung testen und Cache mit Testdaten füllen.
3. Hash und Status des Schlüssels sowie die Anzahl der Cache-Zeilen notieren.
4. Version 1.0.1 im Repository verfügbar machen.
5. Das Update ausführen:

```bash
ispc extension update dbsdns
ispc extension list
```

Erwartungen:

- die installierte Modulversion entspricht 1.0.1;
- credentials.key hat denselben Hash wie vor dem Update;
- dbsdns_settings und dbsdns_zone_cache sind weiterhin vorhanden;
- gespeicherte Einstellungen können entschlüsselt und getestet werden;
- keine doppelten Cache-/Settings-Zeilen entstehen;
- der Admin- und Rollentest funktioniert danach weiter.

## 8. Disable und Enable

Ausgehend von einer funktionierenden Installation:

```bash
sha256sum /usr/local/ispconfig/security/dbsdns/credentials.key \
  > "$TEST_ROOT/credentials.key.before-disable.sha256"

ispc extension disable dbsdns
```

Prüfen:

```bash
test ! -d /usr/local/ispconfig/interface/web/dbsdns
test -f /usr/local/ispconfig/security/dbsdns/credentials.key
sha256sum -c "$TEST_ROOT/credentials.key.before-disable.sha256"

mariadb "$ISPC_DB_NAME" -e \
  "SELECT COUNT(*) AS settings_rows FROM dbsdns_settings;"
mariadb "$ISPC_DB_NAME" -e \
  "SELECT COUNT(*) AS cache_rows FROM dbsdns_zone_cache;"
sha256sum -c "$TEST_ROOT/ispconfig-core.before.sha256"
```

Das Modul und die Zuweisungen müssen deaktiviert sein; Schlüssel, Tabellen und
ISPConfig-Core bleiben erhalten. Ein angemeldeter Benutzer darf das alte
Menü nicht weiter benutzen können.

Danach wieder aktivieren:

```bash
ispc extension enable dbsdns
test -d /usr/local/ispconfig/interface/web/dbsdns
test -f /usr/local/ispconfig/interface/web/dbsdns/.core-integration-none
sha256sum -c "$TEST_ROOT/credentials.key.before-disable.sha256"
sha256sum -c "$TEST_ROOT/ispconfig-core.before.sha256"
```

Ab- und wieder anmelden, dann Einstellungen und eine lesende Zonenausgabe
prüfen. Das gespeicherte Passwort muss weiterhin für den Verbindungstest
funktionieren.

## 9. Deinstallation und Neuinstallation

Mit der funktionierenden Installation zuerst deinstallieren:

```bash
ispc extension uninstall dbsdns
```

Erwartungen:

```bash
test ! -d /usr/local/ispconfig/interface/web/dbsdns
test -f /usr/local/ispconfig/security/dbsdns/credentials.key
sha256sum -c "$TEST_ROOT/credentials.key.before-disable.sha256"

mariadb "$ISPC_DB_NAME" -e \
  "SELECT COUNT(*) AS settings_rows FROM dbsdns_settings;"
mariadb "$ISPC_DB_NAME" -e \
  "SELECT COUNT(*) AS cache_rows FROM dbsdns_zone_cache;"
sha256sum -c "$TEST_ROOT/ispconfig-core.before.sha256"
```

Die Erweiterung entfernt Moduldateien und Modulzuweisungen, bewahrt aber
absichtlich die DBS-Tabellen, Einstellungen und den externen Schlüssel auf.
Das ist erforderlich, damit eine Neuinstallation die gespeicherten Zugangsdaten
weiterverwenden kann.

Neuinstallation:

```bash
ispc extension install dbsdns
test -d /usr/local/ispconfig/interface/web/dbsdns
test -f /usr/local/ispconfig/interface/web/dbsdns/.core-integration-none
sha256sum -c "$TEST_ROOT/credentials.key.before-disable.sha256"
sha256sum -c "$TEST_ROOT/ispconfig-core.before.sha256"
```

Danach prüfen:

- Der Hash von credentials.key ist identisch mit dem Hash vor der
  Deinstallation.
- dbsdns_settings.connection_status und der Cache sind vorhanden.
- Der Admin kann den Verbindungstest ohne erneute Passworteingabe ausführen.
- Die Berechtigungszuweisungen werden nach der Anmeldung wiederhergestellt.
- Eine echte Zonenausgabe und ein echter Record-Test funktionieren erneut.

Wenn der native Katalog noch nicht verfügbar ist, kann die Deinstallation
stattdessen mit dem passenden Release-Skript simuliert werden:

```bash
bash "$RELEASE_DIR/dbsdns/scripts/uninstall.sh" --dry-run
bash "$RELEASE_DIR/dbsdns/scripts/uninstall.sh"
bash "$RELEASE_DIR/dbsdns/scripts/install.sh"
```

Der native ispc-Lebenszyklus bleibt trotzdem zusätzlich erforderlich, sobald
das Paket im offiziellen Katalog verfügbar ist.

## 10. Logs und Beweismittel

Nach jedem Lifecycle- und SOAP-Schritt die relevanten Logs auf Fehler prüfen,
ohne Zugangsdaten zu kopieren:

```bash
grep -iE 'dbsdns|soap|fatal|error|exception' \
  /var/log/ispconfig/ispconfig.log | tail -n 100
```

Zusätzlich die PHP-/Webserver-Fehlerlogs der Testinstallation prüfen. Für den
Abnahmebericht festhalten:

- ISPConfig-Version und Distribution;
- PHP-Version sowie SOAP-/Sodium-Status;
- getestete Extension-Version und GitHub-Release-URL;
- Ergebnis jedes Lifecycle-Schritts;
- getestete Rollen und Domainzuweisungen;
- SOAP-Aktion, Provider-Ergebnis und Zeitpunkt;
- Ausgabe von sha256sum -c ispconfig-core.before.sha256;
- relevante, bereinigte Logzeilen.

Nie in den Bericht aufnehmen: Passwörter, credentials.key, Klartext-
Konfiguration, Session-Cookies oder eine ungeschützte PCAP-Datei.

## Abbruchkriterien

Bei einer Core-Hash-Abweichung, einem Verlust des Schlüssels, einer nicht
reversiblen Provider-Änderung oder einem Fehler in der Zugriffstrennung den
Snapshot wiederherstellen und den Test abbrechen. Nicht durch manuelle
Änderungen in ISPConfig- oder DBS-Datenbanken „reparieren“; genau diese
Abweichung ist ein Release-Blocker.
