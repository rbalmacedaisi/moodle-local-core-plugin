<?php
/**
 * export_class_pdf.php — Attendance roster PDF for a class.
 *
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
$class     = null;
$cname     = 'Todas las clases';
$teacher   = '';
$shift     = '';
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

// ── Student IDs ───────────────────────────────────────────────────────────────
$userids = ($classid > 0)
    ? absd_get_class_enrolled_userids($classid)
    : [];

if (empty($userids)) {
    print_error('No students found for this class or class not specified.');
}

// ── Sessions ──────────────────────────────────────────────────────────────────
$all_session_ids = ($classid > 0)
    ? absd_get_class_all_session_ids($class)
    : [];

$taken_session_ids = absd_get_taken_session_ids($all_session_ids);
$taken_set         = array_flip($taken_session_ids);
$taken_count       = count($taken_session_ids);

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

// ── Student lookup (name + cedula) ────────────────────────────────────────────
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

// ── Total grades (course final grade) ─────────────────────────────────────────
$grade_map     = [];
$grade_max_val = 100;

$course_id_for_grades = (int)($class->corecourseid ?? $class->courseid ?? 0);
if ($course_id_for_grades > 0) {
    $grade_item = $DB->get_record(
        'grade_items',
        ['courseid' => $course_id_for_grades, 'itemtype' => 'course'],
        '*',
        IGNORE_MISSING
    );
    if ($grade_item) {
        $grade_max_val = (float)($grade_item->grademax ?: 100);
        list($guinsql, $guinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
        $grade_rows = $DB->get_records_sql(
            "SELECT userid, finalgrade
               FROM {grade_grades}
              WHERE itemid = :itemid AND userid $guinsql",
            array_merge(['itemid' => $grade_item->id], $guinparams)
        );
        foreach ($grade_rows as $gr) {
            $grade_map[(int)$gr->userid] = ($gr->finalgrade !== null)
                ? round((float)$gr->finalgrade, 1)
                : null;
        }
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
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
$avg_pct         = $total_expected > 0 ? round($total_absent / $total_expected * 100, 1) : 0.0;

// ── PDF generation ────────────────────────────────────────────────────────────
require_once($CFG->libdir . '/tcpdf/tcpdf.php');

$_tcpdf_font_dir  = $CFG->libdir . '/tcpdf/fonts/';
$_font_candidates = ['dejavusans', 'freesans', 'freeserif', 'dejavuserif', 'helvetica'];
$PDF_FONT         = 'helvetica';
foreach ($_font_candidates as $_fc) {
    if ($_fc === 'helvetica' || file_exists($_tcpdf_font_dir . $_fc . '.php')) {
        $PDF_FONT = $_fc;
        break;
    }
}

define('PDF_CHUNK_SIZE', 20);

$chunks       = array_chunk($all_session_ids, PDF_CHUNK_SIZE);
$total_chunks = count($chunks);

class AttendanceExportPDF extends TCPDF {
    public $hd = [];

    public function Header() {
        $d       = $this->hd;
        $font    = $d['font'] ?? 'dejavusans';
        $pw      = $this->getPageWidth();
        $lm      = $this->getMargins()['left'];
        $rm      = $this->getMargins()['right'];
        $usable  = $pw - $lm - $rm;

        // ── Banner azul ───────────────────────────────────────────────────────
        $this->SetFillColor(22, 58, 158);
        $this->SetXY($lm, 4);
        $this->Cell($usable, 9, '', 0, 0, 'C', true);

        $this->SetXY($lm + 2, 4);
        $this->SetFont($font, 'B', 12);
        $this->SetTextColor(255, 255, 255);
        $this->Cell($usable * 0.55, 9, 'Instituto Superior ISI', 0, 0, 'L', false);

        $this->SetFont($font, '', 8.5);
        $this->Cell($usable * 0.45 - 2, 9, 'Lista de Asistencia', 0, 0, 'R', false);

        // ── Barra acento delgada ──────────────────────────────────────────────
        $this->SetFillColor(56, 189, 248);
        $this->SetXY($lm, 13);
        $this->Cell($usable, 1.2, '', 0, 1, 'C', true);

        // ── Bloque de información ─────────────────────────────────────────────
        $this->SetY($this->GetY() + 1.5);
        $this->SetFont($font, '', 7);
        $this->SetTextColor(15, 23, 42);

        $col = $usable / 2;

        $this->SetX($lm);
        $this->SetFont($font, 'B', 7); $this->Cell(18, 4.5, 'Grupo:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell($col - 18, 4.5, ($d['cname'] ?? ''), 0, 0, 'L');
        $this->SetFont($font, 'B', 7); $this->Cell(18, 4.5, 'Docente:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell($col - 18, 4.5, ($d['teacher'] ?? ''), 0, 1, 'L');

        $this->SetX($lm);
        $this->SetFont($font, 'B', 7); $this->Cell(18, 4.5, 'Turno:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell(30, 4.5, ($d['shift'] ?? ''), 0, 0, 'L');
        $this->SetFont($font, 'B', 7); $this->Cell(16, 4.5, 'Horario:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell($col - 64, 4.5, ($d['schedule'] ?? ''), 0, 0, 'L');
        $this->SetFont($font, 'B', 7); $this->Cell(18, 4.5, 'Impreso:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell($col - 18, 4.5, date('d/m/Y  H:i'), 0, 1, 'L');

        $this->SetX($lm);
        $this->SetFont($font, 'B', 7); $this->Cell(28, 4.5, 'Est. totales:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell(14, 4.5, ($d['total'] ?? 0), 0, 0, 'L');
        $this->SetFont($font, 'B', 7); $this->Cell(22, 4.5, 'Activos:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell(14, 4.5, ($d['active'] ?? 0), 0, 0, 'L');
        $this->SetFont($font, 'B', 7); $this->Cell(22, 4.5, 'Inactivos:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell($col - 100, 4.5, (($d['total'] ?? 0) - ($d['active'] ?? 0)), 0, 0, 'L');
        $this->SetFont($font, 'B', 7); $this->Cell(26, 4.5, 'Sesiones:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell(14, 4.5, ($d['taken'] ?? 0), 0, 0, 'L');
        $this->SetFont($font, 'B', 7); $this->Cell(26, 4.5, '% Inasistencia:', 0, 0, 'L');
        $this->SetFont($font, '',  7); $this->Cell(0, 4.5, ($d['avg_pct'] ?? 0) . '%', 0, 1, 'L');

        // ── Línea separadora ──────────────────────────────────────────────────
        $this->SetDrawColor(22, 58, 158);
        $this->SetLineWidth(0.5);
        $this->Line($lm, $this->GetY() + 1, $pw - $rm, $this->GetY() + 1);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(148, 163, 184);
        $this->Ln(3.5);
    }

    public function Footer() {
        $d      = $this->hd;
        $font   = $d['font'] ?? 'dejavusans';
        $pw     = $this->getPageWidth();
        $lm     = $this->getMargins()['left'];
        $rm     = $this->getMargins()['right'];
        $usable = $pw - $lm - $rm;

        $this->SetY(-11);
        $this->SetDrawColor(22, 58, 158);
        $this->SetLineWidth(0.4);
        $this->Line($lm, $this->GetY(), $pw - $rm, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->Ln(1.5);
        $this->SetFont($font, '', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->SetX($lm);
        $this->Cell($usable * 0.6, 6, 'Instituto Superior ISI — Lista de Asistencia — ' . ($d['cname'] ?? ''), 0, 0, 'L');
        $this->Cell($usable * 0.4, 6, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
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
    'avg_pct'  => $avg_pct,
];
$pdf->SetCreator('ISI Moodle');
$pdf->SetTitle('Asistencia - ' . $cname);
$pdf->SetAutoPageBreak(true, 14);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(8, 46, 8);
$pdf->SetHeaderMargin(4);
$pdf->SetFooterMargin(10);

// ── Column widths (A4 landscape = 297mm, usable ≈ 281mm after 8+8 margins) ───
$numColW  = 7;
$cedColW  = 22;
$nameColW = 50;
$noteColW = 16;
$ausColW  = 12;
$fixedW   = $numColW + $cedColW + $nameColW + $noteColW + $ausColW;

$pageUsable = 297 - 16;
$sessColW   = max(6.5, ($pageUsable - $fixedW) / max(1, PDF_CHUNK_SIZE));

// ── Colour palette ────────────────────────────────────────────────────────────
$C = [
    'hdr_blue'    => [22,  58,  158],
    'hdr_last'    => [37,  99,  235],
    'hdr_untaken' => [100, 116, 139],
    'white'       => [255, 255, 255],
    'present_bg'  => [209, 250, 229],
    'present_fg'  => [6,   95,  70 ],
    'absent_bg'   => [254, 226, 226],
    'absent_fg'   => [153, 27,  27 ],
    'future_bg'   => [241, 245, 249],
    'future_fg'   => [148, 163, 184],
    'row_even'    => [247, 249, 252],
    'row_odd'     => [255, 255, 255],
    'black'       => [15,  23,  42 ],
    'muted'       => [100, 116, 139],
    'note_bg'     => [239, 246, 255],
    'note_fg'     => [22,  58,  158],
    'note_warn'   => [255, 251, 235],
    'note_warn_fg'=> [146, 64,  14 ],
    'note_fail'   => [254, 226, 226],
    'note_fail_fg'=> [153, 27,  27 ],
];

function pdfFill(TCPDF $p, array $rgb): void { $p->SetFillColor($rgb[0], $rgb[1], $rgb[2]); }
function pdfText(TCPDF $p, array $rgb): void { $p->SetTextColor($rgb[0], $rgb[1], $rgb[2]); }

// ── Sort students by name ─────────────────────────────────────────────────────
$sorted_uids = array_keys($student_map);
usort($sorted_uids, function($a, $b) use ($student_map) {
    return strcmp($student_map[$a]['name'], $student_map[$b]['name']);
});

// ── Helper: draw header row ───────────────────────────────────────────────────
$drawHeaderRow = function(
    AttendanceExportPDF $pdf,
    string $pdfFont,
    array $chunk_sids,
    array $session_info,
    int $last_taken_id,
    string $chunkLabel,
    float $numColW, float $cedColW, float $nameColW,
    float $noteColW, float $ausColW, float $sessColW,
    array $C
) {
    $pdf->SetFont($pdfFont, 'B', 7);
    pdfFill($pdf, $C['hdr_blue']); pdfText($pdf, $C['white']);
    $pdf->Cell($numColW,  7, '#',              'LTB', 0, 'C', true);
    $pdf->Cell($cedColW,  7, 'Cédula',         'LTB', 0, 'C', true);
    $pdf->Cell($nameColW, 7, 'Nombre' . $chunkLabel, 'LTB', 0, 'L', true);
    $pdf->Cell($noteColW, 7, 'Nota Total',     'LTB', 0, 'C', true);

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
        $pdf->Cell($sessColW, 7, $info['date'], 'LTB', 0, 'C', true);
    }

    pdfFill($pdf, $C['hdr_blue']); pdfText($pdf, $C['white']);
    $pdf->Cell($ausColW, 7, 'Ausc.', 'LTBR', 0, 'C', true);
    $pdf->Ln();
};

// ── Render chunks ─────────────────────────────────────────────────────────────
foreach ($chunks as $chunkIdx => $chunk_sids) {
    $pdf->AddPage();

    $chunkLabel = $total_chunks > 1 ? '  (bloque ' . ($chunkIdx + 1) . '/' . $total_chunks . ')' : '';

    $drawHeaderRow(
        $pdf, $PDF_FONT, $chunk_sids, $session_info,
        $last_taken_id, $chunkLabel,
        $numColW, $cedColW, $nameColW, $noteColW, $ausColW, $sessColW,
        $C
    );

    $pdf->SetFont($PDF_FONT, '', 6.5);
    $rowNum = 0;

    foreach ($sorted_uids as $uid) {
        $rowNum++;
        $rowBg = ($rowNum % 2 === 0) ? $C['row_even'] : $C['row_odd'];
        $info  = $student_map[$uid] ?? ['name' => '?', 'cedula' => ''];

        // ── Page-break guard ──────────────────────────────────────────────────
        if ($pdf->GetY() + 7.5 > $pdf->getPageHeight() - 14) {
            $pdf->AddPage();
            $drawHeaderRow(
                $pdf, $PDF_FONT, $chunk_sids, $session_info,
                $last_taken_id, $chunkLabel,
                $numColW, $cedColW, $nameColW, $noteColW, $ausColW, $sessColW,
                $C
            );
            $pdf->SetFont($PDF_FONT, '', 6.5);
        }

        // Count absences for this chunk
        $abs_total = 0;
        foreach ($chunk_sids as $sid) {
            $entry = $matrix[$uid][$sid] ?? null;
            if ($entry === null || !$entry['present']) {
                $abs_total++;
            }
        }

        // Retrieve grade
        $grade_val = $grade_map[$uid] ?? null;

        // ── # ─────────────────────────────────────────────────────────────────
        pdfFill($pdf, $rowBg); pdfText($pdf, $C['muted']);
        $pdf->Cell($numColW, 7.5, $rowNum, 'LTB', 0, 'C', true);

        // ── Cédula ────────────────────────────────────────────────────────────
        pdfFill($pdf, $rowBg); pdfText($pdf, $C['black']);
        $pdf->Cell($cedColW, 7.5, $info['cedula'], 'LTB', 0, 'C', true);

        // ── Nombre ────────────────────────────────────────────────────────────
        $pdf->Cell($nameColW, 7.5, $info['name'], 'LTB', 0, 'L', true);

        // ── Nota total ────────────────────────────────────────────────────────
        if ($grade_val === null) {
            pdfFill($pdf, $rowBg); pdfText($pdf, $C['muted']);
            $pdf->SetFont($PDF_FONT, '', 6.5);
            $pdf->Cell($noteColW, 7.5, '—', 'LTB', 0, 'C', true);
        } else {
            $pct = $grade_max_val > 0 ? ($grade_val / $grade_max_val) : 0;
            if ($pct >= 0.7) {
                pdfFill($pdf, $C['present_bg']); pdfText($pdf, $C['present_fg']);
            } elseif ($pct >= 0.5) {
                pdfFill($pdf, $C['note_warn']); pdfText($pdf, $C['note_warn_fg']);
            } else {
                pdfFill($pdf, $C['note_fail']); pdfText($pdf, $C['note_fail_fg']);
            }
            $pdf->SetFont($PDF_FONT, 'B', 7);
            $pdf->Cell($noteColW, 7.5, number_format($grade_val, 1), 'LTB', 0, 'C', true);
            $pdf->SetFont($PDF_FONT, '', 6.5);
        }

        // ── Session cells ─────────────────────────────────────────────────────
        foreach ($chunk_sids as $sid) {
            $entry = $matrix[$uid][$sid] ?? null;
            if ($entry === null || !($entry['has_log'] ?? false)) {
                pdfFill($pdf, $C['future_bg']); pdfText($pdf, $C['future_fg']);
                $pdf->Cell($sessColW, 7.5, '–', 'LTB', 0, 'C', true);
            } elseif ($entry['present']) {
                pdfFill($pdf, $C['present_bg']); pdfText($pdf, $C['present_fg']);
                $pdf->Cell($sessColW, 7.5, 'P', 'LTB', 0, 'C', true);
            } else {
                pdfFill($pdf, $C['absent_bg']); pdfText($pdf, $C['absent_fg']);
                $pdf->Cell($sessColW, 7.5, 'A', 'LTB', 0, 'C', true);
            }
        }

        // ── Total ausencias ───────────────────────────────────────────────────
        if ($abs_total === 0) {
            pdfFill($pdf, $C['present_bg']); pdfText($pdf, $C['present_fg']);
        } elseif ($abs_total <= 2) {
            pdfFill($pdf, $C['note_warn']); pdfText($pdf, $C['note_warn_fg']);
        } else {
            pdfFill($pdf, $C['absent_bg']); pdfText($pdf, $C['absent_fg']);
        }
        $pdf->SetFont($PDF_FONT, 'B', 7.5);
        $pdf->Cell($ausColW, 7.5, $abs_total, 'LTBR', 0, 'C', true);
        $pdf->SetFont($PDF_FONT, '', 6.5);

        $pdf->Ln();
    }
}

$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cname);
$filename = 'Asistencia_' . $safeName . '_' . date('Ymd') . '.pdf';
$pdf->Output($filename, 'D');
exit;
