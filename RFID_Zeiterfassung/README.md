# RFID Zeiterfassung (Laravel + Filament)

Re-implementation of the legacy plain-PHP attendance system (`../rfidattendance/`)
as a Laravel 12 + Filament v3 admin panel. The device-facing API is preserved
so existing ESP32 readers keep working without reflashing.

## Stack

- Laravel 12, PHP 8.2+
- Filament v3 admin panel (`/admin`)
- SQLite locally; schema mirrors the legacy MySQL tables (`admin`, `devices`,
  `users`, `users_logs`) so it can point at the existing production database
  with zero data migration.

## Setup

```bash
composer install
cp .env.example .env          # already provided here
php artisan key:generate      # APP_KEY already set in this repo
touch database/database.sqlite
php artisan migrate --seed
php artisan serve             # http://127.0.0.1:8000  ->  /admin
```

Seeded login: **admin@example.de** / **password** (change under *Einstellungen*).

### Using the production MySQL database

Set in `.env` and skip `migrate`/`seed` (the tables already exist):

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=rfidattendance
DB_USERNAME=...
DB_PASSWORD=...
```

The `admin` table gains one additive nullable column (`remember_token`); run
`php artisan migrate` once against MySQL to add it, or add it manually.

## Device API (unchanged contract)

`GET /getdata.php?device_token=<16 hex>&card_uid=<8–32 hex>` — same params,
responses and status codes as the legacy `getdata.php`.

The token may instead travel as `Authorization: Bearer <16 hex>`, which keeps it
out of server and proxy logs. *Einstellungen → Anmeldung der Leser* decides what
is accepted: both (default), header only, or query only. Switch to header-only
once every reader runs firmware that sends it — otherwise you lock out your own
clocks.

| Result | Status | Body |
|---|---|---|
| Check-in | 200 | `login<username>` |
| Check-out | 200 | `logout<username>` |
| New card learned | 200 | `successful` |
| Card already known (learn mode) | 200 | `available` |
| Any failure | 503 | `Error: <german message>` |

Device modes: `0` = Registrierung (learn cards), `1` = Zeiterfassung (attendance).

### Buffered stampings (`POST /api/v1/stampings`)

For readers that keep stampings while the network is down. Token in the
`Authorization: Bearer` header; the query parameter is not accepted here.

```json
{ "firmware": "2.4", "pending": 3,
  "events": [ { "uid": "17", "card_uid": "DEADBEEF", "at": "2026-10-01T07:03:12+02:00" } ] }
```

Answers with one result per event, in the same order:

```json
{ "server_time": "2026-10-01T07:05:00+02:00",
  "results": [ { "uid": "17", "status": "checkin", "name": "Max Mustermann",
                 "message": "", "duplicate": false } ] }
```

`status` is one of `checkin`, `checkout`, `learned`, `known`, `failed`.

Three things set it apart from the legacy endpoint:

- **Several at once**, so a reader empties its buffer in one request (max 200).
- **The device's own time.** A stamping books when the card was held, not when
  the upload succeeded — without that a buffer would be pointless. `at` may be
  omitted by a reader without a clock; then server time applies. A clock running
  more than five minutes ahead is ignored in favour of server time.
- **Safe to repeat.** Each event carries a `uid`, unique per device. If the
  answer is lost and the reader sends again, nothing is booked twice — the
  stored result comes back with `duplicate: true`. Delivered events are kept in
  `device_events`, which doubles as the log of what each reader reported and how
  long it was offline.

## Employees, contracts, worktime & absences

The app is employee-centric. **Employees log into the same panel**; what they see
is governed by their role (Mitarbeiter / Vorgesetzter / Personal / Administrator).

- **Mitarbeiter (employees)** — central identity; each may hold **several RFID
  cards**. Login accounts live here (the legacy `admin` accounts are migrated in
  automatically, passwords intact). Cards link to an employee via `users.employee_id`.
- **Verträge (contracts)** — per employee with `from`/`to` validity and a
  pluggable expected-worktime model: **hours per week**, **hours per month**,
  **fixed hours per workday**, or **tracking only** (no target). `workdays`
  selects which weekdays count.
- **Arbeitszeitkonto (work_days)** — the delivered-worktime ledger: Ist (worked) /
  Soll (expected) / Saldo per day, **grouped by month with per-month subtotals**
  and a **CSV export** (respects the date/employee filters). Rebuilt from
  attendance + approved absences by `WorktimeService`. Run
  `php artisan worktime:recalc [--days=N|--from=…--to=…]` or use the
  "Neu berechnen" button (schedule it daily in production).
- **Feiertage (holidays)** — a holiday on a contract workday yields Soll = 0
  (paid, no negative balance), costs no vacation day, and is excluded from the
  monthly workday count. **Halbe Tage** (toggle *Halber Arbeitstag*) halve the
  Soll and cost half a vacation day instead: Heiligabend and Silvester are added
  that way by the import, since neither is a statutory holiday. Untick the
  option in the import dialog (or pass `--no-half-days`) to skip them; existing
  entries on those dates are never overwritten.
  Auto-imported per Bundesland via `spatie/holidays` (configure the Bundesland
  under *Einstellungen*; import with the "Feiertage importieren" button or
  `php artisan holidays:sync --year=YYYY`), and manually editable. Recompute the
  ledger afterwards.
- **Arbeitszeitnachweis** — per-employee monthly report (panel page + PDF
  download). Shows summary (Soll/Ist/Saldo, Saldo gesamt, Resturlaub) and a
  daily breakdown rendered as **full 7-day week blocks (Mon–Sun)** with weekly
  subtotals; missing/adjacent-month days are shown so weeks stay complete.
  Employees see their own; HR/Admin pick any employee. Built from the shared
  `WorktimeReport` service; PDF via `barryvdh/laravel-dompdf`.
- **Zeitkorrektur** — HR/Admin can edit or add raw stampings under
  *Roh-Stempelungen* (e.g. when someone forgets to clock out: set the time and
  tick "Ausgecheckt"). Saving/deleting a correction recomputes that day's ledger.
- **Abwesenheiten (absences)** — Urlaub / Krank / Unbezahlt / Überstundenabbau,
  filed in advance by employees and approved/rejected by HR/Admin (single step).
  Approval recomputes the affected ledger days. Vacation counts against the
  contract's yearly entitlement; overtime reduction draws from the saldo.
  Days are counted as **contract workdays**, public holidays excluded: Saturday
  through Friday on a Mon–Fri contract costs five days, not seven. A half day
  only applies to a single-day request.
- **Saldo-Korrekturen (balance adjustments)** — manual corrections booked
  *alongside* the ledger, never into it (see below). The overtime counter is the
  sum of both, so the history stays exactly as it was recorded.

Roles & access: employees see only their own absences and work-days and the
check-in/out screen; Mitarbeiter/Verträge/Geräte/Karten/Roh-Stempelungen/
Einstellungen/Anlernen are HR/Admin only. The dashboard shows each user their
vacation balance, overtime saldo and this week's Ist/Soll.

Seeded logins: **admin@example.de** (Administrator) and **max@example.de**
(Mitarbeiter, two cards + a contract) — both password **password**.

### Resetting an overtime balance (e.g. at year end)

Legacy balances carried over from a previous system, or an agreed cut-off at the
turn of the year, are booked as a correction. The ledger itself is read-only by
design — it is the computed result of stampings and contract — so corrections
sit next to it and the counter adds the two together.

1. *Mitarbeiter* → open the employee → tab **Saldo-Korrekturen**.
2. **Saldo setzen** → pick the **Stichtag** (defaults to January 1st). The form
   shows the current balance as of that date.
3. Enter the **Zielsaldo in Stunden** (`0` starts the counter from zero) and a
   reason, then **Differenz buchen**.

The difference is computed against the balance *including* corrections already
booked, so running it twice does not deduct twice — the second run reports
"Nichts zu tun". Single corrections can also be booked by hand via
*Korrektur buchen*; all of them are listed with date, amount, reason and author,
and can be edited or deleted.

The booking date decides where the correction lands: **January 1st** counts into
the new year (the old year's carryover stays visible and is offset by the year
balance), **December 31st** disappears into the carryover instead. Pick whichever
matches how you want the Nachweis to read.

> Do **not** use *Einstellungen → "Zeiterfassung aktiv ab"* for this. That is a
> one-off go-live cut-off: `WorktimeService::recalculateDay()` **deletes** ledger
> rows before that date, which would empty out the Arbeitszeitnachweis for every
> earlier month, not just the balance.

## Panel features

- **Benutzer** — RFID cardholders; register newly-learned cards, edit details,
  link a Google calendar.
- **Karte anlernen** — enroll a card by tapping it via WebNFC. Chrome on Android
  only, and the page must be served over HTTPS (or `localhost`) — a LAN IP over
  plain HTTP will not expose NFC. The UID is normalized to the firmware format
  (uppercase hex, no separators) so browser- and reader-enrolled cards match.
- **Leser** — reader devices; mode toggle, regenerate token.
- **Zeiten-Log** — attendance records with date/department/user filters,
  timezone-converted times, worked-duration column and CSV export.
- **Ein-/Auschecken** — web check-in/out for the logged-in admin's own card.
- **Einstellungen** — operator info, timezone, Google OAuth client + connect,
  and admin profile/password.

## Unterstützen

Freie Software, kostenlos nutzbar. Spenden tragen die Weiterentwicklung und das
Hosting für Betriebe, die nicht selbst hosten können:
**[paypal.me/krasm](https://paypal.me/krasm)**. Im Panel steht dazu ein dezenter
Hinweis am Ende der Seitenleiste — nur für Personal/Administration sichtbar und
unter *Einstellungen → Allgemein* abschaltbar.

## Google Calendar

OAuth config lives in the `settings` table (no more on-disk `config.php`).
Enter the client ID/secret under *Einstellungen*, set the redirect URL to
`<app-url>/google/callback`, then click **Mit Google verbinden**. Check-in/out
creates and updates "Arbeitszeit" events on the cardholder's selected calendar.
