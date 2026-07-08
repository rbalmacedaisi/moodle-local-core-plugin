<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
require_once '/var/www/html/moodle/lib/outputcomponents.php';

$fontdir = $CFG->dirroot . '/local/grupomakro_core/tcpdf_fonts';
$ttffiles = glob($fontdir . '/*.ttf') ?: [];
echo "TTF count: " . count($ttffiles) . "\n";
echo "First 10:\n";
foreach (array_slice($ttffiles, 0, 10) as $f) {
    echo "  " . basename($f) . "\n";
}
echo "--- Emitted @font-face blocks (filtered) ---\n";
$found = [];
foreach ($ttffiles as $ttf) {
    $basename = basename($ttf);
    if (strpos($basename, '[wght]') !== false) continue;
    $parts = explode('__', $basename, 2);
    if (count($parts) < 2) continue;
    $key = $parts[0]; $original = $parts[1];
    $family = preg_replace('/-(Regular|Bold|Italic|BoldItalic|Black|Light|Medium|Thin|ExtraBold|ExtraLight|SemiBold|ExtraLightItalic|BlackItalic|LightItalic|MediumItalic|SemiBoldItalic|ExtraBoldItalic|RegularItalic)\.ttf$/i', '', $original);
    if ($family === '' || $family === $original) continue;
    if (in_array($family, ['Lato', 'Cinzel', 'Great Vibes', 'GreatVibes', 'Montserrat', 'Playfair Display', 'PlayfairDisplay', 'Cinzel Decorative', 'Cormorant Garamond', 'CormorantGaramond', 'Merriweather', 'Open Sans', 'OpenSans', 'Roboto', 'Poppins', 'Lora', 'Pacifico', 'Dancing Script', 'DancingScript', 'Petit Formal Script', 'PetitFormalScript', 'Pinyon Script', 'PinyonScript'])) {
        $url = $CFG->wwwroot . '/local/grupomakro_core/pages/diploma_font.php?key=' . $key;
        echo "  family='" . $family . "' key='" . $key . "' from " . $basename . " -> $url\n";
        $found[$family] = true;
    }
}
echo "--- Families found ---\n";
foreach (array_keys($found) as $f) echo "  $f\n";