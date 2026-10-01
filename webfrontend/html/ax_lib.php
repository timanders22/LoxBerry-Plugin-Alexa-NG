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
            return $schalter($wert);
        case 'bremse_fenster_s': return $zahl($wert, 0, 3600);
        case 'mindestabstand_s': return $zahl($wert, 0, 600);
        case 'stundengrenze':    return $zahl($wert, 10, 240);
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
 * tauschen und EINMAL wiederholen; 429 -> nach 2 s einmal wiederholen.
 * Rueckgabe: array(ok, grund, antwort).
 */
function ax_alexa($methode, $pfad, $rumpf = null)
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
    if ($r['code'] === 429) {
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
    ax_write_json($p['namen'], $namen, 0644);
    $st = array('stand' => time(), 'konto_gesamt' => $gesamt, 'liste' => $liste);
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
    $d += array('stand' => 0, 'konto_gesamt' => 0);
    return $d;
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
        if (!isset($nach_normal[$n])) { return array(404, 'GERAET_UNBEKANNT', array(), 0, array(), $n); }
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
    foreach (array('fenster', 'abstand', 'stunde') as $k) {
        if (!isset($b[$k]) || !is_array($b[$k])) { $b[$k] = array(); }
    }
    if (!isset($b['letzter_preview'])) { $b['letzter_preview'] = 0; }
    $jetzt = time();
    $b['stunde'] = array_values(array_filter($b['stunde'], function ($t) use ($jetzt) {
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
 * Die Befehle - eine Funktion fuer Endpunkt, MQTT-Befehlseingang und
 * Testknoepfe (Regeln/03: Trockenlauf und Ernstfall in derselben Funktion)
 * ================================================================== */

/**
 * Einen Befehl ausfuehren. $aktion: sprechen | ankuendigen | lautstaerke |
 * routine. $par: geraet, text, laut, ssml, titel, wert, name, dringend (alle
 * als Zeichenketten, bereits auf is_string geprueft). $quelle: http | mqtt |
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
        return array($http, $felder);
    };

    if (!in_array($aktion, array('sprechen', 'ankuendigen', 'lautstaerke', 'routine'), true)) {
        $f['GRUND'] = 'AKTION';
        return $ende(400, $f);
    }
    if (empty($cfg['aktiv'])) { $f['GRUND'] = 'PLUGIN_AUS'; return $ende(409, $f); }
    if ($aktion === 'ankuendigen' && empty($cfg['ankuendigen_ein'])) { $f['GRUND'] = 'ANKUENDIGEN_AUS'; return $ende(409, $f); }

    // ---- Parameter pruefen: abweisen, nie zurechtbiegen ----
    $text = '';
    $teile = array();
    $laut = null;
    $laenge = 0;
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

    // ---- ab hier unter der Amazon-Sperre ----
    $sp = ax_sperre();
    if (!$sp) { $f['GRUND'] = 'BESCHAEFTIGT'; return $ende(503, $f); }
    $st = ax_geraete();
    if (!$st) {
        list($ok, $g, $st) = ax_geraete_holen();
        if (!$ok) { ax_sperre_frei($sp); $f['GRUND'] = $g; return $ende(503, $f); }
    }
    list($h, $g, $ziele, $offline, , $unbek) = ax_geraete_aufloesen($geraet_param, $cfg, $st);
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
    if (count($b['stunde']) >= (int) $cfg['stundengrenze']) {
        ax_sperre_frei($sp);
        $f['GRUND'] = 'STUNDENGRENZE';
        return $ende(429, $f);
    }
    $hash = hash('sha256', $aktion . '|' . $text . '|' . ($laut === null ? '' : $laut) . '|' . (isset($par['name']) ? $par['name'] : ''));
    $unveraendert = 0;
    if ((int) $cfg['bremse_fenster_s'] > 0) {
        $rest = array();
        foreach ($ziele as $z) {
            $k = $aktion . '|' . $z['serial'];
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
    } else {
        list($oka, $ga, $ra) = ax_alexa('GET', '/api/behaviors/v2/automations?limit=200');
        if (!$oka) { ax_sperre_frei($sp); $f['GRUND'] = $ga; return $ende(503, $f, true, $ziele); }
        $liste = json_decode($ra['rumpf'], true);
        if (!is_array($liste)) { ax_sperre_frei($sp); $f['GRUND'] = 'AMAZON_UNERWARTET'; return $ende(503, $f, true, $ziele); }
        $treffer = null;
        $gesucht = strtolower(trim($par['name']));
        foreach ($liste as $auto) {
            if (!is_array($auto) || !isset($auto['automationId'], $auto['sequence']) || !is_string($auto['automationId'])) { continue; }
            $name = (isset($auto['name']) && is_string($auto['name'])) ? strtolower(trim($auto['name'])) : '';
            $sprach = false;
            if (isset($auto['triggers']) && is_array($auto['triggers'])) {
                foreach ($auto['triggers'] as $tr) {
                    if (is_array($tr) && isset($tr['payload']['utterance']) && is_string($tr['payload']['utterance'])
                        && strtolower(trim($tr['payload']['utterance'])) === $gesucht) { $sprach = true; }
                }
            }
            if ($sprach || $name === $gesucht) { $treffer = $auto; break; }
        }
        if (!$treffer || !preg_match('/^[A-Za-z0-9._:\-]{1,200}\z/', $treffer['automationId'])) {
            ax_sperre_frei($sp);
            $f['GRUND'] = 'ROUTINE_UNBEKANNT';
            return $ende(404, $f, true, $ziele);
        }
        $z = $ziele[0];
        $sj = is_string($treffer['sequence']) ? $treffer['sequence']
            : json_encode($treffer['sequence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sj = str_replace(array('ALEXA_CURRENT_DEVICE_TYPE', 'ALEXA_CURRENT_DSN', 'ALEXA_CUSTOMER_ID'),
                          array($z['typ'], $z['serial'], $s['kunde']), (string) $sj);
        $behavior = $treffer['automationId'];
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
    $b['stunde'][] = time();
    list($ok, $g, $r) = ax_alexa('POST', '/api/behaviors/preview', ax_preview_rumpf($behavior, $sj));
    $b['letzter_preview'] = ax_mono();
    if ($ok) {
        foreach ($ziele as $z) {
            $b['fenster'][$aktion . '|' . $z['serial']] = array('h' => $hash, 't' => time());
            if ($aktion === 'sprechen' || $aktion === 'ankuendigen') { $b['abstand'][$z['serial']] = array('t' => time()); }
        }
    }
    ax_bremse_schreiben($b);
    ax_sperre_frei($sp);
    if (!$ok) { $f['GRUND'] = $g; return $ende(503, $f, true, $ziele, $laenge); }
    $aus = array('OK' => 1, 'GERAETE' => count($ziele)) + $zusatz
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
                      'dienst_versuch' => 0, 'voll_ts' => 0);
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
    );
}

/** Retained? Ein Thema ohne Tabelleneintrag geht fluechtig (Regeln/07). */
function ax_mqtt_retain($thema)
{
    $muster = preg_replace('#^geraet/[a-z0-9_]{1,40}/#', 'geraet/<name>/', $thema);
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
        $o .= "\t" . '<VirtualOutCmd Title="' . ax_x($c['title']) . '" Comment="' . ax_x($c['comment'])
            . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . ax_x($c['on'])
            . '" CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="true"'
            . ' Repeat="0" RepeatRate="0" SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10" HintText=""/>' . $crlf;
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
    preg_match_all("/'((?:status|geraete|letzte)\/[a-z_]+)'\s*=>\s*[^a]/", $q, $m);
    preg_match_all("/'geraet\/' \. \\\$[a-z]+\['normal'\] \. '\/([a-z]+)'/", $q, $m2);
    preg_match_all("/'geraet\/' \. \\\$weg \. '\/([a-z]+)'/", $q, $m3);
    $gesendet = array_unique($m[1]);
    foreach (array_merge($m2[1], $m3[1]) as $s) { $gesendet[] = 'geraet/<name>/' . $s; }
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
    foreach (array(ax_vorlage_ein('loxberry'), ax_vorlage_aus('loxberry', $cfg)) as $v) {
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
