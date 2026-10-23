#pragma once

#include <Arduino.h>
#include <Adafruit_GFX.h>

/**
 * Die Anzeige des Lesers, unabhängig davon, welches Display verbaut ist.
 *
 * Gezeichnet wird in einen Puffer und erst mit `show()` aufs Display gebracht
 * — beim SSD1306 ist das ohnehin so, beim Farbdisplay des T-Display
 * verhindert es das Flackern, das ein Löschen des ganzen Bildschirms vor
 * jedem Neuzeichnen erzeugt.
 *
 * Farben sind RGB565. Das einfarbige OLED zeigt alles, was nicht schwarz ist,
 * als weiß — Layouts gelten so für beide Displays.
 *
 * Welches Display gebaut wird, entscheidet die Umgebung in platformio.ini
 * (`-D SCREEN_ST7789` für das T-Display, sonst SSD1306).
 */
class Screen
{
public:
    static const uint16_t BLACK = 0x0000;
    static const uint16_t WHITE = 0xFFFF;
    static const uint16_t GREY = 0x8410;
    static const uint16_t GREEN = 0x07E0;
    static const uint16_t ORANGE = 0xFD20;
    static const uint16_t RED = 0xF800;
    static const uint16_t BLUE = 0x3D9F;
    static const uint16_t YELLOW = 0xFFE0;

#if defined(SCREEN_ST7789)
    /** Farbe und genug Platz für eine Uhr im Ruhezustand. */
    static const bool HAS_COLOR = true;
#else
    static const bool HAS_COLOR = false;
#endif

    bool begin();

    void clear();

    /** Puffer aufs Display bringen — nur, wenn sich etwas geändert hat. */
    void show();

    int16_t width();
    int16_t height();

    /**
     * Schriftgröße in den Einheiten des kleinen OLEDs (1, 2, 4) in die des
     * eingebauten Displays umrechnen.
     */
    uint8_t textSize(uint8_t oledSize);

    /** Höhe einer Textzeile in Pixeln, für `textSize(oledSize)`. */
    int16_t lineHeight(uint8_t oledSize);

    /** Text rechtsbündig ausgeben. */
    void rightText(const String &text, int16_t y, uint8_t oledSize, uint16_t color = WHITE);

    /** Text waagerecht zentriert ausgeben. */
    void centerText(const String &text, int16_t y, uint8_t oledSize, uint16_t color = WHITE);

    /**
     * Symbol waagerecht zentriert, auf großen Displays vergrößert.
     *
     * `partColors` färbt die zusammenhängenden Teile des Symbols einzeln —
     * gezählt in der Reihenfolge, in der sie beim Lesen von oben links nach
     * unten rechts zuerst auftauchen. Teile ohne eigene Farbe bekommen die
     * letzte angegebene.
     */
    void centerIcon(const uint8_t *bits, int16_t w, int16_t h, int16_t y,
                    const uint16_t *partColors, uint8_t colorCount);

    /** Höhe, die `centerIcon` für ein Symbol der Höhe `h` belegt. */
    int16_t iconHeight(int16_t h);

private:
    Adafruit_GFX &gfx();
    void plotIconPixel(int16_t x0, int16_t y0, int16_t i, int16_t j, uint16_t color);
};

extern Screen screen;
