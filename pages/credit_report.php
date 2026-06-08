<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * credit_report.php — Downloadable student credit report (PDF / Excel).
 *
 * Both formats are rendered server-side from the same data builder
 * (\local_grupomakro_core\local\credit_report) so they share one design.
 *
 * GET params:
 *   userId (int)   — required, student user id.
 *   planId (int)   — optional, 0 = all the student's plans.
 *   scope  (alpha) — 'all' (default) or 'enrolled'.
 *   format (alpha) — 'pdf' (default) or 'xlsx'.
 *   sesskey        — required.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/credit_report.php');

require_login();
require_sesskey();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$userid = required_param('userId', PARAM_INT);
$planid = optional_param('planId', 0, PARAM_INT);
$scope  = optional_param('scope', 'all', PARAM_ALPHA);
$format = optional_param('format', 'pdf', PARAM_ALPHA);

$data = \local_grupomakro_core\local\credit_report::build($userid, $planid, $scope);

// ── Shared design palette ────────────────────────────────────────────────────
$DESIGN = [
    'header_bg'   => '1976D2', // Main title bar.
    'career_bg'   => '0D47A1', // Career header.
    'cuatri_bg'   => 'E8F0FE', // Cuatrimestre section header.
    'cuatri_txt'  => '15418A',
    'thead_bg'    => 'CFD8DC', // Table column headers.
    'subtotal_bg' => 'ECEFF1', // Per-cuatrimestre subtotal row.
    'summary_bg'  => '1565C0', // Global summary box.
    'zebra_bg'    => 'F8F9FA',
    'border'      => 'B0BEC5',
    'green'       => '1B8E4F',
    'red'         => 'C62828',
    'grey'        => '616161',
];

$scopelabel = ($data['scope'] === 'enrolled') ? 'Solo cursadas / en curso' : 'Todas las asignaturas del plan';

$safename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['student']['name'] ?? 'estudiante');
$safename = trim($safename, '_') ?: 'estudiante';
$filebase = 'informe_creditos_' . $safename . '_' . date('Ymd');

/**
 * Colour (hex, no #) for a numeric grade.
 */
function cr_grade_color(string $grade, array $design): string {
    if (!is_numeric($grade)) {
        return $design['grey'];
    }
    return ((float)$grade >= 70.0) ? $design['green'] : $design['red'];
}

if ($format === 'xlsx') {
    cr_render_xlsx($data, $DESIGN, $scopelabel, $filebase);
} else {
    cr_render_pdf($data, $DESIGN, $scopelabel, $filebase);
}
exit;

// ─────────────────────────────────────────────────────────────────────────────
// PDF renderer (TCPDF + writeHTML).
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Render and stream the report as a PDF download.
 *
 * @param array  $data       Builder output.
 * @param array  $design     Shared palette.
 * @param string $scopelabel Human label for the chosen scope.
 * @param string $filebase   Filename without extension.
 */
function cr_render_pdf(array $data, array $design, string $scopelabel, string $filebase): void {
    global $CFG;
    require_once($CFG->libdir . '/tcpdf/tcpdf.php');

    $student = $data['student'];

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('ISI Moodle');
    $pdf->SetTitle('Informe de Créditos - ' . $student['name']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $e = function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    // Header bar + student info.
    $html  = '<table cellpadding="6" cellspacing="0"><tr>';
    $html .= '<td bgcolor="#' . $design['header_bg'] . '" width="100%">';
    $html .= '<span style="color:#FFFFFF;font-size:15px;font-weight:bold;">Instituto Superior ISI &mdash; Informe de Créditos</span><br/>';
    $html .= '<span style="color:#FFFFFF;font-size:8px;">Generado: ' . $e($data['generatedat']) . ' &nbsp;|&nbsp; Alcance: ' . $e($scopelabel) . '</span>';
    $html .= '</td></tr></table>';

    $html .= '<table cellpadding="3" cellspacing="0" style="font-size:9px;"><tr>';
    $html .= '<td width="55%"><b>Estudiante:</b> ' . $e($student['name']) . '</td>';
    $html .= '<td width="45%"><b>Identificación:</b> ' . $e($student['identification']) . '</td>';
    $html .= '</tr><tr>';
    $html .= '<td width="55%"><b>Email:</b> ' . $e($student['email']) . '</td>';
    $html .= '<td width="45%">&nbsp;</td>';
    $html .= '</tr></table><br/>';

    if (empty($data['careers'])) {
        $html .= '<p style="color:#' . $design['grey'] . ';font-style:italic;">No hay asignaturas para mostrar con el alcance seleccionado.</p>';
    }

    foreach ($data['careers'] as $career) {
        $html .= '<table cellpadding="5" cellspacing="0"><tr>';
        $html .= '<td bgcolor="#' . $design['career_bg'] . '"><span style="color:#FFFFFF;font-size:11px;font-weight:bold;">' . $e($career['career']) . '</span></td>';
        $html .= '</tr></table>';

        foreach ($career['cuatrimestres'] as $cuatri) {
            $sub = $cuatri['subtotal'];
            // Cuatrimestre section header.
            $html .= '<table cellpadding="4" cellspacing="0"><tr>';
            $html .= '<td bgcolor="#' . $design['cuatri_bg'] . '" width="70%"><span style="color:#' . $design['cuatri_txt'] . ';font-size:9.5px;font-weight:bold;">' . $e($cuatri['name']) . '</span></td>';
            $html .= '<td bgcolor="#' . $design['cuatri_bg'] . '" width="30%" align="right"><span style="color:#' . $design['cuatri_txt'] . ';font-size:8.5px;">Créditos: ' . (int)$sub['approved'] . ' / ' . (int)$sub['total'] . '</span></td>';
            $html .= '</tr></table>';

            // Courses table.
            $html .= '<table cellpadding="3" cellspacing="0" border="0.4" style="font-size:8.5px;">';
            $html .= '<tr bgcolor="#' . $design['thead_bg'] . '">';
            $html .= '<th width="55%" align="left"><b>Asignatura</b></th>';
            $html .= '<th width="13%" align="center"><b>Créditos</b></th>';
            $html .= '<th width="20%" align="center"><b>Estado</b></th>';
            $html .= '<th width="12%" align="center"><b>Nota</b></th>';
            $html .= '</tr>';

            $i = 0;
            foreach ($cuatri['courses'] as $course) {
                $bg = ($i % 2 === 0) ? '#FFFFFF' : '#' . $design['zebra_bg'];
                $name = $e($course['coursename']) . (!empty($course['is_module']) ? ' <i>(M)</i>' : '');
                $gradecolor = '#' . cr_grade_color($course['grade'], $design);
                $gradetext = ($course['grade'] === '' || $course['grade'] === '-' || $course['grade'] === '--') ? '--' : $e($course['grade']);
                $html .= '<tr bgcolor="' . $bg . '">';
                $html .= '<td width="55%">' . $name . '</td>';
                $html .= '<td width="13%" align="center">' . (int)$course['credits'] . '</td>';
                $html .= '<td width="20%" align="center"><span style="color:' . $e($course['statusColor']) . ';font-weight:bold;">' . $e($course['statusLabel']) . '</span></td>';
                $html .= '<td width="12%" align="center"><span style="color:' . $gradecolor . ';font-weight:bold;">' . $gradetext . '</span></td>';
                $html .= '</tr>';
                $i++;
            }

            // Subtotal row.
            $html .= '<tr bgcolor="#' . $design['subtotal_bg'] . '">';
            $html .= '<td width="55%" align="right"><b>Subtotal cuatrimestre</b></td>';
            $html .= '<td width="13%" align="center"><b>' . (int)$sub['total'] . '</b></td>';
            $html .= '<td width="32%" align="center"><b>Aprobados: ' . (int)$sub['approved'] . '</b></td>';
            $html .= '</tr>';
            $html .= '</table><br/>';
        }

        // Global summary box for the career.
        $s = $career['summary'];
        $html .= '<table cellpadding="6" cellspacing="0"><tr>';
        $html .= '<td bgcolor="#' . $design['summary_bg'] . '"><span style="color:#FFFFFF;font-size:9px;font-weight:bold;">RESUMEN &nbsp;&mdash;&nbsp; '
            . 'Créditos aprobados: ' . (int)$s['approved']
            . ' &nbsp;|&nbsp; En curso: ' . (int)$s['incourse']
            . ' &nbsp;|&nbsp; Pendientes: ' . (int)$s['pending']
            . ' &nbsp;|&nbsp; Total del plan: ' . (int)$s['total']
            . ' &nbsp;|&nbsp; Avance: ' . $e($s['pct']) . '%</span></td>';
        $html .= '</tr></table><br/><br/>';
    }

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filebase . '.pdf', 'D');
}

// ─────────────────────────────────────────────────────────────────────────────
// Excel renderer (PhpSpreadsheet) — mirrors the PDF layout.
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Render and stream the report as an .xlsx download.
 *
 * @param array  $data
 * @param array  $design
 * @param string $scopelabel
 * @param string $filebase
 */
function cr_render_xlsx(array $data, array $design, string $scopelabel, string $filebase): void {
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Créditos');

    $sheet->getColumnDimension('A')->setWidth(52);
    $sheet->getColumnDimension('B')->setWidth(12);
    $sheet->getColumnDimension('C')->setWidth(22);
    $sheet->getColumnDimension('D')->setWidth(12);

    $student = $data['student'];
    $r = 1;

    $fill = function ($cells, $hex) use ($sheet) {
        $sheet->getStyle($cells)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB($hex);
    };
    $fontcolor = function ($cells, $hex, $bold = false) use ($sheet) {
        $sheet->getStyle($cells)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . $hex));
        if ($bold) {
            $sheet->getStyle($cells)->getFont()->setBold(true);
        }
    };
    $borderall = function ($cells) use ($sheet, $design) {
        $sheet->getStyle($cells)->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . $design['border']));
    };

    // Title bar.
    $sheet->mergeCells("A{$r}:D{$r}");
    $sheet->setCellValue("A{$r}", 'Instituto Superior ISI — Informe de Créditos');
    $fill("A{$r}:D{$r}", $design['header_bg']);
    $fontcolor("A{$r}:D{$r}", 'FFFFFF', true);
    $sheet->getStyle("A{$r}")->getFont()->setSize(14);
    $sheet->getRowDimension($r)->setRowHeight(24);
    $r++;
    $sheet->mergeCells("A{$r}:D{$r}");
    $sheet->setCellValue("A{$r}", 'Generado: ' . $data['generatedat'] . '  |  Alcance: ' . $scopelabel);
    $fill("A{$r}:D{$r}", $design['header_bg']);
    $fontcolor("A{$r}:D{$r}", 'FFFFFF');
    $r += 2;

    // Student info.
    $sheet->setCellValue("A{$r}", 'Estudiante:');
    $sheet->setCellValue("B{$r}", $student['name']);
    $sheet->setCellValue("C{$r}", 'Identificación:');
    $sheet->setCellValue("D{$r}", $student['identification']);
    $sheet->getStyle("A{$r}")->getFont()->setBold(true);
    $sheet->getStyle("C{$r}")->getFont()->setBold(true);
    $r++;
    $sheet->setCellValue("A{$r}", 'Email:');
    $sheet->setCellValue("B{$r}", $student['email']);
    $sheet->getStyle("A{$r}")->getFont()->setBold(true);
    $r += 2;

    if (empty($data['careers'])) {
        $sheet->setCellValue("A{$r}", 'No hay asignaturas para mostrar con el alcance seleccionado.');
        $fontcolor("A{$r}", $design['grey']);
        cr_stream_xlsx($ss, $filebase);
        return;
    }

    foreach ($data['careers'] as $career) {
        // Career header.
        $sheet->mergeCells("A{$r}:D{$r}");
        $sheet->setCellValue("A{$r}", $career['career']);
        $fill("A{$r}:D{$r}", $design['career_bg']);
        $fontcolor("A{$r}:D{$r}", 'FFFFFF', true);
        $sheet->getStyle("A{$r}")->getFont()->setSize(12);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;

        foreach ($career['cuatrimestres'] as $cuatri) {
            $sub = $cuatri['subtotal'];
            // Cuatrimestre section header.
            $sheet->mergeCells("A{$r}:C{$r}");
            $sheet->setCellValue("A{$r}", $cuatri['name']);
            $sheet->setCellValue("D{$r}", $sub['approved'] . ' / ' . $sub['total']);
            $fill("A{$r}:D{$r}", $design['cuatri_bg']);
            $fontcolor("A{$r}:D{$r}", $design['cuatri_txt'], true);
            $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $r++;

            // Table header.
            $sheet->setCellValue("A{$r}", 'Asignatura');
            $sheet->setCellValue("B{$r}", 'Créditos');
            $sheet->setCellValue("C{$r}", 'Estado');
            $sheet->setCellValue("D{$r}", 'Nota');
            $fill("A{$r}:D{$r}", $design['thead_bg']);
            $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
            $borderall("A{$r}:D{$r}");
            $sheet->getStyle("B{$r}:D{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $r++;

            $i = 0;
            foreach ($cuatri['courses'] as $course) {
                $name = $course['coursename'] . (!empty($course['is_module']) ? ' (M)' : '');
                $gradetext = ($course['grade'] === '' || $course['grade'] === '-' || $course['grade'] === '--') ? '--' : $course['grade'];
                $sheet->setCellValue("A{$r}", $name);
                $sheet->setCellValueExplicit("B{$r}", (int)$course['credits'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $sheet->setCellValue("C{$r}", $course['statusLabel']);
                $sheet->setCellValue("D{$r}", $gradetext);
                if ($i % 2 === 1) {
                    $fill("A{$r}:D{$r}", $design['zebra_bg']);
                }
                // Status colour (strip leading # from the hex coming from the builder).
                $statushex = ltrim($course['statusColor'], '#');
                if (strlen($statushex) === 6) {
                    $fontcolor("C{$r}", strtoupper($statushex), true);
                }
                $fontcolor("D{$r}", cr_grade_color($course['grade'], $design), true);
                $borderall("A{$r}:D{$r}");
                $sheet->getStyle("B{$r}:D{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $r++;
                $i++;
            }

            // Subtotal row.
            $sheet->setCellValue("A{$r}", 'Subtotal cuatrimestre');
            $sheet->setCellValueExplicit("B{$r}", (int)$sub['total'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->mergeCells("C{$r}:D{$r}");
            $sheet->setCellValue("C{$r}", 'Aprobados: ' . (int)$sub['approved']);
            $fill("A{$r}:D{$r}", $design['subtotal_bg']);
            $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("B{$r}:D{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $borderall("A{$r}:D{$r}");
            $r += 2;
        }

        // Global summary box.
        $s = $career['summary'];
        $sheet->mergeCells("A{$r}:D{$r}");
        $sheet->setCellValue("A{$r}",
            'RESUMEN  —  Créditos aprobados: ' . (int)$s['approved']
            . '  |  En curso: ' . (int)$s['incourse']
            . '  |  Pendientes: ' . (int)$s['pending']
            . '  |  Total del plan: ' . (int)$s['total']
            . '  |  Avance: ' . $s['pct'] . '%');
        $fill("A{$r}:D{$r}", $design['summary_bg']);
        $fontcolor("A{$r}:D{$r}", 'FFFFFF', true);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r += 2;
    }

    cr_stream_xlsx($ss, $filebase);
}

/**
 * Stream a spreadsheet as an .xlsx attachment and end the request.
 *
 * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $ss
 * @param string $filebase
 */
function cr_stream_xlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $ss, string $filebase): void {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filebase . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    $writer->save('php://output');
}
