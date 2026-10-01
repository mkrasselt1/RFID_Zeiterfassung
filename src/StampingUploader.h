#pragma once

#include <Arduino.h>

#include "StampingBuffer.h"

/**
 * Liefert gepufferte Stempelungen an den Server.
 *
 * Gesendet wird an `POST <server>/api/v1/stampings` mit dem Gerätetoken im
 * Authorization-Header — nicht mehr in der Adresszeile, wo es in jedem
 * Server- und Proxy-Protokoll landet.
 *
 * Jede Stempelung trägt eine Kennung. Geht die Antwort auf dem Rückweg
 * verloren, schickt das Gerät sie beim nächsten Mal erneut; der Server erkennt
 * die Kennung wieder und bucht nichts zweites. Deshalb darf hier erst gelöscht
 * werden, wenn die Antwort wirklich angekommen ist.
 */
struct UploadOutcome
{
    bool ok = false;
    size_t delivered = 0;
    /** Antwort zur zuletzt gelesenen Karte, für die Anzeige am Gerät. */
    String lastStatus;
    String lastName;
    String lastMessage;
};

class StampingUploader
{
public:
    /** So viele je Anlauf; mehr sprengt den Speicher beim Zusammenbauen. */
    static const size_t BATCH_SIZE = 20;

    void configure(const char *baseUrl, const char *token, const char *firmware);

    /**
     * Einen Schwung hochladen. `uidOfInterest` ist die gerade gestempelte
     * Karte — deren Ergebnis landet in `lastStatus`, damit das Display etwas
     * Sinnvolles zeigen kann.
     */
    UploadOutcome flush(const String &uidOfInterest = "");

private:
    /** Aus der alten Adresse (…/getdata.php) die neue ableiten. */
    String endpoint() const;

    String baseUrl;
    String token;
    String firmware;
};

extern StampingUploader stampingUploader;
