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
#   2. Befehlsabo anhalten (dienst.sh der ALTEN Fassung bis 1.0.5: status, dann stop)
#      bzw. die Befehlsbruecke (befehlsbruecke.sh, ab 1.0.6), ebenso die Hue-Probe
#      (hue_dienst.sh, ab 0.9.4); einen laufenden Hue-Vorgang samt Prozessgruppe
#      beenden, BEVOR der Container entfernt wird (F7, 1.0.6); das Netz bleibt (1.0.14).
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

# Gruppe 6a (1.0.6): die Befehlsbruecke der bisherigen Fassung anhalten (gibt es ab 1.0.6); postupgrade.sh
# startet sie danach, wenn sie eingeschaltet ist. Der Block fuer dienst.sh bleibt fuer den Umstieg von 1.0.5.
BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    if timeout 40 bash "$BBSH" stop >/dev/null 2>&1; then
        echo "<OK> Befehlsbruecke fuer das Update angehalten (postupgrade.sh startet sie danach, wenn sie eingeschaltet ist)."
    else
        echo "<WARNING> Die Befehlsbruecke liess sich nicht anhalten; der Takt der neuen Fassung zieht sie nach."
    fi
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
# Fremdbefund F7 (1.0.6): einen laufenden bin/hue_vorgang.php samt Prozessgruppe (docker build bis 1800 s) beenden,
# BEVOR Container und Netz entfernt werden - sonst legte er sie danach neu an (falsches "nachgesehen"). Gefunden wird
# argumentweise: argv[1] genau unser Skript (Regeln/06). setsid macht den Vorgang zum Gruppenfuehrer; dann geht TERM an
# die ganze Gruppe (auch den docker-Client), nach 10 s KILL. Ausgabe: Zahl der gefundenen Vorgaenge; Rueckgabe 1, wenn
# einer weiterlebt.
ax_lebt() {   # $1 PID - laeuft und ist kein Zombie
    [ -r "/proc/$1/stat" ] || return 1
    [ "$(sed 's/^.*) //' "/proc/$1/stat" 2>/dev/null | cut -d' ' -f1)" != "Z" ]
}
ax_vorgang_beenden() {   # $1 bin-Ordner des Plugins
    local prog="$1/hue_vorgang.php" d pid pg i n
    local -a a pids=()
    for d in /proc/[0-9]*; do
        mapfile -d '' -t a 2>/dev/null < "$d/cmdline" || continue
        [ "${#a[@]}" -ge 2 ] && [ "${a[1]}" = "$prog" ] || continue
        pids+=("${d#/proc/}")
    done
    if [ "${#pids[@]}" -eq 0 ]; then echo 0; return 0; fi
    for pid in "${pids[@]}"; do
        pg=$(sed 's/^.*) //' "/proc/$pid/stat" 2>/dev/null | cut -d' ' -f3)
        if [ "$pg" = "$pid" ]; then kill -TERM -- "-$pid" 2>/dev/null; else kill -TERM "$pid" 2>/dev/null; fi
    done
    i=0
    while [ $i -lt 50 ]; do
        n=0
        for pid in "${pids[@]}"; do ax_lebt "$pid" && n=$((n + 1)); done
        [ $n -eq 0 ] && break
        sleep 0.2
        i=$((i + 1))
    done
    for pid in "${pids[@]}"; do
        if ax_lebt "$pid"; then kill -KILL -- "-$pid" 2>/dev/null; kill -KILL "$pid" 2>/dev/null; fi
    done
    sleep 0.5
    n=0
    for pid in "${pids[@]}"; do ax_lebt "$pid" && n=$((n + 1)); done
    echo "${#pids[@]}"
    [ $n -eq 0 ]
}
# Die ALTE Fassung (bis 1.0.5) beendet einen laufenden Vorgang in --hue-entfernen nicht - deshalb hier, vorher.
AX_VG=$(ax_vorgang_beenden "$BASE/bin/plugins/$PFOLDER"); AX_VRC=$?
if [ "$AX_VRC" != 0 ]; then
    echo "<WARNING> Ein laufender Hue-Vorgang (hue_vorgang.php) liess sich nicht beenden - Container und Netz nach dem Update pruefen (Reiter Test)."
elif [ "$AX_VG" != 0 ]; then
    echo "<OK> Laufenden Hue-Vorgang (Anlegen bzw. docker build) samt Prozessgruppe fuer das Update beendet."
fi
# Hue-Probe auf eigener Netzadresse (Nr. 41): fuer das Update NUR den Container entfernen (1.0.14).
# Der Container bindet bin/ax_hue.php und bin/ax_hue_container.php ein; der Installer loescht und schreibt diese
# Dateien neu, und das Label de.loxberry.plugin.def haengt an den Pfaden, nicht am Inhalt - ein weiterlaufender
# Container fuehre also mit dem alten Skript weiter, und der Takt saehe keinen Grund, ihn neu anzulegen. Das Netz
# dagegen traegt keine Plugin-Datei: der Takt benutzt es nach dem Update wieder. Bis 1.0.13 wurde es hier ebenfalls
# entfernt; nutzt Audioserver NG es mit ("vorhandenes Netz mitnutzen"), scheiterte das mit NETZ_ENTFERNEN|1 und
# einem falschen Rat (Alexa NG 1.0.12 -> 1.0.13, 10.11.2026), und bei angehaltenem Nachbarn naehme es ihm das Netz.
# Hier und nicht ueber ax_takt.php --hue-entfernen: preupgrade ruft die ALTE, installierte Fassung des Takts.
# Gemeldet wird: <OK> entfernt und nachgesehen; <WARNING> wenn er danach noch da ist oder Docker nicht antwortet,
# obwohl die Merkdatei einen angelegten Container nennt. Kein Docker oder kein Container: still.
AX_DOCKER=$(command -v docker 2>/dev/null)
AX_MERK="$BASE/data/plugins/$PFOLDER/hue_docker.json"
if [ -n "$AX_DOCKER" ]; then
    AX_FILTER=(--filter "label=de.loxberry.plugin.folder=$PFOLDER" --filter "label=de.loxberry.plugin.name=$PFOLDER-hue")
    AX_IDS=$(timeout 20 "$AX_DOCKER" ps -a -q --no-trunc "${AX_FILTER[@]}" 2>/dev/null); AX_RC=$?
    if [ "$AX_RC" != 0 ]; then
        if grep -q '"angelegt": *1' "$AX_MERK" 2>/dev/null; then
            echo "<WARNING> Hue-Probe auf eigener Netzadresse: Docker antwortet nicht (rc $AX_RC) - der Container wurde fuer das Update nicht entfernt."
            echo "<WARNING> Nach dem Update im Reiter Test nachsehen; laeuft er mit der alten Fassung weiter: docker rm -f lb-$PFOLDER-hue (der Takt legt ihn neu an)."
        fi
    elif [ -n "$AX_IDS" ]; then
        AX_N=0
        for AX_ID in $AX_IDS; do
            timeout 40 "$AX_DOCKER" stop -t 10 "$AX_ID" >/dev/null 2>&1
            timeout 30 "$AX_DOCKER" rm -f "$AX_ID" >/dev/null 2>&1
            AX_N=$((AX_N + 1))
        done
        AX_REST=$(timeout 20 "$AX_DOCKER" ps -a -q --no-trunc "${AX_FILTER[@]}" 2>/dev/null); AX_RC=$?
        if [ "$AX_RC" = 0 ] && [ -z "$AX_REST" ]; then
            echo "<OK> Hue-Probe auf eigener Netzadresse: Container fuer das Update entfernt und nachgesehen ($AX_N); das Netz lb-$PFOLDER-macvlan bleibt. Der Takt legt den Container danach neu an, wenn sie eingeschaltet ist."
        else
            echo "<WARNING> Hue-Probe auf eigener Netzadresse: Container nicht entfernt (rc $AX_RC, noch da: $(echo "$AX_REST" | wc -w)). Von Hand: docker rm -f lb-$PFOLDER-hue (der Takt legt ihn neu an)."
        fi
    fi
fi

BESTAND="$BASE/data/plugins/$PFOLDER.upgrade_bestand"
case "$BESTAND" in
    "$BASE"/data/plugins/*.upgrade_bestand) ;;
    *) echo "<FAIL> Unerwarteter Pfad fuer den Bestand."; exit 2 ;;
esac
if [ -e "$BESTAND" ]; then
    rm -rf "${BESTAND:?}"
    if [ -e "$BESTAND" ]; then
        echo "<FAIL> Ein alter Bestand unter $BESTAND liess sich nicht entfernen - es wird nichts gesichert. Die Aktualisierung"
        echo "<FAIL> wird abgebrochen, die bisherige Fassung bleibt installiert."
        rm -f "$MARKE" 2>/dev/null
        exit 2
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
    echo "<FAIL> Nicht alles liess sich beiseitelegen - die Aktualisierung wird abgebrochen, damit der Purge die Originale"
    echo "<FAIL> nicht loescht. Die bisherige Fassung bleibt installiert."
    rm -f "$MARKE" 2>/dev/null
    exit 2
fi
exit 0
