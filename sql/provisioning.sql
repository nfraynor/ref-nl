-- Recommended defaults (optional but nice)
-- SET NAMES utf8mb4;
-- SET FOREIGN_KEY_CHECKS = 1;

-- Divisions
CREATE TABLE IF NOT EXISTS divisions (
                                         id   INT AUTO_INCREMENT PRIMARY KEY,
                                         name VARCHAR(255) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Districts
CREATE TABLE IF NOT EXISTS districts (
                                         id   INT AUTO_INCREMENT PRIMARY KEY,
                                         name VARCHAR(255) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Division ↔ District mapping
CREATE TABLE IF NOT EXISTS division_districts (
                                                  division_id INT NOT NULL,
                                                  district_id INT NOT NULL,
                                                  PRIMARY KEY (division_id, district_id),
                                                  FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE,
                                                  FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Locations FIRST (so clubs can reference it)
CREATE TABLE IF NOT EXISTS locations (
                                         uuid         CHAR(36) PRIMARY KEY,
                                         name         VARCHAR(255),
                                         address_text VARCHAR(255) NULL,           -- NULL allowed (UI lets address be optional)
                                         latitude     DECIMAL(10,8) NULL,          -- NULL allowed
                                         longitude    DECIMAL(11,8) NULL,          -- NULL allowed
                                         notes        TEXT,
                                         created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                         updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clubs
CREATE TABLE IF NOT EXISTS clubs (
                                     uuid                   CHAR(36) PRIMARY KEY,
                                     club_id                VARCHAR(50) UNIQUE,
                                     club_number            INT NOT NULL AUTO_INCREMENT UNIQUE,
                                     club_name              VARCHAR(255) NOT NULL,
                                     location_uuid          CHAR(36) NULL,
                                     primary_contact_name   VARCHAR(255),
                                     primary_contact_email  VARCHAR(255),
                                     primary_contact_phone  VARCHAR(50),
                                     website_url            VARCHAR(255),
                                     notes                  TEXT,
                                     active                 BOOLEAN NOT NULL DEFAULT 1,
                                     INDEX idx_clubs_location (location_uuid),
                                     CONSTRAINT fk_clubs_location
                                         FOREIGN KEY (location_uuid) REFERENCES locations(uuid)
                                             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Teams (now includes district_id + active; indexes defined here)
CREATE TABLE IF NOT EXISTS teams (
                                     uuid        CHAR(36) PRIMARY KEY,
                                     team_name   VARCHAR(100) NOT NULL,
                                     club_id     CHAR(36),
                                     division    VARCHAR(100),
                                     district_id INT NULL,
                                     active      BOOLEAN NOT NULL DEFAULT TRUE,
                                     INDEX idx_teams_club     (club_id),
                                     INDEX idx_teams_district (district_id),
                                     INDEX idx_teams_active   (active),
                                     CONSTRAINT fk_teams_club     FOREIGN KEY (club_id)     REFERENCES clubs(uuid),
                                     CONSTRAINT fk_teams_district FOREIGN KEY (district_id) REFERENCES districts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Referees
-- Referees
CREATE TABLE IF NOT EXISTS referees (
    uuid                      CHAR(36) PRIMARY KEY,
    ref_number                INT NOT NULL AUTO_INCREMENT UNIQUE,
    referee_id                VARCHAR(50) UNIQUE,
    first_name                VARCHAR(100),
    last_name                 VARCHAR(100),
    email                     VARCHAR(100),
    phone                     VARCHAR(50),
    home_club_id              CHAR(36),
    home_location_city        VARCHAR(100),
    grade                     VARCHAR(50),
    ar_grade                  VARCHAR(50),
    home_lat                  DECIMAL(10,8) DEFAULT NULL,
    home_lon                  DECIMAL(11,8) DEFAULT NULL,
    max_travel_distance       INT,
    district_id               INT,
    max_matches_per_weekend   INT NOT NULL DEFAULT 1,
    max_days_per_weekend      INT NOT NULL DEFAULT 1,

    FOREIGN KEY (home_club_id) REFERENCES clubs(uuid),
    FOREIGN KEY (district_id)  REFERENCES districts(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Referee exempt clubs
CREATE TABLE IF NOT EXISTS referee_exempt_clubs (
                                                    referee_uuid CHAR(36) NOT NULL,
                                                    club_uuid    CHAR(36) NOT NULL,
                                                    PRIMARY KEY (referee_uuid, club_uuid),
                                                    FOREIGN KEY (referee_uuid) REFERENCES referees(uuid) ON DELETE CASCADE,
                                                    FOREIGN KEY (club_uuid)    REFERENCES clubs(uuid)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weekly availability
CREATE TABLE IF NOT EXISTS referee_weekly_availability (
                                                           uuid                CHAR(36) PRIMARY KEY,
                                                           referee_id          CHAR(36) NOT NULL,
                                                           weekday             SMALLINT NOT NULL, -- 0=Sunday .. 6=Saturday
                                                           morning_available   BOOLEAN DEFAULT TRUE,
                                                           afternoon_available BOOLEAN DEFAULT TRUE,
                                                           evening_available   BOOLEAN DEFAULT TRUE,
                                                           created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                                           FOREIGN KEY (referee_id) REFERENCES referees(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ad-hoc unavailability
CREATE TABLE IF NOT EXISTS referee_unavailability (
                                                      uuid        CHAR(36) PRIMARY KEY,
                                                      referee_id  CHAR(36) NOT NULL,
                                                      start_date  DATE NOT NULL,
                                                      end_date    DATE NOT NULL,
                                                      reason      TEXT,
                                                      created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                                      created_by  CHAR(36),
                                                      FOREIGN KEY (referee_id) REFERENCES referees(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users
CREATE TABLE IF NOT EXISTS users (
                                     uuid         CHAR(36) PRIMARY KEY,
                                     username     VARCHAR(255) UNIQUE NOT NULL,
                                     password_hash VARCHAR(255) NOT NULL,
                                     role         VARCHAR(50) DEFAULT NULL,
                                     created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                     updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User permissions scoped by division/district
CREATE TABLE IF NOT EXISTS user_permissions (
                                                user_id     CHAR(36) NOT NULL,
                                                division_id INT,
                                                district_id INT,
                                                PRIMARY KEY (user_id, division_id, district_id),
                                                FOREIGN KEY (user_id)     REFERENCES users(uuid)     ON DELETE CASCADE,
                                                FOREIGN KEY (division_id) REFERENCES divisions(id)   ON DELETE CASCADE,
                                                FOREIGN KEY (district_id) REFERENCES districts(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Matches (no location_uuid; optional per-match address/coords)
CREATE TABLE IF NOT EXISTS matches (
                                       uuid                 CHAR(36) PRIMARY KEY,
                                       home_team_id         CHAR(36),
                                       away_team_id         CHAR(36),
                                       location_lat         DECIMAL(10,7) NULL,
                                       location_lon         DECIMAL(11,7) NULL,
                                       location_address     VARCHAR(255) NULL,
                                       division             VARCHAR(100),
                                       expected_grade       VARCHAR(50),
                                       match_date           DATE,
                                       kickoff_time         TIME,
                                       referee_id           CHAR(36),
                                       ar1_id               CHAR(36),
                                       ar2_id               CHAR(36),
                                       commissioner_id      CHAR(36),
                                       district             VARCHAR(100),
                                       poule                VARCHAR(100),
                                       referee_assigner_uuid CHAR(36),
                                       FOREIGN KEY (home_team_id)        REFERENCES teams(uuid),
                                       FOREIGN KEY (away_team_id)        REFERENCES teams(uuid),
                                       FOREIGN KEY (referee_id)          REFERENCES referees(uuid),
                                       FOREIGN KEY (ar1_id)              REFERENCES referees(uuid),
                                       FOREIGN KEY (ar2_id)              REFERENCES referees(uuid),
                                       FOREIGN KEY (commissioner_id)     REFERENCES referees(uuid),
                                       FOREIGN KEY (referee_assigner_uuid) REFERENCES users(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Travel log
CREATE TABLE IF NOT EXISTS referee_travel_log (
                                                  uuid        CHAR(36) PRIMARY KEY,
                                                  referee_id  CHAR(36),
                                                  match_id    CHAR(36),
                                                  distance_km DECIMAL(10,2),
                                                  FOREIGN KEY (referee_id) REFERENCES referees(uuid),
                                                  FOREIGN KEY (match_id)   REFERENCES matches(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exposure tracker
CREATE TABLE IF NOT EXISTS referee_team_count (
                                                  uuid               CHAR(36) PRIMARY KEY,
                                                  referee_id         CHAR(36),
                                                  team_id            CHAR(36),
                                                  club_id            CHAR(36),
                                                  count              INT,
                                                  last_assigned_date DATETIME,
                                                  FOREIGN KEY (referee_id) REFERENCES referees(uuid),
                                                  FOREIGN KEY (team_id)    REFERENCES teams(uuid),
                                                  FOREIGN KEY (club_id)    REFERENCES clubs(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
