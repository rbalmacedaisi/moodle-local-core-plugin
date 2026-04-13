<?php
/**
 * export_class_pdf.php — Student roster PDF for a class (attendance + grades).
 *
 * GET params: classid, sesskey, planid, periodid, status, search
 * Output: PDF binary (Content-Disposition: attachment), Letter landscape.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_info.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_sesskey();

// ── Parameters ────────────────────────────────────────────────────────────────
$classid  = optional_param('classid',  0, PARAM_INT);
$planid   = optional_param('planid',   '', PARAM_TEXT);
$periodid = optional_param('periodid', '', PARAM_TEXT);
$status   = optional_param('status',   '', PARAM_TEXT);
$search   = optional_param('search',   '', PARAM_TEXT);

// ── Class info ────────────────────────────────────────────────────────────────
$class    = null;
$cname    = 'Todos los estudiantes';
$teacher  = '';
$shift    = '';
$schedHtml = '';

if ($classid > 0) {
    $class = $DB->get_record_sql(
        "SELECT gc.id,
                gc.name        AS classname,
                gc.shift       AS classshift,
                gc.career_label,
                gc.learningplanid,
                gc.corecourseid,
                gc.courseid,
                gc.attendancemoduleid,
                gc.groupid,
                gc.initdate,
                gc.enddate,
                c.fullname     AS coursefullname,
                CONCAT(u.firstname,' ',u.lastname) AS teachername
           FROM {gmk_class} gc
           LEFT JOIN {course} c ON c.id = gc.corecourseid
           LEFT JOIN {user} u   ON u.id = gc.instructorid
          WHERE gc.id = :classid",
        ['classid' => $classid],
        IGNORE_MISSING
    );

    if ($class) {
        $cname   = trim((string)($class->coursefullname ?: $class->classname));
        $teacher = trim((string)($class->teachername ?? ''));

        $shiftval = strtolower(trim((string)($class->classshift ?? '')));
        if (in_array($shiftval, ['d', 'diurno', 'diurna', 'dia'])) $shift = 'Diurno';
        elseif (in_array($shiftval, ['n', 'nocturno', 'nocturna', 'noche'])) $shift = 'Nocturno';
        elseif (in_array($shiftval, ['s', 'sabatino', 'sabatina'])) $shift = 'Sabatino';
        else $shift = ucfirst($shiftval);

        $schedrows = $DB->get_records('gmk_class_schedules', ['classid' => $classid], 'day ASC');
        $dayMap = ['Lunes'=>'Lun','Martes'=>'Mar','Miercoles'=>'Mie','Miercoles'=>'Mie',
                   'Jueves'=>'Jue','Viernes'=>'Vie','Sabado'=>'Sab','Domingo'=>'Dom'];
        $grouped = [];
        foreach ($schedrows as $r) {
            $start = substr((string)($r->start_time ?? ''), 0, 5);
            $end   = substr((string)($r->end_time   ?? ''), 0, 5);
            $key   = "$start-$end";
            $dl    = $dayMap[(string)($r->day ?? '')] ?? (string)($r->day ?? '');
            $grouped[$key][$dl] = true;
        }
        $parts = [];
        foreach ($grouped as $t => $days) {
            $parts[] = implode('/', array_keys($days)) . ' ' . $t;
        }
        $schedHtml = implode(', ', $parts);
    }
}

// ── Student data ──────────────────────────────────────────────────────────────
$result = \local_grupomakro_core\external\student\get_student_info::execute(
    1,      // page
    9999,   // get all records
    $search,
    $planid,
    $periodid,
    $status,
    $classid,
    ''      // financial_status
);

$raw = $result['dataUsers'] ?? '[]';
if (is_string($raw)) {
    $students = json_decode($raw, true) ?: [];
} else {
    $students = is_array($raw) ? $raw : [];
}

$total_students = count($students);
$active_count   = 0;
$total_absences = 0;
$grades         = [];

foreach ($students as $s) {
    $abs = (int)($s['absences'] ?? 0);
    $total_absences += $abs;
    if ($abs < 3) $active_count++;
    $g = $s['currentgrade'] ?? $s['grade'] ?? '--';
    if ($g !== '--' && is_numeric($g)) {
        $grades[] = (float)$g;
    }
}
$avg_absences = $total_students > 0 ? round($total_absences / $total_students, 1) : 0;
$avg_grade    = count($grades) > 0 ? round(array_sum($grades) / count($grades), 1) : '--';

// ── PDF setup ─────────────────────────────────────────────────────────────────
require_once($CFG->libdir . '/tcpdf/tcpdf.php');

$_font_dir    = $CFG->libdir . '/tcpdf/fonts/';
$_candidates  = ['freeserif', 'freesans', 'dejavusans', 'dejavuserif', 'helvetica'];
$PDF_FONT     = 'helvetica';
foreach ($_candidates as $_fc) {
    if ($_fc === 'helvetica' || file_exists($_font_dir . $_fc . '.php')) {
        $PDF_FONT = $_fc;
        break;
    }
}

// Letter = 215.9 × 279.4 mm  →  landscape: width=279.4, height=215.9
class StudentRosterPDF extends TCPDF {
    public $hd = [];

    public function Header() {
        $d    = $this->hd;
        $font = $d['font'] ?? 'freeserif';
        $lm   = $this->getMargins()['left'];
        $pw   = $this->getPageWidth() - $this->getMargins()['right'];

        // ── Top accent bar ────────────────────────────────────────────
        $this->SetFillColor(30, 64, 175);   // indigo-800
        $this->Rect($lm, 4, $pw - $lm, 1.2, 'F');

        // ── Institution + document type ───────────────────────────────
        $this->SetFont($font, 'B', 11);
        $this->SetTextColor(15, 23, 42);
        $this->SetXY($lm, 7);
        $this->Cell($pw - $lm, 6, 'INSTITUTO SUPERIOR ISI', 0, 0, 'L');

        $this->SetFont($font, '', 7);
        $this->SetTextColor(100, 116, 139);
        $this->SetXY($pw - 50, 7);
        $this->Cell(50, 6, 'Impreso: ' . date('d/m/Y H:i'), 0, 1, 'R');

        // ── Class / section info ──────────────────────────────────────
        $this->SetFont($font, 'B', 8.5);
        $this->SetTextColor(30, 64, 175);
        $this->SetX($lm);
        $this->Cell($pw - $lm, 5, 'Lista de Estudiantes' . ($d['cname'] ? ' - ' . $d['cname'] : ''), 0, 1, 'L');

        $this->SetFont($font, '', 7.5);
        $this->SetTextColor(51, 65, 85);
        $this->SetX($lm);
        $col1w = ($pw - $lm) / 2;

        $leftLine  = ($d['teacher'] ? 'Docente: ' . $d['teacher'] : '') .
                     ($d['shift']   ? '   Jornada: ' . $d['shift'] : '');
        $rightLine = ($d['schedule'] ? 'Horario: ' . $d['schedule'] : '');
        $this->Cell($col1w, 4.5, $leftLine,  0, 0, 'L');
        $this->Cell($col1w, 4.5, $rightLine, 0, 1, 'R');

        // ── Stats strip ───────────────────────────────────────────────
        $this->Ln(1);
        $this->SetFillColor(239, 246, 255);  // blue-50
        $this->SetDrawColor(191, 219, 254);  // blue-200
        $stripY = $this->GetY();
        $stripH = 7;
        $this->Rect($lm, $stripY, $pw - $lm, $stripH, 'DF');

        $stats = [
            ['Total est.',  $d['total']        ?? 0],
            ['Activos',     $d['active']        ?? 0],
            ['Prom. aus.',  $d['avg_abs']       ?? 0],
            ['Prom. nota',  $d['avg_grade']     ?? '--'],
        ];
        $statW = ($pw - $lm) / count($stats);
        $this->SetFont($font, '', 6.5);
        foreach ($stats as $i => [$label, $val]) {
            $x = $lm + $i * $statW;
            $this->SetXY($x + 2, $stripY + 0.8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell($statW - 4, 3, $label, 0, 0, 'C');
            $this->SetXY($x + 2, $stripY + 3.5);
            $this->SetFont($font, 'B', 7.5);
            $this->SetTextColor(30, 64, 175);
            $this->Cell($statW - 4, 3.5, (string)$val, 0, 0, 'C');
            $this->SetFont($font, '', 6.5);
        }

        // Separator line
        $this->SetDrawColor(148, 163, 184);
        $this->Line($lm, $stripY + $stripH + 1, $pw, $stripY + $stripH + 1);
        $this->Ln($stripH + 3);
    }

    public function Footer() {
        $this->SetY(-8);
        $this->SetFont($this->hd['font'] ?? 'freeserif', 'I', 6);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 4, 'Pagina ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new StudentRosterPDF('L', 'mm', 'LETTER', true, 'UTF-8', false);
$pdf->hd = [
    'font'      => $PDF_FONT,
    'cname'     => $cname,
    'teacher'   => $teacher,
    'shift'     => $shift,
    'schedule'  => $schedHtml,
    'total'     => $total_students,
    'active'    => $active_count,
    'avg_abs'   => $avg_absences,
    'avg_grade' => $avg_grade,
];
$pdf->SetCreator('ISI Moodle');
$pdf->SetTitle('Estudiantes - ' . $cname);
$pdf->SetAutoPageBreak(true, 12);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(8, 42, 8);
$pdf->SetHeaderMargin(4);
$pdf->SetFooterMargin(6);
$pdf->SetFont($PDF_FONT, '', 7);
$pdf->AddPage();

// ── Column definitions (Letter landscape usable ~263mm) ───────────────────────
$cols = [
    ['label' => '#',              'w' =>  7,  'align' => 'C'],
    ['label' => 'Cedula / ID',    'w' => 24,  'align' => 'C'],
    ['label' => 'Nombre',         'w' => 56,  'align' => 'L'],
    ['label' => 'Carrera',        'w' => 42,  'align' => 'L'],
    ['label' => 'Periodo',        'w' => 22,  'align' => 'C'],
    ['label' => 'Bloque',         'w' => 18,  'align' => 'C'],
    ['label' => 'Telefono',       'w' => 26,  'align' => 'C'],
    ['label' => 'Inasistencias',  'w' => 19,  'align' => 'C'],
    ['label' => 'Nota',           'w' => 14,  'align' => 'C'],
    ['label' => 'Estado',         'w' => 21,  'align' => 'C'],
];
$ROW_H = 7.5;

// Helper closures
$pdfFill = function(array $rgb) use ($pdf) { $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]); };
$pdfText = function(array $rgb) use ($pdf) { $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]); };

// ── Table header ──────────────────────────────────────────────────────────────
$pdf->SetFont($PDF_FONT, 'B', 7);
$pdfFill([30, 64, 175]);
$pdfText([255, 255, 255]);
foreach ($cols as $col) {
    $pdf->Cell($col['w'], 6.5, $col['label'], 'LTB', 0, 'C', true);
}
$pdf->Ln();

// ── Colour palettes ───────────────────────────────────────────────────────────
$C = [
    'row_even'   => [249, 250, 251],
    'row_odd'    => [255, 255, 255],
    'black'      => [15,  23, 42],
    'muted'      => [100, 116, 139],

    // Absence colours
    'abs_ok'     => [240, 253, 244],  // green-50
    'abs_ok_t'   => [21,  128, 61],   // green-700
    'abs_warn'   => [255, 251, 235],  // amber-50
    'abs_warn_t' => [146, 64,  14],   // amber-800
    'abs_crit'   => [254, 242, 242],  // red-50
    'abs_crit_t' => [185, 28,  28],   // red-700

    // Grade colours
    'grade_hi'   => [240, 253, 244],
    'grade_hi_t' => [21,  128, 61],
    'grade_mid'  => [255, 251, 235],
    'grade_mid_t'=> [146, 64,  14],
    'grade_lo'   => [254, 242, 242],
    'grade_lo_t' => [185, 28,  28],

    // Status colours
    'st_activo'  => [220, 252, 231],
    'st_act_t'   => [21,  128, 61],
    'st_inact'   => [254, 226, 226],
    'st_inact_t' => [185, 28,  28],
    'st_susp'    => [254, 215, 170],
    'st_susp_t'  => [154, 52,  18],
    'st_other'   => [241, 245, 249],
    'st_other_t' => [51,  65,  85],
];

// ── Data rows ─────────────────────────────────────────────────────────────────
$pdf->SetFont($PDF_FONT, '', 6.5);
$rowNum = 0;

foreach ($students as $s) {
    $rowNum++;
    $rowBg = ($rowNum % 2 === 0) ? $C['row_even'] : $C['row_odd'];

    // Career / period / subperiod — take first career entry
    $careers  = $s['careers'] ?? [];
    $career1  = !empty($careers) ? ($careers[0]['career'] ?? '') : '';
    $period1  = !empty($careers) ? ($careers[0]['periodname'] ?? '') : '';
    $subperiod = trim((string)($s['subperiods'] ?? '--'));

    $absences  = (int)($s['absences'] ?? 0);
    $gradeRaw  = $s['currentgrade'] ?? $s['grade'] ?? '--';
    $gradeNum  = is_numeric($gradeRaw) ? (float)$gradeRaw : null;
    $gradeDisp = $gradeNum !== null ? number_format($gradeNum, 1) : '--';
    $status    = trim((string)($s['status'] ?? 'Activo'));
    $phone     = trim((string)($s['phone'] ?? '--'));
    $cedula    = trim((string)($s['documentnumber'] ?? ''));

    // ── Row height check — auto-page-break ────────────────────────────
    if ($pdf->GetY() + $ROW_H > $pdf->getPageHeight() - 12) {
        $pdf->AddPage();
        // Reprint table header
        $pdf->SetFont($PDF_FONT, 'B', 7);
        $pdfFill([30, 64, 175]); $pdfText([255, 255, 255]);
        foreach ($cols as $col) {
            $pdf->Cell($col['w'], 6.5, $col['label'], 'LTB', 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont($PDF_FONT, '', 6.5);
    }

    // Column: #
    $pdfFill($rowBg); $pdfText($C['black']);
    $pdf->Cell($cols[0]['w'], $ROW_H, $rowNum, 'LTB', 0, 'C', true);

    // Column: Cedula
    $pdf->Cell($cols[1]['w'], $ROW_H, $cedula, 'LTB', 0, 'C', true);

    // Column: Nombre
    $name = trim((string)($s['nameuser'] ?? ''));
    $pdf->Cell($cols[2]['w'], $ROW_H, $name, 'LTB', 0, 'L', true);

    // Column: Carrera
    $pdf->SetFont($PDF_FONT, '', 6);
    $pdf->Cell($cols[3]['w'], $ROW_H, $career1, 'LTB', 0, 'L', true);
    $pdf->SetFont($PDF_FONT, '', 6.5);

    // Column: Periodo
    $pdf->Cell($cols[4]['w'], $ROW_H, $period1, 'LTB', 0, 'C', true);

    // Column: Bloque
    $pdf->Cell($cols[5]['w'], $ROW_H, $subperiod, 'LTB', 0, 'C', true);

    // Column: Telefono
    $pdf->Cell($cols[6]['w'], $ROW_H, $phone, 'LTB', 0, 'C', true);

    // Column: Inasistencias — colour by threshold
    if ($absences === 0) {
        $pdfFill($C['abs_ok']);  $pdfText($C['abs_ok_t']);
    } elseif ($absences <= 2) {
        $pdfFill($C['abs_warn']); $pdfText($C['abs_warn_t']);
    } else {
        $pdfFill($C['abs_crit']); $pdfText($C['abs_crit_t']);
    }
    $pdf->SetFont($PDF_FONT, 'B', 7);
    $pdf->Cell($cols[7]['w'], $ROW_H, (string)$absences, 'LTB', 0, 'C', true);
    $pdf->SetFont($PDF_FONT, '', 6.5);

    // Column: Nota — colour by grade
    if ($gradeNum === null) {
        $pdfFill($rowBg); $pdfText($C['muted']);
    } elseif ($gradeNum >= 70) {
        $pdfFill($C['grade_hi']); $pdfText($C['grade_hi_t']);
    } elseif ($gradeNum >= 60) {
        $pdfFill($C['grade_mid']); $pdfText($C['grade_mid_t']);
    } else {
        $pdfFill($C['grade_lo']); $pdfText($C['grade_lo_t']);
    }
    $pdf->SetFont($PDF_FONT, 'B', 7);
    $pdf->Cell($cols[8]['w'], $ROW_H, $gradeDisp, 'LTB', 0, 'C', true);
    $pdf->SetFont($PDF_FONT, '', 6.5);

    // Column: Estado — colour by status
    $stLower = strtolower($status);
    if ($stLower === 'activo') {
        $pdfFill($C['st_activo']); $pdfText($C['st_act_t']);
    } elseif (in_array($stLower, ['inactivo', 'retirado'])) {
        $pdfFill($C['st_inact']); $pdfText($C['st_inact_t']);
    } elseif ($stLower === 'suspendido') {
        $pdfFill($C['st_susp']); $pdfText($C['st_susp_t']);
    } else {
        $pdfFill($C['st_other']); $pdfText($C['st_other_t']);
    }
    $pdf->SetFont($PDF_FONT, 'B', 6.5);
    $pdf->Cell($cols[9]['w'], $ROW_H, strtoupper(substr($status, 0, 10)), 'LTBR', 0, 'C', true);
    $pdf->SetFont($PDF_FONT, '', 6.5);
    $pdfFill($rowBg); $pdfText($C['black']);

    $pdf->Ln();
}

// ── Legend ────────────────────────────────────────────────────────────────────
$pdf->Ln(2);
$pdf->SetFont($PDF_FONT, 'I', 5.5);
$pdfText([148, 163, 184]);
$legend = [
    'Inasistencias: verde = 0  |  amarillo = 1-2  |  rojo >= 3',
    'Nota: verde >= 70  |  amarillo 60-69  |  rojo < 60',
];
foreach ($legend as $line) {
    $pdf->Cell(0, 3.5, $line, 0, 1, 'C');
}

// ── Output ────────────────────────────────────────────────────────────────────
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cname);
$filename = 'Estudiantes_' . $safeName . '_' . date('Ymd') . '.pdf';
$pdf->Output($filename, 'D');
exit;
