#include "StampingUploader.h"

#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFi.h>

StampingUploader stampingUploader;

void StampingUploader::configure(const char *baseUrl, const char *token, const char *firmware)
{
    this->baseUrl = String(baseUrl);
    this->token = String(token);
    this->firmware = String(firmware);
}

String StampingUploader::endpoint() const
{
    String url = baseUrl;

    // Konfiguriert ist die alte Adresse (…/getdata.php oder …/getdata). Daraus
    // die neue abzuleiten erspart es, bei jedem Gerät die Einstellung
    // anzufassen — die Firmware wird ohnehin getauscht, die Konfiguration im
    // Flash bleibt.
    int cut = url.lastIndexOf("/getdata");
    if (cut >= 0)
    {
        url = url.substring(0, cut);
    }
    while (url.endsWith("/"))
    {
        url.remove(url.length() - 1);
    }

    return url + "/api/v1/stampings";
}

UploadOutcome StampingUploader::flush(const String &uidOfInterest)
{
    UploadOutcome outcome;

    if (!WiFi.isConnected())
    {
        outcome.lastMessage = "Kein Empfang";
        return outcome;
    }

    Stamping batch[BATCH_SIZE];
    size_t n = stampingBuffer.peek(batch, BATCH_SIZE);
    if (n == 0)
    {
        outcome.ok = true;
        return outcome;
    }

    time_t now = time(nullptr);
    unsigned long nowMs = millis();

    // Heap, nicht Stack: zwei Dokumente zu je 4 KB passen nicht in den Stack
    // der Arduino-Task und würden ihn zur Laufzeit überschreiben.
    DynamicJsonDocument doc(4096);
    doc["firmware"] = firmware;
    // Was nach diesem Schwung noch liegen bleibt — im Panel sichtbar.
    size_t total = stampingBuffer.count();
    doc["pending"] = total > n ? (total - n) : 0;

    JsonArray events = doc.createNestedArray("events");
    for (size_t i = 0; i < n; i++)
    {
        JsonObject event = events.createNestedObject();
        event["uid"] = batch[i].uid;
        event["card_uid"] = batch[i].cardUid;

        time_t at = batch[i].at;
        if (at == 0 && now > 0)
        {
            // Zeit war beim Stempeln unbekannt: aus der Laufzeit zurückrechnen.
            // Nach einem Neustart stimmt der Bezug nicht mehr, dann bleibt es
            // bei "jetzt" und der Server setzt seine eigene Zeit.
            unsigned long elapsed = (nowMs >= batch[i].ms) ? (nowMs - batch[i].ms) : 0;
            at = now - (time_t)(elapsed / 1000UL);
        }

        if (at > 0)
        {
            char iso[32];
            struct tm tmv;
            gmtime_r(&at, &tmv);
            strftime(iso, sizeof(iso), "%Y-%m-%dT%H:%M:%SZ", &tmv);
            event["at"] = iso;
        }
        else
        {
            // Kein brauchbarer Zeitpunkt. Der Server verlangt das Feld, also
            // schicken wir seine Zeit — ungenau, aber die Stempelung ist da.
            event["at"] = "";
        }
    }

    String body;
    serializeJson(doc, body);

    HTTPClient http;
    http.begin(endpoint());
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Authorization", "Bearer " + token);
    http.setTimeout(10000);

    int code = http.POST(body);
    String payload = http.getString();
    http.end();

    if (code != 200)
    {
        outcome.lastMessage = (code > 0) ? ("Server " + String(code)) : "Uebertragungsfehler";
        Serial.printf("Upload fehlgeschlagen: %d %s\n", code, payload.c_str());
        return outcome;
    }

    DynamicJsonDocument answer(4096);
    if (deserializeJson(answer, payload) != DeserializationError::Ok)
    {
        outcome.lastMessage = "Antwort unlesbar";
        return outcome;
    }

    for (JsonObject result : answer["results"].as<JsonArray>())
    {
        if (uidOfInterest.length() > 0 && uidOfInterest == result["uid"].as<String>())
        {
            outcome.lastStatus = result["status"].as<String>();
            outcome.lastName = result["name"].as<String>();
            outcome.lastMessage = result["message"].as<String>();
        }
    }

    // Erst jetzt löschen: angekommen und beantwortet.
    stampingBuffer.drop(n);
    outcome.ok = true;
    outcome.delivered = n;

    return outcome;
}
