# Enphase2Lox

**Deine Enphase-Anlage mit Loxone verbinden – über LoxBerry und MQTT.**

Enphase2Lox liest die lokalen Messdaten eines Enphase Envoy und veröffentlicht sie am in LoxBerry konfigurierten MQTT-Broker. Das Plugin überträgt das Original-JSON und bei Bedarf die Netzleistung als zusätzlichen Zahlenwert für FreeDS oder Loxone.

```text
Enphase Envoy → Enphase2Lox auf LoxBerry → MQTT → Auswertung / MQTT-Gateway → Loxone
```

Das Plugin nutzt die normale LoxBerry-Weboberfläche und das vorhandene PHP. Docker, Composer, eine Python-Umgebung oder ein zusätzlicher Webserver sind auf dem LoxBerry nicht erforderlich. Die Anbindung steuert weder Wechselrichter noch Batterie; bei Batteriedaten kann sie den lokalen Messdatenstream einschalten.

**Version:** 1.0 · **Autor:** BastyJuice

## Funktionen

- Lokaler Abruf von Envoy-Geräten mit Firmwarefamilie 5, 7 oder 8.
- Automatische Erkennung von Seriennummer und Firmware über den Envoy.
- Unterstützung für Zählerdaten und den Livedata-Modus mit Batterie.
- Automatische Erzeugung und Erneuerung des Enphase-Tokens für Firmware 7/8.
- Automatischer Abruf im Hintergrund: standardmäßig alle 0,6 Sekunden zuzüglich Antwort- und Sendezeit, einstellbar auf 0,2 bis 3600 Sekunden. Firmware 5 verwendet einen laufenden Datenstrom.
- Original-JSON über MQTT und optional ein zusätzlicher Leistungswert in Watt.
- Nutzung der zentralen LoxBerry-MQTT-Verbindung; im Plugin werden nur die Topics eingestellt.
- LoxBerry-Oberfläche mit Einstellungen, Dienststeuerung, Status und Protokoll.

## Voraussetzungen

| Voraussetzung | Beschreibung |
| --- | --- |
| LoxBerry | Version 4 |
| PHP | Vorhandenes PHP ab 7.4; mit PHP 7.4.33 geprüft |
| PHP-Erweiterungen | cURL, JSON, SimpleXML und OpenSSL sowie verfügbare Prozessfunktionen `proc_*` |
| Systemwerkzeuge | Vorhandener MQTT-Client `/usr/bin/mosquitto_pub` und `flock` |
| Enphase | Ein im lokalen Netzwerk erreichbarer Envoy mit unterstützter Firmware |
| Enphase-Konto | Für Firmware 7/8 zur Erzeugung und Erneuerung des Tokens; dabei Internetzugang zur Enphase-Cloud erforderlich |
| MQTT/Loxone | In LoxBerry konfigurierter Broker; für die Weiterleitung ein eingerichtetes MQTT-Gateway und ein Miniserver |

Das Plugin installiert keine zusätzlichen Pakete, verändert die PHP-Konfiguration nicht und schaltet keine PHP-Version um. Der Hintergrunddienst verwendet `/usr/bin/php`. Der Installer prüft die benötigten Komponenten und meldet fehlende Voraussetzungen.

## Installation und Update

1. Die bereitgestellte Plugin-ZIP [enphase2lox-1.0.zip](dist/enphase2lox-1.0.zip) herunterladen.
2. In der **LoxBerry-Pluginverwaltung** die ZIP zur Installation auswählen.
3. Nach erfolgreicher Installation **enphase2lox** öffnen.

Für ein Update die neue Plugin-ZIP über die vorhandene Installation installieren. **Vorher nicht deinstallieren:** Einstellungen und gespeicherter Token bleiben bei einem Update erhalten.

Ein Neustart des LoxBerry ist nicht erforderlich. Die Erstinstallation legt eine deaktivierte Konfiguration an. Eine bisherige Python-/Docker-Instanz vor dem Aktivieren stoppen, damit nur eine Instanz auf die gewählten Topics schreibt.

## Envoy einrichten

1. Im Tab **Einstellungen** unter **Envoy-Host** die IP-Adresse oder den Hostnamen des Envoy eintragen, ohne Protokoll und Pfad.
2. **HTTPS für lokale Envoy-Abfragen verwenden** aktivieren, wenn der Envoy HTTPS benötigt. Neuere Firmware 8 stellt die lokale HTTP-Schnittstelle teilweise nicht mehr bereit.
3. Für Firmware 7/8 den **Enphase-Benutzernamen** und das **Enphase-Passwort** eintragen. Firmware 5 benötigt keine Cloud-Zugangsdaten.
4. Bei vorhandenem Speicher **Batterie vorhanden (Firmware 7/8)** aktivieren. Diese Auswahl ändert die verwendete Datenquelle und die JSON-Struktur.
5. Das gewünschte **Abfrageintervall** und die MQTT-Topics einstellen.
6. Unter **Dienst** die Option **Aktivieren und beim Systemstart automatisch starten** einschalten und **Speichern und anwenden** wählen.
7. Den Dienststatus und die **Letzte Übertragung** prüfen. Meldungen stehen im Tab **Protokoll**.

Die **Seriennummer wird automatisch ermittelt** und muss nicht eingetragen werden. Die Oberfläche muss für den laufenden Abruf nicht geöffnet bleiben. Ein leeres Enphase-Passwortfeld behält das gespeicherte Passwort bei; ein eigenes Kontrollkästchen löscht es.

Lokale Envoy-Zertifikate sind häufig selbst signiert. Die Prüfung lässt sich mit **Zertifikat des Envoy prüfen** einschalten. Die Zertifikate der Enphase-Cloud werden immer geprüft. Die IVP-Abfragen für Firmware 7/8 verwenden unabhängig von der Host-Einstellung HTTPS.

## MQTT und Loxone einrichten

Das Plugin übernimmt die Verbindung aus dem **LoxBerry-MQTT-Widget**. Brokeradresse, Port und MQTT-Zugangsdaten werden dort verwaltet. Enphase2Lox legt keine MQTT-Abos oder virtuellen Eingänge automatisch an.

Für den zusätzlichen Zahlenwert in Watt mit dem Standard-Topic; bei einem eigenen Topic das Abo und den Eingangsnamen entsprechend anpassen:

1. Im Plugin **Netzleistung zusätzlich für FreeDS veröffentlichen** aktivieren. Diese Ausgabe ist für Firmware 7/8 verfügbar.
2. Im LoxBerry-MQTT-Gateway das Topic `envoy/inverter/gridwatts` manuell abonnieren und den gewünschten Miniserver auswählen.
3. Die **HTTP-Weiterleitung** aktivieren. Für dieses numerische Topic keine JSON-Aufschlüsselung einschalten.
4. In **Loxone Config** einen virtuellen Eingang `envoy_inverter_gridwatts` anlegen und die Konfiguration an den Miniserver übertragen.

Bei der HTTP-Weiterleitung des Gateways wird aus `/` im MQTT-Topic ein `_` im Eingangsnamen:

| MQTT-Topic | Nutzdaten | Virtueller Eingang bei HTTP-Weiterleitung |
| --- | --- | --- |
| `envoy/inverter/gridwatts` | Gerundete Leistung in Watt | `envoy_inverter_gridwatts` |
| `envoy/data` | Vollständiges JSON des gewählten Envoy-Endpunkts | Gewünschte JSON-Felder zunächst aufschlüsseln und zuordnen |

Für weitere Messwerte aus `envoy/data` ist eine passende JSON-Aufschlüsselung im Gateway oder in einer eigenen Auswertung nötig. Die Struktur hängt von Firmware und Batteriemodus ab. Bei UDP-Weiterleitung gelten die UDP-Konfiguration und Erkennung des Gateways.

**Alle Topics werden kleingeschrieben**, auch beim Laden älterer Einstellungen. Bereits vorhandene MQTT-Abonnements und Loxone-Eingänge entsprechend anpassen. MQTT unterscheidet zwischen Groß- und Kleinschreibung.

## MQTT-Daten

Die folgenden Topics sind die Standardwerte und können in der Oberfläche geändert werden:

| Topic | Format | Bedeutung |
| --- | --- | --- |
| `envoy/data` | JSON-Objekt oder JSON-Liste | Originalantwort des Envoy mit den verfügbaren Messdaten |
| `envoy/inverter/gridwatts` | Ganze Zahl in W | Optionaler FreeDS-Leistungswert für Firmware 7/8 |

Die JSON-Nutzdaten werden unverändert weitergegeben, einschließlich leerer Objekte, Dezimalstellen und großer Zahlen. Das Plugin erzeugt daraus keine feste Liste einzelner Messwert-Topics. MQTT verwendet **QoS 0 ohne Retain**. Eine erfolgreiche Veröffentlichung bestätigt keine Verarbeitung durch den Loxone-Miniserver.

<details>
<summary>Datenquellen und Leistungsberechnung anzeigen</summary>

| Envoy-Modus | Datenquelle | Inhalt von `envoy/data` | Zusätzlicher FreeDS-Wert |
| --- | --- | --- | --- |
| Firmware 5 | `/stream/meter` | JSON aus dem laufenden SSE-Datenstrom | Keine zusätzliche Ausgabe |
| Firmware 7/8 ohne Batterie | `/ivp/meters/readings` | Liste der Zählerdaten | `activePower` aus Zählerindex 1, auf ganze Watt gerundet |
| Firmware 7/8 mit Batterie | `/ivp/livedata/status` | Livedata-Objekt mit den vom Envoy bereitgestellten Daten | `meters.grid.agg_p_mw / 1000`, auf ganze Watt gerundet |

Die Zählerrolle und die Vorzeichenrichtung hängen von der Anlage und ihrer Messkonfiguration ab. Vor der Nutzung zur Regelung prüfen, welcher Wert Netzbezug beziehungsweise Einspeisung abbildet. Ein fehlender oder ungültiger FreeDS-Wert wird nicht durch eine Null ersetzt.

Bei einer Änderung der Batterie-Option die bestehende JSON-Auswertung an das neue Datenformat anpassen. Seriennummer und Firmware werden unabhängig vom Messdatenmodus über `/info` gelesen.

</details>

## Gültigkeit und Ausfälle

Bei einer Unterbrechung kommen keine neuen Messwerte an. Empfänger können trotzdem ihren zuletzt empfangenen Wert behalten. Deshalb bei einer Auswertung oder Regelung auch prüfen, ob weiterhin neue MQTT-Nachrichten eintreffen.

Die aktuelle Version veröffentlicht **keinen Verfügbarkeitsstatus, kein `data_valid` und kein separates Lebenszeichen**. Ein unveränderter Zahlenwert allein zeigt nicht, ob die Verbindung noch funktioniert. Der Status **Letzte Übertragung** in der Pluginoberfläche hilft bei der lokalen Prüfung.

| Situation | Verhalten |
| --- | --- |
| Envoy oder MQTT-Broker vorübergehend nicht erreichbar | Fehler wird protokolliert; erneuter Versuch mit wachsender Wartezeit bis 60 Sekunden. |
| Token vom Envoy abgelehnt, HTTP 401 | Automatische Erneuerung über die Enphase-Cloud; erneute Erneuerungsversuche frühestens nach 60 Sekunden. |
| Endpunkt fehlt, HTTP 404 | Erneuter Versuch nach fünf Minuten. |
| Livedata-Stream deaktiviert | Das Plugin versucht, den Messdatenstream wieder einzuschalten. |
| Vollständiger Absturz oder Beenden des PHP-Prozesses | Dienst bleibt gestoppt; über die Oberfläche erneut starten. Ein automatischer Prozess-Neustart ist bisher nicht eingerichtet. |
| Neustart des LoxBerry | Ein aktivierter Dienst startet automatisch. |

## Betrieb und Zugangsdaten

| Aktion | Wirkung |
| --- | --- |
| **Speichern und anwenden** | Speichert die Einstellungen und startet einen aktivierten Dienst neu; ein deaktivierter Dienst wird gestoppt. |
| **Starten** | Startet den aktivierten Dienst, wenn er gestoppt ist. |
| **Stoppen** | Stoppt den Dienst bis zum nächsten manuellen Start oder LoxBerry-Systemstart. |
| **Neu starten** | Stoppt und startet den aktivierten Dienst. |
| Dienstaktivierung entfernen und speichern | Deaktiviert den Abruf und den automatischen Start beim Systemstart. |
| **Aktualisieren** | Lädt den angezeigten Status beziehungsweise das Protokoll neu. |

Während einer Token-Erneuerung kann das Stoppen ungefähr 30 Sekunden dauern; die Dienststeuerung wartet maximal 45 Sekunden. Doppelte Starts werden über eine Prozesssperre verhindert.

Enphase-Zugangsdaten und Token liegen im privaten Konfigurationsordner mit Dateimodus `0600`; das Verzeichnis erhält `0700`. Gespeicherte Passwörter werden nicht in Formularfelder zurückgesetzt. Passwörter und Tokens werden weder per MQTT veröffentlicht noch in die Pluginprotokolle geschrieben oder als Prozessargumente übergeben.

Der Token wird zwischengespeichert. Die lokalen Messdatenabfragen benötigen mit einem gültigen Token keinen laufenden Zugriff auf die Enphase-Cloud. Für eine neue Anmeldung oder Token-Erneuerung ist Internetzugang erforderlich.

Logs und Status liegen im LoxBerry-RAM-Verzeichnis und werden nach einem Systemstart neu aufgebaut. Einstellungen und Token bleiben erhalten. Lokale Dateien mit Zugangsdaten gehören nicht in das Repository oder in eine veröffentlichte Plugin-ZIP.

## Fehlerbehebung

| Problem | Prüfen |
| --- | --- |
| Envoy wird nicht erreicht oder `/info` kann nicht gelesen werden | IP-Adresse, Netzwerkverbindung und die HTTPS-Einstellung prüfen. |
| Token kann nicht erzeugt oder erneuert werden | Enphase-Zugangsdaten, Internetzugang und die Meldung im Tab **Protokoll** prüfen. |
| Dienst läuft, aber keine Batteriedaten | **Batterie vorhanden (Firmware 7/8)** und die Verfügbarkeit des Livedata-Endpunkts prüfen. |
| MQTT-Werte fehlen | Zentrale MQTT-Konfiguration des LoxBerry, Dienststatus und eingestellte Topics prüfen. |
| MQTT-Werte vorhanden, aber nicht in Loxone | Manuelles Gateway-Abo, Miniserver-Weiterleitung und Namen der virtuellen Eingänge prüfen; bei JSON zusätzlich die Feldaufschlüsselung kontrollieren. |
| FreeDS-Wert fehlt | FreeDS-Option, Firmware und das benötigte Leistungsfeld prüfen. Firmware 5 veröffentlicht diesen Zusatzwert nicht. |
| Nach einem Update werden keine Werte mehr empfangen | Prüfen, ob Abos und Eingänge bereits die kleingeschriebenen Topics verwenden. |
| Dienst ist nach einem Absturz gestoppt | Im Plugin erneut starten und das Protokoll prüfen. Es gibt derzeit keinen automatischen Neustart nach einem Prozessabsturz. |
| Oberfläche oder Dienst lädt nicht | Installationsprotokoll, vorhandenes PHP samt Erweiterungen und Schreibrechte der Pluginverzeichnisse prüfen. |

Bei einer Fehlermeldung helfen Plugin-Version, LoxBerry-Version, Envoy-Firmware und die betroffenen Protokollzeilen. Zugangsdaten und Tokens vor dem Veröffentlichen entfernen.

## Hinweise zur Anbindung

Enphase2Lox ist eine **inoffizielle Anbindung** und basiert auf dem bisherigen Python-Script `envoy_to_mqtt.py`. Pro Plugin-Installation wird ein Envoy abgefragt. Herstelleränderungen an Firmware, lokalen Endpunkten oder Token-Anmeldung können Anpassungen erfordern.

Firmware-Erkennung, Token-Erneuerung, Datenformate, MQTT-Aufrufe, Oberfläche und Installationsabläufe wurden unter **PHP 7.4.33** geprüft.

Automatische Plugin-Updates sind derzeit deaktiviert. Neue Versionen werden als ZIP über die Pluginverwaltung eingespielt.

## Projektstruktur

| Pfad | Inhalt |
| --- | --- |
| `loxberry/plugin.cfg` | Plugin-Metadaten und LoxBerry-Voraussetzungen |
| `loxberry/webfrontend/htmlauth/` | Geschützte LoxBerry-Oberfläche |
| `loxberry/bin/envoy.php` | Hintergrunddienst für Envoy-Abfrage und MQTT |
| `loxberry/bin/lib/` | Konfiguration, HTTP, Token, MQTT und Laufzeitprotokoll |
| `loxberry/bin/service.sh` | Start, Stop, Neustart und Status |
| `loxberry/daemon/` und Lifecycle-Hooks | Systemstart, Installation, Update und Deinstallation |
| `tools/build_loxberry.py` | Build der installierbaren Plugin-ZIP auf dem Entwicklungsrechner |
| `tests/loxberry/` | Tests für PHP, Oberfläche und Dienstabläufe |
| `dist/` | Fertige Plugin-ZIPs |

Technische Details stehen in den [Entwicklungsnotizen](docs/technical.md), Testaufrufe in der [Testanleitung](tests/loxberry/README.md). Die bisherige Python-/Docker-Anleitung bleibt unter [docs/python-docker.md](docs/python-docker.md) verfügbar.

## Referenzen und Lizenz

- [Ursprüngliches Enphase-Envoy-MQTT-Projekt](https://github.com/vk2him/Enphase-Envoy-mqtt-json)
- [Offizielle LoxBerry-Pluginvorlage V4](https://github.com/mschlenstedt/LoxBerry-Plugin-SamplePlugin-V4)
- [LoxBerry: Plugins entwickeln](https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/start)
- [LoxBerry: MQTT-Verbindungsdaten](https://github.com/mschlenstedt/Loxberry/blob/master/libs/phplib/loxberry_io.php)
- [LoxBerry: MQTT-Gateway](https://github.com/mschlenstedt/Loxberry/blob/master/sbin/mqtt_gateway.py)

Das Projekt steht unter der [MIT-Lizenz](LICENSE).

## Donation

Wenn dir mein Projekt hilft, kannst du mir gerne eine Tasse Kaffee spendieren :-)

[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://paypal.me/bastyjuice)
