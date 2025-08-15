ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS district_id INT NULL,
    ADD CONSTRAINT fk_teams_district FOREIGN KEY (district_id) REFERENCES districts(id);

CREATE INDEX IF NOT EXISTS idx_teams_club ON teams (club_id);
CREATE INDEX IF NOT EXISTS idx_teams_district ON teams (district_id);

ALTER TABLE teams
    ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;
    CREATE INDEX IF NOT EXISTS idx_teams_active ON teams (active);
