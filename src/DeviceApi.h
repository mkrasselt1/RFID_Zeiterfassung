#pragma once

#include <Arduino.h>

/**
 * Was der Server dem Leser vorgibt (GET /api/v1/device/config).
 *
 * Zeitzone, Modus und Intervalle werden im Panel gepflegt, nicht am Gerät.
 * Am Gerät bleibt nur, was es braucht, um den Server zu erreichen.
 */
struct RemoteConfig
{
    String name;
    /** "time" oder "learn" (Karten anlernen). */
    String mode = "time";
    /** POSIX-Regel für setenv("TZ"), z. B. "CET-1CEST,M3.5.0,M10.5.0/3". */
    String tz = "CET-1CEST,M3.5.0,M10.5.0/3";
    String ntpServer = "pool.ntp.org";
    uint32_t statusSeconds = 300;
    uint32_t configSeconds = 3600;
    uint32_t flushSeconds = 30;
};

/** Zählerstände für die Statusmeldung. */
struct DeviceCounters
{
    uint32_t stamped = 0;
    uint32_t uploaded = 0;
};

/**
 * Die Geräte-API unter `<server>/api/v1/device/…`, Token im Authorization-Header.
 *
 *   GET  config      Konfiguration holen
 *   POST status      Zustand melden (WLAN, Speicher, Leser, Puffer …)
 *   POST stampings   stempeln und Gepuffertes nachtragen (StampingUploader)
 */
class DeviceApi
{
public:
    void configure(const char *server, const char *token, const char *firmware);

    /**
     * Adresse eines Endpunkts. Eingestellt sein darf die Serverwurzel ebenso
     * wie die alte Adresse …/getdata.php — die Konfiguration im Flash bleibt
     * beim Firmware-Tausch stehen, und niemand soll sie dafür anfassen müssen.
     */
    String url(const char *path) const;

    /**
     * Wohin Stempelungen gehen. Ein Server, der die Geräte-API noch nicht
     * kennt, hat womöglich schon den älteren Pfad /api/v1/stampings — dann
     * wird dorthin geliefert, statt alles im Puffer liegen zu lassen.
     */
    String stampingsUrl() const;
    void useLegacyStampings();
    bool usesLegacyStampings() const { return legacyStampings; }

    const String &bearer() const { return authHeader; }
    const String &firmwareVersion() const { return firmware; }

    /**
     * Eine Anfrage über die gemeinsame Verbindung. Die Verbindung bleibt
     * offen, solange der Server sie offen hält: ein TLS-Handshake kostet den
     * ESP32 ein bis zwei Sekunden, und die soll nicht jedes Stempeln zahlen.
     */
    int request(const char *method, const String &url, const String &body, String &response);

    bool fetchConfig(RemoteConfig &config);

    bool reportStatus(const String &reader, size_t pending, const DeviceCounters &counters);

private:
    String root;
    String base;
    bool legacyStampings = false;
    String authHeader;
    String firmware;
};

extern DeviceApi deviceApi;
