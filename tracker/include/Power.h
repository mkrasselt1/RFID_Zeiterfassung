#pragma once

struct PowerState {
  bool externalPower = false;  // Netzbetrieb (USB-C/VBUS)
  bool sensed = false;         // true: gemessen, false: aus der Akkuspannung geschätzt
  int batteryMv = 0;
  int batteryPercent = 0;
  bool charging = false;       // Spannung beim Laden gemessen, Prozent zu hoch
};

void powerInit();
// modemMv: Versorgungsspannung laut Modem (AT+CBC), 0 wenn unbekannt. Wird
// gebraucht, wenn der Akku-ADC bei Netzbetrieb abgetrennt ist.
PowerState powerRead(int modemMv);
