#pragma once

struct PowerState {
  bool externalPower = false;  // Netzbetrieb (USB/DC-Eingang)
  bool sensed = false;         // true: gemessen, false: aus der Akkuspannung geschätzt
  int batteryMv = 0;
  int batteryPercent = 0;
};

void powerInit();
PowerState powerRead();
