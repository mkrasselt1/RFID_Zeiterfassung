#pragma once

#include <Arduino.h>
#include <WebServer.h>

/**
 * Konfiguration im Betrieb, ohne das Gerät von der Wand zu nehmen.
 *
 * Erreichbar unter `http://<geraete-ip>/`, angemeldet wird sich mit dem Token
 * des Lesers als Passwort — demselben, das im Panel neben dem Gerät steht. Aus
 * der Leserverwaltung führt ein Link direkt hierher.
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

    void begin(const Fields &fields, void (*onSave)());

    /** In loop() aufrufen; blockiert nicht. */
    void handle();

private:
    bool authenticated();
    void handleRoot();
    void handleSave();
    void handleStatus();

    WebServer server{80};
    Fields fields{};
    void (*onSave)() = nullptr;
    bool started = false;
};

extern ConfigPortal configPortal;
