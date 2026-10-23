#pragma once

#include <Arduino.h>
#include <WebServer.h>
#include <vector>

/**
 * Konfiguration im Betrieb, ohne das Gerät von der Wand zu nehmen.
 *
 *   http://<geraete-ip>/         Statusseite, ohne Anmeldung
 *   http://<geraete-ip>/status   dasselbe als JSON, ohne Anmeldung
 *   http://<geraete-ip>/config   Konfiguration, Benutzer "admin", Passwort ist
 *                                das Token des Lesers — dasselbe, das im Panel
 *                                neben dem Gerät steht. Aus der
 *                                Leserverwaltung führt ein Link direkt hierher.
 *
 * Die Statusseite ist offen, damit jeder vor Ort nachsehen kann, ob der Leser
 * arbeitet. Auf ihr steht deshalb nichts, was schützenswert ist: kein Token,
 * keine Namen.
 *
 * Warum nicht das Portal von WiFiManager: dessen Konfigurationsseite kennt
 * keine Anmeldung (die Bibliothek hat die Stelle auskommentiert und per
 * `WM_NOAUTH` abgeschaltet). Eine offene Seite, auf der Serveradresse und
 * Token stehen, gehört nicht dauerhaft ins Netz — also eine eigene, kleine.
 *
 * Nur HTTP: ein ESP32 kann kein vertrauenswürdiges Zertifikat für seine
 * wechselnde lokale Adresse vorweisen. Das Token geht damit im Klartext durchs
 * lokale Netz. Für ein Gerät im Firmennetz vertretbar, aber kein Ersatz dafür,
 * das Netz selbst abzusichern.
 */
class ConfigPortal
{
public:
    /**
     * Zeiger auf die Felder, die die Seite bearbeitet — sie liegen in main.cpp
     * und werden von dort auch gespeichert.
     */
    struct Fields
    {
        char *timeServer;
        size_t timeServerLen;
        char *backendServer;
        size_t backendServerLen;
        char *deviceToken;
        size_t deviceTokenLen;
    };

    /** Eine Zeile der Statusseite: Schlüssel fürs JSON, Beschriftung, Wert. */
    struct StatusLine
    {
        const char *key;
        const char *label;
        String value;
    };
    using StatusLines = std::vector<StatusLine>;

    /** `status` füllt die Statusseite; der Zustand liegt in main.cpp. */
    void begin(const Fields &fields, void (*onSave)(), void (*status)(StatusLines &));

    /** In loop() aufrufen; blockiert nicht. */
    void handle();

private:
    bool authenticated();
    void handleOverview();
    void handleConfig();
    void handleSave();
    void handleStatus();

    WebServer server{80};
    Fields fields{};
    void (*onSave)() = nullptr;
    void (*status)(StatusLines &) = nullptr;
    bool started = false;
};

extern ConfigPortal configPortal;
