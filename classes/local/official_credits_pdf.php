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
 * Official credits certificate ("Certificado de Estudios — Créditos", Serie A).
 *
 * Reproduces the institution's printed form on a Legal page: watermark, header,
 * photo box, subject table, weighting/index rows, signature lines and notes.
 * Only subjects with a final grade on record are listed. Coordinates are in mm
 * and were taken from the scanned form, so keep them together when editing.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Renders the official credits certificate with TCPDF.
 */
class official_credits_pdf {

    /** @var string Watermark phrase repeated diagonally across the page. */
    const WATERMARK = 'INSTITUTO SUPERIOR DE INGENIERÍA';

    /** @var float[] Column x positions of the subject table (left edge .. right edge). */
    const COLS = [6.0, 30.0, 117.9, 140.8, 160.1, 182.8, 209.7];

    /** @var float Table header top / body top / body bottom / weighting row / index row bottoms. */
    const T_TOP = 73.6;
    const T_SUB = 78.3;
    const T_BODY = 83.2;
    const T_BOTTOM = 252.0;
    const T_POND = 262.6;
    const T_INDEX = 272.2;

    /** @var float Height of one subject row. */
    const ROW_H = 4.2;

    /** @var \TCPDF */
    private $pdf;

    /** @var string|null Raw PNG of the institute logo. */
    private $logo;

    /**
     * Render and stream the certificate.
     *
     * @param array  $data     credit_report::build() output.
     * @param string $filebase Download filename without extension.
     */
    public static function output(array $data, string $filebase): void {
        global $CFG;
        require_once($CFG->libdir . '/tcpdf/tcpdf.php');

        $self = new self();
        $self->pdf = new \TCPDF('P', 'mm', 'LEGAL', true, 'UTF-8', false);
        $self->pdf->SetCreator('ISI Moodle');
        $self->pdf->SetTitle('Certificado de Estudios - Créditos - ' . ($data['student']['name'] ?? ''));
        $self->pdf->setPrintHeader(false);
        $self->pdf->setPrintFooter(false);
        $self->pdf->SetMargins(0, 0, 0);
        $self->pdf->SetAutoPageBreak(false, 0);
        $self->pdf->setCellPaddings(0, 0, 0, 0);

        $logopath = $CFG->dirroot . '/local/grupomakro_core/pix/institute-logo.png';
        $self->logo = is_readable($logopath) ? file_get_contents($logopath) : null;

        $careers = $data['careers'] ?? [];
        if (empty($careers)) {
            $self->render_career($data['student'], ['career' => '', 'cuatrimestres' => [], 'summary' => null]);
        }
        foreach ($careers as $career) {
            $self->render_career($data['student'], $career);
        }

        $self->pdf->Output($filebase . '.pdf', 'D');
    }

    /**
     * One certificate (one or more pages) for a career.
     *
     * @param array $student
     * @param array $career
     */
    private function render_career(array $student, array $career): void {
        $rows = [];
        foreach ($career['cuatrimestres'] as $cuatri) {
            foreach ($cuatri['courses'] as $course) {
                if (!empty($course['is_closed'])) {
                    $rows[] = $course;
                }
            }
        }

        $perpage = (int)floor((self::T_BOTTOM - self::T_BODY) / self::ROW_H);
        $chunks = $rows ? array_chunk($rows, $perpage) : [[]];
        $last = count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            $this->pdf->AddPage();
            $this->draw_watermark();
            $this->draw_header($student, (string)$career['career']);
            $this->draw_table($chunk);
            $this->draw_totals($i === $last ? $career['summary'] : null);
            $this->draw_footer();
        }
    }

    /**
     * Security background: diagonal repeated institute name plus the faded central logo.
     *
     * It is a pre-rendered full-page image (pix/certificado-fondo.png, Legal at 150 dpi):
     * TCPDF garbles long rotated text runs, and an image prints the same on every viewer.
     */
    private function draw_watermark(): void {
        global $CFG;
        $bg = $CFG->dirroot . '/local/grupomakro_core/pix/certificado-fondo.png';
        if (is_readable($bg)) {
            $this->pdf->Image($bg, 0, 0, 215.9, 355.6, 'PNG');
        }
    }

    /**
     * Logo, titles, photo box and student data.
     *
     * @param array  $student
     * @param string $career
     */
    private function draw_header(array $student, string $career): void {
        $pdf = $this->pdf;

        if ($this->logo) {
            $pdf->Image('@' . $this->logo, 9.5, 5.4, 36, 0, 'PNG');
        }

        // Letter-spaced titles.
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->setFontSpacing(1.55);
        $pdf->SetXY(100, 17.8);
        $pdf->Cell(95, 5, 'CERTIFICADO DE ESTUDIOS', 0, 0, 'C');
        $pdf->SetFont('helvetica', 'B', 12.5);
        $pdf->setFontSpacing(3.4);
        $pdf->SetXY(100, 23.2);
        $pdf->Cell(97, 6, 'CRÉDITOS', 0, 0, 'C');
        $pdf->setFontSpacing(0);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Text(130.3, 30.6, 'Serie');
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Text(140.6, 29.9, 'A Nº');

        // Photo box.
        $pdf->SetLineWidth(0.3);
        $pdf->SetDrawColor(40, 40, 40);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect(180.8, 31.8, 28.7, 31.6);
        $pdf->SetFont('helvetica', '', 5.8);
        $pdf->SetXY(180.8, 36.8);
        $pdf->Cell(28.7, 3, 'FOTO', 0, 2, 'C');
        $pdf->SetXY(180.8, 44.6);
        $pdf->Cell(28.7, 3, 'TAMAÑO CARNET', 0, 2, 'C');
        $pdf->Cell(28.7, 3, 'FONDO BLANCO', 0, 2, 'C');
        $pdf->Cell(28.7, 3, 'SACO COLOR NEGRO', 0, 2, 'C');

        // Certifying officer.
        $pdf->SetFont('helvetica', 'B', 10.5);
        $pdf->Text(9.0, 36.0, 'El jefe de la oficina de Registros Académicos');
        $pdf->Text(9.0, 41.3, 'Certifica:');

        // Student data: labels in regular weight, values in bold next to them.
        $pdf->SetFont('helvetica', '', 10.5);
        $pdf->Text(9.0, 50.8, 'Que el Sr. (sra):');
        $pdf->Text(9.0, 56.5, 'Carrera Técnica:');
        $pdf->Text(9.0, 62.0, 'Registra las siguientes');
        $pdf->Text(9.0, 67.2, 'Asignaturas:');
        $pdf->Text(119.0, 50.8, 'Cédula:');
        $pdf->Text(119.0, 56.5, 'Codigo Nº.');

        $pdf->SetFont('helvetica', 'B', 9.5);
        $this->fit(38.5, 50.9, 79, (string)($student['name'] ?? ''));
        $this->fit(38.5, 56.6, 79, $career);
        $this->fit(134.5, 50.9, 45, (string)($student['identification'] ?? ''));
        $this->fit(139.0, 56.6, 41, (string)($student['code'] ?? ''));
    }

    /**
     * Subject table frame, headers and rows.
     *
     * @param array $rows Courses for this page.
     */
    private function draw_table(array $rows): void {
        $pdf = $this->pdf;
        $c = self::COLS;
        $left = $c[0];
        $right = $c[6];

        $pdf->SetDrawColor(30, 30, 30);
        $pdf->SetLineWidth(0.35);
        $pdf->Rect($left, self::T_TOP, $right - $left, self::T_INDEX - self::T_TOP);
        $this->line($left, self::T_BODY, $right, self::T_BODY, 0.35);
        $this->line($left, self::T_BOTTOM, $right, self::T_BOTTOM, 0.35);
        $this->line($left, self::T_POND, $right, self::T_POND, 0.35);
        for ($i = 1; $i <= 5; $i++) {
            $top = ($i === 5) ? self::T_SUB : self::T_TOP;
            $this->line($c[$i], $top, $c[$i], self::T_BOTTOM, 0.35);
        }
        $this->line($c[4], self::T_SUB, $right, self::T_SUB, 0.35);

        // Column headers.
        $pdf->SetFont('helvetica', '', 8);
        $mid = (self::T_TOP + self::T_BODY) / 2 - 1.8;
        $pdf->SetXY($c[0], $mid);
        $pdf->Cell($c[1] - $c[0], 3.6, 'CODIGO', 0, 0, 'C');
        $pdf->SetXY($c[1], $mid);
        $pdf->Cell($c[2] - $c[1], 3.6, 'ASIGNATURAS:', 0, 0, 'C');
        $pdf->SetXY($c[2], self::T_TOP + 1.0);
        $pdf->MultiCell($c[3] - $c[2], 3.5, "PERIODO\nACADÉMICO:", 0, 'C');
        $pdf->SetXY($c[3], $mid);
        $pdf->Cell($c[4] - $c[3], 3.6, 'CRÉDITOS', 0, 0, 'C');
        $pdf->SetXY($c[4], self::T_TOP + 0.6);
        $pdf->Cell($right - $c[4], 3.6, 'CALIFICATIVOS', 0, 0, 'C');
        $pdf->SetXY($c[4], self::T_SUB + 0.6);
        $pdf->Cell($c[5] - $c[4], 3.6, 'EN NROS', 0, 0, 'C');
        $pdf->SetXY($c[5], self::T_SUB + 0.6);
        $pdf->Cell($right - $c[5], 3.6, 'EN LETRAS', 0, 0, 'C');

        // Rows.
        $pdf->SetFont('helvetica', '', 7.6);
        $y = self::T_BODY + 0.8;
        foreach ($rows as $course) {
            $grade = grade_scale::to_float($course['grade'] ?? null);
            $cells = [
                [(string)($course['shortname'] ?? ''), 'C'],
                [(string)($course['coursename'] ?? ''), 'L'],
                [(string)($course['academicperiod'] ?? ''), 'C'],
                [(string)(int)($course['credits'] ?? 0), 'C'],
                [$grade === null ? '' : number_format($grade, 2), 'C'],
                [(string)($course['letter'] ?? ''), 'C'],
            ];
            foreach ($cells as $k => [$text, $align]) {
                $pad = ($align === 'L') ? 1.2 : 0.6;
                $pdf->SetXY($c[$k] + $pad, $y);
                // Stretch mode 1 squeezes long subject names into the column instead of wrapping.
                $pdf->Cell($c[$k + 1] - $c[$k] - 2 * $pad, self::ROW_H, $text, 0, 0, $align, false, '', 1);
            }
            $y += self::ROW_H;
        }
    }

    /**
     * Weighting and academic index rows (filled only on the certificate's last page).
     *
     * @param array|null $summary Career summary from the builder, or null.
     */
    private function draw_totals(?array $summary): void {
        $pdf = $this->pdf;
        $pdf->SetFont('helvetica', '', 10.5);
        $pdf->Text(8.6, self::T_BOTTOM + 3.0, 'Ponderación:');
        $pdf->Text(8.6, self::T_POND + 3.0, 'Índice Académico:');
        if ($summary) {
            $idx = $summary['index'];
            $pdf->SetFont('helvetica', 'B', 10.5);
            $pdf->Text(33.5, self::T_BOTTOM + 3.0, number_format((float)$idx['points'], 2));
            $pdf->Text(42.5, self::T_POND + 3.0, (string)$idx['display']);
        }
    }

    /**
     * Closing statement, date blanks, signature lines and notes.
     */
    private function draw_footer(): void {
        $pdf = $this->pdf;
        $pdf->SetDrawColor(30, 30, 30);
        $pdf->SetLineWidth(0.3);

        $pdf->SetFont('helvetica', '', 10.5);
        $pdf->Text(5.9, 273.9, 'Así consta en los Libros de Acta de la Institución');

        $pdf->Text(49.4, 284.4, 'CIUDAD DE PANAMÁ,');
        $this->line(93.4, 288.9, 115.0, 288.9);
        $pdf->Text(116.2, 284.4, 'DE');
        $this->line(122.6, 288.9, 157.7, 288.9);
        $pdf->Text(159.7, 284.4, 'DE');
        $this->line(166.0, 288.9, 193.5, 288.9);

        $this->line(8.2, 300.3, 59.4, 300.3);
        $this->line(154.6, 300.3, 205.7, 300.3);
        $this->line(65.2, 310.3, 148.8, 310.3);
        $pdf->SetXY(8.2, 302.4);
        $pdf->Cell(51.2, 5, 'DIRECTOR', 0, 0, 'C');
        $pdf->SetXY(151.0, 302.0);
        $pdf->Cell(59.0, 5, 'DIRECTOR ACADÉMICO', 0, 0, 'C');
        $pdf->SetXY(55.0, 313.0);
        $pdf->Cell(104.0, 5, 'JEFE OFICINA DE REGISTRO ACADÉMICO', 0, 0, 'C');

        $this->line(8.2, 321.4, 206.2, 321.4);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Text(8.4, 325.2, 'Nota');
        $notes = [
            '1) Se considera nota desaprobada de 0 a 70 y nota aprobada de 71 a 100.',
            '2) Toda enmendadura o borrón invalida el Certificado.',
            '3) El presente Certificado carece de valor sin la impresión del sello frío en la fotografía.',
            '4) Se imprimen de la Serie A del 0001 al 1000.',
        ];
        $y = 324.8;
        foreach ($notes as $note) {
            $pdf->Text(28.2, $y, $note);
            $y += 5.45;
        }
    }

    /**
     * Straight line with an explicit width: TCPDF's Text() leaves the line width at 0.
     *
     * @param float $x1
     * @param float $y1
     * @param float $x2
     * @param float $y2
     * @param float $width
     */
    private function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.3): void {
        $this->pdf->SetLineWidth($width);
        $this->pdf->Line($x1, $y1, $x2, $y2);
    }

    /**
     * Single-line text squeezed to fit a width (TCPDF stretch mode 1).
     *
     * @param float  $x
     * @param float  $y Top of the text line.
     * @param float  $w Available width.
     * @param string $text
     */
    private function fit(float $x, float $y, float $w, string $text): void {
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell($w, 4.5, $text, 0, 0, 'L', false, '', 1);
    }
}
