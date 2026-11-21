#!/bin/sh
# Alexa NG - Hue-Probe starten, anhalten, fragen (Bauart bin/dienst.sh).
#   hue_dienst.sh start | stop | status
# Rueckgabe: status 0 = laeuft, 1 = laeuft nicht; start/stop 0 = Wirkung
# nachgesehen, 1 = nicht gelungen, 3 = ein anderer start/stop laeuft gerade.
#
# DIE SPERRE STEHT HIER, NICHT IM DIENST (Regeln/03): eine flock-Sperre im
# PHP-Prozess erbte jedes Kind (mosquitto_pub), und kein Neustart kaeme mehr
# durch. Der Dienst bekommt den Deskriptor deshalb nicht mit (8<&-).
#
# "Laeuft" wird argumentweise gemessen: argv[0] ist ein php, argv[1] genau
# unser Skript, kein drittes Argument (Regeln/06). Die PID-Datei allein ist
# kein Beleg.

# 1.0.22 (Befund eines fremden Pruefers 21.11.2026, Bauart bin/befehlsbruecke.sh 1.0.1): zuerst ALLE geerbten
# Deskriptoren ueber 2 schliessen - sonst erbte die Hue-Probe (Dauerdienst) Sperren und Dateien des Aufrufers (Takt,
# Oberflaeche). Gerufen wird das Skript mit /bin/sh (dash); dash kann nur die Nummern 3 bis 9 schliessen. Ist bash da,
# laeuft das Skript deshalb einmal neu unter bash (alles hier ist POSIX und laeuft unter beiden; die Marke
# AX_HUE_DIENST_BASH verhindert eine Schleife); ohne bash schliesst es 3 bis 9. Der Deskriptor, ueber den die Schale
# das Skript liest, bleibt (dash: ab 10, bash: 255).
if [ -z "$BASH_VERSION" ] && [ -z "$AX_HUE_DIENST_BASH" ] && command -v bash >/dev/null 2>&1; then
    AX_HUE_DIENST_BASH=1; export AX_HUE_DIENST_BASH
    exec bash "$0" "$@"
fi
unset AX_HUE_DIENST_BASH
EIGEN=$(readlink -f "$0" 2>/dev/null)
for fd in /proc/$$/fd/*; do
    n=${fd##*/}
    case "$n" in ''|*[!0-9]*) continue ;; esac
    [ "$n" -gt 2 ] || continue
    [ "$(readlink "$fd" 2>/dev/null)" = "$EIGEN" ] && continue
    if [ -n "$BASH_VERSION" ]; then
        eval "exec $n>&-" 2>/dev/null
    else
        case "$n" in [3-9]) eval "exec $n>&-" 2>/dev/null ;; esac
    fi
done

SELF=$(cd "$(dirname "$0")" 2>/dev/null && pwd -P)
SKRIPT="$SELF/ax_hue.php"
PNAME=$(basename "$SELF")
if [ "$(basename "$(dirname "$SELF")")" = "plugins" ]; then
    BASE=$(cd "$SELF/../../.." 2>/dev/null && pwd -P)
    DDIR="$BASE/data/plugins/$PNAME"
    LDIR="$BASE/log/plugins/$PNAME"
    MARKE="$BASE/data/plugins/$PNAME.upgrade_laeuft"
elif [ -n "$LBHOMEDIR" ] && [ -n "$LBPPLUGINDIR" ]; then
    DDIR="$LBHOMEDIR/data/plugins/$(basename "$LBPPLUGINDIR")"
    LDIR="$LBHOMEDIR/log/plugins/$(basename "$LBPPLUGINDIR")"
    MARKE="$LBHOMEDIR/data/plugins/$(basename "$LBPPLUGINDIR").upgrade_laeuft"
else
    echo "hue_dienst.sh: keine LoxBerry-Installation erkannt - nichts getan." >&2
    exit 1
fi
PIDF="$DDIR/hue.pid"

ist_unser() {   # $1 PID
    [ -n "$1" ] && [ -r "/proc/$1/cmdline" ] || return 1
    a0=$(tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null | sed -n '1p')
    a1=$(tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null | sed -n '2p')
    a2=$(tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null | sed -n '3p')
    [ "$a1" = "$SKRIPT" ] || return 1
    [ -z "$a2" ] || return 1
    echo "$a0" | grep -qE '(^|/)php[0-9.]*$' || return 1
    return 0
}

alle_pids() {   # alle laufenden Hue-Proben dieses Ordners, auch ohne PID-Datei
    for d in /proc/[0-9]*; do
        p=${d#/proc/}
        if ist_unser "$p"; then echo "$p"; fi
    done
}

laeuft() { [ -n "$(alle_pids)" ]; }

case "$1" in
    status)
        if laeuft; then echo "laeuft (PID $(alle_pids | tr '\n' ' '))"; exit 0; fi
        echo "laeuft nicht"; exit 1 ;;
    start|stop) ;;
    *) echo "Aufruf: hue_dienst.sh start|stop|status" >&2; exit 2 ;;
esac

if [ "$1" = "stop" ]; then
    # 1.0.22 (Bauart befehlsbruecke.sh 1.0.1, BB1): stop legt nichts an. uninstall laeuft als root und ruft stop; bis
    # 1.0.21 entstanden hier vor allem anderen data/ und log/ samt hue_dienst.lock, als root eine root-eigene Sperre.
    # Gesperrt wird nur ueber eine vorhandene, schreibbare hue_dienst.lock; fehlt sie, geht es ohne Sperre weiter.
    if [ -f "$DDIR/hue_dienst.lock" ] && [ -w "$DDIR/hue_dienst.lock" ] && exec 8>>"$DDIR/hue_dienst.lock"; then
        if ! flock -n 8; then echo "Ein anderer Start oder Halt laeuft gerade."; exit 3; fi
    fi
    for p in $(alle_pids); do kill "$p" 2>/dev/null; done
    i=0
    while laeuft && [ $i -lt 10 ]; do sleep 1; i=$((i + 1)); done
    for p in $(alle_pids); do kill -9 "$p" 2>/dev/null; done
    rm -f "$PIDF"
    if laeuft; then echo "Hue-Probe laesst sich nicht anhalten."; exit 1; fi
    echo "Hue-Probe angehalten."; exit 0
fi

# start (1.0.22: root und Upgrade-Marke VOR jedem Anlegen fragen, Bauart befehlsbruecke.sh 1.0.1/1.0.3; bis 1.0.21
# entstanden data/, log/ und hue_dienst.lock, bevor die Marke den Start abwies, und die root-Pruefung kam zuletzt)
if [ "$(id -u)" = "0" ]; then echo "Nicht als root starten." >&2; exit 1; fi
if [ -f "$MARKE" ]; then
    jetzt=$(date +%s 2>/dev/null); seit=$(cat "$MARKE" 2>/dev/null)
    case "$seit" in ''|*[!0-9]*) seit=0 ;; esac
    # 1.0.22 (Bauart befehlsbruecke.sh 1.0.1, B3; wie cron.05min): leere oder ungueltige Uhr - die Marke gilt, geschlossen
    # ausfallen. Bis 1.0.21 rechnete das Skript mit der leeren Zahl weiter.
    case "$jetzt" in ''|*[!0-9]*|0) echo "Aktualisierung laeuft - die Hue-Probe startet danach."; exit 0 ;; esac
    if [ $((jetzt - seit)) -lt 3600 ] && [ "$seit" -le "$jetzt" ]; then
        echo "Aktualisierung laeuft - die Hue-Probe startet danach."; exit 0
    fi
fi
mkdir -p "$DDIR" "$LDIR" 2>/dev/null
exec 8>"$DDIR/hue_dienst.lock" || exit 1
if ! flock -n 8; then echo "Ein anderer Start oder Halt laeuft gerade."; exit 3; fi
if laeuft; then echo "Hue-Probe laeuft bereits."; exit 0; fi
PHP=$(command -v php 2>/dev/null)
[ -n "$PHP" ] || { echo "php nicht gefunden."; exit 1; }
# 1.0.22 (Bauart befehlsbruecke.sh 1.0.1, BB6): die Startausgabe wuchs bis 1.0.21 ungekappt (log/plugins liegt im RAM);
# ueber 256 kB bleiben vor jedem Start die letzten 300 Zeilen (Hausform Regeln/06).
SLOG="$LDIR/hue_start.log"
if [ -f "$SLOG" ] && [ "$(wc -c < "$SLOG" 2>/dev/null || echo 0)" -gt 262144 ]; then
    tail -n 300 "$SLOG" > "$SLOG.tmp.$$" 2>/dev/null && cat "$SLOG.tmp.$$" > "$SLOG" 2>/dev/null
    rm -f "$SLOG.tmp.$$" 2>/dev/null
fi
nohup "$PHP" "$SKRIPT" >>"$LDIR/hue_start.log" 2>&1 </dev/null 8<&- &
echo $! > "$PIDF"
sleep 1
if laeuft; then echo "Hue-Probe gestartet (PID $(alle_pids | tr '\n' ' '))."; exit 0; fi
rm -f "$PIDF"
echo "Hue-Probe endete sofort - siehe $LDIR/alexang.log."
exit 1
