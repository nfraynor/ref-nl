# Data Model Documentation

This document describes the primary data models used in the **Referee Management System**. These models define referees, clubs, teams, matches, and assignments. Each model is designed to support scheduling, reporting, and organizational features while ensuring privacy where appropriate.

---

## Referee

**Represents an individual referee.**

| Field                | Type           | Notes                                                                       |
| -------------------- | -------------- | --------------------------------------------------------------------------- |
| `uuid`               | UUID (PK)      | Primary key. Unique identifier for the referee.                             |
| `referee_id`         | VARCHAR        | Human-friendly referee ID (e.g. `REF123`) for external reference.           |
| `first_name`         | VARCHAR        | Referee’s first name.                                                       |
| `last_name`          | VARCHAR        | Referee’s last name.                                                        |
| `email`              | VARCHAR        | Referee’s contact email.                                                    |
| `phone`              | VARCHAR        | Referee’s contact phone number.                                             |
| `home_club_id`       | UUID (FK)      | Foreign key. References `Club.uuid`. Indicates the referee’s home club.     |
| `home_location_city` | VARCHAR        | City of residence. Used only at the city level for privacy reasons.         |
| `grade`              | VARCHAR or INT | Referee’s current grade/level. Stored as text or numeric based on use case. |

---

## Club

**Represents a sports club.**

| Field                  | Type          | Notes                                                |
| ---------------------- | ------------- | ---------------------------------------------------- |
| `uuid`                 | UUID (PK)     | Primary key. Unique identifier for the club.         |
| `club_id`              | VARCHAR       | Human-friendly ID for the club.                      |
| `club_name`            | VARCHAR       | Name of the club.                                    |
| `precise_location_lat` | FLOAT/DECIMAL | Precise latitude coordinate of the club’s location.  |
| `precise_location_lon` | FLOAT/DECIMAL | Precise longitude coordinate of the club’s location. |
| `address_text`         | VARCHAR       | Full textual address of the club.                    |

---

## Team

**Represents a team within a club.**

| Field       | Type      | Notes                                                          |
| ----------- | --------- | -------------------------------------------------------------- |
| `uuid`      | UUID (PK) | Primary key. Unique identifier for the team.                   |
| `team_name` | VARCHAR   | Name of the team (e.g. `1st XV`, `U16`).                       |
| `club_id`   | UUID (FK) | Foreign key. References `Club.uuid`. Links the team to a club. |
| `division`  | VARCHAR   | Division or competition level of the team (e.g. `Division 2`). |

---

## Match

**Represents a scheduled match between two teams.**

| Field              | Type           | Notes                                                      |
| ------------------ | -------------- | ---------------------------------------------------------- |
| `uuid`             | UUID (PK)      | Primary key. Unique identifier for the match.              |
| `home_team_id`     | UUID (FK)      | Foreign key. References `Team.uuid`.                       |
| `away_team_id`     | UUID (FK)      | Foreign key. References `Team.uuid`.                       |
| `location_lat`     | FLOAT/DECIMAL  | Latitude coordinate of the match location.                 |
| `location_lon`     | FLOAT/DECIMAL  | Longitude coordinate of the match location.                |
| `location_address` | VARCHAR        | Textual description or full address of the match location. |
| `division`         | VARCHAR        | Division name or code for the match.                       |
| `expected_grade`   | VARCHAR or INT | Minimum referee grade required for the match.              |

---

## Assignment

**Represents the assignment of a referee to a match.**

| Field         | Type                 | Notes                                                                      |
| ------------- | -------------------- | -------------------------------------------------------------------------- |
| `uuid`        | UUID (PK)            | Primary key. Unique identifier for the assignment.                         |
| `match_id`    | UUID (FK -> Match)   | Foreign key. References `Match.uuid`.                                      |
| `referee_id`  | UUID (FK -> Referee) | Foreign key. References `Referee.uuid`.                                    |
| `role`        | ENUM or VARCHAR      | Referee role in the match (`REFEREE`, `AR1`, `AR2`, `MATCH_COMMISSIONER`). |
| `proposed`    | BOOLEAN              | True if this is a proposed assignment, false if confirmed.                 |
| `assigned_on` | DATETIME             | Timestamp when the assignment was confirmed.                               |

---

## Referee Team Count

**Tracks how often a referee has officiated a specific team.**

| Field                | Type              | Notes                                                               |
| -------------------- | ----------------- | ------------------------------------------------------------------- |
| `uuid`               | UUID (PK)         | Primary key. Unique identifier for the record.                      |
| `referee_id`         | UUID (FK)         | Foreign key. References `Referee.uuid`.                             |
| `club_id`            | UUID (FK)         | Foreign key. References `Club.uuid`. The club of the team refereed. |
| `team_id`            | UUID (FK -> Team) | Foreign key. References `Team.uuid`.                                |
| `count`              | INT               | How many times the referee has officiated this team.                |
| `last_assigned_date` | DATETIME          | The last time the referee officiated this team.                     |

---

## Referee Travel Log

**Records the travel distance of referees for matches.**

| Field         | Type      | Notes                                                    |
| ------------- | --------- | -------------------------------------------------------- |
| `uuid`        | UUID (PK) | Primary key. Unique identifier for the travel log entry. |
| `referee_id`  | UUID (FK) | Foreign key. References `Referee.uuid`.                  |
| `match_id`    | UUID (FK) | Foreign key. References `Match.uuid`.                    |
| `distance_km` | DECIMAL   | Distance traveled in kilometers.                         |

---

## Notes

* **Privacy**: Personal information (e.g. referee home locations) are stored at city-level granularity only to avoid exposing sensitive data.
* **Location Precision**: Clubs and matches use precise latitude and longitude for mapping and navigation purposes.
* **Human Friendly IDs**: `referee_id` and `club_id` offer readable identifiers for external users and printed materials.
