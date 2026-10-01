-- Schema-Update für MySQL/MariaDB, Stand 2026-10-01.
--
-- NUR nötig, wenn `php artisan migrate --force` nicht laufen kann (kein SSH).
-- Wo artisan läuft, ist das der bessere Weg — er pflegt die Tabelle
-- `migrations` selbst und ist gegen halbe Durchläufe abgesichert.
--
-- Entspricht diesen drei Migrationen:
--   0001_01_01_001800_create_balance_adjustments
--   0001_01_01_001900_add_half_day_to_holidays
--   0001_01_01_002000_create_device_events
--
-- Erzeugt mit Laravels MySQL-Grammatik, nicht von Hand geschrieben.
--
-- VORHER EINE SICHERUNG ZIEHEN. Die Anweisungen laufen in einer Transaktion,
-- aber MySQL macht bei DDL einen stillen Commit — ein Abbruch mittendrin lässt
-- sich also nicht zurückrollen.

-- ---------------------------------------------------------------------------
-- 1) Saldo-Korrekturen: Altbestände neben dem Arbeitszeitkonto buchen
-- ---------------------------------------------------------------------------

create table `balance_adjustments` (
  `id` bigint unsigned not null auto_increment primary key,
  `employee_id` bigint unsigned not null,
  `effective_date` date not null,
  `minutes` int not null,
  `note` varchar(255) null,
  `created_by` bigint unsigned null,
  `created_at` timestamp null,
  `updated_at` timestamp null
) default character set utf8mb4;

alter table `balance_adjustments`
  add constraint `balance_adjustments_employee_id_foreign`
  foreign key (`employee_id`) references `employees` (`id`) on delete cascade;

alter table `balance_adjustments`
  add constraint `balance_adjustments_created_by_foreign`
  foreign key (`created_by`) references `employees` (`id`) on delete set null;

alter table `balance_adjustments`
  add index `balance_adjustments_employee_id_effective_date_index` (`employee_id`, `effective_date`);

-- ---------------------------------------------------------------------------
-- 2) Halbe Feiertage: Heiligabend und Silvester
-- ---------------------------------------------------------------------------

alter table `holidays` add `half_day` tinyint(1) not null default '0' after `name`;

-- ---------------------------------------------------------------------------
-- 3) Gepufferte Stempelungen und Gerätezustand
-- ---------------------------------------------------------------------------

create table `device_events` (
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
  `updated_at` timestamp null
) default character set utf8mb4;

alter table `device_events`
  add constraint `device_events_device_id_foreign`
  foreign key (`device_id`) references `devices` (`id`) on delete cascade;

-- Macht die Lieferung wiederholbar: dieselbe Kennung vom selben Leser wird
-- genau einmal gebucht.
alter table `device_events`
  add unique `device_events_device_id_event_uid_unique` (`device_id`, `event_uid`);

alter table `device_events`
  add index `device_events_occurred_at_index` (`occurred_at`);

alter table `devices` add `last_seen_at` datetime null;
alter table `devices` add `last_ip` varchar(45) null;
alter table `devices` add `firmware_version` varchar(20) null;
alter table `devices` add `pending_count` int unsigned not null default '0';
alter table `devices` add `local_ip` varchar(45) null;

-- ---------------------------------------------------------------------------
-- 4) Laravel sagen, dass diese Migrationen erledigt sind
-- ---------------------------------------------------------------------------
-- Ohne das versucht `php artisan migrate` sie beim nächsten Mal erneut und
-- bricht mit "table already exists" ab.

-- Zwei Schritte statt einer Unterabfrage auf die Zieltabelle: die mögen
-- nicht alle Server-Versionen an dieser Stelle.
set @batch := (select coalesce(max(`batch`), 0) + 1 from `migrations`);

insert into `migrations` (`migration`, `batch`) values
  ('0001_01_01_001800_create_balance_adjustments', @batch),
  ('0001_01_01_001900_add_half_day_to_holidays',   @batch),
  ('0001_01_01_002000_create_device_events',       @batch);

-- ---------------------------------------------------------------------------
-- Danach noch:
--   php artisan optimize:clear          (oder Caches über das Panel leeren)
--   php artisan holidays:sync --year=…  legt Heiligabend/Silvester als halbe
--                                       Tage an
--   php artisan worktime:recalc --from=… --to=…
--                                       damit das geänderte Soll in die
--                                       Ledger-Zeilen kommt
-- ---------------------------------------------------------------------------
