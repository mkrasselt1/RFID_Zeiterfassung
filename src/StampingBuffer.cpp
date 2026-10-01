#include "StampingBuffer.h"

#include <ArduinoJson.h>
#include <Preferences.h>
#include <SPIFFS.h>

StampingBuffer stampingBuffer;

namespace
{
/** Datei mit den wartenden Stempelungen, eine JSON-Zeile je Stück. */
const char *QUEUE_PATH = "/stampings.ndjson";
const char *TMP_PATH = "/stampings.tmp";

/** Ab hier gilt eine Uhrzeit als echt und nicht als "seit Einschalten". */
const time_t TIME_IS_SET = 1700000000; // Mitte November 2023

Preferences prefs;
}

bool StampingBuffer::begin()
{
    // SPIFFS wird in setup() schon für die Konfiguration geöffnet; ein zweites
    // begin() ist harmlos und macht dieses Modul für sich benutzbar.
    ready = SPIFFS.begin(true);
    if (!ready)
    {
        Serial.println(F("StampingBuffer: SPIFFS nicht verfuegbar"));
    }

    return ready;
}

unsigned long StampingBuffer::nextSequence()
{
    // Im NVS, nicht im RAM: die Kennung muss über einen Neustart hinweg
    // eindeutig bleiben, sonst hielte der Server zwei verschiedene
    // Stempelungen für dieselbe und buchte die zweite nicht.
    prefs.begin("stamping", false);
    unsigned long seq = prefs.getULong("seq", 0) + 1;
    prefs.putULong("seq", seq);
    prefs.end();

    return seq;
}

String StampingBuffer::add(const String &cardUid, time_t at)
{
    if (!ready)
    {
        return String();
    }

    if (count() >= MAX_ENTRIES)
    {
        // Lieber die älteste verlieren als die neue: ein volles Dateisystem
        // würde sonst jede weitere Stempelung verschlucken.
        Serial.println(F("StampingBuffer: voll, aelteste wird verworfen"));
        drop(1);
    }

    File file = SPIFFS.open(QUEUE_PATH, FILE_APPEND);
    if (!file)
    {
        Serial.println(F("StampingBuffer: Datei nicht zu oeffnen"));
        return String();
    }

    String uid = String(nextSequence());

    StaticJsonDocument<256> doc;
    doc["uid"] = uid;
    doc["card"] = cardUid;
    doc["at"] = (at >= TIME_IS_SET) ? (long)at : 0;
    doc["ms"] = millis();

    String line;
    serializeJson(doc, line);
    file.println(line);
    file.close();

    return uid;
}

size_t StampingBuffer::count()
{
    if (!ready || !SPIFFS.exists(QUEUE_PATH))
    {
        return 0;
    }

    File file = SPIFFS.open(QUEUE_PATH, FILE_READ);
    if (!file)
    {
        return 0;
    }

    size_t lines = 0;
    while (file.available())
    {
        String line = file.readStringUntil('\n');
        line.trim();
        if (line.length() > 0)
        {
            lines++;
        }
    }
    file.close();

    return lines;
}

size_t StampingBuffer::peek(Stamping *out, size_t limit)
{
    if (!ready || !SPIFFS.exists(QUEUE_PATH) || limit == 0)
    {
        return 0;
    }

    File file = SPIFFS.open(QUEUE_PATH, FILE_READ);
    if (!file)
    {
        return 0;
    }

    size_t found = 0;
    while (file.available() && found < limit)
    {
        String line = file.readStringUntil('\n');
        line.trim();
        if (line.length() == 0)
        {
            continue;
        }

        StaticJsonDocument<256> doc;
        if (deserializeJson(doc, line) != DeserializationError::Ok)
        {
            // Eine unlesbare Zeile (Stromausfall mitten im Schreiben) wird
            // übersprungen, nicht als Abbruch behandelt.
            continue;
        }

        out[found].uid = doc["uid"].as<String>();
        out[found].cardUid = doc["card"].as<String>();
        out[found].at = (time_t)doc["at"].as<long>();
        out[found].ms = doc["ms"].as<unsigned long>();
        found++;
    }
    file.close();

    return found;
}

bool StampingBuffer::drop(size_t n)
{
    if (!ready || n == 0 || !SPIFFS.exists(QUEUE_PATH))
    {
        return true;
    }

    File src = SPIFFS.open(QUEUE_PATH, FILE_READ);
    if (!src)
    {
        return false;
    }

    File dst = SPIFFS.open(TMP_PATH, FILE_WRITE);
    if (!dst)
    {
        src.close();
        return false;
    }

    size_t skipped = 0;
    while (src.available())
    {
        String line = src.readStringUntil('\n');
        line.trim();
        if (line.length() == 0)
        {
            continue;
        }
        if (skipped < n)
        {
            skipped++;
            continue;
        }
        dst.println(line);
    }

    src.close();
    dst.close();

    // Erst umbenennen, wenn die neue Datei vollständig geschrieben ist —
    // bricht der Strom vorher weg, bleibt die alte Warteschlange heil und es
    // wird höchstens doppelt geliefert. Das fängt der Server ab.
    SPIFFS.remove(QUEUE_PATH);

    return SPIFFS.rename(TMP_PATH, QUEUE_PATH);
}

bool StampingBuffer::clear()
{
    if (!ready)
    {
        return false;
    }

    return SPIFFS.remove(QUEUE_PATH);
}
