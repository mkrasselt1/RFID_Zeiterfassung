#pragma once
// Hardware und Verhalten. Zugangsdaten stehen in secrets.h (siehe secrets.example.h).

// ---------------------------------------------------------------------------
// Modem: genau eins auswählen. T-A7670E/G und T-SIM7600E/G haben dieselbe
// Pinbelegung; sie unterscheiden sich nur in den AT-Befehlen für GNSS.
// ---------------------------------------------------------------------------
#define MODEM_A7670
// #define MODEM_SIM7600

// Pins laut LilyGO (utilities.h im Repo LilyGO-T-A76XX). Wer ein anderes
// Board hat, gleicht hier mit dessen Pinbelegung ab.
#define PIN_MODEM_TX      26  // ESP32 sendet -> Modem RX
#define PIN_MODEM_RX      27  // ESP32 empfängt <- Modem TX
#define PIN_MODEM_PWRKEY   4
#define PIN_MODEM_RESET    5
#define MODEM_RESET_LEVEL HIGH
#define PIN_MODEM_DTR     25
#define PIN_BOARD_POWERON 12  // schaltet die Modemversorgung, zugleich LED
#define MODEM_BAUD        115200

#if defined(MODEM_A7670)
#define MODEM_PWRKEY_PULSE_MS 100
#elif defined(MODEM_SIM7600)
#define MODEM_PWRKEY_PULSE_MS 500
#else
#error "MODEM_A7670 oder MODEM_SIM7600 in config.h wählen"
#endif

// ---------------------------------------------------------------------------
// Stromversorgung
// ---------------------------------------------------------------------------
// Akku hängt über einen 1:1-Teiler (2 x 100k) an GPIO35.
#define PIN_BATTERY_ADC     35
#define BATTERY_DIVIDER     2.0f

// Netzbetrieb erkennen. Das Board meldet von sich aus nicht, ob USB oder der
// DC/Solar-Eingang Strom liefert. Zuverlässig geht es nur mit einem
// Spannungsteiler vom 5-V-Eingang auf einen freien ADC1-Pin (32-39), z. B.
// 5 V -- 100k -- GPIO34 -- 100k -- GND. Dann hier Pin und Teiler eintragen.
// Bei -1 wird geschätzt: Akku über BATTERY_CHARGING_MV gilt als "lädt".
#define PIN_POWER_SENSE     -1
#define POWER_SENSE_DIVIDER 2.0f
#define POWER_SENSE_MIN_MV  4300
#define BATTERY_CHARGING_MV 4180

// ---------------------------------------------------------------------------
// Hotspot
// ---------------------------------------------------------------------------
#define AP_MAX_CLIENTS 2
#define AP_CHANNEL     6
#define AP_IP          192, 168, 4, 1
#define AP_DHCP_START  192, 168, 4, 2
#define AP_DNS         1, 1, 1, 1

// ---------------------------------------------------------------------------
// Meldungen an Home Assistant (GPSLogger-Integration)
// ---------------------------------------------------------------------------
#define REPORT_INTERVAL_MOVING_S  30   // solange der Traktor fährt
#define REPORT_INTERVAL_PARKED_S  300  // im Stand
#define MOVING_SPEED_KMH          3.0f
#define GNSS_POLL_INTERVAL_S      5
#define HTTP_TIMEOUT_MS           15000

// Ohne Mobilfunkverbindung so lange warten, dann ESP32 und Modem neu starten.
#define PPP_RESTART_AFTER_S       300
