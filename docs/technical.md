# Technische Hinweise zu Enphase2Lox

Die Einrichtung und Bedienung beschreibt die [Haupt-README](../README.md).

Natives Plugin für LoxBerry 4, portiert aus `envoy_to_mqtt.py`. Es liest einen Enphase Envoy und veröffentlicht seine JSON-Messdaten über MQTT. Pluginversion: **1.0**.

Version 0.1.2 schreibt beide MQTT-Topics klein. Das gilt auch für bestehende Konfigurationen beim Einlesen. Vorhandene MQTT-Abonnements und virtuelle Eingänge entsprechend auf die kleingeschriebenen Topics umstellen, da MQTT zwischen Groß- und Kleinschreibung unterscheidet.

Version 0.1.1 entfernt die fest vorgegebenen LoxBerry-Basispfade aus allen Hooks, nutzt für die Adminoberfläche die nativen LoxBerry-Komponenten und vereinfacht MQTT auf die zentrale LoxBerry-Verbindung mit Topic-Einstellung. Ein Update erhält vorhandene Einstellungen und Tokens.

## Installation

1. `dist/enphase2lox-1.0.zip` im LoxBerry-Widget **Plugin-Verwaltung** hochladen und installieren bzw. als Update einspielen.
2. **enphase2lox** öffnen und IP-Adresse oder Hostname des Envoy eintragen.
3. **HTTPS** einschalten, wenn der Envoy HTTP nicht mehr unterstützt. Insbesondere neuere Firmware 8 benötigt dies bereits für `/info`. Die IVP-Abfragen der Firmware 7/8 verwenden immer HTTPS, wie im ursprünglichen Script.
4. Für Firmware 7/8 den Enphase-Benutzernamen und das Passwort eintragen. Für Firmware 5 sind keine Cloud-Zugangsdaten erforderlich.
5. Bei vorhandenem Speicher **Batterie vorhanden** einschalten. Die Auswahl bestimmt das Messdatenformat.
6. Das Plugin verwendet die zentrale Verbindung des **LoxBerry-MQTT-Widgets**. Im Plugin nur das Daten-Topic und bei Bedarf das zusätzliche FreeDS-Topic einstellen; eigene MQTT-Zugangsdaten sind nicht erforderlich.
7. Die Topics prüfen, **Dienst aktivieren** einschalten und **Speichern und anwenden** wählen.

Die Installation legt eine deaktivierte Konfiguration an. Vor dem ersten Start die bisherige Python-/Docker-Instanz stoppen, damit nur eine Instanz Messwerte auf dieselben Topics schreibt. Es ist kein Neustart des LoxBerry nötig.

Der Dienst läuft als Benutzer `loxberry`. Er startet beim Systemstart, wenn er aktiviert ist. **Stoppen** hält ihn bis zum nächsten manuellen Start oder Systemstart an; für dauerhaftes Abschalten die Aktivierung entfernen und speichern. Status und die letzten Logzeilen sind direkt auf der Pluginseite sichtbar. Während einer laufenden Token-Erneuerung kann das Stoppen bis etwa 30 Sekunden dauern; der Controller wartet maximal 45 Sekunden.

## Was LoxBerry bereits mitbringt

Das konkrete Zielsystem meldet **PHP 7.4.33**. Auch die offiziellen [Bookworm-](https://github.com/mschlenstedt/Loxberry_Installer/blob/main/install_bookworm.sh) und [Trixie-Installer](https://github.com/mschlenstedt/Loxberry_Installer/blob/main/install_trixie.sh) setzen `PHPVER_PROD=7.4`. Die dort zusätzlich aufgeführten PHP-Versionen 8.2 bzw. 8.4 sind Testversionen; das Plugin schaltet keine PHP-Alternative um.

Die [offizielle Paketliste für LoxBerry 4](https://github.com/mschlenstedt/Loxberry/blob/4.0.0.15/packages12.txt) enthält PHP CLI, cURL, JSON, XML und `mosquitto-clients`. Das Plugin prüft vor der Installation die tatsächlich verfügbaren PHP-Module cURL, JSON, SimpleXML und OpenSSL sowie Prozessfunktionen, `/usr/bin/mosquitto_pub` und `flock`. Fehlt etwas, bricht es mit einer konkreten Meldung ab. Es führt keine Paketinstallation aus.

Die [LoxBerry-PHP-Schnittstelle](https://github.com/mschlenstedt/Loxberry/blob/4.0.0.15/libs/phplib/loxberry_io.php) liefert die zentralen MQTT-Zugangsdaten mit `mqtt_connectiondetails()`. Der bestehende Mosquitto-Client übernimmt das Senden. Damit wird auch die auf PHP 8 inkompatible Syntax der älteren mitgelieferten `phpMQTT`-Bibliothek umgangen. Eine eigene MQTT-Bibliothek, ein zusätzlicher Broker und Composer sind nicht erforderlich.

Das Archiv folgt der [SamplePlugin-V4-Vorlage](https://github.com/mschlenstedt/LoxBerry-Plugin-SamplePlugin-V4) und dem [LoxBerry-Pluginformat](https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/start): `plugin.cfg`, Weboberfläche im geschützten `htmlauth`-Bereich, Boot-Hook und Installations-/Update-/Deinstallations-Hooks. Es legt keine systemd-Units oder sudoers-Regeln an.

## Messdaten und Verhalten

| Envoy | Datenquelle | MQTT-Nutzdaten |
| --- | --- | --- |
| Firmware 5 | `/stream/meter`, HTTP Digest mit berechnetem Installer-Passwort | JSON aus dem SSE-Datenstrom |
| Firmware 7/8 ohne Batterie | `/ivp/meters/readings` | JSON-Zählerliste |
| Firmware 7/8 mit Batterie | `/ivp/livedata/status` | Livedata-JSON inklusive Batteriedaten |

Firmware und Seriennummer werden aus `/info` gelesen. Unterstützt werden die vorhandenen Firmwarefamilien 5, 7 und 8. Unbekannte Firmware wird als Fehler angezeigt.

Das Daten-Topic lautet standardmäßig **`envoy/data`**, das optionale FreeDS-Topic **`envoy/inverter/gridwatts`**. Beide Topics werden beim Laden und Speichern kleingeschrieben. Die Original-JSON-Nutzdaten bleiben erhalten, einschließlich leerer Objekte und großer Zahlen. FreeDS erhält die gerundete Leistung aus Zählerindex 1 (`activePower`) oder aus `meters.grid.agg_p_mw / 1000`. Firmware 5 veröffentlicht wie der tatsächlich verwendete Stream-Pfad des Python-Scripts keinen zusätzlichen FreeDS-Wert. Bei einem fehlenden oder ungültigen Leistungswert wird kein Ersatzwert von 0 gesendet.

Abfrageintervall: standardmäßig 0,6 Sekunden zuzüglich Antwort- und Sendezeit. Firmware 5 nutzt den laufenden Stream. MQTT sendet wie bisher mit **QoS 0**, ohne Retain. Pro Veröffentlichung wird der bereits vorhandene `mosquitto_pub` kurz gestartet; bei zwei Topics sind das zwei Aufrufe pro Messung. Erfolgreiches Senden bestätigt bei QoS 0 keine spätere Verarbeitung durch einen Empfänger.

Netzwerkfehler führen zu Wiederholungen mit ansteigender Wartezeit bis 60 Sekunden. Bei einem fehlenden Endpunkt (HTTP 404) werden fünf Minuten gewartet. Ein deaktivierter Livedata-Stream wird erneut aktiviert; aufeinanderfolgende Fehler können ebenfalls eine Aktivierung auslösen. Ein Mindestabstand verhindert wiederholte Aktivierungsanfragen. Die Wiederverbindungseinstellungen sind in der Oberfläche anpassbar.

Tokens werden zwischengespeichert und bei HTTP 401 erneuert. Fehlgeschlagene Erneuerungen werden frühestens nach 60 Sekunden wiederholt. Die Enphase-Cloud benötigt zur Erzeugung eines neuen Tokens Internetzugang; mit einem gültigen gespeicherten Token sind die Messdatenabfragen lokal.

## Konfiguration und Dateien

Die tatsächlichen Pluginverzeichnisse werden von LoxBerry bestimmt; auch Ordner mit angehängter Nummer wie `enphase2lox02` werden unterstützt. Hooks beziehen das Basisverzeichnis aus `LBHOMEDIR`, den vom Installer übergebenen Argumenten oder den offiziellen `REPLACE`-Platzhaltern. Der Build lehnt fest eingetragene LoxBerry-Basispfade ab. Die Einstellungs- und Protokollseiten verwenden LoxBerrys Navigation, Formulare, Karten und Schaltflächen.

| Ort innerhalb des LoxBerry | Inhalt |
| --- | --- |
| `config/plugins/<Ordner>/options.json` | Einstellungen, Dateimodus 0600 |
| `config/plugins/<Ordner>/token.txt` | Zwischengespeicherter Token, Dateimodus 0600 |
| `log/plugins/<Ordner>/envoy.log` | Laufzeitprotokoll, Rotation bei etwa 1 MiB plus eine Sicherung |
| `log/plugins/<Ordner>/status.json` | Status, letzte erfolgreiche Übertragung und letzter Fehler |
| `log/plugins/<Ordner>/worker.lock` | Schutz vor mehreren gleichzeitigen Instanzen |

Logs und Status liegen im LoxBerry-RAM-Verzeichnis und werden beim Neustart neu aufgebaut. Einstellungen und Token bleiben erhalten. Das Pluginarchiv enthält keine Zugangsdaten oder Benutzerkonfiguration. Updates überschreiben bestehende Einstellungen und Tokens nicht.

Die Optionsnamen für Envoy und Topics aus dem Python-Script bleiben erhalten. Für eine manuelle Migration kann eine Kopie der bisherigen `options.json` als Plugin-Konfiguration dienen. Neu sind insbesondere `ENABLED` (Standard `false`), `ENVOY_VERIFY_TLS` und `POLL_INTERVAL`. Die Laufzeit verwendet immer die zentrale LoxBerry-MQTT-Verbindung. Frühere Optionen für separate Broker bleiben in bestehenden Dateien lesbar, steuern den Dienst aber nicht mehr. Die nicht verwendete Altoption `ENVOY_SERIAL` bleibt aus Kompatibilitätsgründen lesbar; die Seriennummer stammt weiterhin aus `/info`.

Die Oberfläche zeigt gespeicherte Passwörter nicht an. Ein leeres Passwortfeld behält das gespeicherte Passwort bei; ein eigenes Kontrollkästchen löscht es. Passwörter und Tokens erscheinen weder in Prozessargumenten noch in den Pluginlogs. Der MQTT-Client erhält seine Zugangsdaten über eine private temporäre Datei im RAM mit Modus 0600. [Dieses Verfahren wird von Mosquitto unterstützt](https://mosquitto.org/man/mosquitto_pub-1.html).

Enphase-Cloud-Zertifikate werden immer geprüft. Lokale Envoy-Zertifikate werden entsprechend der Einstellung geprüft; standardmäßig sind selbst signierte Envoy-Zertifikate erlaubt. Bei MQTT-TLS verwendet der Client die im LoxBerry hinterlegte CA, ansonsten die System-CAs. Die LoxBerry-Option ohne Hostnamenprüfung wird als `--insecure` übernommen; Mosquitto prüft dabei weiterhin die Zertifikatskette. Die MQTT-Verbindung wird ausschließlich im zentralen LoxBerry-MQTT-Widget verwaltet.

## Entwicklung und Prüfung

Die ZIP wird auf dem Entwicklungsrechner ohne zusätzliche Python-Pakete gebaut:

```sh
python3 tools/build_loxberry.py
```

Auf dem LoxBerry wird Python dafür nicht benötigt. Das Buildscript übernimmt ausgewählte Plugindateien, die Lizenz und die Dokumentation einschließlich Testanleitung. Testcode, die bestehende `data/`-Konfiguration und Docker-Images bleiben außerhalb des Archivs. Automatische Pluginupdates sind deaktiviert.

Die Tests verwenden synthetische Envoy-Antworten und einen Test-MQTT-Client. Firmware-Erkennung, Passwortparität zum Python-Original, JSON-Erhalt, SSE-Fragmentierung, FreeDS, Token-Erneuerung, TLS-Einstellungen, Dateirechte und Fehlerbehandlung wurden mit **PHP 7.4.33** geprüft. Die Weboberfläche wurde mit einem lokalen LoxBerry-SDK-Ersatz auf CSRF, HTML-Escaping und sicheres Speichern geprüft. Ein weiterer Integrationstest führt den echten Dienstcode unprivilegiert aus und prüft Doppelstartschutz, Stop/Neustart, Konfigurationserhalt und Boot-Folderauflösung in einer simulierten LoxBerry-Verzeichnisstruktur. Testaufrufe stehen in [tests/loxberry/README.md](../tests/loxberry/README.md).

Version 0.1.0 wurde vom Anwender auf LoxBerry installiert; dabei wurde die inzwischen behobene Pfadwarnung gemeldet. Die aktuelle ZIP wurde lokal mit PHP 7.4.33, einer abweichenden Basisverzeichnisstruktur und simulierten Envoy-/MQTT-Endpunkten geprüft. Der abschließende Funktionstest mit dem echten Envoy bleibt auf dem Zielgerät erforderlich.
