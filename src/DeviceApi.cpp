#include "DeviceApi.h"

#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>

DeviceApi deviceApi;

// Eine Verbindung für alles, was an den Server geht — siehe request().
static WiFiClientSecure secureClient;
static WiFiClient plainClient;
static HTTPClient http;

int DeviceApi::request(const char *method, const String &url, const String &body, String &response)
{
    unsigned long started = millis();
    // Wie bisher ohne Zertifikatsprüfung: der Leser kennt keine CA-Liste.
    // Geschützt ist das Token trotzdem — es steht verschlüsselt im Header.
    secureClient.setInsecure();
    http.setReuse(true);
    http.setTimeout(10000);
    http.setConnectTimeout(5000);

    bool ok = url.startsWith("https://") ? http.begin(secureClient, url) : http.begin(plainClient, url);
    if (!ok)
    {
        return -1;
    }
    http.addHeader("Authorization", authHeader);
    if (body.length())
    {
        http.addHeader("Content-Type", "application/json");
    }
    http.addHeader("Accept", "application/json");

    int code = http.sendRequest(method, body);
    response = code > 0 ? http.getString() : String();
    http.end();

    Serial.printf("%s %s -> %d (%lu ms)\n", method, url.c_str(), code, millis() - started);
    return code;
}

void DeviceApi::configure(const char *server, const char *token, const char *firmware)
{
    String url = String(server);
    url.trim();

    // Alte Adresse (…/getdata.php, …/getdata) oder eine schon vollständige
    // API-Adresse auf die Serverwurzel zurückführen.
    for (const char *tail : {"/getdata", "/api/v1"})
    {
        int cut = url.lastIndexOf(tail);
        if (cut >= 0)
        {
            url = url.substring(0, cut);
        }
    }
    while (url.endsWith("/"))
    {
        url.remove(url.length() - 1);
    }

    this->root = url;
    this->base = url + "/api/v1/device/";
    this->authHeader = "Bearer " + String(token);
    this->firmware = String(firmware);
}

String DeviceApi::url(const char *path) const { return base + path; }

String DeviceApi::stampingsUrl() const
{
    return legacyStampings ? root + "/api/v1/stampings" : url("stampings");
}

void DeviceApi::useLegacyStampings()
{
    legacyStampings = true;
    Serial.println(F("Server kennt /api/v1/device noch nicht - liefere an /api/v1/stampings"));
}

/** Fehlerseiten sind oft ganzes HTML; ins Log gehört nur der Anfang. */
static String shortened(const String &payload)
{
    return payload.length() > 120 ? payload.substring(0, 120) + "..." : payload;
}

static const char *resetReason()
{
    switch (esp_reset_reason())
    {
    case ESP_RST_POWERON:
        return "poweron";
    case ESP_RST_SW:
        return "software";
    case ESP_RST_PANIC:
        return "panic";
    case ESP_RST_INT_WDT:
    case ESP_RST_TASK_WDT:
    case ESP_RST_WDT:
        return "watchdog";
    case ESP_RST_BROWNOUT:
        return "brownout";
    case ESP_RST_DEEPSLEEP:
        return "deepsleep";
    case ESP_RST_EXT:
        return "external";
    default:
        return "unknown";
    }
}

bool DeviceApi::fetchConfig(RemoteConfig &config)
{
    if (!WiFi.isConnected())
    {
        return false;
    }

    String payload;
    int code = request("GET", url("config"), "", payload);
    if (code == 404)
    {
        // Der Server kennt die Geräte-API noch nicht: gleich jetzt umstellen,
        // nicht erst beim ersten Stempeln mit einer vergeblichen Anfrage.
        useLegacyStampings();
    }
    if (code != 200)
    {
        Serial.printf("Konfiguration nicht geholt: %d %s\n", code, shortened(payload).c_str());
        return false;
    }

    // Der Server kann sie inzwischen: zurück auf die Geräte-API.
    legacyStampings = false;

    DynamicJsonDocument doc(1024);
    if (deserializeJson(doc, payload) != DeserializationError::Ok)
    {
        return false;
    }

    // Nur übernehmen, was da ist — ein älterer Server kennt nicht alles.
    config.name = doc["name"] | config.name.c_str();
    config.mode = doc["mode"] | config.mode.c_str();
    config.tz = doc["tz"] | config.tz.c_str();
    config.ntpServer = doc["ntp_server"] | config.ntpServer.c_str();
    config.statusSeconds = max<uint32_t>(30, doc["intervals"]["status"] | config.statusSeconds);
    config.configSeconds = max<uint32_t>(60, doc["intervals"]["config"] | config.configSeconds);
    config.flushSeconds = max<uint32_t>(5, doc["intervals"]["flush"] | config.flushSeconds);
    return true;
}

bool DeviceApi::reportStatus(const String &reader, size_t pending, const DeviceCounters &counters)
{
    // Ein Server ohne Geräte-API hat auch keinen Statusendpunkt.
    if (!WiFi.isConnected() || legacyStampings)
    {
        return false;
    }

    DynamicJsonDocument doc(512);
    doc["firmware"] = firmware;
    doc["pending"] = pending;
    doc["ip"] = WiFi.localIP().toString();
    doc["uptime"] = millis() / 1000UL;
    doc["rssi"] = WiFi.RSSI();
    doc["ssid"] = WiFi.SSID();
    doc["free_heap"] = ESP.getFreeHeap();
    doc["reader"] = reader;
    doc["reset_reason"] = resetReason();
    doc["stamped"] = counters.stamped;
    doc["uploaded"] = counters.uploaded;

    String body;
    serializeJson(doc, body);

    String response;
    int code = request("POST", url("status"), body, response);

    if (code != 200)
    {
        Serial.printf("Status nicht gemeldet: %d\n", code);
        return false;
    }
    return true;
}
