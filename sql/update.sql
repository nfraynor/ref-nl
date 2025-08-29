/* ========== TEAMS ========== */
ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS district_id INT NULL;

CREATE INDEX IF NOT EXISTS idx_teams_club     ON teams (club_id);
CREATE INDEX IF NOT EXISTS idx_teams_district ON teams (district_id);

ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1;

CREATE INDEX IF NOT EXISTS idx_teams_active   ON teams (active);

/* NOTE: Adding a FOREIGN KEY idempotently without dynamic SQL is tricky.
   If you need it and it isn't there yet, run ONCE:
   ALTER TABLE teams
     ADD CONSTRAINT fk_teams_district FOREIGN KEY (district_id) REFERENCES districts(id);
*/

/* ========== REFEREES ========== */
CREATE UNIQUE INDEX IF NOT EXISTS uq_referees_email ON referees (email);

ALTER TABLE referees
    ADD COLUMN IF NOT EXISTS ref_number INT NULL UNIQUE;

ALTER TABLE referees
    MODIFY COLUMN uuid CHAR(36) NOT NULL;

ALTER TABLE referees
    MODIFY COLUMN referee_id VARCHAR(50) NULL;

/* Backfill ref_number only for rows that don't have it yet */
SET @n := (SELECT COALESCE(MAX(ref_number),0) FROM referees);
UPDATE referees
    JOIN (
             SELECT uuid, (@n := @n + 1) AS new_num
             FROM referees
             WHERE ref_number IS NULL
             ORDER BY uuid
         ) AS seq ON seq.uuid = referees.uuid
SET referees.ref_number = seq.new_num;

/* Backfill referee_id from ref_number when missing/empty */
/* 1) (Optional but recommended) normalize existing ids to avoid whitespace/case surprises */
UPDATE referees
SET referee_id = UPPER(TRIM(referee_id))
WHERE referee_id IS NOT NULL AND referee_id <> UPPER(TRIM(referee_id));

/* 2) Ensure ref_number exists for rows that are still NULL (keeps your earlier logic) */
SET @n := (SELECT COALESCE(MAX(ref_number),0) FROM referees);
UPDATE referees r
    JOIN (
             SELECT uuid, (@n := @n + 1) AS new_num
             FROM referees
             WHERE ref_number IS NULL
             ORDER BY uuid
         ) seq ON seq.uuid = r.uuid
SET r.ref_number = seq.new_num;

/* 3) Backfill ONLY the missing referee_id values using a SAFE sequence:
      start after BOTH the largest numeric suffix already used by referee_id
      and the largest ref_number. */
SET @max_id  := (
    SELECT COALESCE(MAX(CAST(SUBSTRING(referee_id,4) AS UNSIGNED)),0)
    FROM referees
    WHERE referee_id REGEXP '^REF[0-9]+$'
    );
SET @max_num := (SELECT COALESCE(MAX(ref_number),0) FROM referees);
SET @base := GREATEST(@max_id, @max_num);
SET @k := @base;

UPDATE referees r
    JOIN (
             SELECT uuid, (@k := @k + 1) AS new_num
             FROM referees
             WHERE (referee_id IS NULL OR referee_id = '')
             ORDER BY uuid
         ) todo ON todo.uuid = r.uuid
SET r.referee_id = CONCAT('REF', LPAD(todo.new_num, 3, '0')),
    r.ref_number = COALESCE(r.ref_number, todo.new_num);  -- don't overwrite existing numbers

/* Helpful secondary indexes */
CREATE INDEX IF NOT EXISTS idx_referees_home_club_id ON referees (home_club_id);
CREATE INDEX IF NOT EXISTS idx_referees_district_id ON referees (district_id);
CREATE INDEX IF NOT EXISTS idx_referees_grade        ON referees (grade);

/* ========== MATCHES ========== */
ALTER TABLE matches
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_matches_deleted_at ON matches (deleted_at);
