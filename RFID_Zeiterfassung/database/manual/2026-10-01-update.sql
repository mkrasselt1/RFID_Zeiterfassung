-- Schema-Update für MySQL/MariaDB, Stand 2026-10-01.
--
-- NUR nötig, wenn `php artisan migrate --force` nicht laufen kann (kein SSH).
-- Wo artisan läuft, ist das der bessere Weg.
--
-- Entspricht diesen drei Migrationen:
--   0001_01_01_001800_create_balance_adjustments
--   0001_01_01_001900_add_half_day_to_holidays
--   0001_01_01_002000_create_device_events
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
-- 1) Saldo-Korrekturen: Altbestände neben dem Arbeitszeitkonto buchen
-- ---------------------------------------------------------------------------

create table if not exists `balance_adjustments` (
  `id` bigint unsigned not null auto_increment primary key,
  `employee_id` bigint unsigned not null,
  `effective_date` date not null,
  `minutes` int not null,
  `note` varchar(255) null,
  `created_by` bigint unsigned null,
  `created_at` timestamp null,
  `updated_at` timestamp null,
  key `balance_adjustments_employee_id_effective_date_index` (`employee_id`, `effective_date`),
  constraint `balance_adjustments_employee_id_foreign`
    foreign key (`employee_id`) references `employees` (`id`) on delete cascade,
  constraint `balance_adjustments_created_by_foreign`
    foreign key (`created_by`) references `employees` (`id`) on delete set null
) default character set utf8mb4;

-- ---------------------------------------------------------------------------
-- 2) Halbe Feiertage: Heiligabend und Silvester
-- ---------------------------------------------------------------------------

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'holidays' and column_name = 'half_day') = 0,
  'alter table `holidays` add `half_day` tinyint(1) not null default ''0'' after `name`',
  'do 0');
prepare s from @sql; execute s; deallocate prepare s;

-- ---------------------------------------------------------------------------
-- 3) Gepufferte Stempelungen und Gerätezustand
-- ---------------------------------------------------------------------------

create table if not exists `device_events` (
  `id` bigint unsigned not null auto_increment primary key,
  `device_id` bigint unsigned not null,
  `event_uid` varchar(64) not null,
  `card_uid` varchar(32) not null,
  `occurred_at` datetime not null,
  `received_at` datetime not null,
  `status` varchar(20) not null,
  `message` varchar(255) null,
  `user_log_id` bigint unsigned null,
  `created_at` timestamp null,
  `updated_at` timestamp null,
  -- Macht die Lieferung wiederholbar: dieselbe Kennung vom selben Leser wird
  -- genau einmal gebucht.
  unique key `device_events_device_id_event_uid_unique` (`device_id`, `event_uid`),
  key `device_events_occurred_at_index` (`occurred_at`),
  constraint `device_events_device_id_foreign`
    foreign key (`device_id`) references `devices` (`id`) on delete cascade
) default character set utf8mb4;

-- Die fünf Zustandsspalten an `devices`, jede für sich geprüft: ein früherer
-- Abbruch kann mitten in dieser Reihe passiert sein.

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'devices' and column_name = 'last_seen_at') = 0,
  'alter table `devices` add `last_seen_at` datetime null', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'devices' and column_name = 'last_ip') = 0,
  'alter table `devices` add `last_ip` varchar(45) null', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'devices' and column_name = 'firmware_version') = 0,
  'alter table `devices` add `firmware_version` varchar(20) null', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'devices' and column_name = 'pending_count') = 0,
  'alter table `devices` add `pending_count` int unsigned not null default ''0''', 'do 0');
prepare s from @sql; execute s; deallocate prepare s;

set @sql := if(
  (select count(*) from information_schema.columns
     where table_schema = database() and table_name = 'devices' and column_name = 'local_ip') = 0,
  'alter table `devices` add `local_ip` varchar(45) null', 'do 0');
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
      select '0001_01_01_001800_create_balance_adjustments' as name
  union all select '0001_01_01_001900_add_half_day_to_holidays'
  union all select '0001_01_01_002000_create_device_events'
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
  '0001_01_01_001800_create_balance_adjustments',
  '0001_01_01_001900_add_half_day_to_holidays',
  '0001_01_01_002000_create_device_events'
)
order by `migration`;

-- Erwartet: 5 (die Zustandsspalten an `devices`).
select count(*) as devices_spalten from information_schema.columns
where table_schema = database() and table_name = 'devices'
  and column_name in ('last_seen_at', 'last_ip', 'firmware_version', 'pending_count', 'local_ip');

-- ---------------------------------------------------------------------------
-- Danach noch:
--   php artisan optimize:clear          (oder Caches über das Panel leeren)
--   php artisan holidays:sync --year=…  legt Heiligabend/Silvester als halbe
--                                       Tage an
--   php artisan worktime:recalc --from=… --to=…
--                                       damit das geänderte Soll in die
--                                       Ledger-Zeilen kommt
-- ---------------------------------------------------------------------------
