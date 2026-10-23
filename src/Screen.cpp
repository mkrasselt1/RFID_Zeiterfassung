#include "Screen.h"

#include <SPI.h>

Screen screen;

#if defined(SCREEN_ST7789)
//************************************************************************
// LilyGO T-Display: ST7789, 135×240, fest auf der Platine verdrahtet.
#include <Adafruit_ST7789.h>
#include <rom/crc.h>

#ifndef TFT_MOSI
#define TFT_MOSI 19
#endif
#ifndef TFT_SCLK
#define TFT_SCLK 18
#endif
#ifndef TFT_CS
#define TFT_CS 5
#endif
#ifndef TFT_DC
#define TFT_DC 16
#endif
#ifndef TFT_RST
#define TFT_RST 23
#endif
#ifndef TFT_BL
#define TFT_BL 4
#endif
#ifndef SCREEN_ROTATION
#define SCREEN_ROTATION 1
#endif

// Eigener SPI-Bus: der Standardbus gehört dem RFID-Leser. Das Display hängt
// fest an Pins, die beim normalen ESP32-Aufbau der Leser belegt (5, 19, 23).
static SPIClass tftSpi(HSPI);
static Adafruit_ST7789 tft(&tftSpi, TFT_CS, TFT_DC, TFT_RST);
// Volle Farbtiefe: 240×135×2 = 65 KB. Eine zweite Kopie zum Vergleichen wäre
// zu viel neben WLAN und TLS — eine Prüfsumme genügt.
static GFXcanvas16 *canvas = nullptr;
static uint32_t shownCrc = 0;

bool Screen::begin()
{
    pinMode(TFT_BL, OUTPUT);
    digitalWrite(TFT_BL, HIGH);

    tftSpi.begin(TFT_SCLK, -1, TFT_MOSI, TFT_CS);
    tft.init(135, 240);
    tft.setSPISpeed(40000000);
    tft.setRotation(SCREEN_ROTATION);
    tft.fillScreen(ST77XX_BLACK);

    canvas = new GFXcanvas16(tft.width(), tft.height());
    return canvas && canvas->getBuffer();
}

Adafruit_GFX &Screen::gfx() { return *canvas; }

void Screen::clear() { canvas->fillScreen(BLACK); }

void Screen::show()
{
    // Unverändert nichts übertragen: die Anzeige wird in jedem Durchlauf von
    // loop() neu gezeichnet, ein volles Bild über SPI kostet aber ~15 ms.
    const size_t bytes = (size_t)canvas->width() * canvas->height() * 2;
    uint32_t crc = crc32_le(0, (const uint8_t *)canvas->getBuffer(), bytes);
    if (crc == shownCrc)
    {
        return;
    }
    shownCrc = crc;
    tft.drawRGBBitmap(0, 0, canvas->getBuffer(), canvas->width(), canvas->height());
}

uint8_t Screen::textSize(uint8_t oledSize) { return (oledSize * 3 + 1) / 2; }

// Die Symbole sind für 128×64 gezeichnet; doppelt so groß lesen sie sich
// auf dem Farbdisplay aus Armeslänge.
static const int16_t ICON_SCALE = 2;

#else
//************************************************************************
// SSD1306, 128×64, per I2C an 21 (SDA) / 22 (SCL).
#include <Wire.h>
#include <Adafruit_SSD1306.h>

#ifndef SCREEN_ROTATION
#define SCREEN_ROTATION 2
#endif

static Adafruit_SSD1306 oled(128, 64, &Wire, 0);

bool Screen::begin()
{
    if (!oled.begin(SSD1306_SWITCHCAPVCC, 0x3C))
    {
        return false;
    }
    oled.setRotation(SCREEN_ROTATION);
    oled.clearDisplay();
    return true;
}

Adafruit_GFX &Screen::gfx() { return oled; }

void Screen::clear() { oled.clearDisplay(); }

void Screen::show() { oled.display(); }

uint8_t Screen::textSize(uint8_t oledSize) { return oledSize; }

static const int16_t ICON_SCALE = 1;
#endif

//************************************************************************
// Für beide Displays gleich.

#if !defined(SCREEN_ST7789)
// Einfarbig: alles, was nicht schwarz ist, leuchtet.
static uint16_t ink(uint16_t color) { return color ? SSD1306_WHITE : SSD1306_BLACK; }
#else
static uint16_t ink(uint16_t color) { return color; }
#endif

int16_t Screen::width() { return gfx().width(); }

int16_t Screen::height() { return gfx().height(); }

int16_t Screen::lineHeight(uint8_t oledSize) { return 8 * textSize(oledSize); }

int16_t Screen::iconHeight(int16_t h) { return h * ICON_SCALE; }

void Screen::centerText(const String &text, int16_t y, uint8_t oledSize, uint16_t color)
{
    Adafruit_GFX &g = gfx();
    const uint8_t size = textSize(oledSize);
    // Eingebaute Schrift: 6 Pixel je Zeichen. Zu Langes beginnt links und
    // bricht um, statt links aus dem Bild zu laufen.
    int16_t textWidth = text.length() * 6 * size;
    int16_t x = textWidth < width() ? (width() - textWidth) / 2 : 0;
    g.setTextSize(size);
    g.setTextColor(ink(color));
    g.setTextWrap(true);
    g.setCursor(x, y);
    g.print(text);
}

void Screen::rightText(const String &text, int16_t y, uint8_t oledSize, uint16_t color)
{
    Adafruit_GFX &g = gfx();
    const uint8_t size = textSize(oledSize);
    g.setTextSize(size);
    g.setTextColor(ink(color));
    g.setTextWrap(false);
    g.setCursor(width() - text.length() * 6 * size, y);
    g.print(text);
}

void Screen::plotIconPixel(int16_t x0, int16_t y0, int16_t i, int16_t j, uint16_t color)
{
    gfx().fillRect(x0 + i * ICON_SCALE, y0 + j * ICON_SCALE, ICON_SCALE, ICON_SCALE, ink(color));
}

void Screen::centerIcon(const uint8_t *bits, int16_t w, int16_t h, int16_t y,
                        const uint16_t *partColors, uint8_t colorCount)
{
    // Platz für das größte Symbol aus icons.h (63×49). Statisch, weil das
    // Zeichnen nie verschachtelt passiert und der Stack der Arduino-Task klein ist.
    static const int16_t MAX_PIXELS = 64 * 50;
    static uint8_t part[MAX_PIXELS];
    static uint16_t todo[MAX_PIXELS];

    const int16_t x0 = (width() - w * ICON_SCALE) / 2;
    const int16_t bytesPerRow = (w + 7) / 8;
    auto isSet = [&](int16_t i, int16_t j) {
        return pgm_read_byte(&bits[j * bytesPerRow + i / 8]) & (0x80 >> (i & 7));
    };

    if (w * h > MAX_PIXELS || colorCount == 0)
    {
        return;
    }

    // Zusammenhängende Teile durchnummerieren (8er-Nachbarschaft), damit Tür
    // und Pfeil oder die einzelnen WLAN-Bögen ihre eigene Farbe bekommen.
    memset(part, 0, w * h);
    uint8_t parts = 0;
    for (int16_t j = 0; j < h; j++)
    {
        for (int16_t i = 0; i < w; i++)
        {
            if (!isSet(i, j) || part[j * w + i])
            {
                continue;
            }
            parts = parts < 254 ? parts + 1 : parts;
            const uint16_t color = partColors[min<int>(parts - 1, colorCount - 1)];
            int16_t top = 0;
            todo[top++] = j * w + i;
            part[j * w + i] = parts;
            while (top)
            {
                const int16_t at = todo[--top];
                const int16_t pi = at % w, pj = at / w;
                plotIconPixel(x0, y, pi, pj, color);
                for (int16_t dj = -1; dj <= 1; dj++)
                {
                    for (int16_t di = -1; di <= 1; di++)
                    {
                        const int16_t ni = pi + di, nj = pj + dj;
                        if (ni < 0 || nj < 0 || ni >= w || nj >= h)
                        {
                            continue;
                        }
                        if (isSet(ni, nj) && !part[nj * w + ni])
                        {
                            part[nj * w + ni] = parts;
                            todo[top++] = nj * w + ni;
                        }
                    }
                }
            }
        }
    }
}
