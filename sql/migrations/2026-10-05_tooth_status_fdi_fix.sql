-- Odontogram saves: tooth_selected stores the FDI tooth number (11–48) and status stores
-- the chart code ("C", "Am", "M"…). Run once on the live database (MariaDB) before
-- deploying the code. The app also applies this on its own the first time a chart is saved.
--
-- 1. The old foreign key pointed tooth_selected at tooth.tooth_id (row ids 1–32), so marks
--    on lower teeth 33–48 were rejected and lost.
-- 2. status was an ENUM ('healthy','decayed',…) that saved chart codes as blank.
--
-- Not needed if you import sql/final/capstone_dental_v14_defense_clean_2026-10-05.sql,
-- which already has both changes.

ALTER TABLE tooth_status DROP FOREIGN KEY IF EXISTS fk_tooth_status_tooth;

ALTER TABLE tooth_status MODIFY status VARCHAR(10) NULL DEFAULT NULL;
