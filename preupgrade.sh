#!/bin/bash
# Alexa NG - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Der Installer raeumt unmittelbar nach diesem Skript config/plugins/<ordner>/
# und data/plugins/<ordner>/ ab (purge_installation, Regeln/06). Dies ist das
# einzige Rettungsfenster. Reihenfolge:
#   1. Upgrade-Marke NEBEN den Datenordner (Unixzeit) - als Erstes. Ohne sie
#      hielte preinstall.sh das Update fuer eine Neuinstallation; deshalb
#      Abbruch mit rc 2, VOR purge_installation.
#   2. Befehlsabo anhalten (dienst.sh der ALTEN Fassung: status, dann stop),
#      ebenso die Hue-Probe (hue_dienst.sh, ab 0.9.4).
#   3. Einen alten Bestand erst wegraeumen, dann Einstellungen und
#      Anmeldung frisch nach data/plugins/<ordner>.upgrade_bestand/ (0700,
#      Dateien 0600) sichern und mit cmp nachsehen. Die Zweitschriften
#      neben dem Konfigordner bleiben ohnehin liegen.

PFOLDER="${3:-alexang}"
case "$PFOLDER" in
    ''|.|..|*/*) echo "<FAIL> Unbrauchbarer Ordnername '$PFOLDER'."; exit 2 ;;
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
    echo "<FAIL> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen - die Aktualisierung wird abgebrochen."
    exit 2
fi

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
{ date +%s > "$MARKE"; } 2>/dev/null
if ! grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi

DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
if [ -f "$DIENST" ]; then
    if timeout 15 /bin/sh "$DIENST" status >/dev/null 2>&1; then
        if timeout 30 /bin/sh "$DIENST" stop >/dev/null 2>&1 && ! timeout 15 /bin/sh "$DIENST" status >/dev/null 2>&1; then
            echo "<OK> Befehlsabo fuer das Update angehalten (der Takt startet es danach wieder, wenn es eingeschaltet ist)."
        else
            echo "<WARNING> Das Befehlsabo liess sich nicht anhalten; postinstall.sh haelt jeden verbliebenen Dienst an."
        fi
    fi
fi
HUEDIENST="$BASE/bin/plugins/$PFOLDER/hue_dienst.sh"
if [ -f "$HUEDIENST" ]; then
    if timeout 15 /bin/sh "$HUEDIENST" status >/dev/null 2>&1; then
        if timeout 30 /bin/sh "$HUEDIENST" stop >/dev/null 2>&1 && ! timeout 15 /bin/sh "$HUEDIENST" status >/dev/null 2>&1; then
            echo "<OK> Hue-Probe fuer das Update angehalten (der Takt startet sie danach wieder, wenn sie eingeschaltet ist)."
        else
            echo "<WARNING> Die Hue-Probe liess sich nicht anhalten; postinstall.sh haelt jede verbliebene an."
        fi
    fi
fi

# Nr. 41: Container und Netz der Hue-Probe auf eigener Netzadresse entfernen. Der
# Container haengt mit Dateien aus bin/ und data/ - beides tauscht das Update
# aus; der Takt der neuen Fassung legt ihn danach neu an, wenn er eingeschaltet ist.
PHP=$(command -v php 2>/dev/null)
TAKT="$BASE/bin/plugins/$PFOLDER/ax_takt.php"
if [ -n "$PHP" ] && [ -f "$TAKT" ] && grep -q -- '--hue-entfernen' "$TAKT" 2>/dev/null; then
    AX_AUS=$(LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" timeout 150 "$PHP" "$TAKT" --hue-entfernen 2>/dev/null)
    case "$AX_AUS" in
        HUE_ENTFERNT*) echo "<OK> Hue-Probe auf eigener Netzadresse fuer das Update entfernt (${AX_AUS}); der Takt legt sie danach neu an, wenn sie eingeschaltet ist." ;;
        HUE_NICHTS*|HUE_KEIN_DOCKER*) ;;
        *) echo "<WARNING> Hue-Probe auf eigener Netzadresse: Container oder Netz nicht entfernt (${AX_AUS:-keine Antwort}). Von Hand: docker rm -f lb-$PFOLDER-hue; docker network rm lb-$PFOLDER-macvlan" ;;
    esac
fi

BESTAND="$BASE/data/plugins/$PFOLDER.upgrade_bestand"
case "$BESTAND" in
    "$BASE"/data/plugins/*.upgrade_bestand) ;;
    *) echo "<FAIL> Unerwarteter Pfad fuer den Bestand."; exit 2 ;;
esac
if [ -e "$BESTAND" ]; then
    rm -rf "${BESTAND:?}"
    if [ -e "$BESTAND" ]; then
        echo "<WARNING> Ein alter Bestand unter $BESTAND liess sich nicht entfernen - es wird nichts gesichert;"
        echo "<WARNING> die Zweitschriften neben dem Konfigordner holen die Einstellungen zurueck."
        exit 0
    fi
fi
( umask 077 && mkdir -p "$BESTAND" ) 2>/dev/null
chmod 700 "$BESTAND" 2>/dev/null
AX_FEHL=0
ax_ablegen() {   # $1 Quelle, $2 Name
    [ -f "$1" ] || return 0
    if ( umask 077 && cp "$1" "$BESTAND/$2" ) 2>/dev/null && cmp -s "$1" "$BESTAND/$2"; then
        chmod 600 "$BESTAND/$2" 2>/dev/null
        echo "<OK> $2 fuer das Update beiseitegelegt."
    else
        AX_FEHL=1
        echo "<WARNING> $2 liess sich nicht beiseitelegen."
    fi
}
ax_ablegen "$BASE/config/plugins/$PFOLDER/alexang.json" alexang.json
ax_ablegen "$BASE/config/plugins/$PFOLDER/amazon.json" amazon.json
date +%s > "$BESTAND/stand" 2>/dev/null
if [ "$AX_FEHL" != 0 ]; then
    echo "<WARNING> Die Zweitschriften neben dem Konfigordner holen die Einstellungen dann zurueck."
fi
exit 0
