#pragma once
#include <Arduino.h>

struct GnssFix {
  bool valid = false;
  double lat = 0, lon = 0;
  float altitude = 0;
  float speedKmh = 0;
  float course = 0;
  float hdop = 0;      // 0 = unbekannt
  int satellites = 0;
  char utc[24] = "";   // "2026-10-15T09:19:18Z"

  // Grobe Genauigkeit in Metern für Home Assistant (zone-Erkennung).
  float accuracyM() const { return hdop > 0 ? hdop * 5.0f : 20.0f; }
};

// Fragt das Modem über PPP.cmd (CMUX) nach der aktuellen Position.
// true, wenn eine gültige Position vorliegt.
bool gnssRead(GnssFix &fix);

// Zerlegt eine +CGNSSINFO- oder +CGPSINFO-Antwort. Eigene Funktion, damit sie
// sich ohne Modem prüfen lässt.
bool gnssParse(const String &response, GnssFix &fix);
