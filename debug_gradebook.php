<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

// Force login and check capabilities if possible, or just require admin if it's sensitive
require_login();
$systemcontext = context_system::instance();

$classid = optional_param('classid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Gradebook Debugger</title>
    <style>
        body { font-family: sans-serif; margin: 20px; line-height: 1.5; background-color: #f4f4f9; color: #333; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1, h2, h3 { color: #0056b3; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; background: white; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f8f9fa; font-weight: bold; }
        tr:nth-child(even) { background-color: #fafafa; }
        .highlight { background-color: #fff3cd !important; }
        .error-row { background-color: #f8d7da !important; }
        .success-row { background-color: #d4edda !important; }
        .info-box { background: #e7f3ff; padding: 15px; border-left: 5px solid #2196F3; margin: 10px 0; }
        select, button { padding: 8px; font-size: 16px; margin: 5px 0; border-radius: 4px; border: 1px solid #ccc; }
        button { cursor: pointer; background: #007bff; color: white; border: none; }
        button:hover { background: #0056b3; }
        form { margin-bottom: 30px; padding: 15px; background: #eee; border-radius: 5px; }
    </style>
</head>
<body>
<div class='container'>
    <h1>Gradebook Debugger 🛠️</h1>";

// 1. Selector de Cursos Activos
echo "<form method='get'>
        <label for='classid'><strong>Seleccionar una Clase Activa:</strong></label><br>
        <select name='classid' id='classid' onchange='this.form.submit()'>
            <option value=''>-- Seleccionar Clase --</option>";

$active_classes = $DB->get_records_sql("
    SELECT c.id, c.name, crs.fullname as coursename 
    FROM {gmk_class} c 
    JOIN {course} crs ON crs.id = c.corecourseid 
    WHERE c.closed = 0 
    ORDER BY crs.fullname ASC, c.name ASC
");

foreach ($active_classes as $ac) {
    $selected = ($classid == $ac->id) ? "selected" : "";
    echo "<option value='{$ac->id}' $selected>{$ac->coursename} - {$ac->name}</option>";
}

echo "  </select>
        <button type='submit'>Cargar</button>
        <p><small>O ingresa el ID directamente: 
            Class ID: <input type='number' name='classid' value='$classid' style='width: 60px;'> 
            Course ID: <input type='number' name='courseid' value='$courseid' style='width: 60px;'>
            <button type='submit'>Ir</button>
        </small></p>
      </form>";

if ($classid) {
    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if ($class) {
        $courseid = $class->corecourseid;
        echo "<div class='info-box'>";
        echo "<h2>Clase: {$class->name} (ID: $classid)</h2>";
        echo "<p><strong>Grade Category ID:</strong> {$class->gradecategoryid}</p>";
        echo "</div>";
    } else {
        echo "<p style='color:red;'>Error: Clase no encontrada.</p>";
    }
}

if ($courseid) {
    echo "<h2>Curso ID: $courseid</h2>";
    
    try {
        $target_cat = \grade_category::fetch_course_category($courseid);
        if ($classid && !empty($class->gradecategoryid)) {
            $class_cat = \grade_category::fetch(['id' => $class->gradecategoryid]);
            if ($class_cat) $target_cat = $class_cat;
        }
        
        echo "<div class='info-box'>";
        echo "<h3>Categoría Destino: {$target_cat->fullname} (ID: {$target_cat->id})</h3>";
        $agg_names = [
            0 => 'Mean of grades',
            1 => 'Weighted mean of grades',
            13 => 'Natural',
            10 => 'Simple weighted mean of grades',
            11 => 'Mean of grades (with extra credits)'
        ];
        $agg_name = isset($agg_names[$target_cat->aggregation]) ? $agg_names[$target_cat->aggregation] : 'Other (' . $target_cat->aggregation . ')';
        echo "<p><strong>Método de Agregación:</strong> $agg_name</p>";
        echo "</div>";

        $grade_items = \grade_item::fetch_all(['courseid' => $courseid]);
        
        echo "<h3>Ítems de Calificación Detectados</h3>";
        echo "<table>";
        echo "<tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Tipo</th>
                <th>Max</th>
                <th>Weight (coef)</th>
                <th>Weight (coef2)</th>
                <th>Override</th>
                <th>Locked</th>
                <th>Hidden</th>
              </tr>";

        foreach ($grade_items as $gi) {
            if ($gi->itemtype == 'course' || $gi->itemtype == 'category') continue;
            
            $row_class = "";
            if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false) {
                $row_class = "error-row";
            }

            echo "<tr class='$row_class'>";
            echo "<td>{$gi->id}</td>";
            echo "<td>" . ($gi->itemname ?: ($gi->itemtype . ' ' . $gi->itemmodule)) . "</td>";
            echo "<td>{$gi->itemtype}</td>";
            echo "<td>{$gi->grademax}</td>";
            echo "<td>{$gi->aggregationcoef}</td>";
            echo "<td>{$gi->aggregationcoef2}</td>";
            echo "<td>" . ($gi->weightoverride ? "✅ SI" : "❌ NO") . "</td>";
            echo "<td>{$gi->locked}</td>";
            echo "<td>{$gi->hidden}</td>";
            echo "</tr>";
        }
        echo "</table>";

        // SIMULACIÓN DE LÓGICA DE NORMALIZACIÓN (La que está en ajax.php)
        echo "<h3>Simulación de Lógica de Normalización (AJAX)</h3>";
        $items = [];
        foreach ($grade_items as $gi) {
            if ($gi->itemtype == 'course' || $gi->itemtype == 'category') continue;
            if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false) continue;

            // Lógica idéntica a ajax.php
            $weight = ($target_cat->aggregation == 13) ? (float)$gi->aggregationcoef2 : (float)$gi->aggregationcoef;
            $items[] = [
                'name' => $gi->itemname ?: ($gi->itemtype . ' ' . $gi->itemmodule),
                'weight' => $weight,
                'override' => (int)$gi->weightoverride,
                'grademax' => (float)$gi->grademax
            ];
        }

        $sum_max = 0;
        $sum_weights = 0;
        $any_overridden = false;
        foreach ($items as $it) {
            $sum_max += $it['grademax'];
            $sum_weights += $it['weight'];
            if ($it['override'] == 1 || $it['weight'] > 0) {
                $any_overridden = true;
            }
        }

        echo "<ul>
                <li><strong>Sum Max Grades:</strong> $sum_max</li>
                <li><strong>Sum Original Weights:</strong> $sum_weights</li>
                <li><strong>¿Hay pesos manuales detectados?:</strong> " . ($any_overridden ? "SI" : "NO") . "</li>
              </ul>";

        // Aplicamos la normalización robusta que acabamos de meter en ajax.php
        $normalized_items = [];
        if ($sum_weights <= 0 && $sum_max > 0) {
            echo "<p class='info-box'><strong>Caso A:</strong> Todo automático. Distribuyendo por Nota Máxima.</p>";
            $running_sum = 0;
            $count = count($items);
            $idx = 0;
            foreach ($items as $it) {
                $idx++;
                $val = ($it['grademax'] / $sum_max) * 100;
                if ($idx == $count) {
                    $it['norm_weight'] = round(100 - $running_sum, 2);
                } else {
                    $it['norm_weight'] = round($val, 2);
                    $running_sum += $it['norm_weight'];
                }
                $normalized_items[] = $it;
            }
        } else if ($sum_weights > 0) {
            echo "<p class='info-box'><strong>Caso B:</strong> Mezcla o pesos manuales. Calculando distribución equitativa para no-bloqueados.</p>";
            $effective_sum = 0;
            foreach ($items as &$it) {
                if ($it['weight'] <= 0 && $it['override'] == 0) {
                    $it['temp_weight'] = 1.0; 
                } else {
                    $it['temp_weight'] = $it['weight'];
                }
                $effective_sum += $it['temp_weight'];
            }
            
            $running_sum = 0;
            $count = count($items);
            $idx = 0;
            foreach ($items as $it) {
                $idx++;
                $val = ($it['temp_weight'] / $effective_sum) * 100;
                if ($idx == $count) {
                    $it['norm_weight'] = round(100 - $running_sum, 2);
                } else {
                    $it['norm_weight'] = round($val, 2);
                    $running_sum += $it['norm_weight'];
                }
                $normalized_items[] = $it;
            }
        } else {
            $normalized_items = $items;
        }

        echo "<table>";
        echo "<tr><th>Ítem</th><th>Peso Original</th><th>Bypass/Manual</th><th>% Resultante</th></tr>";
        $total_final = 0;
        foreach ($normalized_items as $it) {
            $total_final += $it['norm_weight'] ?? 0;
            echo "<tr>";
            echo "<td>{$it['name']}</td>";
            echo "<td>{$it['weight']}</td>";
            echo "<td>" . ($it['override'] ? "✅ SI" : "❌ NO") . "</td>";
            echo "<td><strong>" . number_format($it['norm_weight']??0, 2) . "%</strong></td>";
            echo "</tr>";
        }
        $footer_class = (abs($total_final - 100) < 0.01) ? "success-row" : "error-row";
        echo "<tr class='$footer_class'>
                <td colspan='3'><strong>TOTAL</strong></td>
                <td><strong>" . number_format($total_final, 2) . "%</strong></td>
              </tr>";
        echo "</table>";

    } catch (Exception $e) {
        echo "<p style='color:red;'>Error de Moodle: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<div class='info-box'>Selecciona una clase en el menú de arriba para ver los detalles.</div>";
}

echo "</div> <!-- container -->
</body>
</html>";
