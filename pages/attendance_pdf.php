<?php
/**
 * attendance_pdf.php — Downloadable attendance grid PDF for a class.
 *
 * GET params: classid (int), sesskey (string)
 * Output: PDF binary (Content-Disposition: attachment)
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/absence_helpers.php');

require_login();
require_sesskey();

$classid = required_param('classid', PARAM_INT);

// ── Load class record ──────────────────────────────────────────────────────────
$class = $DB->get_record_sql(
    "SELECT gc.id,
            gc.name           AS classname,
            gc.shift          AS classshift,
            gc.career_label,
            gc.learningplanid,
            gc.corecourseid,
            gc.courseid,
            gc.attendancemoduleid,
            gc.groupid,
            gc.initdate,
            gc.enddate,
            c.fullname        AS coursefullname,
            CONCAT(u.firstname,' ',u.lastname) AS teachername
       FROM {gmk_class} gc
       LEFT JOIN {course} c ON c.id = gc.corecourseid
       LEFT JOIN {user} u   ON u.id = gc.instructorid
      WHERE gc.id = :classid",
    ['classid' => $classid],
    MUST_EXIST
);

$cname    = trim((string)($class->coursefullname ?: $class->classname));
$shift    = absd_normalize_shift((string)($class->classshift ?? ''));
$teacher  = trim((string)($class->teachername ?? ''));
$schedules = $DB->get_records('gmk_class_schedules', ['classid' => $classid], 'day ASC');
$schedHtml = absd_format_schedule(array_values($schedules));

// ── Sessions ──────────────────────────────────────────────────────────────────
$all_session_ids    = absd_get_class_all_session_ids($class);
$taken_session_ids  = absd_get_taken_session_ids($all_session_ids);
$taken_set          = array_flip($taken_session_ids);
$now                = time();

// Session detail (date/time)
$session_info = [];
if (!empty($all_session_ids)) {
    list($sinsql, $sparams) = $DB->get_in_or_equal($all_session_ids, SQL_PARAMS_NAMED, 'pdfs');
    $sess_rows = $DB->get_records_sql(
        "SELECT id, sessdate FROM {attendance_sessions} WHERE id $sinsql ORDER BY sessdate ASC",
        $sparams
    );
    foreach ($sess_rows as $sr) {
        $session_info[(int)$sr->id] = [
            'date'  => date('d/m', (int)$sr->sessdate),
            'ts'    => (int)$sr->sessdate,
            'taken' => isset($taken_set[(int)$sr->id]),
        ];
    }
}

// Sort all session ids by date (ascending)
$all_session_ids_sorted = array_keys($session_info);
usort($all_session_ids_sorted, function($a, $b) use ($session_info) {
    return ($session_info[$a]['ts'] ?? 0) - ($session_info[$b]['ts'] ?? 0);
});

// Last taken session (max sessdate among taken)
$last_taken_id = 0;
$last_taken_ts = 0;
foreach ($taken_session_ids as $tsid) {
    $ts = $session_info[$tsid]['ts'] ?? 0;
    if ($ts > $last_taken_ts) {
        $last_taken_ts = $ts;
        $last_taken_id = $tsid;
    }
}

// ── Students ──────────────────────────────────────────────────────────────────
$userids = absd_get_class_enrolled_userids($classid);
$students = [];
if (!empty($userids)) {
    list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'pdfu');
    $user_rows = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, uid.data AS cedula
           FROM {user} u
           LEFT JOIN {user_info_data} uid ON uid.userid = u.id
             AND uid.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'cedula' ORDER BY id LIMIT 1)
          WHERE u.id $uinsql
          ORDER BY u.lastname ASC, u.firstname ASC",
        $uinparams
    );
    foreach ($user_rows as $ur) {
        $students[(int)$ur->id] = [
            'name'   => trim($ur->firstname . ' ' . $ur->lastname),
            'cedula' => trim((string)($ur->cedula ?? '')),
        ];
    }
}

// Sort students by name
$student_order = array_keys($students);
usort($student_order, function($a, $b) use ($students) {
    return strcmp($students[$a]['name'], $students[$b]['name']);
});

// ── Attendance matrix (taken sessions only) ───────────────────────────────────
$matrix = absd_get_student_session_matrix($taken_session_ids, $userids);

// ── Stats ─────────────────────────────────────────────────────────────────────
$total_students   = count($userids);
$taken_count      = count($taken_session_ids);
$absent_students  = 0;
$total_present_all = 0;

foreach ($userids as $uid) {
    $abs = 0;
    foreach ($taken_session_ids as $sid) {
        $entry = $matrix[$uid][$sid] ?? null;
        if ($entry !== null && $entry['present']) {
            $total_present_all++;
        } else {
            $abs++;
        }
    }
    if ($abs >= 3) {
        $absent_students++;
    }
}
$active_students = $total_students - $absent_students;
$total_expected  = $taken_count * $total_students;
$total_absent_all = $total_expected - $total_present_all;
$avg_pct = $total_expected > 0 ? round($total_absent_all / $total_expected * 100, 1) : 0.0;

// ── PDF generation ────────────────────────────────────────────────────────────
require_once($CFG->libdir . '/tcpdf/tcpdf.php');

// Resolve best available unicode font (Moodle bundles vary across versions).
$_tcpdf_font_dir = $CFG->libdir . '/tcpdf/fonts/';
$_font_candidates = ['freeserif', 'freesans', 'dejavusans', 'dejavuserif', 'helvetica'];
$PDF_FONT = 'helvetica'; // always available as core PDF font (no file needed)
foreach ($_font_candidates as $_fc) {
    if ($_fc === 'helvetica' || file_exists($_tcpdf_font_dir . $_fc . '.php')) {
        $PDF_FONT = $_fc;
        break;
    }
}

define('PDF_CHUNK_SIZE', 25); // max session columns per page pass

$chunks = !empty($all_session_ids_sorted)
    ? array_chunk($all_session_ids_sorted, PDF_CHUNK_SIZE)
    : [[]];
$total_chunks = count($chunks);

// Custom TCPDF with fixed header.
class AttendancePDF extends TCPDF {
    public $hd = []; // header data including 'font' key

    public function Header() {
        $d = $this->hd;
        $font = $d['font'] ?? 'freeserif';
        $this->SetFont($font, 'B', 9);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 6, 'Instituto Superior ISI - Lista de Asistencia', 0, 1, 'C');

        $this->SetFont($font, '', 7.5);
        $this->SetTextColor(51, 65, 85);
        $this->Cell(130, 4.5, 'Grupo: ' . ($d['cname'] ?? '') . ' - ' . ($d['shift'] ?? ''), 0, 0);
        $this->Cell(0,   4.5, 'Docente: ' . ($d['teacher'] ?? ''), 0, 1);
        $this->Cell(130, 4.5, 'Horario: ' . ($d['schedule'] ?? ''), 0, 0);
        $this->Cell(0,   4.5, 'Impreso: ' . date('d/m/Y H:i'), 0, 1);
        $this->Cell(130, 4.5,
            'Est. totales: ' . ($d['total'] ?? 0) .
            '   Activos (<3 aus.): ' . ($d['active'] ?? 0) .
            '   Inactivos (>=3 aus.): ' . (($d['total'] ?? 0) - ($d['active'] ?? 0)),
            0, 0
        );
        $this->Cell(0, 4.5,
            '% promedio inasistencia: ' . ($d['avg_pct'] ?? 0) . '%' .
            '   Sesiones tomadas: ' . ($d['taken'] ?? 0),
            0, 1
        );
        $this->SetDrawColor(148, 163, 184);
        $this->Line(
            $this->getMargins()['left'],
            $this->GetY() + 1,
            $this->getPageWidth() - $this->getMargins()['right'],
            $this->GetY() + 1
        );
        $this->Ln(3);
    }
}

$pdf = new AttendancePDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->hd = [
    'font'     => $PDF_FONT,
    'cname'    => $cname,
    'shift'    => $shift,
    'teacher'  => $teacher,
    'schedule' => $schedHtml,
    'total'    => $total_students,
    'active'   => $active_students,
    'taken'    => $taken_count,
    'avg_pct'  => $avg_pct,
];
$pdf->SetCreator('ISI Moodle');
$pdf->SetTitle('Asistencia - ' . $cname);
$pdf->SetAutoPageBreak(true, 12);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(false);
$pdf->SetMargins(8, 40, 8);
$pdf->SetHeaderMargin(4);

// Column widths (landscape A4 = 297mm, usable ≈ 281mm after margins)
$numColW   = 7;   // # column
$cedColW   = 24;  // cédula
$nameColW  = 58;  // name
$ausColW   = 11;  // total absences
$fixedW    = $numColW + $cedColW + $nameColW + $ausColW;
$pageUsable = 297 - 16; // minus left+right margins
$sessColW  = max(6, min(10, (int)(($pageUsable - $fixedW) / max(1, min(PDF_CHUNK_SIZE, count($all_session_ids_sorted))))));

// Color helper: pass array to SetFillColor
function pdfFill(TCPDF $p, array $rgb): void {
    $p->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
}
function pdfText(TCPDF $p, array $rgb): void {
    $p->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
}

$C = [
    'hdr_blue'    => [30,  64, 175],
    'hdr_last'    => [37,  99, 235],
    'hdr_untaken' => [100, 116, 139],
    'white'       => [255, 255, 255],
    'present_bg'  => [220, 252, 231],
    'present_fg'  => [22,  101, 52],
    'absent_bg'   => [254, 226, 226],
    'absent_fg'   => [153,  27, 27],
    'future_bg'   => [241, 245, 249],
    'future_fg'   => [148, 163, 184],
    'row_even'    => [249, 250, 251],
    'row_odd'     => [255, 255, 255],
    'black'       => [15,  23, 42],
    'muted'       => [100, 116, 139],
    'abs_high_bg' => [254, 226, 226],
    'abs_high_fg' => [153,  27, 27],
];

foreach ($chunks as $chunkIdx => $chunk_sids) {
    $pdf->AddPage();

    $chunkLabel = $total_chunks > 1 ? '  (bloque ' . ($chunkIdx + 1) . '/' . $total_chunks . ')' : '';

    // ── Header row ──────────────────────────────────────────────────────
    $pdf->SetFont($PDF_FONT, 'B', 7);
    pdfFill($pdf, $C['hdr_blue']); pdfText($pdf, $C['white']);
    $pdf->Cell($numColW,  6, '#',      'LTB', 0, 'C', true);
    $pdf->Cell($cedColW,  6, 'Cedula', 'LTB', 0, 'C', true);
    $pdf->Cell($nameColW, 6, 'Nombre' . $chunkLabel, 'LTB', 0, 'L', true);

    foreach ($chunk_sids as $sid) {
        $info = $session_info[$sid] ?? ['date' => '?', 'taken' => false];
        if ($sid === $last_taken_id) {
            pdfFill($pdf, $C['hdr_last']);
        } elseif ($info['taken']) {
            pdfFill($pdf, $C['hdr_blue']);
        } else {
            pdfFill($pdf, $C['hdr_untaken']);
        }
        pdfText($pdf, $C['white']);
        $pdf->Cell($sessColW, 6, $info['date'], 'LTB', 0, 'C', true);
    }
    pdfFill($pdf, $C['hdr_blue']); pdfText($pdf, $C['white']);
    $pdf->Cell($ausColW, 6, 'Aus.', 'LTBR', 1, 'C', true);

    // ── Data rows ────────────────────────────────────────────────────────
    $pdf->SetFont($PDF_FONT, '', 6.5);
    $rowNum = 0;
    foreach ($student_order as $uid) {
        $s = $students[$uid] ?? ['name' => '', 'cedula' => ''];
        $rowNum++;
        $rowBg = ($rowNum % 2 === 0) ? $C['row_even'] : $C['row_odd'];

        // Count total absences (taken sessions only)
        $absCount = 0;
        foreach ($taken_session_ids as $tsid) {
            $entry = $matrix[$uid][$tsid] ?? null;
            if ($entry === null || !$entry['present']) {
                $absCount++;
            }
        }

        pdfFill($pdf, $rowBg); pdfText($pdf, $C['black']);
        $pdf->Cell($numColW,  5, $rowNum,     'LTB', 0, 'C', true);
        $pdf->Cell($cedColW,  5, $s['cedula'], 'LTB', 0, 'C', true);
        $pdf->Cell($nameColW, 5, $s['name'],   'LTB', 0, 'L', true);

        foreach ($chunk_sids as $sid) {
            $info = $session_info[$sid] ?? ['taken' => false];
            if (!$info['taken']) {
                pdfFill($pdf, $C['future_bg']); pdfText($pdf, $C['future_fg']);
                $pdf->Cell($sessColW, 5, '-', 'LTB', 0, 'C', true);
            } else {
                $entry = $matrix[$uid][$sid] ?? null;
                if ($entry !== null && $entry['present']) {
                    pdfFill($pdf, $C['present_bg']); pdfText($pdf, $C['present_fg']);
                    $pdf->Cell($sessColW, 5, 'P', 'LTB', 0, 'C', true);
                } else {
                    pdfFill($pdf, $C['absent_bg']); pdfText($pdf, $C['absent_fg']);
                    $pdf->Cell($sessColW, 5, 'F', 'LTB', 0, 'C', true);
                }
            }
        }

        if ($absCount >= 3) {
            pdfFill($pdf, $C['abs_high_bg']); pdfText($pdf, $C['abs_high_fg']);
        } else {
            pdfFill($pdf, $rowBg); pdfText($pdf, $C['black']);
        }
        $pdf->Cell($ausColW, 5, $absCount, 'LTBR', 1, 'C', true);
    }

    // ── Legend note ──────────────────────────────────────────────────────
    $pdf->Ln(2);
    $pdf->SetFont($PDF_FONT, 'I', 5.5);
    pdfText($pdf, $C['muted']); pdfFill($pdf, $C['white']);
    $legendParts = [
        'P = Presente',
        'F = Falta/Ausente',
        '- = Sesion no tomada aun',
        'Columna azul oscuro = ultima sesion tomada',
    ];
    $pdf->Cell(0, 4, implode('   ', $legendParts), 0, 1, 'C');
}

// ── Output ────────────────────────────────────────────────────────────────────
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cname);
$filename = 'Asistencia_' . $safeName . '_' . date('Ymd') . '.pdf';
$pdf->Output($filename, 'D');
exit;
