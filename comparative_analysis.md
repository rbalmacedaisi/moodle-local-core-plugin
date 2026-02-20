# Comparative Analysis: Projection Logic (Wave Demand)

This document analyzes the differences in how student demand is projected for future periods (P-II to P-VI) between the standalone `Proyecto_Horarios` and the Moodle-integrated `grupomakro_core`.

## The Issue
Users are reporting **duplicated counts** in consecutive periods (e.g., P-II and P-III showing the exact same population for the same level).

## Logic Comparison

| Feature | Proyecto_Horarios (Original) | grupomakro_core (Moodle Version) |
| :--- | :--- | :--- |
| **Unit of Progress** | **Full Level** (1, 2, 3...) | **Half Level** (Bimestres: I, II) |
| **State Jump** | 1 Level per period | 0.5 Levels per period |
| **Subject Filtering** | Filter by `lvl - pIdx` | Filter by `currentLevel` (Advanced by toggle) |
| **Observation** | A student enters Level 2 in P-II and Level 3 in P-III. | A student stays in Level 2 for **two periods** (Bimestre I and II). |

### Why Duplicates Happen in Moodle
In the current Moodle implementation, the "Wave" advances students by Bimestres. 
1. **P-II**: Cohort moves from Level 1.5 to **Level 2.0** (Bimestre I). Demand for **all** Level 2 subjects.
2. **P-III**: Cohort moves from Level 2.0 to **Level 2.5** (Bimestre II). Demand for **all** Level 2 subjects.

Because many subjects are simply tagged as "Level II" (Roman) and not explicitly split into "Level II - Bim I" or "Level II - Bim II", the filtering logic finds the SAME subjects for both periods, resulting in identical demand values.

## Proposed Resolution

### Option A: Parity Alignment (Recommended)
Align the Moodle Wave to match `Proyecto_Horarios` by progressing exactly **one full level per period** for the purpose of the general matrix. This avoids "stays" in the same level and ensures each student hits each subject exactly once.

### Option B: Bimestre Separation
Keep the half-step progress but refine the subject filtering to only count subjects if their "Bimestre" matches the cohort's "Bimestre". This requires reliable metadata in the Learning Plan structure (distinguishing between P1 and P2 of each level).

> [!IMPORTANT]
> Since the majority of current plans in the database aggregate both bimestres under a single Roman Level, **Option A** is the most reliable way to provide clean, non-confusing counts immediately.
