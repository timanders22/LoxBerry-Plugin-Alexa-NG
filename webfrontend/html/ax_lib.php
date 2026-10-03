<?php
/**
 * Alexa NG - gemeinsame Bibliothek (Oberflaeche, Endpunkt, Takt, Befehlsabo)
 *
 * Fassung 1 "Ansagen": Loxone und andere Plugins lassen einen Echo sprechen,
 * ankuendigen, die Lautstaerke setzen oder eine freigegebene Routine starten.
 *
 * Diese Datei ist der EINZIGE Ort mit Amazon-Adressen (ax_amazon_url()).
 * Die Aufrufe folgen der inoffiziellen Web-Schnittstelle von alexa.amazon.de,
 * wie sie im fremden Plugin Alexa2Lox (Skript alexa_remote_control.sh)
 * beschrieben ist - uebernommen ist das WISSEN ueber die Schnittstelle, kein
 * Code. Was nur aus fremden Bibliotheken bekannt und nicht gemessen ist, traegt
 * im Kommentar die Marke [ungemessen] (Bauplan, Abschnitt 1.2 und 2.4).
 *
 * Geheimnisse (Erneuerungs-Token, Cookies, csrf, Kundennummer, Code,
 * code_verifier) gehen nie ins Protokoll, nie in eine Antwort, nie in ein
 * Formular und nie auf eine Befehlszeile. curl laeuft in PHP.
 *
 * Unterbau: PHP 7.4 und 8.x (LoxBerry 3/4 fahren 7.4). Keine match-, keine
 * str_contains-, keine Nullsafe-Ausdruecke.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* display_errors aus: die Bibliothek wird vom UNANGEMELDETEN Endpunkt geladen;
 * eine Warnung vor der Antwortzeile verhinderte den Statuscode. */
ini_set('display_errors', '0');
if (!ini_get('date.timezone')) { date_default_timezone_set('Europe/Berlin'); }

/* ---------------- Grenzen - jede genau einmal ---------------- */
define('AX_TAKT_S', 300);            // Cron-Takt (cron.05min)
define('AX_OK_GRENZE_S', 900);       // OK=0, wenn ALTER > 3 x Takt (Entscheidung 4)
define('AX_KEKS_S', 86400);          // Cookies hoechstens 24 h (ARC:173, geschaetzt)
define('AX_STATUS_S', 1800);         // customer-status alle 30 min
define('AX_GERAETE_S', 21600);       // Geraeteliste alle 6 h
define('AX_PREVIEW_ABSTAND_S', 1.0); // Mindestabstand zweier Befehlsfolgen (ARC:1242)
define('AX_SPERRE_WARTE_S', 8);      // laenger wartet kein Aufruf auf die Sperre
define('AX_TEIL_MAX', 250);          // Zeichen je Alexa.Speak-Knoten [ungemessen]
define('AX_TEXT_MAX', 1000);         // Zeichen je Ansage
define('AX_SSML_MAX', 250);
define('AX_HTTP_VERBINDEN_S', 5);
define('AX_HTTP_ZEIT_S', 10);
define('AX_FEHLVERSUCHE', 20);       // je Absender und Stunde, danach Sperre
define('AX_GERAET_NAME', 'LoxBerry Alexa-NG');
define('AX_LOCALE', 'de-DE');
define('AX_DOMAIN', 'amazon.de');    // Entscheidung 18: nur amazon.de
/* Geraetetyp der Alexa-App fuer iOS, wie ihn alexa-cookie2/alexapy fuer die
 * Anmeldung benutzen [ungemessen]. */
define('AX_APP_TYP', 'A2IVLV5VM2W81');
define('AX_MUSIK_NR_MAX', 50);       // Senderliste: Nummern 1..50 (Entscheidung 29)
define('AX_SENDER_MAX', 100);        // Zeichen je Sendername (Bauliste B1)
define('AX_RADIO_ZONEN_MAX', 24);    // Radio je Zone: Zonen 1..24 (Bauliste alexa3 Z1)
define('AX_RADIO_GLEICH_S', 60);     // gleicher Sollwert je Zone binnen 60 s -> UNVERAENDERT (X-7, Z4)
define('AX_RADIO_PAUSE_S', 60);      // nach AMAZON_RATE: Musik-Pause (Radio und Musik-Probe), kein Wiederholen (Z4, alexa4 N3)
define('AX_HUE_PORT', 8380);         // Hue-Probe: HTTP-Port ab Werk - nicht 80, Apache bleibt unberuehrt (alexa4 H1)
define('AX_HUE_D_BAU_S', 1800);      // Hue-Probe auf eigener Netzadresse: so lange darf docker build dauern (Nr. 41)
define('AX_HUE_D_WARTE_S', 20);      // so lange wartet die Seite nach dem Speichern auf den Vorgang (Nr. 41)
define('AX_HUE_LAMPEN_MAX', 50);     // Fassung 2: hoechstens 50 Lampen in der Freigabeliste
define('AX_HUE_NAME_MAX', 32);       // Fassung 2: Zeichen je Lampenname
define('AX_HUE_ECHOS_MAX', 20);      // Fassung 2: freigegebene Echo-Adressen

/* ==================================================================
 * Pfade
 * ================================================================== */

if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/** Die LoxBerry-Wurzel: erst LBHOMEDIR (mit config/ und data/plugins), dann die Suche. */
function ax_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/\\');
    }
    return lb_wurzel_ermitteln();
}

/**
 * Die Pfade der Anlage - oder im Archivmodus Ersatzpfade im Temp-Ordner.
 *
 * Die Pfade der Anlage gelten nur, wenn diese Bibliothek dort installiert
 * liegt (<Wurzel>/webfrontend/html/plugins/<ordner>) oder der Aufrufer Wurzel
 * UND Ordner ausdruecklich nennt (LBHOMEDIR und LBPPLUGINDIR - so arbeiten
 * die Pruefwerkzeuge und die Deinstallation). Bauart abfahrt_paths()
 * (Abfahrts-Assistent 1.6.19). LBPPLUGINDIR ist am Geraet keine Umgebung
 * (Regeln/03); der Ordner kommt dann aus dem eigenen Ablageort.
 */
function ax_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }
    $home = ax_lbhome();
    $self = basename(__DIR__);
    $nie = array('', '.', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend');
    $lbp = basename(rtrim(str_replace('\\', '/', (string) getenv('LBPPLUGINDIR')), '/'));
    $lbp_gilt = !in_array($lbp, $nie, true) && preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $lbp);
    if ($lbp_gilt) {
        $plugin = $lbp;
    } elseif (!in_array($self, $nie, true) && preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $self)) {
        $plugin = $self;
    } else {
        $plugin = 'alexang';
    }
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . $self);
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim(str_replace('\\', '/', (string) getenv('LBHOMEDIR')), '/')
            || ($lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/\\'));
        if (!$installiert && !$ausdruecklich) { $home = ''; }
    }
    if ($home !== '') {
        $cdir = $home . '/config/plugins/' . $plugin;
        $ddir = $home . '/data/plugins/' . $plugin;
        $p = array(
            'lbhome'      => $home,
            'plugin'      => $plugin,
            'configdir'   => $cdir,
            'config'      => $cdir . '/alexang.json',
            'amazon'      => $cdir . '/amazon.json',
            'backup'      => $home . '/config/plugins/' . $plugin . '.backup.json',
            'backup_amz'  => $home . '/config/plugins/' . $plugin . '.backup.amazon.json',
            'datadir'     => $ddir,
            'namen'       => $home . '/data/plugins/' . $plugin . '.namen.json',
            'marke'       => $home . '/data/plugins/' . $plugin . '.upgrade_laeuft',
            'logdir'      => $home . '/log/plugins/' . $plugin,
            'log'         => $home . '/log/plugins/' . $plugin . '/alexang.log',
            'bindir'      => $home . '/bin/plugins/' . $plugin,
            'general'     => $home . '/config/system/general.json',
            'archiv'      => '',
        );
        return $p;
    }
    $a = sys_get_temp_dir() . '/alexang-archiv';
    $p = array(
        'lbhome'      => '',
        'plugin'      => $plugin,
        'configdir'   => $a . '/config',
        'config'      => $a . '/config/alexang.json',
        'amazon'      => $a . '/config/amazon.json',
        'backup'      => $a . '/alexang.backup.json',
        'backup_amz'  => $a . '/alexang.backup.amazon.json',
        'datadir'     => $a . '/data',
        'namen'       => $a . '/alexang.namen.json',
        'marke'       => $a . '/alexang.upgrade_laeuft',
        'logdir'      => $a . '/log',
        'log'         => $a . '/log/alexang.log',
        'bindir'      => dirname(dirname(__DIR__)) . '/bin',
        'general'     => '',
        'archiv'      => $gefunden,
    );
    return $p;
}

/** Fuer Takt, Befehlsabo, Healthcheck: ohne Pfade der Anlage nichts tun. */
function ax_keine_wurzel_abbruch($programm)
{
    $p = ax_paths();
    if ($p['lbhome'] !== '') { return; }
    fwrite(STDERR, $programm . ': Kein LoxBerry-Wurzelverzeichnis (ausgepacktes Archiv oder Pruefordner).'
        . "\n" . 'Es wurde nichts geschrieben und nichts gesendet. LBHOMEDIR und LBPPLUGINDIR setzen.' . "\n");
    exit(1);
}

/**
 * Nur-Lese-Betrieb. Der unangemeldete Endpunkt schaltet ihn ein, bevor er
 * irgendetwas prueft; erst nach bestandener Tokenpruefung wird er fuer die
 * Laufzeitdateien im Datenordner aufgehoben. Die Konfiguration legt der
 * Endpunkt nie an (Regeln/05).
 */
function ax_nur_lesen($setzen = null)
{
    static $an = false;
    if ($setzen !== null) { $an = (bool) $setzen; }
    return $an;
}

/* ==================================================================
 * Schreiben und Lesen
 * ================================================================== */

/**
 * Unteilbar schreiben: Nebendatei <ziel>.tmp.<pid>, Rechte VOR dem Inhalt,
 * Laenge verglichen (!== strlen), dann rename(). Im Nur-Lese-Betrieb nie.
 */
function ax_write_atomic($datei, $inhalt, $rechte = 0600)
{
    if (ax_nur_lesen()) { return false; }
    $inhalt = (string) $inhalt;
    $verz = dirname($datei);
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
        if (!is_dir($verz)) { return false; }
    }
    $neben = $datei . '.tmp.' . getmypid();
    $fh = @fopen($neben, 'c');
    if ($fh === false) { return false; }
    @chmod($neben, $rechte);
    $ok = ftruncate($fh, 0);
    $n = $ok ? fwrite($fh, $inhalt) : false;
    fclose($fh);
    if ($n !== strlen($inhalt)) { @unlink($neben); return false; }
    if (!@rename($neben, $datei)) { @unlink($neben); return false; }
    clearstatcache(true, $datei);
    return true;
}

/** JSON kodieren, Rueckgabe pruefen, dann schreiben. */
function ax_write_json($datei, $daten, $rechte = 0600)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    return ax_write_atomic($datei, $js . "\n", $rechte);
}

/** Eine JSON-Datei als Objekt lesen; null bei fehlender oder kaputter Datei. */
function ax_json_lesen($datei)
{
    if (!is_file($datei)) { return null; }
    $roh = @file_get_contents($datei);
    if ($roh === false) { return null; }
    $d = json_decode($roh, true);
    if (!is_array($d) || ($d !== array() && array_keys($d) === range(0, count($d) - 1))) { return null; }
    return $d;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

/** Die Vorgaben - an genau einer Stelle. Die Sicherung braucht diese Liste. */
function ax_vorgaben()
{
    return array(
        // Plugin aktiv: Nein -> jeder ausloesende Aufruf 409 (Entscheidung 10).
        'aktiv' => 1,
        // Geraet, wenn ein Aufruf keines nennt (Normalname). Leer = 400.
        'standardgeraet' => '',
        // Eigene Gruppen: Liste von array('name' => 'unten', 'geraete' => 'kueche,flur').
        'gruppen' => array(),
        // Wiederholbremse (ab Werk an): derselbe Text an dasselbe Geraet
        // innerhalb dieses Fensters wird nicht erneut gesprochen. 0 = aus.
        'bremse_fenster_s' => 30,
        // Mindestabstand fuer einen ANDEREN Text am selben Geraet. Ab Werk aus
        // (Entscheidung 14 sinngemaess): mit 429 gingen Ansagen verloren.
        'mindestabstand_s' => 0,
        // Obergrenze je Stunde ueber alle Aufrufe (Entscheidung 18: 60).
        'stundengrenze' => 60,
        // Ruhezeit (ab Werk aus); dringend=1 im Aufruf uebergeht sie.
        'ruhe_ein' => 0,
        'ruhe_von' => '22:00',
        'ruhe_bis' => '07:00',
        // Ankuendigen ist nicht am Geraet erprobt - ab Werk aus (E7).
        'ankuendigen_ein' => 0,
        // Routinen nur aus dieser Freigabeliste, ab Werk leer (E3).
        'routinen_frei' => array(),
        // Ansagetexte protokollieren (gekuerzt), ab Werk aus (E8).
        'texte_protokollieren' => 0,
        // Sperre aus Loxone (K1, Entscheidung 29): Loxone setzt sie per HTTP
        // (Aktionstoken) oder MQTT; gesperrt werden nur Ansagen und
        // Ankuendigungen, dringend=1 geht durch. Ab Werk aus.
        'sperre_ein' => 0,
        // Musik-Probe (Stufe 3, Messplan Teil B): nicht am Geraet erprobt,
        // ab Werk aus; eigene Stundengrenze; Senderliste nach Nummer.
        'musik_ein' => 0,
        'musik_stundengrenze' => 30,
        'musik_sender' => array(),
        // Radio je Zone (Stufe 3, Entscheidung 32): Zonentabelle 1..24 ->
        // Echo (Normalname), Amazon-Gruppe (amazon:<name>) oder eigene
        // Gruppe (gruppe:<name>); Sender nach Nummer aus musik_sender.
        // Ab Werk aus.
        'radio_ein' => 0,
        'radio_zonen' => array(),
        // Hue-Probe (Bauliste alexa4 H1, Entscheidungen 17 und 29): misst, ob
        // ein Echo eine Hue-Bridge-Nachbildung im Heimnetz findet. Ab Werk
        // aus, nicht am Geraet erprobt; eigener Dienst bin/hue_dienst.sh,
        // laeuft nur mit Haken. Schalten geht nur fluechtig an MQTT.
        'hue_ein' => 0,
        'hue_port' => AX_HUE_PORT,
        // Hue-Probe auf eigener Netzadresse (Entscheidung Nr. 41, alexa5):
        // 'loxberry' = Dienst auf dem LoxBerry wie bisher, 'docker' = eigener
        // Container mit eigener IP im Heimnetz (macvlan) auf Port 80. Ab Werk
        // 'loxberry', IP leer, Schnittstelle eth0. Eine alte Konfiguration
        // ohne diese Schluessel bleibt gueltig.
        'hue_art' => 'loxberry',
        'hue_ip' => '',
        'hue_schnittstelle' => 'eth0',
        // Fassung 2 (alexa6): Lampen fuer Alexa. Die Probe-Lampe "Loxone
        // Probe" (Hue-ID 1) bleibt ab Werk sichtbar, damit ein Update von
        // 0.9.5 nichts wegnimmt; die Freigabeliste ist ab Werk leer, die
        // Echo-Liste auch (leer = alle im Heimnetz). Die naechste freie
        // Lampen-ID zaehlt nur hoch - eine geloeschte Lampe gibt ihre ID nie
        // an eine neue weiter (Alexa ordnet nach ID).
        'hue_probe_lampe' => 1,
        'hue_lampen' => array(),
        'hue_lampe_naechste' => 2,
        'hue_echos' => array(),
        // MQTT ab Werk an (Hausstandard); der Befehlseingang ab Werk aus.
        'mqtt_ein' => 1,
        'mqtt_praefix' => 'alexang',
        'befehle_mqtt_ein' => 0,
        'befehle_routine_ein' => 0,
        // Zwei Token (E4): das Sprechtoken fuer Loxone und andere Plugins,
        // das Aktionstoken zusaetzlich fuer Routinen und die Geraeteliste
        // mit Seriennummern. Beide entstehen beim ersten Oeffnen der
        // Oberflaeche (array_key_exists, Regeln/05).
        'sprechtoken' => '',
        'aktionstoken' => '',
    );
}

/** Die Konfigurationsdatei roh: array(Feld|null, 'fehlt'|'leer'|'kaputt'|'ok'). */
function ax_config_roh($datei = null)
{
    if ($datei === null) { $p = ax_paths(); $datei = $p['config']; }
    if (!is_file($datei)) { return array(null, 'fehlt'); }
    $roh = @file_get_contents($datei);
    if ($roh === false) { return array(null, 'kaputt'); }
    $roh = trim($roh);
    if ($roh === '' || $roh === '{}' || $roh === '[]') { return array(array(), 'leer'); }
    $d = json_decode($roh, true);
    if (!is_array($d) || ($d !== array() && array_keys($d) === range(0, count($d) - 1))) {
        return array(null, 'kaputt');
    }
    return array($d, 'ok');
}

/** Was ax_config() beim letzten Lesen abgewiesen hat und was fehlte. */
function ax_config_lage($neu = null)
{
    static $lage = array('zustand' => 'fehlt', 'abgewiesen' => array(), 'fehlend' => array());
    if ($neu !== null) { $lage = $neu; }
    return $lage;
}

/**
 * Die Konfiguration lesen, jeden Wert pruefen, Vorgaben fuer Fehlendes.
 * Schreibt NIE - die Selbstheilung ist ax_config_heilen().
 */
function ax_config()
{
    list($cfg, $zustand) = ax_config_roh();
    if (!is_array($cfg)) { $cfg = array(); }
    $vorgaben = ax_vorgaben();
    $abgewiesen = array();
    foreach ($vorgaben as $k => $v) {
        if (!array_key_exists($k, $cfg)) { continue; }
        $grund = '';
        $gut = ax_wert_pruefen($k, $cfg[$k], $grund);
        if ($gut === null) {
            $abgewiesen[$k] = $grund;
            unset($cfg[$k]);
        } else {
            $cfg[$k] = $gut;
        }
    }
    $fehlend = array_values(array_diff(array_keys($vorgaben), array_keys($cfg)));
    ax_config_lage(array('zustand' => $zustand, 'abgewiesen' => $abgewiesen, 'fehlend' => $fehlend));
    $cfg += $vorgaben;
    if ($cfg['mqtt_praefix'] === '') { $cfg['mqtt_praefix'] = 'alexang'; }
    return $cfg;
}

/**
 * Ist dieser Wert fuer diese Einstellung zulaessig? Rueckgabe der Wert in
 * Normalform oder null mit Grund (KENNUNG|wert). Formular, Sicherung und
 * Lesefunktion rufen dieselbe Pruefung. Muster enden auf \z (Regeln/05).
 */
function ax_wert_pruefen($schluessel, $wert, &$grund = '')
{
    $grund = '';
    $zahl = function ($w, $min, $max) use (&$grund) {
        if (is_array($w) || is_bool($w) || is_null($w) || is_float($w)) { $grund = 'KEINE_ZAHL'; return null; }
        if (!is_int($w) && !preg_match('/^-?[0-9]{1,6}\z/', (string) $w)) { $grund = 'KEINE_ZAHL'; return null; }
        $i = (int) $w;
        if ($i < $min || $i > $max) { $grund = 'AUSSERHALB|' . $min . '|' . $max; return null; }
        return $i;
    };
    $schalter = function ($w) use (&$grund) {
        if (is_array($w) || is_null($w)) { $grund = 'KEIN_SCHALTER'; return null; }
        if ($w === true || $w === 1 || $w === '1') { return 1; }
        if ($w === false || $w === 0 || $w === '0' || $w === '') { return 0; }
        $grund = 'KEIN_SCHALTER';
        return null;
    };
    $zeit = function ($w) use (&$grund) {
        if (!is_string($w) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]\z/', $w)) { $grund = 'UHRZEIT'; return null; }
        return $w;
    };
    switch ($schluessel) {
        case 'aktiv':
        case 'ruhe_ein':
        case 'ankuendigen_ein':
        case 'texte_protokollieren':
        case 'mqtt_ein':
        case 'befehle_mqtt_ein':
        case 'befehle_routine_ein':
        case 'sperre_ein':
        case 'musik_ein':
        case 'radio_ein':
        case 'hue_ein':
        case 'hue_probe_lampe':
            return $schalter($wert);
        case 'bremse_fenster_s': return $zahl($wert, 0, 3600);
        case 'mindestabstand_s': return $zahl($wert, 0, 600);
        case 'stundengrenze':    return $zahl($wert, 10, 240);
        case 'musik_stundengrenze': return $zahl($wert, 10, 240);
        case 'hue_port':         return $zahl($wert, 1024, 65535);   // ohne root kein Port unter 1024
        case 'hue_art':
            if (!is_string($wert) || !in_array($wert, array('loxberry', 'docker'), true)) { $grund = 'HUE_ART'; return null; }
            return $wert;
        case 'hue_ip':
            /* Nur die Form; ob sie ins Netz der Schnittstelle passt, pruefen
             * Formular und Sicherung (ax_hue_ip_pruefen) - Nr. 19. */
            if (!is_string($wert)) { $grund = 'KEIN_TEXT'; return null; }
            if ($wert !== '' && ax_ipv4_zahl($wert) === null) { $grund = 'IP_FORM'; return null; }
            return $wert;
        case 'hue_schnittstelle':
            if (!is_string($wert) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,14}\z/', $wert)) { $grund = 'SCHNITTSTELLE_FORM'; return null; }
            return $wert;
        case 'hue_lampe_naechste': return $zahl($wert, 2, 10000);
        case 'hue_echos':
            /* Fassung 2 (alexa6): nur diese Echos duerfen die Nachbildung
             * benutzen; leer = alle im Heimnetz. Ob sie im Netz der
             * Schnittstelle liegen, pruefen Formular und Sicherung. */
            if (!is_array($wert)) { $grund = 'KEINE_LISTE'; return null; }
            if (count($wert) > AX_HUE_ECHOS_MAX) { $grund = 'MEHR_ZEILEN|' . AX_HUE_ECHOS_MAX; return null; }
            $aus = array();
            foreach (array_values($wert) as $e) {
                if (!is_string($e) || ax_ipv4_zahl($e) === null) { $grund = 'ECHO_FORM|' . (is_string($e) ? str_replace('|', '/', substr($e, 0, 40)) : '?'); return null; }
                if (in_array($e, $aus, true)) { $grund = 'ECHO_DOPPELT|' . $e; return null; }
                $aus[] = $e;
            }
            return $aus;
        case 'hue_lampen':
            /* Fassung 2 (alexa6): die Freigabeliste. Je Lampe genau id
             * (2..9999, eindeutig, bleibt beim Umbenennen), name (1..32
             * Zeichen, eindeutig), art (schalter|licht|dimmer; licht
             * seit alexa7, Nr. 42), kuerzel
             * (a-z/0-9/_, eindeutig) und frei (Haken "bewusst freigeben",
             * Pflicht bei Tuer/Tor/Garage/Alarm/Schloss). Hoechstens 50. */
            if (!is_array($wert)) { $grund = 'KEINE_LISTE'; return null; }
            if (count($wert) > AX_HUE_LAMPEN_MAX) { $grund = 'MEHR_ZEILEN|' . AX_HUE_LAMPEN_MAX; return null; }
            $aus = array();
            $ids = array();
            $namen = array();
            $kz = array();
            foreach (array_values($wert) as $i => $z) {
                if (!is_array($z) || count($z) !== 5 || !isset($z['id'], $z['name'], $z['art'], $z['kuerzel'])
                    || !array_key_exists('frei', $z)) {
                    $grund = 'LAMPE_ZEILE|' . ($i + 1); return null;
                }
                if (!is_int($z['id']) || $z['id'] < 2 || $z['id'] > 9999 || isset($ids[$z['id']])) { $grund = 'LAMPE_ID|' . ($i + 1); return null; }
                $g = ax_hue_name_grund($z['name']);
                if ($g !== '') { $grund = $g; return null; }
                $nn = ax_name_normal($z['name']);
                if (isset($namen[$nn])) { $grund = 'LAMPE_NAME_DOPPELT|' . str_replace('|', '/', $z['name']); return null; }
                if (!is_string($z['art']) || !in_array($z['art'], array('schalter', 'licht', 'dimmer'), true)) { $grund = 'LAMPE_ART|' . str_replace('|', '/', $z['name']); return null; }
                if (!is_string($z['kuerzel']) || !preg_match('/^[a-z0-9_]{1,32}\z/', $z['kuerzel'])) {
                    $grund = 'LAMPE_KUERZEL|' . str_replace('|', '/', $z['name']) . '|' . ax_hue_kuerzel_vorschlag($z['name'], $z['id']); return null;
                }
                if (isset($kz[$z['kuerzel']])) { $grund = 'LAMPE_KUERZEL_DOPPELT|' . $z['kuerzel']; return null; }
                if ($z['frei'] === true || $z['frei'] === 1 || $z['frei'] === '1') {
                    $frei = 1;
                } elseif ($z['frei'] === false || $z['frei'] === 0 || $z['frei'] === '0') {
                    $frei = 0;
                } else {
                    $grund = 'LAMPE_ZEILE|' . ($i + 1); return null;
                }
                $gw = ax_hue_gefahr($z['name']);
                if (!$frei && $gw !== '') { $grund = 'LAMPE_GEFAHR|' . str_replace('|', '/', $z['name']) . '|' . $gw; return null; }
                $ids[$z['id']] = 1;
                $namen[$nn] = 1;
                $kz[$z['kuerzel']] = 1;
                $aus[] = array('id' => $z['id'], 'name' => $z['name'], 'art' => $z['art'], 'kuerzel' => $z['kuerzel'], 'frei' => $frei);
            }
            return $aus;
        case 'ruhe_von':
        case 'ruhe_bis':         return $zeit($wert);
        case 'standardgeraet':
            if (!is_string($wert)) { $grund = 'KEIN_TEXT'; return null; }
            if ($wert !== '' && !preg_match('/^[a-z0-9_]{1,40}\z/', $wert)) { $grund = 'NORMALNAME'; return null; }
            return $wert;
        case 'mqtt_praefix':
            if (!is_string($wert)) { $grund = 'KEIN_TEXT'; return null; }
            if (!preg_match('#^[a-z0-9_\-]{1,32}(/[a-z0-9_\-]{1,32}){0,2}\z#', $wert)) { $grund = 'PRAEFIX'; return null; }
            return $wert;
        case 'sprechtoken':
        case 'aktionstoken':
            /* Weit gefasst (Regeln/05): was ohne Kodierung in eine Adresse
             * passt. Laenge 0 heisst "keines" - entscheidet die Erzeugung. */
            if (!is_string($wert)) { $grund = 'KEIN_TEXT'; return null; }
            if ($wert !== '' && !preg_match('/^[A-Za-z0-9_.\-]{8,64}\z/', $wert)) { $grund = 'TOKEN_ZEICHEN'; return null; }
            return $wert;
        case 'gruppen':
            if (!is_array($wert)) { $grund = 'KEINE_LISTE'; return null; }
            if (count($wert) > 20) { $grund = 'MEHR_ZEILEN|20'; return null; }
            $aus = array();
            $namen = array();
            foreach ($wert as $z) {
                if (!is_array($z) || !isset($z['name'], $z['geraete']) || !is_string($z['name'])
                    || !is_string($z['geraete']) || count($z) !== 2) {
                    $grund = 'KEINE_ZEILE'; return null;
                }
                if (!preg_match('/^[a-z0-9_]{1,40}\z/', $z['name']) || in_array($z['name'], array('alle'), true)) {
                    $grund = 'GRUPPENNAME|' . str_replace('|', '/', $z['name']); return null;
                }
                if (isset($namen[$z['name']])) { $grund = 'GRUPPE_DOPPELT|' . $z['name']; return null; }
                $namen[$z['name']] = 1;
                if (!preg_match('/^[a-z0-9_]{1,40}(,[a-z0-9_]{1,40}){0,19}\z/', $z['geraete'])) {
                    $grund = 'GRUPPENGERAETE|' . $z['name']; return null;
                }
                $aus[] = array('name' => $z['name'], 'geraete' => $z['geraete']);
            }
            return $aus;
        case 'routinen_frei':
            if (!is_array($wert)) { $grund = 'KEINE_LISTE'; return null; }
            if (count($wert) > 20) { $grund = 'MEHR_ZEILEN|20'; return null; }
            $aus = array();
            foreach ($wert as $r) {
                if (!is_string($r) || $r === '' || strlen($r) > 80 || preg_match('/[\x00-\x1F\x7F]/', $r)
                    || preg_match('//u', $r) !== 1 || trim($r) !== $r) {
                    $grund = 'ROUTINENNAME'; return null;
                }
                $aus[] = $r;
            }
            return $aus;
        case 'musik_sender':
            /* Senderliste (Entscheidung 29: Nummer als Hauptweg). Je Eintrag
             * genau nr (ganze Zahl 1..50, eindeutig), name, anbieter. */
            if (!is_array($wert)) { $grund = 'KEINE_LISTE'; return null; }
            if (count($wert) > AX_MUSIK_NR_MAX) { $grund = 'MEHR_ZEILEN|' . AX_MUSIK_NR_MAX; return null; }
            $aus = array();
            $nrn = array();
            foreach (array_values($wert) as $i => $z) {
                if (!is_array($z) || count($z) !== 3 || !isset($z['nr'], $z['name'], $z['anbieter'])) {
                    $grund = 'SENDERZEILE|' . ($i + 1); return null;
                }
                if (!is_int($z['nr']) || $z['nr'] < 1 || $z['nr'] > AX_MUSIK_NR_MAX) { $grund = 'SENDERNUMMER|' . ($i + 1); return null; }
                if (isset($nrn[$z['nr']])) { $grund = 'SENDER_DOPPELT|' . $z['nr']; return null; }
                $nrn[$z['nr']] = 1;
                if (ax_sender_grund($z['name']) !== '') { $grund = 'SENDERNAME|' . $z['nr']; return null; }
                $anb = ax_musik_anbieter();
                if (!is_string($z['anbieter']) || !isset($anb[$z['anbieter']])) { $grund = 'ANBIETER_ZEILE|' . $z['nr']; return null; }
                $aus[] = array('nr' => $z['nr'], 'name' => $z['name'], 'anbieter' => $z['anbieter']);
            }
            return $aus;
        case 'radio_zonen':
            /* Zonentabelle (Radio je Zone, Z1). Je Eintrag genau zone (ganze
             * Zahl 1..24, eindeutig) und ziel (Normalname, amazon:<name>
             * oder gruppe:<name>). Ob eine eigene Gruppe besteht, pruefen
             * Formular und Sicherung (ax_radio_gruppen_fehlen). */
            if (!is_array($wert)) { $grund = 'KEINE_LISTE'; return null; }
            if (count($wert) > AX_RADIO_ZONEN_MAX) { $grund = 'MEHR_ZEILEN|' . AX_RADIO_ZONEN_MAX; return null; }
            $aus = array();
            $zn = array();
            foreach (array_values($wert) as $i => $z) {
                if (!is_array($z) || count($z) !== 2 || !isset($z['zone'], $z['ziel'])) { $grund = 'ZONENZEILE|' . ($i + 1); return null; }
                if (!is_int($z['zone']) || $z['zone'] < 1 || $z['zone'] > AX_RADIO_ZONEN_MAX) { $grund = 'ZONENNUMMER|' . ($i + 1); return null; }
                if (isset($zn[$z['zone']])) { $grund = 'ZONE_DOPPELT|' . $z['zone']; return null; }
                $zn[$z['zone']] = 1;
                if (!ax_radio_ziel_form($z['ziel'])) { $grund = 'ZONENZIEL|' . $z['zone']; return null; }
                $aus[] = array('zone' => $z['zone'], 'ziel' => $z['ziel']);
            }
            return $aus;
    }
    $grund = 'UNBEKANNT';
    return null;
}

/** Ein neues Token. random_bytes, kein Rueckfall auf rand(). */
function ax_token_erzeugen()
{
    return bin2hex(random_bytes(12));
}

/** Traegt die rohe Datei Inhalt (Objekt UND beide Token)? */
function ax_config_hat_inhalt($roh)
{
    return is_array($roh) && isset($roh['sprechtoken'], $roh['aktionstoken'])
        && is_string($roh['sprechtoken']) && $roh['sprechtoken'] !== ''
        && is_string($roh['aktionstoken']) && $roh['aktionstoken'] !== '';
}

/** Der Zustand VOR der ersten Selbstheilung dieses Prozesses (Regeln/05). */
function ax_config_erstbefund($neu = null)
{
    static $b = null;
    if ($neu !== null && $b === null) { $b = $neu; }
    return $b !== null ? $b : array('zustand' => '', 'schritte' => array(), 'datei' => '', 'fehlend' => array());
}

/**
 * Konfiguration speichern: Datei 0600 unteilbar, danach die Zweitschrift -
 * aber nur, wenn der neue Stand Inhalt traegt (sonst ueberschriebe ein
 * Fehlstand die Rueckfallkopie).
 */
function ax_config_speichern(array $cfg)
{
    $p = ax_paths();
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    if (!ax_write_atomic($p['config'], $js . "\n", 0600)) { return false; }
    if (ax_config_hat_inhalt($cfg)) {
        if (!ax_write_atomic($p['backup'], $js . "\n", 0600)) {
            ax_log('WARN', 'Konfiguration: Zweitschrift ' . $p['backup'] . ' liess sich nicht schreiben.');
        }
    } else {
        ax_log('WARN', 'Konfiguration: gespeichert ohne beide Token - die Zweitschrift bleibt unveraendert.');
    }
    return true;
}

/**
 * Die Konfiguration pruefen und heilen - einmal, gemeldet (Oberflaeche und
 * Takt, NIE der Endpunkt). $token_anlegen nur aus der Oberflaeche:
 *   1. kaputt -> <datei>.kaputt-<zeit> (0600), weiter wie "fehlt"
 *   2. ohne Token, Zweitschrift mit Inhalt -> daraus zurueck (fehlen nur die
 *      Token, werden nur sie uebernommen)
 *   3. fehlende Schluessel -> vervollstaendigen, eine Protokollzeile
 *   4. ein Token fehlt als SCHLUESSEL (array_key_exists) -> erzeugen
 * Rueckgabe: Meldungen (Satzschluessel bereits uebersetzt).
 */
function ax_config_heilen($token_anlegen = false)
{
    $p = ax_paths();
    $meld = array();
    list($roh, $zustand) = ax_config_roh($p['config']);
    $erst = array('zustand' => $zustand, 'schritte' => array(), 'datei' => '', 'fehlend' => array());
    list($zroh, $zz) = ax_config_roh($p['backup']);
    $zweit_gut = ($zz === 'ok' && ax_config_hat_inhalt($zroh));

    if ($zustand === 'kaputt') {
        $ziel = $p['config'] . '.kaputt-' . date('Ymd-His');
        if (@rename($p['config'], $ziel)) {
            @chmod($ziel, 0600);
            $meld[] = sprintf(ax_t('MELDUNG.CFG_KAPUTT'), basename($ziel));
            $erst['schritte'][] = 'kaputt';
            $erst['datei'] = basename($ziel);
            $roh = null;
            $zustand = 'fehlt';
        } else {
            $meld[] = ax_t('MELDUNG.CFG_KAPUTT_FEST');
            $erst['schritte'][] = 'kaputt_fest';
            foreach ($meld as $m) { ax_log('WARN', 'Konfiguration: ' . $m); }
            ax_config_erstbefund($erst);
            return $meld;
        }
    }

    if (!ax_config_hat_inhalt($roh) && $zweit_gut) {
        if ($zustand === 'ok' && $roh) {
            $roh['sprechtoken'] = $zroh['sprechtoken'];
            $roh['aktionstoken'] = $zroh['aktionstoken'];
            $inhalt = $roh;
            $meld[] = ax_t('MELDUNG.CFG_TOKEN_AUS_ZWEIT');
            $erst['schritte'][] = 'token_aus_zweit';
        } else {
            $inhalt = $zroh;
            $meld[] = ax_t('MELDUNG.CFG_AUS_ZWEIT');
            $erst['schritte'][] = 'aus_zweit';
        }
        if (!ax_write_json($p['config'], $inhalt, 0600)) {
            $meld[] = sprintf(ax_t('MELDUNG.SPEICHERN_FEHL'), $p['config']);
        }
        list($roh, $zustand) = ax_config_roh($p['config']);
    }

    if ($token_anlegen && $zustand !== 'kaputt') {
        $roh = is_array($roh) ? $roh : array();
        $neu = false;
        foreach (array('sprechtoken', 'aktionstoken') as $tk) {
            if (!array_key_exists($tk, $roh)) {
                $roh[$tk] = ax_token_erzeugen();
                $neu = true;
            }
        }
        if ($neu) {
            $voll = $roh + ax_vorgaben();
            if (ax_config_speichern($voll)) {
                $meld[] = ax_t('MELDUNG.TOKEN_ANGELEGT');
                ax_log('INFO', 'Konfiguration: Token angelegt (keine vorhanden).');
                list($roh, $zustand) = ax_config_roh($p['config']);
            }
        }
    }

    if ($zustand === 'ok' && is_array($roh) && ax_config_hat_inhalt($roh)) {
        $fehlend = array_values(array_diff(array_keys(ax_vorgaben()), array_keys($roh)));
        if ($fehlend) {
            $voll = $roh;
            foreach (ax_vorgaben() as $k => $v) {
                if (!array_key_exists($k, $voll)) { $voll[$k] = $v; }
            }
            if (ax_config_speichern($voll)) {
                $meld[] = sprintf(ax_t('MELDUNG.CFG_VERVOLLSTAENDIGT'), count($fehlend), implode(', ', $fehlend));
                $erst['schritte'][] = 'vervollstaendigt';
                $erst['fehlend'] = $fehlend;
            }
        }
    }
    foreach ($meld as $m) { ax_log('INFO', 'Konfiguration: ' . strip_tags($m)); }
    ax_config_erstbefund($erst);
    ax_config();
    $lage = ax_config_lage();
    foreach ($lage['abgewiesen'] as $k => $g) {
        $meld[] = sprintf(ax_t('MELDUNG.CFG_WERT_ABGEWIESEN'), $k, ax_grund_text($g));
    }
    return $meld;
}

/* ---------------- Formularmerkmal ---------------- */

/** Abgeleitet aus dem Aktionstoken, nicht gespeichert (Regeln/04). */
function ax_formtoken(array $cfg)
{
    return hash_hmac('sha256', 'formular-v1', (string) $cfg['aktionstoken']);
}

function ax_formtoken_ok(array $cfg)
{
    $ist = (isset($_POST['formtoken']) && is_string($_POST['formtoken'])) ? $_POST['formtoken'] : '';
    if ($ist === '' || (string) $cfg['aktionstoken'] === '') { return false; }
    return hash_equals(ax_formtoken($cfg), $ist);
}

/* ==================================================================
 * Amazon-Anmeldung (amazon.json, 0600)
 * ================================================================== */

/** Form eines Erneuerungs-Tokens - absichtlich weit (Regeln/05, Bauplan 2.4a). */
function ax_refresh_form_ok($t)
{
    return is_string($t) && preg_match('/^Atnr\|[\x21-\x7E]{40,4000}\z/', $t) === 1;
}

/** Die Anmeldung roh und geprueft; null, wenn keine brauchbare vorliegt. */
function ax_amazon()
{
    $p = ax_paths();
    $d = ax_json_lesen($p['amazon']);
    if (!is_array($d) || !isset($d['refresh_token']) || !ax_refresh_form_ok($d['refresh_token'])) {
        return null;
    }
    $weg = (isset($d['weg']) && in_array($d['weg'], array('a', 'b'), true)) ? $d['weg'] : 'a';
    $serial = (isset($d['device_serial']) && is_string($d['device_serial'])
               && preg_match('/^[A-Za-z0-9]{8,64}\z/', $d['device_serial'])) ? $d['device_serial'] : '';
    return array(
        'refresh_token' => $d['refresh_token'],
        'weg' => $weg,
        'angemeldet_am' => isset($d['angemeldet_am']) ? (int) $d['angemeldet_am'] : 0,
        'device_serial' => $serial,
    );
}

/** Was liegt als Anmeldung vor - ohne den Wert? Fuer Oberflaeche und Test. */
function ax_amazon_lage()
{
    $p = ax_paths();
    $l = array('datei' => is_file($p['amazon']), 'form' => false, 'laenge' => 0, 'rechte' => '',
               'weg' => '', 'angemeldet_am' => 0);
    if (!$l['datei']) { return $l; }
    clearstatcache(true, $p['amazon']);
    $l['rechte'] = substr(sprintf('%o', (int) @fileperms($p['amazon'])), -4);
    $d = ax_json_lesen($p['amazon']);
    if (is_array($d) && isset($d['refresh_token']) && is_string($d['refresh_token'])) {
        $l['laenge'] = strlen($d['refresh_token']);
        $l['form'] = ax_refresh_form_ok($d['refresh_token']);
    }
    $a = ax_amazon();
    if ($a) { $l['weg'] = $a['weg']; $l['angemeldet_am'] = $a['angemeldet_am']; }
    return $l;
}

/** Anmeldung speichern, 0600, Zweitschrift 0600 daneben. */
function ax_amazon_speichern(array $a)
{
    $p = ax_paths();
    if (!ax_refresh_form_ok(isset($a['refresh_token']) ? $a['refresh_token'] : '')) { return false; }
    $d = array(
        'refresh_token' => $a['refresh_token'],
        'weg' => $a['weg'],
        'angemeldet_am' => time(),
        'device_serial' => isset($a['device_serial']) ? (string) $a['device_serial'] : '',
        'domain' => AX_DOMAIN,
    );
    if (!ax_write_json($p['amazon'], $d, 0600)) { return false; }
    if (!ax_write_json($p['backup_amz'], $d, 0600)) {
        ax_log('WARN', 'Anmeldung: Zweitschrift ' . $p['backup_amz'] . ' liess sich nicht schreiben.');
    }
    // Die alte Sitzung gehoert zur alten Anmeldung.
    if (is_file($p['datadir'] . '/sitzung.json')) { @unlink($p['datadir'] . '/sitzung.json'); }
    ax_anmeldung_befund('OK', 200, true);
    return true;
}

/** Anmeldung oertlich loeschen: Datei, Zweitschrift, Sitzung, Befund. */
function ax_amazon_loeschen()
{
    $p = ax_paths();
    $weg = 0;
    foreach (array($p['amazon'], $p['backup_amz'], $p['datadir'] . '/sitzung.json',
                   $p['datadir'] . '/anmeldung.json', $p['datadir'] . '/pkce.json') as $f) {
        if (is_file($f)) {
            // Ueberschreiben vor dem Loeschen erschwert das Wiederfinden - auf
            // Flash und Journal ist das kein sicheres Loeschen (Regeln/06).
            $n = (int) @filesize($f);
            @file_put_contents($f, str_repeat('0', max(1, $n)));
            if (@unlink($f)) { $weg++; }
        }
    }
    clearstatcache();
    return !is_file($p['amazon']) && !is_file($p['backup_amz']);
}

/** Die Zweitschrift der Anmeldung zurueckholen (Oberflaeche, Takt). */
function ax_amazon_heilen()
{
    $p = ax_paths();
    if (is_file($p['amazon']) || !is_file($p['backup_amz'])) { return ''; }
    $d = ax_json_lesen($p['backup_amz']);
    if (!is_array($d) || !isset($d['refresh_token']) || !ax_refresh_form_ok($d['refresh_token'])) { return ''; }
    $js = @file_get_contents($p['backup_amz']);
    if ($js !== false && ax_write_atomic($p['amazon'], $js, 0600)) {
        ax_log('INFO', 'Anmeldung: aus der Zweitschrift wiederhergestellt.');
        return ax_t('MELDUNG.AMZ_AUS_ZWEIT');
    }
    return '';
}

/**
 * Befund der Anmeldung (data/anmeldung.json): OK, ABGELAUFEN, AMAZON,
 * AMAZON_RATE, NETZ, AMAZON_UNERWARTET. Beim Wechsel nach ABGELAUFEN genau
 * eine Benachrichtigung (notify_ext, Regeln/03). $bestaetigt: Amazon hat die
 * Anmeldung gerade angenommen.
 */
function ax_anmeldung_befund($befund = null, $code = 0, $bestaetigt = false)
{
    $p = ax_paths();
    $f = $p['datadir'] . '/anmeldung.json';
    $alt = ax_json_lesen($f);
    if (!is_array($alt)) { $alt = array('befund' => '', 'zeit' => 0, 'bestaetigt' => 0, 'code' => 0); }
    if ($befund === null) { return $alt; }
    $neu = $alt;
    $neu['befund'] = (string) $befund;
    $neu['zeit'] = time();
    $neu['code'] = (int) $code;
    if ($bestaetigt) { $neu['bestaetigt'] = time(); }
    if ($befund === 'ABGELAUFEN' && $alt['befund'] !== 'ABGELAUFEN') {
        ax_log('ERROR', 'Anmeldung: Amazon hat das Erneuerungs-Token abgelehnt - neu anmelden (Reiter Amazon-Anmeldung).');
        ax_benachrichtigen(3, ax_t('MELDUNG.NOTIFY_ABGELAUFEN'));
    }
    ax_write_json($f, $neu, 0644);
    return $neu;
}

/** Benachrichtigung ueber notify_ext() - nur, wenn es die Funktion gibt. */
function ax_benachrichtigen($schwere, $text)
{
    $p = ax_paths();
    if ($p['lbhome'] === '') { return false; }
    $sdk = $p['lbhome'] . '/libs/phplib/loxberry_log.php';
    if (!function_exists('notify_ext')) {
        if (!is_file($sdk)) { return false; }
        if (is_file($p['lbhome'] . '/libs/phplib/loxberry_system.php')) {
            require_once $p['lbhome'] . '/libs/phplib/loxberry_system.php';
        }
        require_once $sdk;
    }
    if (!function_exists('notify_ext')) { return false; }
    notify_ext(array('PACKAGE' => $p['plugin'], 'NAME' => 'Alexa NG', 'MESSAGE' => (string) $text,
                     'SEVERITY' => (int) $schwere));
    return true;
}

/* ==================================================================
 * HTTP zu Amazon
 * ================================================================== */

/**
 * Die Adresse eines Amazon-Rechners. Der Pruefstand leitet auf eine Attrappe
 * um - aber NUR, wenn zugleich die Umgebungsvariable AX_AMAZON_BASIS eine
 * Adresse auf 127.0.0.1 traegt UND die Pruefmarke im Datenordner liegt. Aus
 * der Oberflaeche oder dem Netz ist beides nicht zu setzen; sonst waere es ein
 * Weg, das Token an einen fremden Rechner zu schicken (Bauplan 2.14).
 */
function ax_amazon_url($wirt, $pfad)
{
    $wirte = array('api' => 'api.' . AX_DOMAIN, 'alexa' => 'alexa.' . AX_DOMAIN, 'www' => 'www.' . AX_DOMAIN);
    $h = isset($wirte[$wirt]) ? $wirte[$wirt] : $wirte['alexa'];
    $b = (string) getenv('AX_AMAZON_BASIS');
    if ($b !== '' && preg_match('#^http://127\.0\.0\.1:[0-9]{2,5}\z#', $b)) {
        $p = ax_paths();
        if (is_file($p['datadir'] . '/pruefstand_amazon.marke')) {
            return $b . '/' . $h . $pfad;
        }
    }
    return 'https://' . $h . $pfad;
}

/** Laeuft der Pruefstand gegen die Attrappe? (fuer Oberflaeche und Protokoll) */
function ax_attrappe_aktiv()
{
    return strpos(ax_amazon_url('api', '/'), 'http://127.0.0.1') === 0;
}

function ax_useragent()
{
    return 'Mozilla/5.0 (X11; Linux aarch64) LoxBerry-Alexa-NG/' . (ax_pluginversion() !== '' ? ax_pluginversion() : '0');
}

/**
 * Eine Anfrage. Kopfzeilen ueber CURLOPT_HEADERFUNCTION (nicht
 * $http_response_header, Verfall unter 8.5), kein FOLLOWLOCATION, 5 s
 * Verbindung, 10 s gesamt. Rueckgabe: code (0 = keine Antwort), rumpf,
 * cookies (Set-Cookie name => wert), fehler (curl), dauer_ms.
 */
function ax_http($methode, $url, array $kopf = array(), $rumpf = null, $zeit = AX_HTTP_ZEIT_S)
{
    $aus = array('code' => 0, 'rumpf' => '', 'cookies' => array(), 'fehler' => '', 'dauer_ms' => 0);
    if (!function_exists('curl_init')) { $aus['fehler'] = 'CURL_FEHLT'; return $aus; }
    $t0 = microtime(true);
    $ch = curl_init($url);
    $kopf[] = 'User-Agent: ' . ax_useragent();
    $kopf[] = 'Accept-Language: de-DE,de;q=0.9';
    $kekse = array();
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => AX_HTTP_VERBINDEN_S,
        CURLOPT_TIMEOUT => (int) $zeit,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $kopf,
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => function ($c, $zeile) use (&$kekse) {
            if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/i', $zeile, $m)) {
                $kekse[$m[1]] = trim($m[2], '"');
            }
            return strlen($zeile);
        },
    ));
    if ($rumpf !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $rumpf); }
    $r = curl_exec($ch);
    $aus['code'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($r === false) {
        $aus['fehler'] = 'curl ' . curl_errno($ch) . ': ' . curl_error($ch);
        $aus['code'] = 0;
    } else {
        $aus['rumpf'] = (string) $r;
    }
    if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    $aus['cookies'] = $kekse;
    $aus['dauer_ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $aus;
}

/** Klasse einer Antwort (Bauplan 2.5): eine Zahl-Antwort, nie eine Textsuche. */
function ax_http_grund(array $r)
{
    if ($r['code'] === 0) { return ($r['fehler'] === 'CURL_FEHLT') ? 'CURL_FEHLT' : 'NETZ'; }
    if ($r['code'] === 429) { return 'AMAZON_RATE'; }
    if ($r['code'] >= 500) { return 'AMAZON'; }
    return 'AMAZON_UNERWARTET';
}

/** Protokollzeile zu einer unerwarteten Antwort - Adresse und Code, nie Rumpf. */
function ax_http_melden($schritt, $pfad, array $r)
{
    $was = $r['code'] ? 'HTTP ' . $r['code'] : ('keine Antwort (' . preg_replace('/[^A-Za-z0-9 :_.\-]/', '', substr($r['fehler'], 0, 80)) . ')');
    ax_log_wenn_neu('http_' . $schritt . '_' . $r['code'], 'WARN',
        'Amazon: ' . $schritt . ' ' . $pfad . ' -> ' . $was . ' nach ' . $r['dauer_ms'] . ' ms', 3600);
}

/* ---------------- Sitzung: Cookies, csrf, Kundennummer ---------------- */

function ax_sitzung_lesen()
{
    $p = ax_paths();
    $s = ax_json_lesen($p['datadir'] . '/sitzung.json');
    if (!is_array($s) || !isset($s['cookies']) || !is_array($s['cookies'])) { return null; }
    $s += array('csrf' => '', 'kunde' => '', 'getauscht_am' => 0);
    return $s;
}

function ax_sitzung_schreiben(array $s)
{
    $p = ax_paths();
    return ax_write_json($p['datadir'] . '/sitzung.json', $s, 0600);
}

/**
 * Erneuerungs-Token gegen Cookies tauschen (ARC:517).
 * Rueckgabe: array(ok, grund, cookies). grund ABGELAUFEN nur bei einer
 * 4xx-Antwort MIT Fehlernamen - sonst AMAZON_UNERWARTET.
 */
function ax_amazon_tauschen($refresh)
{
    $url = ax_amazon_url('api', '/ap/exchangetoken/cookies');
    $rumpf = http_build_query(array(
        'app_name' => 'Amazon Alexa',
        'requested_token_type' => 'auth_cookies',
        'domain' => 'www.' . AX_DOMAIN,
        'source_token_type' => 'refresh_token',
        'source_token' => $refresh,
    ), '', '&', PHP_QUERY_RFC3986);
    $r = ax_http('POST', $url, array('Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json', 'x-amzn-identity-auth-domain: api.' . AX_DOMAIN), $rumpf);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $j = json_decode($r['rumpf'], true);
        $kekse = array();
        if (is_array($j) && isset($j['response']['tokens']['cookies']) && is_array($j['response']['tokens']['cookies'])) {
            foreach ($j['response']['tokens']['cookies'] as $dom => $liste) {
                if (!is_array($liste)) { continue; }
                foreach ($liste as $k) {
                    if (is_array($k) && isset($k['Name'], $k['Value']) && is_string($k['Name']) && is_string($k['Value'])
                        && preg_match('/^[A-Za-z0-9_\-.+]{1,80}\z/', $k['Name'])) {
                        $kekse[$k['Name']] = $k['Value'];
                    }
                }
            }
        }
        $hat_at = false;
        foreach (array_keys($kekse) as $n) { if (strpos($n, 'at-') === 0) { $hat_at = true; break; } }
        if (!$hat_at) {
            ax_http_melden('Tausch', '/ap/exchangetoken/cookies (ohne at-Cookie)', $r);
            return array(false, 'AMAZON_UNERWARTET', array());
        }
        return array(true, '', $kekse);
    }
    if ($r['code'] >= 400 && $r['code'] < 500 && $r['code'] !== 429) {
        $j = json_decode($r['rumpf'], true);
        $name = '';
        if (is_array($j)) {
            if (isset($j['response']['error']['code']) && is_string($j['response']['error']['code'])) {
                $name = $j['response']['error']['code'];
            } elseif (isset($j['error']) && is_string($j['error'])) {
                $name = $j['error'];
            } elseif (isset($j['error']['code']) && is_string($j['error']['code'])) {
                $name = $j['error']['code'];
            }
        }
        $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
        if ($name !== '') {
            ax_log_wenn_neu('tausch_abgelehnt_' . $name, 'WARN', 'Amazon: Tausch abgelehnt (HTTP ' . $r['code'] . ', ' . $name . ').', 3600);
            return array(false, 'ABGELAUFEN', array());
        }
    }
    ax_http_melden('Tausch', '/ap/exchangetoken/cookies', $r);
    return array(false, ax_http_grund($r), array());
}

/** Der Kopf fuer Anfragen an alexa.amazon.de. */
function ax_alexa_kopf(array $s, $json = false)
{
    $paare = array();
    foreach ($s['cookies'] as $n => $w) { $paare[] = $n . '=' . $w; }
    $k = array('Cookie: ' . implode('; ', $paare),
               'Referer: https://alexa.' . AX_DOMAIN . '/spa/index.html',
               'Origin: https://alexa.' . AX_DOMAIN,
               'Accept: application/json, text/plain, */*');
    if ($s['csrf'] !== '') { $k[] = 'csrf: ' . $s['csrf']; }
    if ($json) { $k[] = 'Content-Type: application/json; charset=UTF-8'; }
    return $k;
}

/**
 * Eine gueltige Sitzung besorgen: aus dem Zwischenspeicher (hoechstens 24 h)
 * oder neu tauschen, dann csrf (ARC:529-550) und Kundennummer
 * (/api/users/me, ARC:577). Laeuft innerhalb der Amazon-Sperre.
 * Rueckgabe: array(ok, grund, sitzung).
 */
function ax_sitzung_sichern($erzwingen = false)
{
    $a = ax_amazon();
    if (!$a) { return array(false, 'ANMELDUNG', null); }
    $s = ax_sitzung_lesen();
    if (!$erzwingen && $s && time() - (int) $s['getauscht_am'] < AX_KEKS_S && time() >= (int) $s['getauscht_am']
        && $s['csrf'] !== '' && $s['kunde'] !== '') {
        return array(true, '', $s);
    }
    list($ok, $grund, $kekse) = ax_amazon_tauschen($a['refresh_token']);
    if (!$ok) {
        ax_anmeldung_befund($grund === 'ABGELAUFEN' ? 'ABGELAUFEN' : $grund, 0);
        return array(false, $grund === 'ABGELAUFEN' ? 'ANMELDUNG_ABGELAUFEN' : $grund, null);
    }
    $s = array('cookies' => $kekse, 'csrf' => '', 'kunde' => '', 'getauscht_am' => time());
    foreach (array('/api/language', '/templates/oobe/d-device-pick.handlebars', '/api/devices-v2/device?cached=false') as $pfad) {
        $r = ax_http('GET', ax_amazon_url('alexa', $pfad), ax_alexa_kopf($s));
        foreach ($r['cookies'] as $n => $w) { $s['cookies'][$n] = $w; }
        if (isset($s['cookies']['csrf']) && $s['cookies']['csrf'] !== '') { $s['csrf'] = (string) $s['cookies']['csrf']; break; }
        if ($r['code'] === 0 || $r['code'] >= 500 || $r['code'] === 429) {
            ax_http_melden('csrf', $pfad, $r);
            return array(false, ax_http_grund($r), null);
        }
    }
    if ($s['csrf'] === '') {
        ax_log_wenn_neu('kein_csrf', 'WARN', 'Amazon: kein csrf-Cookie erhalten (drei Wege versucht).', 3600);
        return array(false, 'AMAZON_UNERWARTET', null);
    }
    $r = ax_http('GET', ax_amazon_url('alexa', '/api/users/me'), ax_alexa_kopf($s));
    $j = ($r['code'] === 200) ? json_decode($r['rumpf'], true) : null;
    if (!is_array($j) || !isset($j['id']) || !is_string($j['id']) || !preg_match('/^[A-Za-z0-9]{4,64}\z/', $j['id'])) {
        ax_http_melden('Kunde', '/api/users/me', $r);
        return array(false, $r['code'] === 200 ? 'AMAZON_UNERWARTET' : ax_http_grund($r), null);
    }
    $s['kunde'] = $j['id'];
    ax_sitzung_schreiben($s);
    ax_anmeldung_befund('OK', 200, true);
    ax_log_wenn_neu('tausch_ok', 'INFO', 'Amazon: Cookies erneuert.', 3600);
    return array(true, '', $s);
}

/**
 * Eine Anfrage an alexa.amazon.de mit Sitzung. 401/403 -> EINMAL neu
 * tauschen und EINMAL wiederholen; 429 -> nach 2 s einmal wiederholen
 * (nicht mit $bei_429_nochmal = false: Radio, Bauliste alexa3 Z4 - dort folgt
 * eine Pause und eine ehrliche Antwort, kein stiller zweiter Versuch).
 * Rueckgabe: array(ok, grund, antwort).
 */
function ax_alexa($methode, $pfad, $rumpf = null, $bei_429_nochmal = true)
{
    list($ok, $grund, $s) = ax_sitzung_sichern(false);
    if (!$ok) { return array(false, $grund, null); }
    $json = ($rumpf !== null);
    $r = ax_http($methode, ax_amazon_url('alexa', $pfad), ax_alexa_kopf($s, $json), $rumpf);
    if ($r['code'] === 401 || $r['code'] === 403) {
        list($ok, $grund, $s) = ax_sitzung_sichern(true);
        if (!$ok) { return array(false, $grund, $r); }
        $r = ax_http($methode, ax_amazon_url('alexa', $pfad), ax_alexa_kopf($s, $json), $rumpf);
    }
    if ($r['code'] === 429 && $bei_429_nochmal) {
        sleep(2);
        $r = ax_http($methode, ax_amazon_url('alexa', $pfad), ax_alexa_kopf($s, $json), $rumpf);
    }
    if ($r['code'] >= 200 && $r['code'] < 300) {
        // Jede erfolgreiche Amazon-Antwort (Ansage, Geraeteliste, Status) bestaetigt
        // die Anmeldung - "zuletzt bestaetigt" stand sonst auf dem letzten Statustakt
        // (am Geraet 01.10.2026: 07:10 angezeigt, Ansage um 07:27 mit OK=1).
        ax_anmeldung_befund('OK', $r['code'], true);
        return array(true, '', $r);
    }
    ax_http_melden($methode, preg_replace('/\?.*$/', '', $pfad), $r);
    return array(false, ax_http_grund($r), $r);
}

/** Ist die Anmeldung gueltig? (/api/customer-status, ARC:573-584) */
function ax_amazon_status_pruefen()
{
    list($ok, $grund, $r) = ax_alexa('GET', '/api/customer-status');
    if ($ok) { return array(true, ''); }   // bestaetigt schon in ax_alexa()
    if ($grund !== 'ANMELDUNG_ABGELAUFEN' && $grund !== 'ANMELDUNG') {
        ax_anmeldung_befund($grund, $r ? $r['code'] : 0);
    }
    return array(false, $grund);
}

/* ---------------- Weg (b2): Code-Uebergabe mit PKCE [ungemessen] ---------------- */

function ax_b64url($roh)
{
    return rtrim(strtr(base64_encode($roh), '+/', '-_'), '=');
}

/**
 * Anmeldeadresse erzeugen: code_verifier (32 Zufallsbytes), Pruefwert
 * S256, eigene Geraete-Seriennummer; alles 30 Minuten in pkce.json (0600).
 * Die Parameter stammen aus alexa-cookie2/alexapy [ungemessen].
 */
function ax_pkce_starten()
{
    $p = ax_paths();
    $verifier = ax_b64url(random_bytes(32));
    $challenge = ax_b64url(hash('sha256', $verifier, true));
    $serial = strtoupper(bin2hex(random_bytes(16)));
    $client = bin2hex($serial . '#' . AX_APP_TYP);
    $handle = 'amzn_dp_project_dee_ios_de';
    $adresse = ax_amazon_url('www', '/ap/signin') . '?' . http_build_query(array(
        'openid.return_to' => 'https://www.' . AX_DOMAIN . '/ap/maplanding',
        'openid.assoc_handle' => $handle,
        'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
        'pageId' => 'amzn_dp_project_dee_ios',
        'accountStatusPolicy' => 'P1',
        'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.mode' => 'checkid_setup',
        'openid.ns.oa2' => 'http://www.amazon.com/ap/ext/oauth/2',
        'openid.oa2.client_id' => 'device:' . $client,
        'openid.ns.pape' => 'http://specs.openid.net/extensions/pape/1.0',
        'openid.oa2.response_type' => 'code',
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.pape.max_auth_age' => '0',
        'openid.oa2.scope' => 'device_auth_access',
        'openid.oa2.code_challenge_method' => 'S256',
        'openid.oa2.code_challenge' => $challenge,
        'language' => 'de_DE',
    ), '', '&', PHP_QUERY_RFC3986);
    $ok = ax_write_json($p['datadir'] . '/pkce.json', array(
        'verifier' => $verifier, 'serial' => $serial, 'client' => $client, 'handle' => $handle,
        'adresse' => $adresse, 'seit' => time()), 0600);
    return $ok ? $adresse : '';
}

/** Laufende Code-Uebergabe (hoechstens 30 Minuten alt) oder null. */
function ax_pkce_lage()
{
    $p = ax_paths();
    $l = ax_json_lesen($p['datadir'] . '/pkce.json');
    if (!is_array($l) || !isset($l['verifier'], $l['serial'], $l['client'], $l['handle'], $l['adresse'], $l['seit'])) {
        return null;
    }
    $alter = time() - (int) $l['seit'];
    if ($alter > 1800 || $alter < 0) { return null; }
    return $l;
}

/**
 * Die eingefuegte Umleitungsadresse (oder den nackten Code) einloesen.
 * Rueckgabe: array(ok, grund). Gespeichert wird nur das Erneuerungs-Token.
 */
function ax_pkce_einloesen($eingabe)
{
    $p = ax_paths();
    $l = ax_pkce_lage();
    if (!$l) { return array(false, 'KEINE_ANMELDUNG_OFFEN'); }
    if (!is_string($eingabe)) { return array(false, 'KEIN_CODE'); }
    $eingabe = trim($eingabe);
    if ($eingabe === '' || strlen($eingabe) > 8000 || preg_match('/[\x00-\x1F\x7F]/', $eingabe)) {
        return array(false, 'KEIN_CODE');
    }
    $code = '';
    if (preg_match('/[?&]openid\.oa2\.authorization_code=([^&#\s]+)/', $eingabe, $m)) {
        $code = rawurldecode($m[1]);
        /* Maplanding traegt keinen eigenen state (alexapy) [ungemessen]. Als
         * Gegenstueck gilt: dieselbe assoc_handle und dieselbe Rueckadresse
         * wie in UNSERER Anmeldeadresse - sonst gehoert der Code zu einer
         * anderen Anmeldung. */
        if (preg_match('/[?&]openid\.assoc_handle=([^&#\s]+)/', $eingabe, $mh) && rawurldecode($mh[1]) !== $l['handle']) {
            return array(false, 'FREMDE_ANMELDUNG');
        }
        if (!preg_match('#^https://www\.amazon\.de/ap/maplanding\?#', $eingabe)
            && strpos($eingabe, 'http://127.0.0.1') !== 0) {
            return array(false, 'FREMDE_ANMELDUNG');
        }
    } elseif (preg_match('/^[A-Za-z0-9._\-]{10,200}\z/', $eingabe)) {
        $code = $eingabe;
    }
    if ($code === '' || !preg_match('/^[A-Za-z0-9._\-]{10,200}\z/', $code)) { return array(false, 'KEIN_CODE'); }
    $rumpf = json_encode(array(
        'requested_extensions' => array('device_info', 'customer_info'),
        'cookies' => array('website_cookies' => array(), 'domain' => '.' . AX_DOMAIN),
        'registration_data' => array(
            'domain' => 'Device', 'app_version' => '2.2.556530.0', 'device_type' => AX_APP_TYP,
            'device_name' => AX_GERAET_NAME, 'os_version' => '16.6', 'device_serial' => $l['serial'],
            'device_model' => 'iPhone', 'app_name' => AX_GERAET_NAME, 'software_version' => '1'),
        'auth_data' => array('client_id' => $l['client'], 'authorization_code' => $code,
            'code_verifier' => $l['verifier'], 'code_algorithm' => 'SHA-256', 'client_domain' => 'DeviceLegacy'),
        'requested_token_type' => array('bearer', 'mac_dms', 'website_cookies'),
    ), JSON_UNESCAPED_SLASHES);
    $r = ax_http('POST', ax_amazon_url('api', '/auth/register'), array('Content-Type: application/json',
        'Accept: application/json', 'x-amzn-identity-auth-domain: api.' . AX_DOMAIN), $rumpf);
    $j = json_decode($r['rumpf'], true);
    if ($r['code'] === 200 && is_array($j) && isset($j['response']['success']['tokens']['bearer']['refresh_token'])) {
        $rt = $j['response']['success']['tokens']['bearer']['refresh_token'];
        if (!ax_refresh_form_ok($rt)) {
            ax_log('WARN', 'Anmeldung: Amazon lieferte ein Token in unerwarteter Form (Laenge ' . strlen((string) $rt) . ').');
            return array(false, 'AMAZON_UNERWARTET');
        }
        if (!ax_amazon_speichern(array('refresh_token' => $rt, 'weg' => 'b', 'device_serial' => $l['serial']))) {
            return array(false, 'SPEICHERN');
        }
        @unlink($p['datadir'] . '/pkce.json');
        ax_log('INFO', 'Anmeldung: Code eingeloest (Weg b), Erneuerungs-Token gespeichert.');
        return array(true, '');
    }
    if ($r['code'] >= 400 && $r['code'] < 500 && $r['code'] !== 429) {
        $name = '';
        if (is_array($j) && isset($j['response']['error']['code']) && is_string($j['response']['error']['code'])) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', $j['response']['error']['code']);
        } elseif (is_array($j) && isset($j['error']) && is_string($j['error'])) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', $j['error']);
        }
        ax_log('WARN', 'Anmeldung: Code abgelehnt (HTTP ' . $r['code'] . ($name !== '' ? ', ' . $name : '') . ').');
        return array(false, 'CODE_ABGELEHNT' . ($name !== '' ? '|' . $name : ''));
    }
    ax_http_melden('Registrierung', '/auth/register', $r);
    return array(false, ax_http_grund($r));
}

/**
 * Bei Amazon abmelden [ungemessen]: Erneuerungs-Token gegen ein
 * Zugriffs-Token (/auth/token), damit /auth/deregister. Rueckgabe
 * array(ok, grund). Loescht nichts oertlich.
 */
function ax_amazon_abmelden()
{
    $a = ax_amazon();
    if (!$a) { return array(false, 'ANMELDUNG'); }
    $r = ax_http('POST', ax_amazon_url('api', '/auth/token'), array('Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json', 'x-amzn-identity-auth-domain: api.' . AX_DOMAIN),
        http_build_query(array('app_name' => AX_GERAET_NAME, 'app_version' => '2.2.556530.0',
            'source_token' => $a['refresh_token'], 'requested_token_type' => 'access_token',
            'source_token_type' => 'refresh_token'), '', '&', PHP_QUERY_RFC3986));
    $j = json_decode($r['rumpf'], true);
    if ($r['code'] !== 200 || !is_array($j) || !isset($j['access_token']) || !is_string($j['access_token'])) {
        ax_http_melden('Abmeldung', '/auth/token', $r);
        return array(false, $r['code'] >= 400 && $r['code'] < 500 && $r['code'] !== 429 ? 'ABGELAUFEN' : ax_http_grund($r));
    }
    $r = ax_http('POST', ax_amazon_url('api', '/auth/deregister'), array('Content-Type: application/json',
        'Accept: application/json', 'Authorization: Bearer ' . $j['access_token'],
        'x-amzn-identity-auth-domain: api.' . AX_DOMAIN),
        json_encode(array('requested_extensions' => array('device_info', 'customer_info'),
                          'deregister_all_existing_accounts' => false)));
    if ($r['code'] === 200) {
        ax_log('INFO', 'Anmeldung: bei Amazon abgemeldet.');
        return array(true, '');
    }
    ax_http_melden('Abmeldung', '/auth/deregister', $r);
    return array(false, ax_http_grund($r));
}

/* ==================================================================
 * Geraete und Namen
 * ================================================================== */

/**
 * Normalname (Entscheidung 15): klein, ae/oe/ue/ss, jedes andere Zeichen
 * ausser a-z/0-9 wird '_', hoechstens 40 Zeichen. Eine Funktion fuer
 * Geraete, Gruppen, Aufrufe und Themen.
 */
function ax_name_normal($s)
{
    $s = (string) $s;
    $s = str_replace(array('Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü', 'ß', 'ẞ'), array('ae', 'oe', 'ue', 'ae', 'oe', 'ue', 'ss', 'ss'), $s);
    $s = strtolower($s);
    $n = @preg_replace('/[^a-z0-9]/u', '_', $s);
    if (!is_string($n)) { $n = preg_replace('/[^a-z0-9]/', '_', $s); }
    return substr((string) $n, 0, 40);
}

/** Sprechfaehige Familien (tts.php:107, ARC:1237). */
function ax_familien()
{
    return array('ECHO', 'KNIGHT', 'ROOK', 'WHA');
}

/** Die gemerkte Namenszuordnung (Seriennummer -> Normalname), neben dem Datenordner. */
function ax_namen_lesen()
{
    $p = ax_paths();
    $d = ax_json_lesen($p['namen']);
    $aus = array();
    if (is_array($d)) {
        foreach ($d as $ser => $n) {
            if (is_string($n) && preg_match('/^[a-z0-9_]{1,40}\z/', $n) && preg_match('/^[A-Za-z0-9]{4,64}\z/', (string) $ser)) {
                $aus[(string) $ser] = $n;
            }
        }
    }
    return $aus;
}

/**
 * Die Geraeteliste bei Amazon holen (devices-v2, ARC:561-567) und mit den
 * Lautstaerken (allDeviceVolumes, ARC:1021) in geraete.json ablegen.
 * Bestehende Normalnamen werden nie umbenannt; ein zweiter gleicher Name
 * bekommt _2, _3 ... Rueckgabe: array(ok, grund, liste).
 */
function ax_geraete_holen()
{
    $p = ax_paths();
    list($ok, $grund, $r) = ax_alexa('GET', '/api/devices-v2/device?cached=false');
    if (!$ok) { return array(false, $grund, null); }
    $j = json_decode($r['rumpf'], true);
    if (!is_array($j) || !isset($j['devices']) || !is_array($j['devices'])) {
        ax_log_wenn_neu('devices_form', 'WARN', 'Amazon: devices-v2 ohne Feld devices - Schnittstelle geaendert?', 3600);
        return array(false, 'AMAZON_UNERWARTET', null);
    }
    $laut = array();
    list($ok2, , $r2) = ax_alexa('GET', '/api/devices/deviceType/dsn/audio/v1/allDeviceVolumes');
    if ($ok2) {
        $jv = json_decode($r2['rumpf'], true);
        if (is_array($jv) && isset($jv['volumes']) && is_array($jv['volumes'])) {
            foreach ($jv['volumes'] as $v) {
                if (is_array($v) && isset($v['dsn'], $v['speakerVolume']) && is_scalar($v['dsn'])
                    && is_numeric($v['speakerVolume'])) {
                    $laut[(string) $v['dsn']] = max(0, min(100, (int) $v['speakerVolume']));
                }
            }
        }
    }
    $namen = ax_namen_lesen();
    $namen_vorher = $namen;
    $alt_st = ax_geraete();
    $vergeben = array_flip(array_values($namen));
    $gesamt = 0;
    $liste = array();
    $roh = array();
    foreach ($j['devices'] as $d) {
        if (!is_array($d)) { continue; }
        $gesamt++;
        $fam = isset($d['deviceFamily']) && is_string($d['deviceFamily']) ? $d['deviceFamily'] : '';
        $name = isset($d['accountName']) && is_string($d['accountName']) ? $d['accountName'] : '';
        $ser = isset($d['serialNumber']) && is_string($d['serialNumber']) ? $d['serialNumber'] : '';
        $typ = isset($d['deviceType']) && is_string($d['deviceType']) ? $d['deviceType'] : '';
        if (!in_array($fam, ax_familien(), true) || $name === '' || $name === 'This Device'
            || !preg_match('/^[A-Za-z0-9]{4,64}\z/', $ser) || !preg_match('/^[A-Za-z0-9]{4,64}\z/', $typ)) {
            continue;
        }
        $mitglieder = array();
        if ($fam === 'WHA' && isset($d['clusterMembers']) && is_array($d['clusterMembers'])) {
            foreach ($d['clusterMembers'] as $m) {
                if (is_string($m) && preg_match('/^[A-Za-z0-9]{4,64}\z/', $m)) { $mitglieder[] = $m; }
            }
        }
        $roh[] = array('anzeige' => substr($name, 0, 120), 'familie' => $fam, 'typ' => $typ, 'serial' => $ser,
                       'online' => !empty($d['online']) ? 1 : 0, 'mitglieder' => $mitglieder,
                       'laut' => isset($laut[$ser]) ? $laut[$ser] : -1);
    }
    // Stabil: nach Seriennummer, damit ein Doppelname immer gleich verteilt wird.
    usort($roh, function ($a, $b) { return strcmp($a['serial'], $b['serial']); });
    $doppel = array();
    foreach ($roh as $g) {
        if (isset($namen[$g['serial']])) {
            $g['normal'] = $namen[$g['serial']];
        } else {
            $basis = ax_name_normal($g['anzeige']);
            if ($basis === '' || trim($basis, '_') === '') { $basis = 'geraet'; }
            $n = $basis;
            $i = 2;
            while (isset($vergeben[$n]) || in_array($n, array('alle', 'gruppe'), true)) {
                $n = substr($basis, 0, 40 - strlen('_' . $i)) . '_' . $i;
                $i++;
            }
            $g['normal'] = $n;
            $namen[$g['serial']] = $n;
            $vergeben[$n] = 1;
        }
        if (ax_name_normal($g['anzeige']) !== $g['normal']) { $doppel[] = $g['normal']; }
        $liste[] = $g;
    }
    // K4: Geraete mit vergebenem Normalnamen, die Amazon nicht mehr meldet,
    // bleiben als "verschwunden" sichtbar - seit dem ersten Fehlen. Grundlage
    // ist die Namenszuordnung neben dem Datenordner; sie uebersteht ein Update.
    $jetzt_ser = array();
    foreach ($liste as $g) { $jetzt_ser[$g['serial']] = 1; }
    $alt_ger = array();
    $alt_weg = array();
    if ($alt_st) {
        foreach ($alt_st['liste'] as $g) { if (is_array($g) && isset($g['serial'])) { $alt_ger[$g['serial']] = $g; } }
        foreach ($alt_st['verschwunden'] as $g) { $alt_weg[$g['serial']] = $g; }
    }
    $verschwunden = array();
    foreach ($namen_vorher as $ser => $nn) {
        if (isset($jetzt_ser[$ser])) { continue; }
        if (isset($alt_weg[$ser])) {
            $e = $alt_weg[$ser];
        } else {
            $e = array('anzeige' => isset($alt_ger[$ser]['anzeige']) ? (string) $alt_ger[$ser]['anzeige'] : '',
                       'familie' => isset($alt_ger[$ser]['familie']) ? (string) $alt_ger[$ser]['familie'] : '',
                       'seit' => time());
            ax_log('WARN', 'Geraete: ' . $nn . ' meldet Amazon nicht mehr - im Reiter Geraete als verschwunden gefuehrt.');
        }
        $e['serial'] = (string) $ser;
        $e['normal'] = $nn;
        $verschwunden[] = $e;
    }
    ax_write_json($p['namen'], $namen, 0644);
    $st = array('stand' => time(), 'konto_gesamt' => $gesamt, 'liste' => $liste, 'verschwunden' => $verschwunden);
    ax_write_json($p['datadir'] . '/geraete.json', $st, 0644);
    ax_log_wenn_neu('geraete_' . md5(json_encode(array_map(function ($g) { return $g['normal']; }, $liste))),
        'INFO', 'Geraete: ' . $gesamt . ' im Konto, ' . count($liste) . ' sprechfaehig.', 86400);
    return array(true, '', $st);
}

/** Die zwischengespeicherte Geraeteliste; null, wenn es keine gibt. */
function ax_geraete()
{
    $p = ax_paths();
    $d = ax_json_lesen($p['datadir'] . '/geraete.json');
    if (!is_array($d) || !isset($d['liste']) || !is_array($d['liste'])) { return null; }
    $d += array('stand' => 0, 'konto_gesamt' => 0, 'verschwunden' => array());
    $weg = array();
    if (is_array($d['verschwunden'])) {
        foreach ($d['verschwunden'] as $g) {
            if (is_array($g) && isset($g['serial'], $g['normal']) && is_string($g['serial']) && is_string($g['normal'])
                && preg_match('/^[a-z0-9_]{1,40}\z/', $g['normal'])) {
                $weg[] = array('serial' => $g['serial'], 'normal' => $g['normal'],
                    'anzeige' => (isset($g['anzeige']) && is_string($g['anzeige'])) ? $g['anzeige'] : '',
                    'familie' => (isset($g['familie']) && is_string($g['familie'])) ? $g['familie'] : '',
                    'seit' => isset($g['seit']) ? (int) $g['seit'] : 0);
            }
        }
    }
    $d['verschwunden'] = $weg;
    return $d;
}

/**
 * Grund fuer einen Normalnamen, den die Geraeteliste nicht kennt (alexa4 N5):
 * GERAET_VERSCHWUNDEN, wenn er einem verschwundenen Geraet gehoert (Amazon
 * meldet es nicht mehr), sonst GERAET_UNBEKANNT. Der HTTP-Code bleibt 404.
 */
function ax_geraet_fehlt_grund($normal, array $st)
{
    if (isset($st['verschwunden']) && is_array($st['verschwunden'])) {
        foreach ($st['verschwunden'] as $g) {
            if (is_array($g) && isset($g['normal']) && $g['normal'] === $normal) { return 'GERAET_VERSCHWUNDEN'; }
        }
    }
    return 'GERAET_UNBEKANNT';
}

/**
 * Ein verschwundenes Geraet austragen (alexa4 N4): seine Zuordnung
 * Seriennummer -> Normalname aus der Namenszuordnung nehmen und den Eintrag
 * aus "verschwunden" der Geraeteliste. Danach ist der Normalname frei. Nie
 * ein Geraet, das Amazon noch meldet. Unter der Amazon-Sperre - der Takt
 * schreibt dieselben Dateien. Rueckgabe array(ok, grund).
 */
function ax_geraet_austragen($normal)
{
    if (!is_string($normal) || !preg_match('/^[a-z0-9_]{1,40}\z/', $normal)) { return array(false, 'GERAET'); }
    $p = ax_paths();
    $sp = ax_sperre();
    if (!$sp) { return array(false, 'BESCHAEFTIGT'); }
    $st = ax_geraete();
    $weg = array();
    $rest = array();
    $gemeldet = false;
    if ($st) {
        foreach ($st['liste'] as $g) { if (is_array($g) && isset($g['normal']) && $g['normal'] === $normal) { $gemeldet = true; } }
        foreach ($st['verschwunden'] as $g) { if ($g['normal'] === $normal) { $weg[] = $g['serial']; } else { $rest[] = $g; } }
    }
    if ($gemeldet || !$weg) {
        ax_sperre_frei($sp);
        return array(false, $gemeldet ? 'GERAET_GEMELDET' : 'GERAET_NICHT_VERSCHWUNDEN');
    }
    $namen = ax_namen_lesen();
    foreach ($weg as $ser) { unset($namen[$ser]); }
    $roh = ax_json_lesen($p['datadir'] . '/geraete.json');
    $ok = is_array($roh) && ax_write_json($p['namen'], $namen, 0644);
    if ($ok) {
        $roh['verschwunden'] = $rest;
        $ok = ax_write_json($p['datadir'] . '/geraete.json', $roh, 0644);
    }
    ax_sperre_frei($sp);
    if (!$ok) { return array(false, 'SCHREIBEN'); }
    ax_log('INFO', 'Geraete: ' . $normal . ' ausgetragen (verschwunden, von Hand) - der Normalname ist frei.');
    return array(true, '');
}

/** Plugins mit der Ausgabeart "Alexa-NG": Ordner => (Titel, Konfigurationsdatei). */
function ax_bekannte_plugins()
{
    return array(
        'sprachsteuerung'   => array('Sprachsteuerung lokal', 'sprachsteuerung.json'),
        'octopus'           => array('Octopus Dynamic', 'octopus.json'),
        'awmabfuhr'         => array('Abfuhrkalender (AWM & iCal)', 'awm.json'),
        'abfahrtsassistent' => array('Abfahrts-Assistent', 'abfahrt.json'),
        'ferien'            => array('Ferien und Feiertage', 'ferien.json'),
    );
}

/**
 * Wo steht ein Normalname noch (K4)? Standardgeraet, eigene Gruppen und die
 * Ausgabeart "Alexa-NG" der bekannten Plugins, soweit ihre Konfiguration
 * lesbar ist. Gelesen werden dort nur tts.mode und tts.alexa_geraet; nichts
 * davon geht in eine Ausgabe ausser dem Titel des Plugins.
 * Rueckgabe array(orte, nicht_lesbar) - je eine Liste von Texten.
 */
function ax_name_verwendung($normal, array $cfg)
{
    $orte = array();
    $nicht = array();
    $ist_std = ($cfg['standardgeraet'] !== '' && $cfg['standardgeraet'] === $normal);
    if ($ist_std) { $orte[] = ax_t('GER.ORT_STANDARD'); }
    foreach ($cfg['gruppen'] as $z) {
        if (in_array($normal, explode(',', $z['geraete']), true)) { $orte[] = sprintf(ax_t('GER.ORT_GRUPPE'), $z['name']); }
    }
    $p = ax_paths();
    if ($p['lbhome'] === '') { return array($orte, $nicht); }
    foreach (ax_bekannte_plugins() as $ordner => $d) {
        $datei = $p['lbhome'] . '/config/plugins/' . $ordner . '/' . $d[1];
        if (!is_file($datei)) { continue; }
        $roh = is_readable($datei) ? @file_get_contents($datei) : false;
        $j = ($roh !== false) ? json_decode((string) $roh, true) : null;
        if (!is_array($j)) { $nicht[] = $d[0]; continue; }
        $t = (isset($j['tts']) && is_array($j['tts'])) ? $j['tts'] : array();
        if (!isset($t['mode']) || $t['mode'] !== 'alexang') { continue; }
        $ger = (isset($t['alexa_geraet']) && is_string($t['alexa_geraet'])) ? trim($t['alexa_geraet']) : '';
        if ($ger === '') {
            if ($ist_std) { $orte[] = sprintf(ax_t('GER.ORT_PLUGIN_STD'), $d[0]); }
            continue;
        }
        foreach (explode(',', $ger) as $teil) {
            $teil = trim($teil);
            if ($teil === '' || strtolower($teil) === 'alle' || stripos($teil, 'gruppe:') === 0) { continue; }
            if (ax_name_normal($teil) === $normal) { $orte[] = sprintf(ax_t('GER.ORT_PLUGIN'), $d[0]); break; }
        }
    }
    return array($orte, $nicht);
}

/** Ein Text von Amazon fuer die Anzeige: UTF-8, ohne Steuerzeichen, hoechstens 120 Zeichen. */
function ax_anzeige_text($s)
{
    $s = is_string($s) ? $s : '';
    if (preg_match('//u', $s) !== 1) { return ''; }
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
    preg_match('/^.{0,120}/su', $s, $m);
    return $m[0];
}

/**
 * Den Parameter geraet aufloesen: Kommaliste aus Normalnamen, Anzeigenamen,
 * gruppe:<name> (eigene Gruppe, sonst Amazon-Gruppe) oder alle. Amazon-Gruppen
 * (WHA) werden in ihre Mitglieder aufgeloest - kein echtes Multiroom
 * (ARC:709-712). Ein unbekannter Name wird NIE durch "alle" ersetzt.
 * Rueckgabe: array(http, grund, ziele, offline, namen, name_unbekannt).
 */
function ax_geraete_aufloesen($param, array $cfg, array $st)
{
    $nach_normal = array();
    $nach_serial = array();
    foreach ($st['liste'] as $g) {
        $nach_normal[$g['normal']] = $g;
        $nach_serial[$g['serial']] = $g;
    }
    $gruppen = array();
    foreach ($cfg['gruppen'] as $z) { $gruppen[$z['name']] = explode(',', $z['geraete']); }
    $ziele = array();
    $offline = 0;
    $namen = array();
    $unbekannt_in_gruppe = 0;
    $eintrag = function ($g) use (&$ziele, &$offline, $nach_serial) {
        if ($g['familie'] === 'WHA') {
            foreach ($g['mitglieder'] as $s) {
                if (!isset($nach_serial[$s]) || $nach_serial[$s]['familie'] === 'WHA') { continue; }
                $m = $nach_serial[$s];
                if (empty($m['online'])) { $offline++; continue; }
                $ziele[$m['serial']] = $m;
            }
            return;
        }
        if (empty($g['online'])) { $offline++; return; }
        $ziele[$g['serial']] = $g;
    };
    foreach (explode(',', (string) $param) as $roh) {
        $roh = trim($roh);
        if ($roh === '') { return array(400, 'GERAET', array(), 0, array(), ''); }
        if (strtolower($roh) === 'alle') {
            $namen[] = 'alle';
            foreach ($st['liste'] as $g) { if ($g['familie'] !== 'WHA') { $eintrag($g); } }
            continue;
        }
        if (stripos($roh, 'gruppe:') === 0) {
            $gn = ax_name_normal(substr($roh, 7));
            $namen[] = 'gruppe:' . $gn;
            if (isset($gruppen[$gn])) {
                foreach ($gruppen[$gn] as $gname) {
                    if (isset($nach_normal[$gname])) { $eintrag($nach_normal[$gname]); } else { $unbekannt_in_gruppe++; }
                }
                continue;
            }
            if (isset($nach_normal[$gn]) && $nach_normal[$gn]['familie'] === 'WHA') {
                $eintrag($nach_normal[$gn]);
                continue;
            }
            return array(404, 'GRUPPE_UNBEKANNT', array(), 0, array(), $gn);
        }
        $n = ax_name_normal($roh);
        if (!isset($nach_normal[$n])) { return array(404, ax_geraet_fehlt_grund($n, $st), array(), 0, array(), $n); }
        $namen[] = $n;
        $eintrag($nach_normal[$n]);
    }
    $offline += $unbekannt_in_gruppe;
    if (!$ziele) {
        return array(503, 'GERAETE_OFFLINE', array(), $offline, $namen, '');
    }
    return array(200, '', array_values($ziele), $offline, $namen, '');
}

/* ==================================================================
 * Texte und Befehlsfolgen
 * ================================================================== */

/** Zeichen zaehlen ohne mbstring (auf dem LoxBerry nicht garantiert). */
function ax_zeichen($s)
{
    return (int) preg_match_all('/./su', (string) $s);
}

/**
 * Einen Text pruefen. Rueckgabe: array(http, grund, text). http 200 mit
 * grund TEXT_NULL heisst: nichts sprechen, kein Fehler (Statusbaustein-Falle,
 * tts.php:66-71).
 */
function ax_text_pruefen($text, $ssml)
{
    if (!is_string($text)) { return array(400, 'TEXT', ''); }
    if (preg_match('//u', $text) !== 1) { return array(400, 'TEXT', ''); }
    if (preg_match('/[\x00-\x1F\x7F]/', $text)) { return array(400, 'TEXT', ''); }
    $t = trim($text);
    if ($t === '' || $t === '0') { return array(200, 'TEXT_NULL', ''); }
    $n = ax_zeichen($t);
    if ($n > AX_TEXT_MAX) { return array(400, 'TEXT', ''); }
    if ($ssml) {
        if (substr($t, 0, 7) !== '<speak>' || substr($t, -8) !== '</speak>' || $n > AX_SSML_MAX) {
            return array(400, 'SSML', '');
        }
    } elseif (strpbrk($t, '<>') !== false) {
        return array(400, 'SSML_OHNE_SCHALTER', '');
    }
    return array(200, '', $t);
}

/**
 * Einen langen Text an Satzgrenzen in Teile <= $max Zeichen trennen; nie in
 * einer Wortmitte, ausser ein einzelnes Wort ist laenger als $max.
 */
function ax_text_teilen($t, $max = AX_TEIL_MAX)
{
    if (ax_zeichen($t) <= $max) { return array($t); }
    $saetze = preg_split('/(?<=[.!?;:])\s+/u', $t);
    $teile = array();
    $cur = '';
    foreach ($saetze as $satz) {
        $kand = ($cur === '') ? $satz : $cur . ' ' . $satz;
        if (ax_zeichen($kand) <= $max) { $cur = $kand; continue; }
        if ($cur !== '') { $teile[] = $cur; $cur = ''; }
        if (ax_zeichen($satz) <= $max) { $cur = $satz; continue; }
        foreach (preg_split('/\s+/u', $satz) as $wort) {
            $kand = ($cur === '') ? $wort : $cur . ' ' . $wort;
            if (ax_zeichen($kand) <= $max) { $cur = $kand; continue; }
            if ($cur !== '') { $teile[] = $cur; $cur = ''; }
            while (ax_zeichen($wort) > $max) {
                preg_match('/^.{' . $max . '}/su', $wort, $m);
                $teile[] = $m[0];
                $wort = substr($wort, strlen($m[0]));
            }
            $cur = $wort;
        }
    }
    if ($cur !== '') { $teile[] = $cur; }
    return $teile;
}

/** Ein Blatt der Befehlsfolge (ARC:657-659). */
function ax_knoten($typ, array $geraet, $kunde, array $mehr)
{
    return array(
        '@type' => 'com.amazon.alexa.behaviors.model.OpaquePayloadOperationNode',
        'type' => $typ,
        'operationPayload' => array('deviceType' => $geraet['typ'], 'deviceSerialNumber' => $geraet['serial'],
                                    'customerId' => $kunde, 'locale' => AX_LOCALE) + $mehr,
    );
}

function ax_parallel(array $knoten)
{
    return array('@type' => 'com.amazon.alexa.behaviors.model.ParallelNode', 'nodesToExecute' => $knoten);
}

function ax_sequenz(array $start)
{
    return array('@type' => 'com.amazon.alexa.behaviors.model.Sequence', 'startNode' => $start);
}

/**
 * Sprechen: ein ParallelNode ueber alle Geraete je Textteil; mehrere Teile
 * oder eine Lautstaerke ergeben einen SerialNode (ARC:766-779).
 * $laut: null oder 0..100; $vorher: serial => bisherige Lautstaerke.
 */
function ax_sequenz_sprechen(array $ziele, array $teile, $kunde, $laut, array $vorher)
{
    $schritte = array();
    if ($laut !== null) {
        $k = array();
        foreach ($ziele as $g) { $k[] = ax_knoten('Alexa.DeviceControls.Volume', $g, $kunde, array('value' => (string) $laut)); }
        $schritte[] = ax_parallel($k);
    }
    foreach ($teile as $t) {
        $k = array();
        foreach ($ziele as $g) { $k[] = ax_knoten('Alexa.Speak', $g, $kunde, array('textToSpeak' => $t)); }
        $schritte[] = ax_parallel($k);
    }
    if ($laut !== null) {
        $k = array();
        foreach ($ziele as $g) {
            if (isset($vorher[$g['serial']]) && $vorher[$g['serial']] >= 0) {
                $k[] = ax_knoten('Alexa.DeviceControls.Volume', $g, $kunde, array('value' => (string) $vorher[$g['serial']]));
            }
        }
        if ($k) { $schritte[] = ax_parallel($k); }
    }
    if (count($schritte) === 1) { return ax_sequenz($schritte[0]); }
    return ax_sequenz(array('@type' => 'com.amazon.alexa.behaviors.model.SerialNode', 'nodesToExecute' => $schritte));
}

/** Ankuendigen [ungemessen]: ein AlexaAnnouncement-Knoten fuer alle Geraete. */
function ax_sequenz_ankuendigen(array $ziele, $text, $titel, $kunde)
{
    $geraete = array();
    foreach ($ziele as $g) { $geraete[] = array('deviceSerialNumber' => $g['serial'], 'deviceTypeId' => $g['typ']); }
    return ax_sequenz(array(
        '@type' => 'com.amazon.alexa.behaviors.model.OpaquePayloadOperationNode',
        'type' => 'AlexaAnnouncement',
        'operationPayload' => array(
            'expireAfter' => 'PT5S',
            'content' => array(array('locale' => AX_LOCALE,
                'display' => array('title' => $titel, 'body' => $text),
                'speak' => array('type' => 'text', 'value' => $text))),
            'target' => array('customerId' => $kunde, 'devices' => $geraete),
            'skillId' => 'amzn1.ask.1p.routines.messaging',
        ),
    ));
}

/** Lautstaerke: ein ParallelNode (ARC:431-439). */
function ax_sequenz_lautstaerke(array $ziele, $wert, $kunde)
{
    $k = array();
    foreach ($ziele as $g) { $k[] = ax_knoten('Alexa.DeviceControls.Volume', $g, $kunde, array('value' => (string) $wert)); }
    return ax_sequenz(ax_parallel($k));
}

/** Den Rumpf fuer /api/behaviors/preview bauen. UTF-8 bleibt Byte fuer Byte. */
function ax_preview_rumpf($behavior, $sequenz_json)
{
    return json_encode(array('behaviorId' => $behavior, 'sequenceJson' => $sequenz_json, 'status' => 'ENABLED'),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ==================================================================
 * Sperre und Bremse
 * ================================================================== */

/**
 * Die Amazon-Sperre (flock, Regeln/03 "Sperre um den Geraetezugriff"): reiht
 * gleichzeitige Aufrufe ein. Wartet hoechstens $warte Sekunden.
 */
function ax_sperre($warte = AX_SPERRE_WARTE_S)
{
    $p = ax_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $fh = @fopen($p['datadir'] . '/amazon.lock', 'c');
    if ($fh === false) { return null; }
    $ende = microtime(true) + $warte;
    do {
        if (flock($fh, LOCK_EX | LOCK_NB)) { return $fh; }
        usleep(100000);
    } while (microtime(true) < $ende);
    fclose($fh);
    return null;
}

/** Monotone Sekunden (PHP >= 7.3 hrtime), sonst Wanduhr. */
function ax_mono()
{
    return function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true);
}

function ax_sperre_frei($fh)
{
    if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
}

function ax_bremse_lesen()
{
    $p = ax_paths();
    $b = ax_json_lesen($p['datadir'] . '/bremse.json');
    if (!is_array($b)) { $b = array(); }
    foreach (array('fenster', 'abstand', 'stunde', 'musik_stunde') as $k) {
        if (!isset($b[$k]) || !is_array($b[$k])) { $b[$k] = array(); }
    }
    if (!isset($b['letzter_preview'])) { $b['letzter_preview'] = 0; }
    $jetzt = time();
    $b['stunde'] = array_values(array_filter($b['stunde'], function ($t) use ($jetzt) {
        return is_numeric($t) && $jetzt - (int) $t < 3600 && (int) $t <= $jetzt;
    }));
    $b['musik_stunde'] = array_values(array_filter($b['musik_stunde'], function ($t) use ($jetzt) {
        return is_numeric($t) && $jetzt - (int) $t < 3600 && (int) $t <= $jetzt;
    }));
    return $b;
}

function ax_bremse_schreiben(array $b)
{
    $p = ax_paths();
    $jetzt = time();
    foreach (array('fenster', 'abstand') as $k) {
        foreach ($b[$k] as $sch => $e) {
            $t = is_array($e) && isset($e['t']) ? (int) $e['t'] : 0;
            if ($jetzt - $t > 3600) { unset($b[$k][$sch]); }
        }
    }
    return ax_write_json($p['datadir'] . '/bremse.json', $b, 0600);
}

/**
 * Fehlversuche mit dem Token je Absender (R05:173): nach AX_FEHLVERSUCHE
 * in einer Stunde ist der Absender eine Stunde gesperrt. Geschrieben wird
 * NUR in einen schon vorhandenen Datenordner - der unangemeldete Endpunkt
 * legt keinen an.
 */
function ax_fehlversuch($adresse, $merken)
{
    $p = ax_paths();
    $f = $p['datadir'] . '/fehlversuche.json';
    if (!is_dir($p['datadir'])) { return false; }
    $d = ax_json_lesen($f);
    if (!is_array($d)) { $d = array(); }
    $jetzt = time();
    $sch = preg_replace('/[^0-9a-fA-F:.]/', '', (string) $adresse);
    $liste = (isset($d[$sch]) && is_array($d[$sch])) ? $d[$sch] : array();
    $liste = array_values(array_filter($liste, function ($t) use ($jetzt) { return is_int($t) && $jetzt - $t < 3600; }));
    $gesperrt = count($liste) >= AX_FEHLVERSUCHE;
    if ($merken && !$gesperrt) {
        $liste[] = $jetzt;
        $d[$sch] = $liste;
        foreach ($d as $k => $l) { if (!is_array($l) || !$l) { unset($d[$k]); } }
        $alt = ax_nur_lesen();
        ax_nur_lesen(false);
        ax_write_json($f, $d, 0600);
        ax_nur_lesen($alt);
    }
    return $gesperrt;
}

/** Liegt jetzt die Ruhezeit? (von..bis, auch ueber Mitternacht) */
function ax_ruhezeit(array $cfg, $jetzt = null)
{
    if (empty($cfg['ruhe_ein'])) { return false; }
    $jetzt = ($jetzt === null) ? time() : $jetzt;
    $m = (int) date('G', $jetzt) * 60 + (int) date('i', $jetzt);
    list($vh, $vm) = explode(':', $cfg['ruhe_von']);
    list($bh, $bm) = explode(':', $cfg['ruhe_bis']);
    $v = (int) $vh * 60 + (int) $vm;
    $b = (int) $bh * 60 + (int) $bm;
    if ($v === $b) { return false; }
    return ($v < $b) ? ($m >= $v && $m < $b) : ($m >= $v || $m < $b);
}

/* ==================================================================
 * Sperre aus Loxone (K1, Entscheidung 29; ab Werk aus)
 * ================================================================== */

/**
 * Der zuletzt gesetzte Zustand (data/loxsperre.json). Er liegt im
 * Datenordner und ist nach einem Update weg; dann gilt "offen" (Frage 24),
 * und der Reiter Test zeigt es an. Rueckgabe: bekannt, gesperrt (0/1),
 * seit (letzter Wechsel), zuletzt (letztes Setzen), quelle.
 */
function ax_loxsperre_lesen()
{
    $p = ax_paths();
    $d = ax_json_lesen($p['datadir'] . '/loxsperre.json');
    $aus = array('bekannt' => false, 'gesperrt' => 0, 'seit' => 0, 'zuletzt' => 0, 'quelle' => '');
    if (is_array($d) && isset($d['gesperrt']) && ($d['gesperrt'] === 0 || $d['gesperrt'] === 1)) {
        $aus['bekannt'] = true;
        $aus['gesperrt'] = $d['gesperrt'];
        $aus['seit'] = isset($d['seit']) ? (int) $d['seit'] : 0;
        $aus['zuletzt'] = isset($d['zuletzt']) ? (int) $d['zuletzt'] : 0;
        $aus['quelle'] = (isset($d['quelle']) && is_string($d['quelle'])) ? (string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', substr($d['quelle'], 0, 60)) : '';
    }
    return $aus;
}

/** Wirkt die Sperre jetzt? Nur mit Haken und einem gesetzten Wert 1. */
function ax_loxsperre_wirkt(array $cfg)
{
    if (empty($cfg['sperre_ein'])) { return false; }
    $l = ax_loxsperre_lesen();
    return $l['bekannt'] && $l['gesperrt'] === 1;
}

/**
 * Die Sperre setzen (Endpunkt mit Aktionstoken, MQTT-Befehl). $wert '0' oder
 * '1' - alles andere wird abgewiesen, nie umgedeutet. Kein Kontakt zu
 * Amazon. Rueckgabe array(http, felder).
 */
function ax_loxsperre_setzen($wert, $quelle, $absender = '')
{
    $cfg = ax_config();
    $f = array('OK' => 0);
    $ergebnis = function ($http, array $felder) use ($quelle, $absender) {
        ax_absender_merken('sperre', $quelle, $absender, $felder);
        return array($http, $felder);
    };
    if ($absender !== '' && !ax_absender_ok($absender)) { $f['GRUND'] = 'ABSENDER'; return $ergebnis(400, $f); }
    if (empty($cfg['aktiv'])) { $f['GRUND'] = 'PLUGIN_AUS'; return $ergebnis(409, $f); }
    if (empty($cfg['sperre_ein'])) { $f['GRUND'] = 'SPERRE_AUS'; return $ergebnis(409, $f); }
    if (!is_string($wert) || ($wert !== '0' && $wert !== '1')) { $f['GRUND'] = 'WERT_SPERRE'; return $ergebnis(400, $f); }
    $p = ax_paths();
    $alt = ax_loxsperre_lesen();
    $neu = (int) $wert;
    $gleich = $alt['bekannt'] && $alt['gesperrt'] === $neu;
    $wer = ($quelle === 'http' && isset($_SERVER['REMOTE_ADDR']))
         ? 'http:' . preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : (string) $quelle;
    $d = array('gesperrt' => $neu, 'seit' => $gleich ? $alt['seit'] : time(), 'zuletzt' => time(), 'quelle' => $wer);
    if (!ax_write_json($p['datadir'] . '/loxsperre.json', $d, 0644)) { $f['GRUND'] = 'SPEICHERN'; return $ergebnis(503, $f); }
    if (!$gleich) {
        ax_log('INFO', 'Sperre aus Loxone: ' . ($neu ? 'gesperrt' : 'offen') . ' (von ' . $wer . ').');
    }
    return $ergebnis(200, array('OK' => 1, 'GESPERRT' => $neu, 'UNVERAENDERT' => $gleich ? 1 : 0));
}

/* ==================================================================
 * Absenderuebersicht (K3): wer hat wie oft etwas ausgeloest - nur Zaehler
 * ================================================================== */

/** Der freiwillige Parameter absender (Name des aufrufenden Plugins). */
function ax_absender_ok($s)
{
    return is_string($s) && preg_match('/^[a-z0-9_\-]{1,32}\z/', $s) === 1;
}

/**
 * Einen Aufruf zaehlen: je Weg, Adresse und Absender erster und letzter
 * Aufruf, heute, gesamt, gesendet und die uebrigen nach Grund. Nie ein Text,
 * nie ein Token. Unter einer eigenen kurzen Sperre (2 s); gelingt sie nicht,
 * bleibt dieser eine Aufruf ungezaehlt - die Uebersicht ist eine Hilfe, kein
 * Beleg. Hoechstens 50 Zeilen; die aelteste faellt heraus.
 */
function ax_absender_merken($aktion, $quelle, $absender, array $felder)
{
    if (ax_nur_lesen()) { return false; }
    $p = ax_paths();
    if (!is_dir($p['datadir'])) { return false; }
    $fh = @fopen($p['datadir'] . '/absender.lock', 'c');
    if ($fh === false) { return false; }
    $frist = microtime(true) + 2;
    $gehalten = false;
    do {
        if (flock($fh, LOCK_EX | LOCK_NB)) { $gehalten = true; break; }
        usleep(50000);
    } while (microtime(true) < $frist);
    if (!$gehalten) { fclose($fh); return false; }
    $datei = $p['datadir'] . '/absender.json';
    $d = ax_json_lesen($datei);
    if (!is_array($d)) { $d = array(); }
    $weg = in_array($quelle, array('http', 'mqtt', 'oberflaeche'), true) ? $quelle : 'andere';
    $adr = ($weg === 'http' && isset($_SERVER['REMOTE_ADDR'])) ? (string) preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '-';
    $abs = ax_absender_ok($absender) ? $absender : '-';
    $k = $weg . '|' . $adr . '|' . $abs;
    $jetzt = time();
    $heute = date('Y-m-d', $jetzt);
    $e = (isset($d[$k]) && is_array($d[$k])) ? $d[$k] : array();
    $e += array('weg' => $weg, 'adresse' => $adr, 'absender' => $abs, 'erste' => $jetzt, 'letzte' => $jetzt,
                'gesamt' => 0, 'tag' => $heute, 'heute' => 0, 'gesendet' => 0, 'aktionen' => array(), 'gruende' => array());
    if (!is_array($e['aktionen'])) { $e['aktionen'] = array(); }
    if (!is_array($e['gruende'])) { $e['gruende'] = array(); }
    if ($e['tag'] !== $heute) { $e['tag'] = $heute; $e['heute'] = 0; }
    $e['letzte'] = $jetzt;
    $e['gesamt'] = (int) $e['gesamt'] + 1;
    $e['heute'] = (int) $e['heute'] + 1;
    $a = (string) preg_replace('/[^a-z_]/', '', (string) $aktion);
    $e['aktionen'][$a] = (isset($e['aktionen'][$a]) ? (int) $e['aktionen'][$a] : 0) + 1;
    $gesendet = !empty($felder['OK']) && empty($felder['UEBERSPRUNGEN'])
        && !(isset($felder['GRUND']) && $felder['GRUND'] === 'UNVERAENDERT');
    if ($gesendet) {
        $e['gesendet'] = (int) $e['gesendet'] + 1;
    } else {
        $g = isset($felder['GRUND']) ? (string) preg_replace('/[^A-Z0-9_]/', '', (string) $felder['GRUND']) : '';
        if ($g === '') { $g = 'OHNE_GRUND'; }
        $e['gruende'][$g] = (isset($e['gruende'][$g]) ? (int) $e['gruende'][$g] : 0) + 1;
    }
    $d[$k] = $e;
    if (count($d) > 50) {
        uasort($d, function ($x, $y) {
            return (int) (is_array($y) && isset($y['letzte']) ? $y['letzte'] : 0) - (int) (is_array($x) && isset($x['letzte']) ? $x['letzte'] : 0);
        });
        $d = array_slice($d, 0, 50, true);
    }
    $ok = ax_write_json($datei, $d, 0600);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/** Die Uebersicht fuer den Reiter Test, neueste zuerst; nur Zaehler und Namen. */
function ax_absender_lesen()
{
    $p = ax_paths();
    $d = ax_json_lesen($p['datadir'] . '/absender.json');
    $aus = array();
    if (!is_array($d)) { return $aus; }
    foreach ($d as $e) {
        if (!is_array($e) || !isset($e['weg'], $e['adresse'], $e['absender'], $e['letzte'])) { continue; }
        $z = array('weg' => (string) preg_replace('/[^a-z]/', '', (string) $e['weg']),
                   'adresse' => (string) preg_replace('/[^0-9a-fA-F:.\-]/', '', (string) $e['adresse']),
                   'absender' => (string) preg_replace('/[^a-z0-9_\-]/', '', (string) $e['absender']),
                   'erste' => isset($e['erste']) ? (int) $e['erste'] : 0, 'letzte' => (int) $e['letzte'],
                   'heute' => (isset($e['tag']) && $e['tag'] === date('Y-m-d')) ? (int) $e['heute'] : 0,
                   'gesamt' => isset($e['gesamt']) ? (int) $e['gesamt'] : 0,
                   'gesendet' => isset($e['gesendet']) ? (int) $e['gesendet'] : 0,
                   'aktionen' => array(), 'gruende' => array());
        foreach (array('aktionen', 'gruende') as $feld) {
            if (isset($e[$feld]) && is_array($e[$feld])) {
                foreach ($e[$feld] as $k => $n) {
                    if (preg_match('/^[A-Za-z0-9_]{1,40}\z/', (string) $k)) { $z[$feld][(string) $k] = (int) $n; }
                }
            }
        }
        $aus[] = $z;
    }
    usort($aus, function ($x, $y) { return $y['letzte'] - $x['letzte']; });
    return $aus;
}

/* ==================================================================
 * Routinen bei Amazon - eine Abfrage fuer Start (aktion=routine) und
 * Anzeige (R3, Reiter Geraete)
 * ================================================================== */

/**
 * Die Routinen des Kontos (/api/behaviors/v2/automations?limit=200). Laeuft
 * innerhalb der Amazon-Sperre. Rueckgabe array(ok, grund, liste) - je
 * Routine id, name, ausloeser (Sprachausloeser) und sequence. Nach aussen
 * (Reiter Geraete) gehen nur Name und Ausloeser.
 */
function ax_amazon_routinen()
{
    list($ok, $g, $r) = ax_alexa('GET', '/api/behaviors/v2/automations?limit=200');
    if (!$ok) { return array(false, $g, null); }
    $liste = json_decode($r['rumpf'], true);
    if (!is_array($liste)) { return array(false, 'AMAZON_UNERWARTET', null); }
    $aus = array();
    foreach ($liste as $auto) {
        if (!is_array($auto) || !isset($auto['automationId'], $auto['sequence']) || !is_string($auto['automationId'])) { continue; }
        $sprach = array();
        if (isset($auto['triggers']) && is_array($auto['triggers'])) {
            foreach ($auto['triggers'] as $tr) {
                if (is_array($tr) && isset($tr['payload']['utterance']) && is_string($tr['payload']['utterance'])) {
                    $sprach[] = $tr['payload']['utterance'];
                }
            }
        }
        $aus[] = array('id' => $auto['automationId'], 'name' => (isset($auto['name']) && is_string($auto['name'])) ? $auto['name'] : '',
                       'ausloeser' => $sprach, 'sequence' => $auto['sequence']);
    }
    return array(true, '', $aus);
}

/* ==================================================================
 * Musik-Probe (Stufe 3, Messplan Teil B) - [ungemessen], ab Werk aus
 * ================================================================== */

/** Anbieter => musicProviderId, wie die Alexa-App sie schickt [ungemessen]. */
function ax_musik_anbieter()
{
    return array('tunein' => 'TUNEIN', 'amazon' => 'AMAZON_MUSIC');
}

/**
 * Ein Sendername (Senderliste und Parameter sender): 1 bis 100 Zeichen
 * UTF-8, ohne Steuerzeichen, ohne | (Trenner der Senderliste), ohne
 * Leerraum am Rand. Rueckgabe '' = in Ordnung, sonst die Kennung.
 */
function ax_sender_grund($s)
{
    if (!is_string($s) || $s === '' || preg_match('//u', $s) !== 1 || preg_match('/[\x00-\x1F\x7F|]/', $s)
        || trim($s) !== $s || ax_zeichen($s) > AX_SENDER_MAX) {
        return 'SENDER';
    }
    return '';
}

/**
 * Den Sender fuer musik_probe waehlen: Hauptweg nr=<1..50> aus der
 * Senderliste (Entscheidung 29), Zusatz sender=<Name> mit anbieter (ab Werk
 * tunein). Beides zugleich oder keines wird abgewiesen; ein anbieter neben
 * nr darf der Liste nicht widersprechen.
 * Rueckgabe array(http, grund, name, anbieter, nr).
 */
function ax_musik_sender_waehlen(array $par, array $cfg)
{
    $hat_nr = isset($par['nr']) && $par['nr'] !== '';
    $hat_name = isset($par['sender']) && $par['sender'] !== '';
    if ($hat_nr && $hat_name) { return array(400, 'NR_UND_SENDER', '', '', ''); }
    if (!$hat_nr && !$hat_name) { return array(400, 'SENDER_FEHLT', '', '', ''); }
    $anb = isset($par['anbieter']) ? $par['anbieter'] : '';
    $liste = ax_musik_anbieter();
    if ($anb !== '' && !isset($liste[$anb])) { return array(400, 'ANBIETER', '', '', ''); }
    if ($hat_nr) {
        if (!preg_match('/^[1-9][0-9]?\z/', $par['nr']) || (int) $par['nr'] > AX_MUSIK_NR_MAX) { return array(400, 'NR', '', '', ''); }
        foreach ($cfg['musik_sender'] as $z) {
            if ($z['nr'] === (int) $par['nr']) {
                if ($anb !== '' && $anb !== $z['anbieter']) { return array(400, 'ANBIETER', '', '', (string) $z['nr']); }
                return array(200, '', $z['name'], $z['anbieter'], (string) $z['nr']);
            }
        }
        return array(404, 'SENDER_UNBEKANNT', '', '', (string) (int) $par['nr']);
    }
    $s = trim($par['sender']);
    if (ax_sender_grund($s) !== '') { return array(400, 'SENDER', '', '', ''); }
    return array(200, '', $s, $anb !== '' ? $anb : 'tunein', '');
}

/**
 * Das Ziel der Musik: genau ein Geraet oder eine Amazon-Gruppe (Familie WHA,
 * Mehrraum-Musikgruppe der Alexa-App). Die Gruppe wird NICHT in Mitglieder
 * aufgeloest, damit alle synchron spielen [ungemessen]. Keine Kommaliste,
 * nicht "alle", keine eigene Gruppe (sie waere nicht synchron).
 * Rueckgabe array(http, grund, ziel, name).
 */
function ax_musik_ziel($param, array $cfg, array $st)
{
    $roh = trim((string) $param);
    if ($roh === '') { return array(400, 'GERAET', null, ''); }
    if (strpos($roh, ',') !== false || strtolower($roh) === 'alle') { return array(400, 'EIN_ZIEL', null, ''); }
    $nach_normal = array();
    foreach ($st['liste'] as $g) { $nach_normal[$g['normal']] = $g; }
    if (stripos($roh, 'gruppe:') === 0) {
        $n = ax_name_normal(substr($roh, 7));
        foreach ($cfg['gruppen'] as $z) {
            if ($z['name'] === $n) { return array(400, 'EIN_ZIEL', null, 'gruppe:' . $n); }
        }
        if (!isset($nach_normal[$n]) || $nach_normal[$n]['familie'] !== 'WHA') { return array(404, 'GRUPPE_UNBEKANNT', null, $n); }
    } else {
        $n = ax_name_normal($roh);
        if (!isset($nach_normal[$n])) { return array(404, ax_geraet_fehlt_grund($n, $st), null, $n); }
    }
    $g = $nach_normal[$n];
    if (empty($g['online'])) { return array(503, 'GERAETE_OFFLINE', null, $n); }
    return array(200, '', $g, $n);
}

/** Musik abspielen [ungemessen]: ein Knoten Alexa.Music.PlaySearchPhrase. */
function ax_sequenz_musik(array $ziel, $phrase, $bereinigt, $anbieter_id, $kunde)
{
    return ax_sequenz(ax_knoten('Alexa.Music.PlaySearchPhrase', $ziel, $kunde, array(
        'searchPhrase' => $phrase, 'sanitizedSearchPhrase' => $bereinigt, 'musicProviderId' => $anbieter_id)));
}

/** Musik anhalten [ungemessen]: ein Knoten Alexa.DeviceControls.Stop fuer das Ziel. */
function ax_sequenz_musik_stopp(array $ziel, $kunde)
{
    return ax_sequenz(array(
        '@type' => 'com.amazon.alexa.behaviors.model.OpaquePayloadOperationNode',
        'type' => 'Alexa.DeviceControls.Stop',
        'operationPayload' => array('customerId' => $kunde, 'locale' => AX_LOCALE, 'isAssociatedDevice' => false,
            'devices' => array(array('deviceSerialNumber' => $ziel['serial'], 'deviceType' => $ziel['typ']))),
    ));
}

/** Eigene Bereinigung der Suchphrase, wenn Amazons Pruefung keine liefert. */
function ax_suchphrase_eigen($s)
{
    $t = function_exists('mb_strtolower') ? mb_strtolower((string) $s, 'UTF-8') : strtolower((string) $s);
    $t = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', (string) $t);
    return trim((string) preg_replace('/\s+/u', ' ', (string) $t));
}

/**
 * Die Suchphrase von Amazon pruefen lassen (/api/behaviors/operation/validate,
 * wie die Alexa-App) [ungemessen]. Liefert Amazon keine bereinigte Phrase,
 * gilt die eigene Bereinigung - die Antwort sagt mit SUCHE=AMAZON|EIGEN,
 * welche galt. Rueckgabe array(bereinigt, herkunft).
 */
function ax_suchphrase_pruefen(array $ziel, $phrase, $anbieter_id, $kunde)
{
    $op = array('type' => 'Alexa.Music.PlaySearchPhrase', 'operationPayload' => json_encode(array(
        'deviceType' => $ziel['typ'], 'deviceSerialNumber' => $ziel['serial'], 'customerId' => $kunde, 'locale' => AX_LOCALE,
        'musicProviderId' => $anbieter_id, 'searchPhrase' => $phrase), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $rumpf = json_encode($op, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rumpf !== false && $op['operationPayload'] !== false) {
        list($ok, , $r) = ax_alexa('POST', '/api/behaviors/operation/validate', $rumpf);
        if ($ok) {
            $j = json_decode($r['rumpf'], true);
            $pl = (is_array($j) && isset($j['operationPayload'])) ? $j['operationPayload'] : null;
            if (is_string($pl)) { $pl = json_decode($pl, true); }
            if (is_array($pl) && isset($pl['sanitizedSearchPhrase']) && is_string($pl['sanitizedSearchPhrase'])
                && trim($pl['sanitizedSearchPhrase']) !== '' && strlen($pl['sanitizedSearchPhrase']) <= 400
                && preg_match('//u', $pl['sanitizedSearchPhrase']) === 1) {
                return array($pl['sanitizedSearchPhrase'], 'AMAZON');
            }
        }
    }
    return array(ax_suchphrase_eigen($phrase), 'EIGEN');
}

/* ==================================================================
 * Radio je Zone (Stufe 3, Entscheidung 32; ab Werk aus). Gebaut auf dem
 * direkten Musikbefehl der Musik-Probe (am Geraet mit einem Echo belegt:
 * Ton nach etwa 3 s). Mehrere Zonen gleichzeitig sind nur an der Attrappe
 * geprueft [ungemessen am Geraet - im Haus gibt es ein sprechfaehiges Echo].
 * ================================================================== */

/** Form eines Zonenziels: Normalname, amazon:<name> (Amazon-Gruppe) oder gruppe:<name> (eigene Gruppe). */
function ax_radio_ziel_form($s)
{
    if (!is_string($s) || !preg_match('/^(?:amazon:|gruppe:)?([a-z0-9_]{1,40})\z/', $s, $m)) { return false; }
    return !in_array($m[1], array('alle', 'gruppe'), true);
}

/** Zonen, die eine eigene Gruppe nennen, die es in $gruppen nicht gibt (je "3 = gruppe:oben"). */
function ax_radio_gruppen_fehlen(array $zonen, array $gruppen)
{
    $da = array();
    foreach ($gruppen as $g) { if (is_array($g) && isset($g['name']) && is_string($g['name'])) { $da[$g['name']] = 1; } }
    $fehl = array();
    foreach ($zonen as $z) {
        if (is_array($z) && isset($z['zone'], $z['ziel']) && is_string($z['ziel']) && strpos($z['ziel'], 'gruppe:') === 0
            && !isset($da[substr($z['ziel'], 7)])) {
            $fehl[] = (int) $z['zone'] . ' = ' . $z['ziel'];
        }
    }
    return $fehl;
}

/**
 * Zustand je Zone (data/radio.json, 0644, keine Geheimnisse). sender (Nummer,
 * 0 nach Stopp) und zustand (1 Start, 0 Stopp) sind das, was zuletzt
 * BESTAETIGT gesendet wurde - Amazon nahm den Befehl an; was der Echo
 * tatsaechlich spielt, sagt das nicht (Z3). Dazu je Zone der letzte Befehl
 * mit Ergebnis (Reiter Test, Z6). Liegt im Datenordner und beginnt nach
 * einem Update neu. -1 = nie.
 */
function ax_radio_lesen()
{
    $p = ax_paths();
    $d = ax_json_lesen($p['datadir'] . '/radio.json');
    $aus = array();
    if (!is_array($d)) { return $aus; }
    foreach ($d as $k => $e) {
        if (!preg_match('/^[1-9][0-9]?\z/', (string) $k) || (int) $k > AX_RADIO_ZONEN_MAX || !is_array($e)) { continue; }
        $zahl = function ($n, $v) use ($e) { return (isset($e[$n]) && is_int($e[$n])) ? $e[$n] : $v; };
        $text = function ($n, $muster) use ($e) {
            return (isset($e[$n]) && is_string($e[$n])) ? (string) preg_replace($muster, '', substr($e[$n], 0, 60)) : '';
        };
        $aus[(int) $k] = array('sender' => $zahl('sender', -1), 'zustand' => $zahl('zustand', -1), 't_sender' => $zahl('t_sender', 0),
            'ziel_s' => $text('ziel_s', '/[^a-z0-9_:]/'), 'laut' => $zahl('laut', -1), 't_laut' => $zahl('t_laut', 0),
            'ziel_l' => $text('ziel_l', '/[^a-z0-9_:]/'), 'zeit' => $zahl('zeit', 0), 'ergebnis' => $zahl('ergebnis', -1),
            'befehl' => $text('befehl', '/[^a-z0-9_ ]/'), 'grund' => $text('grund', '/[^A-Z0-9_\-]/'), 'quelle' => $text('quelle', '/[^a-z]/'));
    }
    ksort($aus);
    return $aus;
}

/** Eine Zone im Zustand nachfuehren - unter einer eigenen kurzen Sperre (2 s), wie die Absenderuebersicht. */
function ax_radio_merken($zone, array $neu)
{
    if (ax_nur_lesen()) { return false; }
    $p = ax_paths();
    if (!is_dir($p['datadir'])) { return false; }
    $fh = @fopen($p['datadir'] . '/radio.lock', 'c');
    if ($fh === false) { return false; }
    $frist = microtime(true) + 2;
    $gehalten = false;
    do {
        if (flock($fh, LOCK_EX | LOCK_NB)) { $gehalten = true; break; }
        usleep(50000);
    } while (microtime(true) < $frist);
    if (!$gehalten) { fclose($fh); return false; }
    $datei = $p['datadir'] . '/radio.json';
    $d = ax_json_lesen($datei);
    if (!is_array($d)) { $d = array(); }
    $k = (string) (int) $zone;
    $e = (isset($d[$k]) && is_array($d[$k])) ? $d[$k] : array();
    foreach ($neu as $n => $v) { $e[$n] = $v; }
    $d[$k] = $e;
    $ok = ax_write_json($datei, $d, 0644);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/**
 * Die Geraete einer Zone. Echo oder amazon:<name>: Start und Stopp gehen an
 * genau dieses Ziel (eine Amazon-Gruppe als Ganzes, wie die Musik-Probe), die
 * Lautstaerke an die Mitglieder einer Amazon-Gruppe. gruppe:<name>: jedes
 * Mitglied einzeln (nicht synchron); offline ausgelassen und gezaehlt.
 * Rueckgabe array(http, grund, geraete, offline).
 */
function ax_radio_ziel($ziel, $aktion, array $cfg, array $st)
{
    $nach_normal = array();
    foreach ($st['liste'] as $g) { $nach_normal[$g['normal']] = $g; }
    if (strpos($ziel, 'gruppe:') === 0) {
        $da = false;
        foreach ($cfg['gruppen'] as $z) { if ($z['name'] === substr($ziel, 7)) { $da = true; } }
        if (!$da) { return array(404, 'GRUPPE_UNBEKANNT', array(), 0); }
        list($h, $gr, $ziele, $off) = ax_geraete_aufloesen($ziel, $cfg, $st);
        return array($h, $gr, $ziele, $off);
    }
    $amazon = (strpos($ziel, 'amazon:') === 0);
    $n = $amazon ? substr($ziel, 7) : $ziel;
    if (!isset($nach_normal[$n])) { return array(404, $amazon ? 'GRUPPE_UNBEKANNT' : ax_geraet_fehlt_grund($n, $st), array(), 0); }
    $g = $nach_normal[$n];
    if ($amazon && $g['familie'] !== 'WHA') { return array(404, 'GRUPPE_UNBEKANNT', array(), 0); }
    if ($aktion === 'radio_laut') {
        list($h, $gr, $ziele, $off) = ax_geraete_aufloesen($n, $cfg, $st);
        return array($h, $gr, $ziele, $off);
    }
    if (empty($g['online'])) { return array(503, 'GERAETE_OFFLINE', array(), 1); }
    return array(200, '', array($g), 0);
}

/**
 * Restdauer der Musik-Pause nach AMAZON_RATE in Sekunden, 0 = keine (Radio je
 * Zone, Z4; seit alexa4 N3 auch die Musik-Probe - dieselbe Pause, derselbe
 * Schluessel radio_pause_bis in bremse.json). Steht sie weiter in der Zukunft
 * als die Pause selbst, war es ein Uhrsprung: dann keine Pause.
 */
function ax_musik_pause_rest(array $b)
{
    $pause = isset($b['radio_pause_bis']) ? (int) $b['radio_pause_bis'] - time() : 0;
    if ($pause > AX_RADIO_PAUSE_S) { $pause = 0; }
    return max(0, $pause);
}

/**
 * Eine Befehlsfolge fuer Radio senden - unter der Amazon-Sperre des Aufrufers.
 * Mindestabstand zur vorigen Folge (AX_PREVIEW_ABSTAND_S), zaehlt in der
 * Musik-Stundengrenze; bei 429 KEIN zweiter Versuch, sondern die Radio-Pause
 * (Z4). Rueckgabe array(ok, grund).
 */
function ax_radio_senden(array $seq)
{
    $sj = json_encode($seq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($sj === false) { return array(false, 'TEXT'); }
    $b = ax_bremse_lesen();
    $seit = ax_mono() - (float) $b['letzter_preview'];
    if ($seit >= 0 && $seit < AX_PREVIEW_ABSTAND_S) { usleep((int) ((AX_PREVIEW_ABSTAND_S - $seit) * 1000000)); }
    $b['letzter_preview'] = ax_mono();
    $b['musik_stunde'][] = time();
    list($ok, $g) = ax_alexa('POST', '/api/behaviors/preview', ax_preview_rumpf('PREVIEW', $sj), false);
    $b['letzter_preview'] = ax_mono();
    if (!$ok && $g === 'AMAZON_RATE') {
        $b['radio_pause_bis'] = time() + AX_RADIO_PAUSE_S;
        ax_log('WARN', 'Radio: Amazon meldet zu viele Anfragen (429) - Pause ' . AX_RADIO_PAUSE_S . ' s, kein weiterer Versuch.');
    }
    ax_bremse_schreiben($b);
    return array($ok, $g);
}

/**
 * Einen Radiobefehl ausfuehren (Bauliste alexa3 Z2-Z4, Z7): radio (nr aus der
 * Senderliste), radio_stopp, radio_laut (wert 0..100) - je fuer eine Zone
 * 1..24 oder "alle". $par: zone, nr, wert, absender (Zeichenketten). Nur mit
 * Aktionstoken; die Pruefung macht der Aufrufer. $quelle: http | mqtt |
 * oberflaeche. Die Sperre aus Loxone und die Ruhezeit gelten nicht (Z7).
 * Bremsen (Z4): derselbe Sollwert in derselben Zone binnen
 * AX_RADIO_GLEICH_S -> UNVERAENDERT (X-7, verglichen mit dem zuletzt
 * bestaetigt gesendeten); die Musik-Stundengrenze gilt fuer alle Zonen
 * zusammen und fuer den GANZEN Befehl (reicht sie nicht, geht nichts
 * hinaus); die Zonen gehen nacheinander, je unter der Amazon-Sperre und
 * mindestens AX_PREVIEW_ABSTAND_S auseinander; nach einem Amazon-Fehler
 * werden die uebrigen Zonen nicht mehr versucht, nach AMAZON_RATE gilt
 * zusaetzlich die Pause AX_RADIO_PAUSE_S (429 AMAZON_PAUSE).
 * Rueckgabe array(http, felder) - RADIO;OK=..;ZONE=..;NR=..;GERAET=..
 */
function ax_radio_ausfuehren($aktion, array $par, $quelle)
{
    $t0 = microtime(true);
    // N1 (alexa4): nr=0 ist der benannte Stopp der Zone - Radiotasten in Loxone
    // senden 0, wenn keine Taste gewaehlt ist. Antwort wie radio_stopp (STOPP=1).
    $nr0 = ($aktion === 'radio' && isset($par['nr']) && $par['nr'] === '0');
    if ($nr0) { $aktion = 'radio_stopp'; }
    $cfg = ax_config();
    $zp = (isset($par['zone']) && is_string($par['zone'])) ? $par['zone'] : '';
    $f = array('OK' => 0);
    $ende = function ($http, array $felder, $versucht = false, array $namen = array()) use ($aktion, $quelle, $t0, $cfg, $par, $nr0) {
        $wer = ($quelle === 'http' && isset($_SERVER['REMOTE_ADDR']))
             ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : $quelle;
        $grund = isset($felder['GRUND']) ? (string) $felder['GRUND'] : '-';
        $zeile = strtoupper($aktion) . ' von ' . $wer . ': Zone ' . (isset($felder['ZONE']) ? $felder['ZONE'] : '-')
               . ($namen ? ' (' . implode(',', array_unique($namen)) . ')' : '')
               . (isset($felder['NR']) ? ', NR ' . $felder['NR'] : '') . (isset($felder['WERT']) ? ', WERT ' . $felder['WERT'] : '')
               . ', HTTP ' . $http . ', OK=' . (int) $felder['OK'] . ', GRUND=' . $grund
               . (isset($felder['GESENDET']) ? ', Zonen gesendet ' . $felder['GESENDET'] : '')
               . ($nr0 ? ', nr=0 als Stopp' : '')
               . ', ' . (int) round((microtime(true) - $t0) * 1000) . ' ms';
        ax_log($felder['OK'] ? 'INFO' : 'WARN', $zeile);
        if ($versucht) {
            $letzte = array('zeit' => time(), 'aktion' => $aktion, 'geraet' => $namen ? implode(',', array_unique($namen)) : '-',
                            'laenge' => 0, 'ergebnis' => (int) $felder['OK'], 'grund' => ($grund === '' ? '-' : $grund),
                            'quelle' => $quelle);
            $p = ax_paths();
            ax_write_json($p['datadir'] . '/letzte.json', $letzte, 0644);
            ax_mqtt_letzte($letzte, $cfg);
        }
        ax_absender_merken($aktion, $quelle, isset($par['absender']) ? (string) $par['absender'] : '', $felder);
        return array($http, $felder);
    };

    if (!in_array($aktion, array('radio', 'radio_stopp', 'radio_laut'), true)) { $f['GRUND'] = 'AKTION'; return $ende(400, $f); }
    if (isset($par['absender']) && !ax_absender_ok($par['absender'])) { $f['GRUND'] = 'ABSENDER'; return $ende(400, $f); }
    if (empty($cfg['aktiv'])) { $f['GRUND'] = 'PLUGIN_AUS'; return $ende(409, $f); }
    if (empty($cfg['radio_ein'])) { $f['GRUND'] = 'RADIO_AUS'; return $ende(409, $f); }
    if ($zp !== 'alle' && (!preg_match('/^[1-9][0-9]?\z/', $zp) || (int) $zp > AX_RADIO_ZONEN_MAX)) {
        $f['GRUND'] = 'ZONE';
        return $ende(400, $f);
    }
    $f['ZONE'] = $zp;

    // ---- Sender bzw. Wert: abweisen, nie zurechtbiegen ----
    $nr = 0;
    $wert = -1;
    $sender = null;
    if ($aktion === 'radio') {
        $n = (isset($par['nr']) && is_string($par['nr'])) ? $par['nr'] : '';
        if (!preg_match('/^[1-9][0-9]?\z/', $n) || (int) $n > AX_MUSIK_NR_MAX) { $f['GRUND'] = 'NR'; return $ende(400, $f); }
        $nr = (int) $n;
        $f['NR'] = $nr;
        foreach ($cfg['musik_sender'] as $z) { if ($z['nr'] === $nr) { $sender = $z; } }
        if ($sender === null) { $f['GRUND'] = 'SENDER_UNBEKANNT'; return $ende(404, $f); }
    } elseif ($aktion === 'radio_stopp') {
        $f['NR'] = 0;
    } else {
        $w = (isset($par['wert']) && is_string($par['wert'])) ? $par['wert'] : '';
        if (!preg_match('/^[0-9]{1,3}\z/', $w) || (int) $w > 100) { $f['GRUND'] = 'WERT'; return $ende(400, $f); }
        $wert = (int) $w;
        $f['WERT'] = $wert;
    }
    $zonen = array();
    foreach ($cfg['radio_zonen'] as $z) {
        if ($zp === 'alle' || $z['zone'] === (int) $zp) { $zonen[] = $z; }
    }
    if (!$zonen) { $f['GRUND'] = 'ZONE_UNBEKANNT'; return $ende(404, $f); }
    $f['GERAET'] = ($zp === 'alle') ? 'alle' : $zonen[0]['ziel'];
    $kopf = $f;
    unset($kopf['OK']);

    // ---- Anmeldung, Pause nach AMAZON_RATE ----
    if (!ax_amazon()) { $f['GRUND'] = 'ANMELDUNG'; return $ende(503, $f); }
    $bef = ax_anmeldung_befund();
    if ($bef['befund'] === 'ABGELAUFEN') { $f['GRUND'] = 'ANMELDUNG_ABGELAUFEN'; return $ende(503, $f); }
    if (!function_exists('curl_init')) { $f['GRUND'] = 'CURL_FEHLT'; return $ende(503, $f); }
    $b = ax_bremse_lesen();
    $pause = ax_musik_pause_rest($b);
    if ($pause > 0) { $f['GRUND'] = 'AMAZON_PAUSE'; $f['WARTE'] = $pause; return $ende(429, $f); }
    $st = ax_geraete();
    if (!$st) {
        $sp = ax_sperre();
        if (!$sp) { $f['GRUND'] = 'BESCHAEFTIGT'; return $ende(503, $f); }
        list($ok, $g, $st) = ax_geraete_holen();
        ax_sperre_frei($sp);
        if (!$ok) { $f['GRUND'] = $g; return $ende(503, $f); }
    }

    // ---- Plan: je Zone die Ziele und ob derselbe Sollwert schon gilt (X-7) ----
    $stand = ax_radio_lesen();
    $jetzt = time();
    $plan = array();
    $bedarf = 0;
    foreach ($zonen as $z) {
        list($h, $g, $ger, $off) = ax_radio_ziel($z['ziel'], $aktion, $cfg, $st);
        $e = isset($stand[$z['zone']]) ? $stand[$z['zone']] : null;
        $gleich = false;
        if ($h === 200 && $e) {
            if ($aktion === 'radio_laut') {
                $gleich = $e['ziel_l'] === $z['ziel'] && $e['laut'] === $wert && $jetzt >= $e['t_laut'] && $jetzt - $e['t_laut'] < AX_RADIO_GLEICH_S;
            } else {
                $frisch = $e['ziel_s'] === $z['ziel'] && $jetzt >= $e['t_sender'] && $jetzt - $e['t_sender'] < AX_RADIO_GLEICH_S;
                $gleich = $frisch && (($aktion === 'radio') ? ($e['zustand'] === 1 && $e['sender'] === $nr) : ($e['zustand'] === 0));
            }
        }
        $n = ($h !== 200 || $gleich) ? 0 : (($aktion === 'radio_laut') ? 1 : count($ger));
        $bedarf += $n;
        $plan[] = array('zone' => $z['zone'], 'ziel' => $z['ziel'], 'h' => $h, 'g' => $g, 'geraete' => $ger, 'offline' => $off, 'gleich' => $gleich);
    }
    // Die Musik-Stundengrenze gilt fuer alle Zonen zusammen und fuer den ganzen
    // Befehl: reicht sie nicht, geht nichts hinaus - nie nur ein Teil der Zonen.
    if ($bedarf > 0 && count($b['musik_stunde']) + $bedarf > (int) $cfg['musik_stundengrenze']) {
        $f['GRUND'] = 'MUSIK_STUNDENGRENZE';
        return $ende(429, $f);
    }

    // ---- Senden: Zone fuer Zone, je unter der Amazon-Sperre ----
    if (count($plan) > 1 && function_exists('set_time_limit')) { @set_time_limit(60 + 15 * count($plan)); }
    $anb = ax_musik_anbieter();
    $bereinigt = null;
    $suche = '';
    $gesendet = 0;
    $unv = 0;
    $off_z = 0;
    $fehl_z = 0;
    $offen = 0;
    $off_ger = 0;
    $abbruch = '';
    $erst = null;
    $namen = array();
    $versucht = false;
    $befehl = ($aktion === 'radio') ? 'radio ' . $nr : (($aktion === 'radio_stopp') ? 'stopp' : 'laut ' . $wert);
    foreach ($plan as $pz) {
        $merk = array('befehl' => $befehl, 'zeit' => time(), 'quelle' => $quelle);
        if ($abbruch !== '') {
            $offen++;
            ax_radio_merken($pz['zone'], $merk + array('ergebnis' => 0, 'grund' => 'NICHT_GESENDET'));
            continue;
        }
        if ($pz['h'] !== 200) {
            if ($pz['g'] === 'GERAETE_OFFLINE') { $off_z++; } else { $fehl_z++; }
            if ($erst === null) { $erst = $pz; }
            ax_radio_merken($pz['zone'], $merk + array('ergebnis' => 0, 'grund' => $pz['g']));
            continue;
        }
        if ($pz['gleich']) {
            $unv++;
            ax_radio_merken($pz['zone'], $merk + array('ergebnis' => 1, 'grund' => 'UNVERAENDERT'));
            continue;
        }
        $sp = ax_sperre();
        if (!$sp) {
            $abbruch = 'BESCHAEFTIGT';
            $offen++;
            ax_radio_merken($pz['zone'], $merk + array('ergebnis' => 0, 'grund' => 'BESCHAEFTIGT'));
            continue;
        }
        $versucht = true;
        list($ok, $g, $s) = ax_sitzung_sichern(false);
        if ($ok) {
            $folgen = array();
            if ($aktion === 'radio') {
                $id = $anb[$sender['anbieter']];
                if ($bereinigt === null) {
                    list($bereinigt, $suche) = ax_suchphrase_pruefen($pz['geraete'][0], $sender['name'], $id, $s['kunde']);
                }
                foreach ($pz['geraete'] as $gz) { $folgen[] = ax_sequenz_musik($gz, $sender['name'], $bereinigt, $id, $s['kunde']); }
            } elseif ($aktion === 'radio_stopp') {
                foreach ($pz['geraete'] as $gz) { $folgen[] = ax_sequenz_musik_stopp($gz, $s['kunde']); }
            } else {
                $folgen[] = ax_sequenz_lautstaerke($pz['geraete'], $wert, $s['kunde']);
            }
            foreach ($folgen as $seq) {
                list($ok, $g) = ax_radio_senden($seq);
                if (!$ok) { break; }
            }
        }
        ax_sperre_frei($sp);
        foreach ($pz['geraete'] as $gz) { $namen[] = $gz['normal']; }
        if (!$ok) {
            $abbruch = $g;
            $offen++;
            ax_radio_merken($pz['zone'], $merk + array('ergebnis' => 0, 'grund' => $g));
            continue;
        }
        $gesendet++;
        $off_ger += $pz['offline'];
        $neu = $merk + array('ergebnis' => 1, 'grund' => '-');
        if ($aktion === 'radio_laut') {
            $neu += array('laut' => $wert, 't_laut' => time(), 'ziel_l' => $pz['ziel']);
        } else {
            $neu += array('sender' => $nr, 'zustand' => ($aktion === 'radio') ? 1 : 0, 't_sender' => time(), 'ziel_s' => $pz['ziel']);
            // Z3: je Zone fluechtig, was zuletzt bestaetigt gesendet wurde.
            $zn = (int) $pz['zone'];
            ax_mqtt_senden(array('radio/' . $zn . '/sender' => $nr, 'radio/' . $zn . '/zustand' => ($aktion === 'radio') ? 1 : 0), $cfg);
        }
        ax_radio_merken($pz['zone'], $neu);
    }

    // ---- Antwort: ehrlich, was hinausging ----
    $anbieter = ($aktion === 'radio') ? array('ANBIETER' => $sender['anbieter']) : array();
    if ($zp !== 'alle') {
        $pz = $plan[0];
        if ($pz['h'] !== 200) {
            $f['GRUND'] = $pz['g'];
            if ($pz['g'] === 'GERAETE_OFFLINE') { $f['OFFLINE'] = $pz['offline']; }
            return $ende($pz['h'], $f);
        }
        if ($pz['gleich']) {
            return $ende(200, array('OK' => 1) + $kopf + $anbieter + ($aktion === 'radio_stopp' ? array('STOPP' => 1) : array())
                + array('UNVERAENDERT' => 1, 'GRUND' => 'UNVERAENDERT'));
        }
        if ($abbruch !== '') {
            $f['GRUND'] = $abbruch;
            if ($abbruch === 'AMAZON_RATE') { $f['WARTE'] = AX_RADIO_PAUSE_S; }
            return $ende(503, $f, $versucht, $namen);
        }
        $aus = array('OK' => 1) + $kopf + $anbieter + ($aktion === 'radio' ? array('SUCHE' => $suche) : array())
             + ($aktion === 'radio_stopp' ? array('STOPP' => 1) : array()) + array('UNVERAENDERT' => 0, 'OFFLINE' => $off_ger);
        return $ende(200, $aus, true, $namen);
    }
    $zahlen = array('ZONEN' => count($plan), 'GESENDET' => $gesendet, 'UNVERAENDERT' => $unv, 'OFFLINE' => $off_z, 'FEHLER' => $fehl_z);
    if ($abbruch !== '') {
        $aus = $f + $zahlen + array('OFFEN' => $offen, 'GRUND' => $abbruch) + ($abbruch === 'AMAZON_RATE' ? array('WARTE' => AX_RADIO_PAUSE_S) : array());
        return $ende(503, $aus, $versucht, $namen);
    }
    if ($gesendet + $unv === 0) {
        $aus = $f + $zahlen + array('GRUND' => $erst['g']);
        return $ende($erst['h'], $aus);
    }
    $aus = array('OK' => 1) + $kopf + $anbieter + ($aktion === 'radio' && $suche !== '' ? array('SUCHE' => $suche) : array())
         + ($aktion === 'radio_stopp' ? array('STOPP' => 1) : array()) + $zahlen;
    return $ende(200, $aus, $versucht, $namen);
}

/* ==================================================================
 * Die Befehle - eine Funktion fuer Endpunkt, MQTT-Befehlseingang und
 * Testknoepfe (Regeln/03: Trockenlauf und Ernstfall in derselben Funktion)
 * ================================================================== */

/**
 * Einen Befehl ausfuehren. $aktion: sprechen | ankuendigen | lautstaerke |
 * routine | musik_probe | musik_stopp. $par: geraet, text, laut, ssml, titel,
 * wert, name, dringend, nr, sender, anbieter, absender (alle als
 * Zeichenketten, bereits auf is_string geprueft). $quelle: http | mqtt |
 * oberflaeche. Die Tokenpruefung macht der Aufrufer.
 * Rueckgabe: array(http, felder) - felder beginnt mit OK.
 */
function ax_befehl_ausfuehren($aktion, array $par, $quelle)
{
    $t0 = microtime(true);
    $cfg = ax_config();
    $f = array('OK' => 0);
    $ende = function ($http, array $felder, $versucht = false, $ziele = array(), $laenge = 0) use ($aktion, $quelle, $t0, $cfg, &$par) {
        $grund = isset($felder['GRUND']) ? $felder['GRUND'] : '-';
        $namen = array();
        foreach ($ziele as $g) { $namen[] = $g['normal']; }
        $wer = ($quelle === 'http' && isset($_SERVER['REMOTE_ADDR']))
             ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : $quelle;
        $zeile = strtoupper($aktion) . ' von ' . $wer . ': ' . ($namen ? implode(',', $namen) : (isset($par['geraet']) ? ax_name_normal($par['geraet']) : '-'))
               . ', Laenge ' . (int) $laenge . ', HTTP ' . $http . ', OK=' . (int) $felder['OK'] . ', GRUND=' . $grund
               . ', ' . (int) round((microtime(true) - $t0) * 1000) . ' ms';
        if (!empty($cfg['texte_protokollieren']) && isset($par['text']) && is_string($par['text']) && $laenge > 0) {
            preg_match('/^.{0,60}/su', trim($par['text']), $mt);
            $zeile .= ', Text: ' . str_replace(array("\r", "\n"), ' ', $mt[0]);
        }
        ax_log($felder['OK'] ? 'INFO' : 'WARN', $zeile);
        if ($versucht) {
            $letzte = array('zeit' => time(), 'aktion' => $aktion, 'geraet' => $namen ? implode(',', $namen) : '-',
                            'laenge' => (int) $laenge, 'ergebnis' => (int) $felder['OK'], 'grund' => ($grund === '' ? '-' : $grund),
                            'quelle' => $quelle);
            $p = ax_paths();
            ax_write_json($p['datadir'] . '/letzte.json', $letzte, 0644);
            ax_mqtt_letzte($letzte, $cfg);
        }
        ax_absender_merken($aktion, $quelle, isset($par['absender']) ? (string) $par['absender'] : '', $felder);
        return array($http, $felder);
    };

    if (!in_array($aktion, array('sprechen', 'ankuendigen', 'lautstaerke', 'routine', 'musik_probe', 'musik_stopp'), true)) {
        $f['GRUND'] = 'AKTION';
        return $ende(400, $f);
    }
    if (isset($par['absender']) && !ax_absender_ok($par['absender'])) { $f['GRUND'] = 'ABSENDER'; return $ende(400, $f); }
    if (empty($cfg['aktiv'])) { $f['GRUND'] = 'PLUGIN_AUS'; return $ende(409, $f); }
    if ($aktion === 'ankuendigen' && empty($cfg['ankuendigen_ein'])) { $f['GRUND'] = 'ANKUENDIGEN_AUS'; return $ende(409, $f); }
    $musik = ($aktion === 'musik_probe' || $aktion === 'musik_stopp');
    if ($musik && empty($cfg['musik_ein'])) { $f['GRUND'] = 'MUSIK_AUS'; return $ende(409, $f); }

    // ---- Parameter pruefen: abweisen, nie zurechtbiegen ----
    $text = '';
    $teile = array();
    $laut = null;
    $laenge = 0;
    $sender = '';
    $anbieter = '';
    $nr = '';
    if ($aktion === 'sprechen' || $aktion === 'ankuendigen') {
        if (!isset($par['text'])) { $f['GRUND'] = 'TEXT_FEHLT'; return $ende(400, $f); }
        $ssml = isset($par['ssml']) && $par['ssml'] === '1';
        if (isset($par['ssml']) && !in_array($par['ssml'], array('0', '1'), true)) { $f['GRUND'] = 'SSML'; return $ende(400, $f); }
        if ($aktion === 'ankuendigen' && $ssml) { $f['GRUND'] = 'SSML'; return $ende(400, $f); }
        list($h, $g, $text) = ax_text_pruefen($par['text'], $ssml);
        if ($h !== 200) { $f['GRUND'] = $g; return $ende($h, $f); }
        if ($g === 'TEXT_NULL') {
            return $ende(200, array('OK' => 1, 'UEBERSPRUNGEN' => 1, 'GRUND' => 'TEXT_NULL'));
        }
        $laenge = ax_zeichen($text);
        $teile = $ssml ? array($text) : ax_text_teilen($text);
        if (isset($par['laut']) && $par['laut'] !== '') {
            if (!preg_match('/^[0-9]{1,3}\z/', $par['laut']) || (int) $par['laut'] > 100) { $f['GRUND'] = 'LAUT'; return $ende(400, $f); }
            $laut = (int) $par['laut'];
        }
        if (isset($par['dringend']) && !in_array($par['dringend'], array('0', '1'), true)) { $f['GRUND'] = 'DRINGEND'; return $ende(400, $f); }
        // K1: die Sperre aus Loxone haelt Ansagen und Ankuendigungen an; dringend=1 geht durch.
        if (ax_loxsperre_wirkt($cfg) && !(isset($par['dringend']) && $par['dringend'] === '1')) {
            return $ende(200, array('OK' => 1, 'UEBERSPRUNGEN' => 1, 'GRUND' => 'GESPERRT'));
        }
        if (ax_ruhezeit($cfg) && !(isset($par['dringend']) && $par['dringend'] === '1')) {
            return $ende(200, array('OK' => 1, 'UEBERSPRUNGEN' => 1, 'GRUND' => 'RUHEZEIT'));
        }
        if ($aktion === 'ankuendigen' && isset($par['titel'])) {
            if (!is_string($par['titel']) || strlen($par['titel']) > 80 || preg_match('/[\x00-\x1F\x7F<>]/', $par['titel'])
                || preg_match('//u', $par['titel']) !== 1) { $f['GRUND'] = 'TITEL'; return $ende(400, $f); }
        }
    } elseif ($aktion === 'lautstaerke') {
        if (!isset($par['wert']) || !preg_match('/^[0-9]{1,3}\z/', $par['wert']) || (int) $par['wert'] > 100) {
            $f['GRUND'] = 'WERT'; return $ende(400, $f);
        }
        $laut = (int) $par['wert'];
    } elseif ($musik) {
        if ($aktion === 'musik_probe') {
            list($mh, $mg, $sender, $anbieter, $nr) = ax_musik_sender_waehlen($par, $cfg);
            if ($mh !== 200) {
                $f['GRUND'] = $mg;
                if ($nr !== '') { $f['NR'] = $nr; }
                return $ende($mh, $f);
            }
        }
    } else {
        if (!isset($par['name']) || !is_string($par['name']) || trim($par['name']) === '' || strlen($par['name']) > 80
            || preg_match('/[\x00-\x1F\x7F]/', $par['name']) || preg_match('//u', $par['name']) !== 1) {
            $f['GRUND'] = 'NAME'; return $ende(400, $f);
        }
        $frei = false;
        foreach ($cfg['routinen_frei'] as $r) {
            if (strtolower(trim($r)) === strtolower(trim($par['name']))) { $frei = true; break; }
        }
        if (!$frei) { $f['GRUND'] = 'ROUTINE_NICHT_FREIGEGEBEN'; return $ende(409, $f); }
    }
    $geraet_param = (isset($par['geraet']) && $par['geraet'] !== '') ? $par['geraet'] : $cfg['standardgeraet'];
    if ($geraet_param === '') { $f['GRUND'] = 'KEIN_GERAET'; return $ende(400, $f); }
    if (strlen($geraet_param) > 400 || preg_match('/[\x00-\x1F\x7F]/', $geraet_param) || preg_match('//u', $geraet_param) !== 1) {
        $f['GRUND'] = 'GERAET'; return $ende(400, $f);
    }

    // ---- Anmeldung ----
    if (!ax_amazon()) { $f['GRUND'] = 'ANMELDUNG'; return $ende(503, $f); }
    $bef = ax_anmeldung_befund();
    if ($bef['befund'] === 'ABGELAUFEN') { $f['GRUND'] = 'ANMELDUNG_ABGELAUFEN'; return $ende(503, $f); }
    if (!function_exists('curl_init')) { $f['GRUND'] = 'CURL_FEHLT'; return $ende(503, $f); }
    // N3 (alexa4): nach AMAZON_RATE gilt die Musik-Pause auch fuer die Musik-Probe.
    if ($musik) {
        $pause = ax_musik_pause_rest(ax_bremse_lesen());
        if ($pause > 0) { $f['GRUND'] = 'AMAZON_PAUSE'; $f['WARTE'] = $pause; return $ende(429, $f); }
    }

    // ---- ab hier unter der Amazon-Sperre ----
    $sp = ax_sperre();
    if (!$sp) { $f['GRUND'] = 'BESCHAEFTIGT'; return $ende(503, $f); }
    $st = ax_geraete();
    if (!$st) {
        list($ok, $g, $st) = ax_geraete_holen();
        if (!$ok) { ax_sperre_frei($sp); $f['GRUND'] = $g; return $ende(503, $f); }
    }
    if ($musik) {
        list($h, $g, $mziel, $unbek) = ax_musik_ziel($geraet_param, $cfg, $st);
        $ziele = $mziel ? array($mziel) : array();
        $offline = ($h === 503) ? 1 : 0;
    } else {
        list($h, $g, $ziele, $offline, , $unbek) = ax_geraete_aufloesen($geraet_param, $cfg, $st);
    }
    if ($h !== 200) {
        ax_sperre_frei($sp);
        $f['GRUND'] = $g;
        if ($unbek !== '') { $f['NAME'] = $unbek; }
        if ($g === 'GERAETE_OFFLINE') { $f['OFFLINE'] = $offline; }
        return $ende($h, $f);
    }
    if ($aktion === 'routine' && count($ziele) !== 1) {
        ax_sperre_frei($sp);
        $f['GRUND'] = 'EIN_GERAET';
        return $ende(400, $f);
    }

    // ---- Bremse (Entscheidung 14 sinngemaess, Bauplan 2.6) ----
    $b = ax_bremse_lesen();
    $jetzt = time();
    if (!$musik && count($b['stunde']) >= (int) $cfg['stundengrenze']) {
        ax_sperre_frei($sp);
        $f['GRUND'] = 'STUNDENGRENZE';
        return $ende(429, $f);
    }
    // B3: Musik zaehlt getrennt von den Ansagen, mit eigener Grenze.
    if ($musik && count($b['musik_stunde']) >= (int) $cfg['musik_stundengrenze']) {
        ax_sperre_frei($sp);
        $f['GRUND'] = 'MUSIK_STUNDENGRENZE';
        return $ende(429, $f);
    }
    $hash = hash('sha256', $aktion . '|' . $text . '|' . ($laut === null ? '' : $laut) . '|' . (isset($par['name']) ? $par['name'] : '')
                 . ($musik ? '|' . $sender . '|' . $anbieter : ''));
    $unveraendert = 0;
    if ((int) $cfg['bremse_fenster_s'] > 0) {
        $rest = array();
        foreach ($ziele as $z) {
            // Musik: EIN Merker je Geraet fuer Start und Stopp - Start, Stopp,
            // Start desselben Senders wird so nicht verschluckt.
            $k = ($musik ? 'musik' : $aktion) . '|' . $z['serial'];
            $e = isset($b['fenster'][$k]) ? $b['fenster'][$k] : null;
            if (is_array($e) && isset($e['h'], $e['t']) && $e['h'] === $hash && $jetzt - (int) $e['t'] < (int) $cfg['bremse_fenster_s']) {
                $unveraendert++;
                continue;
            }
            $rest[] = $z;
        }
        if (!$rest) {
            ax_sperre_frei($sp);
            return $ende(200, array('OK' => 1, 'GERAETE' => 0, 'UNVERAENDERT' => $unveraendert, 'GRUND' => 'UNVERAENDERT'), false, $ziele, $laenge);
        }
        $ziele = $rest;
    }
    if ((int) $cfg['mindestabstand_s'] > 0 && ($aktion === 'sprechen' || $aktion === 'ankuendigen')) {
        $warte = 0;
        foreach ($ziele as $z) {
            $e = isset($b['abstand'][$z['serial']]) ? $b['abstand'][$z['serial']] : null;
            if (is_array($e) && isset($e['t'])) {
                $w = (int) $cfg['mindestabstand_s'] - ($jetzt - (int) $e['t']);
                if ($w > $warte) { $warte = $w; }
            }
        }
        if ($warte > 0) {
            ax_sperre_frei($sp);
            $f['GRUND'] = 'BREMSE';
            $f['WARTE'] = $warte;
            return $ende(429, $f);
        }
    }

    // ---- Sitzung, Kundennummer ----
    list($ok, $g, $s) = ax_sitzung_sichern(false);
    if (!$ok) { ax_sperre_frei($sp); $f['GRUND'] = $g; return $ende(503, $f, true, $ziele, $laenge); }

    // ---- Befehlsfolge bauen ----
    $behavior = 'PREVIEW';
    $zusatz = array();
    if ($aktion === 'sprechen') {
        $vorher = array();
        if ($laut !== null) {
            list($okv, , $rv) = ax_alexa('GET', '/api/devices/deviceType/dsn/audio/v1/allDeviceVolumes');
            if ($okv) {
                $jv = json_decode($rv['rumpf'], true);
                if (is_array($jv) && isset($jv['volumes']) && is_array($jv['volumes'])) {
                    foreach ($jv['volumes'] as $v) {
                        if (is_array($v) && isset($v['dsn'], $v['speakerVolume']) && is_numeric($v['speakerVolume'])) {
                            $vorher[(string) $v['dsn']] = max(0, min(100, (int) $v['speakerVolume']));
                        }
                    }
                }
            }
            $zurueck = 0;
            foreach ($ziele as $z) { if (isset($vorher[$z['serial']])) { $zurueck++; } }
            $zusatz['LAUT_ZURUECK'] = $zurueck;
        }
        $seq = ax_sequenz_sprechen($ziele, $teile, $s['kunde'], $laut, $vorher);
        $zusatz = array('TEILE' => count($teile)) + $zusatz;
    } elseif ($aktion === 'ankuendigen') {
        $titel = (isset($par['titel']) && trim($par['titel']) !== '') ? trim($par['titel']) : 'Loxone';
        $seq = ax_sequenz_ankuendigen($ziele, $text, $titel, $s['kunde']);
    } elseif ($aktion === 'lautstaerke') {
        $seq = ax_sequenz_lautstaerke($ziele, $laut, $s['kunde']);
        $zusatz['WERT'] = $laut;
    } elseif ($aktion === 'musik_probe') {
        $ids = ax_musik_anbieter();
        list($bereinigt, $suche) = ax_suchphrase_pruefen($ziele[0], $sender, $ids[$anbieter], $s['kunde']);
        $seq = ax_sequenz_musik($ziele[0], $sender, $bereinigt, $ids[$anbieter], $s['kunde']);
        $zusatz = array('GERAET' => $ziele[0]['normal'], 'ANBIETER' => $anbieter) + ($nr !== '' ? array('NR' => $nr) : array())
                + array('SUCHE' => $suche);
    } elseif ($aktion === 'musik_stopp') {
        $seq = ax_sequenz_musik_stopp($ziele[0], $s['kunde']);
        $zusatz = array('GERAET' => $ziele[0]['normal'], 'STOPP' => 1);
    } else {
        // Dieselbe Abfrage wie "Routinen bei Amazon anzeigen" (R3).
        list($oka, $ga, $liste) = ax_amazon_routinen();
        if (!$oka) { ax_sperre_frei($sp); $f['GRUND'] = $ga; return $ende(503, $f, true, $ziele); }
        $treffer = null;
        $gesucht = strtolower(trim($par['name']));
        foreach ($liste as $auto) {
            $sprach = false;
            foreach ($auto['ausloeser'] as $u) {
                if (strtolower(trim($u)) === $gesucht) { $sprach = true; }
            }
            if ($sprach || strtolower(trim($auto['name'])) === $gesucht) { $treffer = $auto; break; }
        }
        if (!$treffer || !preg_match('/^[A-Za-z0-9._:\-]{1,200}\z/', $treffer['id'])) {
            ax_sperre_frei($sp);
            $f['GRUND'] = 'ROUTINE_UNBEKANNT';
            return $ende(404, $f, true, $ziele);
        }
        $z = $ziele[0];
        $sj = is_string($treffer['sequence']) ? $treffer['sequence']
            : json_encode($treffer['sequence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sj = str_replace(array('ALEXA_CURRENT_DEVICE_TYPE', 'ALEXA_CURRENT_DSN', 'ALEXA_CUSTOMER_ID'),
                          array($z['typ'], $z['serial'], $s['kunde']), (string) $sj);
        $behavior = $treffer['id'];
    }
    if ($aktion !== 'routine') {
        $sj = json_encode($seq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($sj === false) { ax_sperre_frei($sp); $f['GRUND'] = 'TEXT'; return $ende(400, $f); }
    }

    // ---- Abstand zu Amazon (mindestens 1 s zwischen zwei Folgen) ----
    // Gemessen mit der monotonen Uhr (hrtime): ein Sprung der Wanduhr (NTP,
    // WSL-Zeitabgleich) darf den Abstand nicht verkuerzen. Nach einem
    // Neustart ist der gemerkte Wert groesser als jetzt - dann kein Warten.
    $seit = ax_mono() - (float) $b['letzter_preview'];
    if ($seit >= 0 && $seit < AX_PREVIEW_ABSTAND_S) { usleep((int) ((AX_PREVIEW_ABSTAND_S - $seit) * 1000000)); }
    $b['letzter_preview'] = ax_mono();
    if ($musik) { $b['musik_stunde'][] = time(); } else { $b['stunde'][] = time(); }
    // N3 (alexa4): die Musik-Probe wie Radio - bei 429 kein zweiter Versuch, danach die Musik-Pause.
    list($ok, $g, $r) = ax_alexa('POST', '/api/behaviors/preview', ax_preview_rumpf($behavior, $sj), !$musik);
    $b['letzter_preview'] = ax_mono();
    if ($musik && !$ok && $g === 'AMAZON_RATE') {
        $b['radio_pause_bis'] = time() + AX_RADIO_PAUSE_S;
        ax_log('WARN', 'Musik-Probe: Amazon meldet zu viele Anfragen (429) - Pause ' . AX_RADIO_PAUSE_S . ' s, kein weiterer Versuch.');
    }
    if ($ok) {
        foreach ($ziele as $z) {
            $b['fenster'][($musik ? 'musik' : $aktion) . '|' . $z['serial']] = array('h' => $hash, 't' => time());
            if ($aktion === 'sprechen' || $aktion === 'ankuendigen') { $b['abstand'][$z['serial']] = array('t' => time()); }
        }
    }
    ax_bremse_schreiben($b);
    ax_sperre_frei($sp);
    if (!$ok) {
        $f['GRUND'] = $g;
        if ($musik && $g === 'AMAZON_RATE') { $f['WARTE'] = AX_RADIO_PAUSE_S; }
        return $ende(503, $f, true, $ziele, $laenge);
    }
    // Musik: MUSIK;OK=1;GERAET=...;ANBIETER=... (Bauliste B1)
    $aus = $musik ? array('OK' => 1) + $zusatz + array('UNVERAENDERT' => $unveraendert)
         : array('OK' => 1, 'GERAETE' => count($ziele)) + $zusatz
         + array('UNVERAENDERT' => $unveraendert, 'OFFLINE' => $offline);
    return $ende(200, $aus, true, $ziele, $laenge);
}

/** Eine Antwortzeile: KOPF;FELD=WERT;... - Werte ohne ; und = und Umbruch. */
function ax_zeile($kopf, array $felder)
{
    $z = $kopf;
    foreach ($felder as $k => $v) {
        $z .= ';' . $k . '=' . preg_replace('/[;=\r\n]/', '_', (string) $v);
    }
    return $z;
}

/* ==================================================================
 * Status fuer Loxone (eine Feldliste fuer Zeile, Vorlage und Oberflaeche)
 * ================================================================== */

/** Feld => array(analog, min, max, einheit, nachkomma). */
function ax_status_felder()
{
    return array(
        'OK'           => array(false, 0, 1, '', 0),
        'ANMELDUNG'    => array(false, 0, 1, '', 0),
        'GERAETE'      => array(true, 0, 999, '', 0),
        'ONLINE'       => array(true, 0, 999, '', 0),
        'ALTER'        => array(true, -1, 99999999, 's', 0),
        'ZAEHLER'      => array(true, -1, 999, '', 0),
        'LETZTE_OK'    => array(true, -1, 1, '', 0),
        'LETZTE_ALTER' => array(true, -1, 99999999, 's', 0),
    );
}

/** Suchtext fuer Loxone - mit Trennzeichen (Regeln/07). */
function ax_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/** Der Takt-Zustand (data/takt.json). */
function ax_takt_lesen()
{
    $p = ax_paths();
    $t = ax_json_lesen($p['datadir'] . '/takt.json');
    if (!is_array($t)) { $t = array(); }
    return $t + array('ts' => 0, 'zaehler' => -1, 'ok' => 0, 'status_ts' => 0, 'geraete_ts' => 0,
                      'dienst_versuch' => 0, 'voll_ts' => 0, 'hue_versuch' => 0, 'hue_d_versuch' => 0);
}

/** Alter zur Lesezeit; -1 = noch nie (Regeln/03). */
function ax_alter($ts)
{
    $ts = (int) $ts;
    return $ts > 0 ? max(0, time() - $ts) : -1;
}

/**
 * Die Statuswerte. Rueckgabe: array(http, felder). 503 ohne Daten, wenn
 * keine Anmeldung vorliegt oder Amazon sie abgelehnt hat (Regeln/07).
 */
function ax_status()
{
    $a = ax_amazon();
    $bef = ax_anmeldung_befund();
    if (!$a) { return array(503, array('OK' => 0, 'GRUND' => 'ANMELDUNG')); }
    if ($bef['befund'] === 'ABGELAUFEN') { return array(503, array('OK' => 0, 'GRUND' => 'ANMELDUNG_ABGELAUFEN')); }
    $t = ax_takt_lesen();
    $st = ax_geraete();
    $n = 0; $on = 0;
    if ($st) {
        foreach ($st['liste'] as $g) {
            if ($g['familie'] === 'WHA') { continue; }
            $n++;
            if (!empty($g['online'])) { $on++; }
        }
    }
    $p = ax_paths();
    $l = ax_json_lesen($p['datadir'] . '/letzte.json');
    $alter = ax_alter($t['ts']);
    $ok = ($alter >= 0 && $alter <= AX_OK_GRENZE_S && !empty($t['ok'])) ? 1 : 0;
    return array(200, array(
        'OK' => $ok,
        'ANMELDUNG' => 1,
        'GERAETE' => $n,
        'ONLINE' => $on,
        'ALTER' => $alter,
        'ZAEHLER' => (int) $t['zaehler'],
        'LETZTE_OK' => is_array($l) && isset($l['ergebnis']) ? (int) $l['ergebnis'] : -1,
        'LETZTE_ALTER' => is_array($l) && isset($l['zeit']) ? ax_alter($l['zeit']) : -1,
    ));
}

/* ==================================================================
 * MQTT (Regeln/07): mosquitto_pub mit Optionsdatei, Retain je Thema
 * ================================================================== */

/**
 * Die Themenliste - die Anleitung im Reiter MQTT und die Tabelle, nach der
 * gesendet wird. Muster => array(retained, Sprachschluessel).
 */
function ax_mqtt_themen()
{
    return array(
        'status/ok'                 => array(false, 'THEMA.STATUS_OK'),
        'status/ts'                 => array(false, 'THEMA.STATUS_TS'),
        'status/zaehler'            => array(false, 'THEMA.STATUS_ZAEHLER'),
        'status/befehle'            => array(false, 'THEMA.STATUS_BEFEHLE'),
        'status/anmeldung'          => array(false, 'THEMA.STATUS_ANMELDUNG'),
        'geraete/anzahl'            => array(true, 'THEMA.GERAETE_ANZAHL'),
        'geraet/<name>/online'      => array(true, 'THEMA.GERAET_ONLINE'),
        'geraet/<name>/lautstaerke' => array(true, 'THEMA.GERAET_LAUT'),
        'letzte/zeit'               => array(true, 'THEMA.LETZTE_ZEIT'),
        'letzte/geraet'             => array(true, 'THEMA.LETZTE_GERAET'),
        'letzte/ergebnis'           => array(true, 'THEMA.LETZTE_ERGEBNIS'),
        'letzte/grund'              => array(true, 'THEMA.LETZTE_GRUND'),
        'radio/<zone>/sender'       => array(false, 'THEMA.RADIO_SENDER'),
        'radio/<zone>/zustand'      => array(false, 'THEMA.RADIO_ZUSTAND'),
        'hue_probe/ein'             => array(false, 'THEMA.HUE_EIN'),
        'hue/<kuerzel>/ein'         => array(false, 'THEMA.HUE_LAMPE_EIN'),
        'hue/<kuerzel>/helligkeit'  => array(false, 'THEMA.HUE_LAMPE_HELL'),
    );
}

/** Retained? Ein Thema ohne Tabelleneintrag geht fluechtig (Regeln/07). */
function ax_mqtt_retain($thema)
{
    $muster = preg_replace('#^geraet/[a-z0-9_]{1,40}/#', 'geraet/<name>/', $thema);
    $muster = preg_replace('#^radio/[0-9]{1,2}/#', 'radio/<zone>/', $muster);
    $muster = preg_replace('#^hue/[a-z0-9_]{1,32}/#', 'hue/<kuerzel>/', $muster);
    $t = ax_mqtt_themen();
    return isset($t[$muster]) ? $t[$muster][0] : false;
}

function ax_has_mosquitto()
{
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('exec')) { return false; }
    $o = array();
    $rc = 1;
    @exec('command -v mosquitto_pub 2>/dev/null', $o, $rc);
    return $rc === 0 && isset($o[0]) && is_executable(trim($o[0]));
}

function ax_optionswert($v)
{
    return trim(str_replace(array("\r", "\n", "\t"), '', is_scalar($v) ? (string) $v : ''));
}

/** Broker-Daten aus general.json (Brokeruser/Brokerpass, R07:89-91). */
function ax_broker()
{
    $p = ax_paths();
    $aus = array('host' => '127.0.0.1', 'port' => 1883, 'user' => '', 'pass' => '');
    if ($p['general'] === '' || !is_file($p['general'])) { return $aus; }
    $g = json_decode((string) @file_get_contents($p['general']), true);
    $m = (is_array($g) && isset($g['Mqtt']) && is_array($g['Mqtt'])) ? $g['Mqtt'] : null;
    if (!$m) { return $aus; }
    $hol = function ($k) use ($m) { return isset($m[$k]) && is_scalar($m[$k]) ? (string) $m[$k] : ''; };
    $h = ax_optionswert($hol('Brokerhost'));
    $pt = (int) $hol('Brokerport');
    return array('host' => $h !== '' ? $h : '127.0.0.1', 'port' => ($pt > 0 && $pt < 65536) ? $pt : 1883,
                 'user' => ax_optionswert($hol('Brokeruser')), 'pass' => ax_optionswert($hol('Brokerpass')));
}

/**
 * Optionsdateien fuer mosquitto_pub/_sub ($XDG_CONFIG_HOME/<programm>),
 * Ordner 0700, Datei 0600 - das Kennwort steht nie auf der Befehlszeile.
 * Rueckgabe: der Vorspann der Befehlszeile ('' = nicht moeglich).
 */
function ax_mqtt_vorspann()
{
    $p = ax_paths();
    $ordner = $p['datadir'] . '/mosquitto';
    if (!is_dir($ordner)) { @mkdir($ordner, 0700, true); }
    if (!is_dir($ordner)) { return ''; }
    @chmod($ordner, 0700);
    $b = ax_broker();
    $zeilen = '';
    if ($b['user'] !== '') { $zeilen .= '-u ' . $b['user'] . "\n"; }
    if ($b['pass'] !== '') { $zeilen .= '-P ' . $b['pass'] . "\n"; }
    foreach (array('mosquitto_pub', 'mosquitto_sub') as $n) {
        $f = $ordner . '/' . $n;
        if (!is_file($f) || (string) @file_get_contents($f) !== $zeilen) {
            if (!ax_write_atomic($f, $zeilen, 0600)) { return ''; }
        }
    }
    return 'XDG_CONFIG_HOME=' . escapeshellarg($ordner) . ' ';
}

function ax_mqtt_wert($v)
{
    $w = trim(preg_replace('/\s+/', ' ', (string) $v));
    return $w === '' ? '-' : $w;   // nie leer hinaus - leeres retain loescht
}

/**
 * Paare (Thema ohne Praefix => Wert) senden. Retain aus der Tabelle; ein
 * leerer Wert geht als '-'. Rueckgabe array(versucht, gescheitert) -
 * "versucht" ist nicht "angekommen".
 */
function ax_mqtt_senden(array $paare, ?array $cfg = null)
{
    if ($cfg === null) { $cfg = ax_config(); }
    if (empty($cfg['mqtt_ein']) || !$paare || !ax_has_mosquitto()) { return array(0, 0); }
    $vor = ax_mqtt_vorspann();
    if ($vor === '') { return array(0, count($paare)); }
    $b = ax_broker();
    $n = 0; $fehl = 0;
    foreach ($paare as $thema => $wert) {
        $n++;
        $cmd = $vor . 'mosquitto_pub -h ' . escapeshellarg($b['host']) . ' -p ' . (int) $b['port']
             . (ax_mqtt_retain($thema) ? ' -r' : '')
             . ' -t ' . escapeshellarg($cfg['mqtt_praefix'] . '/' . $thema)
             . ' -m ' . escapeshellarg(ax_mqtt_wert($wert)) . ' >/dev/null 2>&1';
        $rc = 0;
        $o = array();
        @exec($cmd, $o, $rc);
        if ($rc !== 0) { $fehl++; }
    }
    if ($fehl) { ax_log_wenn_neu('mqtt_fehl', 'WARN', 'MQTT: ' . $fehl . ' von ' . $n . ' Themen nicht gesendet (mosquitto_pub).', 3600); }
    return array($n, $fehl);
}

/** Das Ergebnis der letzten Ansage auf letzte/* (retained, nie leer). */
function ax_mqtt_letzte(array $l, array $cfg)
{
    return ax_mqtt_senden(array(
        'letzte/zeit' => (int) $l['zeit'],
        'letzte/geraet' => (string) $l['geraet'],
        'letzte/ergebnis' => (int) $l['ergebnis'],
        'letzte/grund' => (string) $l['grund'],
    ), $cfg);
}

/**
 * Den Takt senden: Lebenszeichen immer, sonst nur Aenderungen und alle
 * 30 Minuten den vollen Satz. Ein verschwundenes Geraet bekommt EINMAL -1
 * auf seine Zahlen (Entscheidung 8); seine Themen werden nicht geloescht.
 */
function ax_mqtt_takt(array $cfg, array $t, $befehle_laeuft, $anmeldung)
{
    $p = ax_paths();
    $f = $p['datadir'] . '/mqtt_letzte.json';
    $alt = ax_json_lesen($f);
    if (!is_array($alt)) { $alt = array('werte' => array(), 'geraete' => array(), 'voll' => 0); }
    $voll = (time() - (int) $alt['voll'] >= 1800) || (int) $alt['voll'] > time();
    $werte = array();
    $st = ax_geraete();
    $jetzt_geraete = array();
    if ($st) {
        $n = 0;
        foreach ($st['liste'] as $g) {
            if ($g['familie'] === 'WHA') { continue; }
            $n++;
            $jetzt_geraete[] = $g['normal'];
            $werte['geraet/' . $g['normal'] . '/online'] = (int) $g['online'];
            $werte['geraet/' . $g['normal'] . '/lautstaerke'] = (int) $g['laut'];
        }
        $werte['geraete/anzahl'] = $n;
        foreach ((array) $alt['geraete'] as $weg) {
            if (is_string($weg) && !in_array($weg, $jetzt_geraete, true)) {
                $werte['geraet/' . $weg . '/online'] = -1;
                $werte['geraet/' . $weg . '/lautstaerke'] = -1;
            }
        }
    }
    $l = ax_json_lesen($p['datadir'] . '/letzte.json');
    if (is_array($l) && isset($l['zeit'])) {
        $werte['letzte/zeit'] = (int) $l['zeit'];
        $werte['letzte/geraet'] = (string) $l['geraet'];
        $werte['letzte/ergebnis'] = (int) $l['ergebnis'];
        $werte['letzte/grund'] = (string) $l['grund'];
    }
    $senden = array();
    foreach ($werte as $k => $v) {
        if ($voll || !array_key_exists($k, $alt['werte']) || (string) $alt['werte'][$k] !== (string) $v) { $senden[$k] = $v; }
    }
    $senden['status/ok'] = (int) $t['ok'];
    $senden['status/ts'] = time();
    $senden['status/zaehler'] = (int) $t['zaehler'];
    $senden['status/befehle'] = $befehle_laeuft ? 1 : 0;
    $senden['status/anmeldung'] = $anmeldung ? 1 : 0;
    list($n, $fehl) = ax_mqtt_senden($senden, $cfg);
    if ($n > 0 && $fehl === 0) {
        foreach ($werte as $k => $v) { if (strpos($k, 'geraet/') === 0 && (int) $v === -1 && !in_array(explode('/', $k)[1], $jetzt_geraete, true)) { unset($werte[$k]); } }
        ax_write_json($f, array('werte' => $werte, 'geraete' => $jetzt_geraete, 'voll' => $voll ? time() : (int) $alt['voll']), 0644);
    }
    return array($n, $fehl);
}

/** Zurueckbehaltene Themen unter einem Praefix lesen (fuer Abraeumen und Test). */
function ax_mqtt_retained_lesen($praefix, $sekunden = 2)
{
    if (!ax_has_mosquitto()) { return null; }
    $vor = ax_mqtt_vorspann();
    if ($vor === '') { return null; }
    $b = ax_broker();
    $o = array();
    $rc = 0;
    @exec($vor . 'mosquitto_sub -h ' . escapeshellarg($b['host']) . ' -p ' . (int) $b['port']
          . ' -t ' . escapeshellarg($praefix . '/#') . ' --retained-only -v -W ' . (int) $sekunden . ' 2>/dev/null', $o, $rc);
    $themen = array();
    foreach ($o as $z) {
        $pos = strpos($z, ' ');
        $thema = $pos === false ? $z : substr($z, 0, $pos);
        if (strpos($thema, $praefix . '/') === 0) { $themen[] = substr($thema, strlen($praefix) + 1); }
    }
    return $themen;
}

/**
 * Die eigenen retained Themen unter $praefix abraeumen (-r -n, direkt am
 * Broker, Regeln/07) und zuruecklesen. Nur Themen, die nach der Tabelle
 * retained sind - nie ein fremdes. Rueckgabe array(gefunden, geraeumt, uebrig).
 */
function ax_mqtt_raeumen($praefix)
{
    $vorher = ax_mqtt_retained_lesen($praefix, 2);
    if ($vorher === null) { return array(-1, 0, -1); }
    $vor = ax_mqtt_vorspann();
    $b = ax_broker();
    $eigen = array();
    foreach ($vorher as $t) { if (ax_mqtt_retain($t)) { $eigen[] = $t; } }
    $n = 0;
    foreach ($eigen as $t) {
        $rc = 0; $o = array();
        @exec($vor . 'mosquitto_pub -h ' . escapeshellarg($b['host']) . ' -p ' . (int) $b['port']
              . ' -r -n -t ' . escapeshellarg($praefix . '/' . $t) . ' >/dev/null 2>&1', $o, $rc);
        if ($rc === 0) { $n++; }
    }
    $nachher = ax_mqtt_retained_lesen($praefix, 2);
    $uebrig = 0;
    foreach ((array) $nachher as $t) { if (ax_mqtt_retain($t)) { $uebrig++; } }
    return array(count($eigen), $n, $nachher === null ? -1 : $uebrig);
}

/** Gateway-Lage aus general.json: gefunden, autostart, fassung (0 = unbekannt). */
function ax_mqtt_gateway_info()
{
    $p = ax_paths();
    $aus = array('gefunden' => false, 'autostart' => false, 'fassung' => 0);
    if ($p['general'] === '' || !is_file($p['general'])) { return $aus; }
    $d = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return $aus; }
    $aus['gefunden'] = true;
    $aus['autostart'] = !empty($d['Mqtt']['Gatewayautostart']);
    $aus['fassung'] = isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 1;
    return $aus;
}

/** Die Abo-Datei des Gateways V1 (config/.../mqtt_subscriptions.cfg) nachfuehren. */
function ax_abo_datei($praefix, $schreiben = false)
{
    $p = ax_paths();
    $pfad = $p['configdir'] . '/mqtt_subscriptions.cfg';
    $soll = trim((string) $praefix, '/') . '/#';
    if ($p['lbhome'] === '') { return array($pfad, false); }
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && !$da && is_dir($p['configdir'])) {
        if (ax_write_atomic($pfad, $soll . "\n", 0644)) {
            ax_log('INFO', 'MQTT: Gateway-Abo nachgefuehrt: ' . $soll);
            $da = true;
        }
    }
    return array($pfad, $da);
}

/* ==================================================================
 * Befehlsabo (Dienst ueber bin/dienst.sh)
 * ================================================================== */

/** Laeuft das Befehlsabo? Fragt dienst.sh status - die PID-Datei ist kein Beleg. */
function ax_dienst_status()
{
    $p = ax_paths();
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('exec')) { return null; }
    $sh = $p['bindir'] . '/dienst.sh';
    if (!is_file($sh)) { return null; }
    $o = array(); $rc = 0;
    @exec('timeout 10 /bin/sh ' . escapeshellarg($sh) . ' status 2>/dev/null', $o, $rc);
    return $rc === 0;
}

function ax_dienst($was)
{
    $p = ax_paths();
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('exec') || !in_array($was, array('start', 'stop'), true)) {
        return array(false, 'NICHT_MOEGLICH');
    }
    $sh = $p['bindir'] . '/dienst.sh';
    if (!is_file($sh)) { return array(false, 'DIENST_SH_FEHLT'); }
    $o = array(); $rc = 0;
    @exec('timeout 20 /bin/sh ' . escapeshellarg($sh) . ' ' . $was . ' 2>&1', $o, $rc);
    return array($rc === 0, implode(' ', array_slice($o, 0, 3)));
}

/* ==================================================================
 * Hue-Probe (Bauliste alexa4 H1-H3; ab Werk aus, nicht am Geraet erprobt).
 * Der Dienst ist bin/ax_hue.php, gestartet ueber bin/hue_dienst.sh.
 * ================================================================== */

/** Soll die Hue-Probe laufen? Nur mit Haken und eingeschaltetem Plugin. */
function ax_hue_soll(array $cfg)
{
    return !empty($cfg['hue_ein']) && !empty($cfg['aktiv']);
}

/** Laeuft die Hue-Probe? Fragt hue_dienst.sh status; null = nicht feststellbar. */
function ax_hue_dienst_status()
{
    $p = ax_paths();
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('exec')) { return null; }
    $sh = $p['bindir'] . '/hue_dienst.sh';
    if (!is_file($sh)) { return null; }
    $o = array(); $rc = 0;
    @exec('timeout 10 /bin/sh ' . escapeshellarg($sh) . ' status 2>/dev/null', $o, $rc);
    return $rc === 0;
}

function ax_hue_dienst($was)
{
    $p = ax_paths();
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('exec') || !in_array($was, array('start', 'stop'), true)) {
        return array(false, 'NICHT_MOEGLICH');
    }
    $sh = $p['bindir'] . '/hue_dienst.sh';
    if (!is_file($sh)) { return array(false, 'DIENST_SH_FEHLT'); }
    $o = array(); $rc = 0;
    @exec('timeout 25 /bin/sh ' . escapeshellarg($sh) . ' ' . $was . ' 2>&1', $o, $rc);
    return array($rc === 0, implode(' ', array_slice($o, 0, 3)));
}

/**
 * Der Messstand der Hue-Probe (data/hue_probe.json, nur der Dienst schreibt
 * ihn) - jeder Wert geprueft, Fehlendes mit Vorgabe. Zaehler: suchen (eine
 * SSDP-Suche nach einer Bridge, je Absender), beschreibung (description.xml),
 * abfragen (/api/<user>/lights), schalten (PUT .../state mit on), eigene
 * (Selbstprobe aus dem Reiter Test), andere (Suchen nach anderen Geraeten).
 */
function ax_hue_lesen($art = null)
{
    $d = ax_json_lesen(ax_hue_messdatei($art));
    if (!is_array($d)) { $d = array(); }
    $zahl = function ($a, $k) { return (is_array($a) && isset($a[$k]) && is_int($a[$k]) && $a[$k] >= 0) ? $a[$k] : 0; };
    $ip = function ($a, $k) {
        return (is_array($a) && isset($a[$k]) && is_string($a[$k]) && preg_match('/^\d{1,3}(\.\d{1,3}){3}\z/', $a[$k])) ? $a[$k] : '';
    };
    $st = function ($a, $k) { return (is_array($a) && isset($a[$k]) && is_string($a[$k])) ? substr((string) preg_replace('/[^\x21-\x7E]/', '', $a[$k]), 0, 120) : ''; };
    $aus = array('pid' => $zahl($d, 'pid'), 'start' => $zahl($d, 'start'), 'ende' => $zahl($d, 'ende'), 'port' => $zahl($d, 'port'),
                 'fehler' => (isset($d['fehler']) && is_string($d['fehler']) && preg_match('/^[A-Z_]{1,30}(\|[0-9]{1,5})?\z/', $d['fehler'])) ? $d['fehler'] : '',
                 'eigene_ip' => $ip($d, 'eigene_ip'), 'andere' => $zahl($d, 'andere'));
    foreach (array('beschreibung', 'abfragen', 'eigene') as $art) {
        $x = isset($d[$art]) ? $d[$art] : null;
        $aus[$art] = array('anzahl' => $zahl($x, 'anzahl'), 'ip' => $ip($x, 'ip'), 'zeit' => $zahl($x, 'zeit'));
    }
    $x = isset($d['schalten']) ? $d['schalten'] : null;
    $aus['schalten'] = array('anzahl' => $zahl($x, 'anzahl'), 'ip' => $ip($x, 'ip'), 'zeit' => $zahl($x, 'zeit'),
                             'ein' => $zahl($x, 'ein') === 1 ? 1 : 0, 'mqtt' => $zahl($x, 'mqtt'), 'mqtt_nicht' => $zahl($x, 'mqtt_nicht'));
    $x = isset($d['suchen']) ? $d['suchen'] : null;
    $s = array('anzahl' => $zahl($x, 'anzahl'), 'beantwortet' => $zahl($x, 'beantwortet'), 'ip' => $ip($x, 'ip'), 'zeit' => $zahl($x, 'zeit'),
               'st' => $st($x, 'st'), 'absender' => array());
    if (is_array($x) && isset($x['absender']) && is_array($x['absender'])) {
        foreach ($x['absender'] as $a => $e) {
            // B3 (alexa6): auch die Listenform [{"ip": ...}] einer aelteren Datei
            if (is_int($a) && is_array($e) && isset($e['ip']) && is_string($e['ip'])) { $a = $e['ip']; }
            if (!is_string($a) || !preg_match('/^\d{1,3}(\.\d{1,3}){3}\z/', $a) || !is_array($e)) { continue; }
            $s['absender'][$a] = array('anzahl' => $zahl($e, 'anzahl'), 'erste' => $zahl($e, 'erste'), 'zeit' => $zahl($e, 'zeit'), 'st' => $st($e, 'st'));
            if (count($s['absender']) >= 10) { break; }
        }
    }
    $aus['suchen'] = $s;
    $x = isset($d['lampe']) ? $d['lampe'] : null;
    $bri = $zahl($x, 'bri');
    $aus['lampe'] = array('ein' => $zahl($x, 'ein') === 1 ? 1 : 0, 'bri' => ($bri >= 1 && $bri <= 254) ? $bri : 254);
    // Fassung 2 (alexa6): je Lampe der Liste Zustand, letzte Abfrage und Schaltung;
    // abgewiesene Anfragen; Lage des MQTT-Lesers fuer die Rueckmeldung aus Loxone.
    $aus['lampen'] = array();
    if (isset($d['lampen']) && is_array($d['lampen'])) {
        foreach ($d['lampen'] as $id => $e) {
            if (!preg_match('/^[0-9]{1,4}\z/', (string) $id) || !is_array($e)) { continue; }
            $a = isset($e['abfrage']) ? $e['abfrage'] : null;
            $sc = isset($e['schalten']) ? $e['schalten'] : null;
            $lb = $zahl($e, 'bri');
            $aus['lampen'][(string) $id] = array('ein' => $zahl($e, 'ein') === 1 ? 1 : 0, 'bri' => ($lb >= 1 && $lb <= 254) ? $lb : 254,
                'quelle' => (isset($e['quelle']) && in_array($e['quelle'], array('alexa', 'loxone'), true)) ? $e['quelle'] : '',
                'zeit' => $zahl($e, 'zeit'),
                'abfrage' => array('anzahl' => $zahl($a, 'anzahl'), 'ip' => $ip($a, 'ip'), 'zeit' => $zahl($a, 'zeit')),
                'schalten' => array('anzahl' => $zahl($sc, 'anzahl'), 'ip' => $ip($sc, 'ip'), 'zeit' => $zahl($sc, 'zeit'),
                    'wert' => (is_array($sc) && isset($sc['wert']) && is_string($sc['wert']) && preg_match('/^ein=[01]( helligkeit=[0-9]{1,3})?\z/', $sc['wert'])) ? $sc['wert'] : '',
                    'mqtt' => $zahl($sc, 'mqtt'), 'mqtt_nicht' => $zahl($sc, 'mqtt_nicht'), 'gleich' => $zahl($sc, 'gleich'), 'gebremst' => $zahl($sc, 'gebremst')));
            if (count($aus['lampen']) >= 60) { break; }
        }
    }
    $x = isset($d['abgewiesen']) ? $d['abgewiesen'] : null;
    $aus['abgewiesen'] = array('anzahl' => $zahl($x, 'anzahl'), 'ip' => $ip($x, 'ip'), 'zeit' => $zahl($x, 'zeit'), 'absender' => array());
    if (is_array($x) && isset($x['absender']) && is_array($x['absender'])) {
        foreach ($x['absender'] as $a => $e) {
            if (is_int($a) && is_array($e) && isset($e['ip']) && is_string($e['ip'])) { $a = $e['ip']; }
            if (!is_string($a) || !preg_match('/^\d{1,3}(\.\d{1,3}){3}\z/', $a) || !is_array($e)) { continue; }
            $aus['abgewiesen']['absender'][$a] = array('anzahl' => $zahl($e, 'anzahl'), 'zeit' => $zahl($e, 'zeit'));
            if (count($aus['abgewiesen']['absender']) >= 10) { break; }
        }
    }
    $x = isset($d['mqtt_abo']) ? $d['mqtt_abo'] : null;
    $aus['mqtt_abo'] = array('verbunden' => $zahl($x, 'verbunden') === 1 ? 1 : 0, 'seit' => $zahl($x, 'seit'),
        'fehler' => (is_array($x) && isset($x['fehler']) && is_string($x['fehler']) && preg_match('/^[A-Z_]{0,20}\z/', $x['fehler'])) ? $x['fehler'] : '',
        'empfangen' => $zahl($x, 'empfangen'), 'zeit' => $zahl($x, 'zeit'), 'ungueltig' => $zahl($x, 'ungueltig'), 'unbekannt' => $zahl($x, 'unbekannt'));
    return $aus;
}

/** H3: das Schalten der Probe-Lampe geht fluechtig an MQTT, sonst nirgends hin. */
function ax_hue_melden($ein, array $cfg)
{
    return ax_mqtt_senden(array('hue_probe/ein' => $ein ? 1 : 0), $cfg);
}

/* ==================================================================
 * Fassung 2 (alexa6): Lampen fuer Alexa (Hue-Nachbildung). Die
 * Nachbildung (bin/ax_hue.php) zeigt die Lampen der Freigabeliste; ein
 * Schalten geht fluechtig an MQTT (<praefix>/hue/<kuerzel>/ein, beim Dimmer
 * dazu .../helligkeit, wenn Alexa eine setzt; Schalter und Licht (an/aus,
 * alexa7) nur .../ein); Loxone meldet den Zustand ueber .../status und
 * .../status_helligkeit zurueck. Ab Werk aus, Liste ab Werk leer.
 * ================================================================== */

/** Eine Zeitspanne ohne "vor"/"ago" - fuer Saetze mit "seit", "fuer etwa", "fertig vor" (B1, alexa6). */
function ax_spanne_text($sek)
{
    $sek = (int) $sek;
    if ($sek < 0) { return ax_t('ALLG.NIE'); }
    if ($sek < 90) { return sprintf(ax_t('ALLG.SPANNE_S'), $sek); }
    if ($sek < 5400) { return sprintf(ax_t('ALLG.SPANNE_MIN'), (int) round($sek / 60)); }
    if ($sek < 172800) { return sprintf(ax_t('ALLG.SPANNE_H'), (int) round($sek / 3600)); }
    return sprintf(ax_t('ALLG.SPANNE_D'), (int) round($sek / 86400));
}

/** Der Schritt eines Hintergrundvorgangs als Wort der Oberflaechensprache (B1). */
function ax_hue_schritt_wort($s)
{
    $w = array('beginn' => 'TEST.SCHRITT_BEGINN', 'docker' => 'TEST.SCHRITT_DOCKER', 'ip' => 'TEST.SCHRITT_IP', 'abbild' => 'TEST.SCHRITT_ABBILD',
               'netz' => 'TEST.SCHRITT_NETZ', 'container' => 'TEST.SCHRITT_CONTAINER', 'nachsehen' => 'TEST.SCHRITT_NACHSEHEN');
    return isset($w[$s]) ? ax_t($w[$s]) : '–';
}

/** Der Vorgang als Wort (Anlegen/Entfernen). */
function ax_hue_vorgang_wort($v)
{
    return $v === 'anlegen' ? ax_t('MELDUNG.HUE_D_V_ANLEGEN') : ax_t('MELDUNG.HUE_D_V_ENTFERNEN');
}

/** Befehl einer Lampe an Loxone (Dienst auf dem LoxBerry): fluechtig ueber mosquitto_pub. Rueckgabe array(versucht, gescheitert). */
function ax_hue_lampe_melden($kuerzel, array $paare, array $cfg)
{
    $senden = array();
    if (array_key_exists('ein', $paare)) { $senden['hue/' . $kuerzel . '/ein'] = (int) $paare['ein']; }
    if (array_key_exists('helligkeit', $paare)) { $senden['hue/' . $kuerzel . '/helligkeit'] = (int) $paare['helligkeit']; }
    return ax_mqtt_senden($senden, $cfg);
}

/** Die Lampen der Liste in der Kurzform fuer die Nachbildung (ohne den Haken "bewusst freigeben"). */
function ax_hue_lampen_kurz(array $cfg)
{
    $aus = array();
    foreach ((isset($cfg['hue_lampen']) && is_array($cfg['hue_lampen'])) ? $cfg['hue_lampen'] : array() as $l) {
        $aus[] = array('id' => (int) $l['id'], 'name' => (string) $l['name'], 'art' => (string) $l['art'], 'kuerzel' => (string) $l['kuerzel']);
    }
    return $aus;
}

/**
 * Die IPv4-Netze, an denen der LoxBerry selbst haengt (ohne lo und ohne
 * Docker-, Bruecken- und Tunnel-Schnittstellen) - das "Heimnetz" fuer den
 * Dienst auf dem LoxBerry. Liste array(netz, maske); 60 s gemerkt.
 */
function ax_hue_lokale_netze()
{
    static $netze = null;
    static $zeit = 0;
    if ($netze !== null && time() - $zeit < 60) { return $netze; }
    $netze = array();
    $zeit = time();
    list($rc, $out, ) = ax_aufruf(array('ip', '-4', '-o', 'addr', 'show'), 5);
    if ($rc !== 0) { return $netze; }
    foreach (preg_split('/\r?\n/', $out) as $z) {
        if (!preg_match('#^\d+:\s+([A-Za-z0-9][A-Za-z0-9_.@\-]{0,30})\s+inet ([0-9.]{7,15})/([0-9]{1,2})\b#', $z, $m)) { continue; }
        if ($m[1] === 'lo' || preg_match('/^(docker|veth|br-|virbr|tun|tap|wg|dummy)/', $m[1])) { continue; }
        $ip = ax_ipv4_zahl($m[2]);
        $pf = (int) $m[3];
        if ($ip === null || $pf < 8 || $pf > 30) { continue; }
        $maske = (0xFFFFFFFF << (32 - $pf)) & 0xFFFFFFFF;
        $netze[] = array($ip & $maske, $maske);
    }
    return $netze;
}

/** Die Lage fuer den Dienst auf dem LoxBerry (gleiche Form wie ax_hue_container_lage() im Container). */
function ax_hue_lb_lage(array $cfg)
{
    return array('lampen' => ax_hue_lampen_kurz($cfg), 'probe' => empty($cfg['hue_probe_lampe']) ? 0 : 1,
                 'echos' => (isset($cfg['hue_echos']) && is_array($cfg['hue_echos'])) ? array_values($cfg['hue_echos']) : array(),
                 'netze' => ax_hue_lokale_netze(), 'wirt' => '', 'mqtt_ein' => empty($cfg['mqtt_ein']) ? 0 : 1,
                 'praefix' => $cfg['mqtt_praefix'], 'broker' => ax_broker());
}

/** Die Zeitzone, in der dieses Plugin protokolliert - der Container bekommt dieselbe (B2). */
function ax_hue_zeitzone()
{
    $z = date_default_timezone_get();
    return in_array($z, timezone_identifiers_list(), true) ? $z : 'UTC';
}

/** Zeichen eines Namens (UTF-8), oder -1. */
function ax_hue_zeichenzahl($s)
{
    if (!is_string($s) || preg_match('//u', $s) !== 1) { return -1; }
    return (int) preg_match_all('/./us', $s);
}

/** Grund, warum dieser Lampenname nicht geht ('' = gut). Nr. 19: beanstanden, nie aendern. */
function ax_hue_name_grund($name)
{
    if (!is_string($name) || $name === '') { return 'LAMPE_NAME_FEHLT'; }
    $n = ax_hue_zeichenzahl($name);
    if ($n < 0 || preg_match('/[\x00-\x1F\x7F]/', $name)) { return 'LAMPE_NAME_ZEICHEN|' . str_replace('|', '/', substr($name, 0, 40)); }
    if (trim($name) !== $name) { return 'LAMPE_NAME_ZEICHEN|' . $name; }
    if ($n > AX_HUE_NAME_MAX) { return 'LAMPE_NAME_LAENGE|' . str_replace('|', '/', $name) . '|' . $n; }
    if (preg_match('/[=";<>|\\\\%]/', $name)) { return 'LAMPE_NAME_ZEICHEN|' . str_replace('|', '/', $name); }
    if (ax_name_normal($name) === 'loxone_probe') { return 'LAMPE_NAME_PROBE'; }
    return '';
}

/**
 * Traegt der Name ein Wort, das eine Tuer, ein Tor, eine Garage, einen Alarm
 * oder ein Schloss meint (de/en, R05)? Rueckgabe das Wort oder ''. Bewusst
 * weit: auch "Monitor" enthaelt "tor" - dann genuegt der Haken.
 */
function ax_hue_gefahr($name)
{
    $n = ax_name_normal($name);
    foreach (array('tuer', 'tor', 'garage', 'alarm', 'schloss', 'door', 'gate', 'lock') as $w) {
        if (strpos($n, $w) !== false) { return $w; }
    }
    return '';
}

/** Ein Kuerzel aus dem Namen vorschlagen (klein, a-z/0-9/_, hoechstens 32 Zeichen). */
function ax_hue_kuerzel_vorschlag($name, $id = 0)
{
    $k = trim((string) preg_replace('/_+/', '_', ax_name_normal((string) $name)), '_');
    $k = rtrim(substr($k, 0, 32), '_');
    return $k !== '' ? $k : 'lampe_' . (int) $id;
}

/** Die Lampen, die die Nachbildung zeigen soll: Hue-ID => Name (Probe-Lampe ID 1). */
function ax_hue_erwartete_lampen(array $cfg)
{
    $s = array();
    if (!empty($cfg['hue_probe_lampe'])) { $s['1'] = 'Loxone Probe'; }
    foreach ((isset($cfg['hue_lampen']) && is_array($cfg['hue_lampen'])) ? $cfg['hue_lampen'] : array() as $l) { $s[(string) $l['id']] = $l['name']; }
    return $s;
}

/** Zeigt die Antwort von /api/<u>/lights genau diese Lampen? */
function ax_hue_lichter_passen($j, array $soll)
{
    if (!is_array($j) || count($j) !== count($soll)) { return false; }
    foreach ($soll as $id => $n) {
        if (!isset($j[$id]['name']) || $j[$id]['name'] !== $n) { return false; }
    }
    return true;
}

/** bri 1..254 als Prozent (Anzeige). */
function ax_hue_bri_prozent($bri)
{
    return max(1, min(100, (int) round(((int) $bri) * 100 / 254)));
}

/** Die freigegebenen Echos pruefen: jede Adresse im Netz der Schnittstelle. Rueckgabe Grund ('' = gut). */
function ax_hue_echos_pruefen(array $liste, $schnittstelle)
{
    if (!$liste) { return ''; }
    list($ok, $g, $n) = ax_hue_netz($schnittstelle);
    if (!$ok) { return $g; }
    $maske = (0xFFFFFFFF << (32 - $n['praefix'])) & 0xFFFFFFFF;
    foreach ($liste as $ip) {
        $z = ax_ipv4_zahl($ip);
        if ($z === null || ($z & $maske) !== ax_ipv4_zahl($n['netzadresse'])) {
            return 'ECHO_NETZ|' . $ip . '|' . $n['netzadresse'] . '/' . $n['praefix'] . '|' . $n['schnittstelle'];
        }
    }
    return '';
}

/** Der UDP-Eingang des MQTT-Gateways (Mqtt.Udpinport aus general.json), 0 = unbekannt. */
function ax_mqtt_udpport()
{
    $p = ax_paths();
    if ($p['general'] === '' || !is_file($p['general'])) { return 0; }
    $g = json_decode((string) @file_get_contents($p['general']), true);
    $v = (is_array($g) && isset($g['Mqtt']['Udpinport']) && is_scalar($g['Mqtt']['Udpinport'])) ? (string) $g['Mqtt']['Udpinport'] : '';
    return (preg_match('/^[0-9]{1,5}\z/', $v) && (int) $v > 0 && (int) $v < 65536) ? (int) $v : 0;
}

/** Der Name, unter dem das MQTT-Gateway ein Thema als virtuellen Eingang anlegt (/ und % werden _, Regeln/07). */
function ax_hue_gateway_name(array $cfg, $kuerzel, $feld)
{
    return str_replace(array('/', '%'), '_', $cfg['mqtt_praefix'] . '/hue/' . $kuerzel . '/' . $feld);
}

/**
 * [Dateiname, Inhalt] der Eingangsvorlage fuer die Lampen: Gateway-Eingaenge
 * als VirtualInHttp mit Dummy-Adresse (Kunstgriff Regeln/07), Titel = Name des
 * Gateways, Check leer. Je Lampe "ein" (digital), beim Dimmer "helligkeit" (0..100).
 */
function ax_vorlage_hue_ein($host, array $cfg)
{
    $cmds = array();
    foreach ($cfg['hue_lampen'] as $l) {
        $cmds[] = array('title' => ax_hue_gateway_name($cfg, $l['kuerzel'], 'ein'), 'comment' => 'Alexa ' . $l['name'] . ' ein',
                        'check' => ' ', 'analog' => false, 'min' => 0, 'max' => 1, 'unit' => '<v>');
        if ($l['art'] === 'dimmer') {
            $cmds[] = array('title' => ax_hue_gateway_name($cfg, $l['kuerzel'], 'helligkeit'), 'comment' => 'Alexa ' . $l['name'] . ' Helligkeit',
                            'check' => ' ', 'analog' => true, 'min' => 0, 'max' => 100, 'unit' => '<v> %');
        }
    }
    return array('VI_alexang_hue.xml', ax_xml_virtual_in_http(array('title' => 'Alexa NG Lampen',
        'comment' => ax_t('LOX.VH_KOMMENTAR') . ' (' . date('d.m.Y') . ')', 'address' => 'http://localhost', 'polling' => '604800'), $cmds));
}

/** Die Adresse des LoxBerry fuer /dev/udp/... (aus der Anfrage; ohne Port). */
function ax_hue_udp_host($host)
{
    $h = (string) preg_replace('/:\d+$/', '', (string) preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) $host));
    if ($h === '' || in_array(strtolower($h), array('127.0.0.1', 'localhost', '[::1]', '::1'), true)) { $h = gethostname() ?: 'loxberry'; }
    return $h;
}

/**
 * [Dateiname, Inhalt] der Ausgangsvorlage fuer die Rueckmeldung: ein
 * virtueller Ausgang an den UDP-Eingang des MQTT-Gateways
 * (/dev/udp/<LoxBerry>/<Udpinport>); je Lampe "Rueckmeldung" (digital:
 * publish .../status 1 bzw. 0), beim Dimmer "Rueckmeldung Helligkeit"
 * (publish .../status_helligkeit <v>). publish, nie retain.
 */
function ax_vorlage_hue_aus($host, array $cfg)
{
    $port = ax_mqtt_udpport();
    $cmds = array();
    foreach ($cfg['hue_lampen'] as $l) {
        $t = $cfg['mqtt_praefix'] . '/hue/' . $l['kuerzel'] . '/';
        $cmds[] = array('title' => 'Alexa ' . $l['name'] . ' Rückmeldung', 'comment' => 'Rückmeldung ' . $l['kuerzel'], 'analog' => false,
                        'on' => 'publish ' . $t . 'status 1', 'off' => 'publish ' . $t . 'status 0');
        if ($l['art'] === 'dimmer') {
            $cmds[] = array('title' => 'Alexa ' . $l['name'] . ' Rückmeldung Helligkeit', 'comment' => 'Helligkeit ' . $l['kuerzel'] . ' %',
                            'on' => 'publish ' . $t . 'status_helligkeit <v>');
        }
    }
    return array('VQ_alexang_hue.xml', ax_xml_virtual_out(array('title' => 'Alexa NG Lampen-Rückmeldung',
        'comment' => ax_t('LOX.VQH_KOMMENTAR') . ' (' . date('d.m.Y') . ')',
        'address' => '/dev/udp/' . ax_hue_udp_host($host) . '/' . ($port > 0 ? $port : 11884)), $cmds));
}

/**
 * B4 (alexa6): alte eigene Abbilder entfernen - nur lb-<ordner>-hue-php:*
 * mit BEIDEN eigenen Labels, nie das aktuelle, nie eines, das ein Container
 * noch benutzt (docker image rm ohne -f weigert sich dann). Nachgesehen.
 * Rueckgabe array(entfernt, geblieben).
 */
function ax_hue_docker_abbilder_raeumen($aktuell)
{
    $p = ax_paths();
    $nm = ax_hue_docker_namen();
    list($rc, $out, ) = ax_docker(array('image', 'ls', '--filter', 'label=de.loxberry.plugin.name=' . $nm['label'], '--format', '{{.Repository}}:{{.Tag}}'), 20);
    if ($rc !== 0) {
        ax_log_wenn_neu('hue_d_abbilder', 'INFO', 'Hue-Probe (eigene Netzadresse): die Abbilder liessen sich nicht auflisten (rc ' . $rc . ') - kein altes entfernt.', 86400);
        return array(0, 0);
    }
    $muster = '#^' . preg_quote('lb-' . strtolower($p['plugin']) . '-hue-php', '#') . ':[A-Za-z0-9._\-]{1,40}\z#';
    $weg = 0;
    $bleibt = 0;
    foreach (preg_split('/\s+/', trim($out)) as $b) {
        if ($b === '' || $b === $aktuell || !preg_match($muster, $b)) { continue; }
        list($rc2, $o2, ) = ax_docker(array('image', 'inspect', $b), 15);
        $info = $rc2 === 0 ? ax_docker_json($o2) : null;
        if (!ax_hue_docker_eigen($info)) { $bleibt++; continue; }
        list(, , $e3) = ax_docker(array('image', 'rm', $b), 60);
        list($rc4, , ) = ax_docker(array('image', 'inspect', $b), 15);
        if ($rc4 !== 0) {
            $weg++;
            ax_log('INFO', 'Hue-Probe (eigene Netzadresse): altes Abbild ' . $b . ' entfernt (nachgesehen).');
        } else {
            $bleibt++;
            ax_log('WARN', 'Hue-Probe (eigene Netzadresse): altes Abbild ' . $b . ' blieb (' . ax_letzte_zeile($e3) . ').');
        }
    }
    return array($weg, $bleibt);
}

/**
 * Die Hue-Probe nach einer Aenderung nachziehen (Speichern, Zurueckspielen):
 * starten, anhalten oder - anderer Port - neu starten. Rueckgabe: Satz fuer
 * die Meldung ('' = nichts zu tun). Gesagt wird, was nachgesehen wurde.
 */
function ax_hue_nachziehen(array $neu, array $alt)
{
    // Nr. 41: der Container (eigene Netzadresse) - ab Werk und bei Art "loxberry" ohne Anfrage an Docker.
    $lb = ax_hue_nachziehen_lb($neu, $alt);
    $d = ax_hue_docker_nachziehen($neu, $alt);
    return trim($lb . ($lb !== '' && $d !== '' ? ' ' : '') . $d);
}

/** Der Dienst auf dem LoxBerry (alexa4 H1): laeuft nur bei Art "loxberry". */
function ax_hue_nachziehen_lb(array $neu, array $alt)
{
    $soll = ax_hue_soll_lb($neu);
    $laeuft = ax_hue_dienst_status();
    if ($laeuft === null) { return ax_hue_soll($neu) ? ax_t('MELDUNG.HUE_NICHT_PRUEFBAR') : ''; }
    if ($soll) {
        $port_neu = isset($alt['hue_port']) && (int) $alt['hue_port'] !== (int) $neu['hue_port'];
        if ($laeuft && !$port_neu) { return ''; }
        if ($laeuft) { ax_hue_dienst('stop'); }
        list($ok, $text) = ax_hue_dienst('start');
        if ($ok && ax_hue_dienst_status()) { return sprintf(ax_t('MELDUNG.HUE_GESTARTET'), (int) $neu['hue_port']); }
        $z = ax_hue_lesen('loxberry');
        return sprintf(ax_t('MELDUNG.HUE_FEHL'), trim($text . ' ' . ($z['fehler'] !== '' ? ax_grund_text($z['fehler']) : '')));
    }
    if ($laeuft) {
        list($ok) = ax_hue_dienst('stop');
        return ($ok && ax_hue_dienst_status() === false) ? ax_t('MELDUNG.HUE_ANGEHALTEN') : sprintf(ax_t('MELDUNG.HUE_FEHL'), '');
    }
    // Der Haken ging weg und der Dienst endete schon selbst (er prueft alle 5 s):
    // auch das wird gesagt - nachgesehen ist es mit dem status oben.
    return ax_hue_soll_lb($alt) ? ax_t('MELDUNG.HUE_ANGEHALTEN') : '';
}

/**
 * Selbstprobe aus dem Reiter Test: description.xml und /api/.../lights ueber
 * 127.0.0.1 holen (Kennung im User-Agent - der Dienst zaehlt sie getrennt).
 * Rueckgabe array(stand, text): 1 beide Antworten wie erwartet, 0 andere
 * Antwort, -1 nicht feststellbar.
 */
function ax_hue_selbstprobe(array $cfg)
{
    if (!function_exists('curl_init')) { return array(-1, ax_t('TEST.A_HUE_PROBE_NICHT')); }
    $erg = array();
    foreach (array('/description.xml', '/api/selbstprobe/lights') as $pfad) {
        $ch = curl_init('http://127.0.0.1:' . (int) $cfg['hue_port'] . $pfad);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 3,
                                     CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'AlexaNG-Selbstprobe/1'));
        $r = curl_exec($ch);
        $erg[] = array((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $r);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    }
    if ($erg[0][0] === 0) { return array(0, sprintf(ax_t('TEST.A_HUE_PROBE_FEHL'), (int) $cfg['hue_port'], '-')); }
    $j = json_decode($erg[1][1], true);
    // Fassung 2 (alexa6): genau die eingestellten Lampen (Probe-Lampe und Liste)
    $soll = ax_hue_erwartete_lampen($cfg);
    $gut = $erg[0][0] === 200 && strpos($erg[0][1], '<modelName>Philips hue bridge 2012</modelName>') !== false
        && $erg[1][0] === 200 && ax_hue_lichter_passen($j, $soll);
    if ($gut && $soll === array('1' => 'Loxone Probe')) { return array(1, sprintf(ax_t('TEST.A_HUE_PROBE_OK'), (int) $cfg['hue_port'])); }
    if ($gut) { return array(1, sprintf(ax_t('TEST.A_HUE_PROBE_OK_N'), (int) $cfg['hue_port'], count($soll))); }
    return array(0, sprintf(ax_t('TEST.A_HUE_PROBE_FEHL'), (int) $cfg['hue_port'], $erg[0][0] . '/' . $erg[1][0]));
}

/* ==================================================================
 * Hue-Probe auf eigener Netzadresse (Entscheidung Nr. 41, alexa5;
 * Vorabfassung, nicht am Geraet erprobt). Gemessen am 02.10.2026: das Echo
 * suchte, bekam Antwort und holte description.xml auf Port 8380, fragte die
 * Lampen aber nie ab - es braucht die Bridge auf Port 80, und den haelt
 * Apache. Deshalb bekommt die Nachbildung ueber Docker eine EIGENE IP im
 * Heimnetz (macvlan an der Schnittstelle, ab Werk eth0) und lauscht dort auf
 * Port 80 und 1900. Zusaetzlich haengt der Container am Standard-Bridge-Netz:
 * ein macvlan-Kind erreicht seinen eigenen Wirt nicht, den Broker aber ueber
 * die Bruecke. Apache, Webport, Loxone-Adressen und die Kerndateien des
 * LoxBerry bleiben unberuehrt.
 *
 * Grundsaetze (Bauart MGiSmart 1.1.23, mg_gw_*):
 *   - Nur EIGENES wird angefasst: Container, Netz und Abbild tragen die
 *     Labels de.loxberry.plugin.folder=<ordner> und de.loxberry.plugin.name=
 *     <ordner>-hue. Ein gleichnamiges fremdes Objekt bleibt unberuehrt und
 *     ist ein Grund (CONTAINER_FREMD, NETZ_FREMD).
 *   - Jeder Aufruf argumentweise (proc_open mit Liste, keine Schale), mit
 *     timeout. Docker wird nur gefragt, wenn die Art "docker" eingeschaltet
 *     ist oder ein Container dieses Plugins angelegt wurde - ab Werk nie.
 *   - Anlegen und Entfernen laufen im Hintergrund (bin/hue_vorgang.php).
 *   - Im Container laeuft nur eigener Code (bin/ax_hue.php mit
 *     bin/ax_hue_container.php, nur lesend eingehaengt) auf dem offiziellen
 *     Abbild php:8.4-cli mit sockets und pcntl (bin/hue_docker/Dockerfile,
 *     am Geraet gebaut). Er laeuft als Benutzer des Plugins, ohne
 *     Faehigkeiten, mit nur lesendem Dateisystem; Port 80 bindet er ueber
 *     net.ipv4.ip_unprivileged_port_start=80 in seinem Netz-Namensraum.
 * ================================================================== */

/** Die Art der Hue-Probe: 'loxberry' (Dienst auf dem LoxBerry, alexa4) oder 'docker' (eigene Netzadresse). */
function ax_hue_art(array $cfg)
{
    return (isset($cfg['hue_art']) && $cfg['hue_art'] === 'docker') ? 'docker' : 'loxberry';
}

function ax_hue_soll_lb(array $cfg)
{
    return ax_hue_soll($cfg) && ax_hue_art($cfg) === 'loxberry';
}

function ax_hue_soll_docker(array $cfg)
{
    return ax_hue_soll($cfg) && ax_hue_art($cfg) === 'docker';
}

/** Der Ordner, den der Container als /hue sieht: sein Messstand und seine Konfiguration. */
function ax_hue_dockerdir()
{
    $p = ax_paths();
    return $p['datadir'] . '/hue';
}

/** Der Messstand je Art: der Dienst auf dem LoxBerry schreibt data/hue_probe.json, der Container data/hue/hue_probe.json. */
function ax_hue_messdatei($art = null)
{
    $p = ax_paths();
    if ($art === null) { $art = ax_hue_art(ax_config()); }
    return $art === 'docker' ? ax_hue_dockerdir() . '/hue_probe.json' : $p['datadir'] . '/hue_probe.json';
}

/** Eine IPv4 in Punktschreibweise als Zahl, sonst null. Keine fuehrenden Nullen, kein Leerraum (Nr. 19). */
function ax_ipv4_zahl($s)
{
    if (!is_string($s) || !preg_match('/^(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])){3}\z/', $s)) {
        return null;
    }
    $t = explode('.', $s);
    return (((int) $t[0] * 256 + (int) $t[1]) * 256 + (int) $t[2]) * 256 + (int) $t[3];
}

function ax_ipv4_text($z)
{
    return implode('.', array(($z >> 24) & 255, ($z >> 16) & 255, ($z >> 8) & 255, $z & 255));
}

/** Fremde Ausgabe fuer Protokoll und Oberflaeche: eine Zeile, ohne Steuerzeichen, gekuerzt. */
function ax_kurztext($s, $max = 200)
{
    $s = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $s)));
    if (strlen($s) > $max) {
        $s = substr($s, 0, $max - 3);
        if (preg_match('//u', $s) !== 1) { $s = (string) preg_replace('/[\x80-\xFF]+\z/', '', $s); }
        $s .= '...';
    }
    return $s;
}

/** Die letzte nicht leere Zeile einer Ausgabe (die Fehlermeldung von docker steht zuletzt). */
function ax_letzte_zeile($s)
{
    $z = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $s)), 'strlen'));
    return $z ? ax_kurztext($z[count($z) - 1], 200) : '';
}

/**
 * Einen Befehl argumentweise ausfuehren (proc_open mit Liste - keine Schale,
 * kein Argument wird als Befehl gelesen), begrenzt durch timeout. Rueckgabe
 * array(rc, stdout, stderr); rc 124 Zeitablauf, 127 nicht ausfuehrbar.
 * $env: die Umgebung dieses einen Aufrufs (null = die eigene).
 */
function ax_aufruf(array $cmd, $sekunden, $env = null)
{
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('proc_open')) { return array(127, '', 'nicht moeglich'); }
    $voll = array('timeout', (string) max(1, (int) $sekunden));
    foreach ($cmd as $a) { $voll[] = (string) $a; }
    $desk = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $pipes = array();
    $proc = @proc_open($voll, $desk, $pipes, null, $env);
    if (!is_resource($proc)) { return array(127, '', 'proc_open gescheitert'); }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $aus = array(1 => '', 2 => '');
    $offen = array(1 => $pipes[1], 2 => $pipes[2]);
    $ende = time() + (int) $sekunden + 15;
    while ($offen && time() < $ende) {
        $lesen = array_values($offen);
        $w = null;
        $e = null;
        if (@stream_select($lesen, $w, $e, 1) === false) { break; }
        foreach ($offen as $n => $h) {
            $t = fread($h, 65536);
            if ($t !== false && $t !== '' && strlen($aus[$n]) < 1048576) { $aus[$n] .= $t; }
            if (feof($h)) { fclose($h); unset($offen[$n]); }
        }
    }
    foreach ($offen as $h) { fclose($h); }
    $rc = proc_close($proc);
    return array((int) $rc, $aus[1], $aus[2]);
}

/* ---------------- Netz der Schnittstelle und die eigene IP ---------------- */

/** Die Netzwerkschnittstellen dieses LoxBerry fuer die Auswahl (ohne lo und ohne Docker- und Tunnel-Schnittstellen). */
function ax_hue_schnittstellen()
{
    if (DIRECTORY_SEPARATOR === '\\') { return array(); }
    list($rc, $out, ) = ax_aufruf(array('ip', '-o', 'link', 'show'), 5);
    $aus = array();
    if ($rc !== 0) { return $aus; }
    foreach (preg_split('/\r?\n/', $out) as $z) {
        if (!preg_match('/^\d+:\s+([A-Za-z0-9][A-Za-z0-9_.\-]{0,14})(@[^:\s]+)?:/', $z, $m)) { continue; }
        if ($m[1] === 'lo' || preg_match('/^(docker|veth|br-|virbr|tun|tap|wg|dummy)/', $m[1])) { continue; }
        $aus[] = $m[1];
    }
    return array_values(array_unique($aus));
}

/**
 * Das IPv4-Netz einer Schnittstelle: array(ok, grund, netz) mit netz =
 * array(schnittstelle, ip, praefix, netzadresse, broadcast, gateway). Gelesen
 * mit "ip -4 -o addr show dev <s>" und "ip -4 route show default"
 * (argumentweise). Nur ein Netz mit /16 bis /30 und einem Router an DERSELBEN
 * Schnittstelle gilt - sonst ist nichts sicher zu pruefen, und der Schutz
 * faellt geschlossen aus.
 */
function ax_hue_netz($schnittstelle)
{
    $leer = array('schnittstelle' => (string) $schnittstelle, 'ip' => '', 'praefix' => 0, 'netzadresse' => '', 'broadcast' => '', 'gateway' => '');
    if (!is_string($schnittstelle) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,14}\z/', $schnittstelle)) {
        return array(false, 'SCHNITTSTELLE_FORM', $leer);
    }
    if (DIRECTORY_SEPARATOR === '\\') { return array(false, 'NETZ_LESEN', $leer); }
    list($rc, $out, $err) = ax_aufruf(array('ip', '-4', '-o', 'addr', 'show', 'dev', $schnittstelle), 5);
    if ($rc === 127 || $rc === 124) { return array(false, 'NETZ_LESEN', $leer); }
    if ($rc !== 0) { return array(false, 'SCHNITTSTELLE_FEHLT|' . $schnittstelle, $leer); }
    if (!preg_match('#\binet ([0-9.]{7,15})/([0-9]{1,2})\b#', $out, $m) || ax_ipv4_zahl($m[1]) === null) {
        return array(false, 'SCHNITTSTELLE_OHNE_IP|' . $schnittstelle, $leer);
    }
    $pf = (int) $m[2];
    if ($pf < 16 || $pf > 30) { return array(false, 'NETZ_GROESSE|' . $pf, $leer); }
    $maske = (0xFFFFFFFF << (32 - $pf)) & 0xFFFFFFFF;
    $netz = ax_ipv4_zahl($m[1]) & $maske;
    $bc = $netz | (~$maske & 0xFFFFFFFF);
    list($rc2, $out2, ) = ax_aufruf(array('ip', '-4', 'route', 'show', 'default'), 5);
    $gw = '';
    if ($rc2 === 0) {
        foreach (preg_split('/\r?\n/', $out2) as $z) {
            if (preg_match('#^default via ([0-9.]{7,15}) dev (\S+)#', trim($z), $mm) && $mm[2] === $schnittstelle
                && ax_ipv4_zahl($mm[1]) !== null && (ax_ipv4_zahl($mm[1]) & $maske) === $netz) {
                $gw = $mm[1];
                break;
            }
        }
    }
    if ($gw === '') { return array(false, 'GATEWAY_FEHLT|' . $schnittstelle, $leer); }
    return array(true, '', array('schnittstelle' => $schnittstelle, 'ip' => $m[1], 'praefix' => $pf,
                                 'netzadresse' => ax_ipv4_text($netz), 'broadcast' => ax_ipv4_text($bc), 'gateway' => $gw));
}

/** Benutzt schon ein Geraet diese Adresse? Erst ping (1 s), dann der ARP-Eintrag (antwortet auch ohne ICMP). */
function ax_hue_ip_belegt($ip, $schnittstelle)
{
    list($rc, , ) = ax_aufruf(array('ping', '-c', '1', '-W', '1', $ip), 4);
    if ($rc === 0) { return true; }
    list($rc2, $out2, ) = ax_aufruf(array('ip', 'neigh', 'show', $ip, 'dev', $schnittstelle), 3);
    return $rc2 === 0 && preg_match('/\blladdr\s+[0-9a-fA-F:]{17}\b.*\b(REACHABLE|STALE|DELAY|PROBE|PERMANENT)\b/', $out2) === 1;
}

/**
 * Die eigene IP der Hue-Probe pruefen (Nr. 19: beanstanden, nie aendern).
 * Rueckgabe der Grund ('' = gut): Pflicht, Form, im Netz der Schnittstelle,
 * nicht Netz- oder Rundsendeadresse, nicht der LoxBerry selbst, nicht der
 * Router; mit $belegt zusaetzlich, ob schon ein Geraet sie benutzt.
 */
function ax_hue_ip_pruefen($ip, $schnittstelle, $belegt = true)
{
    if (!is_string($ip) || $ip === '') { return 'IP_PFLICHT'; }
    $z = ax_ipv4_zahl($ip);
    if ($z === null) { return 'IP_FORM'; }
    list($ok, $g, $n) = ax_hue_netz($schnittstelle);
    if (!$ok) { return $g; }
    $maske = (0xFFFFFFFF << (32 - $n['praefix'])) & 0xFFFFFFFF;
    if (($z & $maske) !== ax_ipv4_zahl($n['netzadresse'])) {
        return 'IP_NETZ|' . $n['netzadresse'] . '/' . $n['praefix'] . '|' . $n['schnittstelle'];
    }
    if ($ip === $n['netzadresse']) { return 'IP_NETZADRESSE'; }
    if ($ip === $n['broadcast']) { return 'IP_BROADCAST'; }
    if ($ip === $n['ip']) { return 'IP_LOXBERRY'; }
    if ($ip === $n['gateway']) { return 'IP_GATEWAY'; }
    if ($belegt && ax_hue_ip_belegt($ip, $n['schnittstelle'])) { return 'IP_BELEGT|' . $ip; }
    return '';
}

/** Welches Feld traegt diesen Grund? (Markierung im Formular, X-2) */
function ax_hue_ip_feld($grund)
{
    $k = explode('|', (string) $grund);
    return in_array($k[0], array('SCHNITTSTELLE_FORM', 'SCHNITTSTELLE_FEHLT', 'SCHNITTSTELLE_OHNE_IP', 'NETZ_LESEN', 'NETZ_GROESSE',
                                 'GATEWAY_FEHLT'), true) ? 'hue_schnittstelle' : 'hue_ip';
}

/* ---------------- Docker ---------------- */

/** Pfad zum docker-Programm, oder ''. */
function ax_docker_bin()
{
    static $pfad = null;
    if ($pfad === null) {
        $pfad = '';
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('exec')) {
            $out = array();
            @exec('command -v docker 2>/dev/null', $out);
            $k = isset($out[0]) ? trim($out[0]) : '';
            if ($k !== '' && is_file($k) && is_executable($k)) { $pfad = $k; }
        }
    }
    return $pfad;
}

function ax_docker(array $args, $sekunden, $env = null)
{
    $bin = ax_docker_bin();
    if ($bin === '') { return array(127, '', 'docker fehlt'); }
    return ax_aufruf(array_merge(array($bin), $args), $sekunden, $env);
}

/** Das erste Objekt einer docker-inspect-Ausgabe, oder null. */
function ax_docker_json($out)
{
    $d = json_decode((string) $out, true);
    return (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : null;
}

/**
 * Ist Docker erreichbar? Rueckgabe array(lage, grund, fassung) mit lage
 * ok | nicht | fehlt | kein_zugriff | dienst_aus | haengt | fehler
 * (Bauart mg_docker_lage, MGiSmart 1.1.23).
 */
function ax_docker_lage($sekunden = 10)
{
    if (DIRECTORY_SEPARATOR === '\\') { return array('nicht', 'NICHT_MOEGLICH', ''); }
    if (ax_docker_bin() === '') { return array('fehlt', 'DOCKER_FEHLT', ''); }
    list($rc, $out, $err) = ax_docker(array('info', '--format', '{{.ServerVersion}}'), $sekunden);
    $fassung = trim($out);
    if ($rc === 0 && preg_match('/^[0-9A-Za-z.+\-~]{1,40}\z/', $fassung)) { return array('ok', '', $fassung); }
    if ($rc === 124) { return array('haengt', 'DOCKER_HAENGT|' . (int) $sekunden, ''); }
    $t = strtolower($err . ' ' . $out);
    if (strpos($t, 'permission denied') !== false) { return array('kein_zugriff', 'DOCKER_KEIN_ZUGRIFF', ''); }
    if (strpos($t, 'cannot connect') !== false || strpos($t, 'daemon running') !== false) { return array('dienst_aus', 'DOCKER_DIENST_AUS', ''); }
    return array('fehler', 'DOCKER_FEHLER|' . (int) $rc, '');
}

/** Die Namen dieses Plugins: Container, Netz, Label, Abbild (Tag aus dem Inhalt der Dockerfile). */
function ax_hue_docker_namen()
{
    $p = ax_paths();
    $o = strtolower($p['plugin']);
    $df = $p['bindir'] . '/hue_docker/Dockerfile';
    $roh = is_file($df) ? (string) @file_get_contents($df) : '';
    return array('container' => 'lb-' . $o . '-hue', 'netz' => 'lb-' . $o . '-macvlan', 'label' => $o . '-hue',
                 'bild' => 'lb-' . $o . '-hue-php:' . ($roh !== '' ? substr(sha1($roh), 0, 12) : 'ohne-dockerfile'),
                 'dockerfile' => $roh !== '' ? $df : '', 'kontext' => dirname($df));
}

/** Traegt dieses Docker-Objekt (inspect eines Containers oder Netzes) BEIDE eigenen Labels? */
function ax_hue_docker_eigen($info)
{
    $p = ax_paths();
    $n = ax_hue_docker_namen();
    $l = array();
    if (is_array($info) && isset($info['Config']['Labels']) && is_array($info['Config']['Labels'])) {
        $l = $info['Config']['Labels'];
    } elseif (is_array($info) && isset($info['Labels']) && is_array($info['Labels'])) {
        $l = $info['Labels'];
    }
    return isset($l['de.loxberry.plugin.folder'], $l['de.loxberry.plugin.name'])
        && $l['de.loxberry.plugin.folder'] === $p['plugin'] && $l['de.loxberry.plugin.name'] === $n['label'];
}

/**
 * Die Kennung der Nachbildung auf eigener Adresse - stabil je Anlage UND je
 * Adresse (eine andere IP ist fuer ein Echo eine andere Bridge). Die MAC ist
 * lokal verwaltet (zweites Bit des ersten Bytes gesetzt), damit sie keinem
 * Hersteller gehoert; der Container bekommt genau diese MAC.
 */
function ax_hue_docker_kennung($ip)
{
    $p = ax_paths();
    $h = sha1('alexang-hue-docker|' . (string) gethostname() . '|' . $p['lbhome'] . '|' . $ip);
    $mac = sprintf('%02x', (hexdec(substr($h, 0, 2)) & 0xFC) | 0x02) . substr($h, 2, 10);
    return array('mac' => $mac, 'bridgeid' => strtoupper(substr($mac, 0, 6) . 'fffe' . substr($mac, 6, 6)),
                 'uuid' => '2f402f80-da50-11e1-9b23-' . $mac, 'lampe' => implode(':', str_split(substr($h, 12, 16), 2)) . '-0b',
                 'nutzer' => substr(sha1('alexang-hue-nutzer|' . $h), 0, 32));
}

/** Benutzer und Gruppe, als die der Container laufen soll: die des Plugins (am Geraet loxberry). */
function ax_hue_docker_benutzer()
{
    if (function_exists('posix_geteuid') && function_exists('posix_getegid')) { return array(posix_geteuid(), posix_getegid()); }
    $p = ax_paths();
    $u = @fileowner($p['datadir']);
    $g = @filegroup($p['datadir']);
    return array($u === false ? -1 : (int) $u, $g === false ? -1 : (int) $g);
}

/**
 * Was angelegt sein soll - aus der Konfiguration und dem Netz der
 * Schnittstelle. Rueckgabe array(ok, grund, def). def['hash'] steht als Label
 * am Container; weicht er ab, legt der Vorgang neu an.
 */
function ax_hue_docker_soll(array $cfg)
{
    if (ax_ipv4_zahl(isset($cfg['hue_ip']) ? $cfg['hue_ip'] : '') === null) { return array(false, 'IP_PFLICHT', null); }
    list($ok, $g, $n) = ax_hue_netz($cfg['hue_schnittstelle']);
    if (!$ok) { return array(false, $g, null); }
    $p = ax_paths();
    $nm = ax_hue_docker_namen();
    list($uid, $gid) = ax_hue_docker_benutzer();
    $k = ax_hue_docker_kennung($cfg['hue_ip']);
    $def = array('container' => $nm['container'], 'netz' => $nm['netz'], 'bild' => $nm['bild'], 'ip' => $cfg['hue_ip'],
                 'mac' => implode(':', str_split($k['mac'], 2)), 'parent' => $n['schnittstelle'],
                 'subnetz' => $n['netzadresse'] . '/' . $n['praefix'], 'gateway' => $n['gateway'], 'uid' => $uid, 'gid' => $gid,
                 'hue' => ax_hue_dockerdir(), 'log' => $p['logdir'], 'skript' => $p['bindir'] . '/ax_hue.php',
                 'helfer' => $p['bindir'] . '/ax_hue_container.php');
    $def['hash'] = substr(sha1((string) json_encode($def)), 0, 16);
    $def['kennung'] = $k;
    return array(true, '', $def);
}

/** Die Adresse des Wirts am Standard-Bridge-Netz (dort erreicht der Container den Broker), oder ''. */
function ax_hue_docker_wirt()
{
    list($rc, $out, ) = ax_docker(array('network', 'inspect', 'bridge'), 15);
    $d = $rc === 0 ? ax_docker_json($out) : null;
    if (is_array($d) && isset($d['IPAM']['Config']) && is_array($d['IPAM']['Config'])) {
        foreach ($d['IPAM']['Config'] as $c) {
            if (is_array($c) && isset($c['Gateway']) && ax_ipv4_zahl($c['Gateway']) !== null) { return $c['Gateway']; }
        }
    }
    return '';
}

/**
 * Die Konfiguration des Containers schreiben (data/hue/hue_container.json,
 * 0600): eigene IP, Kennung, MQTT. Der Broker steht in general.json meist als
 * localhost - fuer den Container ist das der Wirt am Bridge-Netz. Geschrieben
 * wird nur, wenn sich etwas aendert. Rueckgabe true = die Datei gilt.
 */
function ax_hue_docker_datei(array $cfg, array $def, $wirt)
{
    $b = ax_broker();
    $host = $b['host'];
    if (in_array(strtolower($host), array('localhost', '127.0.0.1', '::1', 'ip6-localhost'), true)) {
        if ($wirt === '') { return false; }
        $host = $wirt;
    }
    $k = $def['kennung'];
    $d = array('_hinweis' => 'Alexa NG - vom Plugin geschrieben, im Container der Hue-Probe gelesen. Enthaelt den Broker-Zugang.',
               'ip' => $def['ip'], 'port' => 80, 'mac' => $k['mac'], 'bridgeid' => $k['bridgeid'], 'uuid' => $k['uuid'],
               'lampe' => $k['lampe'], 'nutzer' => $k['nutzer'], 'mqtt_ein' => empty($cfg['mqtt_ein']) ? 0 : 1,
               'mqtt_praefix' => $cfg['mqtt_praefix'], 'mqtt_host' => $host, 'mqtt_port' => (int) $b['port'],
               'mqtt_user' => $b['user'], 'mqtt_pass' => $b['pass'],
               // alexa6: Fassung 2 und B2 (die Zeitzone, in der dieses Plugin protokolliert)
               'zeitzone' => ax_hue_zeitzone(), 'netz' => $def['subnetz'], 'wirt' => (string) $wirt,
               'probe_lampe' => empty($cfg['hue_probe_lampe']) ? 0 : 1, 'lampen' => ax_hue_lampen_kurz($cfg),
               'echos' => (isset($cfg['hue_echos']) && is_array($cfg['hue_echos'])) ? array_values($cfg['hue_echos']) : array());
    $dir = ax_hue_dockerdir();
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    if (!is_dir($dir)) { return false; }
    $f = $dir . '/hue_container.json';
    if (ax_json_lesen($f) === $d) { return true; }
    return ax_write_json($f, $d, 0600);
}

/** Merker "angelegt" neben dem Messstand (data/hue_docker.json): sagt dem Takt, ob er Docker fragen muss. */
function ax_hue_docker_merk()
{
    $p = ax_paths();
    $d = ax_json_lesen($p['datadir'] . '/hue_docker.json');
    $d = is_array($d) ? $d : array();
    return array(
        'angelegt' => (isset($d['angelegt']) && $d['angelegt'] === 1) ? 1 : 0,
        'id' => (isset($d['id']) && is_string($d['id']) && preg_match('/^[0-9a-f]{12,64}\z/', $d['id'])) ? $d['id'] : '',
        'def' => (isset($d['def']) && is_string($d['def']) && preg_match('/^[0-9a-f]{16}\z/', $d['def'])) ? $d['def'] : '',
        'ip' => (isset($d['ip']) && ax_ipv4_zahl($d['ip']) !== null) ? $d['ip'] : '',
        'wirt' => (isset($d['wirt']) && ax_ipv4_zahl($d['wirt']) !== null) ? $d['wirt'] : '',
        'bild' => (isset($d['bild']) && is_string($d['bild']) && preg_match('#^[a-z0-9._\-]{1,80}:[A-Za-z0-9._\-]{1,40}\z#', $d['bild'])) ? $d['bild'] : '',
        'zeit' => (isset($d['zeit']) && is_int($d['zeit'])) ? $d['zeit'] : 0,
    );
}

function ax_hue_docker_merk_schreiben(array $m)
{
    $p = ax_paths();
    $m['zeit'] = time();
    return ax_write_json($p['datadir'] . '/hue_docker.json', $m, 0600);
}

/** Kurzfassung eines Containers aus docker inspect. */
function ax_hue_docker_kurz(array $info)
{
    $nm = ax_hue_docker_namen();
    $s = (isset($info['State']) && is_array($info['State'])) ? $info['State'] : array();
    $l = (isset($info['Config']['Labels']) && is_array($info['Config']['Labels'])) ? $info['Config']['Labels'] : array();
    $netze = (isset($info['NetworkSettings']['Networks']) && is_array($info['NetworkSettings']['Networks'])) ? $info['NetworkSettings']['Networks'] : array();
    $ip = function ($n) use ($netze) {
        return (isset($netze[$n]['IPAddress']) && ax_ipv4_zahl($netze[$n]['IPAddress']) !== null) ? $netze[$n]['IPAddress'] : '';
    };
    $start = isset($s['StartedAt']) && is_string($s['StartedAt']) ? strtotime($s['StartedAt']) : false;
    return array(
        'id' => (isset($info['Id']) && is_string($info['Id'])) ? substr($info['Id'], 0, 12) : '',
        'laeuft' => !empty($s['Running']) && empty($s['Restarting']),
        'status' => (isset($s['Status']) && is_string($s['Status'])) ? ax_kurztext($s['Status'], 40) : '',
        'fehler' => (isset($s['Error']) && is_string($s['Error'])) ? ax_kurztext($s['Error'], 160) : '',
        'neustarts' => isset($info['RestartCount']) ? (int) $info['RestartCount'] : 0,
        'seit' => ($start !== false && $start > 0) ? max(0, time() - $start) : -1,
        'def' => (isset($l['de.loxberry.plugin.def']) && is_string($l['de.loxberry.plugin.def'])) ? $l['de.loxberry.plugin.def'] : '',
        'bild' => (isset($info['Config']['Image']) && is_string($info['Config']['Image'])) ? $info['Config']['Image'] : '',
        'ip' => $ip($nm['netz']),
        'mac' => (isset($netze[$nm['netz']]['MacAddress']) && is_string($netze[$nm['netz']]['MacAddress'])
                  && preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}\z/', $netze[$nm['netz']]['MacAddress'])) ? $netze[$nm['netz']]['MacAddress'] : '',
        'ip_bruecke' => $ip('bridge'),
    );
}

/**
 * Der eigene Container. Rueckgabe array(gefunden, eigen, kurz): gefunden ist
 * null, wenn Docker nicht zu fragen war; eigen false bei einem fremden
 * Container gleichen Namens (der bleibt unberuehrt).
 */
function ax_hue_docker_container($sekunden = 15)
{
    $nm = ax_hue_docker_namen();
    list($rc, $out, $err) = ax_docker(array('inspect', '--type', 'container', $nm['container']), $sekunden);
    if ($rc !== 0) {
        return (stripos($err . $out, 'no such') !== false) ? array(false, false, null) : array(null, false, null);
    }
    $info = ax_docker_json($out);
    if ($info === null) { return array(null, false, null); }
    return array(true, ax_hue_docker_eigen($info), ax_hue_docker_kurz($info));
}

/** Einen eigenen Container anhalten und entfernen - nachgesehen. Rueckgabe array(ok, grund, text). */
function ax_hue_docker_weg($ref)
{
    ax_docker(array('stop', '-t', '10', $ref), 40);
    list($rc, , $err) = ax_docker(array('rm', '-f', $ref), 30);
    list($rc2, , $err2) = ax_docker(array('inspect', '--type', 'container', $ref), 15);
    if ($rc2 === 0) { return array(false, 'CONTAINER_ENTFERNEN|' . $rc, ax_letzte_zeile($err)); }
    if (stripos($err2, 'no such') === false) { return array(false, 'DOCKER_FEHLER|' . $rc2, ax_letzte_zeile($err2)); }
    return array(true, '', '');
}

/**
 * Den Container anlegen - vom Hintergrundvorgang gerufen; $schritt($name)
 * schreibt den Stand. Rueckgabe array(ok, grund, text, ergebnis).
 * Reihenfolge: Docker erreichbar -> Netz der Schnittstelle und eigene IP ->
 * fremder Container gleichen Namens? -> Konfiguration des Containers ->
 * unveraendert und laeuft: fertig -> IP belegt? -> Abbild (bauen, wenn es
 * fehlt) -> Netz (das eigene benutzen oder anlegen) -> alten Container
 * entfernen -> anlegen, an die Bruecke haengen, starten -> nachsehen.
 */
function ax_hue_docker_anlegen($schritt)
{
    $cfg = ax_config();
    if (!ax_hue_soll_docker($cfg)) { return array(true, '', '', 'NICHT_EINGESCHALTET'); }
    $p = ax_paths();
    $nm = ax_hue_docker_namen();
    $schritt('docker');
    list($lage, $g) = ax_docker_lage();
    if ($lage !== 'ok') { return array(false, $g, '', 'DOCKER'); }
    $schritt('ip');
    list($ok, $g, $def) = ax_hue_docker_soll($cfg);
    if (!$ok) { return array(false, $g, '', 'IP'); }
    if ($def['uid'] <= 0 || $def['gid'] < 0) { return array(false, 'ALS_ROOT', '', 'IP'); }
    list($da, $eigen, $kurz) = ax_hue_docker_container();
    if ($da === null) { return array(false, 'DOCKER_FEHLER|0', '', 'CONTAINER'); }
    if ($da && !$eigen) { return array(false, 'CONTAINER_FREMD|' . $def['container'], '', 'CONTAINER'); }
    $wirt = ax_hue_docker_wirt();
    if ($wirt === '') { return array(false, 'WIRT_UNBEKANNT', '', 'NETZ'); }
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    if (!ax_hue_docker_datei($cfg, $def, $wirt)) { return array(false, 'SCHREIBEN', ax_hue_dockerdir() . '/hue_container.json', 'DATEI'); }
    $merk = array('angelegt' => 0, 'id' => '', 'def' => '', 'ip' => $def['ip'], 'wirt' => $wirt, 'bild' => $def['bild']);
    if ($da && $kurz['def'] === $def['hash'] && $kurz['laeuft']) {
        ax_hue_docker_merk_schreiben(array('angelegt' => 1, 'id' => $kurz['id'], 'def' => $def['hash']) + $merk);
        return array(true, '', '', 'UNVERAENDERT');
    }
    // Belegt? Nur wenn nicht schon unser Container die Adresse traegt (vom
    // LoxBerry aus ist er ohnehin nicht zu sehen - macvlan).
    if (!($da && $kurz['ip'] === $def['ip']) && ax_hue_ip_belegt($def['ip'], $def['parent'])) {
        return array(false, 'IP_BELEGT|' . $def['ip'], '', 'IP');
    }
    if ($nm['dockerfile'] === '') { return array(false, 'DOCKERFILE_FEHLT', '', 'ABBILD'); }

    $schritt('abbild');
    list($rc, , ) = ax_docker(array('image', 'inspect', $def['bild']), 30);
    if ($rc !== 0) {
        ax_log('INFO', 'Hue-Probe (eigene Netzadresse): Abbild ' . $def['bild'] . ' fehlt - wird gebaut (php:8.4-cli mit sockets und pcntl, beim ersten Mal einige Minuten).');
        $bau = array('build', '-t', $def['bild'], '--label', 'de.loxberry.plugin.folder=' . $p['plugin'],
                     '--label', 'de.loxberry.plugin.name=' . $nm['label'], $nm['kontext']);
        list($rc, $out, $err) = ax_docker($bau, AX_HUE_D_BAU_S);
        if ($rc !== 0 && preg_match('/buildx|buildkit/i', $err . ' ' . $out)) {
            // Docker ohne buildx: einmal auf dem alten Bauweg (DOCKER_BUILDKIT=0, nur fuer diesen Aufruf).
            $env = getenv();
            $env = is_array($env) ? $env : array();
            $env['DOCKER_BUILDKIT'] = '0';
            ax_log('INFO', 'Hue-Probe (eigene Netzadresse): docker build ohne buildx - zweiter Versuch mit DOCKER_BUILDKIT=0.');
            list($rc, $out, $err) = ax_docker($bau, AX_HUE_D_BAU_S, $env);
        }
        if ($rc !== 0) { return array(false, 'ABBILD_BAUEN|' . $rc, ax_letzte_zeile($err . "\n" . $out), 'ABBILD'); }
    }

    $schritt('netz');
    list($rc, $out, $err) = ax_docker(array('network', 'inspect', $def['netz']), 15);
    $netz = $rc === 0 ? ax_docker_json($out) : null;
    if ($rc !== 0 && stripos($err, 'not found') === false && stripos($err, 'no such') === false) {
        return array(false, 'DOCKER_FEHLER|' . $rc, ax_letzte_zeile($err), 'NETZ');
    }
    if ($netz !== null) {
        if (!ax_hue_docker_eigen($netz)) { return array(false, 'NETZ_FREMD|' . $def['netz'], '', 'NETZ'); }
        $c0 = (isset($netz['IPAM']['Config'][0]) && is_array($netz['IPAM']['Config'][0])) ? $netz['IPAM']['Config'][0] : array();
        $passt = isset($netz['Driver'], $netz['Options']['parent'], $c0['Subnet'], $c0['Gateway']) && $netz['Driver'] === 'macvlan'
            && $netz['Options']['parent'] === $def['parent'] && $c0['Subnet'] === $def['subnetz'] && $c0['Gateway'] === $def['gateway'];
        if (!$passt) {
            // Das eigene Netz passt nicht mehr (andere Schnittstelle oder anderes Heimnetz): erst der Container, dann das Netz.
            if ($da) {
                list($ok, $g, $t) = ax_hue_docker_weg($nm['container']);
                if (!$ok) { return array(false, $g, $t, 'CONTAINER'); }
                $da = false;
            }
            list($rc, , $err) = ax_docker(array('network', 'rm', $def['netz']), 30);
            if ($rc !== 0) { return array(false, 'NETZ_ENTFERNEN|' . $rc, ax_letzte_zeile($err), 'NETZ'); }
            $netz = null;
        }
    }
    if ($netz === null) {
        list($rc, , $err) = ax_docker(array('network', 'create', '-d', 'macvlan', '--subnet', $def['subnetz'], '--gateway', $def['gateway'],
                                            '-o', 'parent=' . $def['parent'], '--label', 'de.loxberry.plugin.folder=' . $p['plugin'],
                                            '--label', 'de.loxberry.plugin.name=' . $nm['label'], $def['netz']), 30);
        if ($rc !== 0) { return array(false, 'NETZ_ANLEGEN|' . $rc, ax_letzte_zeile($err), 'NETZ'); }
    }

    $schritt('container');
    if ($da) {
        list($ok, $g, $t) = ax_hue_docker_weg($nm['container']);
        if (!$ok) { return array(false, $g, $t, 'CONTAINER'); }
    }
    $anlegen = array('create', '--name', $def['container'],
        '--label', 'de.loxberry.plugin.folder=' . $p['plugin'], '--label', 'de.loxberry.plugin.name=' . $nm['label'],
        '--label', 'de.loxberry.plugin.def=' . $def['hash'],
        '--restart', 'unless-stopped', '--user', $def['uid'] . ':' . $def['gid'],
        '--network', $def['netz'], '--ip', $def['ip'], '--mac-address', $def['mac'],
        '--sysctl', 'net.ipv4.ip_unprivileged_port_start=80',
        '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--read-only',
        '-v', $def['hue'] . ':/hue', '-v', $def['log'] . ':/log',
        '-v', $def['skript'] . ':/app/ax_hue.php:ro', '-v', $def['helfer'] . ':/app/ax_hue_container.php:ro',
        $def['bild'], 'php', '/app/ax_hue.php', '--container=/hue', '--log=/log');
    list($rc, $out, $err) = ax_docker($anlegen, 60);
    $id = trim($out);
    if ($rc !== 0 || !preg_match('/^[0-9a-f]{64}\z/', $id)) { return array(false, 'CONTAINER_ANLEGEN|' . $rc, ax_letzte_zeile($err), 'CONTAINER'); }
    ax_hue_docker_merk_schreiben(array('angelegt' => 1, 'id' => $id, 'def' => '') + $merk);
    // Zweites Netz: die Bruecke zum Wirt (Broker). Ohne sie kein MQTT - dann nicht starten.
    list($rc, , $err) = ax_docker(array('network', 'connect', 'bridge', $id), 30);
    if ($rc !== 0) {
        $t = ax_letzte_zeile($err);
        ax_hue_docker_weg($id);
        return array(false, 'CONTAINER_ANLEGEN|' . $rc, 'bridge: ' . $t, 'CONTAINER');
    }
    $t0 = time() - 1;
    list($rc, , $err) = ax_docker(array('start', $id), 60);
    if ($rc !== 0) { return array(false, 'CONTAINER_START|' . $rc, ax_letzte_zeile($err), 'CONTAINER'); }
    ax_hue_docker_merk_schreiben(array('angelegt' => 1, 'id' => substr($id, 0, 12), 'def' => $def['hash']) + $merk);

    // Nachsehen: laeuft er, und hat die Nachbildung ihre Sockel offen (Messstand
    // mit neuem Start und ohne Grund)? Hoechstens 15 s.
    $schritt('nachsehen');
    $k2 = null;
    for ($i = 0; $i < 30; $i++) {
        usleep(500000);
        $z = ax_hue_lesen('docker');
        list(, , $k2) = ax_hue_docker_container();
        if ($z['start'] >= $t0 && $z['pid'] > 0) {
            if ($z['fehler'] !== '') { return array(false, $z['fehler'], $k2 ? $k2['fehler'] : '', 'NACHSEHEN'); }
            if ($k2 && $k2['laeuft']) {
                ax_hue_docker_abbilder_raeumen($def['bild']);   // B4 (alexa6): nur eigene, nie das aktuelle
                return array(true, '', '', 'ANGELEGT');
            }
        }
    }
    return array(false, 'CONTAINER_START|0', $k2 ? trim($k2['status'] . ' ' . $k2['fehler']) : '', 'NACHSEHEN');
}

/**
 * Container und Netz dieses Plugins entfernen - nachgesehen (Vorgang,
 * Deinstallation, preupgrade). Das Abbild bleibt (es kostet nur Platz und
 * spart beim naechsten Mal das Bauen); der Befehl dazu steht in der Meldung.
 * Rueckgabe array(ok, grund, text, ergebnis) - ergebnis ENTFERNT|<c>|<n>,
 * NICHTS oder KEIN_DOCKER.
 */
function ax_hue_docker_entfernen($schritt)
{
    $schritt('docker');
    $m = ax_hue_docker_merk();
    $nm = ax_hue_docker_namen();
    list($lage, $g) = ax_docker_lage();
    if ($lage === 'fehlt' && empty($m['angelegt'])) { return array(true, '', '', 'KEIN_DOCKER'); }
    if ($lage !== 'ok') { return array(false, $g, '', 'DOCKER'); }
    $schritt('container');
    $weg_c = 0;
    list($rc, $out, $err) = ax_docker(array('ps', '-a', '-q', '--no-trunc', '--filter', 'label=de.loxberry.plugin.name=' . $nm['label']), 20);
    if ($rc !== 0) { return array(false, 'DOCKER_FEHLER|' . $rc, ax_letzte_zeile($err), 'CONTAINER'); }
    foreach (preg_split('/\s+/', trim($out)) as $id) {
        if (!preg_match('/^[0-9a-f]{12,64}\z/', $id)) { continue; }
        list($rc, $o2, ) = ax_docker(array('inspect', '--type', 'container', $id), 15);
        $info = $rc === 0 ? ax_docker_json($o2) : null;
        if ($info === null || !ax_hue_docker_eigen($info)) { continue; }
        list($ok, $g, $t) = ax_hue_docker_weg($id);
        if (!$ok) { return array(false, $g, $t, 'CONTAINER'); }
        $weg_c++;
    }
    $schritt('netz');
    $weg_n = 0;
    list($rc, $out, $err) = ax_docker(array('network', 'inspect', $nm['netz']), 15);
    $netz = $rc === 0 ? ax_docker_json($out) : null;
    if ($rc !== 0 && stripos($err, 'not found') === false && stripos($err, 'no such') === false) {
        return array(false, 'DOCKER_FEHLER|' . $rc, ax_letzte_zeile($err), 'NETZ');
    }
    if ($netz !== null && ax_hue_docker_eigen($netz)) {
        list($rc, , $err) = ax_docker(array('network', 'rm', $nm['netz']), 30);
        list($rc2, , ) = ax_docker(array('network', 'inspect', $nm['netz']), 15);
        if ($rc2 === 0) { return array(false, 'NETZ_ENTFERNEN|' . $rc, ax_letzte_zeile($err), 'NETZ'); }
        $weg_n = 1;
    }
    ax_hue_docker_merk_schreiben(array('angelegt' => 0, 'id' => '', 'def' => '', 'ip' => '', 'wirt' => $m['wirt'],
                                       'bild' => $m['bild'] !== '' ? $m['bild'] : $nm['bild']));
    return array(true, '', '', ($weg_c + $weg_n) > 0 ? 'ENTFERNT|' . $weg_c . '|' . $weg_n : 'NICHTS');
}

/* ---------------- Der Hintergrundvorgang (bin/hue_vorgang.php) ---------------- */

function ax_hue_vorgang_programm()
{
    $p = ax_paths();
    return $p['bindir'] . '/hue_vorgang.php';
}

function ax_hue_vorgang_schreiben(array $d)
{
    $p = ax_paths();
    return ax_write_json($p['datadir'] . '/hue_vorgang.json', $d, 0644);
}

/** Laeuft unter dieser Nummer der eigene Vorgang? Argumentweise: argv[1] ist genau unser Skript. */
function ax_hue_vorgang_prozess($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0 || DIRECTORY_SEPARATOR === '\\') { return false; }
    $c = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($c === false || $c === '') { return false; }
    $a = explode("\0", $c);
    $soll = ax_hue_vorgang_programm();
    return isset($a[1]) && ($a[1] === $soll || (@realpath($a[1]) !== false && @realpath($a[1]) === @realpath($soll)));
}

/** Der Stand des Vorgangs, jeder Wert geprueft. Zustand "abgebrochen", wenn der Prozess nicht mehr lebt. */
function ax_hue_vorgang()
{
    $p = ax_paths();
    $d = ax_json_lesen($p['datadir'] . '/hue_vorgang.json');
    $d = is_array($d) ? $d : array();
    $wahl = function ($k, array $liste) use ($d) { return (isset($d[$k]) && in_array($d[$k], $liste, true)) ? $d[$k] : ''; };
    $zahl = function ($k) use ($d) { return (isset($d[$k]) && is_int($d[$k]) && $d[$k] >= 0) ? $d[$k] : 0; };
    $v = array(
        'vorgang' => $wahl('vorgang', array('anlegen', 'entfernen')),
        'zustand' => $wahl('zustand', array('gestartet', 'laeuft', 'fertig', 'fehler')),
        'pid' => $zahl('pid'), 'start' => $zahl('start'), 'ende' => $zahl('ende'),
        'schritt' => $wahl('schritt', array('beginn', 'docker', 'ip', 'abbild', 'netz', 'container', 'nachsehen')),
        'grund' => (isset($d['grund']) && is_string($d['grund']) && preg_match('#^[A-Z_]{1,30}(\|[A-Za-z0-9_.:/\-]{1,64}){0,3}\z#', $d['grund'])) ? $d['grund'] : '',
        'text' => (isset($d['text']) && is_string($d['text'])) ? ax_kurztext($d['text'], 200) : '',
        'ergebnis' => (isset($d['ergebnis']) && is_string($d['ergebnis']) && preg_match('/^[A-Z_]{1,30}(\|[0-9]{1,3}){0,2}\z/', $d['ergebnis'])) ? $d['ergebnis'] : '',
    );
    if ($v['zustand'] === 'laeuft' && !ax_hue_vorgang_prozess($v['pid'])) { $v['zustand'] = 'abgebrochen'; }
    if ($v['zustand'] === 'gestartet' && (time() - $v['start'] > 60 || $v['start'] > time() + 60)) { $v['zustand'] = 'abgebrochen'; }
    return $v;
}

/**
 * Den Vorgang im Hintergrund starten (Bauart mg_gw_vorgang_starten). Ein
 * laufender Vorgang verhindert jeden zweiten. Rueckgabe array(ok, grund).
 */
function ax_hue_vorgang_starten($auftrag)
{
    if (!in_array($auftrag, array('anlegen', 'entfernen'), true)) { return array(false, 'VORGANG'); }
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('proc_open')) { return array(false, 'NICHT_MOEGLICH'); }
    $v = ax_hue_vorgang();
    if (in_array($v['zustand'], array('gestartet', 'laeuft'), true)) { return array(false, 'VORGANG_LAEUFT|' . $v['vorgang']); }
    $prog = ax_hue_vorgang_programm();
    if (!is_file($prog)) { return array(false, 'VORGANG'); }
    if (!ax_hue_vorgang_schreiben(array('vorgang' => $auftrag, 'zustand' => 'gestartet', 'pid' => 0, 'start' => time(), 'ende' => 0,
                                        'schritt' => '', 'grund' => '', 'text' => '', 'ergebnis' => ''))) {
        return array(false, 'SCHREIBEN');
    }
    $desk = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w'));
    $pipes = array();
    // setsid loest den Vorgang von Apache; "&" laesst die Schale sofort enden.
    $proc = @proc_open(array('sh', '-c', 'setsid "$0" "$@" </dev/null >/dev/null 2>&1 &', 'php', $prog, $auftrag), $desk, $pipes);
    if (!is_resource($proc)) { return array(false, 'VORGANG'); }
    proc_close($proc);
    return array(true, '');
}

/** Der Satz zu einem beendeten Vorgang (fuer Meldung und Reiter Test). */
function ax_hue_vorgang_satz(array $v)
{
    $nm = ax_hue_docker_namen();
    $m = ax_hue_docker_merk();
    $cfg = ax_config();
    if ($v['zustand'] === 'fertig') {
        $e = explode('|', $v['ergebnis']);
        if ($e[0] === 'ANGELEGT') { return sprintf(ax_t('MELDUNG.HUE_D_GESTARTET'), $cfg['hue_ip'], $nm['container']); }
        if ($e[0] === 'UNVERAENDERT') { return sprintf(ax_t('MELDUNG.HUE_D_UNVERAENDERT'), $cfg['hue_ip'], $nm['container']); }
        if ($e[0] === 'ENTFERNT') { return sprintf(ax_t('MELDUNG.HUE_D_ENTFERNT'), $nm['container'], $nm['netz'], $m['bild'] !== '' ? $m['bild'] : $nm['bild']); }
        if ($e[0] === 'NICHTS' || $e[0] === 'KEIN_DOCKER') { return ax_t('MELDUNG.HUE_D_NICHTS'); }
        return '';
    }
    if ($v['zustand'] === 'abgebrochen') { return sprintf(ax_t('MELDUNG.HUE_D_FEHL'), ax_t('MELDUNG.HUE_D_ABGEBROCHEN')); }
    return sprintf(ax_t('MELDUNG.HUE_D_FEHL'), ax_grund_text($v['grund']) . ($v['text'] !== '' ? ' (' . $v['text'] . ')' : ''));
}

/**
 * Den Vorgang starten und kurz warten: ohne Bauen ist er in Sekunden fertig -
 * dann wird gesagt, was nachgesehen wurde. Baut er das Abbild, sagt die
 * Meldung, dass er im Hintergrund weiterlaeuft.
 */
function ax_hue_vorgang_melden($auftrag)
{
    list($ok, $g) = ax_hue_vorgang_starten($auftrag);
    if (!$ok) { return sprintf(ax_t('MELDUNG.HUE_D_FEHL'), ax_grund_text($g)); }
    $ende = microtime(true) + AX_HUE_D_WARTE_S;
    $bauen_seit = 0.0;
    while (microtime(true) < $ende) {
        usleep(250000);
        $v = ax_hue_vorgang();
        if (in_array($v['zustand'], array('fertig', 'fehler', 'abgebrochen'), true)) { return ax_hue_vorgang_satz($v); }
        if ($v['schritt'] === 'abbild') {
            if ($bauen_seit === 0.0) { $bauen_seit = microtime(true); }
            if (microtime(true) - $bauen_seit > 3) { break; }
        }
    }
    return sprintf(ax_t('MELDUNG.HUE_D_LAEUFT'), $auftrag === 'anlegen' ? ax_t('MELDUNG.HUE_D_V_ANLEGEN') : ax_t('MELDUNG.HUE_D_V_ENTFERNEN'));
}

/**
 * Nach Speichern und Zurueckspielen: den Container nachziehen. Docker wird
 * nur gefragt, wenn die Art "docker" eingeschaltet ist oder war oder ein
 * Container angelegt ist - ab Werk und bei Art "loxberry" nie. Rueckgabe:
 * Satz fuer die Meldung ('' = nichts zu tun).
 */
function ax_hue_docker_nachziehen(array $neu, array $alt)
{
    if (DIRECTORY_SEPARATOR === '\\') { return ''; }
    $soll = ax_hue_soll_docker($neu);
    $m = ax_hue_docker_merk();
    if (!$soll && !ax_hue_soll_docker($alt) && empty($m['angelegt'])) { return ''; }
    if ($soll) {
        list($ok, $g, $def) = ax_hue_docker_soll($neu);
        if (!$ok) { return sprintf(ax_t('MELDUNG.HUE_D_FEHL'), ax_grund_text($g)); }
        if (!empty($m['angelegt']) && $m['def'] === $def['hash'] && ax_hue_soll_docker($alt)) {
            // Unveraendert angelegt: nur die Konfiguration des Containers frisch (MQTT); ob er laeuft, sieht der Takt nach.
            ax_hue_docker_datei($neu, $def, $m['wirt']);
            return '';
        }
        return ax_hue_vorgang_melden('anlegen');
    }
    return ax_hue_vorgang_melden('entfernen');
}

/** Nach dem MQTT-Speichern: nur die Konfiguration des Containers nachziehen (kein Docker-Aufruf). */
function ax_hue_docker_datei_nachziehen(array $cfg)
{
    $m = ax_hue_docker_merk();
    if (!ax_hue_soll_docker($cfg) || empty($m['angelegt']) || $m['wirt'] === '') { return false; }
    list($ok, , $def) = ax_hue_docker_soll($cfg);
    return $ok ? ax_hue_docker_datei($cfg, $def, $m['wirt']) : false;
}

/**
 * Fuer den Takt: was ist zu tun? '' nichts, 'anlegen' oder 'entfernen'.
 * Docker wird nur gefragt, wenn die Art "docker" eingeschaltet ist; sonst
 * entscheidet der Merker (ein angelegter Container wird entfernt).
 */
function ax_hue_docker_takt_auftrag(array $cfg)
{
    $m = ax_hue_docker_merk();
    if (!ax_hue_soll_docker($cfg)) { return !empty($m['angelegt']) ? 'entfernen' : ''; }
    list($da, $eigen, $k) = ax_hue_docker_container();
    if ($da && !$eigen) { return ''; }         // fremder Container gleichen Namens: nie anfassen, der Reiter Test sagt es
    if ($da === null || !$da || !$k['laeuft']) { return 'anlegen'; }
    list($ok, , $def) = ax_hue_docker_soll($cfg);
    if ($ok && $k['def'] !== $def['hash']) { return 'anlegen'; }
    if ($ok && $m['wirt'] !== '') { ax_hue_docker_datei($cfg, $def, $m['wirt']); }
    return '';
}

/** Eine Zeile "letzter Vorgang" fuer den Reiter Test. */
function ax_hue_vorgang_zeile(array $v)
{
    if ($v['vorgang'] === '' || $v['zustand'] === '') { return ax_t('TEST.A_HUE_D_VORGANG_KEINER'); }
    $art = ax_hue_vorgang_wort($v['vorgang']);
    if (in_array($v['zustand'], array('gestartet', 'laeuft'), true)) {
        // B1 (alexa6): "seit 33 s (Schritt: Abbild)", nicht "seit vor 33 s (Schritt abbild)"
        return sprintf(ax_t('TEST.A_HUE_D_VORGANG_LAEUFT'), $art, ax_spanne_text(max(0, time() - $v['start'])), ax_hue_schritt_wort($v['schritt']));
    }
    if ($v['zustand'] === 'abgebrochen') { return sprintf(ax_t('TEST.A_HUE_D_VORGANG_ABGEBROCHEN'), $art); }
    $vor = ax_spanne_text(max(0, time() - ($v['ende'] > 0 ? $v['ende'] : $v['start'])));
    if ($v['zustand'] === 'fertig') { return sprintf(ax_t('TEST.A_HUE_D_VORGANG_FERTIG'), $art, $vor, ax_hue_vorgang_satz($v)); }
    return sprintf(ax_t('TEST.A_HUE_D_VORGANG_FEHLER'), $art, $vor,
                   ax_grund_text($v['grund']) . ($v['text'] !== '' ? ' (' . $v['text'] . ')' : ''));
}

/**
 * Lage der Hue-Probe auf eigener Netzadresse fuer den Reiter Test. Docker wird
 * nur gefragt, wenn $fragen (Reiter Test offen). Rueckgabe array(stand, satz,
 * zeilen, kurz) - stand wie im Reiter Test (1 ja, 0 nein, -1 nicht
 * feststellbar, 2 Hinweis); zeilen fuer den Abschnitt Hue-Probe.
 */
function ax_hue_docker_befund(array $cfg, $fragen)
{
    $nm = ax_hue_docker_namen();
    $v = ax_hue_vorgang();
    $zeilen = array(
        array(ax_t('TEST.F_HUE_D_ADRESSE'), sprintf(ax_t('TEST.A_HUE_D_ADRESSE'), $cfg['hue_ip'], $cfg['hue_schnittstelle'], $nm['container'], $nm['netz'])),
        array(ax_t('TEST.F_HUE_D_VORGANG'), ax_hue_vorgang_zeile($v)),
    );
    if (DIRECTORY_SEPARATOR === '\\') { return array(-1, ax_t('TEST.A_HUE_NICHT'), $zeilen, null); }
    if (in_array($v['zustand'], array('gestartet', 'laeuft'), true)) { return array(2, ax_hue_vorgang_zeile($v), $zeilen, null); }
    if (!$fragen) { return array(-1, ax_t('TEST.A_HUE_D_NUR_REITER'), $zeilen, null); }
    list($lage, $g, $fassung) = ax_docker_lage();
    if ($lage !== 'ok') {
        $zeilen[] = array(ax_t('TEST.F_HUE_D_DOCKER'), ax_grund_text($g));
        return array(0, sprintf(ax_t('TEST.A_HUE_STEHT'), ax_grund_text($g)), $zeilen, null);
    }
    $zeilen[] = array(ax_t('TEST.F_HUE_D_DOCKER'), sprintf(ax_t('TEST.A_HUE_D_DOCKER'), $fassung));
    list($rc, , ) = ax_docker(array('image', 'inspect', $nm['bild']), 15);
    $zeilen[] = array(ax_t('TEST.F_HUE_D_ABBILD'), $rc === 0 ? sprintf(ax_t('TEST.A_HUE_D_ABBILD_DA'), $nm['bild'])
                                                           : sprintf(ax_t('TEST.A_HUE_D_ABBILD_FEHLT'), $nm['bild']));
    list($rc, $out, ) = ax_docker(array('network', 'inspect', $nm['netz']), 15);
    $netz = $rc === 0 ? ax_docker_json($out) : null;
    if ($netz === null) {
        $zeilen[] = array(ax_t('TEST.F_HUE_D_NETZ'), sprintf(ax_t('TEST.A_HUE_D_NETZ_FEHLT'), $nm['netz']));
    } elseif (!ax_hue_docker_eigen($netz)) {
        $zeilen[] = array(ax_t('TEST.F_HUE_D_NETZ'), ax_grund_text('NETZ_FREMD|' . $nm['netz']));
    } else {
        $c0 = (isset($netz['IPAM']['Config'][0]) && is_array($netz['IPAM']['Config'][0])) ? $netz['IPAM']['Config'][0] : array();
        $zeilen[] = array(ax_t('TEST.F_HUE_D_NETZ'), sprintf(ax_t('TEST.A_HUE_D_NETZ'), $nm['netz'],
            isset($netz['Options']['parent']) && is_string($netz['Options']['parent']) ? ax_kurztext($netz['Options']['parent'], 15) : '–',
            isset($c0['Subnet']) && is_string($c0['Subnet']) ? ax_kurztext($c0['Subnet'], 20) : '–',
            isset($c0['Gateway']) && is_string($c0['Gateway']) ? ax_kurztext($c0['Gateway'], 15) : '–'));
    }
    list($da, $eigen, $k) = ax_hue_docker_container();
    $hz = ax_hue_lesen('docker');
    if ($da === null) { return array(0, sprintf(ax_t('TEST.A_HUE_STEHT'), ax_grund_text('DOCKER_FEHLER|0')), $zeilen, null); }
    if (!$da) {
        $zeilen[] = array(ax_t('TEST.F_HUE_D_CONTAINER'), sprintf(ax_t('TEST.A_HUE_D_CONTAINER_FEHLT'), $nm['container']));
        $warum = ($v['zustand'] === 'fehler' && $v['grund'] !== '') ? ax_grund_text($v['grund']) : ax_t('TEST.A_HUE_D_OHNE_CONTAINER');
        return array(0, sprintf(ax_t('TEST.A_HUE_STEHT'), $warum), $zeilen, null);
    }
    if (!$eigen) {
        $zeilen[] = array(ax_t('TEST.F_HUE_D_CONTAINER'), ax_grund_text('CONTAINER_FREMD|' . $nm['container']));
        return array(0, sprintf(ax_t('TEST.A_HUE_STEHT'), ax_grund_text('CONTAINER_FREMD|' . $nm['container'])), $zeilen, null);
    }
    $zeilen[] = array(ax_t('TEST.F_HUE_D_CONTAINER'), sprintf(ax_t('TEST.A_HUE_D_CONTAINER'), $k['id'], $k['status'], $k['neustarts'],
        $k['ip'] !== '' ? $k['ip'] : '–', $k['mac'] !== '' ? $k['mac'] : '–', $k['ip_bruecke'] !== '' ? $k['ip_bruecke'] : '–'));
    if (!$k['laeuft']) {
        $warum = $hz['fehler'] !== '' ? ax_grund_text($hz['fehler']) : ($k['fehler'] !== '' ? $k['fehler'] : $k['status']);
        return array(0, sprintf(ax_t('TEST.A_HUE_STEHT'), $warum), $zeilen, $k);
    }
    list($ok, , $def) = ax_hue_docker_soll($cfg);
    if ($ok && $k['def'] !== $def['hash']) { return array(2, ax_t('TEST.A_HUE_D_ALT'), $zeilen, $k); }
    return array(1, sprintf(ax_t('TEST.A_HUE_D_LAEUFT'), $k['ip'] !== '' ? $k['ip'] : $cfg['hue_ip'], $nm['container'],
                            ax_spanne_text($k['seit'])), $zeilen, $k);
}

/**
 * Selbstprobe aus dem Reiter Test fuer den Container: description.xml und
 * Lampenliste ueber die Brueckenadresse des Containers (die eigene Adresse im
 * Heimnetz ist vom LoxBerry aus nicht erreichbar - macvlan). Rueckgabe
 * array(stand, text) wie ax_hue_selbstprobe().
 */
function ax_hue_selbstprobe_docker($k)
{
    if (!function_exists('curl_init')) { return array(-1, ax_t('TEST.A_HUE_PROBE_NICHT')); }
    if (!is_array($k) || $k['ip_bruecke'] === '') { return array(-1, ax_t('TEST.A_HUE_D_PROBE_OHNE')); }
    $erg = array();
    foreach (array('/description.xml', '/api/selbstprobe/lights') as $pfad) {
        $ch = curl_init('http://' . $k['ip_bruecke'] . ':80' . $pfad);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 3,
                                     CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'AlexaNG-Selbstprobe/1'));
        $r = curl_exec($ch);
        $erg[] = array((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $r);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    }
    $j = json_decode($erg[1][1], true);
    $soll = ax_hue_erwartete_lampen(ax_config());
    $gut = $erg[0][0] === 200 && strpos($erg[0][1], '<modelName>Philips hue bridge 2012</modelName>') !== false
        && $erg[1][0] === 200 && ax_hue_lichter_passen($j, $soll);
    if ($gut && $soll === array('1' => 'Loxone Probe')) { return array(1, sprintf(ax_t('TEST.A_HUE_D_PROBE_OK'), $k['ip_bruecke'])); }
    if ($gut) { return array(1, sprintf(ax_t('TEST.A_HUE_D_PROBE_OK_N'), $k['ip_bruecke'], count($soll))); }
    return array(0, sprintf(ax_t('TEST.A_HUE_D_PROBE_FEHL'), $k['ip_bruecke'], $erg[0][0] . '/' . $erg[1][0]));
}

/* ==================================================================
 * Protokoll
 * ================================================================== */

/** Eine Zeile ins eigene Protokoll; Kappung ab 500 kB auf 200 Zeilen. */
function ax_log($stufe, $text)
{
    $p = ax_paths();
    if (!is_dir($p['logdir'])) {
        if (ax_nur_lesen() || $p['lbhome'] === '') { return; }
        @mkdir($p['logdir'], 0775, true);
    }
    $f = $p['log'];
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        $rest = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $rest) . "\n");
    }
    $text = str_replace(array("\r", "\n"), ' ', (string) $text);
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] <' . $stufe . '> ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Zeile hoechstens einmal je $sekunden; die Bremse faellt mit der Datei. */
function ax_log_wenn_neu($schluessel, $stufe, $text, $sekunden = 3600)
{
    $p = ax_paths();
    $f = $p['datadir'] . '/protokollbremse.json';
    $d = is_dir($p['datadir']) ? ax_json_lesen($f) : null;
    if (!is_array($d)) { $d = array(); }
    $k = preg_replace('/[^A-Za-z0-9_]/', '_', $schluessel);
    clearstatcache(true, $p['log']);
    $log_da = is_file($p['log']);
    if ($log_da && isset($d[$k]) && is_int($d[$k]) && time() - $d[$k] < $sekunden) { return; }
    ax_log($stufe, $text);
    $d[$k] = time();
    if (count($d) > 200) { asort($d); $d = array_slice($d, -150, null, true); }
    if (is_dir($p['datadir']) && !ax_nur_lesen()) { ax_write_json($f, $d, 0600); }
}

/** Das Protokollende rueckwaerts mit fseek. */
function ax_log_ende($anzahl = 300)
{
    $p = ax_paths();
    $f = $p['log'];
    if (!is_file($f)) { return array(); }
    $fh = @fopen($f, 'rb');
    if ($fh === false) { return array(); }
    fseek($fh, 0, SEEK_END);
    $pos = ftell($fh);
    $puffer = '';
    while ($pos > 0 && substr_count($puffer, "\n") <= $anzahl) {
        $n = min(8192, $pos);
        $pos -= $n;
        fseek($fh, $pos);
        $puffer = fread($fh, $n) . $puffer;
    }
    fclose($fh);
    $z = preg_split('/\r?\n/', rtrim($puffer, "\r\n"));
    return array_reverse(array_slice($z, -$anzahl));
}

/* ==================================================================
 * Fassung, Sprache, Maskierung
 * ================================================================== */

/** Die eigene Fassung: plugindatabase.json ueber den Ordnernamen, sonst plugin.cfg, sonst leer. */
function ax_pluginversion()
{
    static $v = null;
    if ($v !== null) { return $v; }
    $v = '';
    $p = ax_paths();
    if ($p['lbhome'] !== '' && is_file($p['lbhome'] . '/data/system/plugindatabase.json')) {
        $db = json_decode((string) @file_get_contents($p['lbhome'] . '/data/system/plugindatabase.json'), true);
        if (is_array($db) && isset($db['plugins']) && is_array($db['plugins'])) {
            foreach ($db['plugins'] as $e) {
                if (is_array($e) && isset($e['folder'], $e['version']) && $e['folder'] === $p['plugin'] && is_string($e['version'])) {
                    $v = $e['version'];
                    return $v;
                }
            }
        }
    }
    foreach (array(dirname(dirname(__DIR__)) . '/plugin.cfg') as $cfgdatei) {
        if (is_file($cfgdatei)) {
            $roh = (string) @file_get_contents($cfgdatei);
            if (preg_match('/^VERSION=([0-9][0-9.]*)[^\r\n]*/m', $roh, $m)) { $v = $m[1]; break; }
        }
    }
    return $v;
}

function ax_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    } else {
        $p = ax_paths();
        if ($p['general'] !== '' && is_file($p['general'])) {
            $g = json_decode((string) @file_get_contents($p['general']), true);
            if (is_array($g) && isset($g['Base']['Lang']) && is_string($g['Base']['Lang'])) { $s = $g['Base']['Lang']; }
        }
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

/** Text zu "ABSCHNITT.SCHLUESSEL"; unbekannt -> der Schluessel selbst. */
function ax_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = ax_paths();
        $pfad = ($p['lbhome'] !== '') ? $p['lbhome'] . '/templates/plugins/' . $p['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) { $pfad = dirname(dirname(__DIR__)) . '/templates/lang'; }
        $texte = @parse_ini_file($pfad . '/language_' . ax_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    list($a, $s) = array_pad(explode('.', (string) $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

function ax_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Einen Grund (KENNUNG|wert|...) in einen Satz der Oberflaechensprache. */
function ax_grund_text($g)
{
    $teile = explode('|', (string) $g);
    $k = array_shift($teile);
    if (!preg_match('/^[A-Z0-9_]+\z/', (string) $k)) { return (string) $g; }
    $t = ax_t('GRUND.' . $k);
    if ($t === 'GRUND.' . $k) { return (string) $g; }
    $n = preg_match_all('/%(?:\d+\$)?[sd]/', $t);
    $werte = array_pad(array_slice($teile, 0, $n), $n, '');
    return $n > 0 ? vsprintf($t, $werte) : $t;
}

/** Eine Dauer fuer Menschen (Sekunden, Minuten, Stunden, Tage). */
function ax_dauer_text($sek)
{
    $sek = (int) $sek;
    if ($sek < 0) { return ax_t('ALLG.NIE'); }
    if ($sek < 90) { return sprintf(ax_t('ALLG.DAUER_S'), $sek); }
    if ($sek < 5400) { return sprintf(ax_t('ALLG.DAUER_MIN'), (int) round($sek / 60)); }
    if ($sek < 172800) { return sprintf(ax_t('ALLG.DAUER_H'), (int) round($sek / 3600)); }
    return sprintf(ax_t('ALLG.DAUER_D'), (int) round($sek / 86400));
}

/* ==================================================================
 * Webport und eigene Adressen
 * ================================================================== */

function ax_webport()
{
    static $port = null;
    if ($port !== null) { return $port; }
    $port = 80;
    $p = ax_paths();
    if ($p['general'] !== '' && is_file($p['general'])) {
        $g = json_decode((string) @file_get_contents($p['general']), true);
        if (is_array($g) && isset($g['Webserver']['Port']) && (int) $g['Webserver']['Port'] > 0) {
            $port = (int) $g['Webserver']['Port'];
        }
    }
    return $port;
}

/** Die Adresse, unter der Loxone und andere Plugins den Endpunkt rufen. */
function ax_endpunkt_basis($host)
{
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) $host);
    if ($host === '' || in_array(strtolower(preg_replace('/:\d+$/', '', $host)), array('127.0.0.1', 'localhost', '[::1]', '::1'), true)) {
        $host = gethostname() ?: 'loxberry';
    }
    $p = ax_paths();
    $port = (strpos($host, ':') === false && ax_webport() !== 80) ? ':' . ax_webport() : '';
    return 'http://' . $host . $port . '/plugins/' . $p['plugin'] . '/';
}

/* ==================================================================
 * Loxone-Vorlagen (Bauart APC-UPS/Abfahrt, nach den Ausfuhren vom 12.08.2026)
 * ================================================================== */

function ax_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function ax_xml_virtual_in_http(array $kopf, array $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="' . ax_x($kopf['title']) . '" Comment="' . ax_x($kopf['comment'])
        . '" Address="' . ax_x($kopf['address']) . '" PollingTime="' . ax_x($kopf['polling']) . '">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd Title="' . ax_x($c['title']) . '" Comment="' . ax_x($c['comment'])
            . '" Check="' . ax_x($c['check']) . '" Signed="' . ($c['min'] < 0 ? 'true' : 'false')
            . '" Analog="' . ($c['analog'] ? 'true' : 'false') . '" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1"'
            . ' DefVal="0" MinVal="' . (int) $c['min'] . '" MaxVal="' . (int) $c['max'] . '" Unit="' . ax_x($c['unit'])
            . '" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function ax_xml_virtual_out(array $kopf, array $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="' . ax_x($kopf['title']) . '" Comment="' . ax_x($kopf['comment'])
        . '" Address="' . ax_x($kopf['address']) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        // 'analog' => false: digitaler Befehl ohne Skalierung (Regeln/07, Ausfuhr VQ_KEBA); sonst analog wie bisher.
        $digital = isset($c['analog']) && $c['analog'] === false;
        $o .= "\t" . '<VirtualOutCmd Title="' . ax_x($c['title']) . '" Comment="' . ax_x($c['comment'])
            . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . ax_x($c['on'])
            . '" CmdOnHTTP="" CmdOnPost="" CmdOff="' . (isset($c['off']) ? ax_x($c['off']) : '') . '" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="' . ($digital ? 'false' : 'true') . '"'
            . ' Repeat="0" RepeatRate="0"' . ($digital ? '' : ' SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10"')
            . ' HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/** [Dateiname, Inhalt] der Eingangsvorlage (Status). */
function ax_vorlage_ein($host)
{
    $cmds = array();
    foreach (ax_status_felder() as $feld => $d) {
        list($analog, $min, $max, $einheit, $nk) = $d;
        $cmds[] = array('title' => 'Alexa NG ' . $feld, 'comment' => 'Alexa: ' . ax_t('KACHEL.' . $feld),
            'check' => ax_check($feld), 'analog' => $analog, 'min' => $min, 'max' => $max,
            'unit' => '<v.' . $nk . '>' . ($einheit !== '' ? ' ' . $einheit : ''));
    }
    return array('VI_alexang.xml', ax_xml_virtual_in_http(array('title' => 'Alexa NG',
        'comment' => ax_t('LOX.VI_KOMMENTAR') . ' (' . date('d.m.Y') . ')',
        'address' => ax_endpunkt_basis($host) . '?aktion=status', 'polling' => '300'), $cmds));
}

/**
 * [Dateiname, Inhalt] der Ausgangsvorlage: je Geraet "sprechen" und
 * "Lautstaerke", dazu "alle". Traegt das SPRECHtoken - vertraulich.
 * Ob <v> am Ausgang Text traegt (Statusbaustein), ist am Miniserver zu messen.
 */
function ax_vorlage_aus($host, array $cfg)
{
    $p = ax_paths();
    $st = ax_geraete();
    $ziele = array('alle' => 'alle');
    if ($st) { foreach ($st['liste'] as $g) { if ($g['familie'] !== 'WHA') { $ziele[$g['normal']] = $g['normal']; } } }
    foreach ($cfg['gruppen'] as $z) { $ziele['gruppe:' . $z['name']] = 'gruppe:' . $z['name']; }
    $cmds = array();
    $pfad = '/plugins/' . $p['plugin'] . '/?token=' . rawurlencode((string) $cfg['sprechtoken']);
    foreach ($ziele as $n) {
        $cmds[] = array('title' => 'Alexa ' . $n . ' sprechen', 'comment' => 'Alexa: ' . $n,
            'on' => $pfad . '&aktion=sprechen&geraet=' . $n . '&text=<v>');
        if (strpos($n, 'gruppe:') !== 0 && $n !== 'alle') {
            $cmds[] = array('title' => 'Alexa ' . $n . ' Lautstaerke', 'comment' => 'Alexa: ' . $n . ' %',
                'on' => $pfad . '&aktion=lautstaerke&geraet=' . $n . '&wert=<v>');
        }
    }
    $basis = ax_endpunkt_basis($host);
    return array('VQ_alexang.xml', ax_xml_virtual_out(array('title' => 'Alexa NG Ansagen',
        'comment' => ax_t('LOX.VO_KOMMENTAR') . ' (' . date('d.m.Y') . ')',
        'address' => preg_replace('#/plugins/.*$#', '', $basis)), $cmds));
}

/**
 * [Dateiname, Inhalt] der Radio-Vorlage (Z5): je Zone "Sender" (analog, <v> ist
 * die Nummer aus der Senderliste), "Stopp" (digital) und "Lautstärke" (analog
 * 0-100), dazu dieselben drei fuer alle Zonen. Traegt das AKTIONStoken -
 * vertraulich. Titel mit Umlaut (vorlagen_pruefen h3); die aeltere
 * Ansage-Vorlage behaelt "Lautstaerke", weil ihre Titel schon importiert sind.
 */
function ax_vorlage_radio($host, array $cfg)
{
    $p = ax_paths();
    $zonen = array();
    if (isset($cfg['radio_zonen']) && is_array($cfg['radio_zonen'])) {
        foreach ($cfg['radio_zonen'] as $z) { if (is_array($z) && isset($z['zone'])) { $zonen[] = (string) (int) $z['zone']; } }
    }
    $zonen[] = 'alle';
    $pfad = '/plugins/' . $p['plugin'] . '/?token=' . rawurlencode(isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '');
    $cmds = array();
    foreach ($zonen as $zn) {
        $t = ($zn === 'alle') ? 'alle Zonen' : 'Zone ' . $zn;
        $cmds[] = array('title' => 'Alexa Radio ' . $t . ' Sender', 'comment' => 'Radio ' . $t . ': Sender-Nr., 0 = Stopp',
            'on' => $pfad . '&aktion=radio&zone=' . $zn . '&nr=<v>');
        $cmds[] = array('title' => 'Alexa Radio ' . $t . ' Stopp', 'comment' => 'Radio ' . $t . ': Stopp', 'analog' => false,
            'on' => $pfad . '&aktion=radio_stopp&zone=' . $zn);
        $cmds[] = array('title' => 'Alexa Radio ' . $t . ' Lautstärke', 'comment' => 'Radio ' . $t . ' %',
            'on' => $pfad . '&aktion=radio_laut&zone=' . $zn . '&wert=<v>');
    }
    $basis = ax_endpunkt_basis($host);
    return array('VQ_alexang_radio.xml', ax_xml_virtual_out(array('title' => 'Alexa NG Radio',
        'comment' => ax_t('LOX.VR_KOMMENTAR') . ' (' . date('d.m.Y') . ')',
        'address' => preg_replace('#/plugins/.*$#', '', $basis)), $cmds));
}

/* ==================================================================
 * Sicherung (Regeln/05; Entscheidung 18: Anmeldung nur mit Haken)
 * ================================================================== */

/** Die Sicherungsdatei: lesbarer Kopf, alle Schluessel, Amazon nur mit Haken. */
function ax_sicherung_bauen(array $cfg, $mit_amazon)
{
    $kopf = array(
        '_plugin' => 'alexang',
        '_stand' => date('Y-m-d H:i:s'),
        '_hinweis' => 'Enthaelt Sprech- und Aktionstoken' . ($mit_amazon ? ' UND die Amazon-Anmeldung' : '')
                    . '. Wie ein Passwort behandeln.',
    );
    // X-3: Was ax_config() beim Lesen abwies, steht in der Datei als Vorgabe -
    // die Datei sagt es (dieselbe Pruefung wie beim Zurueckspielen).
    $lage = ax_config_lage();
    if ($lage['abgewiesen']) {
        $kopf['_warnung'] = 'Gespeicherte Werte abgewiesen, in dieser Datei steht dafuer die Vorgabe: '
            . implode(', ', array_keys($lage['abgewiesen']));
    }
    $d = array();
    foreach (array_keys(ax_vorgaben()) as $k) { $d[$k] = $cfg[$k]; }
    if ($mit_amazon) {
        $a = ax_amazon();
        if ($a) { $d['_amazon'] = array('refresh_token' => $a['refresh_token'], 'weg' => $a['weg'], 'device_serial' => $a['device_serial']); }
    }
    return json_encode($kopf + $d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Eine Sicherung lesen: Fremdes ablehnen, jeden Wert pruefen, eine halb
 * gueltige Datei aendert GAR NICHTS. Grundlage ist der jetzige Stand.
 * Rueckgabe: array(cfg|null, meldungen, anzahl, amazon|null).
 */
function ax_sicherung_lesen($roh)
{
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten !== array() && array_keys($daten) === range(0, count($daten) - 1))) {
        return array(null, array(ax_t('SICH.KEIN_JSON')), 0, null);
    }
    $jetzt = ax_config();
    $vorgaben = ax_vorgaben();
    $neu = array();
    foreach (array_keys($vorgaben) as $k) { $neu[$k] = $jetzt[$k]; }
    $anzahl = 0;
    $gesehen = array();
    $amazon = null;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k === '_amazon') {
            if (!is_array($w) || !isset($w['refresh_token']) || !ax_refresh_form_ok($w['refresh_token'])
                || !isset($w['weg']) || !in_array($w['weg'], array('a', 'b'), true)
                || (isset($w['device_serial']) && (!is_string($w['device_serial'])
                    || ($w['device_serial'] !== '' && !preg_match('/^[A-Za-z0-9]{8,64}\z/', $w['device_serial']))))) {
                $mangel[] = ax_t('SICH.AMAZON_UNGUELTIG');
                continue;
            }
            $amazon = array('refresh_token' => $w['refresh_token'], 'weg' => $w['weg'],
                            'device_serial' => isset($w['device_serial']) ? $w['device_serial'] : '');
            continue;
        }
        if ($k !== '' && $k[0] === '_') { continue; }   // lesbarer Kopf: uebergangen, nicht beanstandet
        if (!array_key_exists($k, $vorgaben)) { $mangel[] = sprintf(ax_t('SICH.FREMD'), $k); continue; }
        $g = '';
        $wert = ax_wert_pruefen($k, $w, $g);
        if ($wert === null) { $mangel[] = sprintf(ax_t('SICH.WERT'), $k, ax_grund_text($g)); continue; }
        $neu[$k] = $wert;
        $gesehen[$k] = 1;
        $anzahl++;
    }
    if ($anzahl === 0) { $mangel[] = ax_t('SICH.LEER'); }
    foreach (array('sprechtoken', 'aktionstoken') as $tk) {
        if (isset($gesehen[$tk]) && $neu[$tk] === '' && (string) $jetzt[$tk] !== '') {
            $neu[$tk] = $jetzt[$tk];
            $hinweise[] = sprintf(ax_t('SICH.TOKEN_BEHALTEN'), $tk);
        }
    }
    if ($neu['sprechtoken'] !== '' && $neu['sprechtoken'] === $neu['aktionstoken']) {
        $mangel[] = ax_t('SICH.TOKEN_GLEICH');
    }
    // Radio (Z1): eine Zone auf eine eigene Gruppe braucht diese Gruppe (aus der
    // Sicherung oder, fehlt sie dort, aus dem jetzigen Stand).
    $fg = ax_radio_gruppen_fehlen($neu['radio_zonen'], $neu['gruppen']);
    if ($fg) { $mangel[] = sprintf(ax_t('SICH.RADIO_GRUPPE'), implode(', ', $fg)); }
    // Nr. 41: eigene Netzadresse - dieselbe Pruefung wie beim Speichern, wenn die
    // Sicherung einen der drei Werte traegt (ohne Belegt-Probe: die macht der
    // Vorgang vor dem Anlegen). Eine Sicherung von 0.9.4 traegt keinen.
    if (ax_hue_art($neu) === 'docker' && (isset($gesehen['hue_art']) || isset($gesehen['hue_ip']) || isset($gesehen['hue_schnittstelle']))) {
        $hg = ax_hue_ip_pruefen($neu['hue_ip'], $neu['hue_schnittstelle'], false);
        if ($hg !== '') { $mangel[] = sprintf(ax_t('SICH.WERT'), ax_hue_ip_feld($hg), ax_grund_text($hg)); }
    }
    // Fassung 2 (alexa6): die freigegebenen Echos im Netz der Schnittstelle (wie beim Speichern) und
    // die naechste Lampen-ID ueber jeder vergebenen (sonst erbte eine neue Lampe die Kennung einer alten).
    if (isset($gesehen['hue_echos']) && $neu['hue_echos']) {
        $eg = ax_hue_echos_pruefen($neu['hue_echos'], $neu['hue_schnittstelle']);
        if ($eg !== '') { $mangel[] = sprintf(ax_t('SICH.WERT'), 'hue_echos', ax_grund_text($eg)); }
    }
    foreach ($neu['hue_lampen'] as $hl) {
        if ($hl['id'] >= $neu['hue_lampe_naechste']) {
            $mangel[] = sprintf(ax_t('SICH.WERT'), 'hue_lampe_naechste', ax_grund_text('LAMPE_NAECHSTE|' . $hl['id']));
            break;
        }
    }
    if ($mangel) { return array(null, $mangel, $anzahl, null); }
    $fehlend = array_values(array_diff(array_keys($vorgaben), array_keys($gesehen)));
    if ($fehlend) { $hinweise[] = sprintf(ax_t('SICH.FEHLEND'), count($fehlend), implode(', ', $fehlend)); }
    return array($neu, $hinweise, $anzahl, $amazon);
}

/* ==================================================================
 * Befund (Healthcheck, Reiter Test) - eine Funktion fuer alle
 * ================================================================== */

/**
 * Die Lage in Zeilen: array(status, text) mit Status nach LoxBerry
 * (3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis). Kein Netz.
 */
function ax_befund()
{
    $z = array();
    $cfg = ax_config();
    if (empty($cfg['aktiv'])) { $z[] = array(6, ax_t('BEFUND.AUS')); }
    $l = ax_amazon_lage();
    $bef = ax_anmeldung_befund();
    if (!$l['datei']) {
        $z[] = array(4, ax_t('BEFUND.KEINE_ANMELDUNG'));
    } elseif (!$l['form']) {
        $z[] = array(3, ax_t('BEFUND.ANMELDUNG_FORM'));
    } elseif ($bef['befund'] === 'ABGELAUFEN') {
        $z[] = array(3, ax_t('BEFUND.ABGELAUFEN'));
    } elseif ($bef['befund'] !== '' && $bef['befund'] !== 'OK') {
        $z[] = array(4, sprintf(ax_t('BEFUND.AMAZON_GESTOERT'), ax_grund_text($bef['befund'])));
    }
    $t = ax_takt_lesen();
    $alter = ax_alter($t['ts']);
    if ($alter < 0 || $alter > AX_OK_GRENZE_S) {
        $z[] = array(3, sprintf(ax_t('BEFUND.TAKT_ALT'), ax_dauer_text($alter)));
    }
    if (!function_exists('curl_init')) { $z[] = array(3, ax_t('BEFUND.CURL')); }
    if (!$z) { $z[] = array(5, ax_t('BEFUND.OK')); }
    return $z;
}

/** Zusammenfassung: nie besser als der schlechteste Punkt. */
function ax_befund_gesamt(array $z)
{
    $rang = array(3 => 0, 4 => 1, 6 => 2, 5 => 3);
    $schlecht = 5;
    $texte = array();
    foreach ($z as $e) {
        if ($rang[$e[0]] < $rang[$schlecht]) { $schlecht = $e[0]; }
        $texte[] = $e[1];
    }
    return array($schlecht, implode(' ', $texte));
}

/* ---------------- Pruefzeilen, die die eigene Oberflaeche zaehlen ---------------- */

/** Passen Reiterleiste, Bereiche und Positivliste zusammen? */
function ax_pruef_reiter($quelle, $muster)
{
    preg_match_all('/<a class="sm-tab(.*?)"\s+data-ziel="(tab-[a-z]+)"/', (string) $quelle, $ml);
    preg_match_all('/<div class="sm-seite(.*?)"\s+id="(tab-[a-z]+)"/', (string) $quelle, $mb);
    $liste = array();
    if (preg_match('/\(([a-z|]+)\)/', (string) $muster, $mm)) {
        foreach (explode('|', $mm[1]) as $r) { $liste[] = 'tab-' . $r; }
    }
    $fehl = array();
    if (!$ml[2] || !$mb[2] || !$liste) { $fehl[] = ax_t('TEST.P_LEER'); }
    foreach (array_unique(array_merge($ml[2], $mb[2], $liste)) as $r) {
        if (!in_array($r, $ml[2], true) || !in_array($r, $mb[2], true) || !in_array($r, $liste, true)) { $fehl[] = $r; }
    }
    foreach (array($ml, $mb) as $x) {
        foreach ($x[2] as $i => $r) {
            if (strpos($x[1][$i], "'" . $r . "'") === false || strpos($x[1][$i], 'sm-active') === false) { $fehl[] = $r . ' (sm-active)'; }
        }
    }
    if ($fehl) { return array(0, sprintf(ax_t('TEST.A_REITER_FEHL'), implode('; ', array_unique($fehl)))); }
    return array(1, sprintf(ax_t('TEST.A_REITER_OK'), count($liste)));
}

/** Tragen alle POST-Formulare das Merkmal? Eine leere Menge ist kein Haken. */
function ax_pruef_formulare($quelle)
{
    $n = 0;
    $ohne = 0;
    foreach (preg_split('/<form\b/i', (string) $quelle) as $i => $teil) {
        if ($i === 0) { continue; }
        $ende = stripos($teil, '</form>');
        $block = ($ende === false) ? $teil : substr($teil, 0, $ende);
        if (!preg_match('/^[^>]*method="post"/i', $block)) { continue; }
        $n++;
        if (strpos($block, 'name="formtoken"') === false) { $ohne++; }
    }
    if ($n === 0) { return array(0, ax_t('TEST.A_FORM_LEER')); }
    if ($ohne > 0) { return array(0, sprintf(ax_t('TEST.A_FORM_FEHL'), $ohne, $n)); }
    return array(1, sprintf(ax_t('TEST.A_FORM_OK'), $n, $n));
}

/**
 * Themenliste gegen Sendecode, in BEIDE Richtungen (Regeln/07): die Themen,
 * die diese Datei woertlich als Schluessel an ax_mqtt_senden() uebergibt,
 * gegen ax_mqtt_themen().
 */
function ax_pruef_themen()
{
    $q = (string) @file_get_contents(__FILE__);
    preg_match_all("/'((?:status|geraete|letzte|hue_probe)\/[a-z_]+)'\s*=>\s*[^a]/", $q, $m);
    preg_match_all("/'geraet\/' \. \\\$[a-z]+\['normal'\] \. '\/([a-z]+)'/", $q, $m2);
    preg_match_all("/'geraet\/' \. \\\$weg \. '\/([a-z]+)'/", $q, $m3);
    $gesendet = array_unique($m[1]);
    foreach (array_merge($m2[1], $m3[1]) as $s) { $gesendet[] = 'geraet/<name>/' . $s; }
    preg_match_all("/'radio\/' \. \\\$zn \. '\/([a-z]+)'/", $q, $m4);
    foreach ($m4[1] as $s) { $gesendet[] = 'radio/<zone>/' . $s; }
    preg_match_all("/'hue\/' \. \\\$kuerzel \. '\/([a-z]+)'/", $q, $m5);
    foreach ($m5[1] as $s) { $gesendet[] = 'hue/<kuerzel>/' . $s; }
    $gesendet = array_values(array_unique($gesendet));
    $tabelle = array_keys(ax_mqtt_themen());
    if (!$gesendet) { return array(0, ax_t('TEST.A_THEMEN_LEER')); }
    $nur_code = array_diff($gesendet, $tabelle);
    $nur_tab = array_diff($tabelle, $gesendet);
    if ($nur_code || $nur_tab) {
        return array(0, sprintf(ax_t('TEST.A_THEMEN_FEHL'), implode(', ', $nur_code) ?: '-', implode(', ', $nur_tab) ?: '-'));
    }
    return array(1, sprintf(ax_t('TEST.A_THEMEN_OK'), count($tabelle)));
}

/** Vorlagen wohlgeformt, Suchmuster eindeutig (an der erzeugten Statuszeile). */
function ax_pruef_vorlagen(array $cfg)
{
    if (!function_exists('simplexml_load_string')) { return array(-1, ax_t('TEST.A_XML_NICHT')); }
    $fehl = array();
    $alt = libxml_use_internal_errors(true);
    foreach (array(ax_vorlage_ein('loxberry'), ax_vorlage_aus('loxberry', $cfg), ax_vorlage_radio('loxberry', $cfg),
                   ax_vorlage_hue_ein('loxberry', $cfg), ax_vorlage_hue_aus('loxberry', $cfg)) as $v) {
        if (@simplexml_load_string($v[1]) === false) { $fehl[] = $v[0]; }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($alt);
    $zeile = ax_zeile('ALEXANG', array('OK' => 1, 'ANMELDUNG' => 1, 'GERAETE' => 1, 'ONLINE' => 1, 'ALTER' => 1,
                                       'ZAEHLER' => 1, 'LETZTE_OK' => 1, 'LETZTE_ALTER' => 1));
    foreach (array_keys(ax_status_felder()) as $feld) {
        if (substr_count($zeile, ';' . $feld . '=') !== 1) { $fehl[] = $feld; }
    }
    if ($fehl) { return array(0, sprintf(ax_t('TEST.A_XML_FEHL'), implode(', ', $fehl))); }
    return array(1, sprintf(ax_t('TEST.A_XML_OK'), count(ax_status_felder())));
}

/* ---------------- Eigener Endpunkt (nur aus dem Reiter Test) ---------------- */

/**
 * Den eigenen Endpunkt ueber 127.0.0.1 fragen (?selftest=1). Drei Ausgaenge:
 * 1 richtige Antwort, 0 andere Antwort, -1 nicht feststellbar.
 */
function ax_selbstprobe(array $cfg)
{
    $p = ax_paths();
    if (!function_exists('curl_init') || (string) $cfg['sprechtoken'] === '') { return array(-1, ax_t('TEST.A_SELBST_NICHT')); }
    $url = 'http://127.0.0.1' . (ax_webport() !== 80 ? ':' . ax_webport() : '') . '/plugins/' . $p['plugin']
         . '/?selftest=1&token=' . rawurlencode((string) $cfg['sprechtoken']);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 3,
                                 CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => ''));
    $r = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    if ($r === false || $code === 0) { return array(-1, ax_t('TEST.A_SELBST_NICHT')); }
    if ($code === 200 && strpos((string) $r, 'SELFTEST;OK=1;TOKEN=OK') === 0) { return array(1, ax_t('TEST.A_SELBST_OK')); }
    preg_match('/^.{0,60}/s', (string) $r, $m);
    return array(0, sprintf(ax_t('TEST.A_SELBST_FEHL'), $code, str_replace(array("\r", "\n"), ' ', $m[0])));
}
