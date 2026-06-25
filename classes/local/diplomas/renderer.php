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
     * Maps our custom font family names to TCPDF built-in fonts that are
     * actually shipped with the bundled tcpdf library on this Moodle
     * install. The available core fonts in /lib/tcpdf/fonts are:
     *   helvetica, times, courier, freesans, freeserif, freemono
     * and their bold/italic variants. Older maps referenced dejavu*
     * which are NOT present in this distro, causing TCPDF to abort
     * with "Could not include font definition file".
     *
     * @var array<string,string>
     */
    private const FONT_MAP = [
        // Direct aliases (case/formatting-normalized keys).
        'helvetica' => 'helvetica',
        'arial' => 'helvetica',
        'times' => 'times',
        'timesnewroman' => 'times',
        'courier' => 'courier',
        'dejavusans' => 'freesans',
        'dejavuserif' => 'freeserif',
        'freesans' => 'freesans',
        'freeserif' => 'freeserif',
        'freemono' => 'freemono',
        // Google Fonts mapped to the closest core font that ships with tcpdf.
        // Sans-serif workhorses -> helvetica / freesans (use freesans so
        // the metrics line up with the editor preview).
        'opensans' => 'freesans',
        'roboto' => 'freesans',
        'montserrat' => 'helvetica',
        'lato' => 'helvetica',
        'poppins' => 'helvetica',
        'raleway' => 'helvetica',
        'oswald' => 'helvetica',
        'notosans' => 'freesans',
        'ptsans' => 'freesans',
        'verdana' => 'helvetica',
        'tahoma' => 'helvetica',
        'gillsans' => 'helvetica',
        'segoeui' => 'helvetica',
        'systemui' => 'helvetica',
        'sansserif' => 'helvetica',
        // Editorial serifs -> times / freeserif.
        'lora' => 'times',
        'playfairdisplay' => 'times',
        'merriweather' => 'times',
        'notoserif' => 'freeserif',
        'ptserif' => 'freeserif',
        'garamond' => 'times',
        'georgia' => 'times',
        'palatino' => 'times',
        'cormorantgaramond' => 'freeserif',
        'ebgaramond' => 'freeserif',
        'librebaskerville' => 'times',
        'cinzel' => 'times',
        'cinzeldecorative' => 'times',
        'marcellus' => 'times',
        'italiana' => 'times',
        'bodoni' => 'times',
        'abrilfatface' => 'times',
        'serif' => 'times',
        // Script / calligraphy -> freeserif (no script face in core tcpdf;
        // italic freeserif is the closest readable approximation).
        'dancingscript' => 'freeserif',
        'pacifico' => 'freeserif',
        'greatvibes' => 'freeserif',
        'petitformalscript' => 'freeserif',
        'pinyonscript' => 'freeserif',
        'allura' => 'freeserif',
        'tangerine' => 'freeserif',
        'sacramento' => 'freeserif',
        'alexbrush' => 'freeserif',
        'parisienne' => 'freeserif',
        'mrdehaviland' => 'freeserif',
        'italianno' => 'freeserif',
        'mrssaintdelafield' => 'freeserif',
        'bilbo' => 'freeserif',
        'rougescript' => 'freeserif',
        'allisonscript' => 'freeserif',
        'labelleaurore' => 'freeserif',
        'halimun' => 'freeserif',
        // Monospace.
        'monospace' => 'courier',
    ];

    /**
     * Map a user-supplied font family to a TCPDF-supported core font.
     */
    public static function resolve_tcpdf_font(string $family): string {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $family));
        if (isset(self::FONT_MAP[$key])) {
            return self::FONT_MAP[$key];
        }
        // Fallback by category heuristic so a typo or unknown face still
        // produces a readable PDF (serif for serif-ish names, mono for
        // mono-ish names, sans for everything else).
        if (str_contains($key, 'mono') || str_contains($key, 'courier') || str_contains($key, 'console')) {
            return 'courier';
        }
        if (str_contains($key, 'script') || str_contains($key, 'hand') || str_contains($key, 'brush')
            || str_contains($key, 'calli') || str_contains($key, 'vibes') || str_contains($key, 'serif')) {
            return 'freeserif';
        }
        return 'helvetica';
    }

    /**
     * Map a friendly alignment token (left/center/right/justify or the
     * TCPDF single-letter codes) to the single-letter code TCPDF expects
     * on MultiCell / writeHTML etc. Anything unrecognised falls back to
     * 'L' so the renderer never throws an undefined-index notice on
     * legacy rows that might have an empty align column.
     *
     * @param string $align
     * @return string One of 'L', 'C', 'R', 'J'.
     */
    public static function normalize_tcpdf_align(string $align): string {
        $a = strtolower(trim($align));
        switch ($a) {
            case 'l':
            case 'left':
                return 'L';
            case 'c':
            case 'center':
            case 'centre':
                return 'C';
            case 'r':
            case 'right':
                return 'R';
            case 'j':
            case 'justify':
            case 'justified':
                return 'J';
            default:
                return 'L';
        }
    }

    /**
     * Combine the editor's font-weight (and optional font-style) into a
     * TCPDF style string. Accepts 'bold' / 'normal' / numeric weights
     * (300..900) for the weight, plus an optional 'italic' / 'oblique'
     * style hint. Returns something like '', 'B', 'I', or 'BI'.
     */
    public static function normalize_tcpdf_style(string $weight, string $style = ''): string {
        $w = strtolower(trim($weight));
        $s = strtolower(trim($style));
        $bold = false;
        // Named weights.
        if (in_array($w, ['bold', 'bolder', 'black', 'heavy'], true)) {
            $bold = true;
        }
        // Numeric weights (CSS): >= 600 counts as bold.
        if (is_numeric($w) && (int)$w >= 600) {
            $bold = true;
        }
        $italic = in_array($s, ['italic', 'oblique', 'i'], true)
            || in_array($w, ['italic', 'oblique'], true);
        $out = '';
        if ($bold) { $out .= 'B'; }
        if ($italic) { $out .= 'I'; }
        return $out;
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
        $align = self::normalize_tcpdf_align((string)$f->align);
        $family = self::resolve_tcpdf_font((string)$f->font_family);
        $style = self::normalize_tcpdf_style((string)$f->font_weight, (string)($f->font_style ?? ''));
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
