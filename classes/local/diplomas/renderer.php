<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * TCPDF-based renderer for diploma PDFs.
 *
 * @package    local_grupomakro_core
 * @copyright  2024 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\diplomas;

defined('MOODLE_INTERNAL') || die();

use context_system;
use stdClass;

/**
 * Diploma PDF renderer.
 */
class renderer {
    /**
     * Maps our custom font family names to TCPDF built-in fonts.
     * TCPDF accepts custom fonts if registered, but for a portable default
     * we map the most common Google Fonts to TCPDF core fonts.
     *
     * @var array<string,string>
     */
    private const FONT_MAP = [
        'helvetica' => 'helvetica',
        'arial' => 'helvetica',
        'times' => 'times',
        'timesnewroman' => 'times',
        'courier' => 'courier',
        'dejavusans' => 'dejavusans',
        'dejavuserif' => 'dejavuserif',
        'freesans' => 'freesans',
        'freeserif' => 'freeserif',
        'freemono' => 'freemono',
        'opensans' => 'dejavusans',
        'roboto' => 'dejavusans',
        'montserrat' => 'helvetica',
        'lora' => 'times',
        'playfairdisplay' => 'times',
        'merriweather' => 'times',
        'ptsans' => 'dejavusans',
        'ptsernif' => 'dejavuserif',
        'lato' => 'helvetica',
        'poppins' => 'helvetica',
        'oswald' => 'helvetica',
        'raleway' => 'helvetica',
        'notosans' => 'dejavusans',
        'notoserif' => 'dejavuserif',
        'dancingscript' => 'dejavuserif',
        'pacifico' => 'dejavuserif',
        'greatvibes' => 'dejavuserif',
        'garamond' => 'times',
        'georgia' => 'times',
        'verdana' => 'helvetica',
        'tahoma' => 'helvetica',
        'palatino' => 'times',
        'gillsans' => 'helvetica',
        'segoeui' => 'helvetica',
    ];

    /**
     * Map a user-supplied font family to a TCPDF-supported core font.
     */
    public static function resolve_tcpdf_font(string $family): string {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $family));
        return self::FONT_MAP[$key] ?? 'helvetica';
    }

    /**
     * Renders a diploma PDF for the given template, fields and user context.
     *
     * @param stdClass $template Template row.
     * @param array<int, stdClass> $fields Template field rows.
     * @param stdClass $user User record.
     * @param stdClass|null $lp Learning plan context.
     * @param stdClass|null $generation Generation row.
     * @param string $verificationurl URL used for the QR code.
     * @return string PDF bytes.
     */
    public function render_pdf(
        stdClass $template,
        array $fields,
        stdClass $user,
        ?stdClass $lp,
        ?stdClass $generation,
        string $verificationurl
    ): string {
        global $CFG;
        require_once($CFG->libdir . '/tcpdf/tcpdf.php');

        $orientation = ($template->orientation ?? 'landscape') === 'portrait' ? 'P' : 'L';
        $format = [$template->width_mm ?? 297, $template->height_mm ?? 210];

        $pdf = new \TCPDF($orientation, 'mm', $format, true, 'UTF-8', false);
        $pdf->SetCreator('Moodle Grupo Makro');
        $pdf->SetAuthor(fullname($user));
        $pdf->SetTitle('Diploma - ' . ($generation->diploma_number ?? ''));
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // 1) Background image as full-page fill.
        if (!empty($template->background_fileid)) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id((int)$template->background_fileid);
            if ($file) {
                $tmpdir = make_request_directory();
                $tmpfile = $tmpdir . '/bg_' . $template->id . '_' . $file->get_id() . '.img';
                file_put_contents($tmpfile, $file->get_content());
                $mime = (string)$template->background_mimetype;
                $ext = '';
                if (strpos($mime, 'png') !== false) {
                    $ext = 'PNG';
                } else if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) {
                    $ext = 'JPEG';
                } else if (strpos($mime, 'webp') !== false) {
                    // Convert webp to jpg via GD if available.
                    $tmpfile = self::convert_webp_to_jpg($tmpfile, $tmpdir);
                    $ext = 'JPEG';
                } else {
                    // Let TCPDF try to autodetect.
                    $ext = '';
                }
                $pdf->Image($tmpfile, 0, 0, (float)$template->width_mm, (float)$template->height_mm, $ext, '', '', false, 300, '', false, false, 0, false, false, false);
                @unlink($tmpfile);
            }
        }

        // 2) Sort fields by z_index then draw each.
        usort($fields, function ($a, $b) {
            return ((int)$a->z_index) <=> ((int)$b->z_index);
        });

        foreach ($fields as $f) {
            $this->draw_field($pdf, $f, $user, $lp, $generation, $verificationurl, (float)$template->width_mm, (float)$template->height_mm);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Draw a single template field on the PDF.
     */
    private function draw_field(
        \TCPDF $pdf,
        stdClass $f,
        stdClass $user,
        ?stdClass $lp,
        ?stdClass $generation,
        string $verificationurl,
        float $pagew,
        float $pageh
    ): void {
        $type = (string)$f->field_type;
        $x = (float)$f->x_mm;
        $y = (float)$f->y_mm;
        $w = max(5.0, (float)$f->width_mm);
        $h = max(5.0, (float)$f->height_mm);
        $rotation = (float)$f->rotation;
        $align = (string)$f->align;
        $family = self::resolve_tcpdf_font((string)$f->font_family);
        $style = ((string)$f->font_weight) === 'bold' ? 'B' : '';
        $fontsize = (float)$f->font_size;
        $lineheight = max(0.8, (float)$f->line_height);
        $color = manager::normalize_color((string)$f->font_color);
        [$r, $g, $b] = self::hex_to_rgb($color);

        // Build content based on field type.
        if ($type === manager::FIELD_QR) {
            $this->draw_qr($pdf, $verificationurl, $x, $y, $w, $h, $rotation);
            return;
        }
        $text = '';
        if ($type === manager::FIELD_VARIABLE) {
            $text = manager::resolve_variable((string)$f->variable_code, $user, $lp, $generation);
        } else if ($type === manager::FIELD_CUSTOM) {
            $text = self::substitute_placeholders((string)$f->custom_text, $user, $lp, $generation);
        } else if ($type === manager::FIELD_STATIC) {
            $text = (string)$f->static_text;
        }
        if ($text === '') {
            return;
        }

        $pdf->SetFont($family, $style, $fontsize, '', false);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->setCellHeightRatio($lineheight);
        // If rotated, use StartTransform/StopTransform so the bounding box stays mm-precise.
        if (abs($rotation) > 0.01) {
            $pdf->StartTransform();
            $pdf->Rotate($rotation, $x + $w / 2.0, $y + $h / 2.0);
        }
        $pdf->SetXY($x, $y);
        // MultiCell keeps text wrapping inside the rotated bbox.
        $pdf->MultiCell($w, $fontsize * 0.4 * $lineheight, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, $h, 'T', false);
        if (abs($rotation) > 0.01) {
            $pdf->StopTransform();
        }
    }

    /**
     * Draw a QR code field with the verification URL.
     */
    private function draw_qr(\TCPDF $pdf, string $url, float $x, float $y, float $w, float $h, float $rotation): void {
        if ($url === '') {
            return;
        }
        $size = min($w, $h);
        $style = [
            'border' => 0,
            'vpadding' => 0,
            'hpadding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
            'module_width' => 1,
            'module_height' => 1,
        ];
        if (abs($rotation) > 0.01) {
            $pdf->StartTransform();
            $pdf->Rotate($rotation, $x + $w / 2.0, $y + $h / 2.0);
        }
        $pdf->write2DBarcode($url, 'QRCODE,Q', $x, $y, $size, $size, $style, 'N');
        if (abs($rotation) > 0.01) {
            $pdf->StopTransform();
        }
    }

    /**
     * Substitute {{var}} tokens inside a custom text with the value from manager.
     */
    public static function substitute_placeholders(string $text, stdClass $user, ?stdClass $lp, ?stdClass $generation): string {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($user, $lp, $generation) {
            $code = strtolower($m[1]);
            return manager::resolve_variable($code, $user, $lp, $generation);
        }, $text);
    }

    /**
     * Convert a #RRGGBB hex into [r,g,b] (0..255 ints).
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function hex_to_rgb(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return [0, 0, 0];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Best-effort WebP to JPEG conversion so TCPDF can embed the background.
     *
     * @param string $srcpath Absolute path to the source webp file.
     * @param string $tmpdir Temporary directory for the converted file.
     * @return string Absolute path to a JPEG file (same as src if conversion failed).
     */
    private static function convert_webp_to_jpg(string $srcpath, string $tmpdir): string {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagejpeg')) {
            return $srcpath;
        }
        $img = @imagecreatefromwebp($srcpath);
        if (!$img) {
            return $srcpath;
        }
        $outpath = $tmpdir . '/bg_' . uniqid('', true) . '.jpg';
        imagejpeg($img, $outpath, 90);
        imagedestroy($img);
        return file_exists($outpath) ? $outpath : $srcpath;
    }
}
