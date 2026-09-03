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
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/grade_scale.php');

use local_grupomakro_core\local\grade_scale;

require_login();
require_sesskey();
$context = context_system::instance();
require_capability('local/grupomakro_core:view_credit_report', $context);

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
 * Resolve the site/theme logo as raw image data for server-side embedding.
 *
 * Tries the given fileareas in order, in the active theme component first
 * (where this install actually stores its logo) and core_admin as fallback.
 *
 * @param string[] $areas Filearea preference order (e.g. ['logodark','logo']).
 * @return array|null ['data'=>string, 'mime'=>string, 'w'=>int, 'h'=>int] or null when none.
 */
function cr_logo_binary(array $areas): ?array {
    global $CFG;

    // 1) Primary source: the same logo the "Reporte de Notas" uses (academicpanel.php):
    //    the theme 'logo' setting served from the theme's pix/static directory on disk.
    try {
        $theme = \theme_config::load($CFG->theme);
        if (!empty($theme->settings->logo)) {
            $path = $CFG->dirroot . '/theme/' . $CFG->theme . '/pix/static/' . basename($theme->settings->logo);
            if (is_readable($path)) {
                $content = file_get_contents($path);
                $info = @getimagesizefromstring($content);
                if ($info) {
                    return [
                        'data' => $content,
                        'mime' => $info['mime'],
                        'w'    => (int)$info[0],
                        'h'    => (int)$info[1],
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        // Fall through to the file-storage lookup below.
    }

    // 2) Fallback: theme / core_admin file storage areas.
    $fs = get_file_storage();
    $ctxid = \context_system::instance()->id;
    $components = ['theme_' . $CFG->theme, 'core_admin'];
    foreach ($areas as $area) {
        foreach ($components as $comp) {
            $files = $fs->get_area_files($ctxid, $comp, $area, false, 'itemid', false);
            foreach ($files as $f) {
                if ($f->is_valid_image()) {
                    $content = $f->get_content();
                    $info = @getimagesizefromstring($content);
                    return [
                        'data' => $content,
                        'mime' => $info ? $info['mime'] : 'image/png',
                        'w'    => $info ? (int)$info[0] : 0,
                        'h'    => $info ? (int)$info[1] : 0,
                    ];
                }
            }
        }
    }
    return null;
}

/**
 * Colour (hex, no #) for a numeric grade.
 *
 * Uses the canonical institutional threshold (> 70.9, same as
 * gmk_classify_student_grade) so the colour never disagrees with the letter
 * printed next to it: a flat 70.00 is a D and must not read as green.
 */
function cr_grade_color(string $grade, array $design): string {
    if (grade_scale::to_float($grade) === null) {
        return $design['grey'];
    }
    return grade_scale::is_passing($grade) ? $design['green'] : $design['red'];
}

/**
 * Text and colour for the letter cell of a course row.
 *
 * @param array $course Course entry from the builder.
 * @param array $design Shared palette.
 * @return array{0:string,1:string} [letter, hex colour without #]
 */
function cr_letter_cell(array $course, array $design): array {
    $letter = (string)($course['letter'] ?? '');
    if ($letter === '') {
        return ['--', $design['grey']];
    }
    $color = (string)($course['lettercolor'] ?? '');
    return [$letter, $color !== '' ? $color : $design['grey']];
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
    $margin = 12;
    $pdf->SetMargins($margin, $margin, $margin);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $e = function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    // ── Header band drawn manually so the logo can sit inside the coloured bar ──
    $pagew   = $pdf->getPageWidth();
    $bandw   = $pagew - ($margin * 2);
    $bandh   = 18;
    $rgb     = [hexdec(substr($design['header_bg'], 0, 2)), hexdec(substr($design['header_bg'], 2, 2)), hexdec(substr($design['header_bg'], 4, 2))];
    // White header band with a blue border (logo and text are dark/blue on white).
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
    $pdf->SetLineWidth(0.6);
    $pdf->RoundedRect($margin, $margin, $bandw, $bandh, 2, '1111', 'DF');
    $pdf->SetLineWidth(0.2);

    $logo  = cr_logo_binary(['logo', 'logodark', 'logocompact']);
    $textx = $margin + 4;
    if ($logo && !empty($logo['data'])) {
        $logoh = 13;
        $logow = ($logo['h'] > 0) ? min(40, $logoh * ($logo['w'] / max(1, $logo['h']))) : 26;
        try {
            $pdf->Image('@' . $logo['data'], $margin + 3, $margin + 2.5, $logow, $logoh, '', '', '', true, 300, '', false, false, 0, false, false, false);
            $textx = $margin + 3 + $logow + 4;
        } catch (\Throwable $ex) {
            $textx = $margin + 4;
        }
    }
    $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetXY($textx, $margin + 3);
    $pdf->Cell($bandw - ($textx - $margin) - 2, 8, 'Instituto Superior ISI — Informe de Créditos', 0, 2, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetX($textx);
    $pdf->Cell($bandw - ($textx - $margin) - 2, 5, 'Generado: ' . $data['generatedat'] . '  |  Alcance: ' . $scopelabel, 0, 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($margin + $bandh + 4);

    // Student info + report body.
    $html  = '<table cellpadding="3" cellspacing="0" style="font-size:9px;"><tr>';
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
            $html .= '<th width="44%" align="left"><b>Asignatura</b></th>';
            $html .= '<th width="11%" align="center"><b>Créditos</b></th>';
            $html .= '<th width="19%" align="center"><b>Estado</b></th>';
            $html .= '<th width="13%" align="center"><b>Nota</b></th>';
            $html .= '<th width="13%" align="center"><b>Letra</b></th>';
            $html .= '</tr>';

            $i = 0;
            foreach ($cuatri['courses'] as $course) {
                $bg = ($i % 2 === 0) ? '#FFFFFF' : '#' . $design['zebra_bg'];
                $name = $e($course['coursename']) . (!empty($course['is_module']) ? ' <i>(M)</i>' : '');
                $gradecolor = '#' . cr_grade_color($course['grade'], $design);
                $gradetext = ($course['grade'] === '' || $course['grade'] === '-' || $course['grade'] === '--') ? '--' : $e($course['grade']);
                list($lettertext, $letterhex) = cr_letter_cell($course, $design);
                $html .= '<tr bgcolor="' . $bg . '">';
                $html .= '<td width="44%">' . $name . '</td>';
                $html .= '<td width="11%" align="center">' . (int)$course['credits'] . '</td>';
                $html .= '<td width="19%" align="center"><span style="color:' . $e($course['statusColor']) . ';font-weight:bold;">' . $e($course['statusLabel']) . '</span></td>';
                $html .= '<td width="13%" align="center"><span style="color:' . $gradecolor . ';font-weight:bold;">' . $gradetext . '</span></td>';
                $html .= '<td width="13%" align="center"><span style="color:#' . $letterhex . ';font-weight:bold;">' . $e($lettertext) . '</span></td>';
                $html .= '</tr>';
                $i++;
            }

            // Subtotal row.
            $html .= '<tr bgcolor="#' . $design['subtotal_bg'] . '">';
            $html .= '<td width="44%" align="right"><b>Subtotal cuatrimestre</b></td>';
            $html .= '<td width="11%" align="center"><b>' . (int)$sub['total'] . '</b></td>';
            $html .= '<td width="45%" align="center"><b>Aprobados: ' . (int)$sub['approved'] . '</b></td>';
            $html .= '</tr>';
            $html .= '</table><br/>';
        }

        // Global summary box for the career.
        $s = $career['summary'];
        $idx = $s['index'];
        $html .= '<table cellpadding="6" cellspacing="0"><tr>';
        $html .= '<td bgcolor="#' . $design['summary_bg'] . '"><span style="color:#FFFFFF;font-size:9px;font-weight:bold;">RESUMEN &nbsp;&mdash;&nbsp; '
            . 'Créditos aprobados: ' . (int)$s['approved']
            . ' &nbsp;|&nbsp; En curso: ' . (int)$s['incourse']
            . ' &nbsp;|&nbsp; Pendientes: ' . (int)$s['pending']
            . ' &nbsp;|&nbsp; Total del plan: ' . (int)$s['total']
            . ' &nbsp;|&nbsp; Avance: ' . $e($s['pct']) . '%'
            . ' &nbsp;|&nbsp; Índice: ' . $e($idx['display']) . ' / ' . $e($idx['scaletext'])
            . '</span></td>';
        $html .= '</tr></table><br/><br/>';
    }

    // ── Closing block: cumulative academic index + scale legend ──────────────
    if (!empty($data['careers'])) {
        $gidx = $data['index'];
        $html .= '<table cellpadding="6" cellspacing="0" border="0.4"><tr>';
        $html .= '<td bgcolor="#' . $design['career_bg'] . '" width="62%"><span style="color:#FFFFFF;font-size:10px;font-weight:bold;">ÍNDICE ACADÉMICO ACUMULADO</span><br/>'
            . '<span style="color:#DCE7F7;font-size:7.5px;">Ponderado por créditos sobre ' . (int)$gidx['courses'] . ' asignatura(s) con calificación final ('
            . (int)$gidx['credits'] . ' créditos).</span></td>';
        $html .= '<td bgcolor="#' . $design['career_bg'] . '" width="38%" align="center"><span style="color:#FFFFFF;font-size:18px;font-weight:bold;">'
            . $e($gidx['display']) . '</span><span style="color:#DCE7F7;font-size:9px;"> / ' . $e($gidx['scaletext']) . '</span></td>';
        $html .= '</tr></table>';

        if ((int)$gidx['uncredited'] > 0) {
            $html .= '<p style="font-size:7px;color:#' . $design['grey'] . ';">Nota: ' . (int)$gidx['uncredited']
                . ' asignatura(s) con calificación final no ponderan porque no tienen créditos definidos en el plan.</p>';
        }

        $html .= '<br/><span style="font-size:8.5px;font-weight:bold;color:#' . $design['cuatri_txt'] . ';">Escala de calificación</span><br/>';
        $html .= '<table cellpadding="3" cellspacing="0" border="0.4" style="font-size:8px;">';
        $html .= '<tr bgcolor="#' . $design['thead_bg'] . '">';
        $html .= '<th width="30%" align="center"><b>Calificación numérica</b></th>';
        $html .= '<th width="15%" align="center"><b>Letras</b></th>';
        $html .= '<th width="35%" align="left"><b>Concepto</b></th>';
        $html .= '<th width="20%" align="center"><b>Puntos índice</b></th>';
        $html .= '</tr>';
        foreach ($data['legend'] as $band) {
            $html .= '<tr>';
            $html .= '<td width="30%" align="center">' . $e($band['range']) . '</td>';
            $html .= '<td width="15%" align="center"><span style="color:#' . $e($band['color']) . ';font-weight:bold;">' . $e($band['letter']) . '</span></td>';
            $html .= '<td width="35%">' . $e($band['concept']) . '</td>';
            $html .= '<td width="20%" align="center">' . $e($band['points']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '<p style="font-size:7px;color:#' . $design['grey'] . ';">Las asignaturas en curso no reciben calificación en letras ni ponderan en el índice hasta el cierre del período.</p>';
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
    global $CFG;
    require_once($CFG->libdir . '/phpspreadsheet/vendor/autoload.php');

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Créditos');

    $sheet->getColumnDimension('A')->setWidth(52);
    $sheet->getColumnDimension('B')->setWidth(12);
    $sheet->getColumnDimension('C')->setWidth(22);
    $sheet->getColumnDimension('D')->setWidth(12);
    $sheet->getColumnDimension('E')->setWidth(10);
    $sheet->getColumnDimension('F')->setWidth(20);

    $student = $data['student'];
    $r = 1;

    // Logo on the white header (the ISI logo from pix/static is dark/coloured).
    $logo = cr_logo_binary(['logo', 'logodark', 'logocompact']);
    $haslogo = false;
    if ($logo && !empty($logo['data'])) {
        $gd = @imagecreatefromstring($logo['data']);
        if ($gd !== false) {
            $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing();
            $drawing->setImageResource($gd);
            $drawing->setRenderingFunction(\PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing::RENDERING_DEFAULT);
            $drawing->setMimeType(\PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing::MIMETYPE_DEFAULT);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(6);
            $drawing->setOffsetY(5);
            $drawing->setHeight(26);
            $drawing->setWorksheet($sheet);
            $haslogo = true;
        }
    }

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

    // Header: white background with a blue border (logo + text are dark/blue on white).
    $headerstart = $r;
    $sheet->mergeCells("A{$r}:F{$r}");
    $sheet->setCellValue("A{$r}", 'Instituto Superior ISI — Informe de Créditos');
    $fill("A{$r}:F{$r}", 'FFFFFF');
    $fontcolor("A{$r}:F{$r}", $design['header_bg'], true);
    $sheet->getStyle("A{$r}")->getFont()->setSize(14);
    $sheet->getStyle("A{$r}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    if ($haslogo) {
        $sheet->getStyle("A{$r}")->getAlignment()->setIndent(6);
    }
    $sheet->getRowDimension($r)->setRowHeight(30);
    $r++;
    $sheet->mergeCells("A{$r}:F{$r}");
    $sheet->setCellValue("A{$r}", 'Generado: ' . $data['generatedat'] . '  |  Alcance: ' . $scopelabel);
    $fill("A{$r}:F{$r}", 'FFFFFF');
    $fontcolor("A{$r}:F{$r}", '5A5A5A');
    // Blue outline around the whole header block.
    $sheet->getStyle("A{$headerstart}:F{$r}")->getBorders()->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)
        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF' . $design['header_bg']));
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
        $sheet->mergeCells("A{$r}:F{$r}");
        $sheet->setCellValue("A{$r}", $career['career']);
        $fill("A{$r}:F{$r}", $design['career_bg']);
        $fontcolor("A{$r}:F{$r}", 'FFFFFF', true);
        $sheet->getStyle("A{$r}")->getFont()->setSize(12);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;

        foreach ($career['cuatrimestres'] as $cuatri) {
            $sub = $cuatri['subtotal'];
            // Cuatrimestre section header.
            $sheet->mergeCells("A{$r}:D{$r}");
            $sheet->setCellValue("A{$r}", $cuatri['name']);
            $sheet->mergeCells("E{$r}:F{$r}");
            $sheet->setCellValue("E{$r}", $sub['approved'] . ' / ' . $sub['total']);
            $fill("A{$r}:F{$r}", $design['cuatri_bg']);
            $fontcolor("A{$r}:F{$r}", $design['cuatri_txt'], true);
            $sheet->getStyle("E{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $r++;

            // Table header.
            $sheet->setCellValue("A{$r}", 'Asignatura');
            $sheet->setCellValue("B{$r}", 'Créditos');
            $sheet->setCellValue("C{$r}", 'Estado');
            $sheet->setCellValue("D{$r}", 'Nota');
            $sheet->setCellValue("E{$r}", 'Letra');
            $sheet->setCellValue("F{$r}", 'Concepto');
            $fill("A{$r}:F{$r}", $design['thead_bg']);
            $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
            $borderall("A{$r}:F{$r}");
            $sheet->getStyle("B{$r}:F{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $r++;

            $i = 0;
            foreach ($cuatri['courses'] as $course) {
                $name = $course['coursename'] . (!empty($course['is_module']) ? ' (M)' : '');
                $gradetext = ($course['grade'] === '' || $course['grade'] === '-' || $course['grade'] === '--') ? '--' : $course['grade'];
                list($lettertext, $letterhex) = cr_letter_cell($course, $design);
                $sheet->setCellValue("A{$r}", $name);
                $sheet->setCellValueExplicit("B{$r}", (int)$course['credits'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $sheet->setCellValue("C{$r}", $course['statusLabel']);
                // Numeric grades stay numeric (sortable in Excel) but keep the two decimals
                // of the PDF; '--' is written as plain text.
                $gradevalue = grade_scale::to_float($course['grade']);
                if ($gradevalue === null) {
                    $sheet->setCellValue("D{$r}", $gradetext);
                } else {
                    $sheet->setCellValueExplicit("D{$r}", $gradevalue, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                    $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode('0.00');
                }
                $sheet->setCellValue("E{$r}", $lettertext);
                $sheet->setCellValue("F{$r}", (string)($course['letterconcept'] ?? ''));
                if ($i % 2 === 1) {
                    $fill("A{$r}:F{$r}", $design['zebra_bg']);
                }
                // Status colour (strip leading # from the hex coming from the builder).
                $statushex = ltrim($course['statusColor'], '#');
                if (strlen($statushex) === 6) {
                    $fontcolor("C{$r}", strtoupper($statushex), true);
                }
                $fontcolor("D{$r}", cr_grade_color($course['grade'], $design), true);
                $fontcolor("E{$r}", $letterhex, true);
                $borderall("A{$r}:F{$r}");
                $sheet->getStyle("B{$r}:E{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $r++;
                $i++;
            }

            // Subtotal row.
            $sheet->setCellValue("A{$r}", 'Subtotal cuatrimestre');
            $sheet->setCellValueExplicit("B{$r}", (int)$sub['total'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->mergeCells("C{$r}:F{$r}");
            $sheet->setCellValue("C{$r}", 'Aprobados: ' . (int)$sub['approved']);
            $fill("A{$r}:F{$r}", $design['subtotal_bg']);
            $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("B{$r}:F{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $borderall("A{$r}:F{$r}");
            $r += 2;
        }

        // Global summary box.
        $s = $career['summary'];
        $idx = $s['index'];
        $sheet->mergeCells("A{$r}:F{$r}");
        $sheet->setCellValue("A{$r}",
            'RESUMEN  —  Créditos aprobados: ' . (int)$s['approved']
            . '  |  En curso: ' . (int)$s['incourse']
            . '  |  Pendientes: ' . (int)$s['pending']
            . '  |  Total del plan: ' . (int)$s['total']
            . '  |  Avance: ' . $s['pct'] . '%'
            . '  |  Índice: ' . $idx['display'] . ' / ' . $idx['scaletext']);
        $fill("A{$r}:F{$r}", $design['summary_bg']);
        $fontcolor("A{$r}:F{$r}", 'FFFFFF', true);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r += 2;
    }

    // ── Closing block: cumulative academic index + scale legend ──────────────
    $gidx = $data['index'];
    $sheet->mergeCells("A{$r}:D{$r}");
    $sheet->setCellValue("A{$r}", 'ÍNDICE ACADÉMICO ACUMULADO');
    $sheet->mergeCells("E{$r}:F{$r}");
    $sheet->setCellValue("E{$r}", $gidx['display'] . ' / ' . $gidx['scaletext']);
    $fill("A{$r}:F{$r}", $design['career_bg']);
    $fontcolor("A{$r}:F{$r}", 'FFFFFF', true);
    $sheet->getStyle("A{$r}")->getFont()->setSize(12);
    $sheet->getStyle("E{$r}")->getFont()->setSize(14);
    $sheet->getStyle("E{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension($r)->setRowHeight(24);
    $r++;
    $sheet->mergeCells("A{$r}:F{$r}");
    $sheet->setCellValue("A{$r}", 'Ponderado por créditos sobre ' . (int)$gidx['courses']
        . ' asignatura(s) con calificación final (' . (int)$gidx['credits'] . ' créditos).'
        . ((int)$gidx['uncredited'] > 0
            ? '  ' . (int)$gidx['uncredited'] . ' asignatura(s) con calificación final no ponderan por no tener créditos definidos en el plan.'
            : ''));
    $fontcolor("A{$r}:F{$r}", $design['grey']);
    $r += 2;

    // Scale legend.
    $sheet->mergeCells("A{$r}:F{$r}");
    $sheet->setCellValue("A{$r}", 'Escala de calificación');
    $fontcolor("A{$r}:F{$r}", $design['cuatri_txt'], true);
    $r++;
    $sheet->setCellValue("A{$r}", 'Calificación numérica');
    $sheet->setCellValue("B{$r}", 'Letras');
    $sheet->mergeCells("C{$r}:D{$r}");
    $sheet->setCellValue("C{$r}", 'Concepto');
    $sheet->mergeCells("E{$r}:F{$r}");
    $sheet->setCellValue("E{$r}", 'Puntos índice');
    $fill("A{$r}:F{$r}", $design['thead_bg']);
    $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
    $borderall("A{$r}:F{$r}");
    $r++;
    foreach ($data['legend'] as $band) {
        $sheet->setCellValue("A{$r}", $band['range']);
        $sheet->setCellValue("B{$r}", $band['letter']);
        $sheet->mergeCells("C{$r}:D{$r}");
        $sheet->setCellValue("C{$r}", $band['concept']);
        $sheet->mergeCells("E{$r}:F{$r}");
        // Written as text so the legend keeps the "3.00" form instead of collapsing to 3.
        $sheet->setCellValueExplicit("E{$r}", $band['points'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $fontcolor("B{$r}", $band['color'], true);
        $borderall("A{$r}:F{$r}");
        $sheet->getStyle("A{$r}:B{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $r++;
    }
    $sheet->mergeCells("A{$r}:F{$r}");
    $sheet->setCellValue("A{$r}", 'Las asignaturas en curso no reciben calificación en letras ni ponderan en el índice hasta el cierre del período.');
    $fontcolor("A{$r}:F{$r}", $design['grey']);

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
