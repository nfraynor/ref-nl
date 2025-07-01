# Data Model Documentation

This document describes the primary data models used in the **Referee Management System**. These models define referees, clubs, teams, matches, and assignments. Each model is designed to support scheduling, reporting, and organizational features while ensuring privacy where appropriate.

---

## Referee

**Represents an individual referee.**

| Field                | Type             | Notes                                                                       |
| -------------------- | ---------------- | --------------------------------------------------------------------------- |
| `uuid`               | CHAR(36) (PK)    | Primary key. Unique identifier for the referee.                             |
| `referee_id`         | VARCHAR          | Human-friendly referee ID (e.g. `REF123`) for external reference.           |
| `first_name`         | VARCHAR          | Referee’s first name.                                                       |
| `last_name`          | VARCHAR          | Referee’s last name.                                                        |
| `email`              | VARCHAR          | Referee’s contact email.                                                    |
| `phone`              | VARCHAR          | Referee’s contact phone number.                                             |
| `home_club_id`       | CHAR(36) (FK)    | Foreign key. References `Club.uuid`. Indicates the referee’s home club.     |
| `home_location_city` | VARCHAR          | City of residence. Used only at the city level for privacy reasons.         |
| `grade`              | VARCHAR or INT   | Referee’s current grade/level. Stored as text or numeric based on use case. |

---

## Club

**Represents a sports club.**

| Field                  | Type             | Notes                                                |
| ---------------------- | ---------------- | ---------------------------------------------------- |
| `uuid`                 | CHAR(36) (PK)    | Primary key. Unique identifier for the club.         |
| `club_id`              | VARCHAR          | Human-friendly ID for the club.                      |
| `club_name`            | VARCHAR          | Name of the club.                                    |
| `precise_location_lat` | FLOAT/DECIMAL    | Precise latitude coordinate of the club’s location.  |
| `precise_location_lon` | FLOAT/DECIMAL    | Precise longitude coordinate of the club’s location. |
| `address_text`         | VARCHAR          | Full textual address of the club.                    |

---

## Team

**Represents a team within a club.**

| Field       | Type          | Notes                                                          |
| ----------- | ------------- | -------------------------------------------------------------- |
| `uuid`      | CHAR(36) (PK) | Primary key. Unique identifier for the team.                   |
| `team_name` | VARCHAR       | Name of the team (e.g. `1st XV`, `U16`).                       |
| `club_id`   | CHAR(36) (FK) | Foreign key. References `Club.uuid`. Links the team to a club. |
| `division`  | VARCHAR       | Division or competition level of the team (e.g. `Division 2`). |

---

## Locations

**Represents a physical location where matches can be played.** This table allows for standardized location entries, reducing redundancy and enabling better mapping.

| Field          | Type             | Notes                                                            |
| -------------- | ---------------- | ---------------------------------------------------------------- |
| `uuid`         | CHAR(36) (PK)    | Primary key. Unique identifier for the location.                 |
| `name`         | VARCHAR(255)     | Optional name for the location (e.g., "Main Pitch", "Clubhouse Field"). |
| `address_text` | VARCHAR(255)     | Full textual address of the location.                            |
| `latitude`     | DECIMAL(10, 8)   | Precise latitude coordinate.                                     |
| `longitude`    | DECIMAL(11, 8)   | Precise longitude coordinate.                                    |
| `notes`        | TEXT             | Any relevant notes about the location (e.g., parking, access).   |
| `created_at`   | TIMESTAMP        | Timestamp when the record was created.                           |
| `updated_at`   | TIMESTAMP        | Timestamp when the record was last updated.                      |

---

## Match

**Represents a scheduled match between two teams.**

| Field              | Type             | Notes                                                                      |
| ------------------ | ---------------- | -------------------------------------------------------------------------- |
| `uuid`             | CHAR(36) (PK)    | Primary key. Unique identifier for the match.                              |
| `home_team_id`     | CHAR(36) (FK)    | Foreign key. References `Team.uuid`.                                       |
| `away_team_id`     | CHAR(36) (FK)    | Foreign key. References `Team.uuid`.                                       |
| `location_lat`     | FLOAT/DECIMAL    | **Deprecated.** Latitude coordinate. Superseded by `location_uuid`.        |
| `location_lon`     | FLOAT/DECIMAL    | **Deprecated.** Longitude coordinate. Superseded by `location_uuid`.       |
| `location_address` | VARCHAR          | **Deprecated.** Textual address. Superseded by `location_uuid`.            |
| `location_uuid`    | CHAR(36) (FK)    | Foreign key. References `locations.uuid`. Points to the standardized location. |
| `division`         | VARCHAR          | Division name or code for the match.                                       |
| `expected_grade`   | VARCHAR or INT   | Minimum referee grade required for the match.                              |
| `referee_id`       | CHAR(36) (FK)    | Foreign key. References `Referee.uuid`. (If directly assigned)             |
| `ar1_id`           | CHAR(36) (FK)    | Foreign key. References `Referee.uuid`. (If directly assigned)             |
| `ar2_id`           | CHAR(36) (FK)    | Foreign key. References `Referee.uuid`. (If directly assigned)             |
| `commissioner_id`  | CHAR(36) (FK)    | Foreign key. References `Referee.uuid`. (If directly assigned)             |
| `match_date`       | DATE             | Date of the match.                                                         |
| `kickoff_time`     | TIME             | Kick-off time of the match.                                                |
| `district`         | VARCHAR          | District information for the match.                                        |
| `poule`            | VARCHAR          | Poule/group information for the match.                                     |


---

## Assignment

**Note:** The `Assignment` table is typically used if referee assignments are managed as separate entities from the `Match` record. If `matches.referee_id`, `matches.ar1_id` etc. are used, this table might be for a different purpose or future use. For this example, we assume direct assignment fields in `matches` table are primary. The `Assignment` table could be for tracking proposals or more complex assignment workflows.

| Field         | Type                 | Notes                                                                      |
| ------------- | -------------------- | -------------------------------------------------------------------------- |
| `uuid`        | CHAR(36) (PK)        | Primary key. Unique identifier for the assignment.                         |
| `match_id`    | CHAR(36) (FK)        | Foreign key. References `Match.uuid`.                                      |
| `referee_id`  | CHAR(36) (FK)        | Foreign key. References `Referee.uuid`.                                    |
| `role`        | ENUM or VARCHAR      | Referee role in the match (`REFEREE`, `AR1`, `AR2`, `MATCH_COMMISSIONER`). |
| `proposed`    | BOOLEAN              | True if this is a proposed assignment, false if confirmed.                 |
| `assigned_on` | DATETIME             | Timestamp when the assignment was confirmed.                               |

---

## Referee Team Count

**Tracks how often a referee has officiated a specific team.**

| Field                | Type          | Notes                                                               |
| -------------------- | ------------- | ------------------------------------------------------------------- |
| `uuid`               | CHAR(36) (PK) | Primary key. Unique identifier for the record.                      |
| `referee_id`         | CHAR(36) (FK) | Foreign key. References `Referee.uuid`.                             |
| `club_id`            | CHAR(36) (FK) | Foreign key. References `Club.uuid`. The club of the team refereed. |
| `team_id`            | CHAR(36) (FK) | Foreign key. References `Team.uuid`.                                |
| `count`              | INT           | How many times the referee has officiated this team.                |
| `last_assigned_date` | DATETIME      | The last time the referee officiated this team.                     |

---

## Referee Travel Log

**Records the travel distance of referees for matches.**

| Field         | Type          | Notes                                                    |
| ------------- | ------------- | -------------------------------------------------------- |
| `uuid`        | CHAR(36) (PK) | Primary key. Unique identifier for the travel log entry. |
| `referee_id`  | CHAR(36) (FK) | Foreign key. References `Referee.uuid`.                  |
| `match_id`    | CHAR(36) (FK) | Foreign key. References `Match.uuid`.                    |
| `distance_km` | DECIMAL       | Distance traveled in kilometers.                         |

---

## User

**Represents an application user account.**

| Field           | Type             | Notes                                                                 |
| --------------- | ---------------- | --------------------------------------------------------------------- |
| `uuid`          | CHAR(36) (PK)    | Primary key. Unique identifier for the user.                          |
| `username`      | VARCHAR(255)     | Unique username for login.                                            |
| `password_hash` | VARCHAR(255)     | Hashed password.                                                      |
| `role`          | VARCHAR(50)      | Global role (e.g., `super_admin`, `user_admin`). Specific permissions via `user_permissions`. |
| `created_at`    | TIMESTAMP        | Timestamp when the user was created.                                  |
| `updated_at`    | TIMESTAMP        | Timestamp when the user was last updated.                             |

---

## Division

**Represents a sports division.**

| Field | Type             | Notes                                        |
| ----- | ---------------- | -------------------------------------------- |
| `id`  | INT (PK)         | Primary key. Auto-incrementing identifier.   |
| `name`| VARCHAR(255)     | Unique name of the division (e.g., "Men's Division 1"). |

---

## District

**Represents a geographical or organizational district, typically within a division.**

| Field        | Type             | Notes                                                           |
| ------------ | ---------------- | --------------------------------------------------------------- |
| `id`         | INT (PK)         | Primary key. Auto-incrementing identifier.                      |
| `name`       | VARCHAR(255)     | Name of the district (e.g., "North District").                  |
| `division_id`| INT (FK)         | Foreign key. References `divisions.id`. Links district to a division. |
|              |                  | `UNIQUE (name, division_id)` ensures district name is unique within its division. |

---

## User Permission

**Links users to specific divisions and districts they have access to.**

| Field        | Type          | Notes                                                                    |
| ------------ | ------------- | ------------------------------------------------------------------------ |
| `user_id`    | CHAR(36) (FK) | Foreign key. References `users.uuid`. Part of composite primary key.     |
| `division_id`| INT (FK)      | Foreign key. References `divisions.id`. Part of composite primary key.   |
| `district_id`| INT (FK)      | Foreign key. References `districts.id`. Part of composite primary key.   |
|              |               | `PRIMARY KEY (user_id, division_id, district_id)`                        |

---

## Notes

* **Privacy**: Personal information (e.g. referee home locations) are stored at city-level granularity only to avoid exposing sensitive data.
* **Location Precision**: Clubs and now standardized `Locations` use precise latitude and longitude for mapping and navigation purposes.
* **Human Friendly IDs**: `referee_id` and `club_id` offer readable identifiers for external users and printed materials.
* **UUIDs**: Primary and Foreign Keys are CHAR(36) to store UUIDs.
* **Match Assignments**: Referee assignments are currently modeled as direct foreign keys in the `matches` table (`referee_id`, `ar1_id`, `ar2_id`, `commissioner_id`). The separate `Assignment` table might be used for future enhancements like proposed assignments or detailed logging.
