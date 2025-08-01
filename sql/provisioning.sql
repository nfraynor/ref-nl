-- Divisions Table
CREATE TABLE IF NOT EXISTS divisions (
                                         id INT AUTO_INCREMENT PRIMARY KEY,
                                         name VARCHAR(255) UNIQUE NOT NULL
);

-- Districts Table
CREATE TABLE IF NOT EXISTS districts (
                                         id INT AUTO_INCREMENT PRIMARY KEY,
                                         name VARCHAR(255) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS division_districts (
                                                  division_id INT NOT NULL,
                                                  district_id INT NOT NULL,
                                                  PRIMARY KEY (division_id, district_id),
                                                  FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE,
                                                  FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE
);

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
                                        ar_grade VARCHAR(50),
                                        home_lat DECIMAL(10, 8) DEFAULT NULL,
                                        home_lon DECIMAL(11, 8) DEFAULT NULL,
                                        max_travel_distance INT,
                                        district_id INT,
                                        FOREIGN KEY (home_club_id) REFERENCES clubs(uuid),
                                        FOREIGN KEY (district_id) REFERENCES districts(id)
);

-- Referee Exempt Clubs Table
CREATE TABLE IF NOT EXISTS referee_exempt_clubs (
                                                    referee_uuid CHAR(36) NOT NULL,
                                                    club_uuid CHAR(36) NOT NULL,
                                                    PRIMARY KEY (referee_uuid, club_uuid),
                                                    FOREIGN KEY (referee_uuid) REFERENCES referees(uuid) ON DELETE CASCADE,
                                                    FOREIGN KEY (club_uuid) REFERENCES clubs(uuid) ON DELETE CASCADE
);

-- Ref Availability
CREATE TABLE IF NOT EXISTS referee_weekly_availability (
                                                           uuid CHAR(36) PRIMARY KEY,
                                                           referee_id CHAR(36) NOT NULL,
                                                           weekday SMALLINT NOT NULL, -- 0 = Sunday, 6 = Saturday
                                                           morning_available BOOLEAN DEFAULT TRUE,
                                                           afternoon_available BOOLEAN DEFAULT TRUE,
                                                           evening_available BOOLEAN DEFAULT TRUE,
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
                                                      created_by CHAR(36),
                                                      FOREIGN KEY (referee_id) REFERENCES referees(uuid)
);

-- Users Table
CREATE TABLE IF NOT EXISTS users (
                                     uuid CHAR(36) PRIMARY KEY,
                                     username VARCHAR(255) UNIQUE NOT NULL,
                                     password_hash VARCHAR(255) NOT NULL,
                                     role VARCHAR(50) DEFAULT NULL,
                                     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- User Permissions Table
CREATE TABLE IF NOT EXISTS user_permissions (
                                                user_id CHAR(36) NOT NULL,
                                                division_id INT,
                                                district_id INT,
                                                PRIMARY KEY (user_id, division_id, district_id),
                                                FOREIGN KEY (user_id) REFERENCES users(uuid) ON DELETE CASCADE,
                                                FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE,
                                                FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE
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
                                       district VARCHAR(100),
                                       poule VARCHAR(100),
                                       referee_assigner_uuid CHAR(36),
                                       FOREIGN KEY (home_team_id) REFERENCES teams(uuid),
                                       FOREIGN KEY (away_team_id) REFERENCES teams(uuid),
                                       FOREIGN KEY (referee_id) REFERENCES referees(uuid),
                                       FOREIGN KEY (ar1_id) REFERENCES referees(uuid),
                                       FOREIGN KEY (ar2_id) REFERENCES referees(uuid),
                                       FOREIGN KEY (commissioner_id) REFERENCES referees(uuid),
                                       FOREIGN KEY (referee_assigner_uuid) REFERENCES users(uuid)
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

-- Add location_uuid column to matches if it doesn't exist
SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'matches'
    AND COLUMN_NAME = 'location_uuid'
    AND TABLE_SCHEMA = DATABASE()
    );

SET @sql := IF(@column_exists = 0,
    'ALTER TABLE matches ADD COLUMN location_uuid CHAR(36) NULL;',
    'SELECT "Column location_uuid already exists" AS info;'
    );
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add constraint fk_match_location if it doesn't exist
SET @constraint_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_NAME = 'fk_match_location'
    AND TABLE_NAME = 'matches'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_SCHEMA = DATABASE()
    );

SET @sql2 := IF(@constraint_exists = 0,
    'ALTER TABLE matches ADD CONSTRAINT fk_match_location FOREIGN KEY (location_uuid) REFERENCES locations(uuid);',
    'SELECT "Constraint fk_match_location already exists" AS info;'
    );
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

ALTER TABLE referees ADD COLUMN IF NOT EXISTS max_days_per_weekend TINYINT DEFAULT NULL CHECK (max_days_per_weekend IN (1, 2));
ALTER TABLE referees ADD COLUMN IF NOT EXISTS max_matches_per_weekend TINYINT DEFAULT NULL CHECK (max_matches_per_weekend IN (1));