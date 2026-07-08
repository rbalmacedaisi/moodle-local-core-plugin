-- ================================================================
-- ROLLBACK: restaura gmk_planning_period_maps al estado previo a
-- los fixes de 2026-07-06 (incluyendo los self-mappings problemáticos)
-- ================================================================
-- EJECUTAR SOLO SI QUIERES DESHACER LOS CAMBIOS DE MAPS.
-- El codigo PHP actualizado sigue siendo valido con cualquier estado.

DELETE FROM isi_gmk_planning_period_maps WHERE id IN (9, 10);

INSERT INTO isi_gmk_planning_period_maps
  (id, base_period_id, relative_index, target_period_id, usermodified, timecreated, timemodified)
VALUES
  (1, 1, 0, 3, 2, 1771627427, 1772120392),
  (2, 3, 0, 3, 2, 1772120680, 1774104933),
  (3, 4, 0, 3, 2, 1775768359, 1782925157),
  (4, 4, 1, 4, 2, 1775768359, 1782925157),
  (5, 4, 2, 5, 2, 1782921411, 1782925157),
  (6, 5, 0, 5, 2, 1783017512, 1783349538);

UPDATE isi_gmk_planning_period_maps SET target_period_id = 3 WHERE id = 3;

SELECT 'Rollback completo: 6 registros restaurados' AS info;
SELECT id, base_period_id, relative_index, target_period_id FROM isi_gmk_planning_period_maps ORDER BY id;
