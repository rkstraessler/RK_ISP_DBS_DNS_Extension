# Core-freie ISPConfig-3.3.1p1-Integration

## Modul und Navigation

`src/dbsdns/lib/module.conf.php` definiert das eigenständige Modul `dbsdns`. Für normale Benutzer lautet sein sichtbarer Titel **DNS**, für Administratoren **DBS DNS**.

Die Hauptnavigation stammt unverändert aus ISPConfigs `sys_user.modules`. `DbsModuleAccess` ersetzt bei berechtigten Kunden und Resellern den exakten Moduleintrag `dns` durch `dbsdns`; bei Administratoren wird `dbsdns` ergänzt und `dns` beibehalten. `dbsdns_module_access_plugin` hält diese Zuordnung bei Login und den regulären Client-/Reseller-/Domain-TForm-Ereignissen aktuell.

Im core-freien Zielbetrieb werden weder `interface/lib/config.inc.php` noch `interface/web/dns/lib/module.conf.php`, `nav.php`, `capp.php` oder andere Coredateien geändert.

## Installationsquelle, Staging und Berechtigungen

Ein Release kann unter `/root` oder in einem anderen Root-only-Verzeichnis
entpackt werden. Ein durch `mktemp -d` erzeugtes Verzeichnis mit `0700` ist
absichtlich sicher und darf nicht mit `chmod 0755` für den Panel-Benutzer
geöffnet werden. Die frühere Fehlerursache war, dass der Installer beim Kopieren
die Root-only-Quelle über `runuser` als Panel-Benutzer lesen wollte; dieser
`cp`-Aufruf muss an der Quelle als Root erfolgen.

Der aktuelle Ablauf erstellt darunter
`/usr/local/ispconfig/security/.dbsdns-stage-XXXXXX` mit `0700`. Root kopiert
das Release-Modul in dessen Kind `module`, prüft Manifest und Dateiinhalte,
normalisiert Besitz und Modi und testet dieses Kind anschließend mit dem aus
der geschützten ISPConfig-Konfiguration abgeleiteten Panel-Laufzeitbenutzer.
Der private Container bleibt für den Laufzeitbenutzer unzugänglich. Nach der
Prüfung wird das Modul auf demselben Dateisystem per Rename in den Webroot
aktiviert. `security/` und der ISPConfig-Webroot müssen deshalb auf demselben
Dateisystem liegen.

Die Zielmodi werden aus dem nativen DNS-Modul abgeleitet: Verzeichnisse
übernehmen die nativen Bits `& 0755` (typischerweise `0750`), PHP-Dateien die
Bits `& 0644` (typischerweise `0640`). Die Eigentümer kommen aus der geschützten
ISPConfig-Konfiguration; es wird kein fester Webserver-Benutzer angenommen.
Die Zuordnung wird auf den aus dem nativen Upstream abgeleiteten
`panel_user:panel_group` gesetzt. Das Upstream-Verhalten ist im geprüften
[ISPConfig-Commit `5005589c22794b504cd7e580a86752a93a619917`](https://git.ispconfig.org/ispconfig/ispconfig3/-/blob/5005589c22794b504cd7e580a86752a93a619917/install/lib/installer_base.lib.php#L3768-3825)
dokumentiert: `ispconfig:ispconfig`, `chmod -R 750` für die Interface-Dateien
und `770` für das Sprachverzeichnis; die Erweiterung übernimmt die
Lesbarkeit/Traversierung und entfernt Schreibbits für ihre Webdateien.
Der gleiche Upstream-Installationspfad nimmt den konfigurierten Nginx- bzw.
Apache-Benutzer in die `ispconfig`-Gruppe auf
([Zeilen 3861–3880](https://git.ispconfig.org/ispconfig/ispconfig3/-/blob/5005589c22794b504cd7e580a86752a93a619917/install/lib/installer_base.lib.php#L3861-3880)).
Dadurch kann der Webserver die `0750`-Verzeichnisse über die Panel-Gruppe
traversieren; auch diese Identität wird in der Abnahme aus der tatsächlichen
Konfiguration abgeleitet.

Für Nginx ist zusätzlich die geprüfte native Vorlage maßgeblich:
[nginx_ispconfig.vhost.master, Zeile 30](https://git.ispconfig.org/ispconfig/ispconfig3/-/blob/5005589c22794b504cd7e580a86752a93a619917/install/tpl/nginx_ispconfig.vhost.master#L30)
prüft mit `try_files $uri =404`, bevor an FastCGI weitergereicht wird. Deshalb
muss die Abnahme die Pfadberechtigungen und die HTTP-Antworten gemeinsam
prüfen.

Ein `--dry-run` führt nur geschützte Leseprüfungen von Quelle und Ziel aus.
Es kopiert nicht in das Staging, führt keine Laufzeitprüfung des Staging-Kinds
aus und ändert weder Datenbank, Schlüssel, Modulberechtigungen noch Coredateien.
Die echte Staging-, Kopier-, Runtime- und Rename-Prüfung gehört zum normalen
Installationslauf. Scheitert dieser Lauf nach Beginn der dauerhaften Schritte,
bleiben bereits angelegte oder geprüfte DBS-Schema- und Schlüsseländerungen
erhalten; ein vorheriges Modul wird bei einem Aktivierungs- oder
Berechtigungsfehler automatisch zurückgerollt. Der geheime Schlüssel liegt
außerhalb des Webroots unter `security/dbsdns` (`root:panel_group`, Verzeichnis
`0750`, Datei `0640`).
Der Preflight verlangt für `sys_user` die transaktionale InnoDB-Engine.
Der Installer stellt keine Tabellen-Engine automatisch um. Die abschließende
Synchronisierung aller Modulzuweisungen erfolgt in einer Transaktion;
ein Fehler vor dem Commit rollt die Benutzeränderungen zurück. Schema-DDL
und die externe Schlüsseldatei bleiben bewusst außerhalb dieser Transaktion.
Auch das Entfernen der Modulzuweisungen bei der Deinstallation ist
transaktional. Wird der Installer während der abschließenden
Berechtigungssynchronisierung unterbrochen, kann deren Commit-Ergebnis
unbekannt sein. In diesem Sonderfall bleibt das bereits geprüfte neue Modul
aktiv, und das alte Modul bleibt im gemeldeten privaten Recovery-Workspace
erhalten. Den Installer erneut ausführen; den gemeldeten Workspace erst nach
erfolgreicher Abnahme als Root entfernen. Ein regulär gemeldeter
Synchronisierungsfehler rollt dagegen weiterhin zum alten Modul zurück.
Der übergeordnete ISPConfig-Installationspfad muss Root gehören und darf keine
Gruppen- oder anderen Schreibbits tragen (native Installationen verwenden
hier typischerweise `0755`); die Abnahme prüft dies vor dem Installationslauf
mit `stat` und `namei`.

## Includes und Fehlerdiagnose

Die neun PHP-Einstiegspunkte leiten ihre Interface- und Modul-Includes aus
`__DIR__` ab. Dies beseitigt eine zusätzliche CWD-Abhängigkeit; sie ist nicht
als zweite Ursache des beschriebenen Nginx-Ausfalls nachgewiesen.
`DbsRuntime` stellt absolute Sprach- und Template-Pfade bereit. ISPConfigs
`tpl::_fileSearch()` prüft Theme-Overrides weiterhin zuerst und akzeptiert
anschließend den absoluten Pfad
([Upstream-Zeilen 914–936](https://git.ispconfig.org/ispconfig/ispconfig3/-/blob/5005589c22794b504cd7e580a86752a93a619917/interface/lib/classes/tpl.inc.php#L914)).
Native List-/TForm-Ladeabläufe laufen kontrolliert im Modulverzeichnis und
stellen danach das vorherige Arbeitsverzeichnis wieder her.

Ein früher Request-Guard unterdrückt PHP-Trace-Ausgaben, protokolliert
ungefangene Fehler mit Operation, Klasse und Position und liefert eine
generische ISPConfig-Fehlermeldung mit HTTP 500. Exception-Nachrichten,
Requestdaten und SOAP-Details werden nicht protokolliert. Fachlich behandelte
Fehler und die bestehenden Modul-, Admin-, DomainAccess- und CSRF-Prüfungen
bleiben maßgeblich. Ein HTTP 404 bereits bei Nginx erreicht diesen PHP-Guard
nicht; deshalb gehören Browser-Netzwerkstatus, Nginx- und PHP-FPM-Logs
gemeinsam zur Abnahme.

## Zonenliste

`zone_list.php` lädt ISPConfigs generische `listform_actions` und verwendet:

- `list/zone.list.php`
- `templates/dbsdns_zone_list.htm`
- `DbsZoneListActions`

Die abgeleitete Listendatenquelle enthält ausschließlich:

```sql
dbsdns_zone_cache
INNER JOIN domain
INNER JOIN sys_group
INNER JOIN client
```

Die vorab von `DbsZoneAccess` freigegebenen Cache-IDs begrenzen Daten- und Count-Abfrage. `listform::getSearchSQL()`, das normale `SQLOrderBy`, `getPagingHTML()` und das `pageForm`-/AJAX-Verhalten liefern Search, Sorting, Pagination und Ergebnisanzahl im nativen Look & Feel. Weder die SQL-Datenquelle noch der Zugriffsservice fragt `dns_soa` ab.

## Zonen- und Recordansicht

`zone_view.php` akzeptiert nur eine kanonische positive Cache-ID. Der Ablauf lautet:

1. ISPConfig-Modulberechtigung prüfen.
2. Sessionrolle bilden.
3. Cache-ID mit `DbsZoneAccess` erneut gegen `domain` prüfen.
4. Erst danach `DbsClient::getZoneInfo()` beziehungsweise `nameserverZoneInfo()` aufrufen.
5. SOA-Werte und Records ausschließlich aus dem Providerzustand darstellen; dokumentierte SOA-Werte können über den abgesicherten Zone-settings-Pfad geändert werden.

`DbsRecordListRenderer` verwendet eine eigene Listendefinition und ein eigenes minimales Template, aber ISPConfigs generische Listform-, Paging-, Template-, Session- und AJAX-Komponenten. Providerrecords erhalten nur dann Edit/Delete-Aktionen, wenn die zentrale Capability vollständig ist und `DbsRecordIdentity` den durch `DbsRecordNormalizer` kanonisierten Tupel aus `name`, `type`, `data`, `aux` und `ttl` signieren kann.

Während eines Record- oder SOA-Writes hält ein zonenweiter JavaScript-Lock weitere Buttons, Links, Suche, Sorting und Pagination an. Der bisherige Tabellen- beziehungsweise Formularinhalt bleibt sichtbar und höhenstabil; ein lokales Overlay wird bei erfolgreichem Inhaltswechsel, bei AJAX-Fehlern und über einen request-bewussten Failsafe wieder gelöst.

## Capabilities und Formulare

`DbsRecordCapabilities::supportedWritableRecordTypes()` enthält genau A, AAAA, CNAME, MX, NS, SRV und TXT. Die Recordliste iteriert diese Methode für ihre Buttons; es gibt keine zweite UI-Allowlist und keine disabled Buttons für andere Typen.

`record_edit.php`, `form/record.tform.php`, `templates/record_edit.htm` und `DbsRecordPage` bilden den eigenen Create-/Edit-Pfad. TForm stellt Formularlayout, feldspezifische Validatoren, Sessionzustand und CSRF-Felder bereit; `DbsRecordService` übernimmt die endgültige typabhängige Prüfung, frische Providerverifikation und beim Edit den bestätigten Delete/Create-Ablauf mit Rollback.

`DbsRecordNormalizer` bildet Owner/Apex, Casing, FQDN-Endpunkte, IP-Adressen, TTL/AUX, SRV und NS zentral zwischen Formular, DBS-Tupel, Providerantwort, HMAC und Edit-Formular ab; TXT-Daten werden nicht gequotet, escaped oder als Hostname behandelt. `record_delete.php` prüft den ISPConfig-CSRF-Token, den HMAC-Identifier und DomainAccess; `DbsRecordService::delete()` lädt die Zone frisch, vergleicht den kanonischen vollständigen Providerrecord, ruft erst dann `nameserverRRDelete()` auf und bestätigt anschließend dessen Abwesenheit.

Alle DBS-Pfade bleiben frei von `dns_soa`-/`dns_rr`-Writes.

## Einmalige Restore-Migration

Die Produktionspatches und alle Core-Delegationsadapter wurden entfernt. Für Installationen eines früheren Releases enthält `migration/ispconfig-3.3.1p1` ausschließlich:

- einen Restore-Hashsatz für bekannte frühere DBS-Stände;
- bytegenaue, gegen Commit `5005589c22794b504cd7e580a86752a93a619917` verifizierte Upstream-Originale.

Ohne `.core-integration-none` klassifiziert der Installer die fünf historisch betroffenen Dateien sowie das frühere zusätzliche DNS-Menü. Originale bleiben unangetastet, bekannte DBS-Stände werden vor dem exakten Restore gesichert, das Menü wird nur bei seinem bekannten Hash entfernt, und unbekannte Hashes führen vor jeder Änderung zum Abbruch. Nach erfolgreichem Restore wird der Marker mit dem Modul installiert; alle späteren Installationen melden `Core integration: none` und prüfen, sichern oder verändern keine Coredatei mehr.

Historische Diff-Dateien unter `tests/fixtures/legacy-dbs-core` sind nicht Bestandteil des Installers. Sie erzeugen ausschließlich reproduzierbare frühere Testzustände.
