#include "ConfigPortal.h"

#include <WiFi.h>

#include "StampingBuffer.h"

ConfigPortal configPortal;

namespace
{
/** Kopiert einen Formularwert in ein festes Zeichenfeld, immer abgeschlossen. */
void copyInto(char *target, size_t size, const String &value)
{
    if (size == 0)
    {
        return;
    }
    strncpy(target, value.c_str(), size - 1);
    target[size - 1] = '\0';
}

String escape(const String &raw)
{
    String out;
    out.reserve(raw.length() + 8);
    for (size_t i = 0; i < raw.length(); i++)
    {
        char c = raw[i];
        if (c == '&') out += "&amp;";
        else if (c == '<') out += "&lt;";
        else if (c == '>') out += "&gt;";
        else if (c == '"') out += "&quot;";
        else out += c;
    }

    return out;
}
}

void ConfigPortal::begin(const Fields &fields, void (*onSave)())
{
    this->fields = fields;
    this->onSave = onSave;

    server.on("/", HTTP_GET, [this]() { handleRoot(); });
    server.on("/save", HTTP_POST, [this]() { handleSave(); });
    server.on("/status", HTTP_GET, [this]() { handleStatus(); });
    server.begin();
    started = true;

    Serial.print(F("Konfiguration erreichbar unter http://"));
    Serial.println(WiFi.localIP());
}

void ConfigPortal::handle()
{
    if (started)
    {
        server.handleClient();
    }
}

bool ConfigPortal::authenticated()
{
    // Benutzername fest, Passwort ist das Gerätetoken: ein zweites Geheimnis
    // zu pflegen, das niemand notiert, hilft hier niemandem.
    if (server.authenticate("admin", fields.deviceToken))
    {
        return true;
    }

    server.requestAuthentication(HTTPAuthMethod::BASIC_AUTH, "Zeiterfassung",
                                 "Anmeldung mit dem Token des Lesers.");

    return false;
}

void ConfigPortal::handleRoot()
{
    if (!authenticated())
    {
        return;
    }

    String page = F("<!doctype html><html lang=de><meta charset=utf-8>"
                    "<meta name=viewport content='width=device-width,initial-scale=1'>"
                    "<title>Leser konfigurieren</title>"
                    "<style>body{font-family:system-ui,sans-serif;margin:0;padding:16px;"
                    "background:#f6f6f6;color:#111}main{max-width:32rem;margin:0 auto;"
                    "background:#fff;padding:20px;border-radius:10px;"
                    "box-shadow:0 1px 3px rgba(0,0,0,.1)}h1{font-size:1.1rem;margin:0 0 4px}"
                    "p.sub{margin:0 0 16px;color:#666;font-size:.85rem}"
                    "label{display:block;margin:12px 0 4px;font-size:.85rem;font-weight:600}"
                    "input{width:100%;box-sizing:border-box;padding:8px;font-size:1rem;"
                    "border:1px solid #ccc;border-radius:6px}"
                    "button{margin-top:16px;padding:10px 16px;font-size:1rem;border:0;"
                    "border-radius:6px;background:#d97706;color:#fff;font-weight:600}"
                    "dl{margin:16px 0 0;font-size:.85rem;color:#444}"
                    "dt{font-weight:600;margin-top:8px}</style><main>"
                    "<h1>Leser konfigurieren</h1>"
                    "<p class=sub>Änderungen werden gespeichert und das Gerät startet neu.</p>"
                    "<form method=post action=/save>");

    page += F("<label for=time>Zeitserver</label><input id=time name=time_server value=\"");
    page += escape(String(fields.timeServer));
    page += F("\">");

    page += F("<label for=backend>Serveradresse</label><input id=backend name=backend_server value=\"");
    page += escape(String(fields.backendServer));
    page += F("\">");

    page += F("<label for=token>Token</label><input id=token name=device_token value=\"");
    page += escape(String(fields.deviceToken));
    page += F("\">");

    page += F("<button type=submit>Speichern und neu starten</button></form><dl>");
    page += F("<dt>Adresse</dt><dd>");
    page += WiFi.localIP().toString();
    page += F("</dd><dt>WLAN</dt><dd>");
    page += escape(WiFi.SSID());
    page += F(" (");
    page += String(WiFi.RSSI());
    page += F(" dBm)</dd><dt>Stempelungen im Puffer</dt><dd>");
    page += String((unsigned)stampingBuffer.count());
    page += F("</dd></dl>"
              "<p class=sub style='margin-top:16px'>Das WLAN selbst wird über das "
              "Einrichtungsnetz des Lesers geändert.</p></main></html>");

    server.send(200, "text/html; charset=utf-8", page);
}

void ConfigPortal::handleSave()
{
    if (!authenticated())
    {
        return;
    }

    if (server.hasArg("time_server"))
    {
        copyInto(fields.timeServer, fields.timeServerLen, server.arg("time_server"));
    }
    if (server.hasArg("backend_server"))
    {
        copyInto(fields.backendServer, fields.backendServerLen, server.arg("backend_server"));
    }
    if (server.hasArg("device_token"))
    {
        copyInto(fields.deviceToken, fields.deviceTokenLen, server.arg("device_token"));
    }

    if (onSave != nullptr)
    {
        onSave();
    }

    server.send(200, "text/html; charset=utf-8",
                F("<!doctype html><meta charset=utf-8><p>Gespeichert. Das Gerät startet neu."));

    // Erst antworten, dann neu starten — sonst sieht der Browser nur einen
    // Verbindungsabbruch und man weiß nicht, ob es geklappt hat.
    delay(500);
    ESP.restart();
}

void ConfigPortal::handleStatus()
{
    if (!authenticated())
    {
        return;
    }

    String json = F("{\"ip\":\"");
    json += WiFi.localIP().toString();
    json += F("\",\"ssid\":\"");
    json += WiFi.SSID();
    json += F("\",\"rssi\":");
    json += String(WiFi.RSSI());
    json += F(",\"pending\":");
    json += String((unsigned)stampingBuffer.count());
    json += F("}");

    server.send(200, "application/json", json);
}
