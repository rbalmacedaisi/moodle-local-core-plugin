<?php
// Download Google Fonts TTF files for the diploma renderer.
//
// Google Fonts hosts source TTF files at:
//   https://github.com/google/fonts/raw/main/<category>/<family>/<filename>.ttf
//
// Categories used:
//   ofl  = Open Font License
//   apache = Apache License
//
// This script is meant to be run ONCE on the server (or any machine
// with internet access) to populate the tcpdf_fonts/ directory.

define('CLI_SCRIPT', true);

$root = '/var/www/html/moodle/local/grupomakro_core';
$destdir = $root . '/tcpdf_fonts';
if (!is_dir($destdir)) {
    mkdir($destdir, 0755, true);
}

// Family -> [category, dir, regular_filename, bold_filename, italic_filename, bolditalic_filename].
// dir is the directory slug under the category (lowercase, no spaces).
// filenames are looked up by querying the GitHub repo API for the family dir.
$families = [
    // Sans-serif workhorses
    'opensans'      => ['ofl', 'opensans'],
    'roboto'        => ['apache', 'roboto'],
    'lato'          => ['ofl', 'lato'],
    'montserrat'    => ['ofl', 'montserrat'],
    'poppins'       => ['ofl', 'poppins'],
    'raleway'       => ['ofl', 'raleway'],
    'oswald'        => ['ofl', 'oswald'],
    // Editorial serifs
    'playfairdisplay'   => ['ofl', 'playfairdisplay'],
    'cormorantgaramond' => ['ofl', 'cormorantgaramond'],
    'ebgaramond'        => ['ofl', 'ebgaramond'],
    'librebaskerville'  => ['ofl', 'librebaskerville'],
    'lora'              => ['ofl', 'lora'],
    'merriweather'      => ['ofl', 'merriweather'],
    'cinzel'            => ['ofl', 'cinzel'],
    'cinzeldecorative'  => ['ofl', 'cinzeldecorative'],
    'marcellus'         => ['ofl', 'marcellus'],
    'italiana'          => ['ofl', 'italiana'],
    'bodoni'            => ['ofl', 'bodoni Moda'],
    'abrilfatface'      => ['ofl', 'abrilfatface'],
    'garamond'          => ['ofl', 'ebgaramond'], // fallback alias
    // Script / calligraphy
    'petitformalscript' => ['ofl', 'petitformalscript'],
    'greatvibes'        => ['ofl', 'greatvibes'],
    'pinyonscript'      => ['ofl', 'pinyonscript'],
    'allura'            => ['ofl', 'allura'],
    'tangerine'         => ['ofl', 'tangerine'],
    'sacramento'        => ['ofl', 'sacramento'],
    'alexbrush'         => ['ofl', 'alexbrush'],
    'dancingscript'     => ['ofl', 'dancingscript'],
    'pacifico'          => ['ofl', 'pacifico'],
    'parisienne'        => ['ofl', 'parisienne'],
    'mrdehaviland'      => ['ofl', 'mrdehaviland'],
    'italianno'         => ['ofl', 'italianno'],
    'mrssaintdelafield' => ['ofl', 'mrssaintdelafield'],
    'bilbo'             => ['ofl', 'bilbo'],
    'rougescript'       => ['ofl', 'rougescript'],
    'allisonscript'     => ['ofl', 'allisonscript'],
    'labelleaurore'     => ['ofl', 'labelleaurore'],
    'halimun'           => ['ofl', 'halimun'],
];

// Helper: list TTFs in a Google Fonts family directory via GitHub API.
function list_family_ttfs($category, $dirslug) {
    $url = "https://api.github.com/repos/google/fonts/contents/{$category}/{$dirslug}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'moodle-plugin-fonts/1.0',
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        return [];
    }
    $files = json_decode($body, true);
    if (!is_array($files)) {
        return [];
    }
    $ttfs = [];
    foreach ($files as $f) {
        if (!empty($f['name']) && strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) === 'ttf') {
            $ttfs[] = $f['name'];
        }
    }
    return $ttfs;
}

function download_to($url, $dest) {
    $ch = curl_init($url);
    $fp = fopen($dest, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'moodle-plugin-fonts/1.0',
        CURLOPT_TIMEOUT => 30,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return $ok && $code === 200 && filesize($dest) > 0;
}

$total = 0;
$ok = 0;
$failed = [];
foreach ($families as $key => [$cat, $dirslug]) {
    echo "=== $key ($cat/$dirslug) ===\n";
    $ttfs = list_family_ttfs($cat, $dirslug);
    if (!$ttfs) {
        echo "  no TTFs found\n";
        $failed[] = "$key (list)";
        continue;
    }
    foreach ($ttfs as $t) {
        $total++;
        $lower = strtolower($t);
        // Skip variable fonts (TCPDF can't handle them) and italic-only if no regular exists.
        if (strpos($lower, 'variablefont') !== false || strpos($lower, 'vf') !== false) {
            echo "  skip variable $t\n";
            continue;
        }
        $url = "https://github.com/google/fonts/raw/main/{$cat}/{$dirslug}/" . rawurlencode($t);
        $dest = $destdir . '/' . $key . '__' . $t;
        if (file_exists($dest) && filesize($dest) > 0) {
            echo "  already have $t\n";
            $ok++;
            continue;
        }
        if (download_to($url, $dest)) {
            $sz = filesize($dest);
            echo "  got $t ($sz bytes)\n";
            $ok++;
        } else {
            echo "  FAIL $t\n";
            $failed[] = "$key/$t";
        }
    }
}

echo "\n=== Summary ===\n";
echo "Downloaded: $ok / $total\n";
if ($failed) {
    echo "Failed:\n";
    foreach ($failed as $f) {
        echo "  - $f\n";
    }
}
echo "Done.\n";