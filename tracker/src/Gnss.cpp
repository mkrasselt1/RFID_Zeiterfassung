#include "Gnss.h"

#include <PPP.h>
#include <vector>

// ddmm.mmmm bzw. dddmm.mmmm -> Dezimalgrad
static double nmeaToDeg(const String &v) {
  double raw = v.toDouble();
  int deg = (int)(raw / 100);
  return deg + (raw - deg * 100) / 60.0;
}

static std::vector<String> splitFields(const String &s) {
  std::vector<String> out;
  int start = 0;
  for (;;) {
    int comma = s.indexOf(',', start);
    String f = comma < 0 ? s.substring(start) : s.substring(start, comma);
    f.trim();
    out.push_back(f);
    if (comma < 0) break;
    start = comma + 1;
  }
  return out;
}

// SIM7600 und A7670 liefern +CGNSSINFO je nach Firmware mit unterschiedlich
// vielen Satellitenfeldern vor der Breite. Verlässlicher Anker ist das
// "N"/"S"-Feld: davor die Breite, danach Länge, "E"/"W", Datum, Uhrzeit,
// Höhe, Tempo (Knoten), Kurs und — nur bei CGNSSINFO — PDOP, HDOP, VDOP.
bool gnssParse(const String &response, GnssFix &fix) {
  int colon = response.indexOf(':');
  String data = colon >= 0 ? response.substring(colon + 1) : response;
  int nl = data.indexOf('\n');
  if (nl >= 0) data = data.substring(0, nl);
  data.trim();

  auto f = splitFields(data);
  int ns = -1;
  for (size_t i = 1; i < f.size(); i++) {
    if (f[i] == "N" || f[i] == "S") {
      ns = i;
      break;
    }
  }
  if (ns < 1 || ns + 2 >= (int)f.size()) return false;
  if (f[ns - 1].isEmpty() || f[ns + 1].isEmpty()) return false;
  if (f[ns + 2] != "E" && f[ns + 2] != "W") return false;

  fix.lat = nmeaToDeg(f[ns - 1]) * (f[ns] == "S" ? -1 : 1);
  fix.lon = nmeaToDeg(f[ns + 1]) * (f[ns + 2] == "W" ? -1 : 1);

  auto field = [&](int i) -> String { return i < (int)f.size() ? f[i] : String(); };
  String date = field(ns + 3);  // ddmmyy
  String time = field(ns + 4);  // hhmmss.s
  if (date.length() == 6 && time.length() >= 6) {
    snprintf(fix.utc, sizeof(fix.utc), "20%s-%s-%sT%s:%s:%sZ", date.substring(4, 6).c_str(),
             date.substring(2, 4).c_str(), date.substring(0, 2).c_str(), time.substring(0, 2).c_str(),
             time.substring(2, 4).c_str(), time.substring(4, 6).c_str());
  } else {
    fix.utc[0] = 0;
  }
  fix.altitude = field(ns + 5).toFloat();
  fix.speedKmh = field(ns + 6).toFloat() * 1.852f;
  fix.course = field(ns + 7).toFloat();
  fix.hdop = field(ns + 9).toFloat();

  // CGNSSINFO: <mode>,<GPS-SVs>,... — Summe aller Satellitenfelder vor der Breite.
  fix.satellites = 0;
  for (int i = 1; i < ns - 1; i++) fix.satellites += f[i].toInt();

  fix.valid = !(fix.lat == 0 && fix.lon == 0);
  return fix.valid;
}

bool gnssRead(GnssFix &fix) {
  String r = PPP.cmd("AT+CGNSSINFO", 3000);
  if (gnssParse(r, fix)) return true;
  // Ältere Firmware ohne CGNSSINFO antwortet mit ERROR -> leerer String.
  if (r.isEmpty()) return gnssParse(PPP.cmd("AT+CGPSINFO", 3000), fix);
  return false;
}
