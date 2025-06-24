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

-- Ref Availability
CREATE TABLE IF NOT EXISTS referee_weekly_availability (
                                                           uuid CHAR(36) PRIMARY KEY,
                                                           referee_id CHAR(36) NOT NULL,
                                                           weekday SMALLINT NOT NULL, -- 0 = Sunday, 6 = Saturday
                                                           morning_available BOOLEAN DEFAULT FALSE,
                                                           afternoon_available BOOLEAN DEFAULT FALSE,
                                                           evening_available BOOLEAN DEFAULT FALSE,
                                                           created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                                                           FOREIGN KEY (referee_id) REFERENCES referees(uuid)
);

CREATE TABLE IF NOT EXISTS referee_unavailability (
                                                      uuid CHAR(36) PRIMARY KEY,
                                                      referee_id CHAR(36) NOT NULL,
                                                      start_date DATE NOT NULL,
                                                      end_date DATE NOT NULL,
                                                      reason TEXT,
                                                      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                                      created_by CHAR(36), -- optionally track who blocked it

                                                      FOREIGN KEY (referee_id) REFERENCES referees(uuid)
);

-- Matches
CREATE TABLE IF NOT EXISTS matches (
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
                                       district VARCHAR(100),        -- New column
                                       poule VARCHAR(100),           -- New column
                                       referee_assigner_uuid CHAR(36), -- New column for referee assigner
                                       FOREIGN KEY (home_team_id) REFERENCES teams(uuid),
                                       FOREIGN KEY (away_team_id) REFERENCES teams(uuid),
                                       FOREIGN KEY (referee_id) REFERENCES referees(uuid),
                                       FOREIGN KEY (ar1_id) REFERENCES referees(uuid),
                                       FOREIGN KEY (ar2_id) REFERENCES referees(uuid),
                                       FOREIGN KEY (commissioner_id) REFERENCES referees(uuid),
                                       FOREIGN KEY (referee_assigner_uuid) REFERENCES users(uuid) -- Foreign key constraint
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

-- Users
CREATE TABLE IF NOT EXISTS users (
                                     uuid CHAR(36) PRIMARY KEY,
                                     username VARCHAR(255) UNIQUE NOT NULL,
                                     password_hash VARCHAR(255) NOT NULL,
                                     role VARCHAR(50) NOT NULL,
                                     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Locations Table
CREATE TABLE IF NOT EXISTS locations (
    uuid CHAR(36) PRIMARY KEY,
    name VARCHAR(255),
    address_text VARCHAR(255) NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Modify matches table to include location_uuid foreign key
ALTER TABLE matches ADD COLUMN location_uuid CHAR(36) NULL;
ALTER TABLE matches ADD CONSTRAINT fk_match_location FOREIGN KEY (location_uuid) REFERENCES locations(uuid);
