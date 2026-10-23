-- Schema-Update für MySQL/MariaDB, Stand 2026-10-23.
--
-- NUR nötig, wenn `php artisan migrate --force` nicht laufen kann (kein SSH).
-- Wo artisan läuft, ist das der bessere Weg.
--
-- Setzt auf 2026-10-01-update.sql auf (bzw. auf einen Stand, an dem
-- 0001_01_01_002000_create_device_events schon gelaufen ist).
--
-- Entspricht diesen drei Migrationen:
--   0001_01_01_002100_add_kiosk_stamping
--   0001_01_01_002200_create_user_log_changes
--   0001_01_01_002300_add_device_status
--
-- WIEDERHOLBAR: jeder Schritt prüft erst, ob er schon erledigt ist. Das Skript
-- lässt sich also auch dann laufen, wenn ein früherer Versuch mittendrin
-- abgebrochen ist oder Teile schon über artisan liefen. Es repariert dabei
-- auch den Fall, dass Tabellen angelegt wurden, ohne sie in `migrations`
-- einzutragen — dann scheitert `artisan migrate` sonst mit
-- "Base table or view already exists".
--
-- Die Spalten-Typen stammen aus Laravels MySQL-Grammatik, nicht von Hand.
--
-- VORHER EINE SICHERUNG ZIEHEN. MySQL rollt DDL nicht zurück.

-- ---------------------------------------------------------------------------
-- 1) Stempeln ohne Karte: Namen am Gerät wählen, PIN eingeben
-- ---------------------------------------------------------------------------

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'employees' and column_name = 'kiosk_enabled') = 0,
  'alter table `employees` add `kiosk_enabled` tinyint(1) not null default ''0''', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'employees' and column_name = 'kiosk_pin') = 0,
  'alter table `employees` add `kiosk_pin` varchar(255) null', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

-- Wie eine Stempelung entstand. Bestehende Zeilen kamen alle von einer Karte.
set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'users_logs' and column_name = 'source') = 0,
  'alter table `users_logs` add `source` varchar(10) not null default ''card''', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

-- ---------------------------------------------------------------------------
-- 2) Änderungsprotokoll für Stempelungen
-- ---------------------------------------------------------------------------
-- `user_log_id` bewusst ohne Fremdschlüssel: der Eintrag soll eine gelöschte
-- Stempelung überleben.

create table if not exists `user_log_changes` (
  `id` bigint unsigned not null auto_increment primary key,
  `user_log_id` bigint unsigned not null,
  `employee_id` bigint unsigned null,
  `action` varchar(10) not null,
  `before` json null,
  `after` json null,
  `changed_by` bigint unsigned null,
  `channel` varchar(10) not null,
  `created_at` timestamp not null default CURRENT_TIMESTAMP,
  key `user_log_changes_user_log_id_index` (`user_log_id`),
  key `user_log_changes_employee_id_index` (`employee_id`)
) default character set utf8mb4;

-- ---------------------------------------------------------------------------
-- 3) Zustandsbericht der Leser (WLAN, Speicher, Laufzeit, Neustartgrund …)
-- ---------------------------------------------------------------------------

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'devices' and column_name = 'status_report') = 0,
  'alter table `devices` add `status_report` json null', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'devices' and column_name = 'status_reported_at') = 0,
  'alter table `devices` add `status_reported_at` datetime null', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

-- ---------------------------------------------------------------------------
-- 4) Laravel sagen, dass diese Migrationen erledigt sind
-- ---------------------------------------------------------------------------
-- Ohne das versucht `php artisan migrate` sie beim nächsten Mal erneut und
-- bricht mit "Base table or view already exists" ab. Nachgetragen wird nur,
-- was noch fehlt.

set @batch := (select coalesce(max(`batch`), 0) + 1 from `migrations`);

insert into `migrations` (`migration`, `batch`)
select m.name, @batch from (
      select '0001_01_01_002100_add_kiosk_stamping' as name
  union all select '0001_01_01_002200_create_user_log_changes'
  union all select '0001_01_01_002300_add_device_status'
) as m
left join `migrations` done on done.`migration` = m.name
where done.`id` is null;

-- ---------------------------------------------------------------------------
-- 5) Kontrolle
-- ---------------------------------------------------------------------------
-- Erwartet: drei Zeilen. Fehlt eine, ist der zugehörige Schritt oben nicht
-- gelaufen — dann die Fehlermeldung von dort ansehen, nicht einfach eintragen.

select `migration`, `batch` from `migrations`
where `migration` in (
  '0001_01_01_002100_add_kiosk_stamping',
  '0001_01_01_002200_create_user_log_changes',
  '0001_01_01_002300_add_device_status'
)
order by `migration`;

-- Erwartet: 5 (zwei an `employees`, eine an `users_logs`, zwei an `devices`).
select count(*) as neue_spalten from information_schema.columns
where table_schema = database()
  and (   (table_name = 'employees'  and column_name in ('kiosk_enabled', 'kiosk_pin'))
       or (table_name = 'users_logs' and column_name = 'source')
       or (table_name = 'devices'    and column_name in ('status_report', 'status_reported_at')));

-- ---------------------------------------------------------------------------
-- Danach noch:
--   php artisan optimize:clear          (oder Caches über das Panel leeren)
-- ---------------------------------------------------------------------------
