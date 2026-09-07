# Core-freie ISPConfig-3.3.1p1-Integration

## Modul und Navigation

`src/dbsdns/lib/module.conf.php` definiert das eigenständige Modul `dbsdns`. Für normale Benutzer lautet sein sichtbarer Titel **DNS**, für Administratoren **DBS DNS**.

Die Hauptnavigation stammt unverändert aus ISPConfigs `sys_user.modules`. `DbsModuleAccess` ersetzt bei berechtigten Kunden und Resellern den exakten Moduleintrag `dns` durch `dbsdns`; bei Administratoren wird `dbsdns` ergänzt und `dns` beibehalten. `dbsdns_module_access_plugin` hält diese Zuordnung bei Login und den regulären Client-/Reseller-/Domain-TForm-Ereignissen aktuell.

Im core-freien Zielbetrieb werden weder `interface/lib/config.inc.php` noch `interface/web/dns/lib/module.conf.php`, `nav.php`, `capp.php` oder andere Coredateien geändert.

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
