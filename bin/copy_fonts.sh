#!/bin/bash
set -e
FAMILIES="ofl/lato ofl/montserrat ofl/poppins ofl/playfairdisplay ofl/cormorantgaramond ofl/lora ofl/cinzel ofl/cinzeldecorative ofl/greatvibes ofl/dancingscript ofl/pinyonscript ofl/pacifico ofl/sacramento ofl/petitformalscript ofl/alexbrush ofl/parisienne ofl/ebgaramond ofl/merriweather ofl/oswald ofl/raleway ofl/opensans ofl/abrilfatface ofl/marcellus ofl/italiana ofl/librebaskerville ofl/allura ofl/tangerine ofl/mrdehaviland ofl/italianno ofl/mrssaintdelafield ofl/bilbo ofl/rougescript ofl/allisonscript ofl/labelleaurore ofl/halimun"
DEST="/var/www/html/moodle/local/grupomakro_core/tcpdf_fonts"
mkdir -p "$DEST"
TOTAL=0
for fam in $FAMILIES; do
    KEY=$(basename "$fam")
    for f in /tmp/google-fonts/$fam/*.ttf; do
        [ -f "$f" ] || continue
        base=$(basename "$f")
        dest="$DEST/${KEY}__${base}"
        cp "$f" "$dest"
        TOTAL=$((TOTAL+1))
    done
done
echo "Copied: $TOTAL files"
du -sh "$DEST"
ls "$DEST" | wc -l