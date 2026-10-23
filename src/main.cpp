/* -----------------------------------------------------------------------------
  - Project: RFID attendance system using ESP32
  - Author:  https://www.youtube.com/ElectronicsTechHaIs
  - Date:  6/03/2020
   -----------------------------------------------------------------------------
  This code was created by Electronics Tech channel for
  the RFID attendance project with ESP32.
   ---------------------------------------------------------------------------*/
//*******************************libraries********************************
// ESP32----------------------------
#include <FS.h>
#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <time.h>
#include <SPIFFS.h>
#include <ArduinoJson.h>
#include <WiFiManager.h>
#include <WiFiClientSecure.h>
// RFID-----------------------------
#include <SPI.h>
#include "CardReader.h"
#include "Timer.h"
// Anzeige--------------------------
#include <icons.h>
#include "Screen.h"
#include "StampingBuffer.h"

#include "StampingUploader.h"
#include "ConfigPortal.h"
#include "DeviceApi.h"
//************************************************************************
Timer readerTimer;
uint16_t TimerEventCardRead = 0;
uint16_t TimerEventCardClear = 0;
uint16_t TimerEventCardSend = 0;
//************************************************************************
unsigned long lastCardTime = 0;
bool shouldSaveConfig = false;
String OldCardID;

//************************************************************************
// configurable things
// Ab Werk steht ein Platzhalter im Gerät. Solange er gilt, ist der Leser nicht
// eingerichtet — das Display sagt es, und es zeigt, womit man sich anmeldet.
const char *PLACEHOLDER_TOKEN = "0123456789ABCDEF";
char device_token[17] = "0123456789ABCDEF";
char backend_server[100] = "https://server.example/getdata.php";
char time_server[30] = "pool.ntp.org";

WiFiManagerParameter custom_time_server("time_server", "time server", time_server, 30);
WiFiManagerParameter custom_backend_server("backend_server", "backend server", backend_server, 100);
WiFiManagerParameter custom_device_token("device_token", "device token", device_token, 17);

WiFiClientSecure client;

// Global, nicht nur in setup(): das Konfigurationsportal soll im Betrieb
// erreichbar bleiben, damit WLAN, Zeitserver und Serveradresse ohne
// Neuflashen zu ändern sind.
WiFiManager wifiManager;

// Zeitpunkt des letzten Upload-Versuchs und der letzten WLAN-Prüfung.
unsigned long lastFlushAttempt = 0;
unsigned long lastWifiCheck = 0;
unsigned long lastStatusReport = 0;
unsigned long lastConfigFetch = 0;
const unsigned long WIFI_RETRY_MS = 15000;

// Vom Server vorgegeben (GET /api/v1/device/config); bis zum ersten Abruf
// gelten die Vorgaben aus DeviceApi.h und die zuletzt gespeicherte Zeitzone.
RemoteConfig remoteConfig;
DeviceCounters counters;

// Wie oft im Ruhezustand nachgesehen wird, ob der Kartenleser noch antwortet.
unsigned long lastReaderCheck = 0;
const unsigned long READER_CHECK_MS = 10000;

// Eine aufgelegte Karte wird bei jedem Abfragen wieder gelesen. Gestempelt
// wird nur, wenn sie neu auftaucht — nicht alle paar Sekunden, solange sie
// liegen bleibt.
String heldCardUid;
unsigned long heldCardSeenMs = 0;
const unsigned long CARD_GONE_MS = 2500;


enum CardStates
{
  IDLE,
  DEVICE_READY,
  CARD_SEND,
  LOGIN_PICTURE,
  LOGIN_NAME,
  LOGOUT_PICTURE,
  LOGOUT_NAME,
  TIME,
  CARD_ADD,
  CARD_FREE,
  CARD_ERROR
};
String Card_Message = "";
// Zweite Zeile nach dem Stempeln: Uhrzeit und Arbeitszeit des Tages.
String Card_Detail = "";
String newRfidId = "";
long display_timeout = 0;
CardStates CardResult = IDLE;

//=======================================================================
//************send the Card UID to the website*************
void resetLastCard();
void SendCardID();
void checkNewCard();

static String hoursMinutes(int minutes)
{
  char text[8];
  snprintf(text, sizeof(text), "%d:%02d", minutes / 60, minutes % 60);
  return text;
}

/** "07:03  6:12/8:00" — gebuchte Uhrzeit, heute gearbeitet, Soll. */
static String dayDetail(const UploadOutcome &outcome)
{
  String detail = outcome.lastTime;
  if (outcome.lastWorkedMinutes >= 0)
  {
    if (detail.length())
    {
      detail += "  ";
    }
    detail += hoursMinutes(outcome.lastWorkedMinutes);
    if (outcome.lastExpectedMinutes > 0)
    {
      detail += "/" + hoursMinutes(outcome.lastExpectedMinutes);
    }
  }
  return detail;
}

void SendCardID()
{
  TimerEventCardClear = readerTimer.every(2000, resetLastCard);
  readerTimer.stop(TimerEventCardSend);
  Serial.println("Sending the Card ID");
  lastCardTime = millis();

  // Erst puffern, dann senden. Früher ging die Stempelung verloren, sobald das
  // WLAN weg war — die Person hatte gestempelt, das System wusste nichts davon.
  // Jetzt liegt sie sicher auf dem Gerät und wird so lange nachgeliefert, bis
  // der Server sie bestätigt.
  time_t now = time(nullptr);
  String ownUid = stampingBuffer.add(newRfidId, now);
  counters.stamped++;
  Card_Detail = "";
  if (ownUid.length() == 0)
  {
    CardResult = CARD_ERROR;
    Card_Message = "Speicher voll";
    Serial.println(Card_Message);
    return;
  }

  // Mit der eigenen Kennung findet sich in der Antwort wieder, was aus genau
  // dieser Stempelung wurde — auch wenn davor noch Liegengebliebenes mitgeht.
  UploadOutcome outcome = stampingUploader.flush(ownUid);
  lastFlushAttempt = millis();
  counters.uploaded += outcome.delivered;
  Card_Detail = dayDetail(outcome);

  if (!outcome.ok)
  {
    // Nicht zugestellt — aber nicht verloren. Das sagen wir auch so.
    CardResult = CARD_ERROR;
    Card_Message = outcome.lastMessage.length() > 0
                       ? outcome.lastMessage + " - gespeichert"
                       : String("Gespeichert, sende spaeter");
    Serial.println(Card_Message);
    return;
  }

  if (outcome.lastStatus == "checkin")
  {
    Card_Message = outcome.lastName;
    CardResult = LOGIN_PICTURE;
  }
  else if (outcome.lastStatus == "checkout")
  {
    Card_Message = outcome.lastName;
    CardResult = LOGOUT_PICTURE;
  }
  else if (outcome.lastStatus == "learned")
  {
    CardResult = CARD_ADD;
  }
  else if (outcome.lastStatus == "known")
  {
    CardResult = CARD_FREE;
  }
  else if (outcome.lastStatus == "failed")
  {
    CardResult = CARD_ERROR;
    Card_Message = outcome.lastMessage;
  }
  else
  {
    // Zugestellt, aber ohne Antwort zu dieser Karte (etwa weil der Rückstau
    // größer war als ein Schwung). Kein Fehler — nur noch keine Rückmeldung.
    CardResult = CARD_SEND;
    Card_Message = "Gesendet";
  }

  Serial.println(Card_Message);
}

//=======================================================================

//************************************************************************
// callback notifying us of the need to save config
/** Die aktuellen Felder nach SPIFFS schreiben — ohne Neustart. */
void persistConfig()
{
  DynamicJsonDocument json(1024);
  json["time_server"] = time_server;
  json["backend_server"] = backend_server;
  json["device_token"] = device_token;
  // Zuletzt vom Server bekommene Zeitzone: nach einem Neustart ohne Netz
  // stimmt die Uhr sonst um die Sommerzeit nicht.
  json["tz"] = remoteConfig.tz;

  File configFile = SPIFFS.open("/config.json", "w");
  if (!configFile)
  {
    Serial.println("failed to open config file for writing");
    return;
  }
  serializeJson(json, configFile);
  configFile.close();
  Serial.println("config saved");
}

void saveConfigCallback()
{
  Serial.println("config shall be saved");
  DynamicJsonDocument json(1024);
  json["time_server"] = custom_time_server.getValue();
  json["backend_server"] = custom_backend_server.getValue();
  json["device_token"] = custom_device_token.getValue();

  File configFile = SPIFFS.open("/config.json", "w");
  if (!configFile)
  {
    Serial.println("failed to open config file for writing");
  }

  serializeJson(json, Serial);
  serializeJson(json, configFile);
  configFile.close();
  ESP.restart();
  // end save
}
//=======================================================================

//************************************************************************
void resetLastCard()
{
  OldCardID = "";
  newRfidId = "";
  // Während gesendet wurde, lief keine Abfrage. Liegt die Karte noch, soll
  // sie jetzt nicht als neu gelten.
  heldCardSeenMs = millis();
  TimerEventCardRead =  readerTimer.every(300, checkNewCard);
  readerTimer.stop(TimerEventCardClear);
}

void checkNewCard()
{
  String uid;
  if (!cardReader.readCard(uid))
  {
    return;
  }
  const bool stillHeld = uid == heldCardUid && millis() - heldCardSeenMs < CARD_GONE_MS;
  heldCardUid = uid;
  heldCardSeenMs = millis();
  if (stillHeld)
  {
    return;
  }
  newRfidId = uid;
  // Wenn die neue gelesene RFID-ID ungleich der bereits zuvor gelesenen ist,
  // dann soll diese auf der seriellen Schnittstelle ausgegeben werden.
  if (!newRfidId.equals(OldCardID))
  {
    //überschreiben der alten ID mit der neuen
    OldCardID = newRfidId;
    //---------------------------------------------
    CardResult = CARD_SEND;
    Serial.println(newRfidId);
    // Kurz Luft lassen, damit "bitte warten" auf dem Display steht, bevor
    // die Anfrage den Ablauf für eine Sekunde anhält.
    TimerEventCardSend = readerTimer.every(50, SendCardID);
    readerTimer.stop(TimerEventCardRead);
  }
}

// Farben der Teile eines Symbols, in der Reihenfolge, in der sie beim Lesen
// von oben links zuerst auftauchen (siehe Screen::centerIcon).
static const uint16_t CHECKIN_COLORS[] = {Screen::WHITE, Screen::GREEN};   // Tür, Pfeil
static const uint16_t CHECKOUT_COLORS[] = {Screen::WHITE, Screen::ORANGE}; // Tür, Pfeil
static const uint16_t WIFI_COLORS[] = {0x2B1F, Screen::BLUE, 0x7EFF, Screen::WHITE};
static const uint16_t WIFI_START_COLORS[] = {Screen::GREY, Screen::GREY, Screen::GREY,
                                             Screen::ORANGE, Screen::GREY, Screen::WHITE};

/** Ruhebildschirm: Uhr, Name des Lesers — und was nicht stimmt. */
static void idleScreen()
{
  const int16_t small = screen.lineHeight(1) + 2;
  int16_t y = 0;

  if (Screen::HAS_COLOR)
  {
    time_t now = time(nullptr);
    if (now > 1600000000)
    {
      struct tm local;
      localtime_r(&now, &local);
      char clock[6];
      snprintf(clock, sizeof(clock), "%02d:%02d", local.tm_hour, local.tm_min);
      screen.centerText(clock, 4, 4);
    }
    y = 4 + screen.lineHeight(4) + 6;
    screen.centerText(remoteConfig.name, y, 1, Screen::GREY);
    y += small;
  }

  // Hinweise, wichtigstes zuerst. Auf dem OLED ist der Bildschirm sonst
  // schwarz, damit sich nichts einbrennt — Hinweise zeigt er trotzdem.
  if (strcmp(device_token, PLACEHOLDER_TOKEN) == 0)
  {
    screen.centerText("Nicht eingerichtet", y, 1, Screen::RED);
    y += small;
    screen.centerText("http://" + WiFi.localIP().toString(), y, 1, Screen::YELLOW);
    y += small;
    screen.centerText(String("admin / ") + PLACEHOLDER_TOKEN, y, 1, Screen::YELLOW);
    return;
  }
  if (!cardReader.found())
  {
    screen.centerText("Kein Kartenleser", y, 1, Screen::RED);
    y += small;
  }
  if (remoteConfig.mode == "learn")
  {
    screen.centerText("Karten anlernen", y, 1, Screen::YELLOW);
    y += small;
  }
  if (!WiFi.isConnected())
  {
    screen.centerText("Kein WLAN", y, 1, Screen::ORANGE);
    y += small;
  }
  size_t pending = stampingBuffer.count();
  if (pending > 0)
  {
    screen.centerText(String(pending) + " im Puffer", y, 1, Screen::ORANGE);
  }
}

void display_routine()
{
  // only if nothing to be displayed
  if (!display_timeout)
  {
    screen.clear();
    const int16_t line = screen.lineHeight(2) + 4;
    switch (CardResult)
    {
    case CARD_SEND:
      screen.centerText("bitte warten", 0, 2);
      break;
    case DEVICE_READY:
      screen.centerText("Verbunden", 0, 2);
      screen.centerIcon(Wifi_connected_bits, Wifi_connected_width, Wifi_connected_height,
                        screen.height() - screen.iconHeight(Wifi_connected_height), WIFI_COLORS, 4);
      display_timeout = millis() + 5000;
      break;
    case LOGIN_PICTURE:
      screen.centerIcon(checkin_bits, CheckInOut_width, CheckInOut_height,
                        (screen.height() - screen.iconHeight(CheckInOut_height)) / 2, CHECKIN_COLORS, 2);
      display_timeout = millis() + 1000;
      break;
    case LOGIN_NAME:
      screen.centerText("Willkommen", 0, 2, Screen::GREEN);
      screen.centerText(Card_Message, line, 2);
      screen.centerText(Card_Detail, 2 * line, 2, Screen::GREY);
      display_timeout = millis() + 3000;
      break;
    case LOGOUT_PICTURE:
      screen.centerIcon(checkout_bits, CheckInOut_width, CheckInOut_height,
                        (screen.height() - screen.iconHeight(CheckInOut_height)) / 2, CHECKOUT_COLORS, 2);
      display_timeout = millis() + 1000;
      break;
    case LOGOUT_NAME:
      screen.centerText("Tschuess", 0, 2, Screen::ORANGE);
      screen.centerText(Card_Message, line, 2);
      screen.centerText(Card_Detail, 2 * line, 2, Screen::GREY);
      display_timeout = millis() + 3000;
      break;
    case CARD_ADD:
      screen.centerText("Neue Karte", 0, 2, Screen::YELLOW);
      display_timeout = millis() + 3000;
      break;
    case CARD_FREE:
      screen.centerText("Leere Karte", 0, 2, Screen::YELLOW);
      display_timeout = millis() + 3000;
      break;
    case CARD_ERROR:
      screen.centerText(Card_Message, 0, 2, Screen::RED);
      display_timeout = millis() + 2000;
      break;

    default:
      CardResult = IDLE;
      idleScreen();
    }
    screen.show();
  }
  // Clear Screen
  if (display_timeout && display_timeout < millis())
  {
    display_timeout = 0; // Stop Routine from Displaying new stuff
    screen.clear();
    // Decide whats next
    switch (CardResult)
    {
    case LOGIN_PICTURE:
      CardResult = LOGIN_NAME;
      break;
    case LOGOUT_PICTURE:
      CardResult = LOGOUT_NAME;
      break;
    default:
      CardResult = IDLE;
    }
  }
}

/** Zeitzone und Zeitserver setzen; die Regel bringt die Sommerzeit mit. */
static void applyTime()
{
  const char *ntp = remoteConfig.ntpServer.length() ? remoteConfig.ntpServer.c_str() : time_server;
  configTzTime(remoteConfig.tz.c_str(), ntp, time_server);
}

/** Konfiguration vom Server holen; geänderte Zeitzone sofort anwenden und merken. */
static void refreshConfig()
{
  lastConfigFetch = millis();
  String tzBefore = remoteConfig.tz;
  String ntpBefore = remoteConfig.ntpServer;
  if (!deviceApi.fetchConfig(remoteConfig))
  {
    return;
  }
  Serial.printf("Konfiguration: %s, Modus %s, TZ %s\n", remoteConfig.name.c_str(),
                remoteConfig.mode.c_str(), remoteConfig.tz.c_str());
  if (tzBefore != remoteConfig.tz || ntpBefore != remoteConfig.ntpServer)
  {
    applyTime();
    persistConfig();
  }
}

static String uptimeText()
{
  unsigned long s = millis() / 1000UL;
  char text[24];
  snprintf(text, sizeof(text), "%lud %02lu:%02lu:%02lu", s / 86400, (s / 3600) % 24, (s / 60) % 60, s % 60);
  return text;
}

/** Inhalt der offenen Statusseite des Lesers — nichts Geheimes, keine Namen. */
static void fillStatus(ConfigPortal::StatusLines &lines)
{
  time_t now = time(nullptr);
  String clock = "unbekannt";
  if (now > 1600000000)
  {
    struct tm local;
    localtime_r(&now, &local);
    char text[24];
    strftime(text, sizeof(text), "%d.%m.%Y %H:%M:%S", &local);
    clock = text;
  }
  String host = backend_server;
  host.replace("https://", "");
  host.replace("http://", "");
  host = host.substring(0, host.indexOf('/') >= 0 ? host.indexOf('/') : host.length());

  lines.push_back({"firmware", "Firmware", CODE_VERSION});
  lines.push_back({"name", "Name im Panel", remoteConfig.name.length() ? remoteConfig.name : String("–")});
  lines.push_back({"mode", "Modus", remoteConfig.mode == "learn" ? "Karten anlernen" : "Zeiterfassung"});
  lines.push_back({"configured", "Eingerichtet", strcmp(device_token, PLACEHOLDER_TOKEN) == 0 ? "nein" : "ja"});
  lines.push_back({"reader", "Kartenleser", cardReader.name()});
  lines.push_back({"server", "Server", host});
  lines.push_back({"api", "Schnittstelle", deviceApi.usesLegacyStampings() ? "/api/v1/stampings (Server noch alt)" : "/api/v1/device"});
  lines.push_back({"time", "Uhrzeit", clock});
  lines.push_back({"uptime", "Läuft seit", uptimeText()});
  lines.push_back({"ip", "Adresse", WiFi.localIP().toString()});
  lines.push_back({"ssid", "WLAN", WiFi.SSID()});
  lines.push_back({"rssi", "Empfang", String(WiFi.RSSI()) + " dBm"});
  lines.push_back({"pending", "Im Puffer", String((unsigned)stampingBuffer.count())});
  lines.push_back({"stamped", "Gestempelt seit Start", String(counters.stamped)});
  lines.push_back({"uploaded", "Zugestellt seit Start", String(counters.uploaded)});
  lines.push_back({"free_heap", "Freier Speicher", String(ESP.getFreeHeap() / 1024) + " KB"});
}

static void reportStatus()
{
  lastStatusReport = millis();
  deviceApi.reportStatus(cardReader.name(), stampingBuffer.count(), counters);
}

//************************************************************************
void setup()
{
  delay(1000);
  Serial.begin(115200);
  Serial.print("Startup RFID Attendance v");
  Serial.println(CODE_VERSION);

  //-----------initiate display-------------
  if (!screen.begin())
  {
    Serial.println(F("Display allocation failed"));
    Serial.println(F("Restart in 5s - as no feedback possible"));
    delay(5000);
    ESP.restart();
  }
  screen.clear();
  screen.centerText("Startet", 0, 2);
  screen.centerIcon(Wifi_start_bits, Wifi_start_width, Wifi_start_height,
                    screen.height() - screen.iconHeight(Wifi_start_height), WIFI_START_COLORS, 6);
  // Version oben rechts: wer vor dem Gerät steht, soll sie ablesen können.
  screen.rightText(String("v") + CODE_VERSION, screen.height() - screen.lineHeight(1), 1, Screen::GREY);
  screen.show();

  //-----------start rfid reader-------------
  // Wer den Leser aufbaut, soll sofort sehen, ob er richtig verdrahtet ist.
  bool readerFound = cardReader.begin();
  screen.clear();
  screen.centerText(readerFound ? "Leser erkannt" : "Kein Leser!", 0, 2,
                    readerFound ? Screen::GREEN : Screen::RED);
  screen.centerText(readerFound ? cardReader.name() : String("PN532 / RC522 pruefen"),
                    screen.lineHeight(2) + 8, 1);
  screen.show();
  delay(1500);

  //-----------init and load config from spiffs-------------
  Serial.println("mounting FS...");

  if (SPIFFS.begin())
  {
    Serial.println("mounted file system");
    if (SPIFFS.exists("/config.json"))
    {
      // file exists, reading and loading
      Serial.println("reading config file");
      File configFile = SPIFFS.open("/config.json", "r");
      if (configFile)
      {
        Serial.println("opened config file");
        size_t size = configFile.size();
        // Allocate a buffer to store contents of the file.
        std::unique_ptr<char[]> buf(new char[size]);

        configFile.readBytes(buf.get(), size);

        DynamicJsonDocument json(1024);
        auto deserializeError = deserializeJson(json, buf.get());
        serializeJson(json, Serial);
        if (!deserializeError)
        {
          Serial.println("\nparsed json");
          strcpy(time_server, json["time_server"]);
          strcpy(backend_server, json["backend_server"]);
          strcpy(device_token, json["device_token"]);
          if (json.containsKey("tz"))
          {
            remoteConfig.tz = json["tz"].as<String>();
          }
        }
        else
        {
          Serial.println("failed to load json config");
        }
        configFile.close();
      }
    }
  }
  else
  {
    Serial.println("failed to mount FS");
    SPIFFS.format();
    Serial.println("FS formated");
    ESP.restart();
  }
  // end read

  //-----------self configuration page-------------
  // WiFiManager

  // set config save notify callback
  wifiManager.setSaveConfigCallback(saveConfigCallback);
  // Ohne bekanntes WLAN wartet das Gerät hier, bis jemand es einrichtet.
  // Wer davorsteht, soll lesen können, womit er sich verbinden muss.
  wifiManager.setAPCallback([](WiFiManager *wm) {
    screen.clear();
    screen.centerText("Einrichten:", 0, 1);
    screen.centerText(wm->getConfigPortalSSID(), screen.lineHeight(1) + 4, 1);
    screen.centerText("192.168.4.1", 2 * (screen.lineHeight(1) + 4), 1);
    screen.show();
  });
  wifiManager.addParameter(&custom_time_server);
  wifiManager.addParameter(&custom_backend_server);
  wifiManager.addParameter(&custom_device_token);

  String hostname = "Zeiterfassung";
  hostname.concat(WiFi.macAddress());
  WiFi.hostname(hostname);
  //Give more time to connect to access point
  wifiManager.setConnectTimeout(300);
  wifiManager.setConnectRetries(5);
  
  // reset saved settings
  // wifiManager.resetSettings();
  if (!wifiManager.autoConnect("ESP-Attendance-Setup"))
  {
    Serial.println("failed to connect and hit timeout");
    delay(3000);
    // reset and try again, or maybe put it to deep sleep
    ESP.restart();
    delay(5000);
  }

  //-----------Uhrzeit, Server-Konfiguration-------------
  applyTime();
  deviceApi.configure(backend_server, device_token, CODE_VERSION);
  refreshConfig();

  //-----------Puffer und Upload-------------
  stampingBuffer.begin();
  Serial.printf("Gepufferte Stempelungen: %u\n", (unsigned)stampingBuffer.count());
  reportStatus();

  //-----------Konfiguration im Betrieb-------------
  // Eigene, passwortgeschützte Seite statt des WiFiManager-Portals: dessen
  // Konfigurationsseite kennt keine Anmeldung, und auf ihr stehen Serveradresse
  // und Token. Angemeldet wird sich mit dem Token des Lesers.
  ConfigPortal::Fields fields{
      time_server, sizeof(time_server),
      backend_server, sizeof(backend_server),
      device_token, sizeof(device_token),
  };
  configPortal.begin(fields, persistConfig, fillStatus);

  Serial.println("device ready");
  CardResult = DEVICE_READY;
  TimerEventCardRead =  readerTimer.every(300, checkNewCard);
}
//************************************************************************
void loop()
{
  // single character user interface
  if (Serial.available())
  {
    switch (Serial.read())
    {
    case 'r':
      ESP.restart();
      break;
    default:
      break;
    }
  }

  //display routine
  display_routine();

  //reader actions
  readerTimer.update();

  // Konfigurationsseite bedienen (nicht blockierend).
  configPortal.handle();

  // Früher startete das Gerät bei jedem WLAN-Aussetzer neu. Das machte den
  // Puffer wertlos und riss die Stempeluhr für eine Minute aus dem Betrieb.
  // Jetzt wird nur neu verbunden — gestempelt werden kann auch ohne Netz.
  if (!WiFi.isConnected() && millis() - lastWifiCheck > WIFI_RETRY_MS)
  {
    lastWifiCheck = millis();
    Serial.println(F("WLAN weg - versuche erneut zu verbinden"));
    WiFi.reconnect();
  }

  // Nur im Ruhezustand: mitten im Stempeln soll niemand den Leser neu starten.
  if (CardResult == IDLE && millis() - lastReaderCheck > READER_CHECK_MS)
  {
    lastReaderCheck = millis();
    cardReader.check();
  }

  // Beim Server melden und nachsehen, ob sich im Panel etwas geändert hat.
  if (WiFi.isConnected() && millis() - lastStatusReport > remoteConfig.statusSeconds * 1000UL)
  {
    reportStatus();
  }
  if (WiFi.isConnected() && millis() - lastConfigFetch > remoteConfig.configSeconds * 1000UL)
  {
    refreshConfig();
  }

  // Liegengebliebenes nachliefern, sobald wieder Netz da ist.
  if (WiFi.isConnected() && millis() - lastFlushAttempt > remoteConfig.flushSeconds * 1000UL)
  {
    lastFlushAttempt = millis();
    if (!stampingBuffer.isEmpty())
    {
      UploadOutcome outcome = stampingUploader.flush();
      counters.uploaded += outcome.delivered;
      if (outcome.delivered > 0)
      {
        Serial.printf("%u gepufferte Stempelungen zugestellt\n", (unsigned)outcome.delivered);
      }
    }
  }
}