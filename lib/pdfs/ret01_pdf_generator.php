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
 * RET-01 PDF generator (20261001080, refactored 20261001100).
 *
 * Builds the official "Solicitud de Retiro del Programa" PDF from a
 * gmk_wdr row using TCPDF. Layout inspired by the institutional
 * account-statement (estado de cuenta) format produced in Odoo: clean
 * typography, a tinted header bar with a right-aligned request-number
 * badge, two-column student data, justified body paragraphs, a
 * bordered dual signature block, and a thin page footer.
 *
 * Eight sections, in order:
 *   1) Header (institutional block + request number badge)
 *   2) Student data (two-column key/value table)
 *   3) Solicitud declaration (justified paragraph)
 *   4) Reason + payment option (two side-by-side option blocks)
 *   5) Declaracion (5 numbered items + signature line)
 *   6) Constancia de recepcion (two side-by-side receipt boxes)
 *   7) Para uso interno (compact form)
 *   8) Footer (address + page number)
 *
 * Uses TCPDF's bundled opensans + opensans__b (Open Sans Regular / Bold)
 * which are available at /var/www/html/moodle/lib/tcpdf/fonts/ in
 * this Moodle install. Falls back to Helvetica if those files are
 * missing (e.g. on dev machines that haven't fetched the font zips).
 *
 * The correlative is stamped on the top-right inside a tinted
 * bordered badge so it is unmistakable on the printed copy.
 */

namespace local_grupomakro_core\local\pdf;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tcpdf/tcpdf.php');

use local_grupomakro_core\local\wdr_manager;

class ret01_pdf_generator extends \TCPDF {

    /** @var \stdClass The withdrawal request row. */
    private $row;

    /** @var string Full request number, e.g. "RET-2026-0001". */
    private $request_number;

    /** @var string Institutional template version. */
    private $template_version;

    /** Warm institutional palette (aligned with the Estado de Cuenta PDF). */
    private const C_PRIMARY   = [212, 145, 38];  // #D49126 amber, section bands
    private const C_ACCENT    = [140, 30, 35];   // #8C1E23 bordeaux, warnings/mora
    private const C_LIGHT     = [252, 248, 240]; // #FCF8F0 warm cream
    private const C_LIGHTER   = [255, 252, 245]; // #FFFCF5 paler cream
    private const C_MUTED     = [120, 110, 95];  // #786E5F warm gray
    private const C_RULE      = [218, 210, 195]; // #DAD2C3 warm rule
    private const C_TEXT      = [50, 40, 30];    // #32281E warm near-black
    private const C_BAND      = [245, 230, 200]; // #F5E6C8 band cream
    private const C_GREEN     = [60, 130, 70];   // #3C8246 success/check
    private const C_HEADER_ACCENT = [192, 95, 30]; // #C05F1E header amber

    /** @var bool Whether the opensans font files are available. */
    private $use_opensans;

    public function __construct(\stdClass $row) {
        // Letter portrait, mm, A4-equivalent. Margins tuned to give the
        // header bar and footer enough room without crowding content.
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);

        $this->row = $row;
        $this->request_number   = (string)$row->request_number;
        $this->template_version = wdr_manager::get_template_version();

        // Check if the opensans fonts are available on this install; fall
        // back to helvetica silently if not. Same TTF zips are bundled
        // in the standard TCPDF install.
        $opensans_path = $CFG->libdir . '/tcpdf/fonts/opensans.php';
        $this->use_opensans = file_exists($opensans_path);

        $this->SetCreator('ISI Moodle');
        $this->SetAuthor('Instituto Superior de Ingenieria');
        $this->SetTitle('Solicitud de Retiro RET-01 - ' . $this->request_number);
        $this->SetMargins(15, 18, 15);
        $this->SetAutoPageBreak(true, 22);
        $this->setHeaderMargin(0);
        $this->setFooterMargin(12);

        $this->use_opensans ? $this->SetFont('opensans', '', 9)
                            : $this->SetFont('helvetica', '', 9);
    }

    public function render(): string {
        $this->AddPage();
        $this->render_header();
        $this->Ln(2);
        $this->render_section_student();
        $this->render_section_solicitud();
        // Dejar que TCPDF rompa naturalmente entre secciones 2-3-4-5-6
        // para no generar paginas vacias cuando el contenido ya cabe
        // en la pagina actual. Las cajas del recibo (5) chequean su
        // propio espacio dentro de render_section_receipt().
        $this->render_section_reason_payment();
        $this->render_section_declaration();
        $this->render_section_receipt();
        $this->render_section_internal();
        $this->render_footer();
        $this->trim_trailing_blank_page();
        return $this->Output('ret01.pdf', 'S');
    }

    /**
     * Removes the trailing page if it has no real content (just the
     * footer / break-margin cursor). This is the root cause of the
     * historical "empty 3rd page" bug: TCPDF's auto page break sometimes
     * leaves a blank trailing page after the last content block + footer.
     * Heuristic: a page is "blank" if the cursor Y after the footer is
     * less than 15% of the available content area, AND we have more than
     * one page.
     */
    private function trim_trailing_blank_page(): void {
        $total = $this->getNumPages();
        if ($total <= 1) {
            return;
        }
        $pageHeight = $this->getPageHeight();
        $breakMargin = $this->getBreakMargin();
        $cursorY = $this->GetY();
        $available = $pageHeight - $breakMargin - 20; // 20mm footer reserve
        if ($available <= 0) {
            return;
        }
        $fillRatio = $cursorY / $available;
        if ($fillRatio < 0.15) {
            $this->deletePage($total);
        }
    }

    /**
     * Si quedan menos de $needed mm entre el cursor actual y el break
     * margin, fuerza un salto de pagina. Esto evita que TCPDF parta
     * bloques que no se rompen bien (cajas RoundedRect, firmas, etc.).
     * Si quedan menos de $needed mm entre el cursor actual y el break
     * margin, fuerza un salto de pagina. NO renderiza el header aqui
     * (eso lo hace el flujo principal de render() y los callbacks de
     * TCPDF). Llamarlo en mitad de una seccion causa que el subtitulo
     * "Para estudiantes activos..." se inserte en mitad de otra seccion
     * (lo que el usuario veia en el PDF como "header de la siguiente
     * pagina" cuando en realidad es el header en mitad de page 2).
     */
    private function ensure_space(float $needed): void {
        $available = $this->getPageHeight() - $this->getBreakMargin() - $this->GetY();
        if ($available < $needed) {
            $this->AddPage();
        }
    }

    // ─────────────────── HEADER ───────────────────
    private function render_header(): void {
        $y0 = 12;
        $this->SetY($y0);

        // Left: institutional block.
        $this->SetTextColor(...self::C_PRIMARY);
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', 'B', 14);
        $this->Cell(0, 7, 'INSTITUTO SUPERIOR DE INGENIERIA', 0, 1, 'L');

        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 8);
        $this->SetTextColor(...self::C_MUTED);
        $this->Cell(0, 4, 'DIRECCION ACADEMICA', 0, 1, 'L');

        $this->Ln(2);
        $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 16);
        $this->SetTextColor(...self::C_TEXT);
        $this->Cell(0, 8, 'SOLICITUD DE RETIRO DEL PROGRAMA', 0, 1, 'L');

        $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 9);
        $this->SetTextColor(...self::C_MUTED);
        $this->Cell(0, 5, 'Para estudiantes activos que no continuaran en el siguiente periodo academico', 0, 1, 'L');
        $this->Cell(0, 5, sprintf('Formulario oficial RET-01  -  Version %s', $this->template_version), 0, 1, 'L');

        // Right: request-number badge in a tinted bordered cell.
        $nx = 150;
        $ny = $y0;
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 7);
        $this->SetTextColor(...self::C_MUTED);
        $this->SetXY($nx, $ny);
        $this->Cell(35, 4, 'SOLICITUD N.', 0, 1, 'C');
        $this->SetXY($nx, $ny + 4);
        $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 13);
        $this->SetTextColor(...self::C_TEXT);
        $this->SetFillColor(...self::C_LIGHT);
        $this->MultiCell(35, 12, $this->request_number, 1, 'C', true, 1, $nx, $ny + 4);

        // Reset X for the left block below. Mantener margen de 4mm
        // entre el subtitulo y la seccion 1 para que no se corten
        // las lineas del subtitulo contra la banda azul.
        $this->SetXY(15, max($this->GetY(), $ny + 16) + 4);
        $this->SetTextColor(...self::C_TEXT);
        $this->SetDrawColor(...self::C_RULE);
        // No horizontal rule bajo el titulo: la banda azul de cada
        // section_title provee suficiente separacion visual.
    }

    // ─────────────────── 1. DATOS DEL ESTUDIANTE ───────────────────
    private function render_section_student(): void {
        $this->section_title('1.  DATOS DEL ESTUDIANTE');
        $rows = [
            ['Nombre completo',     $this->row->fullname ?? ''],
            ['Cedula / Pasaporte',  $this->row->id_number ?? ''],
            ['Telefono',            $this->row->phone ?? ''],
            ['Correo electronico',  $this->row->email ?? ''],
            ['Carrera o programa',  $this->row->program ?? ''],
            ['Forma de pago',       self::payment_mode_label($this->row->payment_mode ?? '')],
            ['Periodo academico actual',  $this->row->current_period ?? ''],
            ['Periodo en el que ya no continuara', $this->row->last_period ?? ''],
        ];
        $this->kv_table($rows);
        $this->Ln(2);
    }

    // ─────────────────── 2. SOLICITUD ───────────────────
    private function render_section_solicitud(): void {
        $this->section_title('2.  SOLICITUD');
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 10);
        $this->SetTextColor(...self::C_TEXT);
        $text = "Por medio del presente, comunico formalmente al Instituto Superior de Ingenieria "
              . "mi decision de no continuar mis estudios a partir del periodo indicado en la seccion 1, "
              . "conforme a la Clausula Sexta del Contrato de Prestacion de Servicios Educativos y al "
              . "Reglamento Estudiantil.";
        $this->MultiCell(180, 5.5, $text, 0, 'J');
        if (!empty($this->row->observations)) {
            $this->Ln(1);
            $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 9);
            $this->SetTextColor(...self::C_MUTED);
            $this->MultiCell(180, 5, 'Observaciones del estudiante: ' . $this->row->observations, 0, 'J');
            $this->SetTextColor(...self::C_TEXT);
        }
        $this->Ln(2);
    }

    // ─────────────────── 3. MOTIVO Y OPCION ───────────────────
    private function render_section_reason_payment(): void {
        $this->section_title('3.  MOTIVO Y OPCION SOBRE LOS PAGOS REALIZADOS');

        // Two side-by-side blocks. Each helper returns its final Y position
        // (since motivo has 6 options vs pago's 3, and both can have a
        // detail line, their final Ys differ). We then move to the max so
        // the next section (Nota) starts BELOW both blocks. Without this
        // the second block would override the first, causing the overlap
        // the user reported.
        $y0 = $this->GetY();
        $colWidth = 87.5;
        $yMotivo = $this->render_option_block_motivo($colWidth, $y0);
        $yPago   = $this->render_option_block_pago($colWidth, $y0);
        $yBottom = max($yMotivo, $yPago) + 3;
        $this->SetY($yBottom);

        $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 8);
        $this->SetTextColor(...self::C_MUTED);
        $this->MultiCell(180, 4.5,
            'Nota: El ISI no realiza devoluciones de dinero por matricula ni por mensualidades. Si pago el '
            . 'cuatrimestre completo o la carrera completa, el siguiente periodo se factura salvo que se acoja a '
            . 'alguna de las opciones anteriores (Condiciones Especiales, punto 5).', 0, 'J');
        $this->SetTextColor(...self::C_TEXT);
        $this->Ln(2);
    }

    /**
     * Render the "Motivo" column (left half). Returns the final Y so the
     * caller can compute the max of both columns.
     */
    private function render_option_block_motivo(float $w, float $y0): float {
        $this->SetXY(15, $y0);
        $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 10);
        $this->SetTextColor(...self::C_PRIMARY);
        $this->Cell($w, 6, '  Motivo', 0, 1, 'L');
        $this->SetDrawColor(...self::C_RULE);
        $this->Line(15, $this->GetY(), 15 + $w, $this->GetY());

        $reasons = [
            'A' => 'Economico',
            'B' => 'Laboral',
            'C' => 'Personal / familiar',
            'D' => 'Salud',
            'E' => 'Cambio de residencia',
            'F' => 'Otro',
        ];
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9);
        $this->SetTextColor(...self::C_TEXT);
        $yStart = $this->GetY();
        foreach ($reasons as $code => $label) {
            $this->SetY($yStart);
            $checked = ((string)$this->row->reason === $code);
            $this->render_checkbox(15 + 2, $yStart + 1, $checked);
            $this->SetXY(15 + 8, $yStart);
            $this->Cell($w - 8, 5, "$code. $label", 0, 0, 'L');
            $yStart += 5;
        }
        // Reason detail (only when F + detail present).
        if (!empty($this->row->payment_option_detail) && (string)$this->row->reason === 'F') {
            $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 8);
            $this->SetTextColor(...self::C_MUTED);
            $this->SetXY(15 + 8, $yStart);
            $this->MultiCell($w - 8, 4, 'Especifique: ' . $this->row->payment_option_detail, 0, 'J');
            $this->SetTextColor(...self::C_TEXT);
            $yStart = $this->GetY();
        }
        return $yStart;
    }

    /**
     * Render the "Opcion sobre los pagos" column (right half). Returns
     * the final Y so the caller can compute the max of both columns.
     */
    private function render_option_block_pago(float $w, float $y0): float {
        $x = 107.5;
        $this->SetXY($x, $y0);
        $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 10);
        $this->SetTextColor(...self::C_PRIMARY);
        $this->Cell($w, 6, '  Opcion sobre los pagos', 0, 1, 'L');
        $this->SetDrawColor(...self::C_RULE);
        $this->Line($x, $this->GetY(), $x + $w, $this->GetY());

        $options = [
            'cambio_carrera'         => 'Cambio a otra carrera del ISI',
            'transferencia_derechos'  => 'Transferencia de derechos a tercero',
            'no_aplica'               => 'No aplica / no me acojo',
        ];
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9);
        $this->SetTextColor(...self::C_TEXT);
        $yStart = $this->GetY();
        foreach ($options as $key => $label) {
            $this->SetXY($x, $yStart);
            $checked = ((string)$this->row->payment_option === $key);
            $this->render_checkbox($x + 2, $yStart + 1, $checked);
            $this->SetXY($x + 8, $yStart);
            $this->Cell($w - 8, 5, $label, 0, 0, 'L');
            $yStart += 5;
        }
        if (!empty($this->row->payment_option_detail) && (string)$this->row->payment_option !== 'no_aplica') {
            $detail_label = ($this->row->payment_option === 'cambio_carrera')
                ? 'Carrera de destino: '
                : 'Cedula del tercero: ';
            $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 8);
            $this->SetTextColor(...self::C_MUTED);
            $this->SetXY($x + 8, $yStart);
            $this->MultiCell($w - 8, 4, $detail_label . $this->row->payment_option_detail, 0, 'J');
            $this->SetTextColor(...self::C_TEXT);
            $yStart = $this->GetY();
        }
        return $yStart;
    }

    /**
     * Draws a 3.5mm square checkbox. If $checked, fills with a thin
     * check mark drawn from the X-style lines. Mimics the look of an
     * account-statement "tick here" box.
     */
    private function render_checkbox(float $x, float $y, bool $checked): void {
        $size = 3.2;
        $this->SetDrawColor(...self::C_MUTED);
        $this->SetLineWidth(0.3);
        $this->Rect($x, $y, $size, $size, 'D');
        if ($checked) {
            $this->SetDrawColor(...self::C_PRIMARY);
            $this->SetLineWidth(0.8);
            // Diagonal cross to indicate checked (two strokes).
            $this->Line($x + 0.3, $y + $size / 2, $x + $size / 2, $y + $size - 0.3);
            $this->Line($x + $size / 2, $y + $size - 0.3, $x + $size - 0.3, $y + 0.3);
            $this->SetDrawColor(...self::C_RULE);
            $this->SetLineWidth(0.2);
        }
    }

    // ─────────────────── 4. DECLARACION ───────────────────
    private function render_section_declaration(): void {
        $this->section_title('4.  DECLARACION DEL ESTUDIANTE');
        // Strip the leading "N. " from each line - the number is rendered
        // separately by the cell below, so leaving it in the text would
        // produce "1. 1. Entiendo que..." duplicates.
        $lines = [
            'Entiendo que este retiro solo surte efecto cuando cuenta con la firma y fecha de recibido de la '
            . 'Direccion Academica y de la Direccion Administrativa.',
            'Entiendo que debe ser recibido al menos 30 dias calendario antes del inicio oficial del siguiente '
            . 'periodo. De lo contrario, el ISI facturara el siguiente periodo y ese cargo sera firme y adeudado '
            . '(Clausula Sexta del Contrato).',
            'Me comprometo a cancelar cualquier saldo pendiente a la fecha, incluidos los recargos por mora '
            . 'aplicados (10% despues de 3 dias de la fecha de corte).',
            'Entiendo que al retirarme pierdo la calidad de estudiante y que el ISI no devuelve dinero, salvo '
            . 'las opciones indicadas en la seccion 3. Un aviso verbal, por telefono, WhatsApp o correo '
            . 'electronico no sustituye este formulario. El estudiante debe conservar su copia firmada.',
            'Declaro que la informacion de este formulario es verdadera.',
        ];
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9.5);
        $this->SetTextColor(...self::C_TEXT);
        $i = 1;
        foreach ($lines as $l) {
            $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 9.5);
            $this->Cell(5, 5, "$i.", 0, 0, 'R');
            $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9.5);
            $this->MultiCell(175, 5, $l, 0, 'J');
            $this->Ln(1);
            $i++;
        }
        $this->Ln(3);
        $this->SetDrawColor(...self::C_RULE);
        $this->SetLineWidth(0.4);
        $this->Line(15, $this->GetY(), 90, $this->GetY());
        $this->Line(95, $this->GetY(), 195, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 9);
        $this->SetXY(15, $this->GetY() - 1);
        // Linea de firma y fecha con espacio suficiente para que el
        // usuario pueda firmar a mano sin que se corte contra la siguiente
        // seccion. La firma va sobre la linea; la fecha al lado.
        $this->SetDrawColor(...self::C_MUTED);
        $this->SetLineWidth(0.3);
        $yFirma = $this->GetY() + 4;
        $this->Line(15, $yFirma, 90, $yFirma);
        $this->Line(95, $yFirma, 195, $yFirma);
        $this->SetDrawColor(...self::C_RULE);
        $this->SetLineWidth(0.2);
        $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 8);
        $this->SetTextColor(...self::C_MUTED);
        $this->SetXY(15, $yFirma + 2);
        $this->Cell(80, 4, '  Firma del estudiante', 0, 0, 'L');
        $this->SetX(95);
        $this->Cell(100, 4, '  Fecha:   ____  /  ____  /  ________', 0, 0, 'L');
        $this->SetTextColor(...self::C_TEXT);
        $this->Ln(10);
    }

    // ─────────────────── 5. CONSTANCIA DE RECEPCION ───────────────────
    private function render_section_receipt(): void {
        $this->section_title('5.  CONSTANCIA DE RECEPCION');
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9);
        $this->SetTextColor(...self::C_MUTED);
        $this->MultiCell(180, 5,
            'Sin las dos firmas de recibido este documento no tiene validez. Ambos departamentos son obligatorios.',
            0, 'C');
        $this->Ln(2);

        // Forzar salto de pagina si no hay ~90mm para las DOS cajas
        // (caja 38mm + titulo 5.5mm + Ln de margen + cuerpo). Si no hay
        // espacio, AMBAS cajas pasan a la siguiente pagina y se
        // renderizan JUNTAS desde el top. Esto evita que las cajas
        // queden partidas entre paginas (bug historico).
        $this->ensure_space(90);
        $y0 = $this->GetY();
        $this->receipt_block(
            'Direccion Academica (original)',
            isset($this->row->received_da_at) && (int)$this->row->received_da_at > 0
                ? date('Y-m-d', (int)$this->row->received_da_at) : '',
            isset($this->row->received_da_by) && (int)$this->row->received_da_by > 0
                ? $this->user_fullname_or_id((int)$this->row->received_da_by) : ''
        );
        $this->SetXY(110, $y0);
        $this->receipt_block(
            'Direccion Administrativa (copia)',
            isset($this->row->received_admin_at) && (int)$this->row->received_admin_at > 0
                ? date('Y-m-d', (int)$this->row->received_admin_at) : '',
            isset($this->row->received_admin_by) && (int)$this->row->received_admin_by > 0
                ? $this->user_fullname_or_id((int)$this->row->received_admin_by) : ''
        );
        // Move the cursor below the LAST receipt block. receipt_block
        // leaves SetXY at ($x + $w + 5, $y0) - i.e. to the RIGHT of the
        // second box, at the same Y. We want the cursor BELOW both
        // boxes so the next section (6) renders beneath, not on top of,
        // the receipt boxes. The previous version had
        //   SetY(max($this->GetY(), $this->GetY()) + 2)
        // which is a no-op (max(x, x) === x) - that is why section 6
        // was rendering on top of the receipt boxes.
        $this->SetY($y0 + 40);
    }

    private function receipt_block(string $title, string $date, string $receivedby): void {
        $x = $this->GetX();
        $y = $this->GetY();
        $w = 90;
        // Tinted background for the whole block.
        $this->SetFillColor(...self::C_LIGHTER);
        $this->SetDrawColor(...self::C_RULE);
        $this->SetLineWidth(0.3);
        $this->RoundedRect($x, $y, $w, 38, 1.5, 'DF');

        // Title bar.
        $this->SetFillColor(...self::C_PRIMARY);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 9);
        $this->Cell($w, 5.5, '  ' . $title, 0, 0, 'L', true);
        $this->SetY($y + 7);
        $this->SetX($x);
        $this->SetFillColor(...self::C_LIGHTER);

        $this->SetTextColor(...self::C_TEXT);
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9);
        $this->Cell($w * 0.5, 5, '  Fecha:  ' . $date, 0, 0, 'L');
        $this->SetX($x + $w * 0.5);
        $this->Cell($w * 0.5, 5, '  Hora:  __________', 0, 1, 'L');
        $this->SetX($x);
        $this->Cell($w, 5, '  Recibido por:  ' . $receivedby, 0, 1, 'L');

        // Signature placeholder (a ruled line with a small hint).
        $this->SetX($x);
        $this->SetDrawColor(...self::C_MUTED);
        $this->SetLineWidth(0.3);
        $this->Line($x + 8, $this->GetY() + 9, $x + $w - 8, $this->GetY() + 9);
        $this->SetXY($x, $this->GetY() + 10);
        $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 8);
        $this->SetTextColor(...self::C_MUTED);
        $this->Cell($w, 4, '  Firma y sello', 0, 1, 'C');
        $this->SetTextColor(...self::C_TEXT);

        // Move cursor to the right of this block for the next sibling.
        $this->SetXY($x + $w + 5, $y);
    }

    // ─────────────────── 6. PARA USO INTERNO ───────────────────
    private function render_section_internal(): void {
        $this->Ln(2);
        $this->section_title('6.  PARA USO INTERNO');
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9);
        $this->SetTextColor(...self::C_TEXT);

        // Deadline compliance (1-line form).
        $this->SetFillColor(...self::C_LIGHT);
        $this->SetDrawColor(...self::C_RULE);
        $this->SetLineWidth(0.3);
        $this->Cell(40, 6, '  Dias de antelacion:', 1, 0, 'L', true);
        $this->Cell(30, 6, '____________', 1, 0, 'L', true);
        $this->Cell(20, 6, '  Cumple?', 1, 0, 'L', true);
        $this->Cell(20, 6, '  [ ]  Si', 1, 0, 'C', true);
        $this->Cell(70, 6, '  [ ]  No  (se factura el sgte. periodo)', 1, 1, 'L', true);

        // Pending balance.
        $this->SetFillColor(...self::C_LIGHT);
        $this->Cell(50, 6, '  Saldo pendiente a la fecha:', 1, 0, 'L', true);
        $this->Cell(130, 6, '  $  ________________________', 1, 1, 'L', true);

        // Filing.
        $this->SetFillColor(...self::C_LIGHT);
        $this->Cell(50, 6, '  Registrado en expediente por:', 1, 0, 'L', true);
        $this->Cell(130, 6, '  _____________________  /  Fecha: ____ / ____ / ________', 1, 1, 'L', true);

        // NOTA: Se elimino el watermark diagonal "SOLICITUD GENERADA
        // DIGITALMENTE - ISI" que estaba aqui. Razon: su posicion fija
        // (y=245) y tamano (38pt) hacian que la pagina siguiente se
        // quedara en blanco porque el texto rotado caia fuera del
        // margen inferior. La marca "Generado digitalmente" sigue
        // apareciendo en el footer de cada pagina.
    }

    // ─────────────────── FOOTER ───────────────────
    private function render_footer(): void {
        $this->SetY(-14);
        $this->SetDrawColor(...self::C_RULE);
        $this->SetLineWidth(0.2);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(1);
        $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 7);
        $this->SetTextColor(...self::C_MUTED);
        $this->Cell(0, 4,
            'Instituto Superior de Ingenieria  -  Avenida Peru y Calle 34 Este, esquina Bellavista, Ciudad de Panama',
            0, 1, 'C');
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 7);
        $this->Cell(0, 4,
            'Original: Direccion Academica  -  Copias: Direccion Administrativa y Estudiante',
            0, 1, 'C');
        $this->SetFont($this->use_opensans ? 'opensans__i' : 'helvetica', 'I', 6.5);
        $this->Cell(0, 3, 'Solicitud RET-01 generada digitalmente desde el LXP.  -  Pag. ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 1, 'C');
    }

    // ─────────────────── HELPERS ───────────────────
    private function section_title(string $title): void {
        $this->SetFillColor(...self::C_PRIMARY);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 10);
        $this->Cell(180, 7, '  ' . $title, 0, 1, 'L', true);
        $this->SetTextColor(...self::C_TEXT);
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9);
        // Small spacer to keep the section title from kissing the first
        // row of the next table (was overlapping in the previous build).
        $this->Ln(3);
    }

    private function kv_table(array $rows): void {
        $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9);
        $rowH = 6.5;
        $colW = [60, 120];
        $altBg = false;
        foreach ($rows as [$k, $v]) {
            $this->SetFillColor(...($altBg ? self::C_LIGHTER : [255, 255, 255]));
            $this->SetDrawColor(...self::C_RULE);
            $this->SetLineWidth(0.3);
            $this->SetFont($this->use_opensans ? 'opensans__b' : 'helvetica', 'B', 9);
            $this->SetTextColor(...self::C_PRIMARY);
            $this->Cell($colW[0], $rowH, '  ' . $k, 1, 0, 'L', true);
            $this->SetFont($this->use_opensans ? 'opensans' : 'helvetica', '', 9);
            $this->SetTextColor(...self::C_TEXT);
            $this->Cell($colW[1], $rowH, '  ' . (string)$v, 1, 1, 'L', true);
            $altBg = !$altBg;
        }
    }

    private static function payment_mode_label(string $mode): string {
        if ($mode === 'mensual') return 'Mensual';
        if ($mode === 'quincenal') return 'Quincenal';
        return $mode !== '' ? ucfirst($mode) : '';
    }

    private function user_fullname_or_id(int $userid): string {
        global $DB;
        try {
            $u = $DB->get_record('user', ['id' => $userid], 'firstname,lastname', IGNORE_MISSING);
            if ($u) {
                return trim(fullname($u));
            }
        } catch (\Throwable $e) {
            // ignore.
        }
        return '';
    }
}