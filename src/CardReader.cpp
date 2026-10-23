#include "CardReader.h"

#include <SPI.h>
#include <MFRC522.h>
#include <Adafruit_PN532.h>

CardReader cardReader;

//************************************************************************
// Pins. Ohne Angabe die des ursprünglichen Aufbaus: RC522 an VSPI
// (SCK 18, MISO 19, MOSI 23, SDA 5, RST 15), PN532 per UART an den
// Standardpins von Serial2 (ESP-RX 16 ← TXD, ESP-TX 17 → RXD).
#ifndef RFID_SS
#define RFID_SS 5
#endif
#ifndef RFID_RST
#define RFID_RST 15
#endif
#ifndef PN532_RX
#define PN532_RX 16
#endif
#ifndef PN532_TX
#define PN532_TX 17
#endif

static MFRC522 mfrc522(RFID_SS, RFID_RST);
// Reset nicht verdrahtet: nach dem Einschalten ist er nicht nötig, und das
// spart eine Leitung. Der PN532 muss per DIP-Schalter auf HSU (UART) stehen.
static Adafruit_PN532 pn532((uint8_t)-1, &Serial2);

/**
 * Reste einer abgebrochenen Kartensuche aus dem UART-Puffer werfen. Sonst
 * liest die Bibliothek sie als Antwort auf den nächsten Befehl, hält sie für
 * falsch — und der Leser scheint nicht zu antworten.
 */
static void drainPn532()
{
    while (Serial2.available())
    {
        Serial2.read();
    }
}

static String hexUid(const uint8_t *bytes, uint8_t len)
{
    String uid;
    for (uint8_t i = 0; i < len; i++)
    {
        if (bytes[i] < 0x10)
        {
            uid += '0';
        }
        uid += String(bytes[i], HEX);
    }
    uid.toUpperCase();
    return uid;
}

bool CardReader::begin()
{
    if (beginPn532() || beginRc522())
    {
        Serial.printf("Leser gefunden: %s\n", chipName.c_str());
        return true;
    }
    Serial.println(F("Kein Leser gefunden - PN532 (UART) und RC522 (SPI) geprueft"));
    return false;
}

bool CardReader::beginPn532()
{
    // Pins vor pn532.begin() setzen: dessen Serial2.begin(115200) übernimmt
    // sie, statt auf die Standardpins zurückzufallen.
    Serial2.begin(115200, SERIAL_8N1, PN532_RX, PN532_TX);
    // Die Bibliothek liest nach jeder Kartensuche fest 20 Byte. Ohne Karte
    // antwortet der PN532 kürzer, und readBytes() wartet dann das ganze
    // Timeout ab — ab Werk eine Sekunde, in der loop() steht. Eine Karte
    // antwortet in wenigen Millisekunden; 80 ms reichen mit Reserve.
    Serial2.setTimeout(80);
    pn532.begin();

    // Der PN532 schläft nach dem Einschalten und verschluckt mitunter die
    // erste Ansprache; ein zweiter Versuch weckt ihn.
    uint32_t version = pn532.getFirmwareVersion();
    if (!version)
    {
        version = pn532.getFirmwareVersion();
    }
    if (!version)
    {
        Serial2.end();
        return false;
    }
    // Nicht ewig auf eine Karte warten: loop() muss weiterlaufen.
    pn532.setPassiveActivationRetries(0x01);
    pn532.SAMConfig();

    kind = PN532;
    chipName = String("PN532 v") + ((version >> 16) & 0xFF) + "." + ((version >> 8) & 0xFF);
    return true;
}

bool CardReader::beginRc522()
{
#if defined(RFID_SCK)
    SPI.begin(RFID_SCK, RFID_MISO, RFID_MOSI, RFID_SS);
#else
    SPI.begin();
#endif
    mfrc522.PCD_Init();
    delay(50);
    byte version = mfrc522.PCD_ReadRegister(MFRC522::VersionReg);
    // 0x00 und 0xFF heißen: niemand am Bus.
    if (version == 0x00 || version == 0xFF)
    {
        return false;
    }

    kind = RC522;
    chipName = String("RC522 0x") + String(version, HEX);
    return true;
}

bool CardReader::check()
{
    // Wer gerade eine Karte gelesen hat, lebt. Die Prüfung selbst unterbricht
    // die Kartensuche — bei aufgelegter Karte sähe es danach so aus, als wäre
    // sie neu aufgelegt worden.
    if (kind != NONE && millis() - lastCardMs < 30000)
    {
        return true;
    }

    bool alive = false;
    switch (kind)
    {
    case PN532:
        // Erst ein zweites Schweigen zählt als Ausfall.
        drainPn532();
        alive = pn532.getFirmwareVersion() != 0;
        if (!alive)
        {
            delay(50);
            drainPn532();
            alive = pn532.getFirmwareVersion() != 0;
        }
        if (alive)
        {
            // Nach einem Stromaussetzer am Leser ist er zurückgesetzt.
            pn532.SAMConfig();
        }
        break;
    case RC522:
    {
        byte version = mfrc522.PCD_ReadRegister(MFRC522::VersionReg);
        alive = version != 0x00 && version != 0xFF;
        if (alive)
        {
            mfrc522.PCD_Init();
        }
        break;
    }
    default:
        break;
    }

    if (alive)
    {
        return true;
    }
    if (kind != NONE)
    {
        Serial.printf("Leser %s antwortet nicht mehr\n", chipName.c_str());
    }
    kind = NONE;
    chipName = "kein Leser";
    return begin();
}

bool CardReader::readCard(String &uid)
{
    switch (kind)
    {
    case PN532:
    {
        uint8_t bytes[7];
        uint8_t len = 0;
        drainPn532();
        unsigned long started = millis();
        bool found = pn532.readPassiveTargetID(PN532_MIFARE_ISO14443A, bytes, &len, 50) && len > 0;
        unsigned long took = millis() - started;
        if (took > 150)
        {
            Serial.printf("PN532: Abfrage dauerte %lu ms\n", took);
        }
        if (!found)
        {
            return false;
        }
        lastCardMs = millis();
        uid = hexUid(bytes, len);
        return true;
    }
    case RC522:
        if (!mfrc522.PICC_IsNewCardPresent() || !mfrc522.PICC_ReadCardSerial())
        {
            return false;
        }
        uid = hexUid(mfrc522.uid.uidByte, mfrc522.uid.size);
        lastCardMs = millis();
        return true;
    default:
        return false;
    }
}
