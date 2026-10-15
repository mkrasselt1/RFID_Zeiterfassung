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
3. In `include/config.h` das Modem wählen (`MODEM_A7670` oder
   `MODEM_SIM7600`) und die Pins mit dem eigenen Board abgleichen.
4. Bauen und flashen:

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

Das Board meldet nicht von sich aus, ob Strom von außen kommt. Ohne Zusatz
schätzt die Firmware aus der Akkuspannung (über 4,18 V gilt als „lädt“) —
das ist ungenau: Ein voller Akku ohne Netz sieht kurz genauso aus, ein
leerer Akku am Netz lädt unter 4,18 V.

Zuverlässig wird es mit zwei Widerständen: vom 5-V-Eingang (USB bzw. der
Ausgang des 12-V-Wandlers) über 100 kΩ auf einen freien ADC1-Pin, z. B.
GPIO34, und von dort 100 kΩ nach GND. Dann in `config.h`
`PIN_POWER_SENSE` auf 34 setzen.

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

- **Pins**: LilyGO hat mehrere Revisionen. Bei Schweigen des Modems zuerst
  TX/RX in `config.h` tauschen.
- **CMUX auf dem A7670**: Die Firmware nutzt den SIM7600-Treiber von
  esp-modem. Bricht `CMUX nicht möglich` den Start ab, bitte das Log
  schicken.
- **APN**: Einige Prepaid-Tarife brauchen Benutzername/Passwort; das kann die
  PPP-Bibliothek nicht.
