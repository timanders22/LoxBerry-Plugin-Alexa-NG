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

foreach ($argv as $ax_i => $ax_a) {
    if ($ax_i === 0) { continue; }
    fwrite(STDERR, 'Unbekannter Schalter: ' . $ax_a . "\n");
    exit(2);
}

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
$ax_port = (int) $ax_cfg['hue_port'];

/* ---------------- Zustand (nur dieser Dienst schreibt ihn) ---------------- */
$ax_z = ax_hue_lesen();
$ax_z['pid'] = getmypid();
$ax_z['start'] = time();
$ax_z['ende'] = 0;
$ax_z['port'] = $ax_port;
$ax_z['fehler'] = '';
$ax_z['lampe'] = array('ein' => 0, 'bri' => 254);
$ax_geschrieben = 0.0;
$ax_geaendert = true;

function ax_hue_zustand_schreiben($erzwingen = false)
{
    global $ax_z, $ax_geschrieben, $ax_geaendert, $ax_p;
    if (!$ax_geaendert && !$erzwingen) { return; }
    if (!$erzwingen && microtime(true) - $ax_geschrieben < 1.0) { return; }
    ax_write_json($ax_p['datadir'] . '/hue_probe.json', $ax_z, 0644);
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
$ax_h = sha1('alexang-hue|' . (string) gethostname() . '|' . $ax_p['lbhome']);
$ax_mac = substr($ax_h, 0, 12);
$ax_kennung = array(
    'mac'      => $ax_mac,
    'bridgeid' => strtoupper(substr($ax_mac, 0, 6) . 'fffe' . substr($ax_mac, 6, 6)),
    'uuid'     => '2f402f80-da50-11e1-9b23-' . $ax_mac,
    'lampe'    => implode(':', str_split(substr($ax_h, 12, 16), 2)) . '-0b',
    'nutzer'   => substr(sha1('alexang-hue-nutzer|' . $ax_h), 0, 32),
);

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
if (!$ax_s || !@socket_set_option($ax_s, IPPROTO_IP, MCAST_JOIN_GROUP, array('group' => AX_HUE_GRUPPE, 'interface' => 0))) {
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
        if (!$eigen) { ax_hue_zaehlen('abfragen', $ip); }
        return array(200, 'application/json', ax_hue_json(array('lights' => array('1' => ax_hue_lampe($ax_z, $k)),
            'groups' => new stdClass(), 'config' => ax_hue_konfig($eigene_ip, $k), 'schedules' => new stdClass(),
            'scenes' => new stdClass(), 'rules' => new stdClass(), 'sensors' => new stdClass(), 'resourcelinks' => new stdClass())));
    }
    if ($methode === 'GET' && $rest === '/lights') {
        if (!$eigen) { ax_hue_zaehlen('abfragen', $ip); }
        return array(200, 'application/json', ax_hue_json(array('1' => ax_hue_lampe($ax_z, $k))));
    }
    if ($methode === 'GET' && $rest === '/lights/1') {
        if (!$eigen) { ax_hue_zaehlen('abfragen', $ip); }
        return array(200, 'application/json', ax_hue_json(ax_hue_lampe($ax_z, $k)));
    }
    if ($methode === 'GET' && $rest === '/config') {
        return array(200, 'application/json', ax_hue_json(ax_hue_konfig($eigene_ip, $k)));
    }
    if ($methode === 'GET' && in_array($rest, array('/groups', '/scenes', '/schedules', '/rules', '/sensors', '/resourcelinks'), true)) {
        return array(200, 'application/json', '{}');
    }
    if ($methode === 'PUT' && $rest === '/lights/1/state') {
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
    return $fehler(3, $rest === '' ? '/' : $rest, 'resource, ' . ($rest === '' ? '/' : $rest) . ', not available');
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
    $eigen = (strpos($ua, 'AlexaNG-Selbstprobe') === 0);
    $eigene_ip = (string) preg_replace('/:\d+$/', '', (string) stream_socket_get_name($k['c'], false));
    list($code, $typ, $antwort) = ax_hue_anfrage($m[1], $m[2], $rumpf, $k['ip'], $eigen, $eigene_ip);
    ax_hue_kunde_fertig($id, $code, $typ, $antwort);
}

/* ---------------- Schleife ---------------- */
ax_log('INFO', 'Hue-Probe: gestartet (PID ' . getmypid() . ', HTTP-Port ' . $ax_port . ', SSDP 1900, PHP ' . PHP_VERSION . ').');
ax_hue_zustand_schreiben(true);
$ax_naechste_pruefung = time() + 5;
$ax_kunde_nr = 0;
while (true) {
    $ax_r = array('udp' => $ax_udp, 'http' => $ax_http);
    foreach ($ax_kunden as $ax_id => $ax_kd) { $ax_r['k' . $ax_id] = $ax_kd['c']; }
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
        } else {
            $ax_id = (int) substr((string) $ax_name, 1);
            if (isset($ax_kunden[$ax_id])) { ax_hue_kunde_lesen($ax_id); }
        }
    }
    foreach ($ax_kunden as $ax_id => $ax_kd) {
        if (time() - $ax_kd['seit'] > AX_HUE_KUNDE_S) { @fclose($ax_kd['c']); unset($ax_kunden[$ax_id]); }
    }
    ax_hue_zustand_schreiben();
    if (time() >= $ax_naechste_pruefung) {
        $ax_naechste_pruefung = time() + 5;
        $ax_cfg2 = ax_config();
        if (empty($ax_cfg2['hue_ein']) || empty($ax_cfg2['aktiv']) || (int) $ax_cfg2['hue_port'] !== $ax_port) {
            $ax_z['ende'] = time();
            ax_hue_zustand_schreiben(true);
            ax_log('INFO', 'Hue-Probe: ' . ((int) $ax_cfg2['hue_port'] !== $ax_port ? 'Port geaendert' : 'ausgeschaltet') . ' - der Dienst endet.');
            exit(0);
        }
        $ax_cfg = $ax_cfg2;
    }
}
