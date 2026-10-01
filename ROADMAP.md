# Roadmap: von der Hauslösung zur Standardlösung

Ziel: eine Arbeitszeiterfassung, die ein Betrieb ohne Lizenzkosten einsetzen
kann — mit eigener Stempeluhr für ein Zehntel des Preises kommerzieller
Terminals, selbst hostbar, und so gebaut, dass sie einer Prüfung standhält.

Die Reihenfolge ist Absicht: erst das, was Vertrauen schafft, dann die Hardware,
dann das Mitmachen, zuletzt die Reichweite. Aufmerksamkeit für ein Projekt, das
man nicht in fünf Minuten starten kann, ist verschwendete Aufmerksamkeit.

**Aufwand** ist grob geschätzt: S = ein Abend, M = ein Wochenende, L = mehrere
Wochenenden, XL = ein längeres Vorhaben.

---

## A — Rechtssicherheit und Vertrauen

Ohne diesen Block sollte das Projekt nicht als „erfüllt die gesetzlichen
Anforderungen" beworben werden. Das ist das eine Versprechen, das Vertrauen
kostet, wenn es nicht hält.

- [ ] **Änderungsprotokoll für Stempelungen** — M · *der wichtigste Punkt*
  Personal und Administration können Roh-Stempelungen heute spurlos ändern und
  löschen. Jede Änderung muss eine unveränderliche Zeile schreiben: Zeitpunkt,
  Urheber, alter Wert, neuer Wert, Grund. Eine Zeiterfassung, in der sich Zeiten
  spurlos ändern lassen, taugt nicht für den Zweck, für den sie da ist — im
  Streitfall zu belegen, wann jemand gearbeitet hat.
- [ ] **Protokoll auch für Abwesenheiten, Verträge und Saldo-Korrekturen** — S
  Korrekturen führen bereits ein `created_by`; der Rest fehlt.
- [ ] **Löschkonzept** — M
  Aufbewahrungsfrist konfigurierbar, automatische Löschung nach Fristablauf,
  Protokoll darüber. Dazu eine Übersicht, welche Daten wie lange liegen.
- [ ] **Prüfer-Export** — M
  Ein Ausgabeformat, das ein Prüfer akzeptiert: vollständiger, maschinenlesbarer
  Export je Person und Zeitraum, inklusive Änderungsprotokoll.
- [ ] **Datenauskunft und Export für Mitarbeitende** — S
  Eigene Daten einsehen und herunterladen (DSGVO-Auskunft).
- [ ] **Zwei-Faktor-Anmeldung für Personal und Administration** — S
  Wer fremde Arbeitszeiten ändern darf, braucht mehr als ein Passwort.
- [ ] **Rollen schärfen** — S
  Heute vier Rollen; „darf Zeiten korrigieren" sollte getrennt vergebbar sein
  von „darf Stammdaten pflegen".
- [ ] **Arbeitsrechtliche Prüfung** — XL · *braucht Geld, nicht Code*
  Ein Fachanwalt für Arbeitsrecht und jemand für Datenschutz sehen sich das an.
  Ergebnis als Dokument ins Repository. Dafür lohnen sich Spenden.
- [ ] **Muster-Betriebsvereinbarung** — M
  Vorlage, die ein Betrieb mit seinem Betriebsrat verwenden kann.

---

## B — Hardware: der eigentliche Unterschied

Der physische Leser ist der größte Vorteil gegenüber kommerziellen Lösungen —
dort kostet ein Terminal schnell mehrere hundert Euro, hier liegt die
Materialseite bei einem Bruchteil. Damit das trägt, muss der Nachbau trivial
sein und das Gerät zuverlässig.

### B1 — Zuverlässigkeit zuerst

- [ ] **Offline-Puffer im Gerät** — M · *kritisch*
  Fällt das WLAN aus, ist die Stempelung heute verloren. Das Gerät muss sie
  lokal ablegen und später nachliefern. SPIFFS hält bisher nur die Konfiguration.
- [ ] **Echtzeituhr und Gerätezeitstempel** — M
  Hängt am Puffer: eine nachgelieferte Stempelung braucht die Zeit, zu der sie
  entstand, nicht die des Uploads. Der ESP32 ohne RTC verliert die Zeit beim
  Stromausfall.
- [ ] **Geräte-API härten** — M
  Heute `GET /getdata.php?device_token=…&card_uid=…`. Das Token steht in der
  URL und landet damit in Server-, Proxy- und Browserverläufen, und ein GET
  ändert Zustand. Nötig: POST, Token im Header, signierte Anfragen, Schutz gegen
  Wiedereinspielen, und eine Idempotenz-Kennung, damit eine doppelt gelieferte
  Stempelung nicht doppelt zählt. Der alte Endpunkt kann übergangsweise bleiben.
- [ ] **Gerätezustand im Panel** — S
  Zuletzt gesehen, Firmware-Version, Puffergröße, WLAN-Qualität. Ein stummes
  Terminal fällt sonst erst auf, wenn jemand seine Zeiten vermisst.
- [ ] **Rückmeldung am Gerät verbessern** — S
  Hörbar und sichtbar bestätigen, dass gestempelt wurde — Name und Uhrzeit auf
  dem Display, ein Ton bei Erfolg, ein anderer bei Fehler.

### B2 — Nachbau trivial machen

- [ ] **`platformio.ini` aufräumen** — S
  `com_port = COM9` ist fest eingetragen; auf jedem anderen Rechner als dem des
  Autors scheitert der Upload. Port automatisch erkennen, Boards als getrennte
  Umgebungen.
- [ ] **Firmware im Browser flashen** — M · *größter Hebel für Verbreitung*
  Mit ESP Web Tools lässt sich ein Board direkt aus einer Webseite flashen, ohne
  Toolchain, ohne Treiberbastelei. Fertige Binärdateien pro Release, eine kleine
  Flash-Seite auf GitHub Pages. Damit wird aus „man müsste mal" ein Nachmittag.
- [ ] **Einrichtung ohne Neuflashen** — S
  WLAN, Serveradresse und Token stellt WiFiManager schon ein; das sollte
  dokumentiert und mit einem QR-Code aus dem Panel heraus vereinfacht werden.
- [ ] **Referenz-Stückliste mit Preisen und Bezugsquellen** — S
  Zwei oder drei empfohlene Aufbauten, je mit Gesamtpreis und Fotos. Daneben die
  Rechnung gegen ein kommerzielles Terminal — das ist das Argument.
- [ ] **Gehäuse zum Drucken** — M
  STL-Dateien im Repository, für jeden empfohlenen Aufbau eines.
- [ ] **Aufbauanleitung mit Bildern** — M
  Verkabelung, Flashen, Einrichten, erster Test. Für jemanden ohne
  Elektronikerfahrung.

### B3 — Mehrere Geräte unterstützen

- [ ] **Leser und Anzeige hinter eine Schnittstelle legen** — M
  Heute sind MFRC522 und SSD1306 fest verdrahtet (`main.cpp`, 496 Zeilen).
  Getrennte Schnittstellen für „Karte lesen" und „etwas anzeigen" erlauben
  mehrere Boards aus einer Codebasis, statt die Firmware zu gabeln.
- [ ] **Variante M5Stack Dial** — M · *vielversprechend, vorher prüfen*
  Fertiges Gerät mit Gehäuse, rundem Touch-Display, Drehencoder und eingebautem
  RFID-Leser — kein Steckbrett, kein Löten, ein Kabel. Für einen Betrieb sieht
  das nach Produkt aus, nicht nach Bastelei, und das entscheidet mit darüber, ob
  jemand es an die Wand hängt.
  Zu klären, bevor wir das empfehlen: Der eingebaute Leser ist **nicht** der
  MFRC522, die Lesebibliothek muss also ausgetauscht werden — darum steht die
  Schnittstelle oben zuerst. Außerdem zu prüfen: welche Kartentypen er liest
  (die vorhandenen Werksausweise müssen funktionieren), ob eine Echtzeituhr
  verbaut ist, Stromversorgung über Dauerbetrieb, und der tatsächliche Preis.
  Ein Testgerät kaufen und einen Tag damit verbringen, bevor es in die Stückliste
  kommt.
- [ ] **Günstigste Variante dokumentieren** — S
  ESP32 plus MFRC522 plus OLED bleibt die Sparvariante. Beide Wege nebeneinander
  beschreiben, mit ehrlichem Vergleich.
- [ ] **Kartentypen und Werksausweise** — M
  Welche Karten funktionieren, welche nicht. Viele Betriebe haben schon Ausweise
  und wollen die weiter nutzen — das kann den Ausschlag geben.
- [ ] **Firmware-Aktualisierung über die Luft** — L
  Bei mehreren Geräten im Betrieb will niemand mit dem Laptop herumlaufen.

---

## C — Betrieb und Mitmachen

- [ ] **Tests in der CI** — S
  Die 80 Tests laufen bisher nur lokal. Ein Workflow, der sie bei jedem Push
  ausführt, plus Linter.
- [ ] **Docker-Compose zum Ausprobieren** — M
  `docker compose up`, Demo-Daten, fertig. Ohne das probiert kaum jemand etwas aus.
- [ ] **`CONTRIBUTING.md`, Issue- und PR-Vorlagen, Verhaltenskodex** — S
- [ ] **Installationsanleitung für die üblichen Wege** — M
  Managed-Hosting gibt es schon (`DEPLOY.md`); dazu Docker und ein eigener Server.
- [ ] **Aktualisierungspfad** — S
  Was tut man bei einem neuen Release? Migrationen, Neuberechnung, Rückfallplan.
- [ ] **Übersetzungen** — M
  Heute nur Deutsch, fest im Code. Erst die Übersetzungsebene einziehen, dann
  Englisch — ohne Englisch bleibt das Projekt auf den deutschsprachigen Raum
  beschränkt.
- [ ] **Versionsnummern und Änderungsprotokoll** — S
  Semantische Versionen, `CHANGELOG.md`, Releases auf GitHub mit den
  Firmware-Binärdateien im Anhang.
- [ ] **Urheberschaft klären** — S
  Die `LICENSE` nennt „Electronics Tech HaIs" von 2020, aus dem Vorgängerprojekt.
  Sauber trennen, was übernommen und was neu ist, damit die Rechtslage eindeutig
  bleibt.

---

## D — Mehrere Betriebe und Hosting

- [ ] **Mehrmandantenfähigkeit** — XL
  Eine Installation bedient heute genau einen Betrieb. Voraussetzung für jedes
  Hosting-Angebot, das mehr als eine Handvoll Betriebe bedient.
- [ ] **Sicherung und Wiederherstellung** — M
  Dokumentiert und erprobt, nicht nur vorhanden.
- [ ] **Hosting-Angebot** — L · *erst nach Block A, und bewusst klein*
  Wer Arbeitszeitdaten fremder Betriebe verarbeitet, ist Auftragsverarbeiter:
  Verträge mit jedem Betrieb, technische und organisatorische Maßnahmen,
  Meldepflicht bei Datenpannen, Haftung. Das endet nicht, wenn man mal keine
  Lust mehr hat. Bis dahin hilft eine wirklich gute Installationsanleitung mehr
  Leuten.

---

## E — Reichweite

Erst sinnvoll, wenn A, B2 und C stehen.

- [ ] **Projektseite** — M
  Was es kann, was es kostet (nichts), wie die Hardware aussieht, Bilder vom
  Gerät an der Wand. Die Preisrechnung gegen kommerzielle Terminals gehört
  nach oben.
- [ ] **Beispielinstallation zum Anschauen** — M
  Mit Demo-Daten, die sich täglich zurücksetzen.
- [ ] **Dort auftauchen, wo gesucht wird** — S
  Awesome-Selfhosted, Verzeichnisse für freie Software, einschlägige Foren.
- [ ] **Der Hardware-Artikel** — M
  „Stempeluhr für unter 50 Euro selbst gebaut" ist die Geschichte, die
  weitergetragen wird — nicht die Weboberfläche. Mit Fotos und Stückliste.
- [ ] **Spenden sichtbar und nachvollziehbar** — S
  Sponsor-Button und Hinweis im Panel stehen. Dazu gehört, offenzulegen, wofür
  das Geld verwendet wird — Rechtsprüfung, Hosting, Testgeräte.

---

## Was zuerst?

Wer hier anfangen will und nicht weiß wo: **das Änderungsprotokoll (A)** und
**der Offline-Puffer (B1)**. Beides sind Fehler, die stillschweigend Daten
verfälschen oder verlieren — alles andere ist Komfort dagegen.
