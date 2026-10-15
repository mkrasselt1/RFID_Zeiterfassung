// Traktor-Tracker für LilyGO T-A7670 / T-SIM7600:
//  - Mobilfunk per PPP, als WLAN-Hotspot mit NAT an ein, zwei Geräte weitergegeben
//  - GNSS des Modems, Position an Home Assistant (GPSLogger-Webhook)
//  - Netz-/Akkubetrieb und Akkustand, ebenfalls an Home Assistant
//  - lokale Statusseite im Hotspot: http://192.168.4.1/ und /status (JSON)

#include <Arduino.h>
#include <HTTPClient.h>
#include <NetworkClientSecure.h>
#include <PPP.h>
#include <WebServer.h>
#include <WiFi.h>

#include "config.h"
#if __has_include("secrets.h")
#include "secrets.h"
#else
#warning "include/secrets.h fehlt, es gelten die Platzhalter aus secrets.example.h"
#include "secrets.example.h"
#endif

#include "Gnss.h"
#include "Power.h"

static WebServer server(80);

static GnssFix lastFix;            // letzte gültige Position
static bool gnssHasFix = false;    // aktuelle Abfrage hat eine Position
static PowerState power;
static bool lastSeenExternalPower = false;

static int pppRssi = 99;          // 99 = unbekannt (AT+CSQ)
static uint32_t lastGnssPoll = 0;
static uint32_t nextReportAt = 0;
static uint32_t pppLostSince = 0;  // 0 = verbunden
static int lastHttpCode = 0;
static uint32_t lastReportOkAt = 0;

// ---------------------------------------------------------------------------
// Modem einschalten
// ---------------------------------------------------------------------------

// Wartet auf "OK" auf ein "AT". Läuft über Serial1, bevor PPP den UART übernimmt.
static bool modemResponds(uint32_t timeoutMs) {
  uint32_t start = millis();
  while (millis() - start < timeoutMs) {
    while (Serial1.available()) Serial1.read();
    Serial1.print("AT\r\n");
    uint32_t t = millis();
    String resp;
    while (millis() - t < 500) {
      while (Serial1.available()) resp += (char)Serial1.read();
      if (resp.indexOf("OK") >= 0) return true;
      delay(10);
    }
  }
  return false;
}

static String modemCmdRaw(const char *cmd, uint32_t timeoutMs) {
  while (Serial1.available()) Serial1.read();
  Serial1.print(cmd);
  Serial1.print("\r\n");
  String resp;
  uint32_t t = millis();
  while (millis() - t < timeoutMs) {
    while (Serial1.available()) resp += (char)Serial1.read();
    if (resp.indexOf("OK") >= 0 || resp.indexOf("ERROR") >= 0) break;
    delay(10);
  }
  resp.trim();
  return resp;
}

static void pulsePwrKey() {
  digitalWrite(PIN_MODEM_PWRKEY, LOW);
  delay(100);
  digitalWrite(PIN_MODEM_PWRKEY, HIGH);
  delay(MODEM_PWRKEY_PULSE_MS);
  digitalWrite(PIN_MODEM_PWRKEY, LOW);
}

// Bringt das Modem in einen Zustand, in dem es auf AT antwortet. Nach einem
// Neustart des ESP32 läuft es womöglich noch, dann im PPP-/CMUX-Modus — dann
// hilft nur der Reset-Pin.
static bool modemPowerOn() {
  pinMode(PIN_BOARD_POWERON, OUTPUT);
  digitalWrite(PIN_BOARD_POWERON, HIGH);
  pinMode(PIN_MODEM_DTR, OUTPUT);
  digitalWrite(PIN_MODEM_DTR, LOW);  // LOW = nicht schlafen
  pinMode(PIN_MODEM_PWRKEY, OUTPUT);
  digitalWrite(PIN_MODEM_PWRKEY, LOW);
  pinMode(PIN_MODEM_RESET, OUTPUT);
  digitalWrite(PIN_MODEM_RESET, !MODEM_RESET_LEVEL);

  Serial1.begin(MODEM_BAUD, SERIAL_8N1, PIN_MODEM_RX, PIN_MODEM_TX);

  if (modemResponds(2000)) {
    Serial.println("Modem läuft bereits");
    return true;
  }

  Serial.println("Modem zurücksetzen und einschalten");
  digitalWrite(PIN_MODEM_RESET, MODEM_RESET_LEVEL);
  delay(2600);
  digitalWrite(PIN_MODEM_RESET, !MODEM_RESET_LEVEL);
  pulsePwrKey();
  if (modemResponds(30000)) return true;

  // War es vorher an, hat der Puls es eben ausgeschaltet.
  Serial.println("Keine Antwort, zweiter Einschaltversuch");
  pulsePwrKey();
  return modemResponds(30000);
}

static void gnssEnable() {
#if defined(MODEM_A7670)
  Serial.printf("GNSS an: %s\n", modemCmdRaw("AT+CGNSSPWR=1", 5000).c_str());
#else
  Serial.printf("GNSS an: %s\n", modemCmdRaw("AT+CGPS=1,1", 5000).c_str());
#endif
}

// ---------------------------------------------------------------------------
// Netzwerk
// ---------------------------------------------------------------------------

static void onNetworkEvent(arduino_event_id_t event, arduino_event_info_t info) {
  switch (event) {
    case ARDUINO_EVENT_PPP_GOT_IP:
      Serial.print("Mobilfunk verbunden: ");
      Serial.println(PPP.localIP());
      PPP.setDefault();
      WiFi.AP.enableNAPT(true);
      pppLostSince = 0;
      break;
    case ARDUINO_EVENT_PPP_LOST_IP:
    case ARDUINO_EVENT_PPP_DISCONNECTED:
      Serial.println("Mobilfunk getrennt");
      WiFi.AP.enableNAPT(false);
      if (pppLostSince == 0) pppLostSince = millis() | 1;
      break;
    case ARDUINO_EVENT_WIFI_AP_STACONNECTED:
      Serial.println("Hotspot: Gerät verbunden");
      break;
    case ARDUINO_EVENT_WIFI_AP_STADISCONNECTED:
      Serial.println("Hotspot: Gerät getrennt");
      break;
    default:
      break;
  }
}

static void startHotspot() {
  WiFi.AP.begin();
  WiFi.AP.config(IPAddress(AP_IP), IPAddress(AP_IP), IPAddress(255, 255, 255, 0), IPAddress(AP_DHCP_START),
                 IPAddress(AP_DNS));
  WiFi.AP.create(SECRET_AP_SSID, SECRET_AP_PASS, AP_CHANNEL, 0, AP_MAX_CLIENTS);
  WiFi.setSleep(false);
  if (!WiFi.AP.waitStatusBits(ESP_NETIF_STARTED_BIT, 2000)) Serial.println("Hotspot startet nicht");
  else Serial.printf("Hotspot \"%s\" auf %s\n", SECRET_AP_SSID, WiFi.AP.localIP().toString().c_str());
}

static void startMobile() {
  // Serial1 freigeben, PPP öffnet den UART selbst.
  Serial1.end();
  PPP.setApn(SECRET_APN);
  PPP.setPin(SECRET_SIM_PIN);
  PPP.setPins(PIN_MODEM_TX, PIN_MODEM_RX);
  if (!PPP.begin(PPP_MODEM_SIM7600, 1, MODEM_BAUD)) {
    Serial.println("PPP.begin fehlgeschlagen, Neustart");
    delay(1000);
    ESP.restart();
  }
  Serial.printf("Modem %s, IMEI %s\n", PPP.moduleName().c_str(), PPP.IMEI().c_str());

  Serial.print("Warte auf Netz");
  uint32_t start = millis();
  while (!PPP.attached() && millis() - start < 120000) {
    Serial.print(".");
    delay(1000);
  }
  Serial.println();
  if (!PPP.attached()) {
    Serial.println("Kein Netz, Neustart");
    ESP.restart();
  }
  Serial.printf("Netz: %s, RSSI %d\n", PPP.operatorName().c_str(), PPP.RSSI());

  // CMUX: Daten und AT-Befehle gleichzeitig — nötig, um GNSS abzufragen,
  // während der Hotspot online ist.
  pppLostSince = millis() | 1;
  if (!PPP.mode(ESP_MODEM_MODE_CMUX)) {
    Serial.println("CMUX nicht möglich, Neustart");
    ESP.restart();
  }
}

// ---------------------------------------------------------------------------
// Home Assistant
// ---------------------------------------------------------------------------

static String urlEncode(const char *s) {
  String out;
  for (; *s; s++) {
    char c = *s;
    if (isalnum((unsigned char)c) || c == '-' || c == '_' || c == '.' || c == '~') out += c;
    else {
      char buf[4];
      snprintf(buf, sizeof(buf), "%%%02X", (unsigned char)c);
      out += buf;
    }
  }
  return out;
}

// GPSLogger nimmt nur bekannte Felder an (latitude, longitude, device,
// accuracy, battery, speed, direction, altitude, provider, activity) und
// verwirft den Rest. Netz-/Akkubetrieb steckt deshalb in "activity".
static bool reportToHomeAssistant() {
  if (!lastFix.valid) {
    Serial.println("Noch keine Position, nichts gemeldet");
    return false;
  }
  if (!PPP.connected()) return false;

  char body[400];
  snprintf(body, sizeof(body),
           "device=%s&latitude=%.6f&longitude=%.6f&accuracy=%.0f&altitude=%.1f&speed=%.2f"
           "&direction=%.0f&battery=%d&provider=%s&activity=%s",
           urlEncode(SECRET_DEVICE_NAME).c_str(), lastFix.lat, lastFix.lon, lastFix.accuracyM(), lastFix.altitude,
           lastFix.speedKmh / 3.6f, lastFix.course, power.batteryPercent, gnssHasFix ? "gps" : "letzte_position",
           power.externalPower ? "netzbetrieb" : "akkubetrieb");

  NetworkClientSecure tls;
  NetworkClient plain;
  bool https = strncmp(SECRET_WEBHOOK_URL, "https", 5) == 0;
  // Ohne hinterlegtes Zertifikat: verschlüsselt, aber der Server wird nicht
  // geprüft. Wer das will, setzt hier tls.setCACert(...).
  if (https) tls.setInsecure();

  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(https ? (NetworkClient &)tls : plain, SECRET_WEBHOOK_URL)) {
    Serial.println("Webhook-URL ungültig");
    return false;
  }
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  lastHttpCode = http.POST((uint8_t *)body, strlen(body));
  http.end();

  Serial.printf("Home Assistant: HTTP %d\n", lastHttpCode);
  if (lastHttpCode >= 200 && lastHttpCode < 300) {
    lastReportOkAt = millis();
    return true;
  }
  return false;
}

// ---------------------------------------------------------------------------
// Lokale Statusseite
// ---------------------------------------------------------------------------

static String statusJson() {
  char buf[640];
  snprintf(buf, sizeof(buf),
           "{\"device\":\"%s\",\"uptime_s\":%lu,"
           "\"gps\":{\"fix\":%s,\"valid\":%s,\"latitude\":%.6f,\"longitude\":%.6f,\"altitude_m\":%.1f,"
           "\"speed_kmh\":%.1f,\"course\":%.0f,\"satellites\":%d,\"hdop\":%.1f,\"utc\":\"%s\"},"
           "\"power\":{\"external\":%s,\"source\":\"%s\",\"sensed\":%s,\"battery_mv\":%d,\"battery_percent\":%d},"
           "\"mobile\":{\"connected\":%s,\"ip\":\"%s\",\"rssi\":%d},"
           "\"hotspot\":{\"ssid\":\"%s\",\"clients\":%d},"
           "\"report\":{\"last_http_code\":%d,\"last_ok_s_ago\":%ld}}",
           SECRET_DEVICE_NAME, (unsigned long)(millis() / 1000), gnssHasFix ? "true" : "false",
           lastFix.valid ? "true" : "false", lastFix.lat, lastFix.lon, lastFix.altitude, lastFix.speedKmh,
           lastFix.course, lastFix.satellites, lastFix.hdop, lastFix.utc, power.externalPower ? "true" : "false",
           power.externalPower ? "netz" : "akku", power.sensed ? "true" : "false", power.batteryMv,
           power.batteryPercent, PPP.connected() ? "true" : "false", PPP.localIP().toString().c_str(),
           pppRssi, SECRET_AP_SSID, WiFi.AP.stationCount(), lastHttpCode,
           lastReportOkAt ? (long)((millis() - lastReportOkAt) / 1000) : -1L);
  return String(buf);
}

static const char INDEX_HTML[] PROGMEM = R"HTML(<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Traktor</title>
<style>body{font-family:system-ui,sans-serif;margin:16px;max-width:480px}td{padding:4px 8px}td:first-child{color:#666}</style>
</head><body><h1>Traktor</h1><table id="t"></table>
<script>
async function upd(){try{const s=await (await fetch('/status')).json();const g=s.gps,p=s.power;
const rows=[['Position',g.valid?`<a href="https://www.openstreetmap.org/?mlat=${g.latitude}&mlon=${g.longitude}#map=16/${g.latitude}/${g.longitude}">${g.latitude.toFixed(5)}, ${g.longitude.toFixed(5)}</a>`+(g.fix?'':' (letzte)'):'kein GPS-Fix'],
['Tempo',g.speed_kmh.toFixed(1)+' km/h'],['Satelliten',g.satellites],
['Strom',(p.external?'Netz':'Akku')+(p.sensed?'':' (geschätzt)')],['Akku',p.battery_percent+' % ('+(p.battery_mv/1000).toFixed(2)+' V)'],
['Mobilfunk',s.mobile.connected?'verbunden, RSSI '+s.mobile.rssi:'getrennt'],['Hotspot',s.hotspot.clients+' Gerät(e)'],
['Home Assistant',s.report.last_ok_s_ago<0?'noch nichts gemeldet':'vor '+s.report.last_ok_s_ago+' s, HTTP '+s.report.last_http_code]];
document.getElementById('t').innerHTML=rows.map(r=>`<tr><td>${r[0]}</td><td>${r[1]}</td></tr>`).join('');}catch(e){}}
upd();setInterval(upd,5000);
</script></body></html>)HTML";

static void startWebServer() {
  server.on("/", [] { server.send_P(200, "text/html; charset=utf-8", INDEX_HTML); });
  server.on("/status", [] {
    server.sendHeader("Access-Control-Allow-Origin", "*");
    server.send(200, "application/json", statusJson());
  });
  server.onNotFound([] { server.send(404, "text/plain", "nicht gefunden"); });
  server.begin();
}

// ---------------------------------------------------------------------------

void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println("\nTraktor-Tracker startet");

  powerInit();
  Network.onEvent(onNetworkEvent);
  startHotspot();
  startWebServer();

  if (!modemPowerOn()) {
    Serial.println("Modem antwortet nicht, Neustart");
    delay(1000);
    ESP.restart();
  }
  gnssEnable();
  startMobile();

  power = powerRead();
  lastSeenExternalPower = power.externalPower;
  nextReportAt = millis();
}

void loop() {
  server.handleClient();
  uint32_t now = millis();

  if (now - lastGnssPoll >= GNSS_POLL_INTERVAL_S * 1000UL) {
    lastGnssPoll = now;
    GnssFix fix;
    gnssHasFix = gnssRead(fix);
    if (gnssHasFix) lastFix = fix;
    pppRssi = PPP.RSSI();
    power = powerRead();
  }

  // Wechsel zwischen Netz und Akku sofort melden.
  if (power.externalPower != lastSeenExternalPower) {
    lastSeenExternalPower = power.externalPower;
    Serial.printf("Stromversorgung: %s\n", power.externalPower ? "Netz" : "Akku");
    nextReportAt = now;
  }
  bool moving = gnssHasFix && lastFix.speedKmh >= MOVING_SPEED_KMH;
  uint32_t interval = (moving ? REPORT_INTERVAL_MOVING_S : REPORT_INTERVAL_PARKED_S) * 1000UL;
  // Losfahren: nicht das lange Stand-Intervall abwarten.
  if ((int32_t)(nextReportAt - now) > (int32_t)interval) nextReportAt = now + interval;
  if ((int32_t)(now - nextReportAt) >= 0) {
    // Fehlgeschlagen: in 30 s nochmal.
    nextReportAt = now + (reportToHomeAssistant() ? interval : 30000UL);
  }

  if (pppLostSince && now - pppLostSince > PPP_RESTART_AFTER_S * 1000UL) {
    Serial.println("Mobilfunk zu lange weg, Neustart");
    delay(500);
    ESP.restart();
  }

  delay(5);
}
