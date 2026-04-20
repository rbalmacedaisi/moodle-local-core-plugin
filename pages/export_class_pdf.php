<?php
/**
 * export_class_pdf.php — Attendance roster PDF for a class.
 *
 * Uses the same helpers and structure as attendance_pdf.php for consistency.
 * GET params: classid, sesskey, planid, periodid, status, search
 * Output: PDF binary (Content-Disposition: attachment), A4 landscape.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/absence_helpers.php');
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
$cname    = 'Todas las clases';
$teacher  = '';
$shift    = '';
$schedHtml = '';

if ($classid > 0) {
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
        IGNORE_MISSING
    );

    if ($class) {
        $cname   = trim((string)($class->coursefullname ?: $class->classname));
        $teacher = trim((string)($class->teachername ?? ''));
        $shift   = absd_normalize_shift((string)($class->classshift ?? ''));

        $schedrows = $DB->get_records('gmk_class_schedules', ['classid' => $classid], 'day ASC');
        $schedHtml = absd_format_schedule(array_values($schedrows));
    }
}

// ── Student IDs via the same helper used by attendance_pdf.php ────────────────
$userids = ($classid > 0)
    ? absd_get_class_enrolled_userids($classid)
    : [];

if (empty($userids)) {
    print_error('No students found for this class or class not specified.');
}

// ── Sessions ─────────────────────────────────────────────────────────────────
$all_session_ids = ($classid > 0)
    ? absd_get_class_all_session_ids($class)
    : [];

$taken_session_ids  = absd_get_taken_session_ids($all_session_ids);
$taken_set          = array_flip($taken_session_ids);
$taken_count        = count($taken_session_ids);

$all_session_ids = array_values(array_filter($all_session_ids, function($sid) use ($taken_set) {
    return isset($taken_set[$sid]);
}));
sort($all_session_ids);

$session_info = [];
if (!empty($all_session_ids)) {
    list($sinsql, $sparams) = $DB->get_in_or_equal($all_session_ids, SQL_PARAMS_NAMED, 'sess');
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

$last_taken_id = 0;
$last_taken_ts = 0;
foreach ($taken_session_ids as $tsid) {
    $ts = $session_info[$tsid]['ts'] ?? 0;
    if ($ts > $last_taken_ts) {
        $last_taken_ts = $ts;
        $last_taken_id = $tsid;
    }
}

// ── Attendance matrix ─────────────────────────────────────────────────────────
$matrix = !empty($taken_session_ids) && !empty($userids)
    ? absd_get_student_session_matrix($taken_session_ids, $userids)
    : [];

// ── Build student lookup (name + cedula) ──────────────────────────────────────
$student_map = [];
if (!empty($userids)) {
    list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
    $user_rows = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.idnumber
           FROM {user} u
          WHERE u.id $uinsql
       ORDER BY u.lastname ASC, u.firstname ASC",
        $uinparams
    );
    foreach ($user_rows as $ur) {
        $student_map[(int)$ur->id] = [
            'name'   => trim($ur->firstname . ' ' . $ur->lastname),
            'cedula' => trim((string)$ur->idnumber),
        ];
    }

    $_pdf_docfid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']) ?: 0;
    if ($_pdf_docfid) {
        list($uinsql2, $uinparams2) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u2');
        $doc_rows = $DB->get_records_sql(
            "SELECT userid, data
               FROM {user_info_data}
              WHERE fieldid = :fid AND userid $uinsql2",
            array_merge(['fid' => $_pdf_docfid], $uinparams2)
        );
        foreach ($doc_rows as $dr) {
            $v = trim((string)$dr->data);
            if ($v !== '' && isset($student_map[(int)$dr->userid])) {
                $student_map[(int)$dr->userid]['cedula'] = $v;
            }
        }
    }
}

// ── Stats ──────────────────────────────────────────────────────────────────────
$total_students  = count($userids);
$absent_students = 0;
$total_present   = 0;

foreach ($userids as $uid) {
    $abs = 0;
    foreach ($taken_session_ids as $sid) {
        $entry = $matrix[$uid][$sid] ?? null;
        if ($entry === null || !$entry['present']) {
            $abs++;
        } else {
            $total_present++;
        }
    }
    if ($abs >= 3) $absent_students++;
}
$active_students = $total_students - $absent_students;
$total_expected  = $taken_count * $total_students;
$total_absent    = $total_expected - $total_present;
$avg_pct          = $total_expected > 0 ? round($total_absent / $total_expected * 100, 1) : 0.0;

// ── PDF generation ────────────────────────────────────────────────────────────
require_once($CFG->libdir . '/tcpdf/tcpdf.php');

$_tcpdf_font_dir = $CFG->libdir . '/tcpdf/fonts/';
$_font_candidates = ['freeserif', 'freesans', 'dejavusans', 'dejavuserif', 'helvetica'];
$PDF_FONT = 'helvetica';
foreach ($_font_candidates as $_fc) {
    if ($_fc === 'helvetica' || file_exists($_tcpdf_font_dir . $_fc . '.php')) {
        $PDF_FONT = $_fc;
        break;
    }
}

define('PDF_CHUNK_SIZE', 20);

$chunks      = array_chunk($all_session_ids, PDF_CHUNK_SIZE);
$total_chunks = count($chunks);

class AttendanceExportPDF extends TCPDF {
    public $hd = [];

    public function Header() {
        $d    = $this->hd;
        $font = $d['font'] ?? 'freeserif';
        $lm   = $this->getMargins()['left'];
        $pw   = $this->getPageWidth() - $this->getMargins()['right'];

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

$pdf = new AttendanceExportPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->hd = [
    'font'     => $PDF_FONT,
    'cname'    => $cname,
    'shift'    => $shift,
    'teacher'  => $teacher,
    'schedule' => $schedHtml,
    'total'    => $total_students,
    'active'   => $active_students,
    'taken'    => $taken_count,
    'avg_pct'   => $avg_pct,
];
$pdf->SetCreator('ISI Moodle');
$pdf->SetTitle('Asistencia - ' . $cname);
$pdf->SetAutoPageBreak(true, 12);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(false);
$pdf->SetMargins(8, 40, 8);
$pdf->SetHeaderMargin(4);

// Column widths (landscape A4 = 297mm, usable ≈ 281mm after margins)
$numColW   = 7;
$cedColW   = 24;
$nameColW  = 55;
$ausColW   = 12;
$fixedW    = $numColW + $cedColW + $nameColW + $ausColW;
$pageUsable = 297 - 16;
$sessColW  = max(6, min(10, (int)(($pageUsable - $fixedW) / max(1, min(PDF_CHUNK_SIZE, count($all_session_ids))))));

// Colours
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
];

function pdfFill(TCPDF $p, array $rgb): void { $p->SetFillColor($rgb[0], $rgb[1], $rgb[2]); }
function pdfText(TCPDF $p, array $rgb): void { $p->SetTextColor($rgb[0], $rgb[1], $rgb[2]); }

// Sort student IDs by name
$sorted_uids = array_keys($student_map);
usort($sorted_uids, function($a, $b) use ($student_map) {
    return strcmp($student_map[$a]['name'], $student_map[$b]['name']);
});

foreach ($chunks as $chunkIdx => $chunk_sids) {
    $pdf->AddPage();

    $chunkLabel = $total_chunks > 1 ? '  (bloque ' . ($chunkIdx + 1) . '/' . $total_chunks . ')' : '';

    // ── Header row ──────────────────────────────────────────────────────
    $pdf->SetFont($PDF_FONT, 'B', 7);
    pdfFill($pdf, $C['hdr_blue']); pdfText($pdf, $C['white']);
    $pdf->Cell($numColW,  6, '#',       'LTB', 0, 'C', true);
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
    $pdf->Cell($ausColW, 6, 'Ausc.', 'LTB', 0, 'C', true);
    $pdf->Ln();

    // ── Data rows ────────────────────────────────────────────────────────
    $pdf->SetFont($PDF_FONT, '', 6.5);
    $rowNum = 0;

    foreach ($sorted_uids as $uid) {
        $rowNum++;
        $rowBg = ($rowNum % 2 === 0) ? $C['row_even'] : $C['row_odd'];
        $info  = $student_map[$uid] ?? ['name' => '?', 'cedula' => ''];

        // ── Row height check ──────────────────────────────────────────
        if ($pdf->GetY() + 7 > $pdf->getPageHeight() - 12) {
            $pdf->AddPage();
            $pdf->SetFont($PDF_FONT, 'B', 7);
            pdfFill($pdf, $C['hdr_blue']); pdfText($pdf, $C['white']);
            $pdf->Cell($numColW,  6, '#',       'LTB', 0, 'C', true);
            $pdf->Cell($cedColW,  6, 'Cedula', 'LTB', 0, 'C', true);
            $pdf->Cell($nameColW, 6, 'Nombre' . $chunkLabel, 'LTB', 0, 'L', true);
            foreach ($chunk_sids as $sid) {
                $sinfo = $session_info[$sid] ?? ['date' => '?', 'taken' => false];
                if ($sid === $last_taken_id) {
                    pdfFill($pdf, $C['hdr_last']);
                } elseif ($sinfo['taken']) {
                    pdfFill($pdf, $C['hdr_blue']);
                } else {
                    pdfFill($pdf, $C['hdr_untaken']);
                }
                pdfText($pdf, $C['white']);
                $pdf->Cell($sessColW, 6, $sinfo['date'], 'LTB', 0, 'C', true);
            }
            pdfFill($pdf, $C['hdr_blue']); pdfText($pdf, $C['white']);
            $pdf->Cell($ausColW, 6, 'Ausc.', 'LTB', 0, 'C', true);
            $pdf->Ln();
            $pdf->SetFont($PDF_FONT, '', 6.5);
        }

        $abs_total = 0;
        foreach ($chunk_sids as $sid) {
            $entry = $matrix[$uid][$sid] ?? null;
            if ($entry === null || !$entry['present']) {
                $abs_total++;
            }
        }

        // ── # ────────────────────────────────────────────────────────
        pdfFill($pdf, $rowBg); pdfText($pdf, $C['black']);
        $pdf->Cell($numColW, 7, $rowNum, 'LTB', 0, 'C', true);

        // ── Cedula ────────────────────────────────────────────────────
        $pdf->Cell($cedColW, 7, $info['cedula'], 'LTB', 0, 'C', true);

        // ── Nombre ──────────────────────────────────────────────────
        $pdf->Cell($nameColW, 7, $info['name'], 'LTB', 0, 'L', true);

        // ── Session cells ─────────────────────────────────────────────
        foreach ($chunk_sids as $sid) {
            $entry = $matrix[$uid][$sid] ?? null;
            if ($entry === null || !($entry['has_log'] ?? false)) {
                pdfFill($pdf, $C['future_bg']); pdfText($pdf, $C['future_fg']);
                $pdf->Cell($sessColW, 7, '-', 'LTB', 0, 'C', true);
            } elseif ($entry['present']) {
                pdfFill($pdf, $C['present_bg']); pdfText($pdf, $C['present_fg']);
                $pdf->Cell($sessColW, 7, 'P', 'LTB', 0, 'C', true);
            } else {
                pdfFill($pdf, $C['absent_bg']); pdfText($pdf, $C['absent_fg']);
                $pdf->Cell($sessColW, 7, 'A', 'LTB', 0, 'C', true);
            }
        }

        // ── Total absences ──────────────────────────────────────────
        if ($abs_total === 0) {
            pdfFill($pdf, $C['present_bg']); pdfText($pdf, $C['present_fg']);
        } elseif ($abs_total <= 2) {
            pdfFill($pdf, [255, 251, 235]); pdfText($pdf, [146, 64, 14]);
        } else {
            pdfFill($pdf, $C['absent_bg']); pdfText($pdf, $C['absent_fg']);
        }
        $pdf->SetFont($PDF_FONT, 'B', 7);
        $pdf->Cell($ausColW, 7, $abs_total, 'LTB', 0, 'C', true);
        $pdf->SetFont($PDF_FONT, '', 6.5);

        $pdf->Ln();
    }
}

$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cname);
$filename = 'Asistencia_' . $safeName . '_' . date('Ymd') . '.pdf';
$pdf->Output($filename, 'D');
exit;
