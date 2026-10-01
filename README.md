# Zeiterfassung

Arbeitszeiterfassung für Betriebe: RFID-Stempeluhr auf ESP32-Basis plus eine
Weboberfläche, die daraus Arbeitszeitkonten, Urlaubsverwaltung und
Arbeitszeitnachweise macht. Freie Software unter MIT-Lizenz, selbst hostbar,
ohne Lizenzkosten und ohne Nutzerlimit.

> **Status:** im Produktivbetrieb bei einem Betrieb, aber noch keine fertige
> Standardlösung. Mehrmandantenfähigkeit, ein revisionssicheres Änderungsprotokoll
> und eine arbeitsrechtliche Prüfung fehlen — siehe [Was noch fehlt](#was-noch-fehlt).
> Wer das Projekt für den eigenen Betrieb einsetzt, sollte das wissen.

## Was es kann

- **Stempeln per RFID-Karte** an einem ESP32-Leser; mehrere Karten pro Person,
  Anlernen per WebNFC direkt im Browser.
- **Arbeitszeitkonto** je Person: Ist, Soll und Saldo pro Tag, monatsweise
  summiert, aus Stempelungen und Vertrag berechnet.
- **Verträge** mit Wochen-, Monats- oder Tagesstundenmodell, eigenen Arbeitstagen,
  Urlaubsanspruch, Pausenstaffel und Tagestoleranz.
- **Abwesenheiten**: Urlaub, Krank, Sonderurlaub, Unbezahlt, Überstundenabbau —
  beantragt von Mitarbeitenden, genehmigt von der Personalabteilung. Gezählt wird
  in Arbeitstagen laut Vertrag, Feiertage und halbe Tage wie Heiligabend
  eingerechnet.
- **Feiertage** je Bundesland automatisch importiert, halbe Arbeitstage möglich.
- **Arbeitszeitnachweis** je Monat als Panel-Ansicht und PDF, mit Wochenblöcken,
  Zwischensummen und Jahresstand zum Stichtag des Monats.
- **Saldo-Korrekturen** für Altbestände, nachvollziehbar neben dem Konto gebucht
  statt hineingeschrieben.

## Loslegen

Die Webanwendung liegt in [`RFID_Zeiterfassung/`](RFID_Zeiterfassung/) — dort
stehen [Setup](RFID_Zeiterfassung/README.md) und
[Deployment](RFID_Zeiterfassung/DEPLOY.md). Die Firmware für den Leser liegt in
[`src/`](src/) und baut mit PlatformIO. `rfidattendance/` ist die abgelöste
PHP-Fassung und wird nicht mehr weiterentwickelt.

## Was noch fehlt

Ehrliche Liste für alle, die überlegen, das einzusetzen oder mitzuentwickeln:

- **Änderungsprotokoll.** Korrekturen an Stempelungen werden ohne Spur
  überschrieben. Für eine gesetzeskonforme Zeiterfassung muss nachvollziehbar
  sein, wer wann was geändert hat. Das ist der wichtigste offene Punkt.
- **Mehrmandantenfähigkeit.** Eine Installation bedient genau einen Betrieb.
- **Aufbewahrung und Löschung.** Keine automatische Löschung nach Fristablauf,
  kein Löschkonzept im Sinne der DSGVO.
- **Prüfer-Export.** Kein Ausgabeformat für Prüfungen durch Zoll oder
  Rentenversicherung.
- **Nur Deutsch.** Keine Übersetzungsebene.
- **Keine automatisierten Tests in CI.** Die Testsuite läuft nur lokal.
- **Keine arbeitsrechtliche Prüfung.** Niemand hat das Projekt gegen ArbZG,
  MiLoG und DSGVO geprüft.

Mithilfe ist willkommen, besonders bei diesen Punkten.

## Unterstützen

Das Projekt ist und bleibt kostenlos. Spenden tragen die Weiterentwicklung und
das Hosting für Betriebe, die nicht selbst hosten können:

**[paypal.me/krasm](https://paypal.me/krasm)**

## Lizenz

MIT — siehe [LICENSE](LICENSE). Der RFID-Teil geht auf ein Vorgängerprojekt
zurück, dessen Copyright-Vermerk erhalten bleibt.
