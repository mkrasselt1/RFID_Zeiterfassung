# Deployment (Managed-Panel, z. B. Plesk/cPanel)

Die App ist Laravel 12 + Filament v3 — ein reiner FTP-Datei-Upload wie bei der
alten Plain-PHP-App genügt **nicht**. Voraussetzungen: PHP **8.2+**, Composer und
Cron im Panel, Document-Root frei wählbar.

> Nur **`RFID_Zeiterfassung/`** wird deployt (nicht `rfidattendance/` oder `src/`).

## Einmalige Einrichtung

1. **APP_KEY erzeugen** (lokal, einmal): `php artisan key:generate --show`
   → in die Server-`.env` eintragen und stabil halten.
2. **Code hochladen** (Panel-Git oder FTP) inkl. `vendor/` *oder* per Composer-Runner
   (siehe 4) installieren.
3. **Document-Root** der Domain `arbeitszeit.kaffeeteam.de` auf
   `…/RFID_Zeiterfassung/public` setzen.
4. **Composer** im Panel im Ordner `RFID_Zeiterfassung` ausführen:
   `composer install --no-dev --optimize-autoloader`
5. **.env** auf dem Server anlegen — Vorlage: `.env.production.example` → `.env`,
   DB-Zugangsdaten der **bestehenden** Produktions-MySQL eintragen.
6. **Migration** ausführen (Panel-Terminal oder einmaliger Task):
   `php artisan migrate --force`
   - Idempotent & datensicher: bestehende Tabellen/Daten bleiben, es kommen nur
     die neuen Tabellen (employees, contracts, work_days, absences, holidays,
     settings, sessions, cache, jobs) + `users.employee_id` + `admin.remember_token` dazu.
   - Bestehende `admin`-Konten werden als `employees` (Rolle admin) übernommen —
     **Login mit den vorhandenen Zugangsdaten** (Passwörter bleiben gültig).
   - **Kein** `db:seed` in Produktion (das wäre Demo-Daten)!
7. **Feiertage importieren:** `php artisan holidays:sync` (und fürs Folgejahr:
   `php artisan holidays:sync --year=$(date +%Y -d "+1 year")`).
   Bundesland danach unter *Einstellungen* prüfen (Default DE-SN).
8. **Schreibrechte:** `storage/` und `bootstrap/cache/` für den Webserver beschreibbar.
9. **Cron** im Panel anlegen (treibt nächtlichen Ledger-Neuaufbau + jährlichen
   Feiertagsimport):
   `* * * * * php /pfad/zu/RFID_Zeiterfassung/artisan schedule:run >> /dev/null 2>&1`
10. **Google-Kalender** (optional): unter *Einstellungen* Client-ID/Secret setzen,
    Redirect-URL `https://arbeitszeit.kaffeeteam.de/google/callback` in der Google
    Cloud Console hinterlegen, dann „Mit Google verbinden".

## Plesk konkret

**Hosting-Einstellungen → Document Root:**
`…/httpdocs/RFID_Zeiterfassung/public` (Pfad an euren Git-Zielordner anpassen).

**PHP-Version:** ≥ 8.2 für die Domain auswählen.

**Git → „Zusätzliche Bereitstellungsaktionen"** (laufen nach jedem Pull im Repo-Root):

```sh
set -e                     # ohne dies läuft die Kette nach einem Fehler weiter
cd RFID_Zeiterfassung
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan migrate:status | grep -i pending && echo "WARNUNG: offene Migrationen!" || true
```

> **`set -e` ist wichtig.** Die Schritte hängen voneinander ab: schlägt
> `composer install` fehl (z. B. weil `composer.lock` eine neuere PHP-Version
> verlangt als die Domain hat), läuft ohne `set -e` `migrate` trotzdem — oder die
> Kette bricht still ab und die Migration wird übersprungen. Beides fällt erst
> auf, wenn das Panel eine SQL-Exception wirft („Unknown column …"). Nach jedem
> Deploy das Aktionsprotokoll in Plesk prüfen, nicht nur die Seite aufrufen.

`migrate --force` ist ausdrücklich für nicht-interaktive Deploys gedacht (`--force`
unterdrückt nur die Sicherheitsabfrage in Produktion, es erzwingt nichts anderes).
Die Migrationen hier sind additiv und idempotent — ein erneuter Lauf ist folgenlos.

Optional danach (Performance; nur wenn `.env` stabil ist — bei `.env`-Änderung
greift beim nächsten Deploy automatisch wieder `optimize:clear`):

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components
```

> Findet Plesk `php`/`composer` nicht, den vollen Pfad der Domain-PHP nutzen, z. B.
> `/opt/plesk/php/8.2/bin/php artisan migrate --force` und Composer über
> `/opt/plesk/php/8.2/bin/php /usr/lib/plesk-9.0/composer.phar install …`
> (oder Composer über die Plesk-Composer-Oberfläche ausführen).

**`.env` vor dem ersten Deploy** per Dateimanager anlegen (aus
`.env.production.example`) — sonst schlägt `migrate` beim ersten Lauf fehl.
`.env` ist nicht in Git.

**Geplante Aufgaben (Cron) → jede Minute:**

```sh
php /var/www/vhosts/arbeitszeit.kaffeeteam.de/httpdocs/RFID_Zeiterfassung/artisan schedule:run
```

(Treibt den nächtlichen `worktime:recalc` und den jährlichen `holidays:sync`.)

## Bei jedem weiteren Deploy

1. Code aktualisieren (Git-Pull/FTP).
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force`
4. Falls Config/Routes gecacht werden: `php artisan optimize:clear` und ggf.
   neu cachen. (Ohne Caching liest die App `.env` pro Request — auf Shared
   Hosting unkritisch.)
5. `php artisan migrate:status` — es darf nichts „Pending" übrig sein.

> Läuft nur der Git-Pull (ohne Composer/Migrate), ist der Code neu und das Schema
> alt. Die App startet trotzdem, weil `vendor/` vom letzten erfolgreichen Deploy
> liegen bleibt — der Fehler zeigt sich erst beim Schreiben („Unknown column …").
> `php artisan migrate --force` lässt sich in dem Fall gefahrlos einzeln
> nachziehen; es braucht nur ein vorhandenes `vendor/`, keinen Composer-Lauf.

## Nach dem Deploy testen

- **Geräte-API** (RFID-Leser!): `https://arbeitszeit.kaffeeteam.de/getdata.php?device_token=<16hex>&card_uid=<uid>`
  muss Klartext (`login…`/`logout…`/`Error:…`) liefern. `/getdata.php` ist jetzt
  eine Laravel-Route (keine Datei mehr) — über Apache `.htaccess` wird das auf
  `index.php` umgeschrieben. Unbedingt prüfen, damit die Stempeluhren weiterlaufen.
- **Panel-Login** unter `/admin` mit einem bestehenden Admin-Konto.
- **Favicon** und Assets laden (relative Pfade via `ASSET_URL=/`).
