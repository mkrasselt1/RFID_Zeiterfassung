#include "Power.h"

#include <Arduino.h>

#include "config.h"

// Ruhespannung einer LiPo-Zelle -> Ladestand. Unter Last und beim Laden
// verschoben, für "voll / halb / bald leer" reicht es.
static const int CURVE[][2] = {
    {4150, 100}, {4100, 95}, {4000, 85}, {3900, 72}, {3800, 58}, {3750, 48},
    {3700, 38},  {3650, 26}, {3600, 18}, {3500, 9},  {3400, 4},  {3300, 0},
};

static int percentFromMv(int mv) {
  const int n = sizeof(CURVE) / sizeof(CURVE[0]);
  if (mv >= CURVE[0][0]) return 100;
  if (mv <= CURVE[n - 1][0]) return 0;
  for (int i = 1; i < n; i++) {
    if (mv >= CURVE[i][0]) {
      int v0 = CURVE[i][0], v1 = CURVE[i - 1][0], p0 = CURVE[i][1], p1 = CURVE[i - 1][1];
      return p0 + (mv - v0) * (p1 - p0) / (v1 - v0);
    }
  }
  return 0;
}

static int readMv(int pin, float divider) {
  uint32_t sum = 0;
  for (int i = 0; i < 16; i++) sum += analogReadMilliVolts(pin);
  return (int)(sum / 16 * divider);
}

void powerInit() {
  analogSetAttenuation(ADC_11db);
  pinMode(PIN_BATTERY_ADC, INPUT);
#if PIN_POWER_SENSE >= 0
  pinMode(PIN_POWER_SENSE, INPUT);
#endif
}

PowerState powerRead() {
  static int smoothedMv = 0;
  int mv = readMv(PIN_BATTERY_ADC, BATTERY_DIVIDER);
  smoothedMv = smoothedMv == 0 ? mv : (smoothedMv * 3 + mv) / 4;

  PowerState s;
  s.batteryMv = smoothedMv;
  s.batteryPercent = percentFromMv(smoothedMv);
#if PIN_POWER_SENSE >= 0
  s.externalPower = readMv(PIN_POWER_SENSE, POWER_SENSE_DIVIDER) >= POWER_SENSE_MIN_MV;
  s.sensed = true;
#else
  s.externalPower = smoothedMv >= BATTERY_CHARGING_MV;
  s.sensed = false;
#endif
  return s;
}
