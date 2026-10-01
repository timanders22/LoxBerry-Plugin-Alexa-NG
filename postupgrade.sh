#!/bin/bash
# Alexa NG - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Laeuft nach postinstall.sh (LoxBerry ruft beim Upgrade beide Haken; es
# wird NICHT an postinstall.sh weitergeleitet). Es meldet nur, was es
# nachgelesen hat, setzt die Rechte und raeumt die Upgrade-Marke ab (trap).
# Das Vervollstaendigen der Konfiguration um neue Schluessel macht der Takt
# beim naechsten Lauf (ax_config_heilen), mit einer Protokollzeile.

PFOLDER="${3:-alexang}"
case "$PFOLDER" in
    ''|.|..|*/*) echo "<WARNING> Unbrauchbarer Ordnername '$PFOLDER'."; exit 0 ;;
esac
ax_ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] && [ -f "$1/config/system/general.json" ]
}
lb_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if ax_ist_wurzel "$v"; then echo "$v"; return 0; fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
BASE=""
for AX_K in "$5" "$LBHOMEDIR"; do
    if ax_ist_wurzel "$AX_K"; then BASE="$AX_K"; break; fi
done
[ -n "$BASE" ] || BASE=$(lb_wurzel_suchen)
if [ -z "$BASE" ]; then
    echo "<WARNING> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen - nichts nachgesehen."
    exit 0
fi
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
ax_marke_weg() {
    ax_rc=$?
    rm -f "$MARKE" 2>/dev/null
    [ -e "$MARKE" ] && echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen - bitte von Hand loeschen."
    exit $ax_rc
}
trap ax_marke_weg EXIT

CF="$BASE/config/plugins/$PFOLDER/alexang.json"
AF="$BASE/config/plugins/$PFOLDER/amazon.json"
for AX_F in "$CF" "$AF" "$BASE/config/plugins/$PFOLDER.backup.json" "$BASE/config/plugins/$PFOLDER.backup.amazon.json"; do
    [ -f "$AX_F" ] && chmod 600 "$AX_F" 2>/dev/null
done
if command -v php >/dev/null 2>&1; then
    AX_LAGE=$(php -r '$c = json_decode((string) @file_get_contents($argv[1]), true);
        $a = json_decode((string) @file_get_contents($argv[2]), true);
        $t = is_array($c) && !empty($c["sprechtoken"]) && !empty($c["aktionstoken"]);
        $r = is_array($a) && isset($a["refresh_token"]) && is_string($a["refresh_token"]) && strpos($a["refresh_token"], "Atnr|") === 0;
        echo ($t ? "T1" : "T0") . ($r ? "A1" : "A0") . (is_array($c) ? "K" . count($c) : "K0");' "$CF" "$AF" 2>/dev/null)
    case "$AX_LAGE" in
        T1A1*) echo "<OK> Nachgelesen: Konfiguration mit beiden Token (${AX_LAGE#T1A1K} Schluessel) und Amazon-Anmeldung liegen vor." ;;
        T1A0*) echo "<OK> Nachgelesen: Konfiguration mit beiden Token liegt vor; keine Amazon-Anmeldung (Reiter Amazon-Anmeldung)." ;;
        *) echo "<INFO> Nachgelesen: noch keine eingerichtete Konfiguration - beim ersten Oeffnen der Oberflaeche entstehen die Token." ;;
    esac
fi
exit 0
