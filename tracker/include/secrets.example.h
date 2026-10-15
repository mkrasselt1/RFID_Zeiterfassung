#pragma once
// Vorlage. Als secrets.h kopieren und ausfüllen — secrets.h wird nicht eingecheckt.

// Mobilfunk
#define SECRET_APN      "internet"   // Telekom: internet.telekom, Vodafone: web.vodafone.de, O2: internet
#define SECRET_SIM_PIN  NULL         // z. B. "1234", NULL wenn die SIM keine PIN hat

// WLAN-Hotspot
#define SECRET_AP_SSID  "Traktor-WLAN"
#define SECRET_AP_PASS  "bitte-aendern"  // mindestens 8 Zeichen

// Home Assistant: Einstellungen > Geräte & Dienste > Integration hinzufügen >
// GPSLogger. Die angezeigte Webhook-URL hier eintragen.
#define SECRET_WEBHOOK_URL "https://example.ui.nabu.casa/api/webhook/xxxxxxxx"
// Name des device_tracker in Home Assistant (device_tracker.traktor)
#define SECRET_DEVICE_NAME "traktor"
