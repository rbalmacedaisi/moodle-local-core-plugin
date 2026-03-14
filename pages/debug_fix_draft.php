<?php
// Pgina de diagnstico y reparacin del draft de planificacin:
//   1. Detecta entradas duplicadas en draft_schedules y deduplica (conserva la ms reciente por id).
//   2. Detecta grupos Moodle hurfanos (sin gmk_class asociada) en todos los cursos
//      y permite eliminarlos junto con sus secciones y actividades relacionadas.
//   3. Detecta secciones de curso hurfanas (con actividades) sin gmk_class apuntando a ellas.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_fix_draft.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: Fix Draft & Grupos Hurfanos');
$PAGE->set_heading('Debug: Fix Draft & Grupos Hurfanos');

$action   = optional_param('action',   '', PARAM_ALPHA);
$periodid = optional_param('periodid', 0,  PARAM_INT);

/**
 * Busca recursivamente una condicion de disponibilidad de tipo group.
 *
 * @param mixed $node
 * @param int $groupid
 * @return bool
 */
function gmk_debug_fix_availability_node_has_group($node, $groupid) {
    if (!is_array($node)) {
        return false;
    }

    if (($node['type'] ?? '') === 'group' && (int)($node['id'] ?? 0) === (int)$groupid) {
        return true;
    }

    foreach ($node as $child) {
        if (is_array($child) && gmk_debug_fix_availability_node_has_group($child, $groupid)) {
            return true;
        }
    }

    return false;
}

/**
 * Verifica si el JSON de availability de una seccion referencia al grupo dado.
 *
 * @param string|null $availability
 * @param int $groupid
 * @return bool
 */
function gmk_debug_fix_availability_has_group($availability, $groupid) {
    if (empty($availability) || !is_string($availability)) {
        return false;
    }

    $decoded = json_decode($availability, true);
    if (!is_array($decoded)) {
        return false;
    }

    return gmk_debug_fix_availability_node_has_group($decoded, (int)$groupid);
}

// ?"??"? AJAX: Deduplica el draft del perodo ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
if ($action === 'fixdraft') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    if (!$periodid) {
        echo json_encode(['ok' => false, 'msg' => 'periodid requerido.']);
        exit;
    }

    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
    if (!$period || empty($period->draft_schedules)) {
        echo json_encode(['ok' => false, 'msg' => 'Perodo no encontrado o draft vaco.']);
        exit;
    }

    $draft = json_decode($period->draft_schedules, true);
    if (!is_array($draft)) {
        echo json_encode(['ok' => false, 'msg' => 'Draft no es un array JSON vlido.']);
        exit;
    }

    $byKey   = [];
    $removed = 0;
    foreach ($draft as $entry) {
        $key = ($entry['corecourseid'] ?? '') . '|' . ($entry['shift'] ?? '') . '|' . ($entry['day'] ?? '');
        if (!isset($byKey[$key])) {
            $byKey[$key] = $entry;
        } else {
            $currentId  = (int)($byKey[$key]['id'] ?? 0);
            $incomingId = (int)($entry['id'] ?? 0);
            if ($incomingId > $currentId) {
                $byKey[$key] = $entry;
            }
            $removed++;
        }
    }

    $newDraft = array_values($byKey);
    $DB->set_field('gmk_academic_periods', 'draft_schedules', json_encode($newDraft), ['id' => $periodid]);

    echo json_encode([
        'ok'      => true,
        'msg'     => "Draft reparado: $removed entradas duplicadas eliminadas. Quedaron " . count($newDraft) . " clases nicas.",
        'removed' => $removed,
        'kept'    => count($newDraft),
    ]);
    exit;
}

// ?"??"? AJAX: Elimina una seccin hurfana (con todas sus actividades) ?"??"??"??"??"??"??"??"??"??"??"??"?
if ($action === 'deletesection') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    require_once($CFG->dirroot . '/course/lib.php');

    $sectionid     = required_param('sectionid',     PARAM_INT);
    $courseid      = required_param('courseid',      PARAM_INT);
    $sectionnumber = required_param('sectionnumber', PARAM_INT);

    if ($DB->record_exists('gmk_class', ['coursesectionid' => $sectionid])) {
        echo json_encode(['ok' => false, 'msg' => "Seccin $sectionid tiene gmk_class activa; no se elimina."]);
        exit;
    }
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        echo json_encode(['ok' => false, 'msg' => "Curso $courseid no existe."]);
        exit;
    }
    try {
        course_delete_section($courseid, $sectionnumber, true, false);
        echo json_encode(['ok' => true, 'msg' => "Seccin id=$sectionid eliminada con todas sus actividades."]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => "ERROR: " . $e->getMessage()]);
    }
    exit;
}

// ?"??"? AJAX: Elimina un grupo hurfano (y su seccin si existe) ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
if ($action === 'deletegroup') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    require_once($CFG->dirroot . '/group/lib.php');
    require_once($CFG->dirroot . '/course/lib.php');

    $groupid  = required_param('groupid', PARAM_INT);
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $log      = [];

    $group = $DB->get_record('groups', ['id' => $groupid], 'id,courseid,name,idnumber');
    if (!$group) {
        echo json_encode(['ok' => false, 'msg' => "Grupo $groupid no encontrado (ya eliminado)."]);
        exit;
    }

    if ($DB->record_exists('gmk_class', ['groupid' => $groupid])) {
        echo json_encode(['ok' => false, 'msg' => "Grupo $groupid ahora tiene una gmk_class activa; no se elimina."]);
        exit;
    }

    try {
        if ($courseid > 0 && (int)$courseid !== (int)$group->courseid) {
            $log[] = "Aviso: courseid recibido ($courseid) no coincide con curso real del grupo ({$group->courseid}).";
        }

        $sections = $DB->get_records(
            'course_sections',
            ['course' => (int)$group->courseid],
            'section ASC',
            'id,section,name,availability'
        );
        $deletedsections = 0;
        $skippedsections = 0;

        foreach ($sections as $section) {
            if ((int)$section->section <= 0) {
                continue;
            }

            $matchesname = !empty($group->idnumber) && trim((string)$section->name) === trim((string)$group->idnumber);
            $matchesavailability = gmk_debug_fix_availability_has_group($section->availability, (int)$groupid);
            if (!$matchesname && !$matchesavailability) {
                continue;
            }

            if ($DB->record_exists('gmk_class', ['coursesectionid' => (int)$section->id])) {
                $skippedsections++;
                $log[] = "Seccion id={$section->id} omitida: tiene gmk_class activa.";
                continue;
            }

            try {
                course_delete_section((int)$group->courseid, (int)$section->section, true, false);
                $deletedsections++;
                $log[] = "Seccion id={$section->id} (num={$section->section}) eliminada con sus actividades.";
            } catch (Exception $se) {
                $skippedsections++;
                $log[] = "ERROR al eliminar seccion id={$section->id}: " . $se->getMessage();
            }
        }

        // Fallback: elimina actividades del curso que sigan vinculadas al grupo por disponibilidad.
        $modules = $DB->get_records(
            'course_modules',
            ['course' => (int)$group->courseid],
            'id ASC',
            'id,section,availability'
        );
        $deletedmodules = 0;
        $skippedmodules = 0;
        foreach ($modules as $cm) {
            if (!gmk_debug_fix_availability_has_group($cm->availability, (int)$groupid)) {
                continue;
            }

            // Defensa: no tocar modulos todavia referenciados por una clase activa.
            if ($DB->record_exists('gmk_class', ['attendancemoduleid' => (int)$cm->id])) {
                $skippedmodules++;
                $log[] = "Modulo cmid={$cm->id} omitido: es attendancemoduleid de gmk_class activa.";
                continue;
            }
            if (!empty($cm->section) && $DB->record_exists('gmk_class', ['coursesectionid' => (int)$cm->section])) {
                $skippedmodules++;
                $log[] = "Modulo cmid={$cm->id} omitido: su seccion ({$cm->section}) pertenece a gmk_class activa.";
                continue;
            }
            if ($DB->record_exists_sql(
                "SELECT 1
                   FROM {gmk_bbb_attendance_relation} r
                   JOIN {gmk_class} c ON c.id = r.classid
                  WHERE r.bbbmoduleid = :cmid
                  LIMIT 1",
                ['cmid' => (int)$cm->id]
            )) {
                $skippedmodules++;
                $log[] = "Modulo cmid={$cm->id} omitido: referenciado por gmk_bbb_attendance_relation activa.";
                continue;
            }

            try {
                course_delete_module((int)$cm->id);
                $deletedmodules++;
                $log[] = "Modulo cmid={$cm->id} eliminado por disponibilidad de grupo.";
            } catch (Exception $me) {
                $skippedmodules++;
                $log[] = "ERROR al eliminar modulo cmid={$cm->id}: " . $me->getMessage();
            }
        }

        groups_delete_group($groupid);
        $log[] = "Grupo $groupid ('{$group->name}') eliminado.";
        $log[] = "Resumen secciones: eliminadas=$deletedsections, omitidas/error=$skippedsections.";
        $log[] = "Resumen modulos: eliminados=$deletedmodules, omitidos/error=$skippedmodules.";

        echo json_encode(['ok' => true, 'msg' => implode('; ', $log)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => "ERROR: " . $e->getMessage()]);
    }
    exit;
}
// ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
echo $OUTPUT->header();

echo '<style>
  body { font-family: sans-serif; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 10px; }
  th { background: #1a73e8; color: white; }
  tr:nth-child(even) { background: #f9f9f9; }
  .ok   { color: green; font-weight: bold; }
  .err  { color: red; font-weight: bold; }
  .warn { color: orange; font-weight: bold; }
  .box { padding: 10px 14px; border-radius: 4px; margin: 8px 0; border: 1px solid; }
  .box.ok    { background:#dfd; border-color:green; }
  .box.err   { background:#fde; border-color:red; }
  .box.warn  { background:#fff3cd; border-color:#ffc107; }
  .box.info  { background:#e8f0fe; border-color:#1a73e8; }
  .section { margin: 22px 0 8px; font-size: 16px; font-weight: bold;
             border-bottom: 2px solid #1a73e8; padding-bottom: 4px; }
  .subsection { margin: 14px 0 6px; font-size: 14px; font-weight: bold; color: #555; }
  button, .btn { padding: 7px 18px; background:#1a73e8; color:white; border:none;
                 border-radius:3px; cursor:pointer; font-size:13px; display:inline-block;
                 text-decoration:none; }
  button:hover, .btn:hover { background:#1558b0; }
  .btn-danger { background:#c0392b; }
  .btn-danger:hover { background:#962d22; }
  .btn-sm { padding: 4px 12px; font-size: 12px; }
  select { padding: 7px 12px; border: 1px solid #ccc; border-radius:4px; font-size:14px; min-width:320px; }
  code { background:#f0f0f0; padding: 1px 5px; border-radius:3px; font-size:12px; }
  #progress-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
                      z-index:9999; align-items:center; justify-content:center; }
  #progress-box { background:#fff; border-radius:8px; padding:28px 32px; width:480px;
                  max-width:95vw; box-shadow:0 8px 32px rgba(0,0,0,.3); }
  #prog-bar-wrap { background:#e9ecef; border-radius:4px; height:18px; overflow:hidden; margin-bottom:8px; }
  #prog-bar { height:100%; background:#1a73e8; width:0%; transition:width .3s; }
  #prog-log { font-size:12px; line-height:1.9; max-height:200px; overflow-y:auto;
              border:1px solid #ddd; border-radius:4px; padding:8px 12px;
              background:#f8f9fa; margin-top:8px; font-family:monospace; }
  .row-log { font-size:12px; color:#555; font-family:monospace; margin-left:6px; }
</style>';

// ?"??"? Selector de perodo ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
$periods = $DB->get_records_sql(
    "SELECT id, name FROM {gmk_academic_periods}
      WHERE draft_schedules IS NOT NULL AND draft_schedules != ''
      ORDER BY id DESC"
);

echo "<div class='section'>1. Duplicados en el Draft de Planificacin</div>";

echo "<div style='margin:12px 0; display:flex; gap:10px; align-items:center;'>
  <select id='period-select'><option value=''>-- Selecciona un periodo --</option>";
foreach ($periods as $p) {
    $sel = ($p->id == $periodid) ? 'selected' : '';
    echo "<option value='{$p->id}' $sel>" . htmlspecialchars($p->name) . "</option>";
}
echo "  </select>
  <button class='btn' onclick='loadPeriod()'>Analizar</button>
</div>";

// ?"??"? Anlisis del draft ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
if ($periodid > 0) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);

    if (!$period || empty($period->draft_schedules)) {
        echo "<div class='box warn'>?s? El perodo no tiene draft guardado.</div>";
    } else {
        $draft = json_decode($period->draft_schedules, true);

        if (!is_array($draft)) {
            echo "<div class='box err'>?o~ El campo draft_schedules no contiene un JSON vlido.</div>";
        } else {
            $total  = count($draft);
            $byKey  = [];
            foreach ($draft as $idx => $entry) {
                $key = ($entry['corecourseid'] ?? '') . '|' . ($entry['shift'] ?? '') . '|' . ($entry['day'] ?? '');
                $byKey[$key][] = ['idx' => $idx, 'entry' => $entry];
            }

            $duplicateGroups = array_filter($byKey, function($g) { return count($g) > 1; });
            $dupCount        = count($duplicateGroups);
            $totalDuplicates = array_sum(array_map(function($g) { return count($g) - 1; }, $duplicateGroups));

            if ($dupCount === 0) {
                echo "<div class='box ok'>OK: El draft no tiene duplicados. Total: $total clases unicas.</div>";
            } else {
                echo "<div class='box warn'>Se encontraron <b>$dupCount claves duplicadas</b>
                ($totalDuplicates entradas extras de $total totales).</div>";

                echo "<table>
                <thead><tr>
                  <th>Clave (corecourseid|shift|day)</th>
                  <th>Copias</th>
                  <th>IDs en draft</th>
                  <th>Se conservar</th>
                </tr></thead><tbody>";

                foreach ($duplicateGroups as $key => $group) {
                    $ids    = array_map(function($g) { return isset($g['entry']['id']) ? $g['entry']['id'] : '(sin id)'; }, $group);
                    $maxId  = max(array_map(function($g) { return (int)(isset($g['entry']['id']) ? $g['entry']['id'] : 0); }, $group));
                    $keepId = $maxId ?: end($ids);
                    echo "<tr>
                      <td><code>" . htmlspecialchars($key) . "</code></td>
                      <td>" . count($group) . "</td>
                      <td><code>" . htmlspecialchars(implode(', ', $ids)) . "</code></td>
                      <td><code>id=$keepId</code></td>
                    </tr>";
                }
                echo "</tbody></table>";

                echo "<p>
                  <button class='btn-danger btn' onclick='fixDraft($periodid)'>
                    Reparar Draft (eliminar duplicados)
                  </button>
                </p>";
            }
        }
    }
}

// ?"??"? Cursos del plugin (base para ambas consultas) ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
$pluginCourseIds = $DB->get_fieldset_sql(
    "SELECT DISTINCT corecourseid FROM {gmk_class}
      WHERE corecourseid IS NOT NULL AND corecourseid > 0"
);

// ?"??"? Seccin 2: Grupos hurfanos ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
echo "<div class='section' style='margin-top:32px;'>2. Grupos Moodle Huerfanos</div>";

echo "<div class='box info'>
  Se listan <b>todos</b> los grupos de Moodle que no tienen ninguna fila en
  <code>gmk_class.groupid</code>. Puedes seleccionarlos por curso o masivamente,
  y eliminarlos junto con sus secciones/actividades relacionadas.
</div>";

$orphanedGroups = [];
$groupsQueryError = null;
try {
    $orphanedGroups = $DB->get_records_sql(
        "SELECT g.id AS groupid, g.name AS groupname, g.idnumber AS groupidnumber, g.courseid,
                c.fullname AS coursename, c.shortname AS courseshortname
           FROM {groups} g
           JOIN {course} c ON c.id = g.courseid
      LEFT JOIN {gmk_class} gc ON gc.groupid = g.id
          WHERE gc.id IS NULL
          ORDER BY c.fullname, g.name"
    );
} catch (Exception $e) {
    $groupsQueryError = $e->getMessage();
}

if ($groupsQueryError) {
    echo "<div class='box err'>Error al consultar grupos: <code>" . htmlspecialchars($groupsQueryError) . "</code></div>";
} elseif (empty($orphanedGroups)) {
    echo "<div class='box ok'>No se encontraron grupos huerfanos.</div>";
} else {
    $orphanCount = count($orphanedGroups);
    echo "<div class='box warn'>Se encontraron <b>$orphanCount grupo(s) huerfano(s)</b>.</div>";

    echo "<p style='display:flex;gap:10px;align-items:center;flex-wrap:wrap;'>
      <button class='btn-danger btn' onclick='deleteCheckedGroups()'>Eliminar seleccionados</button>
      <button class='btn' style='background:#6c757d' onclick='toggleAllGroups(true)'>Todos</button>
      <button class='btn' style='background:#6c757d' onclick='toggleAllGroups(false)'>Ninguno</button>
    </p>";

    $byCourse = [];
    foreach ($orphanedGroups as $og) {
        $byCourse[$og->courseid][] = $og;
    }

    foreach ($byCourse as $cid => $groups) {
        $first = $groups[0];
        echo "<div class='subsection'>Curso: " . htmlspecialchars($first->coursename) .
             " <small style='color:#888'>(" . htmlspecialchars($first->courseshortname) . ", id=$cid)</small></div>";

        echo "<table><thead><tr>
          <th style='width:32px'><input type='checkbox' checked onchange='toggleCourseGroups($cid, this.checked)' title='Seleccionar/deseleccionar curso'></th>
          <th>Group ID</th><th>Nombre del grupo</th><th>idnumber</th><th>Accion</th>
        </tr></thead><tbody>";

        foreach ($groups as $og) {
            $groupJson = json_encode(
                ['groupid' => (int)$og->groupid, 'courseid' => (int)$og->courseid],
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            );
            echo "<tr id='grow-{$og->groupid}'>
              <td><input type='checkbox' class='grp-chk' data-groupid='{$og->groupid}' data-courseid='{$og->courseid}' checked></td>
              <td>{$og->groupid}</td>
              <td>" . htmlspecialchars($og->groupname) . "</td>
              <td><code style='font-size:11px'>" . htmlspecialchars((string)$og->groupidnumber) . "</code></td>
              <td>
                <button class='btn btn-danger btn-sm' onclick='deleteOneGroup($groupJson, this)'>Eliminar</button>
                <span id='gstatus-{$og->groupid}' class='row-log'></span>
              </td>
            </tr>";
        }
        echo "</tbody></table>";
    }
}
// ?"??"? Seccin 3: Secciones de curso hurfanas ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
echo "<div class='section' style='margin-top:32px;'>3. Secciones de Curso Hurfanas (con actividades)</div>";

echo "<div class='box info'>
  Secciones (section &gt; 0, con nombre) que <b>tienen actividades</b> y
  <b>ninguna gmk_class</b> apunta a ellas (<code>coursesectionid</code>).
  Aparecen cuando el grupo fue eliminado pero la seccin con actividades qued sin limpiar
  (muestra grupo que falta en el curso).
</div>";

$orphanedSections = [];
$sectionsQueryError = null;
if (!empty($pluginCourseIds)) {
    try {
        list($inSql, $inParams) = $DB->get_in_or_equal($pluginCourseIds, SQL_PARAMS_NAMED, 'sc');
        $orphanedSections = $DB->get_records_sql(
            "SELECT cs.id AS sectionid, cs.name AS sectionname, cs.section AS sectionnumber,
                    cs.course AS courseid, c.fullname AS coursename, c.shortname AS courseshortname,
                    COUNT(cm.id) AS module_count
               FROM {course_sections} cs
               JOIN {course} c ON c.id = cs.course
          LEFT JOIN {course_modules} cm ON cm.section = cs.id
              WHERE cs.course $inSql
                AND cs.section > 0
                AND cs.name IS NOT NULL AND cs.name != ''
                AND NOT EXISTS (SELECT 1 FROM {gmk_class} WHERE coursesectionid = cs.id)
              GROUP BY cs.id, cs.name, cs.section, cs.course, c.fullname, c.shortname
             HAVING COUNT(cm.id) > 0
              ORDER BY c.fullname, cs.name",
            $inParams
        );
    } catch (Exception $e) {
        $sectionsQueryError = $e->getMessage();
    }
}

if ($sectionsQueryError) {
    echo "<div class='box err'>Error al consultar secciones: <code>" . htmlspecialchars($sectionsQueryError) . "</code></div>";
} elseif (empty($orphanedSections)) {
    echo "<div class='box ok'>No se encontraron secciones huerfanas con actividades.</div>";
} else {
    $secCount = count($orphanedSections);
    echo "<div class='box warn'>Se encontraron <b>$secCount seccion(es) huerfana(s) con actividades</b>.</div>";

    $byCourseS = [];
    foreach ($orphanedSections as $os) {
        $byCourseS[$os->courseid][] = $os;
    }

    foreach ($byCourseS as $cid => $sections) {
        $first = $sections[0];
        echo "<div class='subsection'>Curso: " . htmlspecialchars($first->coursename) .
             " <small style='color:#888'>(" . htmlspecialchars($first->courseshortname) . ", id=$cid)</small></div>";

        echo "<table><thead><tr>
          <th>Section ID</th><th>Nombre de la seccin</th><th>Actividades</th><th>Accin</th>
        </tr></thead><tbody>";

        foreach ($sections as $os) {
            $secJson = json_encode([
                'sectionid'     => (int)$os->sectionid,
                'courseid'      => (int)$os->courseid,
                'sectionnumber' => (int)$os->sectionnumber,
            ]);
            echo "<tr id='srow-{$os->sectionid}'>
              <td>{$os->sectionid}</td>
              <td>" . htmlspecialchars($os->sectionname) . "</td>
              <td>{$os->module_count}</td>
              <td>
                <button class='btn btn-danger btn-sm' onclick='deleteOneSection($secJson, this)'>Eliminar</button>
                <span id='sstatus-{$os->sectionid}' class='row-log'></span>
              </td>
            </tr>";
        }
        echo "</tbody></table>";
    }
}

// ?"??"? Overlay de progreso (usado por fixDraft) ?"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"??"?
echo "
<div id='progress-overlay'>
  <div id='progress-box'>
    <div style='font-size:16px;font-weight:bold;margin-bottom:14px;' id='prog-title'>Procesando...</div>
    <div id='prog-bar-wrap'><div id='prog-bar'></div></div>
    <div id='prog-log'></div>
    <div style='margin-top:16px;text-align:right;'>
      <button id='prog-reload' onclick='window.location.reload()' class='btn'
              style='display:none;background:#28a745;'>Listo - Recargar</button>
    </div>
  </div>
</div>";

$sesskey = sesskey();
echo "<script>
var SESS = '$sesskey';
var BASE = window.location.pathname;

function loadPeriod() {
    var pid = document.getElementById('period-select').value;
    if (!pid) return;
    window.location.href = BASE + '?periodid=' + pid;
}

function logLine(msg, ok) {
    var d = document.getElementById('prog-log');
    var line = document.createElement('div');
    line.style.color = ok ? '#2e7d32' : '#c62828';
    line.textContent = (ok ? '[OK] ' : '[ERR] ') + msg;
    d.appendChild(line);
    d.scrollTop = d.scrollHeight;
}

function showOverlay(title) {
    document.getElementById('prog-title').textContent = title;
    document.getElementById('prog-log').innerHTML = '';
    document.getElementById('prog-bar').style.width = '0%';
    document.getElementById('prog-bar').style.background = '#1a73e8';
    document.getElementById('prog-reload').style.display = 'none';
    document.getElementById('progress-overlay').style.display = 'flex';
}

function finishOverlay(ok) {
    document.getElementById('prog-bar').style.width = '100%';
    document.getElementById('prog-bar').style.background = ok ? '#28a745' : '#fd7e14';
    document.getElementById('prog-reload').style.display = 'inline-block';
}

async function fetchWithTimeout(url, timeoutMs) {
    var ctrl = new AbortController();
    var timer = setTimeout(function() { ctrl.abort(); }, timeoutMs);
    try {
        var resp = await fetch(url, { method: 'POST', signal: ctrl.signal });
        clearTimeout(timer);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return await resp.json();
    } catch(e) {
        clearTimeout(timer);
        if (e.name === 'AbortError') throw new Error('Timeout (' + (timeoutMs/1000) + 's)');
        throw e;
    }
}

async function fixDraft(periodid) {
    if (!confirm('Desduplicar el draft del perodo ' + periodid + '?\\nSe conservar el ID ms alto por cada clave nica.')) return;
    showOverlay('Reparando draft...');
    try {
        var data = await fetchWithTimeout(BASE + '?action=fixdraft&periodid=' + periodid + '&sesskey=' + SESS, 30000);
        logLine(data.msg, data.ok);
        finishOverlay(data.ok);
    } catch(e) {
        logLine('Error: ' + e.message, false);
        finishOverlay(false);
    }
}

async function deleteOneGroup(info, btn) {
    if (!confirm('Eliminar grupo ' + info.groupid + '?\\nTambien se eliminaran sus secciones y actividades relacionadas.')) return;
    btn.disabled = true;
    var statusEl = document.getElementById('gstatus-' + info.groupid);
    statusEl.textContent = ' Eliminando...';
    try {
        var data = await fetchWithTimeout(
            BASE + '?action=deletegroup&groupid=' + info.groupid + '&courseid=' + info.courseid + '&sesskey=' + SESS,
            45000
        );
        if (data.ok) {
            statusEl.textContent = ' [OK] ' + data.msg;
            statusEl.style.color = 'green';
            document.getElementById('grow-' + info.groupid).style.opacity = '0.4';
        } else {
            statusEl.textContent = ' [ERR] ' + data.msg;
            statusEl.style.color = 'red';
            btn.disabled = false;
        }
    } catch(e) {
        statusEl.textContent = ' [ERR] ' + e.message;
        statusEl.style.color = 'red';
        btn.disabled = false;
    }
}

function toggleAllGroups(checked) {
    document.querySelectorAll('.grp-chk').forEach(function(cb) {
        cb.checked = checked;
    });
}

function toggleCourseGroups(courseid, checked) {
    document.querySelectorAll('.grp-chk[data-courseid=\"' + courseid + '\"]').forEach(function(cb) {
        cb.checked = checked;
    });
}

async function deleteCheckedGroups() {
    var checked = Array.from(document.querySelectorAll('.grp-chk:checked'));
    if (!checked.length) {
        alert('No hay grupos seleccionados.');
        return;
    }

    var groups = checked.map(function(cb) {
        return {
            groupid: +cb.dataset.groupid,
            courseid: +cb.dataset.courseid
        };
    });

    if (!confirm('Eliminar ' + groups.length + ' grupo(s) seleccionado(s)?\\nEsta accion no se puede deshacer.')) {
        return;
    }

    showOverlay('Eliminando grupos seleccionados...');
    var bar = document.getElementById('prog-bar');
    var done = 0;
    var errors = 0;
    var total = groups.length;

    for (var i = 0; i < groups.length; i++) {
        var g = groups[i];
        document.getElementById('prog-title').textContent = 'Eliminando grupos... ' + (i + 1) + '/' + total;
        bar.style.width = Math.round((i / total) * 100) + '%';
        try {
            var data = await fetchWithTimeout(
                BASE + '?action=deletegroup&groupid=' + g.groupid + '&courseid=' + g.courseid + '&sesskey=' + SESS,
                45000
            );
            logLine('Grupo ' + g.groupid + ': ' + data.msg, data.ok);
            if (data.ok) {
                done++;
                var row = document.getElementById('grow-' + g.groupid);
                if (row) {
                    row.style.opacity = '0.4';
                }
            } else {
                errors++;
            }
        } catch(e) {
            logLine('Grupo ' + g.groupid + ': ' + e.message, false);
            errors++;
        }
        bar.style.width = Math.round(((i + 1) / total) * 100) + '%';
    }

    document.getElementById('prog-title').textContent =
        'Completado: ' + done + ' eliminado(s)' + (errors > 0 ? ', ' + errors + ' error(es)' : '') + '.';
    finishOverlay(errors === 0);
}

async function deleteOneSection(info, btn) {
    if (!confirm('Eliminar seccin id=' + info.sectionid + ' con todas sus actividades?')) return;
    btn.disabled = true;
    var statusEl = document.getElementById('sstatus-' + info.sectionid);
    statusEl.textContent = ' Eliminando...';
    try {
        var data = await fetchWithTimeout(
            BASE + '?action=deletesection&sectionid=' + info.sectionid +
                   '&courseid=' + info.courseid + '&sectionnumber=' + info.sectionnumber + '&sesskey=' + SESS,
            45000
        );
        if (data.ok) {
            statusEl.textContent = ' [OK] ' + data.msg;
            statusEl.style.color = 'green';
            document.getElementById('srow-' + info.sectionid).style.opacity = '0.4';
        } else {
            statusEl.textContent = ' [ERR] ' + data.msg;
            statusEl.style.color = 'red';
            btn.disabled = false;
        }
    } catch(e) {
        statusEl.textContent = ' [ERR] ' + e.message;
        statusEl.style.color = 'red';
        btn.disabled = false;
    }
}
</script>";

echo $OUTPUT->footer();

