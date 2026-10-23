#pragma once

#include <Arduino.h>

/**
 * Der Kartenleser, unabhängig davon, welcher Chip verbaut ist.
 *
 * Beim Start wird nachgesehen, was antwortet: zuerst ein PN532 an der seriellen
 * Schnittstelle (UART), dann ein MFRC522 am SPI-Bus. So läuft dieselbe Firmware mit beiden Lesern,
 * ohne dass jemand beim Nachbau eine Einstellung finden muss — und das
 * Display sagt, ob überhaupt einer gefunden wurde.
 */
class CardReader
{
public:
    /** Leser suchen. false, wenn keiner antwortet. */
    bool begin();

    /** Was gefunden wurde, z. B. "PN532 v1.6" — oder "kein Leser". */
    String name() const { return chipName; }

    bool found() const { return kind != NONE; }

    /**
     * Liegt eine Karte auf, steht ihre Kennung danach in `uid` (Hex, groß
     * geschrieben, wie der Server sie kennt). Kehrt ohne Karte schnell zurück.
     */
    bool readCard(String &uid);

    /**
     * Antwortet der Leser noch? Fällt er im Betrieb aus (Kabel gelöst), soll
     * das Display es sagen — und wenn er wieder da ist, weiterarbeiten.
     * Sucht neu, falls bisher keiner gefunden war.
     */
    bool check();

private:
    enum Kind
    {
        NONE,
        PN532,
        RC522,
    };

    bool beginPn532();
    bool beginRc522();

    Kind kind = NONE;
    unsigned long lastCardMs = 0;
    String chipName = "kein Leser";
};

extern CardReader cardReader;
