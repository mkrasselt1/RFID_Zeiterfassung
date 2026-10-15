# Traktor-Tracker (LilyGO T-A7670 / T-SIM7600)

Firmware für ein LilyGO-LTE-Board im Traktor. Sie macht drei Dinge gleichzeitig:

1. **WLAN-Hotspot** über die Mobilfunkverbindung (NAT) für ein, zwei Handys
   oder Tablets in der Kabine.
2. **Position** aus dem GNSS des Modems an Home Assistant, über die
   [GPSLogger-Integration](https://www.home-assistant.io/integrations/gpslogger/).
   Daraus wird `device_tracker.traktor`.
3. **Stromversorgung und Akku**: ob das Board am Netz hängt oder vom Akku
   läuft, und wie voll der Akku ist — beides an Home Assistant und auf einer
   Statusseite im Hotspot.

## Einrichten

1. In Home Assistant *Einstellungen → Geräte & Dienste → Integration
   hinzufügen → GPSLogger*. Die angezeigte Webhook-URL notieren.
2. `include/secrets.example.h` nach `include/secrets.h` kopieren und APN,
   SIM-PIN, Hotspot-Name/-Passwort und Webhook-URL eintragen.
3. In `include/config.h` das Board wählen. Voreingestellt ist der
   T-SIM7600E (`BOARD_T_SIM7600`), alternativ `BOARD_T_A7670`. Die Pins
   stammen aus LilyGOs eigenen Beispielen.
4. Auf dem T-SIM7600 die DIP-Schalter so stellen: P1 und P2 auf ON (Modem-UART
   am ESP32), P4 auf OFF (ESP32 schaltet das Modem ein).
5. Bauen und flashen:

   ```sh
   cd tracker
   pio run -t upload
   pio device monitor
   ```

Gebaut wird mit Arduino-Core 3.x über [pioarduino](https://github.com/pioarduino/platform-espressif32);
das offizielle `espressif32`-Paket von PlatformIO hat die nötige PPP- und
NAT-Unterstützung nicht.

## Was in Home Assistant ankommt

| Attribut   | Inhalt                                                   |
|------------|----------------------------------------------------------|
| Position   | Breite/Länge, `gps_accuracy` aus HDOP geschätzt          |
| `battery`  | Akkustand in Prozent                                     |
| `activity` | `netzbetrieb` oder `akkubetrieb`                         |
| `provider` | `gps`, oder `letzte_position` ohne aktuellen Fix         |
| `speed`, `direction`, `altitude` | aus dem GNSS                       |

GPSLogger verwirft Felder, die es nicht kennt; der Strom-Status steckt deshalb
in `activity`. Als Sensor:

```yaml
template:
  - binary_sensor:
      - name: Traktor am Netz
        device_class: plug
        state: "{{ state_attr('device_tracker.traktor', 'activity') == 'netzbetrieb' }}"
```

Gemeldet wird alle 30 s während der Fahrt, alle 5 min im Stand und sofort,
wenn zwischen Netz und Akku gewechselt wird (`config.h`).

## Statusseite im Hotspot

Wer im Traktor-WLAN ist, sieht unter `http://192.168.4.1/` Position, Tempo,
Akku, Mobilfunk und Hotspot. `http://192.168.4.1/status` liefert dasselbe als
JSON.

## Netzbetrieb erkennen

**T-SIM7600:** Hier geht das ohne Zusatzteile. Sobald Strom über USB-C oder
den VBUS-Pin kommt, trennt das Board den Spannungsteiler des Akkus an GPIO35,
und der ADC liest 0. Daran erkennt die Firmware den Netzbetrieb. Die
Akkuspannung kommt in dieser Zeit vom Modem (`AT+CBC`). Weil der Akku dabei
lädt, liegt der Prozentwert zu hoch (`charging: true` in `/status`).

Für den Traktor heißt das: Ein 12-V-auf-5-V-Wandler mit mindestens 2 A an
USB-C oder VBUS. **VBUS verträgt höchstens 5 V.** Der Solar-Eingang taugt
nicht als Versorgung, er lädt nur den Akku. Laut LilyGO kann das Board beim
Umschalten zwischen Netz und Akku neu starten. Die Firmware meldet nach jedem
Start sofort, der Wechsel kommt also trotzdem in Home Assistant an.

**T-A7670 und andere:** Ohne Zusatz wird aus der Akkuspannung geschätzt
(über 4,18 V gilt als „lädt“), und das ist ungenau. Zuverlässig wird es mit
zwei Widerständen: vom 5-V-Eingang über 100 kΩ auf GPIO34, von dort 100 kΩ
nach GND, und in `config.h` `PIN_POWER_SENSE` auf 34 setzen.

## Wie es funktioniert

- Das Modem wird beim Start geprüft, nötigenfalls zurückgesetzt und
  eingeschaltet, GNSS per AT-Befehl aktiviert.
- Die Mobilfunkverbindung läuft als PPP im **CMUX**-Modus: Datenverbindung
  und AT-Befehle teilen sich den UART. So lässt sich `AT+CGNSSINFO` abfragen,
  während der Hotspot online ist.
- Der Hotspot leitet per NAPT ins Mobilnetz. `AP_MAX_CLIENTS` begrenzt die
  Zahl der Geräte (Standard 2).
- Ist die Mobilfunkverbindung länger als 5 min weg, startet das Board samt
  Modem neu.

## Ungetestet

Die Firmware baut, der GNSS-Parser ist gegen Beispielantworten von SIM7600
und A7670 geprüft. Auf echter Hardware lief sie noch nicht. Am ehesten
hakt es bei:

- **Pins**: Sie stammen aus LilyGOs Beispielen. Bei Schweigen des Modems
  zuerst die DIP-Schalter prüfen, dann TX/RX in `config.h` tauschen.
- **CMUX**: Beim SIM7600 unterstützt esp-modem das offiziell, beim A7670
  wird derselbe Treiber verwendet. Bricht `CMUX nicht möglich` den Start ab,
  bitte das Log schicken.
- **APN**: Einige Prepaid-Tarife brauchen Benutzername/Passwort; das kann die
  PPP-Bibliothek nicht.
