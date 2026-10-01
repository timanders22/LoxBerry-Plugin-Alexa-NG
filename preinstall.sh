#!/bin/bash
# Alexa NG - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Laeuft bei JEDEM Einbau, nach dem Aufraeumen der alten Fassung. Eine
# Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh anlegt (ohne
# Altersgrenze, Entscheidung 1 und 8). Dann tut es nichts.
#
# Ohne Marke ist es eine NEUINSTALLATION (Entscheidung 1): liegengebliebene
# Zweitschriften - Einstellungen UND Amazon-Anmeldung -, die Namenszuordnung
# der Geraete und ein alter Upgrade-Bestand gehen nach <name>.alt, gemeldet
# mit GENAU EINER <WARNING>. Ein altes Erneuerungs-Token wird nicht still
# weiterbenutzt. Die Selbstheilung der Bibliothek liest .alt nie; die
# Deinstallation raeumt es ab.

PFOLDER="${3:-alexang}"
case "$PFOLDER" in
    ''|.|..|*/*) echo "<WARNING> Unbrauchbarer Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
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
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - nichts beiseitegelegt."
    exit 0
fi

[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

C="$BASE/config/plugins/$PFOLDER"
D="$BASE/data/plugins/$PFOLDER"
BEISEITE=""
FEST=""
for ZIEL in "$C.backup.json" "$C.backup.json.kaputt" "$C.backup.amazon.json" "$C.backup.amazon.json.kaputt" \
            "$D.namen.json" "$D.upgrade_bestand"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null && [ ! -e "$ZIEL" ]; then
            if [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ]; then chmod 600 "$ZIEL.alt" 2>/dev/null; fi
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    AX_TEXT="<WARNING> Neuinstallation: Einstellungen und Amazon-Anmeldung einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && AX_TEXT="$AX_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && AX_TEXT="$AX_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$AX_TEXT"
fi
exit 0
