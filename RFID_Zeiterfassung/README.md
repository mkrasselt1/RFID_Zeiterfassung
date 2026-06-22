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
responses and status codes as the legacy `getdata.php`:

| Result | Status | Body |
|---|---|---|
| Check-in | 200 | `login<username>` |
| Check-out | 200 | `logout<username>` |
| New card learned | 200 | `successful` |
| Card already known (learn mode) | 200 | `available` |
| Any failure | 503 | `Error: <german message>` |

Device modes: `0` = Registrierung (learn cards), `1` = Zeiterfassung (attendance).

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
  (paid, no negative balance) and is excluded from the monthly workday count.
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

Roles & access: employees see only their own absences and work-days and the
check-in/out screen; Mitarbeiter/Verträge/Geräte/Karten/Roh-Stempelungen/
Einstellungen/Anlernen are HR/Admin only. The dashboard shows each user their
vacation balance, overtime saldo and this week's Ist/Soll.

Seeded logins: **admin@example.de** (Administrator) and **max@example.de**
(Mitarbeiter, two cards + a contract) — both password **password**.

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

## Google Calendar

OAuth config lives in the `settings` table (no more on-disk `config.php`).
Enter the client ID/secret under *Einstellungen*, set the redirect URL to
`<app-url>/google/callback`, then click **Mit Google verbinden**. Check-in/out
creates and updates "Arbeitszeit" events on the cardholder's selected calendar.
