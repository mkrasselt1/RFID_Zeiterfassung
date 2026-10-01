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
#include <wifiManager.h>
#include <WiFiClientSecure.h>
// RFID-----------------------------
#include <SPI.h>
#include <MFRC522.h>
#include "Timer.h"
// OLED-----------------------------
#include <Wire.h>
#include <icons.h>
#include "StampingBuffer.h"

#include "StampingUploader.h"
#include "ConfigPortal.h"
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
//************************************************************************
// Declaration for SSD1306 display connected using software I2C pins are(22 SCL, 21 SDA)
#define SCREEN_WIDTH 128 // OLED display width, in pixels
#define SCREEN_HEIGHT 64 // OLED display height, in pixels
#define OLED_RESET 0     // Reset pin # (or -1 if sharing Arduino reset pin)
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);
//************************************************************************
SPIClass spi(HSPI);
MFRC522 mfrc522(5, 15); // Create MFRC522 instance.
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
int timezone = 2;
int time_dst = 0;

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
const unsigned long FLUSH_INTERVAL_MS = 30000;
const unsigned long WIFI_RETRY_MS = 15000;


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
String newRfidId = "";
long display_timeout = 0;
CardStates CardResult = IDLE;

//=======================================================================
//************send the Card UID to the website*************
void resetLastCard();
void SendCardID();
void checkNewCard();
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
  TimerEventCardRead =  readerTimer.every(300, checkNewCard);
  readerTimer.stop(TimerEventCardClear);
}

void checkNewCard()
{
  if (!mfrc522.PICC_IsNewCardPresent() || !mfrc522.PICC_ReadCardSerial())
  {
    return;
  }

  for (byte i = 0; i < mfrc522.uid.size; i++)
  {
    // !! Achtung es wird ein Leerzeichen vor der ID gesetzt !!
    newRfidId.concat(mfrc522.uid.uidByte[i] < 0x10 ? "0" : "");
    newRfidId.concat(String(mfrc522.uid.uidByte[i], HEX));
  }
  // alle Buchstaben in Großbuchstaben umwandeln
  newRfidId.toUpperCase();
  // Wenn die neue gelesene RFID-ID ungleich der bereits zuvor gelesenen ist,
  // dann soll diese auf der seriellen Schnittstelle ausgegeben werden.
  if (!newRfidId.equals(OldCardID))
  {
    //überschreiben der alten ID mit der neuen
    OldCardID = newRfidId;
    //---------------------------------------------
    CardResult = CARD_SEND;
    Serial.println(newRfidId);
    TimerEventCardSend = readerTimer.every(500, SendCardID);
    readerTimer.stop(TimerEventCardRead);
  }
}

void display_routine()
{
  // only if nothing to be displayed
  if (!display_timeout)
  {
    display.clearDisplay();
    display.setTextSize(2);      // Normal 1:1 pixel scale
    display.setTextColor(WHITE); // Draw white text
    switch (CardResult)
    {
    case CARD_SEND:
    {
      display.setCursor(0, 0);
      display.print("bitte warten");
      // display_timeout = millis() + 100;
    }
    break;
    case DEVICE_READY:
      display.setCursor(8, 0); // Start at top-left corner
      display.print(F("Verbunden \n"));
      display.drawBitmap(33, 15, Wifi_connected_bits, Wifi_connected_width, Wifi_connected_height, WHITE);
      display_timeout = millis() + 5000;
      break;
    case LOGIN_PICTURE:
      display.drawBitmap(5, 15, checkin_bits, CheckInOut_width, CheckInOut_height, WHITE);
      display_timeout = millis() + 1000;
      break;
    case LOGIN_NAME:
    {
      display.setCursor(0, 0);
      display.print("Willkommen");
      display.setCursor(0, 20);
      display.print(Card_Message);
      display_timeout = millis() + 3000;
    }
    break;
    case LOGOUT_PICTURE:
    {
      display.drawBitmap(5, 15, checkout_bits, CheckInOut_width, CheckInOut_height, WHITE);
      display_timeout = millis() + 1000;
    }
    break;
    case LOGOUT_NAME:
    {
      display.setCursor(0, 0);
      display.print("Tschuess");
      display.setCursor(0, 20);
      display.print(Card_Message);
      display_timeout = millis() + 2000;
    }
    break;
    case TIME:
    {
      time_t now = time(nullptr);
      struct tm *p_tm = localtime(&now);
      display.setTextSize(4); // Normal 2:2 pixel scale
      display.setCursor(0, 21);
      if ((p_tm->tm_hour) < 10)
      {
        display.print("0");
        display.print(p_tm->tm_hour);
      }
      else
      {
        display.print(p_tm->tm_hour);
      }
      display.print(":");
      if ((p_tm->tm_min) < 10)
      {
        display.print("0");
        display.println(p_tm->tm_min);
      }
      else
      {
        display.println(p_tm->tm_min);
      }
    }
    break;
    case CARD_ADD:
    {
      display.setCursor(5, 0); // Start at top-left corner
      display.print(F("Neue Karte"));
      display_timeout = millis() + 3000;
    }
    break;
    case CARD_FREE:
    {
      display.setCursor(5, 0); // Start at top-left corner
      display.print(F("Leere Karte"));
      display_timeout = millis() + 3000;
    }
    break;
    case CARD_ERROR:
    {
      display.setCursor(0, 0);
      display.print(Card_Message);
      display_timeout = millis() + 2000;
    }
    break;

    default:
      CardResult = IDLE;
    }
    display.display();
  }
  // Clear Screen
  if (display_timeout && display_timeout < millis())
  {
    display_timeout = 0; // Stop Routine from Displaying new stuff
    display.clearDisplay();
    // Decide whats next
    switch (CardResult)
    {
    case DEVICE_READY:
      CardResult = IDLE;
      break;
    case LOGIN_PICTURE:
      CardResult = LOGIN_NAME;
      break;
    case LOGIN_NAME:
      CardResult = IDLE;
      break;
    case LOGOUT_PICTURE:
      CardResult = LOGOUT_NAME;
      break;
    case LOGOUT_NAME:
      CardResult = IDLE;
      break;
    default:
      CardResult = IDLE;
    }
  }
}

//************************************************************************
void setup()
{
  delay(1000);
  Serial.begin(115200);
  Serial.print("Startup RFID Attendance v");
  Serial.print(CODE_VERSION);

  //-----------start rfid reader-------------
  delay(1000);
  SPI.begin();        // Init SPI bus
  mfrc522.PCD_Init(); // Init MFRC522 card
  delay(1000);        //
  mfrc522.PCD_DumpVersionToSerial();
  //---------------------------------------------

  //-----------initiate OLED display-------------
  if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C))
  { // Address 0x3D for 128x64
    Serial.println(F("Display allocation failed"));
    Serial.println(F("Restart in 5s - as no feedback possible"));
    delay(5000);
    ESP.restart();
  }
  //rotate screen if needed
  display.setRotation(2);
  display.clearDisplay();
  display.setTextSize(1);      // Normal 1:1 pixel scale
  display.setTextColor(WHITE); // Draw white text
  display.setCursor(0, 0);     // Start at top-left corner
  display.print(F("Startet \n"));
  display.setCursor(0, 50);
  display.setTextSize(2);
  display.drawBitmap(73, 10, Wifi_start_bits, Wifi_start_width, Wifi_start_height, WHITE);
  display.display();

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

  //---------------------------------------------
  configTime(timezone * 3600, time_dst, time_server, "time.nist.gov");

  //-----------Puffer und Upload-------------
  stampingBuffer.begin();
  stampingUploader.configure(backend_server, device_token, CODE_VERSION);
  Serial.printf("Gepufferte Stempelungen: %u\n", (unsigned)stampingBuffer.count());

  //-----------Konfiguration im Betrieb-------------
  // Eigene, passwortgeschützte Seite statt des WiFiManager-Portals: dessen
  // Konfigurationsseite kennt keine Anmeldung, und auf ihr stehen Serveradresse
  // und Token. Angemeldet wird sich mit dem Token des Lesers.
  ConfigPortal::Fields fields{
      time_server, sizeof(time_server),
      backend_server, sizeof(backend_server),
      device_token, sizeof(device_token),
  };
  configPortal.begin(fields, persistConfig);

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

  // Liegengebliebenes nachliefern, sobald wieder Netz da ist.
  if (WiFi.isConnected() && millis() - lastFlushAttempt > FLUSH_INTERVAL_MS)
  {
    lastFlushAttempt = millis();
    if (!stampingBuffer.isEmpty())
    {
      UploadOutcome outcome = stampingUploader.flush();
      if (outcome.delivered > 0)
      {
        Serial.printf("%u gepufferte Stempelungen zugestellt\n", (unsigned)outcome.delivered);
      }
    }
  }
}