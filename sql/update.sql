ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS district_id INT NULL,
    ADD CONSTRAINT fk_teams_district FOREIGN KEY (district_id) REFERENCES districts(id);

CREATE INDEX IF NOT EXISTS idx_teams_club ON teams (club_id);
CREATE INDEX IF NOT EXISTS idx_teams_district ON teams (district_id);

ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;
    CREATE INDEX IF NOT EXISTS idx_teams_active ON teams (active);

-- 1) Ensure email is unique
ALTER TABLE referees
    ADD UNIQUE KEY uq_referees_email (email);

-- 2) Add sequential counter like clubs (if not already present)
ALTER TABLE referees
    ADD COLUMN ref_number INT NOT NULL AUTO_INCREMENT UNIQUE FIRST;

-- 3) Keep uuid as the PK, but make sure it’s NOT NULL
ALTER TABLE referees
    MODIFY uuid CHAR(36) NOT NULL;

-- 4) Make referee_id nullable so we can fill it after insert
ALTER TABLE referees
    MODIFY referee_id VARCHAR(50) NULL;

-- 5) Backfill referee_id from ref_number if missing
UPDATE referees
SET referee_id = CONCAT('REF', LPAD(ref_number, 3, '0'))
WHERE referee_id IS NULL OR referee_id = '';

-- 6) Optional indexes for performance
ALTER TABLE referees
    ADD INDEX idx_referees_home_club_id (home_club_id),
    ADD INDEX idx_referees_district_id (district_id),
    ADD INDEX idx_referees_grade (grade);
