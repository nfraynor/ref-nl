ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS district_id INT NULL,
    ADD CONSTRAINT fk_teams_district FOREIGN KEY (district_id) REFERENCES districts(id);

CREATE INDEX IF NOT EXISTS idx_teams_club ON teams (club_id);
CREATE INDEX IF NOT EXISTS idx_teams_district ON teams (district_id);

ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;
    CREATE INDEX IF NOT EXISTS idx_teams_active ON teams (active);

ALTER TABLE referees
    ADD UNIQUE KEY uq_referees_email (email);

ALTER TABLE referees
    ADD COLUMN ref_number INT NOT NULL AUTO_INCREMENT UNIQUE FIRST;

ALTER TABLE referees
    MODIFY uuid CHAR(36) NOT NULL;

ALTER TABLE referees
    MODIFY referee_id VARCHAR(50) NULL;

UPDATE referees
SET referee_id = CONCAT('REF', LPAD(ref_number, 3, '0'))
WHERE referee_id IS NULL OR referee_id = '';

ALTER TABLE referees
    ADD INDEX idx_referees_home_club_id (home_club_id),
    ADD INDEX idx_referees_district_id (district_id),
    ADD INDEX idx_referees_grade (grade);
