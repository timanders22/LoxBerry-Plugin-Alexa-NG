<?php
/**
 * Alexa NG - Hue-Probe im eigenen Container (Entscheidung Nr. 41, alexa5;
 * Vorabfassung, nicht am Geraet erprobt)
 *
 * Wird NUR von bin/ax_hue.php geladen, wenn es mit --container=<ordner>
 * laeuft - im Container des Plugins (Abbild aus bin/hue_docker/Dockerfile:
 * das offizielle php:8.4-cli mit den Erweiterungen sockets und pcntl). Dort
 * gibt es keinen LoxBerry und keine ax_lib.php. Diese Datei stellt die
 * wenigen Funktionen, die ax_hue.php braucht, mit derselben Bedeutung bereit:
 *   ax_paths, ax_keine_wurzel_abbruch, ax_config, ax_log, ax_write_json,
 *   ax_hue_lesen, ax_hue_melden
 * dazu die Konfiguration des Containers (<ordner>/hue_container.json, 0600,
 * vom Plugin geschrieben: eigene IP, Kennung der Nachbildung, Broker-Zugang)
 * und einen kleinen MQTT-Sender (3.1.1, QoS 1, ohne Fremdbibliothek: CONNECT
 * mit Anmeldung, CONNACK, PUBLISH, PUBACK, DISCONNECT).
 *
 * Eingehaengt sind nur <ordner> (data/plugins/<ordner>/hue, lesen und
 * schreiben), <log> (log/plugins/<ordner>) und diese beiden Dateien (nur
 * lesend). Der Container laeuft als Benutzer des Plugins, nie als root.
 *
 * Unterbau: PHP 7.4 und 8.x (im Abbild 8.4); keine match-, keine
 * str_contains-, keine Nullsafe-Ausdruecke.
 */

$AX_HUE_C = array('dir' => '', 'log' => '');

/** Ordner pruefen, Konfiguration lesen; ohne brauchbare Konfiguration endet der Dienst mit Grund. */
function ax_hue_container_start($dir, $logdir)
{
    global $AX_HUE_C;
    $dir = rtrim($dir, '/');
    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, 'ax_hue.php: Ordner ' . $dir . " fehlt - im Container nicht eingehaengt?\n");
        exit(1);
    }
    $AX_HUE_C['dir'] = $dir;
    $logdir = rtrim((string) $logdir, '/');
    $AX_HUE_C['log'] = ($logdir !== '' && is_dir($logdir)) ? $logdir . '/alexang.log' : $dir . '/hue.log';
    if (ax_hue_container_konfig() === null) {
        $z = ax_hue_lesen();
        $z['pid'] = getmypid();
        $z['start'] = time();
        $z['ende'] = time();
        $z['fehler'] = 'HUE_KONFIG';
        ax_write_json($dir . '/hue_probe.json', $z, 0644);
        ax_log('ERROR', 'Hue-Probe (Container): ' . $dir . '/hue_container.json fehlt oder ist ungueltig - der Dienst endet.');
        exit(1);
    }
    // B2 (alexa6): die Zeitzone des Plugins auf dem LoxBerry. PHP liest weder /etc/localtime noch TZ,
    // nur date.timezone - ohne diese Zeile stuenden die Zeilen des Containers in UTC im Protokoll.
    $c = ax_hue_container_konfig();
    if ($c['zeitzone'] !== '') { date_default_timezone_set($c['zeitzone']); }
}

/** Eine IPv4 in Punktschreibweise? (keine fuehrenden Nullen) */
function ax_hue_container_ipv4($s)
{
    return is_string($s) && preg_match('/^(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])){3}\z/', $s) === 1;
}

/**
 * Die Konfiguration des Containers, jeder Wert geprueft; null, wenn die
 * Datei fehlt, kaputt ist oder ein Wert nicht passt. Frisch gelesen bei
 * jedem Aufruf ("MQTT aus" gilt sofort, wie im Dienst auf dem LoxBerry).
 */
function ax_hue_container_konfig()
{
    global $AX_HUE_C;
    $f = $AX_HUE_C['dir'] . '/hue_container.json';
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) { return null; }
    $text = function ($k, $max) use ($d) {
        return isset($d[$k]) && is_string($d[$k]) && strlen($d[$k]) <= $max && !preg_match('/[\x00-\x1F\x7F]/', $d[$k]);
    };
    $zahl = function ($k, $min, $max) use ($d) {
        return isset($d[$k]) && is_int($d[$k]) && $d[$k] >= $min && $d[$k] <= $max;
    };
    $gut = isset($d['ip']) && ax_hue_container_ipv4($d['ip'])
        && $zahl('port', 1, 65535)
        && isset($d['mac']) && is_string($d['mac']) && preg_match('/^[0-9a-f]{12}\z/', $d['mac'])
        && isset($d['bridgeid']) && is_string($d['bridgeid']) && preg_match('/^[0-9A-F]{16}\z/', $d['bridgeid'])
        && isset($d['uuid']) && is_string($d['uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $d['uuid'])
        && isset($d['lampe']) && is_string($d['lampe']) && preg_match('/^([0-9a-f]{2}:){7}[0-9a-f]{2}-0b\z/', $d['lampe'])
        && isset($d['nutzer']) && is_string($d['nutzer']) && preg_match('/^[0-9a-f]{32}\z/', $d['nutzer'])
        && $zahl('mqtt_ein', 0, 1)
        && isset($d['mqtt_praefix']) && is_string($d['mqtt_praefix'])
        && preg_match('#^[a-z0-9_\-]{1,32}(/[a-z0-9_\-]{1,32}){0,2}\z#', $d['mqtt_praefix'])
        && isset($d['mqtt_host']) && is_string($d['mqtt_host']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}\z/', $d['mqtt_host'])
        && $zahl('mqtt_port', 1, 65535)
        && $text('mqtt_user', 256) && $text('mqtt_pass', 256);
    return $gut ? ax_hue_container_zusatz($d) : null;   // alexa6: Lampen, Freigabe, Heimnetz, Wirt, Zeitzone
}

function ax_hue_container_ip()
{
    $c = ax_hue_container_konfig();
    return $c ? $c['ip'] : '';
}

/** Die Kennung der Nachbildung, wie das Plugin sie fuer diese Adresse festgelegt hat. */
function ax_hue_container_kennung()
{
    $c = ax_hue_container_konfig();
    return array('mac' => $c['mac'], 'bridgeid' => $c['bridgeid'], 'uuid' => $c['uuid'], 'lampe' => $c['lampe'], 'nutzer' => $c['nutzer']);
}

/**
 * Die Schnittstellen dieses Netz-Namensraums ohne lo - aus /proc/net/dev
 * (zeigt den eigenen Namensraum; im Container sind das das Heimnetz
 * (macvlan) und die Bruecke zum Wirt).
 */
function ax_hue_container_schnittstellen()
{
    $aus = array();
    $roh = @file_get_contents('/proc/net/dev');
    if ($roh === false) { return $aus; }
    foreach (preg_split('/\r?\n/', $roh) as $z) {
        if (preg_match('/^\s*([A-Za-z0-9][A-Za-z0-9_.\-]{0,14}):/', $z, $m) && $m[1] !== 'lo') { $aus[] = $m[1]; }
    }
    return $aus;
}

/* ---------------- Die Funktionen, die ax_hue.php aus ax_lib.php kennt ---------------- */

function ax_paths()
{
    global $AX_HUE_C;
    return array('lbhome' => '', 'plugin' => 'alexang', 'datadir' => $AX_HUE_C['dir'], 'log' => $AX_HUE_C['log'],
                 'logdir' => dirname($AX_HUE_C['log']));
}

/** Im Container: nie als root (der Container bekommt --user des Plugins). */
function ax_keine_wurzel_abbruch($programm)
{
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        fwrite(STDERR, $programm . ": im Container nicht als root starten - es wurde nichts gestartet.\n");
        exit(1);
    }
}

/** Was ax_hue.php aus der Konfiguration braucht; der Haken liegt beim Plugin (es legt an und entfernt). */
function ax_config()
{
    $c = ax_hue_container_konfig();
    return array('hue_ein' => 1, 'aktiv' => 1, 'hue_art' => 'container', 'hue_port' => $c ? (int) $c['port'] : 80,
                 'mqtt_ein' => $c ? (int) $c['mqtt_ein'] : 0, 'mqtt_praefix' => $c ? $c['mqtt_praefix'] : 'alexang');
}

/** Eine Zeile ins Protokoll des Plugins (eingehaengt); Kappung ab 500 kB auf 200 Zeilen. */
function ax_log($stufe, $text)
{
    $p = ax_paths();
    $f = $p['log'];
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        $rest = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $rest) . "\n");
    }
    $text = str_replace(array("\r", "\n"), ' ', (string) $text);
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] <' . $stufe . '> ' . $text . "\n", FILE_APPEND);
}

/** Unteilbar schreiben: Nebendatei, Rechte VOR dem Inhalt, Laenge verglichen, dann rename(). */
function ax_write_json($datei, $daten, $rechte = 0600)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    $js .= "\n";
    $neben = $datei . '.tmp.' . getmypid();
    $fh = @fopen($neben, 'c');
    if ($fh === false) { return false; }
    @chmod($neben, $rechte);
    $ok = ftruncate($fh, 0);
    $n = $ok ? fwrite($fh, $js) : false;
    fclose($fh);
    if ($n !== strlen($js)) { @unlink($neben); return false; }
    if (!@rename($neben, $datei)) { @unlink($neben); return false; }
    clearstatcache(true, $datei);
    return true;
}

/** Der eigene Messstand (fortgesetzt nach einem Neustart); nur Felder in der erwarteten Form. */
function ax_hue_lesen($art = null)
{
    $p = ax_paths();
    $d = json_decode((string) @file_get_contents($p['datadir'] . '/hue_probe.json'), true);
    if (!is_array($d)) { return array(); }
    $aus = array();
    foreach (array('beschreibung', 'abfragen', 'eigene', 'schalten', 'suchen', 'lampen', 'abgewiesen') as $k) {
        if (isset($d[$k]) && is_array($d[$k])) { $aus[$k] = $d[$k]; }
    }
    if (isset($aus['suchen']['absender']) && !is_array($aus['suchen']['absender'])) { unset($aus['suchen']['absender']); }
    // B3 (alexa6): Absender in Listenform (aeltere Datei) werden zur Zuordnung IP => Werte.
    foreach (array(array('suchen', 'absender'), array('abgewiesen', 'absender')) as $w) {
        if (!isset($aus[$w[0]][$w[1]]) || !is_array($aus[$w[0]][$w[1]])) { continue; }
        $ab = array();
        foreach ($aus[$w[0]][$w[1]] as $a => $e) {
            if (is_int($a) && is_array($e) && isset($e['ip']) && is_string($e['ip'])) { $a = $e['ip']; unset($e['ip']); }
            if (is_string($a) && ax_hue_container_ipv4($a) && is_array($e)) { $ab[$a] = $e; }
        }
        $aus[$w[0]][$w[1]] = $ab;
    }
    if (isset($aus['lampen'])) {
        $l = array();
        foreach ($aus['lampen'] as $id => $e) { if (preg_match('/^[0-9]{1,4}\z/', (string) $id) && is_array($e)) { $l[(string) $id] = $e; } }
        $aus['lampen'] = $l;
    }
    $aus['andere'] = (isset($d['andere']) && is_int($d['andere']) && $d['andere'] >= 0) ? $d['andere'] : 0;
    return $aus;
}

/** H3 wie auf dem LoxBerry: fluechtig <praefix>/hue_probe/ein = 1/0, sonst nichts. Rueckgabe array(versucht, gescheitert). */
function ax_hue_melden($ein, array $cfg)
{
    $c = ax_hue_container_konfig();
    if (!$c || empty($c['mqtt_ein'])) { return array(0, 0); }
    list($ok, $grund) = ax_hue_mqtt_senden($c, $c['mqtt_praefix'] . '/hue_probe/ein', $ein ? '1' : '0');
    if (!$ok) {
        ax_log('WARN', 'Hue-Probe (Container): MQTT an ' . $c['mqtt_host'] . ':' . (int) $c['mqtt_port'] . ' nicht gesendet - ' . $grund . '.');
    }
    return array(1, $ok ? 0 : 1);
}

/* ---------------- MQTT 3.1.1, nur Senden (QoS 1 an den Broker, nicht retained) ---------------- */

function ax_hue_mqtt_laenge($n)
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

function ax_hue_mqtt_text($s)
{
    return pack('n', strlen($s)) . $s;
}

function ax_hue_mqtt_lesen($c, $n)
{
    $d = '';
    $ende = microtime(true) + 3;
    while (strlen($d) < $n && microtime(true) < $ende) {
        $t = @fread($c, $n - strlen($d));
        if ($t === false || ($t === '' && feof($c))) { break; }
        $d .= $t;
    }
    return $d;
}

function ax_hue_mqtt_schreiben($c, $paket)
{
    $n = @fwrite($c, $paket);
    return $n === strlen($paket);
}

/**
 * Einen Wert senden. Rueckgabe array(ok, grund). "ok" heisst: der Broker hat
 * die Anmeldung angenommen (CONNACK 0) und den Empfang bestaetigt (PUBACK).
 */
function ax_hue_mqtt_senden(array $c, $thema, $wert)
{
    $s = @stream_socket_client('tcp://' . $c['mqtt_host'] . ':' . (int) $c['mqtt_port'], $en, $es, 3);
    if ($s === false) { return array(false, 'keine Verbindung (' . $es . ')'); }
    stream_set_timeout($s, 3);
    $flags = 0x02;
    $anmeldung = '';
    if ($c['mqtt_user'] !== '') {
        $flags |= 0x80;
        $anmeldung .= ax_hue_mqtt_text($c['mqtt_user']);
        if ($c['mqtt_pass'] !== '') {
            $flags |= 0x40;
            $anmeldung .= ax_hue_mqtt_text($c['mqtt_pass']);
        }
    }
    $rumpf = ax_hue_mqtt_text('MQTT') . chr(4) . chr($flags) . pack('n', 30)
           . ax_hue_mqtt_text('alexang-hue-' . getmypid()) . $anmeldung;
    if (!ax_hue_mqtt_schreiben($s, chr(0x10) . ax_hue_mqtt_laenge(strlen($rumpf)) . $rumpf)) {
        fclose($s);
        return array(false, 'CONNECT nicht geschrieben');
    }
    $ack = ax_hue_mqtt_lesen($s, 4);
    if (strlen($ack) !== 4 || ord($ack[0]) !== 0x20) { fclose($s); return array(false, 'keine CONNACK'); }
    if (ord($ack[3]) !== 0) { fclose($s); return array(false, 'Anmeldung abgewiesen (CONNACK ' . ord($ack[3]) . ')'); }
    $kennung = 1;
    $rumpf = ax_hue_mqtt_text($thema) . pack('n', $kennung) . $wert;
    if (!ax_hue_mqtt_schreiben($s, chr(0x32) . ax_hue_mqtt_laenge(strlen($rumpf)) . $rumpf)) {
        fclose($s);
        return array(false, 'PUBLISH nicht geschrieben');
    }
    $pa = ax_hue_mqtt_lesen($s, 4);
    $gut = strlen($pa) === 4 && ord($pa[0]) === 0x40 && substr($pa, 2, 2) === pack('n', $kennung);
    ax_hue_mqtt_schreiben($s, chr(0xE0) . chr(0));
    fclose($s);
    return $gut ? array(true, '') : array(false, 'kein PUBACK');
}

/* ---------------- Fassung 2 (alexa6): Lampen, Freigabe, Zeitzone ---------------- */

/**
 * Die Zusatzfelder der Container-Konfiguration (alexa6), jedes geprueft.
 * Fehlt ein Feld (Datei einer aelteren Fassung), gilt seine Vorgabe:
 * keine Lampe, Probe-Lampe an, keine Echo-Liste, Heimnetz unbekannt (dann
 * faellt die Freigabe geschlossen aus, bis das Plugin die Datei neu schreibt).
 * Rueckgabe das ergaenzte Feld oder null.
 */
function ax_hue_container_zusatz(array $d)
{
    $d += array('lampen' => array(), 'probe_lampe' => 1, 'echos' => array(), 'netz' => '', 'wirt' => '', 'zeitzone' => '');
    if (!is_array($d['lampen']) || count($d['lampen']) > 50 || !in_array($d['probe_lampe'], array(0, 1), true)
        || !is_array($d['echos']) || count($d['echos']) > 20 || !is_string($d['netz']) || !is_string($d['wirt']) || !is_string($d['zeitzone'])) {
        return null;
    }
    $ids = array();
    $kz = array();
    foreach ($d['lampen'] as $l) {
        if (!is_array($l) || !isset($l['id'], $l['name'], $l['art'], $l['kuerzel']) || !is_int($l['id']) || $l['id'] < 2 || $l['id'] > 9999
            || !is_string($l['name']) || $l['name'] === '' || strlen($l['name']) > 160 || preg_match('/[\x00-\x1F\x7F]/', $l['name'])
            || !in_array($l['art'], array('schalter', 'licht', 'dimmer'), true)
            || !is_string($l['kuerzel']) || !preg_match('/^[a-z0-9_]{1,32}\z/', $l['kuerzel'])
            || isset($ids[$l['id']]) || isset($kz[$l['kuerzel']])) {
            return null;
        }
        $ids[$l['id']] = 1;
        $kz[$l['kuerzel']] = 1;
    }
    foreach ($d['echos'] as $e) { if (!ax_hue_container_ipv4($e)) { return null; } }
    if ($d['netz'] !== '' && (!preg_match('#^([0-9.]{7,15})/([0-9]{1,2})\z#', $d['netz'], $m) || !ax_hue_container_ipv4($m[1])
        || (int) $m[2] < 8 || (int) $m[2] > 30)) {
        return null;
    }
    if ($d['wirt'] !== '' && !ax_hue_container_ipv4($d['wirt'])) { return null; }
    if ($d['zeitzone'] !== '' && !in_array($d['zeitzone'], timezone_identifiers_list(), true)) { return null; }
    return $d;
}

/** Die Lage fuer ax_hue.php (gleiche Form wie ax_hue_lb_lage() auf dem LoxBerry). */
function ax_hue_container_lage()
{
    $c = ax_hue_container_konfig();
    $aus = array('lampen' => array(), 'probe' => 1, 'echos' => array(), 'netze' => array(), 'wirt' => '', 'mqtt_ein' => 0,
                 'praefix' => 'alexang', 'broker' => array('host' => '', 'port' => 1883, 'user' => '', 'pass' => ''));
    if (!$c) { return $aus; }
    $aus['lampen'] = $c['lampen'];
    $aus['probe'] = (int) $c['probe_lampe'];
    $aus['echos'] = $c['echos'];
    if (preg_match('#^([0-9.]{7,15})/([0-9]{1,2})\z#', $c['netz'], $m)) {
        $t = explode('.', $m[1]);
        $z = (((int) $t[0] * 256 + (int) $t[1]) * 256 + (int) $t[2]) * 256 + (int) $t[3];
        $maske = (0xFFFFFFFF << (32 - (int) $m[2])) & 0xFFFFFFFF;
        $aus['netze'] = array(array($z & $maske, $maske));
    }
    $aus['wirt'] = $c['wirt'];
    $aus['mqtt_ein'] = (int) $c['mqtt_ein'];
    $aus['praefix'] = $c['mqtt_praefix'];
    $aus['broker'] = array('host' => $c['mqtt_host'], 'port' => (int) $c['mqtt_port'], 'user' => $c['mqtt_user'], 'pass' => $c['mqtt_pass']);
    return $aus;
}

/** Befehl einer Lampe an Loxone: <praefix>/hue/<kuerzel>/<feld> = wert, fluechtig, QoS 1 an den Broker. Rueckgabe array(versucht, gescheitert). */
function ax_hue_lampe_melden($kuerzel, array $paare, array $cfg)
{
    $c = ax_hue_container_konfig();
    if (!$c || empty($c['mqtt_ein']) || !$paare) { return array(0, 0); }
    $themen = array();
    foreach ($paare as $k => $v) { $themen[$c['mqtt_praefix'] . '/hue/' . $kuerzel . '/' . $k] = (string) $v; }
    list($ok, $grund) = ax_hue_mqtt_senden_mehr($c, $themen);
    if (!$ok) {
        ax_log('WARN', 'Hue (Container): MQTT an ' . $c['mqtt_host'] . ':' . (int) $c['mqtt_port'] . ' nicht gesendet - ' . $grund . '.');
    }
    return array(count($themen), $ok ? 0 : count($themen));
}

/** Mehrere Werte ueber EINE Verbindung senden (QoS 1, nicht retained); ok nur, wenn jeder PUBACK kam. */
function ax_hue_mqtt_senden_mehr(array $c, array $themen)
{
    $s = @stream_socket_client('tcp://' . $c['mqtt_host'] . ':' . (int) $c['mqtt_port'], $en, $es, 3);
    if ($s === false) { return array(false, 'keine Verbindung (' . $es . ')'); }
    stream_set_timeout($s, 3);
    $flags = 0x02;
    $anmeldung = '';
    if ($c['mqtt_user'] !== '') {
        $flags |= 0x80;
        $anmeldung .= ax_hue_mqtt_text($c['mqtt_user']);
        if ($c['mqtt_pass'] !== '') { $flags |= 0x40; $anmeldung .= ax_hue_mqtt_text($c['mqtt_pass']); }
    }
    $rumpf = ax_hue_mqtt_text('MQTT') . chr(4) . chr($flags) . pack('n', 30) . ax_hue_mqtt_text('alexang-hue-' . getmypid()) . $anmeldung;
    if (!ax_hue_mqtt_schreiben($s, chr(0x10) . ax_hue_mqtt_laenge(strlen($rumpf)) . $rumpf)) { fclose($s); return array(false, 'CONNECT nicht geschrieben'); }
    $ack = ax_hue_mqtt_lesen($s, 4);
    if (strlen($ack) !== 4 || ord($ack[0]) !== 0x20) { fclose($s); return array(false, 'keine CONNACK'); }
    if (ord($ack[3]) !== 0) { fclose($s); return array(false, 'Anmeldung abgewiesen (CONNACK ' . ord($ack[3]) . ')'); }
    $kennung = 0;
    $gut = true;
    foreach ($themen as $thema => $wert) {
        $kennung++;
        $rumpf = ax_hue_mqtt_text($thema) . pack('n', $kennung) . $wert;
        if (!ax_hue_mqtt_schreiben($s, chr(0x32) . ax_hue_mqtt_laenge(strlen($rumpf)) . $rumpf)) { $gut = false; break; }
        $pa = ax_hue_mqtt_lesen($s, 4);
        if (strlen($pa) !== 4 || ord($pa[0]) !== 0x40 || substr($pa, 2, 2) !== pack('n', $kennung)) { $gut = false; break; }
    }
    ax_hue_mqtt_schreiben($s, chr(0xE0) . chr(0));
    fclose($s);
    return $gut ? array(true, '') : array(false, 'kein PUBACK');
}
