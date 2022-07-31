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
#include <MFRC522.h>
// OLED-----------------------------
#include <Wire.h>
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

//************************************************************************
unsigned long previousMillis1 = 0;
unsigned long previousMillis2 = 0;
unsigned long previousMillis3 = 0;
bool shouldSaveConfig = false;
String OldCardID;

//************************************************************************
// configurable things
int timezone = 2;
int time_dst = 0;

char device_token[30] = "0123456789ABCDEF";
char backend_server[100] = "https://server.example/getdata.php";
char time_server[30] = "pool.ntp.org";

WiFiManagerParameter custom_time_server("time_server", "time server", time_server, 30);
WiFiManagerParameter custom_backend_server("backend_server", "backend server", backend_server, 100);
WiFiManagerParameter custom_device_token("device_token", "device token", device_token, 30);

//*************************Biometric Icons*********************************
#define Wifi_start_width 54
#define Wifi_start_height 49
const uint8_t PROGMEM Wifi_start_bits[] = {
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x1f, 0xf0, 0x00, 0x00, 0x00, 0x00, 0x03, 0xff, 0xff, 0x80, 0x00, 0x00, 0x00, 0x1f, 0xf0, 0x1f, 0xf0, 0x00, 0x00, 0x00, 0x7e, 0x00, 0x00, 0xfc, 0x00, 0x00, 0x01, 0xf0, 0x00, 0x00, 0x1f, 0x00, 0x00, 0x03, 0xc0, 0x00, 0x00, 0x07, 0xc0, 0x00, 0x0f, 0x00, 0x00, 0x00, 0x01, 0xe0, 0x00, 0x1c, 0x00, 0x00, 0x00, 0x00, 0x70, 0x00, 0x38, 0x00, 0x07, 0xc0, 0x00, 0x38, 0x00, 0x70, 0x00, 0xff, 0xfe, 0x00, 0x1e, 0x00, 0xe0, 0x03, 0xfc, 0x7f, 0xc0, 0x0e, 0x00, 0x00, 0x1f, 0x80, 0x03, 0xf0, 0x00, 0x00, 0x00, 0x3c, 0x00, 0x00, 0x78, 0x00, 0x00, 0x00, 0xf0, 0x00, 0x00, 0x1c, 0x00, 0x00, 0x01, 0xe0, 0x00, 0x00, 0x0c, 0x00, 0x00, 0x03, 0x80, 0x00, 0x00, 0x00, 0x00, 0x00, 0x03, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xf8, 0x07, 0x1e, 0x00, 0x00, 0x00, 0xff, 0xfe, 0x1f, 0xbf, 0x80, 0x00, 0x03, 0xe0, 0x04, 0x7f, 0xff, 0xc0, 0x00, 0x07, 0x80, 0x00, 0xff, 0xff, 0xe0, 0x00, 0x0e, 0x00, 0x00, 0xff, 0xff, 0xe0, 0x00, 0x0c, 0x00, 0x00, 0x7f, 0xff, 0xc0, 0x00, 0x00, 0x00, 0x00, 0xfe, 0x07, 0xe0, 0x00, 0x00, 0x00, 0x03, 0xf8, 0x03, 0xf8, 0x00, 0x00, 0x07, 0xe7, 0xf9, 0xf1, 0xfc, 0x00, 0x00, 0x1f, 0xe7, 0xf1, 0xf9, 0xfc, 0x00, 0x00, 0x1f, 0xe7, 0xf3, 0xf9, 0xfc, 0x00, 0x00, 0x3f, 0xe7, 0xf3, 0xf9, 0xfc, 0x00, 0x00, 0x3f, 0xe7, 0xf1, 0xf1, 0xfc, 0x00, 0x00, 0x3f, 0xe3, 0xf8, 0xe3, 0xfc, 0x00, 0x00, 0x3f, 0xf3, 0xfc, 0x07, 0xf8, 0x00, 0x00, 0x1f, 0xf0, 0x7f, 0x0f, 0xc0, 0x00, 0x00, 0x0f, 0xe0, 0x7f, 0xff, 0xe0, 0x00, 0x00, 0x07, 0xc0, 0xff, 0xff, 0xe0, 0x00, 0x00, 0x00, 0x00, 0x7f, 0xff, 0xe0, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xff, 0x80, 0x00, 0x00, 0x00, 0x00, 0x1f, 0xbf, 0x00, 0x00, 0x00, 0x00, 0x00, 0x03, 0x18, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00};
#define Wifi_connected_width 63
#define Wifi_connected_height 49
const uint8_t PROGMEM Wifi_connected_bits[] = {
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x03, 0xff, 0xff, 0x80, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xff, 0xff, 0xf8, 0x00, 0x00, 0x00, 0x01, 0xff, 0xff, 0xff, 0xff, 0x00, 0x00, 0x00, 0x0f, 0xff, 0xff, 0xff, 0xff, 0xe0, 0x00, 0x00, 0x3f, 0xff, 0xc0, 0x07, 0xff, 0xf8, 0x00, 0x00, 0xff, 0xf8, 0x00, 0x00, 0x3f, 0xfe, 0x00, 0x03, 0xff, 0x80, 0x00, 0x00, 0x03, 0xff, 0x80, 0x07, 0xfe, 0x00, 0x00, 0x00, 0x00, 0xff, 0xc0, 0x1f, 0xf8, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xf0, 0x3f, 0xe0, 0x01, 0xff, 0xff, 0x00, 0x0f, 0xf8, 0x7f, 0x80, 0x0f, 0xff, 0xff, 0xe0, 0x03, 0xfc, 0xff, 0x00, 0x7f, 0xff, 0xff, 0xfc, 0x01, 0xfe, 0xfc, 0x01, 0xff, 0xff, 0xff, 0xff, 0x00, 0x7e, 0x78, 0x07, 0xff, 0xc0, 0x07, 0xff, 0xc0, 0x3c, 0x00, 0x0f, 0xfc, 0x00, 0x00, 0x7f, 0xe0, 0x00, 0x00, 0x1f, 0xf0, 0x00, 0x00, 0x1f, 0xf0, 0x00, 0x00, 0x3f, 0xc0, 0x00, 0x00, 0x07, 0xf8, 0x00, 0x00, 0x7f, 0x00, 0x01, 0x00, 0x01, 0xfc, 0x00, 0x00, 0x7e, 0x00, 0x7f, 0xfc, 0x00, 0xfc, 0x00, 0x00, 0x3c, 0x03, 0xff, 0xff, 0x80, 0x78, 0x00, 0x00, 0x00, 0x07, 0xff, 0xff, 0xc0, 0x00, 0x00, 0x00, 0x00, 0x1f, 0xff, 0xff, 0xf0, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xf0, 0x1f, 0xf8, 0x00, 0x00, 0x00, 0x00, 0x3f, 0x80, 0x03, 0xf8, 0x00, 0x00, 0x00, 0x00, 0x3f, 0x00, 0x01, 0xf8, 0x00, 0x00, 0x00, 0x00, 0x1c, 0x00, 0x00, 0x70, 0x00, 0x00, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x0f, 0xe0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x1f, 0xf0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xf8, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xf8, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xf8, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x3f, 0xf8, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x1f, 0xf0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x0f, 0xe0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00};

WiFiClientSecure client;


//********************connect to the WiFi******************
void displayReady()
{
  Serial.println("device ready");
  display.clearDisplay();
  display.setTextSize(2);      // Normal 1:1 pixel scale
  display.setTextColor(WHITE); // Draw white text
  display.setCursor(8, 0);     // Start at top-left corner
  display.print(F("Connected \n"));
  display.drawBitmap(33, 15, Wifi_connected_bits, Wifi_connected_width, Wifi_connected_height, WHITE);
  display.display();
  delay(1000);
}
//=======================================================================
//************send the Card UID to the website*************
void SendCardID(String Card_uid)
{
  Serial.println("Sending the Card ID");
  if (WiFi.isConnected())
  {
    HTTPClient http; // Declare object of class HTTPClient
    // GET Data
    String temp_url;
    temp_url = backend_server;
    temp_url.concat("?card_uid=");
    temp_url.concat(Card_uid);
    temp_url.concat("&device_token=");
    temp_url.concat(device_token); // Add the Card ID to the GET array in order to send it

    // GET method
    http.begin(temp_url); // initiate HTTP request
    int httpCode = http.GET();         // Send the request
    String payload = http.getString(); // Get the response payload

    //    Serial.println(Link);   //Print HTTP return code
    Serial.println(httpCode); // Print HTTP return code
    Serial.println(Card_uid); // Print Card ID
    Serial.println(payload);  // Print request response payload

    if (httpCode == 200)
    {
      if (payload.substring(0, 5) == "login")
      {
        String user_name = payload.substring(5);
        Serial.println(user_name);

        display.clearDisplay();
        display.setTextSize(2);      // Normal 2:2 pixel scale
        display.setTextColor(WHITE); // Draw white text
        display.setCursor(15, 0);    // Start at top-left corner
        display.print(F("Welcome"));
        display.setCursor(0, 20);
        display.print(user_name);
        display.display();
      }
      else if (payload.substring(0, 6) == "logout")
      {
        String user_name = payload.substring(6);
        Serial.println(user_name);

        display.clearDisplay();
        display.setTextSize(2);      // Normal 2:2 pixel scale
        display.setTextColor(WHITE); // Draw white text
        display.setCursor(10, 0);    // Start at top-left corner
        display.print(F("Good Bye"));
        display.setCursor(0, 20);
        display.print(user_name);
        display.display();
      }
      else if (payload == "succesful")
      {
        display.clearDisplay();
        display.setTextSize(2);      // Normal 2:2 pixel scale
        display.setTextColor(WHITE); // Draw white text
        display.setCursor(5, 0);     // Start at top-left corner
        display.print(F("New Card"));
        display.display();
      }
      else if (payload == "available")
      {
        display.clearDisplay();
        display.setTextSize(2);      // Normal 2:2 pixel scale
        display.setTextColor(WHITE); // Draw white text
        display.setCursor(5, 0);     // Start at top-left corner
        display.print(F("Free Card"));
        display.display();
      }
      delay(100);
      http.end(); // Close connection
    }
  }
}
//=======================================================================

//************************************************************************
// callback notifying us of the need to save config
void saveConfigCallback()
{
  Serial.println("config shall be saved");
  shouldSaveConfig = true;
}
//=======================================================================

//************************************************************************
// display time
void displayTime()
{
  display.clearDisplay();

  time_t now = time(nullptr);
  struct tm *p_tm = localtime(&now);
  display.setTextSize(1);      // Normal 2:2 pixel scale
  display.setTextColor(WHITE); // Draw white text
  display.setCursor(10, 0);

  display.setTextSize(4);      // Normal 2:2 pixel scale
  display.setTextColor(WHITE); // Draw white text
  display.setCursor(0, 21);
  if ((p_tm->tm_hour) < 10)
  {
    display.print("0");
    display.print(p_tm->tm_hour);
  }
  else
    display.print(p_tm->tm_hour);
  display.print(":");
  if ((p_tm->tm_min) < 10)
  {
    display.print("0");
    display.println(p_tm->tm_min);
  }
  else
    display.println(p_tm->tm_min);
  display.display();
}
//=======================================================================

//************************************************************************
void checkNewCard()
{
  if (!mfrc522.PICC_IsNewCardPresent() || !mfrc522.PICC_ReadCardSerial())
  {
    return;
  }

  String newRfidId = "";
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
    Serial.println(newRfidId);
    SendCardID(newRfidId);
  }
}

//************************************************************************
void setup()
{
  delay(1000);
  Serial.begin(115200);

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
    for (;;)
      ; // Don't proceed, loop forever
  }
  display.clearDisplay();
  display.setTextSize(1);      // Normal 1:1 pixel scale
  display.setTextColor(WHITE); // Draw white text
  display.setCursor(0, 0);     // Start at top-left corner
  display.print(F("Booting \n"));
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
    ESP.restart();
  }
  // end read

  //-----------self configuration page-------------
  // WiFiManager
  WiFiManager WiFiManager;

  // set config save notify callback
  WiFiManager.setSaveConfigCallback(saveConfigCallback);
  WiFiManager.addParameter(&custom_time_server);
  WiFiManager.addParameter(&custom_backend_server);
  WiFiManager.addParameter(&custom_device_token);

  // reset saved settings
  // WiFiManager.resetSettings();
  if (!WiFiManager.autoConnect("ESP-Attendance-Setup"))
  {
    Serial.println("failed to connect and hit timeout");
    delay(3000);
    // reset and try again, or maybe put it to deep sleep
    ESP.restart();
    delay(5000);
  }

  //---------------------------------------------
  configTime(timezone * 3600, time_dst, time_server, "time.nist.gov");

  displayReady();
}
//************************************************************************
void loop()
{
  //---------------------------------------------
  if (millis() - previousMillis1 >= 15000)
  {
    previousMillis1 = millis();
    displayTime();
  }
  //---------------------------------------------
  if (millis() - previousMillis2 >= 15000)
  {
    previousMillis2 = millis();
    OldCardID = "";
  }
  //---------------------------------------------
  if (millis() - previousMillis3 >= 200)
  {
    previousMillis3 = millis();
    checkNewCard();
  }
  delay(50);
}