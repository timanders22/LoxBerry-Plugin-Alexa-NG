#!/bin/bash
# Alexa NG - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Laeuft IMMER, auch beim Upgrade - dort unmittelbar nachdem der Installer
# config/plugins/<ordner>/ und data/plugins/<ordner>/ geloescht hat.
#   - legt Ordner an, die Konfiguration mit {} (0600), den Ordner der
#     Broker-Optionsdateien (0700)
#   - NUR bei liegender Upgrade-Marke (Entscheidung 1): haelt jeden
#     verbliebenen Dienst an (auch einen ohne PID-Datei, Regeln/06) und
#     spielt den Bestand aus preupgrade.sh zurueck (er ist der von eben,
#     daher ohne Inhaltspruefung); fehlt er, die Zweitschriften - wenn sie
#     Inhalt tragen
#   - chmod 600 NACH jeder Wiederherstellung (Regeln/06, cp -p)
#   - entfernt die Marke per trap, auch bei einem Abbruch
# postupgrade.sh leitet NICHT hierher weiter (LoxBerry ruft beide Haken).

PFOLDER="${3:-alexang}"
case "$PFOLDER" in
    ''|.|..|*/*) echo "<FAIL> Unbrauchbarer Ordnername '$PFOLDER'."; exit 1 ;;
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
    echo "<FAIL> Das LoxBerry-Wurzelverzeichnis liess sich nicht bestimmen - nichts angelegt."
    exit 1
fi

CDIR="$BASE/config/plugins/$PFOLDER"
DDIR="$BASE/data/plugins/$PFOLDER"
LDIR="$BASE/log/plugins/$PFOLDER"
CF="$CDIR/alexang.json"
AF="$CDIR/amazon.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
BKA="$BASE/config/plugins/$PFOLDER.backup.amazon.json"
BESTAND="$BASE/data/plugins/$PFOLDER.upgrade_bestand"
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
ax_marke_weg() {
    ax_rc=$?
    rm -f "$MARKE" 2>/dev/null
    [ -e "$MARKE" ] && echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen - bitte von Hand loeschen."
    exit $ax_rc
}
trap ax_marke_weg EXIT

mkdir -p "$CDIR" "$DDIR" "$LDIR" 2>/dev/null

# Traegt die Datei Inhalt? Konfiguration: Objekt mit beiden Token.
# Anmeldung: Objekt mit refresh_token der Form Atnr|... Entschieden nach
# Inhalt, nicht nach Form (Regeln/05). Ohne php keine Antwort = "nein".
ax_inhalt() {   # $1 Datei, $2 cfg|amazon
    [ -f "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        if ($argv[2] === "amazon") {
            exit(isset($d["refresh_token"]) && is_string($d["refresh_token"])
                 && preg_match("/^Atnr\\|[\\x21-\\x7E]{40,4000}\\z/", $d["refresh_token"]) ? 0 : 1);
        }
        exit(isset($d["sprechtoken"], $d["aktionstoken"]) && is_string($d["sprechtoken"]) && $d["sprechtoken"] !== ""
             && is_string($d["aktionstoken"]) && $d["aktionstoken"] !== "" ? 0 : 1);' "$1" "$2" 2>/dev/null
}

# Kopieren ueber eine Nebendatei; Rueckgabe 0 nur, wenn das Ziel der Quelle gleicht.
ax_kopieren() {   # $1 Quelle, $2 Ziel
    ax_neu="$2.neu.$$"
    if ( umask 077 && cp "$1" "$ax_neu" ) 2>/dev/null && cmp -s "$1" "$ax_neu" \
       && chmod 600 "$ax_neu" 2>/dev/null && mv -f "$ax_neu" "$2" 2>/dev/null && cmp -s "$1" "$2"; then
        return 0
    fi
    rm -f "$ax_neu" 2>/dev/null
    return 1
}

AX_UEBERNOMMEN=0
if [ -f "$MARKE" ]; then
    DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
    if [ -f "$DIENST" ] && timeout 15 /bin/sh "$DIENST" status >/dev/null 2>&1; then
        timeout 30 /bin/sh "$DIENST" stop >/dev/null 2>&1
        echo "<INFO> Ein Befehlsabo aus der Update-Luecke wurde angehalten."
    fi
    for AX_PAAR in "alexang.json:$CF:cfg:$BK" "amazon.json:$AF:amazon:$BKA"; do
        AX_NAME=${AX_PAAR%%:*}; AX_R=${AX_PAAR#*:}
        AX_ZIEL=${AX_R%%:*}; AX_R=${AX_R#*:}
        AX_ART=${AX_R%%:*}; AX_ZWEIT=${AX_R#*:}
        if [ -f "$BESTAND/$AX_NAME" ] && ax_inhalt "$BESTAND/$AX_NAME" "$AX_ART"; then
            if ax_kopieren "$BESTAND/$AX_NAME" "$AX_ZIEL"; then
                echo "<OK> $AX_NAME aus dem Update-Bestand zurueckgespielt."
                AX_UEBERNOMMEN=1
            else
                echo "<WARNING> $AX_NAME liess sich nicht zurueckspielen; die Bibliothek holt die Zweitschrift."
            fi
        elif ! ax_inhalt "$AX_ZIEL" "$AX_ART" && ax_inhalt "$AX_ZWEIT" "$AX_ART"; then
            if ax_kopieren "$AX_ZWEIT" "$AX_ZIEL"; then
                echo "<OK> $AX_NAME aus der Zweitschrift zurueckgespielt."
                AX_UEBERNOMMEN=1
            fi
        fi
    done
    case "$BESTAND" in
        "$BASE"/data/plugins/*.upgrade_bestand) rm -rf "${BESTAND:?}" 2>/dev/null ;;
    esac
fi

if [ ! -f "$CF" ]; then
    echo '{}' > "$CF"
fi
# Nach JEDER Wiederherstellung: Konfiguration, Anmeldung und Zweitschriften 0600.
for AX_F in "$CF" "$AF" "$BK" "$BKA"; do
    [ -f "$AX_F" ] && chmod 600 "$AX_F" 2>/dev/null
done
mkdir -p "$DDIR/mosquitto" 2>/dev/null
chmod 700 "$DDIR/mosquitto" 2>/dev/null

if ! command -v mosquitto_pub >/dev/null 2>&1; then
    echo "<WARNING> mosquitto_pub wurde nicht gefunden (Paket mosquitto-clients) - MQTT bleibt stumm."
fi
if command -v php >/dev/null 2>&1 && ! php -r 'exit(function_exists("curl_init") ? 0 : 1);' 2>/dev/null; then
    echo "<WARNING> Die PHP-Erweiterung curl fehlt fuer $(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null) - ohne sie erreicht das Plugin Amazon nicht."
fi

if [ "$AX_UEBERNOMMEN" = 1 ]; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen und Anmeldung uebernommen. Der Takt laeuft innerhalb von 5 Minuten an."
else
    echo "<OK> Installation abgeschlossen."
    echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen: im Reiter Amazon-Anmeldung einmal bei amazon.de anmelden."
    echo "<INFO> Der Reiter Test beantwortet danach, ob die Einrichtung traegt."
fi
exit 0
