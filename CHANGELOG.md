# Changelog

Alle wesentlichen Änderungen dieses Projekts werden in dieser Datei dokumentiert.

## 1.0.1 – 2026-09-07

- Installer: Root-only Release-Quellen unter `/root` mit `0700` bleiben
  geschlossen; das Modul wird als Root in einem privaten
  `security/.dbsdns-stage-*`-Bereich kopiert, geprüft, für den aus der
  geschützten ISPConfig-Konfiguration abgeleiteten Panel-Laufzeitbenutzer
  normalisiert und auf demselben Dateisystem atomar aktiviert.
- Installer: Die Modulmodi werden aus dem nativen DNS-Modul abgeleitet
  (Verzeichnisse `& 0755`, Dateien `& 0644`) und entfernen Gruppen-/Fremdschreibrechte;
  ein `chmod 0755`-Workaround für die Quelle ist nicht erforderlich.
- Installer: Staging- und Runtime-Prüfungen erfolgen vor Datenbank-, Schlüssel-
  und Core-Schritten; bei einem späten Fehler bleiben Schema und Schlüssel
  erhalten und ein vorheriges Modul wird zurückgerollt.
- Modulzuweisungen: InnoDB-Preflight und gemeinsame Transaktion verhindern
  teilweise umgestellte Benutzer bei einem Synchronisierungsfehler.
- Laufzeit: Dateibasierte Includes verwenden vom Modulverzeichnis abgeleitete
  Pfade; Sprachdateien und Templates funktionieren auch bei fremdem CWD.
- Oberfläche: Ungefangene Fehler liefern eine generische Meldung;
  Diagnose-Logs enthalten Operation, Fehlerklasse und Position, keine
  Exception-Nachrichten oder Stacktraces mit möglichen Zugangsdaten.
- Abnahme- und Troubleshooting-Dokumentation für Berechtigungen, Nginx-
  `try_files`, Browser-Netzwerkstatus, Rollenrechte und den vollständigen
  Installations-/Deinstallations-/Neuinstallationslauf ergänzt.

## 1.0.0 – 2026-09-04

- Erste öffentliche Version der core-freien DBS-DNS-Erweiterung für ISPConfig 3.3.1p1.
- Abgesicherte Installation, Aktualisierung, Deinstallation und verschlüsselte Zugangsdatenverwaltung.
- Native Paketstruktur für das ISPConfig Extension Repository ergänzt.
- Reproduzierbarer, geprüfter `.pkg`-Build über GitHub Releases automatisiert.
