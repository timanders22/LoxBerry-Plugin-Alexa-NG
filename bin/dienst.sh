#!/bin/sh
# Alexa NG - Befehlsabo starten, anhalten, fragen.
#   dienst.sh start | stop | status
# Rueckgabe: status 0 = laeuft, 1 = laeuft nicht; start/stop 0 = Wirkung
# nachgesehen, 1 = nicht gelungen, 3 = ein anderer start/stop laeuft gerade.
#
# DIE SPERRE STEHT HIER, NICHT IM DIENST. Eine flock-Sperre im PHP-Prozess
# erbt mosquitto_sub, das Kind haelt sie nach dem Tod des Dienstes weiter,
# und kein Neustart kommt mehr durch (Regeln/03). Das Kind bekommt den
# Deskriptor deshalb nicht mit (8<&-).
#
# "Laeuft" wird argumentweise gemessen: argv[0] ist ein php, argv[1] genau
# unser Skript, kein drittes Argument (Regeln/06, Bauart APC-UPS 1.2.10).
# Die PID-Datei allein ist kein Beleg.

SELF=$(cd "$(dirname "$0")" 2>/dev/null && pwd -P)
SKRIPT="$SELF/ax_befehle.php"
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
    echo "dienst.sh: keine LoxBerry-Installation erkannt - nichts getan." >&2
    exit 1
fi
PIDF="$DDIR/befehle.pid"

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

alle_pids() {   # alle laufenden Dienste dieses Ordners, auch ohne PID-Datei
    for d in /proc/[0-9]*; do
        p=${d#/proc/}
        if ist_unser "$p"; then echo "$p"; fi
    done
}

kind_pids() {   # $1 PID des Dienstes -> seine mosquitto_sub-Kinder
    for d in /proc/[0-9]*; do
        p=${d#/proc/}
        pp=$(sed -n 's/^PPid:[[:space:]]*//p' "$d/status" 2>/dev/null)
        [ "$pp" = "$1" ] && echo "$p"
    done
}

laeuft() { [ -n "$(alle_pids)" ]; }

case "$1" in
    status)
        if laeuft; then echo "laeuft (PID $(alle_pids | tr '\n' ' '))"; exit 0; fi
        echo "laeuft nicht"; exit 1 ;;
    start|stop) ;;
    *) echo "Aufruf: dienst.sh start|stop|status" >&2; exit 2 ;;
esac

mkdir -p "$DDIR" "$LDIR" 2>/dev/null
exec 8>"$DDIR/dienst.lock" || exit 1
if ! flock -n 8; then echo "Ein anderer Start oder Halt laeuft gerade."; exit 3; fi

if [ "$1" = "stop" ]; then
    for p in $(alle_pids); do
        for k in $(kind_pids "$p"); do kill "$k" 2>/dev/null; done
        kill "$p" 2>/dev/null
    done
    i=0
    while laeuft && [ $i -lt 10 ]; do sleep 1; i=$((i + 1)); done
    for p in $(alle_pids); do kill -9 "$p" 2>/dev/null; done
    rm -f "$PIDF"
    if laeuft; then echo "Befehlsabo laesst sich nicht anhalten."; exit 1; fi
    echo "Befehlsabo angehalten."; exit 0
fi

# start
if [ -f "$MARKE" ]; then
    jetzt=$(date +%s); seit=$(cat "$MARKE" 2>/dev/null)
    case "$seit" in ''|*[!0-9]*) seit=0 ;; esac
    if [ $((jetzt - seit)) -lt 3600 ] && [ "$seit" -le "$jetzt" ]; then
        echo "Aktualisierung laeuft - der Dienst startet danach."; exit 0
    fi
fi
if laeuft; then echo "Befehlsabo laeuft bereits."; exit 0; fi
if [ "$(id -u)" = "0" ]; then echo "Nicht als root starten." >&2; exit 1; fi
PHP=$(command -v php 2>/dev/null)
[ -n "$PHP" ] || { echo "php nicht gefunden."; exit 1; }
nohup "$PHP" "$SKRIPT" >>"$LDIR/befehle_start.log" 2>&1 </dev/null 8<&- &
echo $! > "$PIDF"
sleep 1
if laeuft; then echo "Befehlsabo gestartet (PID $(alle_pids | tr '\n' ' '))."; exit 0; fi
rm -f "$PIDF"
echo "Befehlsabo endete sofort - siehe $LDIR/alexang.log."
exit 1
