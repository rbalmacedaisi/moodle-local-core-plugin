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
 * RET-01 PDF generator (20261001080).
 *
 * Builds the official "Solicitud de Retiro del Programa" PDF from a gmk_wdr
 * row using TCPDF (already available alongside other PDFs in this plugin:
 * classes/local/diplomas/renderer.php, pages/attendance_pdf.php, etc.).
 *
 * Layout follows the institutional form on file at
 * docs/ret01/reference.pdf. Six sections in order:
 *   1) Header (institutional masthead + Direccion Academica + version)
 *   2) Student data
 *   3) Withdrawal declaration
 *   4) Reason + payment option
 *   5) Student declaration (5 points)
 *   6) Receipt of submission (Direccion Academica original + Direccion
 *      Administrativa copia)
 *   7) Internal use (deadline compliance + pending balance)
 *   8) Footer (institution address + copy routing)
 *
 * The correlative is stamped on the top-right inside a bordered cell so it
 * is unmistakable on the printed copy.
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

    public function __construct(\stdClass $row) {
        // Letter portrait, mm, A4-equivalent.
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);

        $this->row = $row;
        $this->request_number   = (string)$row->request_number;
        $this->template_version = wdr_manager::get_template_version();

        // Set up TCPDF defaults that mirror the rest of the plugin.
        $this->SetCreator('ISI Moodle');
        $this->SetAuthor('Instituto Superior de Ingenieria');
        $this->SetTitle('Solicitud de Retiro RET-01 - ' . $this->request_number);
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 18);
        $this->setHeaderMargin(0);
        $this->setFooterMargin(10);

        // Use the first Google font available locally; fallback to Helvetica.
        $this->SetFont('helvetica', '', 9);
    }

    /**
     * Renders the full PDF and returns the raw bytes.
     */
    public function render(): string {
        $this->AddPage();
        $this->render_header();
        $this->render_section_student();
        $this->render_section_declaration();
        $this->render_section_reason();
        $this->render_section_declaration_points();
        $this->render_section_receipt();
        $this->render_section_internal();
        $this->render_footer();
        return $this->Output('ret01.pdf', 'S');
    }

    private function render_header(): void {
        // Top-left: institutional masthead.
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(120, 7, 'INSTITUTO SUPERIOR DE INGENIERIA', 0, 0, 'L');
        // Top-right: top-right bordered cell with the request number.
        $this->SetFont('helvetica', '', 8);
        $this->Cell(50, 7, '', 0, 1, 'R'); // advance to right margin.
        $nx = 150;
        $ny = 15;
        $this->SetXY($nx, $ny);
        $this->SetFont('helvetica', '', 7);
        $this->Cell(35, 5, 'Solicitud N.°', 0, 1, 'L');
        $this->SetXY($nx, $ny + 4);
        $this->SetFont('helvetica', 'B', 12);
        $this->MultiCell(35, 12, $this->request_number, 1, 'C', false, 1, $nx, $ny + 4);

        // Reset X for the left block below.
        $this->SetXY(15, 22);

        $this->SetFont('helvetica', '', 8);
        $this->Cell(120, 5, 'DIRECCIÓN ACADÉMICA', 0, 1, 'L');
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(120, 8, 'Solicitud de Retiro del Programa', 0, 1, 'L');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(120, 5, 'Para estudiantes activos que no continuarán en el siguiente período académico', 0, 1, 'L');
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(120, 5, sprintf('Formulario oficial RET-01 · Versión %s', $this->template_version), 0, 1, 'L');

        $this->Ln(4);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(4);
    }

    private function render_section_student(): void {
        $this->section_title('1. DATOS DEL ESTUDIANTE');
        $rows = [
            ['Nombre completo', $this->row->fullname ?? ''],
            ['Cédula / Pasaporte', $this->row->id_number ?? ''],
            ['Teléfono', $this->row->phone ?? ''],
            ['Correo electrónico', $this->row->email ?? ''],
            ['Carrera o programa', $this->row->program ?? ''],
            ['Forma de pago', self::payment_mode_label($this->row->payment_mode ?? '')],
            ['Período académico actual', $this->row->current_period ?? ''],
            ['Período en el que ya no continuará', $this->row->last_period ?? ''],
        ];
        $this->kv_table($rows);
        $this->Ln(3);
    }

    private function render_section_declaration(): void {
        $this->section_title('2. SOLICITUD');
        $this->SetFont('helvetica', '', 9);
        $text = "Por medio del presente, comunico formalmente al Instituto Superior de Ingeniería mi decisión "
              . "de no continuar mis estudios a partir del período indicado en la sección 1, conforme a la "
              . "Cláusula Sexta del Contrato de Prestación de Servicios Educativos y al Reglamento Estudiantil.";
        $this->MultiCell(180, 5, $text, 0, 'J');
        if (!empty($this->row->observations)) {
            $this->Ln(2);
            $this->SetFont('helvetica', 'I', 9);
            $this->MultiCell(180, 5, 'Observaciones del estudiante: ' . $this->row->observations, 0, 'J');
            $this->SetFont('helvetica', '', 9);
        }
        $this->Ln(3);
    }

    private function render_section_reason(): void {
        $this->section_title('3. MOTIVO Y OPCIÓN SOBRE LOS PAGOS REALIZADOS');
        $reasons = [
            'A' => 'Económico',
            'B' => 'Laboral',
            'C' => 'Personal / familiar',
            'D' => 'Salud',
            'E' => 'Cambio de residencia',
            'F' => 'Otro',
        ];

        $this->SetFont('helvetica', '', 9);
        $this->Cell(0, 5, 'Motivo', 0, 1, 'L');
        foreach ($reasons as $code => $label) {
            $checked = ((string)$this->row->reason === $code) ? '[X]' : '[ ]';
            $this->Cell(8, 5, $checked, 0, 0, 'C');
            $this->Cell(80, 5, "$code. $label", 0, 1, 'L');
        }
        $this->Ln(2);

        // Reason detail (only when filled).
        $reason_detail = !empty($this->row->payment_option_detail) ? $this->row->payment_option_detail : '';
        if (!empty($reason_detail) && (string)$this->row->reason === 'F') {
            $this->SetFont('helvetica', 'I', 9);
            $this->MultiCell(180, 5, 'Especifique el motivo: ' . $reason_detail, 0, 'J');
            $this->SetFont('helvetica', '', 9);
        }

        $this->Ln(3);
        $this->Cell(0, 5, 'Opción sobre los pagos realizados', 0, 1, 'L');

        $options = [
            'cambio_carrera' => 'Cambio a otra carrera del ISI',
            'transferencia_derechos' => 'Transferencia de derechos a tercero',
            'no_aplica' => 'No aplica / no me acojo',
        ];
        foreach ($options as $key => $label) {
            $checked = ((string)$this->row->payment_option === $key) ? '[X]' : '[ ]';
            $this->Cell(8, 5, $checked, 0, 0, 'C');
            $this->Cell(120, 5, $label, 0, 1, 'L');
        }
        if (!empty($this->row->payment_option_detail) && (string)$this->row->payment_option !== 'no_aplica') {
            $this->SetFont('helvetica', 'I', 9);
            $detail_label = ($this->row->payment_option === 'cambio_carrera') ? 'Carrera de destino: ' : 'Cédula del tercero: ';
            $this->MultiCell(180, 5, $detail_label . $this->row->payment_option_detail, 0, 'J');
            $this->SetFont('helvetica', '', 9);
        }

        $this->Ln(3);
        $this->SetFont('helvetica', 'I', 8);
        $this->MultiCell(180, 5,
            'Nota: El ISI no realiza devoluciones de dinero por matrícula ni por mensualidades. Si pagó el '
            . 'cuatrimestre completo o la carrera completa, el siguiente período se factura salvo que se acoja a '
            . 'alguna de las opciones anteriores (Condiciones Especiales, punto 5).', 0, 'J');
        $this->SetFont('helvetica', '', 9);
        $this->Ln(3);
    }

    private function render_section_declaration_points(): void {
        $this->section_title('4. DECLARACIÓN DEL ESTUDIANTE');
        $lines = [
            '1. Entiendo que este retiro solo surte efecto cuando cuenta con la firma y fecha de recibido de la '
            . 'Dirección Académica y de la Dirección Administrativa.',
            '2. Entiendo que debe ser recibido al menos 30 días calendario antes del inicio oficial del siguiente '
            . 'período. De lo contrario, el ISI facturará el siguiente período y ese cargo será firme y adeudado '
            . '(Cláusula Sexta del Contrato).',
            '3. Me comprometo a cancelar cualquier saldo pendiente a la fecha, incluidos los recargos por mora '
            . 'aplicados (10% después de 3 días de la fecha de corte).',
            '4. Entiendo que al retirarme pierdo la calidad de estudiante y que el ISI no devuelve dinero, salvo '
            . 'las opciones indicadas en la sección 3. Un aviso verbal, por teléfono, WhatsApp o correo '
            . 'electrónico no sustituye este formulario. El estudiante debe conservar su copia firmada.',
            '5. Declaro que la información de este formulario es verdadera.',
        ];
        $this->SetFont('helvetica', '', 9);
        foreach ($lines as $l) {
            $this->MultiCell(180, 6, $l, 0, 'J');
            $this->Ln(1);
        }
        $this->Ln(4);
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(50, 6, 'Firma del estudiante: ____________________________', 0, 0, 'L');
        $this->Cell(50, 6, 'Fecha de firma: ____ / ____ / ________', 0, 1, 'L');
        $this->Ln(6);
    }

    private function render_section_receipt(): void {
        $this->section_title('5. CONSTANCIA DE RECEPCIÓN');
        $this->SetFont('helvetica', '', 9);
        $this->MultiCell(180, 5,
            'Sin las dos firmas de recibido este documento no tiene validez. Ambos departamentos son obligatorios.',
            0, 'J');
        $this->Ln(2);

        // Two-column block: Direccion Academica (izq) | Direccion Administrativa (der).
        $y0 = $this->GetY();
        $this->receipt_block(
            'Dirección Académica (original)',
            isset($this->row->received_da_at) && (int)$this->row->received_da_at > 0
                ? date('Y-m-d', (int)$this->row->received_da_at) : '',
            isset($this->row->received_da_by) && (int)$this->row->received_da_by > 0
                ? $this->user_fullname_or_id((int)$this->row->received_da_by) : ''
        );
        $this->SetXY(110, $y0);
        $this->receipt_block(
            'Dirección Administrativa (copia)',
            isset($this->row->received_admin_at) && (int)$this->row->received_admin_at > 0
                ? date('Y-m-d', (int)$this->row->received_admin_at) : '',
            isset($this->row->received_admin_by) && (int)$this->row->received_admin_by > 0
                ? $this->user_fullname_or_id((int)$this->row->received_admin_by) : ''
        );
    }

    private function receipt_block(string $title, string $date, string $receivedby): void {
        $x = $this->GetX();
        $y = $this->GetY();
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(90, 6, $title, 1, 1, 'C');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(45, 6, 'Fecha: ' . $date, 1, 0, 'L');
        $this->Cell(45, 6, 'Hora: ________', 1, 1, 'L');
        $this->Cell(90, 6, 'Nombre de quien recibe: ' . $receivedby, 1, 1, 'L');
        $this->Cell(90, 12, 'Firma y Sello', 1, 1, 'C');
        $this->SetXY($x + 95, $y);
    }

    private function render_section_internal(): void {
        $this->Ln(4);
        $this->section_title('6. PARA USO INTERNO');
        $this->SetFont('helvetica', '', 9);

        // Días de antelación entre timecreated y last_period, si se puede.
        $days = '';
        if (!empty($this->row->timecreated) && !empty($this->row->last_period)) {
            // best-effort: asumes last_period as a literal string, no se
            // puede convertir a fecha con seguridad. Mostramos lo que el
            // operador registra a mano en la bandeja.
            $days = '____________';
        }
        $this->Cell(40, 6, 'Días de antelación:', 1, 0, 'L');
        $this->Cell(35, 6, $days ?: '____________', 1, 0, 'L');
        $this->Cell(20, 6, '¿Cumple?', 1, 0, 'L');
        $this->Cell(20, 6, '[ ] Sí', 1, 0, 'C');
        $this->Cell(60, 6, '[ ] No → se factura el siguiente período', 1, 1, 'L');

        $this->Cell(50, 6, 'Saldo pendiente a la fecha (Administración):', 1, 0, 'L');
        $this->Cell(125, 6, '$ ____________________', 1, 1, 'L');

        $this->Cell(50, 6, 'Registrado en expediente por:', 1, 0, 'L');
        $this->Cell(125, 6, '______________________  /  Fecha: ____/____/________', 1, 1, 'L');
        $this->Ln(3);

        // Diagonal watermark to distinguish from the manually signed copy.
        $this->SetAlpha(0.08);
        $this->StartTransform();
        $this->Rotate(35, 105, 250);
        $this->SetFont('helvetica', 'B', 38);
        $this->SetTextColor(0, 0, 0);
        $this->Text(35, 250, 'SOLICITUD GENERADA DIGITALMENTE - ISI');
        $this->StopTransform();
        $this->SetAlpha(1);
        $this->SetTextColor(0, 0, 0);
    }

    private function render_footer(): void {
        $this->SetY(-12);
        $this->SetFont('helvetica', 'I', 7);
        $this->Cell(0, 5,
            'Instituto Superior de Ingeniería · Avenida Perú y Calle 34 Este, esquina Bellavista, Ciudad de Panamá',
            0, 1, 'C');
        $this->Cell(0, 5,
            'Original: Dirección Académica · Copias: Dirección Administrativa y Estudiante',
            0, 1, 'C');
        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 4, 'Solicitud RET-01 generada digitalmente desde el LXP.', 0, 1, 'C');
    }

    private function section_title(string $title): void {
        $this->SetFont('helvetica', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(180, 7, $title, 1, 1, 'L', true);
        $this->SetFont('helvetica', '', 9);
        $this->Ln(2);
    }

    private function kv_table(array $rows): void {
        $this->SetFont('helvetica', '', 9);
        foreach ($rows as [$k, $v]) {
            $this->SetFont('helvetica', 'B', 9);
            $this->Cell(60, 6, $k, 1, 0, 'L');
            $this->SetFont('helvetica', '', 9);
            $this->Cell(120, 6, (string)$v, 1, 1, 'L');
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
