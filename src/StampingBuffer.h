#pragma once

#include <Arduino.h>

/**
 * Stempelungen überleben den Netzausfall.
 *
 * Bisher war eine Stempelung ohne Netz verloren: die Firmware zeigte "Kein
 * Empfang" und vergaß sie. Für eine Zeiterfassung ist das der schlimmste
 * Fehler, weil er stillschweigend passiert — die Person hat gestempelt, das
 * System weiß nichts davon.
 *
 * Jede Stempelung landet daher zuerst hier, in einer Datei im SPIFFS, und wird
 * erst gelöscht, wenn der Server sie bestätigt hat. Eine Zeile je Ereignis
 * (JSON), damit Anhängen billig ist und eine halb geschriebene Zeile beim
 * Stromausfall nur sich selbst beschädigt.
 *
 * Zur Zeit: Ein ESP32 ohne Echtzeituhr weiß nach dem Einschalten nicht, wie
 * spät es ist. Solange die Uhr nicht steht, merken wir uns die Laufzeit seit
 * dem Start (`millis`) und rechnen den Zeitpunkt beim Hochladen zurück. Das
 * überlebt keinen Neustart — danach ist der Zeitpunkt unbekannt und der Server
 * setzt seine eigene Zeit. Eine Echtzeituhr löst das; bis dahin ist eine
 * Stempelung mit ungenauer Zeit besser als keine.
 */
struct Stamping
{
    String uid;        // eindeutig je Gerät, damit der Server doppelt Geliefertes erkennt
    String cardUid;
    time_t at;         // 0 = Zeit war unbekannt
    unsigned long ms;  // Laufzeit beim Stempeln, für die Rückrechnung
};

class StampingBuffer
{
public:
    /** Bis hierhin wird gepuffert; darüber fällt die älteste Stempelung raus. */
    static const size_t MAX_ENTRIES = 500;

    bool begin();

    /**
     * Stempelung aufnehmen und ihre Kennung zurückgeben; leer bei Fehler.
     * Mit der Kennung findet der Aufrufer in der Antwort des Servers wieder,
     * was aus genau dieser Stempelung wurde.
     */
    String add(const String &cardUid, time_t at);

    /** Wie viele warten auf den Upload. */
    size_t count();

    bool isEmpty() { return count() == 0; }

    /**
     * Die ältesten `limit` Stempelungen lesen, ohne sie zu entfernen.
     * Entfernt werden sie erst mit `drop()`, nachdem der Server bestätigt hat.
     */
    size_t peek(Stamping *out, size_t limit);

    /** Die ersten `n` Stempelungen entfernen — sie sind angekommen. */
    bool drop(size_t n);

    /** Alles verwerfen (nur für die Wartung). */
    bool clear();

private:
    /** Laufende Nummer, damit jede Stempelung eine eigene Kennung bekommt. */
    unsigned long nextSequence();

    bool ready = false;
};

extern StampingBuffer stampingBuffer;
