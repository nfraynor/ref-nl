# Assignment Logic Rules (Phase 1)

This document outlines the smart assignment logic for referee scheduling. These rules are designed to ensure fair and logical assignment of referees to matches based on grade, workload, and other constraints.

---

## Rule 1: Referee Grade vs Division Priority

* Higher division matches require higher grade referees.
* Lower divisions can be assigned lower grade referees.
* Referees should be sorted by grade (highest first) to prioritize best matches.

**Summary:**

* Sort referees by grade (descending).
* Assign higher grade referees to higher division matches first.

---

## Rule 2: Assign REFEREE Roles First

* Always assign the `REFEREE` role before assigning `AR1` and `AR2`.
* `MATCH_COMMISSIONER` is optional and should be left unassigned for now.

**Assignment process:**

* First pass: Assign all `REFEREE` roles.
* Second pass: Assign `AR1` and `AR2` roles.

---

## Rule 3: One Game Per Referee Before Second

* Referees should receive only one assignment until all have at least one.
* After every referee has a game, begin assigning second games if needed.

**Implementation:**

* Track number of assigned games per referee.
* Prioritize referees with zero games for assignments.

---

## Rule 4: Same Day, Same Location for Multiple Games

* Referees can only be assigned two games in one day if both games are at the same location.
* If a referee already has a game that day at a different location, leave the slot unassigned.

**Implementation:**

* Check assigned games for the referee on the same date.
* Allow second assignment only if the location matches.

---

## Rule 5: If No Suitable Referee, Leave Unassigned

* If no available referee fits the criteria, leave the slot unassigned.
* Do not randomly assign referees as a fallback. This allows manual override and ensures fairness.

---

**Summary of Assignment Flow:**

1. Sort referees by grade (high to low).
2. Assign `REFEREE` roles first across all matches, with best grade to highest division first. 
3. Assign `AR1` and `AR2` roles only after all `REFEREE` slots are filled.
4. Assign ARs for highest division games first, then work down.
5. Ensure referees get one match before assigning a second.
6. For same-day matches, only assign a second if location matches.
7. If no suitable referee exists, leave the slot blank.
