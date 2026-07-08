<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
require_once '/var/www/html/moodle/lib/outputcomponents.php';
$user = core_user::get_user(2, '*', MUST_EXIST);
core\session\manager::set_user($user);
$PAGE = new moodle_page();
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('embedded');
$PAGE->set_url('/local/grupomakro_core/pages/diplomatemplates.php');
$PAGE->set_title('test');
echo $OUTPUT->header();
// Now echo the @font-face generation part
$ttfkeytofamily = [
    'opensans'              => 'Open Sans',
    'roboto'                => 'Roboto',
    'lato'                  => 'Lato',
    'montserrat'            => 'Montserrat',
    'poppins'               => 'Poppins',
    'raleway'               => 'Raleway',
    'oswald'                => 'Oswald',
    'playfairdisplay'       => 'Playfair Display',
    'cormorantgaramond'     => 'Cormorant Garamond',
    'ebgaramond'            => 'EB Garamond',
    'librebaskerville'      => 'Libre Baskerville',
    'lora'                  => 'Lora',
    'merriweather'          => 'Merriweather',
    'cinzel'                => 'Cinzel',
    'cinzeldecorative'      => 'Cinzel Decorative',
    'marcellus'             => 'Marcellus',
    'italiana'              => 'Italiana',
    'bodoni'                => 'Bodoni Moda',
    'abrilfatface'          => 'Abril Fatface',
    'petitformalscript'     => 'Petit Formal Script',
    'greatvibes'            => 'Great Vibes',
    'pinyonscript'          => 'Pinyon Script',
    'allura'                => 'Allura',
    'tangerine'             => 'Tangerine',
    'sacramento'            => 'Sacramento',
    'alexbrush'             => 'Alex Brush',
    'dancingscript'         => 'Dancing Script',
    'pacifico'              => 'Pacifico',
    'parisienne'            => 'Parisienne',
    'mrdehaviland'          => 'Mr De Haviland',
    'italianno'             => 'Italianno',
    'mrssaintdelafield'     => 'Mrs Saint Delafield',
    'bilbo'                 => 'Bilbo',
    'rougescript'           => 'Rouge Script',
    'allisonscript'         => 'Allison Script',
    'labelleaurore'         => 'La Belle Aurore',
    'halimun'               => 'Halimun',
    'notosans'              => 'Noto Sans',
    'notoserif'             => 'Noto Serif',
    'ptsans'                => 'PT Sans',
    'ptserif'               => 'PT Serif',
];
$fontdir = $CFG->dirroot . '/local/grupomakro_core/tcpdf_fonts';
echo "FONT-FACE BLOCKS:\n";
$emitted = [];
foreach ($ttfkeytofamily as $key => $family) {
    $candidates = glob($fontdir . '/' . $key . '__*.ttf') ?: [];
    $ttf = null;
    foreach ($candidates as $cand) {
        if (strpos($cand, '[wght]') === false && strpos($cand, '[wdth') === false) {
            $ttf = $cand;
            break;
        }
    }
    if (!$ttf || !is_readable($ttf)) { continue; }
    if (isset($emitted[$family])) { continue; }
    $emitted[$family] = true;
    echo "  @font-face family='$family' from " . basename($ttf) . "\n";
}