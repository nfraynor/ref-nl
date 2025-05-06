-- Clubs
CREATE TABLE IF NOT EXISTS clubs (
                                     uuid CHAR(36) PRIMARY KEY,
                                     club_id VARCHAR(50) UNIQUE,
                                     club_name VARCHAR(255) NOT NULL,
                                     precise_location_lat DECIMAL(10, 7),
                                     precise_location_lon DECIMAL(10, 7),
                                     address_text VARCHAR(255)
);

-- Teams
CREATE TABLE IF NOT EXISTS teams (
                                     uuid CHAR(36) PRIMARY KEY,
                                     team_name VARCHAR(100) NOT NULL,
                                     club_id CHAR(36),
                                     division VARCHAR(100),
                                     FOREIGN KEY (club_id) REFERENCES clubs(uuid)
);

-- Referees
CREATE TABLE IF NOT EXISTS referees (
                                        uuid CHAR(36) PRIMARY KEY,
                                        referee_id VARCHAR(50) UNIQUE,
                                        first_name VARCHAR(100),
                                        last_name VARCHAR(100),
                                        email VARCHAR(100),
                                        phone VARCHAR(50),
                                        home_club_id CHAR(36),
                                        home_location_city VARCHAR(100),
                                        grade VARCHAR(50),
                                        FOREIGN KEY (home_club_id) REFERENCES clubs(uuid)
);
-- Matches
CREATE TABLE matches (
                         uuid CHAR(36) PRIMARY KEY,
                         home_team_id CHAR(36),
                         away_team_id CHAR(36),
                         location_lat DECIMAL(10, 7),
                         location_lon DECIMAL(10, 7),
                         location_address VARCHAR(255),
                         division VARCHAR(100),
                         expected_grade VARCHAR(50),
                         match_date DATE,
                         kickoff_time TIME,
                         referee_id CHAR(36),
                         ar1_id CHAR(36),
                         ar2_id CHAR(36),
                         commissioner_id CHAR(36),
                         FOREIGN KEY (home_team_id) REFERENCES teams(uuid),
                         FOREIGN KEY (away_team_id) REFERENCES teams(uuid),
                         FOREIGN KEY (referee_id) REFERENCES referees(uuid),
                         FOREIGN KEY (ar1_id) REFERENCES referees(uuid),
                         FOREIGN KEY (ar2_id) REFERENCES referees(uuid),
                         FOREIGN KEY (commissioner_id) REFERENCES referees(uuid)
);


-- Referee Travel Log
CREATE TABLE IF NOT EXISTS referee_travel_log (
                                                  uuid CHAR(36) PRIMARY KEY,
                                                  referee_id CHAR(36),
                                                  match_id CHAR(36),
                                                  distance_km DECIMAL(10, 2),
                                                  FOREIGN KEY (referee_id) REFERENCES referees(uuid),
                                                  FOREIGN KEY (match_id) REFERENCES matches(uuid)
);

-- Referee Team Count (exposure tracker)
CREATE TABLE IF NOT EXISTS referee_team_count (
                                                  uuid CHAR(36) PRIMARY KEY,
                                                  referee_id CHAR(36),
                                                  team_id CHAR(36),
                                                  club_id CHAR(36),
                                                  count INT,
                                                  last_assigned_date DATETIME,
                                                  FOREIGN KEY (referee_id) REFERENCES referees(uuid),
                                                  FOREIGN KEY (team_id) REFERENCES teams(uuid),
                                                  FOREIGN KEY (club_id) REFERENCES clubs(uuid)
);
