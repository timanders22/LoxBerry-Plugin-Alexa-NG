<?php
/**
 * Alexa NG - Hue-Probe (Dauerlaeufer, nur bei hue_ein=1; nicht am Geraet erprobt)
 *
 * Misst, ob ein Echo eine Hue-Bridge-Nachbildung im Heimnetz findet - die
 * Voraussetzung fuer Fassung 2 "Steuerung" (Entscheidungen 17 und 29). Der
 * Dienst stellt bereit:
 *   - SSDP/UPnP: er hoert auf 239.255.255.250:1900 und beantwortet eine
 *     Suche (M-SEARCH) nach ssdp:all, upnp:rootdevice oder
 *     urn:schemas-upnp-org:device:basic:1 mit EINER Antwort an den Absender
 *     (unicast). Er sendet nie von sich aus ins Netz (kein NOTIFY).
 *   - HTTP auf hue_port (ab Werk 8380, nicht 80 - Apache bleibt unberuehrt):
 *     /description.xml und die Hue-Schnittstelle /api/<user>/lights mit
 *     genau einer Lampe "Loxone Probe" (Ein/Aus, Helligkeit nur gemerkt).
 * Wirkung eines Schaltens: fluechtig <praefix>/hue_probe/ein = 1/0 ueber MQTT,
 * sonst nichts - keine Verbindung zu Loxone-Steuerungen (Bauliste H3).
 *
 * Gemessen wird in data/plugins/<ordner>/hue_probe.json (0644, nur dieser
 * Dienst schreibt sie): Suchen je Absender, Abrufe der Beschreibung, Abfragen
 * und Schaltungen der Lampe. Anfragen der eigenen Selbstprobe (Reiter Test,
 * Kennung im User-Agent) zaehlen getrennt.
 *
 * Gestartet und angehalten ueber bin/hue_dienst.sh (Sperre IM SKRIPT, nicht
 * hier, Regeln/03). Der Dienst endet von selbst, sobald der Haken, das Plugin
 * oder der Port sich aendern (Pruefung alle 5 s).
 *
 * Unterbau: PHP 7.4 und 8.x; fuer den Beitritt zur Multicast-Gruppe braucht
 * es die Erweiterung sockets (am Geraet unter 7.4 vorhanden, gemessen
 * 28.09.2026) - fehlt sie, endet der Dienst mit Grund SOCKETS_FEHLT, ohne
 * einen Fatal error (Regeln/03: function_exists vor jedem Aufruf).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

/* Nr. 41 (alexa5): im Container laeuft derselbe Dienst mit --container=<ordner>
 * [--log=<ordner>] - ohne LoxBerry und ohne ax_lib.php; die wenigen Funktionen
 * stellt bin/ax_hue_container.php bereit. Ohne Schalter wie bisher. */
$ax_container = '';
$ax_logdir = '';
foreach ($argv as $ax_i => $ax_a) {
    if ($ax_i === 0) { continue; }
    if ($ax_container === '' && preg_match('#^--container=(/[A-Za-z0-9_./\-]{1,200})\z#', $ax_a, $ax_m)) { $ax_container = $ax_m[1]; continue; }
    if ($ax_logdir === '' && preg_match('#^--log=(/[A-Za-z0-9_./\-]{1,200})\z#', $ax_a, $ax_m)) { $ax_logdir = $ax_m[1]; continue; }
    fwrite(STDERR, 'Unbekannter Schalter: ' . $ax_a . "\n");
    exit(2);
}
if ($ax_logdir !== '' && $ax_container === '') {
    fwrite(STDERR, "--log nur zusammen mit --container\n");
    exit(2);
}
define('AX_HUE_CONTAINER', $ax_container);

if (AX_HUE_CONTAINER !== '') {
    require __DIR__ . '/ax_hue_container.php';
    ax_hue_container_start(AX_HUE_CONTAINER, $ax_logdir);
} else {
    $ax_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $ax_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ax_lib.php';
    }
    $ax_kand[] = dirname(__DIR__) . '/webfrontend/html/ax_lib.php';
    $ax_da = false;
    foreach ($ax_kand as $ax_k) {
        if (is_file($ax_k)) { require_once $ax_k; $ax_da = true; break; }
    }
    if (!$ax_da) {
        fwrite(STDERR, 'ax_hue.php: ax_lib.php nicht gefunden, gesucht in: ' . implode(', ', $ax_kand) . "\n");
        exit(1);
    }
}
ax_keine_wurzel_abbruch('ax_hue.php');
$ax_p = ax_paths();
ini_set('log_errors', '1');
ini_set('error_log', $ax_p['log']);

define('AX_HUE_GRUPPE', '239.255.255.250');
define('AX_HUE_SSDP_PORT', 1900);
define('AX_HUE_KOPF_MAX', 8192);        // Bytes je HTTP-Anfrage (Kopf und Rumpf)
define('AX_HUE_KUNDEN_MAX', 16);        // gleichzeitige HTTP-Verbindungen
define('AX_HUE_KUNDE_S', 5);            // so lange darf eine Anfrage brauchen
define('AX_HUE_ANTWORTEN_JE_S', 10);    // SSDP-Antworten je Sekunde hoechstens
define('AX_HUE_ABSENDER_MAX', 10);      // gemerkte Absender von Suchen

$ax_cfg = ax_config();
if (empty($ax_cfg['hue_ein']) || empty($ax_cfg['aktiv'])) {
    ax_log('INFO', 'Hue-Probe: ausgeschaltet - der Dienst startet nicht.');
    exit(0);
}
if (AX_HUE_CONTAINER === '' && ax_hue_art($ax_cfg) !== 'loxberry') {
    ax_log('INFO', 'Hue-Probe: Art "eigene Netzadresse" - der Dienst auf dem LoxBerry startet nicht.');
    exit(0);
}
$ax_port = (int) $ax_cfg['hue_port'];

/* ---------------- Zustand (nur dieser Dienst schreibt ihn) ---------------- */
$ax_z = ax_hue_lesen('loxberry');
$ax_z['pid'] = getmypid();
$ax_z['start'] = time();
$ax_z['ende'] = 0;
$ax_z['port'] = $ax_port;
$ax_z['fehler'] = '';
$ax_z['lampe'] = array('ein' => 0, 'bri' => 254);
$ax_z['mqtt_abo'] = array('verbunden' => 0, 'seit' => 0, 'fehler' => '', 'empfangen' => 0, 'zeit' => 0, 'ungueltig' => 0, 'unbekannt' => 0);
// B3 (alexa6): Suchen und Lampen von Anfang an in ihrer Form - die Zuordnungen schreibt
// ax_hue_zustand_schreiben() immer als Objekt, auch leer.
if (!isset($ax_z['lampen']) || !is_array($ax_z['lampen'])) { $ax_z['lampen'] = array(); }
$ax_z['suchen'] = (isset($ax_z['suchen']) && is_array($ax_z['suchen'])) ? $ax_z['suchen'] : array();
$ax_z['suchen'] += array('anzahl' => 0, 'beantwortet' => 0, 'ip' => '', 'zeit' => 0, 'st' => '', 'absender' => array());
$ax_geschrieben = 0.0;
$ax_geaendert = true;

function ax_hue_zustand_schreiben($erzwingen = false)
{
    global $ax_z, $ax_geschrieben, $ax_geaendert, $ax_p;
    if (!$ax_geaendert && !$erzwingen) { return; }
    if (!$erzwingen && microtime(true) - $ax_geschrieben < 1.0) { return; }
    // B3 (alexa6): Absender, Lampen und Abgewiesene immer als Objekt - eine leere
    // Zuordnung waere in JSON sonst eine Liste [] und der Leser saehe zwei Formen.
    $aus = $ax_z;
    if (isset($aus['suchen']['absender']) && is_array($aus['suchen']['absender'])) { $aus['suchen']['absender'] = (object) $aus['suchen']['absender']; }
    if (isset($aus['lampen']) && is_array($aus['lampen'])) { $aus['lampen'] = (object) $aus['lampen']; }
    if (isset($aus['abgewiesen']['absender']) && is_array($aus['abgewiesen']['absender'])) { $aus['abgewiesen']['absender'] = (object) $aus['abgewiesen']['absender']; }
    ax_write_json($ax_p['datadir'] . '/hue_probe.json', $aus, 0644);
    $ax_geschrieben = microtime(true);
    $ax_geaendert = false;
}

function ax_hue_abbruch($grund, $text)
{
    global $ax_z;
    $ax_z['fehler'] = $grund;
    $ax_z['ende'] = time();
    ax_hue_zustand_schreiben(true);
    ax_log('ERROR', 'Hue-Probe: ' . $text . ' - der Dienst endet.');
    exit(1);
}

if (!function_exists('socket_import_stream') || !function_exists('socket_set_option') || !defined('MCAST_JOIN_GROUP')) {
    ax_hue_abbruch('SOCKETS_FEHLT', 'die PHP-Erweiterung sockets fehlt (' . PHP_VERSION . '), ohne sie kein Beitritt zur SSDP-Gruppe');
}

/* ---------------- Kennung der Nachbildung (stabil je Anlage, ohne echte MAC) ---------------- */
if (AX_HUE_CONTAINER !== '') {
    // Nr. 41: eigene, stabile Bridge-Kennung je Anlage und Adresse - festgelegt vom Plugin.
    $ax_kennung = ax_hue_container_kennung();
} else {
    $ax_h = sha1('alexang-hue|' . (string) gethostname() . '|' . $ax_p['lbhome']);
    $ax_mac = substr($ax_h, 0, 12);
    $ax_kennung = array(
        'mac'      => $ax_mac,
        'bridgeid' => strtoupper(substr($ax_mac, 0, 6) . 'fffe' . substr($ax_mac, 6, 6)),
        'uuid'     => '2f402f80-da50-11e1-9b23-' . $ax_mac,
        'lampe'    => implode(':', str_split(substr($ax_h, 12, 16), 2)) . '-0b',
        'nutzer'   => substr(sha1('alexang-hue-nutzer|' . $ax_h), 0, 32),
    );
}

/**
 * Beitritt zur SSDP-Gruppe. Auf dem LoxBerry wie bisher (Schnittstelle nach
 * Route). Im Container an JEDER Schnittstelle ausser lo: welche die
 * Standardroute traegt (Heimnetz oder Bruecke), ist dort nicht sicher -
 * mindestens ein Beitritt muss gelingen.
 */
function ax_hue_beitreten($s)
{
    if (AX_HUE_CONTAINER === '') {
        return @socket_set_option($s, IPPROTO_IP, MCAST_JOIN_GROUP, array('group' => AX_HUE_GRUPPE, 'interface' => 0));
    }
    $gut = array();
    foreach (ax_hue_container_schnittstellen() as $n) {
        if (@socket_set_option($s, IPPROTO_IP, MCAST_JOIN_GROUP, array('group' => AX_HUE_GRUPPE, 'interface' => $n))) { $gut[] = $n; }
    }
    if ($gut) { ax_log('INFO', 'Hue-Probe (Container): SSDP-Gruppe an ' . implode(', ', $gut) . ' beigetreten.'); }
    return (bool) $gut;
}

/* ---------------- Sockel ---------------- */
$ax_ctx = stream_context_create(array('socket' => array('so_reuseport' => true, 'so_reuseaddr' => true)));
$ax_http = @stream_socket_server('tcp://0.0.0.0:' . $ax_port, $ax_en, $ax_es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if ($ax_http === false) {
    ax_hue_abbruch('PORT_BELEGT|' . $ax_port, 'TCP-Port ' . $ax_port . ' laesst sich nicht oeffnen (' . $ax_es . ')');
}
$ax_udp = @stream_socket_server('udp://0.0.0.0:' . AX_HUE_SSDP_PORT, $ax_en, $ax_es, STREAM_SERVER_BIND, $ax_ctx);
if ($ax_udp === false) {
    ax_hue_abbruch('SSDP_BELEGT', 'UDP-Port 1900 laesst sich nicht oeffnen (' . $ax_es . ')');
}
$ax_s = @socket_import_stream($ax_udp);
if (!$ax_s || !ax_hue_beitreten($ax_s)) {
    ax_hue_abbruch('MULTICAST', 'Beitritt zur Gruppe ' . AX_HUE_GRUPPE . ' misslungen');
}
stream_set_blocking($ax_http, false);
stream_set_blocking($ax_udp, false);

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $ax_ende = function () {
        global $ax_z;
        $ax_z['ende'] = time();
        ax_hue_zustand_schreiben(true);
        ax_log('INFO', 'Hue-Probe: angehalten (Signal).');
        exit(0);
    };
    pcntl_signal(SIGTERM, $ax_ende);
    pcntl_signal(SIGINT, $ax_ende);
}

/** Die eigene Adresse in Richtung eines Absenders (UDP-Verbindung ohne Paket). */
function ax_hue_eigene_ip($ziel)
{
    if (AX_HUE_CONTAINER !== '') { return ax_hue_container_ip(); }   // Nr. 41: immer die eigene Adresse im Heimnetz
    $c = @stream_socket_client('udp://' . $ziel . ':' . AX_HUE_SSDP_PORT, $en, $es, 1);
    if ($c === false) { return ''; }
    $n = (string) stream_socket_get_name($c, false);
    fclose($c);
    $ip = preg_replace('/:\d+$/', '', $n);
    return preg_match('/^\d{1,3}(\.\d{1,3}){3}\z/', $ip) ? $ip : '';
}

/** Absender "ip:port" in seine Teile; nur IPv4. */
function ax_hue_peer($peer)
{
    if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d{1,5})\z/', (string) $peer, $m)) { return array('', 0); }
    return array($m[1], (int) $m[2]);
}

function ax_hue_beschreibung($ip, $port, array $k)
{
    $x = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    return '<?xml version="1.0" encoding="UTF-8" ?>' . "\r\n"
        . '<root xmlns="urn:schemas-upnp-org:device-1-0">' . "\r\n"
        . '<specVersion><major>1</major><minor>0</minor></specVersion>' . "\r\n"
        . '<URLBase>http://' . $x($ip) . ':' . (int) $port . '/</URLBase>' . "\r\n"
        . '<device>' . "\r\n"
        . '<deviceType>urn:schemas-upnp-org:device:Basic:1</deviceType>' . "\r\n"
        . '<friendlyName>' . $x('Alexa NG Probe (' . $ip . ')') . '</friendlyName>' . "\r\n"
        . '<manufacturer>Royal Philips Electronics</manufacturer>' . "\r\n"
        . '<manufacturerURL>http://www.philips.com</manufacturerURL>' . "\r\n"
        . '<modelDescription>Philips hue Personal Wireless Lighting</modelDescription>' . "\r\n"
        . '<modelName>Philips hue bridge 2012</modelName>' . "\r\n"
        . '<modelNumber>929000226503</modelNumber>' . "\r\n"
        . '<modelURL>http://www.meethue.com</modelURL>' . "\r\n"
        . '<serialNumber>' . $x($k['mac']) . '</serialNumber>' . "\r\n"
        . '<UDN>uuid:' . $x($k['uuid']) . '</UDN>' . "\r\n"
        . '<presentationURL>index.html</presentationURL>' . "\r\n"
        . '</device>' . "\r\n"
        . '</root>' . "\r\n";
}

function ax_hue_lampe(array $z, array $k)
{
    return array(
        'state' => array('on' => !empty($z['lampe']['ein']), 'bri' => (int) $z['lampe']['bri'], 'alert' => 'none',
                         'mode' => 'homeautomation', 'reachable' => true),
        'type' => 'Dimmable light',
        'name' => 'Loxone Probe',
        'modelid' => 'LWB010',
        'manufacturername' => 'Philips',
        'productname' => 'Hue white lamp',
        'uniqueid' => $k['lampe'],
        'swversion' => '1.46.13',
    );
}

function ax_hue_konfig($ip, array $k)
{
    return array('name' => 'Alexa NG Probe', 'bridgeid' => $k['bridgeid'], 'mac' => implode(':', str_split($k['mac'], 2)),
                 'modelid' => 'BSB002', 'apiversion' => '1.41.0', 'swversion' => '1941132080', 'ipaddress' => $ip,
                 'linkbutton' => false);
}

function ax_hue_json($d)
{
    return json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Zaehler fortschreiben: $art beschreibung | abfragen | schalten | eigene. */
function ax_hue_zaehlen($art, $ip, array $mehr = array())
{
    global $ax_z, $ax_geaendert;
    if (!isset($ax_z[$art]) || !is_array($ax_z[$art])) { $ax_z[$art] = array('anzahl' => 0, 'ip' => '', 'zeit' => 0); }
    $ax_z[$art]['anzahl'] = (int) $ax_z[$art]['anzahl'] + 1;
    $ax_z[$art]['ip'] = $ip;
    $ax_z[$art]['zeit'] = time();
    foreach ($mehr as $k => $v) { $ax_z[$art][$k] = $v; }
    $ax_geaendert = true;
}

/**
 * Eine HTTP-Anfrage beantworten. Rueckgabe array(code, typ, rumpf).
 * $eigen: die Selbstprobe aus dem Reiter Test (zaehlt nicht als Echo).
 */
function ax_hue_anfrage($methode, $pfad, $rumpf, $ip, $eigen, $eigene_ip)
{
    global $ax_z, $ax_kennung, $ax_port, $ax_cfg, $ax_geaendert;
    $pfad = (string) preg_replace('/[?#].*$/s', '', $pfad);
    $k = $ax_kennung;
    if ($eigen) { ax_hue_zaehlen('eigene', $ip); }
    if ($pfad === '/description.xml' && $methode === 'GET') {
        if (!$eigen) { ax_hue_zaehlen('beschreibung', $ip); }
        return array(200, 'text/xml; charset=utf-8', ax_hue_beschreibung($eigene_ip, $ax_port, $k));
    }
    if (!preg_match('#^/api(?:/([A-Za-z0-9_\-]{1,64}))?(/.*)?\z#', $pfad, $m)) {
        return array(404, 'text/plain; charset=utf-8', "nicht gefunden\n");
    }
    // Fassung 2 (alexa6): nur freigegebene Echos (leer = Heimnetz) und die Selbstprobe.
    global $ax_hl_zugang;
    $lage = ax_hue_lage(true);
    if ($ax_hl_zugang === '') {
        ax_hue_abweisen($ip, $pfad);
        return array(200, 'application/json', ax_hue_json(array(array('error' => array('type' => 1, 'address' => $pfad, 'description' => 'unauthorized user')))));
    }
    $nutzer = isset($m[1]) ? $m[1] : '';
    $rest = isset($m[2]) ? rtrim($m[2], '/') : '';
    if ($nutzer === '' && $rest === '' && $methode === 'POST') {
        return array(200, 'application/json', ax_hue_json(array(array('success' => array('username' => $k['nutzer'])))));
    }
    if ($nutzer === 'config' && $rest === '' && $methode === 'GET') {
        return array(200, 'application/json', ax_hue_json(ax_hue_konfig($eigene_ip, $k)));
    }
    $fehler = function ($typ, $adresse, $text) {
        return array(200, 'application/json', ax_hue_json(array(array('error' => array('type' => $typ, 'address' => $adresse, 'description' => $text)))));
    };
    if ($nutzer === '') { return $fehler(4, '/', 'method, ' . $methode . ', not available for resource, /'); }
    if ($methode === 'GET' && $rest === '') {
        if (!$eigen) { ax_hue_zaehlen('abfragen', $ip); ax_hue_abfrage_merken($lage['lampen'], $ip); }
        return array(200, 'application/json', ax_hue_json(array('lights' => ax_hue_lichter($lage),
            'groups' => new stdClass(), 'config' => ax_hue_konfig($eigene_ip, $k), 'schedules' => new stdClass(),
            'scenes' => new stdClass(), 'rules' => new stdClass(), 'sensors' => new stdClass(), 'resourcelinks' => new stdClass())));
    }
    if ($methode === 'GET' && $rest === '/lights') {
        if (!$eigen) { ax_hue_zaehlen('abfragen', $ip); ax_hue_abfrage_merken($lage['lampen'], $ip); }
        return array(200, 'application/json', ax_hue_json(ax_hue_lichter($lage)));
    }
    if ($methode === 'GET' && preg_match('#^/lights/([0-9]{1,4})\z#', $rest, $mm)) {
        if ($mm[1] === '1' && !empty($lage['probe'])) {
            if (!$eigen) { ax_hue_zaehlen('abfragen', $ip); }
            return array(200, 'application/json', ax_hue_json(ax_hue_lampe($ax_z, $k)));
        }
        $hl = ax_hue_lampe_finden($lage, $mm[1]);
        if ($hl !== null) {
            if (!$eigen) { ax_hue_zaehlen('abfragen', $ip); ax_hue_abfrage_merken(array($hl), $ip); }
            return array(200, 'application/json', ax_hue_json(ax_hue_liste_lampe($hl)));
        }
    }
    if ($methode === 'GET' && $rest === '/config') {
        return array(200, 'application/json', ax_hue_json(ax_hue_konfig($eigene_ip, $k)));
    }
    if ($methode === 'GET' && in_array($rest, array('/groups', '/scenes', '/schedules', '/rules', '/sensors', '/resourcelinks'), true)) {
        return array(200, 'application/json', '{}');
    }
    if ($methode === 'PUT' && $rest === '/lights/1/state' && !empty($lage['probe'])) {
        $d = json_decode((string) $rumpf, true);
        if (!is_array($d)) { return $fehler(2, '/lights/1/state', 'body contains invalid json'); }
        $erg = array();
        if (array_key_exists('bri', $d)) {
            if (is_int($d['bri']) && $d['bri'] >= 1 && $d['bri'] <= 254) {
                $ax_z['lampe']['bri'] = $d['bri'];
                $ax_geaendert = true;
                $erg[] = array('success' => array('/lights/1/state/bri' => $d['bri']));
            } else {
                $erg[] = array('error' => array('type' => 7, 'address' => '/lights/1/state/bri', 'description' => 'invalid value for parameter, bri'));
            }
        }
        if (array_key_exists('on', $d)) {
            if (!is_bool($d['on'])) {
                $erg[] = array('error' => array('type' => 7, 'address' => '/lights/1/state/on', 'description' => 'invalid value, on, for parameter, on'));
            } else {
                $ax_z['lampe']['ein'] = $d['on'] ? 1 : 0;
                $erg[] = array('success' => array('/lights/1/state/on' => $d['on']));
                if (!$eigen) {
                    // H3: fluechtig ueber MQTT, sonst nichts. Die Konfiguration frisch lesen:
                    // "MQTT aus" gilt sofort, nicht erst bei der naechsten Pruefung nach 5 s.
                    list($n, $f) = ax_hue_melden($d['on'] ? 1 : 0, ax_config());
                    $gesendet = ($n > 0 && $f === 0);
                    $alt = isset($ax_z['schalten']) && is_array($ax_z['schalten']) ? $ax_z['schalten'] : array();
                    ax_hue_zaehlen('schalten', $ip, array('ein' => $d['on'] ? 1 : 0,
                        'mqtt' => (isset($alt['mqtt']) ? (int) $alt['mqtt'] : 0) + ($gesendet ? 1 : 0),
                        'mqtt_nicht' => (isset($alt['mqtt_nicht']) ? (int) $alt['mqtt_nicht'] : 0) + ($gesendet ? 0 : 1)));
                    ax_log('INFO', 'Hue-Probe: Loxone Probe ' . ($d['on'] ? 'ein' : 'aus') . ' von ' . $ip
                        . ($gesendet ? ', MQTT hue_probe/ein gesendet.' : ', MQTT nicht gesendet (aus oder mosquitto_pub fehlt).'));
                }
            }
        }
        if (!$erg) { return $fehler(6, '/lights/1/state', 'parameter not available'); }
        return array(200, 'application/json', ax_hue_json($erg));
    }
    if ($methode === 'PUT' && preg_match('#^/lights/([0-9]{1,4})/state\z#', $rest, $mm)) {
        $hl = ax_hue_lampe_finden($lage, $mm[1]);
        if ($hl !== null) {
            if ($eigen) { return $fehler(1, $rest, 'unauthorized user'); }   // die Selbstprobe schaltet nie
            return array(200, 'application/json', ax_hue_json(ax_hue_lampe_put($hl, $rumpf, $ip)));
        }
    }
    return $fehler(3, $rest === '' ? '/' : $rest, 'resource, ' . ($rest === '' ? '/' : $rest) . ', not available');
}

/* ================================================================
 * Fassung 2 (alexa6): Lampen fuer Alexa, Freigabe nach Echo-Adresse,
 * Rueckmeldung aus Loxone. Gilt fuer beide Arten (Dienst auf dem
 * LoxBerry und Container); was je Art verschieden ist, liefert
 * ax_hue_lage(): auf dem LoxBerry ax_hue_lb_lage() aus ax_lib.php, im
 * Container ax_hue_container_lage() aus ax_hue_container.php.
 *
 *   lage = lampen (id, name, art schalter|licht|dimmer, kuerzel), probe (0/1),
 *          echos (freigegebene IPs, leer = alle im Heimnetz), netze
 *          (Heimnetz als Liste array(netz, maske)), wirt (Adresse des
 *          LoxBerry am Docker-Netz bridge, sonst ''), mqtt_ein, praefix,
 *          broker (host, port, user, pass)
 *
 * Befehl an Loxone (fluechtig, nie retained): <praefix>/hue/<kuerzel>/ein
 * = 1/0, beim Dimmer dazu .../helligkeit = 0..100 (bri 1..254 -> 1..100 %,
 * gerundet), aber nur, wenn der PUT "bri" traegt; ein blosses "on":true
 * schickt nur ein=1 (alexa7, Nr. 42); bei aus 0. Schalter und Licht
 * (an/aus) senden nur .../ein. Zustand zurueck: Loxone meldet
 * .../status (0/1) und .../status_helligkeit (0..100); dieser Dienst
 * abonniert beide ueber einen eigenen MQTT-3.1.1-Leser in seiner Schleife.
 * Ohne Rueckmeldung gilt der zuletzt von Alexa gesetzte Wert.
 * Schutz: gleicher Wert je Thema binnen 2 s nur einmal gesendet; hoechstens
 * 10 Schaltbefehle je Lampe und Minute (danach Hue-Fehler 901).
 * ================================================================ */

define('AX_HUEL_GLEICH_S', 2.0);       // gleicher Wert je Thema binnen 2 s: nur einmal senden
define('AX_HUEL_MINUTE_MAX', 10);      // Schaltbefehle je Lampe und Minute
define('AX_HUEL_PING_S', 30);          // MQTT-Leser: PINGREQ-Abstand (Keepalive 60 s)
define('AX_HUEL_PAKET_MAX', 65536);    // groesstes MQTT-Paket, das der Leser annimmt

$ax_hl_lage = null;
$ax_hl_lage_zeit = 0.0;
$ax_hl_gesendet = array();             // Thema => array(wert, zeit)
$ax_hl_takt = array();                 // Lampen-ID => Zeitpunkte der letzten Minute
$ax_hl_abgewiesen_log = array();       // IP => 1 (je Lauf einmal ins Protokoll)
$ax_hl_bremse_log = array();           // Lampen-ID => Zeit der letzten Protokollzeile
$ax_ab = array('s' => null, 'puffer' => '', 'naechster' => 0, 'warte' => 5, 'ping' => 0, 'sig' => '', 'pflege' => 0);

/** Die Lage (siehe oben). $frisch: neu lesen (jede HTTP-Anfrage), sonst hoechstens einmal je Sekunde. */
function ax_hue_lage($frisch = false)
{
    global $ax_hl_lage, $ax_hl_lage_zeit;
    if ($frisch || $ax_hl_lage === null || microtime(true) - $ax_hl_lage_zeit >= 1.0) {
        $ax_hl_lage = (AX_HUE_CONTAINER !== '') ? ax_hue_container_lage() : ax_hue_lb_lage(ax_config());
        $ax_hl_lage_zeit = microtime(true);
    }
    return $ax_hl_lage;
}

/** IPv4 als Zahl (ohne fuehrende Nullen), sonst null. */
function ax_hue_ipzahl($s)
{
    if (!is_string($s) || !preg_match('/^(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])){3}\z/', $s)) {
        return null;
    }
    $t = explode('.', $s);
    return (((int) $t[0] * 256 + (int) $t[1]) * 256 + (int) $t[2]) * 256 + (int) $t[3];
}

/**
 * Darf diese Adresse die Hue-Schnittstelle benutzen? 'eigen' = Selbstprobe
 * des Plugins (Kennung im User-Agent UND vom LoxBerry selbst: Wirt, eigene
 * Adresse oder 127.x), 'echo' = freigegeben, '' = abgewiesen. Ist die Liste
 * der Echos leer, gilt das Heimnetz; ist auch das unbekannt, faellt der
 * Schutz geschlossen aus.
 */
function ax_hue_zugang($ip, $ua, $lokal, array $lage)
{
    $selbst = ($ip === $lokal || strpos((string) $ip, '127.') === 0 || ($lage['wirt'] !== '' && $ip === $lage['wirt']));
    if (strpos((string) $ua, 'AlexaNG-Selbstprobe') === 0 && $selbst) { return 'eigen'; }
    $z = ax_hue_ipzahl($ip);
    if ($z === null) { return ''; }
    if ($lage['echos']) { return in_array($ip, $lage['echos'], true) ? 'echo' : ''; }
    foreach ($lage['netze'] as $n) {
        if (($z & $n[1]) === $n[0]) { return 'echo'; }
    }
    return '';
}

/** Eine abgewiesene Anfrage zaehlen (Reiter Test) und je Adresse einmal je Lauf protokollieren. */
function ax_hue_abweisen($ip, $pfad)
{
    global $ax_z, $ax_geaendert, $ax_hl_abgewiesen_log;
    $a = (isset($ax_z['abgewiesen']) && is_array($ax_z['abgewiesen'])) ? $ax_z['abgewiesen'] : array();
    $a += array('anzahl' => 0, 'ip' => '', 'zeit' => 0, 'absender' => array());
    $a['anzahl'] = (int) $a['anzahl'] + 1;
    $a['ip'] = (string) $ip;
    $a['zeit'] = time();
    $ab = is_array($a['absender']) ? $a['absender'] : array();
    $schluessel = (string) $ip;
    if (!isset($ab[$schluessel]) || !is_array($ab[$schluessel])) { $ab[$schluessel] = array('anzahl' => 0, 'zeit' => 0); }
    $ab[$schluessel]['anzahl'] = (int) $ab[$schluessel]['anzahl'] + 1;
    $ab[$schluessel]['zeit'] = time();
    uasort($ab, function ($x, $y) { return (int) $y['zeit'] - (int) $x['zeit']; });
    $a['absender'] = array_slice($ab, 0, 10, true);
    $ax_z['abgewiesen'] = $a;
    $ax_geaendert = true;
    if (!isset($ax_hl_abgewiesen_log[$schluessel])) {
        $ax_hl_abgewiesen_log[$schluessel] = 1;
        ax_log('WARN', 'Hue: Anfrage von ' . $schluessel . ' abgewiesen (nicht freigegeben): ' . substr((string) $pfad, 0, 80) . '.');
    }
}

/** Der gemerkte Zustand einer Lampe der Liste (ein, bri). */
function ax_hue_lampe_stand($id)
{
    global $ax_z;
    $s = (isset($ax_z['lampen'][$id]) && is_array($ax_z['lampen'][$id])) ? $ax_z['lampen'][$id] : array();
    $ein = (isset($s['ein']) && (int) $s['ein'] === 1) ? 1 : 0;
    $bri = (isset($s['bri']) && is_int($s['bri']) && $s['bri'] >= 1 && $s['bri'] <= 254) ? $s['bri'] : 254;
    return array('ein' => $ein, 'bri' => $bri);
}

/** Felder einer Lampe im Messstand fortschreiben ($teil leer = Zustand, sonst abfrage|schalten). */
function ax_hue_lampe_merken($id, $teil, array $werte, $zaehlen = true)
{
    global $ax_z, $ax_geaendert;
    $id = (string) $id;
    if (!isset($ax_z['lampen']) || !is_array($ax_z['lampen'])) { $ax_z['lampen'] = array(); }
    if (!isset($ax_z['lampen'][$id]) || !is_array($ax_z['lampen'][$id])) { $ax_z['lampen'][$id] = array('ein' => 0, 'bri' => 254, 'quelle' => '', 'zeit' => 0); }
    if ($teil === '') {
        foreach ($werte as $k => $v) { $ax_z['lampen'][$id][$k] = $v; }
    } else {
        $alt = (isset($ax_z['lampen'][$id][$teil]) && is_array($ax_z['lampen'][$id][$teil])) ? $ax_z['lampen'][$id][$teil] : array('anzahl' => 0);
        if ($zaehlen) { $alt['anzahl'] = (isset($alt['anzahl']) ? (int) $alt['anzahl'] : 0) + 1; }
        foreach ($werte as $k => $v) {
            if (in_array($k, array('mqtt', 'mqtt_nicht', 'gleich', 'gebremst'), true)) {
                $alt[$k] = (isset($alt[$k]) ? (int) $alt[$k] : 0) + (int) $v;
            } else {
                $alt[$k] = $v;
            }
        }
        $ax_z['lampen'][$id][$teil] = $alt;
    }
    $ax_geaendert = true;
}

/** bri 1..254 -> 1..100 %, gerundet. */
function ax_hue_prozent($bri)
{
    return max(1, min(100, (int) round(((int) $bri) * 100 / 254)));
}

/** 0..100 % -> bri 1..254. */
function ax_hue_bri($prozent)
{
    return max(1, min(254, (int) round(((float) $prozent) * 254 / 100)));
}

/** Eindeutige Kennung (uniqueid) einer Lampe der Liste - stabil je Bridge und Lampen-ID. */
function ax_hue_lampe_uid($id)
{
    global $ax_kennung;
    $h = sha1($ax_kennung['lampe'] . '|alexang-lampe|' . (int) $id);
    return implode(':', str_split(substr($h, 0, 16), 2)) . '-0b';
}

/** Eine Lampe der Liste, wie eine Hue-Bridge sie meldet. */
function ax_hue_liste_lampe(array $l)
{
    $s = ax_hue_lampe_stand((string) $l['id']);
    if ($l['art'] === 'dimmer') {
        return array(
            'state' => array('on' => (bool) $s['ein'], 'bri' => (int) $s['bri'], 'alert' => 'none', 'mode' => 'homeautomation', 'reachable' => true),
            'type' => 'Dimmable light', 'name' => (string) $l['name'], 'modelid' => 'LWB010', 'manufacturername' => 'Philips',
            'productname' => 'Hue white lamp', 'uniqueid' => ax_hue_lampe_uid($l['id']), 'swversion' => '1.46.13',
        );
    }
    if ($l['art'] === 'licht') {
        // alexa7 (Nr. 42): Licht ohne Helligkeit, damit Raumbefehle ("Licht aus") es einschliessen. Hue v1 "On/Off light"
        // (ZigBee On/Off Light), kein bri im Zustand, Archetyp classicbulb; eigene modelid statt einer Philips-Kennung,
        // die eine Helligkeit verspraeche. Nicht am Echo gemessen.
        return array(
            'state' => array('on' => (bool) $s['ein'], 'alert' => 'none', 'mode' => 'homeautomation', 'reachable' => true),
            'type' => 'On/Off light', 'name' => (string) $l['name'], 'modelid' => 'AXNG-ONOFF', 'manufacturername' => 'LoxBerry Alexa NG',
            'productname' => 'On/Off light', 'uniqueid' => ax_hue_lampe_uid($l['id']), 'swversion' => '1.0.0',
            'config' => array('archetype' => 'classicbulb', 'function' => 'functional', 'direction' => 'omnidirectional'),
        );
    }
    return array(
        'state' => array('on' => (bool) $s['ein'], 'alert' => 'none', 'mode' => 'homeautomation', 'reachable' => true),
        'type' => 'On/Off plug-in unit', 'name' => (string) $l['name'], 'modelid' => 'LOM001', 'manufacturername' => 'Philips',
        'productname' => 'Hue Smart plug', 'uniqueid' => ax_hue_lampe_uid($l['id']), 'swversion' => '1.65.11_hB798F2B',
    );
}

/** Alle Lampen fuer /api/<u>/lights: die Probe-Lampe (ID 1, mit Haken) und die Lampen der Liste. Leer = {}. */
function ax_hue_lichter(array $lage)
{
    global $ax_z, $ax_kennung;
    $aus = array();
    if (!empty($lage['probe'])) { $aus['1'] = ax_hue_lampe($ax_z, $ax_kennung); }
    foreach ($lage['lampen'] as $l) { $aus[(string) $l['id']] = ax_hue_liste_lampe($l); }
    return $aus ? $aus : new stdClass();
}

/** Die Lampe der Liste zu einer Hue-ID, oder null. */
function ax_hue_lampe_finden(array $lage, $id)
{
    foreach ($lage['lampen'] as $l) {
        if ((string) $l['id'] === (string) $id) { return $l; }
    }
    return null;
}

/** Abfrage zaehlen: je gezeigter Lampe der Liste die letzte Abfrage merken. */
function ax_hue_abfrage_merken(array $lampen, $ip)
{
    foreach ($lampen as $l) { ax_hue_lampe_merken((string) $l['id'], 'abfrage', array('ip' => (string) $ip, 'zeit' => time())); }
}

/**
 * Werte einer Lampe senden - mit Wiederholsperre je Thema (gleicher Wert
 * binnen 2 s nur einmal). Rueckgabe array(versucht, gescheitert, unterdrueckt).
 */
function ax_hue_lampe_senden(array $l, array $paare)
{
    global $ax_hl_gesendet;
    $jetzt = microtime(true);
    $senden = array();
    $gleich = 0;
    foreach ($paare as $k => $v) {
        $thema = $l['kuerzel'] . '/' . $k;
        if (isset($ax_hl_gesendet[$thema]) && $ax_hl_gesendet[$thema][0] === (string) $v && $jetzt - $ax_hl_gesendet[$thema][1] < AX_HUEL_GLEICH_S) {
            $gleich++;
            continue;
        }
        $senden[$k] = $v;
    }
    if (!$senden) { return array(0, 0, $gleich); }
    list($n, $f) = ax_hue_lampe_melden($l['kuerzel'], $senden, ax_config());
    if ($n > 0 && $f === 0) {
        foreach ($senden as $k => $v) { $ax_hl_gesendet[$l['kuerzel'] . '/' . $k] = array((string) $v, $jetzt); }
    }
    return array($n, $f, $gleich);
}

/**
 * PUT /api/<u>/lights/<id>/state fuer eine Lampe der Liste. Rueckgabe: die
 * Antwortliste der Hue-Schnittstelle (success/error je Feld).
 */
function ax_hue_lampe_put(array $l, $rumpf, $ip)
{
    global $ax_hl_takt, $ax_hl_bremse_log;
    $id = (string) $l['id'];
    $adr = '/lights/' . $id . '/state';
    $d = json_decode((string) $rumpf, true);
    if (!is_array($d)) { return array(array('error' => array('type' => 2, 'address' => $adr, 'description' => 'body contains invalid json'))); }
    $erg = array();
    $neu_ein = null;
    $neu_bri = null;
    if (array_key_exists('on', $d)) {
        if (!is_bool($d['on'])) {
            $erg[] = array('error' => array('type' => 7, 'address' => $adr . '/on', 'description' => 'invalid value, on, for parameter, on'));
        } else {
            $neu_ein = $d['on'] ? 1 : 0;
        }
    }
    if (array_key_exists('bri', $d)) {
        if ($l['art'] !== 'dimmer') {
            $erg[] = array('error' => array('type' => 6, 'address' => $adr . '/bri', 'description' => 'parameter, bri, not available'));
        } elseif (!is_int($d['bri']) || $d['bri'] < 1 || $d['bri'] > 254) {
            $erg[] = array('error' => array('type' => 7, 'address' => $adr . '/bri', 'description' => 'invalid value for parameter, bri'));
        } else {
            $neu_bri = $d['bri'];
        }
    }
    if ($neu_ein === null && $neu_bri === null) {
        return $erg ? $erg : array(array('error' => array('type' => 6, 'address' => $adr, 'description' => 'parameter not available')));
    }
    // Minutenbremse je Lampe
    $jetzt = microtime(true);
    $t = array();
    foreach ((isset($ax_hl_takt[$id]) ? $ax_hl_takt[$id] : array()) as $z) { if ($jetzt - $z < 60.0) { $t[] = $z; } }
    if (count($t) >= AX_HUEL_MINUTE_MAX) {
        $ax_hl_takt[$id] = $t;
        ax_hue_lampe_merken($id, 'schalten', array('ip' => (string) $ip, 'zeit' => time(), 'gebremst' => 1), false);
        if (!isset($ax_hl_bremse_log[$id]) || time() - $ax_hl_bremse_log[$id] >= 60) {
            $ax_hl_bremse_log[$id] = time();
            ax_log('WARN', 'Hue: Lampe "' . $l['name'] . '" (' . $l['kuerzel'] . ') - mehr als ' . AX_HUEL_MINUTE_MAX
                . ' Schaltbefehle in einer Minute von ' . $ip . ', abgewiesen (Hue-Fehler 901).');
        }
        return array(array('error' => array('type' => 901, 'address' => $adr,
            'description' => 'Internal error, rate limit: at most ' . AX_HUEL_MINUTE_MAX . ' commands per minute')));
    }
    $t[] = $jetzt;
    $ax_hl_takt[$id] = $t;
    $s = ax_hue_lampe_stand($id);
    if ($neu_bri !== null) {
        $s['bri'] = $neu_bri;
        if ($neu_ein === null) { $neu_ein = 1; }   // Helligkeit ohne "on": Alexa meint "an, auf x %"
    }
    $s['ein'] = $neu_ein;
    $paare = array('ein' => $s['ein']);
    // alexa7 (Nr. 42): beim Dimmer die Helligkeit nur, wenn der PUT "bri" traegt. Ein blosses "on":true schickt nur
    // ein=1 - sonst sprang der Loxone-Dimmer kurz auf die alte Helligkeit (gemessen 03.10. mit 0.9.6: "on":true und
    // "bri" in derselben Sekunde ergaben helligkeit=100, dann 40). Aus bleibt ein=0 und helligkeit=0 wie in 0.9.6.
    if ($l['art'] === 'dimmer' && ($neu_bri !== null || !$s['ein'])) { $paare['helligkeit'] = $s['ein'] ? ax_hue_prozent($s['bri']) : 0; }
    list($n, $f, $gleich) = ax_hue_lampe_senden($l, $paare);
    $gesendet = ($n > 0 && $f === 0);
    $wert = 'ein=' . $paare['ein'] . (isset($paare['helligkeit']) ? ' helligkeit=' . $paare['helligkeit'] : '');
    ax_hue_lampe_merken($id, '', array('ein' => $s['ein'], 'bri' => $s['bri'], 'quelle' => 'alexa', 'zeit' => time()));
    ax_hue_lampe_merken($id, 'schalten', array('ip' => (string) $ip, 'zeit' => time(), 'wert' => $wert,
        'mqtt' => $gesendet ? 1 : 0, 'mqtt_nicht' => ($n > 0 && !$gesendet) || ($n === 0 && $gleich === 0) ? 1 : 0, 'gleich' => ($n === 0 && $gleich > 0) ? 1 : 0));
    ax_log('INFO', 'Hue: Lampe "' . $l['name'] . '" (' . $l['kuerzel'] . ') ' . $wert . ' von ' . $ip . ', MQTT '
        . ($gesendet ? 'gesendet' . ($gleich ? ' (' . $gleich . ' gleicher Wert binnen 2 s nicht erneut)' : '')
           : (($n === 0 && $gleich > 0) ? 'nicht erneut gesendet (gleicher Wert binnen 2 s)' : 'nicht gesendet (aus oder Broker nicht erreichbar)')) . '.');
    if ($neu_bri !== null) { $erg[] = array('success' => array($adr . '/bri' => $neu_bri)); }
    if (array_key_exists('on', $d) && is_bool($d['on'])) { $erg[] = array('success' => array($adr . '/on' => $d['on'])); }
    return $erg;
}

/* ---------------- MQTT-Leser fuer die Rueckmeldung aus Loxone ---------------- */

function ax_hue_abo_stand(array $werte)
{
    global $ax_z, $ax_geaendert;
    $a = (isset($ax_z['mqtt_abo']) && is_array($ax_z['mqtt_abo'])) ? $ax_z['mqtt_abo'] : array();
    $a += array('verbunden' => 0, 'seit' => 0, 'fehler' => '', 'empfangen' => 0, 'zeit' => 0, 'ungueltig' => 0, 'unbekannt' => 0);
    foreach ($werte as $k => $v) {
        $a[$k] = in_array($k, array('empfangen', 'ungueltig', 'unbekannt'), true) ? (int) $a[$k] + (int) $v : $v;
    }
    if (!isset($ax_z['mqtt_abo']) || $ax_z['mqtt_abo'] !== $a) { $ax_z['mqtt_abo'] = $a; $ax_geaendert = true; }
}

function ax_hue_abo_trennen($grund)
{
    global $ax_ab;
    if ($ax_ab['s']) { @fwrite($ax_ab['s'], chr(0xE0) . chr(0)); @fclose($ax_ab['s']); }
    $ax_ab['s'] = null;
    $ax_ab['puffer'] = '';
    if ($grund !== '') {
        $ax_ab['naechster'] = time() + $ax_ab['warte'];
        $ax_ab['warte'] = min(60, $ax_ab['warte'] * 2);
    }
    ax_hue_abo_stand(array('verbunden' => 0, 'fehler' => $grund));
}

function ax_hue_abo_text($s)
{
    return pack('n', strlen($s)) . $s;
}

function ax_hue_abo_laenge($n)
{
    $s = '';
    do {
        $b = $n % 128;
        $n = intdiv($n, 128);
        if ($n > 0) { $b |= 128; }
        $s .= chr($b);
    } while ($n > 0);
    return $s;
}

function ax_hue_abo_genau($s, $n)
{
    $d = '';
    $ende = microtime(true) + 3;
    while (strlen($d) < $n && microtime(true) < $ende) {
        $t = @fread($s, $n - strlen($d));
        if ($t === false || ($t === '' && feof($s))) { break; }
        $d .= $t;
    }
    return $d;
}

/** Verbinden, anmelden, die zwei Themen abonnieren (QoS 0). Blockiert hoechstens einige Sekunden. */
function ax_hue_abo_verbinden(array $lage, $sig)
{
    global $ax_ab;
    $b = $lage['broker'];
    $s = @stream_socket_client('tcp://' . $b['host'] . ':' . (int) $b['port'], $en, $es, 3);
    if ($s === false) {
        ax_log_einmal('abo_verbindung', 'WARN', 'Hue: Rueckmeldungen aus Loxone - keine Verbindung zum Broker ' . $b['host'] . ':' . (int) $b['port'] . ' (' . $es . ').');
        ax_hue_abo_trennen('VERBINDUNG');
        return;
    }
    stream_set_timeout($s, 3);
    $flags = 0x02;
    $anm = '';
    if ($b['user'] !== '') {
        $flags |= 0x80;
        $anm .= ax_hue_abo_text($b['user']);
        if ($b['pass'] !== '') { $flags |= 0x40; $anm .= ax_hue_abo_text($b['pass']); }
    }
    $r = ax_hue_abo_text('MQTT') . chr(4) . chr($flags) . pack('n', 60) . ax_hue_abo_text('alexang-hue-abo-' . getmypid()) . $anm;
    @fwrite($s, chr(0x10) . ax_hue_abo_laenge(strlen($r)) . $r);
    $ack = ax_hue_abo_genau($s, 4);
    if (strlen($ack) !== 4 || ord($ack[0]) !== 0x20 || ord($ack[3]) !== 0) {
        $rc = strlen($ack) === 4 ? ord($ack[3]) : -1;
        @fclose($s);
        ax_log_einmal('abo_connack', 'WARN', 'Hue: Rueckmeldungen aus Loxone - Anmeldung am Broker abgewiesen (CONNACK ' . $rc . ').');
        ax_hue_abo_trennen('CONNACK');
        return;
    }
    $p = $lage['praefix'] . '/hue/+/';
    $r = pack('n', 1) . ax_hue_abo_text($p . 'status') . chr(0) . ax_hue_abo_text($p . 'status_helligkeit') . chr(0);
    @fwrite($s, chr(0x82) . ax_hue_abo_laenge(strlen($r)) . $r);
    $sa = ax_hue_abo_genau($s, 6);
    if (strlen($sa) !== 6 || ord($sa[0]) !== 0x90 || ord($sa[4]) === 0x80 || ord($sa[5]) === 0x80) {
        @fclose($s);
        ax_log_einmal('abo_suback', 'WARN', 'Hue: Rueckmeldungen aus Loxone - Abo vom Broker abgewiesen.');
        ax_hue_abo_trennen('SUBACK');
        return;
    }
    stream_set_blocking($s, false);
    $ax_ab['s'] = $s;
    $ax_ab['puffer'] = '';
    $ax_ab['sig'] = $sig;
    $ax_ab['warte'] = 5;
    $ax_ab['ping'] = time();
    ax_hue_abo_stand(array('verbunden' => 1, 'seit' => time(), 'fehler' => ''));
    ax_log('INFO', 'Hue: Rueckmeldungen aus Loxone abonniert (' . $p . 'status, ' . $p . 'status_helligkeit) an ' . $b['host'] . ':' . (int) $b['port'] . '.');
}

/** Eine Protokollzeile je Schluessel hoechstens einmal je Stunde (in beiden Arten verfuegbar). */
function ax_log_einmal($schluessel, $stufe, $text)
{
    static $zuletzt = array();
    if (isset($zuletzt[$schluessel]) && time() - $zuletzt[$schluessel] < 3600) { return; }
    $zuletzt[$schluessel] = time();
    ax_log($stufe, $text);
}

/** Einmal je Sekunde aus der Schleife: verbinden, trennen, Lebenszeichen. */
function ax_hue_abo_pflege()
{
    global $ax_ab;
    if (time() === $ax_ab['pflege']) { return; }
    $ax_ab['pflege'] = time();
    $lage = ax_hue_lage();
    $soll = $lage['lampen'] && !empty($lage['mqtt_ein']) && $lage['broker']['host'] !== '';
    $sig = sha1(json_encode(array($lage['broker'], $lage['praefix'])));
    if ($ax_ab['s'] && (!$soll || $sig !== $ax_ab['sig'])) {
        ax_hue_abo_trennen('');
        $ax_ab['naechster'] = 0;
        $ax_ab['warte'] = 5;
    }
    if (!$soll) { ax_hue_abo_stand(array('verbunden' => 0, 'fehler' => '')); return; }
    if (!$ax_ab['s']) {
        if (time() >= $ax_ab['naechster']) { ax_hue_abo_verbinden($lage, $sig); }
        return;
    }
    if (time() - $ax_ab['ping'] >= AX_HUEL_PING_S) {
        $ax_ab['ping'] = time();
        if (@fwrite($ax_ab['s'], chr(0xC0) . chr(0)) !== 2) { ax_hue_abo_trennen('GETRENNT'); }
    }
}

/** Lesbar: alle vollstaendigen Pakete verarbeiten (PUBLISH -> Rueckmeldung; PINGRESP, SUBACK uebergehen). */
function ax_hue_abo_lesen()
{
    global $ax_ab;
    $neu = @fread($ax_ab['s'], 65536);
    if ($neu === false || ($neu === '' && feof($ax_ab['s']))) { ax_hue_abo_trennen('GETRENNT'); return; }
    $ax_ab['puffer'] .= $neu;
    while (strlen($ax_ab['puffer']) >= 2) {
        $p = $ax_ab['puffer'];
        $n = 0;
        $f = 1;
        $i = 1;
        $fertig = false;
        while ($i < strlen($p) && $i <= 4) {
            $b = ord($p[$i]);
            $n += ($b & 127) * $f;
            $f *= 128;
            $i++;
            if (!($b & 128)) { $fertig = true; break; }
        }
        if (!$fertig) {
            if ($i > 4) { ax_hue_abo_trennen('PAKET'); }
            return;
        }
        if ($n > AX_HUEL_PAKET_MAX) { ax_hue_abo_trennen('PAKET'); return; }
        if (strlen($p) < $i + $n) { return; }
        $kopf = ord($p[0]);
        $rumpf = substr($p, $i, $n);
        $ax_ab['puffer'] = (string) substr($p, $i + $n);
        if (($kopf >> 4) === 3 && strlen($rumpf) >= 2) {
            $qos = ($kopf >> 1) & 3;
            $tl = unpack('n', substr($rumpf, 0, 2));
            $tl = $tl[1];
            $thema = substr($rumpf, 2, $tl);
            $j = 2 + $tl;
            if ($qos > 0) {
                $pid = substr($rumpf, $j, 2);
                $j += 2;
                if ($qos === 1 && strlen($pid) === 2) { @fwrite($ax_ab['s'], chr(0x40) . chr(2) . $pid); }
            }
            ax_hue_rueckmeldung((string) $thema, (string) substr($rumpf, $j));
        }
    }
}

/** Eine Rueckmeldung aus Loxone: .../hue/<kuerzel>/status (0/1) oder .../status_helligkeit (0..100). */
function ax_hue_rueckmeldung($thema, $wert)
{
    $lage = ax_hue_lage();
    $p = $lage['praefix'] . '/hue/';
    if (strpos($thema, $p) !== 0 || !preg_match('#^([a-z0-9_]{1,32})/(status|status_helligkeit)\z#', substr($thema, strlen($p)), $m)) { return; }
    $w = trim($wert);
    if ($w === '') { return; }   // geloeschtes retained Thema - kein Wert
    $l = null;
    foreach ($lage['lampen'] as $x) { if ($x['kuerzel'] === $m[1]) { $l = $x; break; } }
    if ($l === null) { ax_hue_abo_stand(array('unbekannt' => 1)); return; }
    $zahl = preg_match('/^-?[0-9]{1,3}(\.[0-9]{1,6})?\z/', $w) ? (float) $w : null;
    $id = (string) $l['id'];
    $s = ax_hue_lampe_stand($id);
    if ($m[2] === 'status' && ($zahl === 0.0 || $zahl === 1.0)) {
        $s['ein'] = (int) $zahl;
    } elseif ($m[2] === 'status_helligkeit' && $l['art'] === 'dimmer' && $zahl !== null && $zahl >= 0 && $zahl <= 100) {
        if ($zahl == 0) { $s['ein'] = 0; } else { $s['ein'] = 1; $s['bri'] = ax_hue_bri($zahl); }
    } else {
        ax_hue_abo_stand(array('ungueltig' => 1));
        ax_log_einmal('abo_ungueltig_' . $l['kuerzel'] . '_' . $m[2], 'WARN', 'Hue: Rueckmeldung ' . $thema . ' = "' . substr($w, 0, 20)
            . '" nicht verwendet (erwartet ' . ($m[2] === 'status' ? '0 oder 1' : '0 bis 100 bei einem Dimmer') . ').');
        return;
    }
    ax_hue_lampe_merken($id, '', array('ein' => $s['ein'], 'bri' => $s['bri'], 'quelle' => 'loxone', 'zeit' => time()));
    ax_hue_abo_stand(array('empfangen' => 1, 'zeit' => time()));
}

/* ---------------- SSDP ---------------- */
$ax_antworten = array('sekunde' => 0, 'anzahl' => 0);

function ax_hue_ssdp($roh, $peer)
{
    global $ax_z, $ax_udp, $ax_port, $ax_kennung, $ax_antworten, $ax_geaendert;
    list($ip, $pt) = ax_hue_peer($peer);
    if ($ip === '' || $pt === 0) { return; }
    $zeilen = preg_split('/\r?\n/', (string) $roh);
    if (!isset($zeilen[0]) || stripos($zeilen[0], 'M-SEARCH * HTTP/1.1') !== 0) { return; }   // NOTIFY und Antworten: nicht unser Fall
    $kopf = array();
    foreach (array_slice($zeilen, 1) as $z) {
        $pos = strpos($z, ':');
        if ($pos === false) { continue; }
        $kopf[strtoupper(trim(substr($z, 0, $pos)))] = trim(substr($z, $pos + 1));
    }
    $st = isset($kopf['ST']) ? substr((string) preg_replace('/[^\x21-\x7E]/', '', $kopf['ST']), 0, 120) : '';
    $man = isset($kopf['MAN']) ? strtolower($kopf['MAN']) : '';
    $passt = strpos($man, 'ssdp:discover') !== false
          && in_array(strtolower($st), array('ssdp:all', 'upnp:rootdevice', 'urn:schemas-upnp-org:device:basic:1'), true);
    if (!$passt) {
        $ax_z['andere'] = (isset($ax_z['andere']) ? (int) $ax_z['andere'] : 0) + 1;
        $ax_geaendert = true;
        return;
    }
    $s = isset($ax_z['suchen']) && is_array($ax_z['suchen']) ? $ax_z['suchen'] : array();
    $s += array('anzahl' => 0, 'beantwortet' => 0, 'ip' => '', 'zeit' => 0, 'st' => '', 'absender' => array());
    $s['anzahl'] = (int) $s['anzahl'] + 1;
    $s['ip'] = $ip;
    $s['zeit'] = time();
    $s['st'] = $st;
    $ab = is_array($s['absender']) ? $s['absender'] : array();
    if (!isset($ab[$ip])) {
        ax_log('INFO', 'Hue-Probe: erste Suche von ' . $ip . ' (ST ' . $st . ').');
        $ab[$ip] = array('anzahl' => 0, 'erste' => time(), 'zeit' => 0, 'st' => '');
    }
    $ab[$ip]['anzahl'] = (int) $ab[$ip]['anzahl'] + 1;
    $ab[$ip]['zeit'] = time();
    $ab[$ip]['st'] = $st;
    uasort($ab, function ($a, $b) { return (int) $b['zeit'] - (int) $a['zeit']; });
    $s['absender'] = array_slice($ab, 0, AX_HUE_ABSENDER_MAX, true);
    // Hoechstens AX_HUE_ANTWORTEN_JE_S Antworten je Sekunde - die Probe soll kein Verstaerker sein.
    if ($ax_antworten['sekunde'] !== time()) { $ax_antworten = array('sekunde' => time(), 'anzahl' => 0); }
    $eigene_ip = ax_hue_eigene_ip($ip);
    if ($ax_antworten['anzahl'] < AX_HUE_ANTWORTEN_JE_S && $eigene_ip !== '') {
        $ax_antworten['anzahl']++;
        $antwort_st = (strtolower($st) === 'ssdp:all') ? 'urn:schemas-upnp-org:device:basic:1' : $st;
        $usn = 'uuid:' . $ax_kennung['uuid'] . (strtolower($antwort_st) === 'upnp:rootdevice' ? '::upnp:rootdevice' : '');
        $msg = "HTTP/1.1 200 OK\r\n"
             . "HOST: 239.255.255.250:1900\r\n"
             . "EXT:\r\n"
             . "CACHE-CONTROL: max-age=100\r\n"
             . 'LOCATION: http://' . $eigene_ip . ':' . (int) $ax_port . "/description.xml\r\n"
             . "SERVER: Linux/3.14.0 UPnP/1.0 IpBridge/1.41.0\r\n"
             . 'hue-bridgeid: ' . $ax_kennung['bridgeid'] . "\r\n"
             . 'ST: ' . $antwort_st . "\r\n"
             . 'USN: ' . $usn . "\r\n"
             . "\r\n";
        if (@stream_socket_sendto($ax_udp, $msg, 0, $ip . ':' . $pt) === strlen($msg)) {
            $s['beantwortet'] = (int) $s['beantwortet'] + 1;
            $ax_z['eigene_ip'] = $eigene_ip;
        }
    }
    $ax_z['suchen'] = $s;
    $ax_geaendert = true;
}

/* ---------------- HTTP-Kunden ---------------- */
$ax_kunden = array();

function ax_hue_kunde_fertig($id, $code, $typ, $rumpf)
{
    global $ax_kunden;
    $texte = array(200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed', 413 => 'Payload Too Large');
    $kopf = 'HTTP/1.1 ' . (int) $code . ' ' . (isset($texte[$code]) ? $texte[$code] : 'OK') . "\r\n"
          . 'Content-Type: ' . $typ . "\r\n"
          . 'Content-Length: ' . strlen($rumpf) . "\r\n"
          . "Connection: close\r\n\r\n";
    $c = $ax_kunden[$id]['c'];
    stream_set_blocking($c, true);
    stream_set_timeout($c, 2);
    @fwrite($c, $kopf . $rumpf);
    @fclose($c);
    unset($ax_kunden[$id]);
}

function ax_hue_kunde_lesen($id)
{
    global $ax_kunden;
    $k = &$ax_kunden[$id];
    $neu = @fread($k['c'], 4096);
    if ($neu === false || ($neu === '' && feof($k['c']))) { @fclose($k['c']); unset($ax_kunden[$id]); return; }
    $k['puffer'] .= $neu;
    if (strlen($k['puffer']) > AX_HUE_KOPF_MAX) { ax_hue_kunde_fertig($id, 413, 'text/plain; charset=utf-8', "zu gross\n"); return; }
    $ende = strpos($k['puffer'], "\r\n\r\n");
    if ($ende === false) { return; }
    $kopfteil = substr($k['puffer'], 0, $ende);
    $zeilen = explode("\r\n", $kopfteil);
    if (!preg_match('#^(GET|PUT|POST) (/[\x21-\x7E]{0,511}) HTTP/1\.[01]\z#', $zeilen[0], $m)) {
        ax_hue_kunde_fertig($id, 400, 'text/plain; charset=utf-8', "unerwartete Anfrage\n");
        return;
    }
    $laenge = 0;
    $ua = '';
    foreach (array_slice($zeilen, 1) as $z) {
        if (preg_match('/^Content-Length:\s*(\d{1,5})\s*\z/i', $z, $mm)) { $laenge = (int) $mm[1]; }
        if (preg_match('/^User-Agent:\s*(.*)\z/i', $z, $mm)) { $ua = $mm[1]; }
    }
    if ($laenge > AX_HUE_KOPF_MAX) { ax_hue_kunde_fertig($id, 413, 'text/plain; charset=utf-8', "zu gross\n"); return; }
    $rumpf = substr($k['puffer'], $ende + 4);
    if (strlen($rumpf) < $laenge) { return; }
    $rumpf = substr($rumpf, 0, $laenge);
    // Fassung 2 (alexa6): die Selbstprobe gilt nur vom LoxBerry selbst (Wirt, eigene Adresse, 127.x).
    global $ax_hl_zugang;
    $lokal = (string) preg_replace('/:\d+$/', '', (string) stream_socket_get_name($k['c'], false));
    $ax_hl_zugang = ax_hue_zugang($k['ip'], $ua, $lokal, ax_hue_lage(true));
    $eigen = ($ax_hl_zugang === 'eigen');
    $eigene_ip = (AX_HUE_CONTAINER !== '') ? ax_hue_container_ip() : $lokal;
    list($code, $typ, $antwort) = ax_hue_anfrage($m[1], $m[2], $rumpf, $k['ip'], $eigen, $eigene_ip);
    ax_hue_kunde_fertig($id, $code, $typ, $antwort);
}

/* ---------------- Schleife ---------------- */
ax_log('INFO', 'Hue-Probe: gestartet (PID ' . getmypid() . ', HTTP-Port ' . $ax_port . ', SSDP 1900, PHP ' . PHP_VERSION
    . (AX_HUE_CONTAINER !== '' ? ', im Container, eigene Adresse ' . ax_hue_container_ip() : '') . ').');
ax_hue_zustand_schreiben(true);
$ax_naechste_pruefung = time() + 5;
$ax_kunde_nr = 0;
while (true) {
    $ax_r = array('udp' => $ax_udp, 'http' => $ax_http);
    foreach ($ax_kunden as $ax_id => $ax_kd) { $ax_r['k' . $ax_id] = $ax_kd['c']; }
    if ($ax_ab['s']) { $ax_r['mqtt'] = $ax_ab['s']; }   // alexa6: Rueckmeldungen aus Loxone
    $ax_w = null; $ax_e = null;
    $ax_n = @stream_select($ax_r, $ax_w, $ax_e, 1);
    if ($ax_n === false) { usleep(200000); }
    foreach ((array) $ax_r as $ax_name => $ax_strom) {
        if ($ax_strom === $ax_udp) {
            for ($ax_i = 0; $ax_i < 50; $ax_i++) {
                $ax_peer = '';
                $ax_d = @stream_socket_recvfrom($ax_udp, 2048, 0, $ax_peer);
                if ($ax_d === false || $ax_d === '') { break; }
                ax_hue_ssdp($ax_d, $ax_peer);
            }
        } elseif ($ax_strom === $ax_http) {
            $ax_c = @stream_socket_accept($ax_http, 0, $ax_peer);
            if ($ax_c === false) { continue; }
            if (count($ax_kunden) >= AX_HUE_KUNDEN_MAX) { @fclose($ax_c); continue; }
            stream_set_blocking($ax_c, false);
            list($ax_kip) = ax_hue_peer($ax_peer);
            $ax_kunde_nr++;
            $ax_kunden[$ax_kunde_nr] = array('c' => $ax_c, 'ip' => $ax_kip, 'seit' => time(), 'puffer' => '');
        } elseif ($ax_name === 'mqtt') {
            if ($ax_ab['s']) { ax_hue_abo_lesen(); }
        } else {
            $ax_id = (int) substr((string) $ax_name, 1);
            if (isset($ax_kunden[$ax_id])) { ax_hue_kunde_lesen($ax_id); }
        }
    }
    foreach ($ax_kunden as $ax_id => $ax_kd) {
        if (time() - $ax_kd['seit'] > AX_HUE_KUNDE_S) { @fclose($ax_kd['c']); unset($ax_kunden[$ax_id]); }
    }
    ax_hue_abo_pflege();
    ax_hue_zustand_schreiben();
    // Im Container legt das Plugin an und entfernt; dort keine Selbstpruefung (sonst endete er, und Docker startete ihn neu).
    if (AX_HUE_CONTAINER === '' && time() >= $ax_naechste_pruefung) {
        $ax_naechste_pruefung = time() + 5;
        $ax_cfg2 = ax_config();
        if (empty($ax_cfg2['hue_ein']) || empty($ax_cfg2['aktiv']) || (int) $ax_cfg2['hue_port'] !== $ax_port
            || ax_hue_art($ax_cfg2) !== 'loxberry') {
            $ax_z['ende'] = time();
            ax_hue_zustand_schreiben(true);
            ax_log('INFO', 'Hue-Probe: ' . ((int) $ax_cfg2['hue_port'] !== $ax_port ? 'Port geaendert'
                : (ax_hue_art($ax_cfg2) !== 'loxberry' ? 'Art eigene Netzadresse gewaehlt' : 'ausgeschaltet')) . ' - der Dienst endet.');
            exit(0);
        }
        $ax_cfg = $ax_cfg2;
    }
}
