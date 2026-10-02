<?php
/**
 * Alexa NG - MQTT-Befehlseingang (Dauerlaeufer, nur bei befehle_mqtt_ein=1)
 *
 * Gestartet und angehalten ueber bin/dienst.sh (Sperre IM SKRIPT, nicht hier:
 * eine flock-Sperre in diesem Prozess vererbte sich an mosquitto_sub und
 * verhinderte jeden Neustart - Regeln/03, Gedaechtnis "Sperre vererbt sich").
 *
 * Themen (Praefix einstellbar):
 *   <p>/befehl/<name>/sprechen      Nutzlast: Text
 *   <p>/befehl/<name>/ankuendigen   Nutzlast: Text (nur mit Freigabe)
 *   <p>/befehl/<name>/lautstaerke   Nutzlast: 0-100
 *   <p>/befehl/gruppe/<g>/sprechen  Nutzlast: Text
 *   <p>/befehl/routine              Nutzlast: Name (nur mit eigenem Haken)
 *   <p>/befehl/sperre               Nutzlast: 1 sperren, 0 oeffnen (nur mit Haken "Sperre aus Loxone")
 *   <p>/befehl/radio/<zone>         Nutzlast: Sendernummer, 0 oder stopp (nur mit Haken "Radio je Zone")
 *   <p>/befehl/radio/<zone>/laut    Nutzlast: 0-100; <zone> ist 1-24 oder alle
 *
 * Zurueckbehaltene Nachrichten werden VERWORFEN, nie ausgefuehrt: sonst
 * spraeche jeder Neustart die letzte Ansage erneut (Bauplan 2.7). Doppelt
 * abgesichert: mosquitto_sub -R und die Retain-Marke %r je Nachricht. Die
 * Nutzlast kommt hexadezimal (%x), damit ein Zeilenumbruch sie nicht zerlegt.
 * Beides ist am Geraet mit mosquitto-clients 2.x zu messen.
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
    fwrite(STDERR, 'ax_befehle.php: ax_lib.php nicht gefunden, gesucht in: ' . implode(', ', $ax_kand) . "\n");
    exit(1);
}
ax_keine_wurzel_abbruch('ax_befehle.php');
$ax_p = ax_paths();
ini_set('log_errors', '1');
ini_set('error_log', $ax_p['log']);

/** Ein Thema in Aktion und Parameter zerlegen; null = kein Befehl. */
function ax_befehl_thema($thema, $praefix)
{
    $vor = $praefix . '/befehl/';
    if (strpos($thema, $vor) !== 0) { return null; }
    $rest = substr($thema, strlen($vor));
    if ($rest === 'routine') { return array('routine', ''); }
    if ($rest === 'sperre') { return array('sperre', ''); }
    if (preg_match('#^radio/([a-z0-9]{1,4})\z#', $rest, $m)) { return array('radio', $m[1]); }
    if (preg_match('#^radio/([a-z0-9]{1,4})/laut\z#', $rest, $m)) { return array('radio_laut', $m[1]); }
    if (preg_match('#^gruppe/([a-z0-9_]{1,40})/sprechen\z#', $rest, $m)) { return array('sprechen', 'gruppe:' . $m[1]); }
    if (preg_match('#^([a-z0-9_]{1,40})/(sprechen|ankuendigen|lautstaerke)\z#', $rest, $m)) {
        if ($m[1] === 'gruppe') { return null; }
        return array($m[2], $m[1]);
    }
    return null;
}

$ax_zaehler = array('empfangen' => 0, 'verworfen' => 0, 'ausgefuehrt' => 0);
$ax_warte = 10;
ax_log('INFO', 'Befehlsabo: gestartet (PID ' . getmypid() . ').');
while (true) {
    $ax_cfg = ax_config();
    if (empty($ax_cfg['befehle_mqtt_ein']) || empty($ax_cfg['mqtt_ein']) || empty($ax_cfg['aktiv'])) {
        ax_log('INFO', 'Befehlsabo: ausgeschaltet - der Dienst endet.');
        exit(0);
    }
    $ax_vor = ax_mqtt_vorspann();
    if ($ax_vor === '' || !ax_has_mosquitto()) {
        ax_log_wenn_neu('befehle_kein_mosq', 'ERROR', 'Befehlsabo: mosquitto_sub fehlt oder Optionsdatei nicht schreibbar.', 3600);
        sleep(60);
        continue;
    }
    $ax_b = ax_broker();
    $ax_cmd = 'exec env ' . $ax_vor . 'mosquitto_sub -h ' . escapeshellarg($ax_b['host']) . ' -p ' . (int) $ax_b['port']
            . ' -R -F ' . escapeshellarg('%r %t %x') . ' -t ' . escapeshellarg($ax_cfg['mqtt_praefix'] . '/befehl/#');
    $ax_rohr = array();
    $ax_proz = proc_open($ax_cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'),
                                        2 => array('file', $ax_p['log'], 'a')), $ax_rohr);
    if (!is_resource($ax_proz)) {
        ax_log_wenn_neu('befehle_start', 'ERROR', 'Befehlsabo: mosquitto_sub liess sich nicht starten.', 3600);
        sleep($ax_warte);
        $ax_warte = min(60, $ax_warte * 2);
        continue;
    }
    $ax_st = proc_get_status($ax_proz);
    @file_put_contents($ax_p['datadir'] . '/befehle_kind.pid', (string) $ax_st['pid']);
    $ax_puffer = '';
    $ax_seit = time();
    stream_set_blocking($ax_rohr[1], false);
    while (true) {
        $ax_r = array($ax_rohr[1]); $ax_w = null; $ax_e = null;
        $ax_n = @stream_select($ax_r, $ax_w, $ax_e, 30);
        if ($ax_n === false) { break; }
        $ax_neu = ($ax_n > 0) ? fread($ax_rohr[1], 65536) : '';
        if ($ax_n > 0 && ($ax_neu === '' || $ax_neu === false) && feof($ax_rohr[1])) { break; }
        $ax_puffer .= (string) $ax_neu;
        if (strlen($ax_puffer) > 65536 && strpos($ax_puffer, "\n") === false) {
            ax_log('WARN', 'Befehlsabo: Zeile ueber 64 kB ohne Umbruch verworfen.');
            $ax_puffer = '';
        }
        while (($ax_pos = strpos($ax_puffer, "\n")) !== false) {
            $ax_zeile = rtrim(substr($ax_puffer, 0, $ax_pos), "\r");
            $ax_puffer = substr($ax_puffer, $ax_pos + 1);
            $ax_zaehler['empfangen']++;
            if (!preg_match('/^([01]) (\S+) ([0-9a-fA-F]*)\z/', $ax_zeile, $ax_m)) {
                $ax_zaehler['verworfen']++;
                ax_log_wenn_neu('befehle_form', 'WARN', 'Befehlsabo: Zeile in unerwarteter Form verworfen (mosquitto_sub -F).', 3600);
                continue;
            }
            if ($ax_m[1] === '1') {
                $ax_zaehler['verworfen']++;
                ax_log('INFO', 'Befehlsabo: zurueckbehaltener Befehl auf ' . $ax_m[2] . ' verworfen (nicht ausgefuehrt).');
                continue;
            }
            $ax_nutz = (strlen($ax_m[3]) % 2 === 0 && strlen($ax_m[3]) <= 4000) ? hex2bin($ax_m[3]) : false;
            $ax_ziel = ax_befehl_thema($ax_m[2], $ax_cfg['mqtt_praefix']);
            if ($ax_nutz === false || $ax_ziel === null) {
                $ax_zaehler['verworfen']++;
                ax_log_wenn_neu('befehle_thema_' . md5($ax_m[2]), 'WARN', 'Befehlsabo: unbekanntes Thema oder Nutzlast verworfen: ' . substr($ax_m[2], 0, 120), 3600);
                continue;
            }
            list($ax_akt, $ax_ger) = $ax_ziel;
            if ($ax_akt === 'sperre') {
                // K1: dieselbe Funktion wie der Endpunkt; prueft Haken und Wert selbst.
                ax_loxsperre_setzen(trim($ax_nutz), 'mqtt');
                $ax_zaehler['ausgefuehrt']++;
                continue;
            }
            if ($ax_akt === 'radio' || $ax_akt === 'radio_laut') {
                // Radio je Zone (Z3): dieselbe Funktion wie der Endpunkt; sie prueft
                // Haken, Zone, Sender und Wert selbst. Nutzlast "stopp" oder "0" haelt an.
                $ax_wert = trim($ax_nutz);
                if ($ax_akt === 'radio_laut') {
                    $ax_rp = array('zone' => $ax_ger, 'wert' => $ax_wert);
                } elseif ($ax_wert === 'stopp') {
                    $ax_akt = 'radio_stopp';
                    $ax_rp = array('zone' => $ax_ger);
                } else {
                    $ax_rp = array('zone' => $ax_ger, 'nr' => $ax_wert);
                }
                ax_radio_ausfuehren($ax_akt, $ax_rp, 'mqtt');
                $ax_zaehler['ausgefuehrt']++;
                continue;
            }
            $ax_par = array('geraet' => $ax_ger);
            if ($ax_akt === 'lautstaerke') { $ax_par['wert'] = trim($ax_nutz); }
            elseif ($ax_akt === 'routine') {
                if (empty($ax_cfg['befehle_routine_ein'])) {
                    ax_log('WARN', 'Befehlsabo: Routine per MQTT ist nicht freigegeben - verworfen.');
                    continue;
                }
                $ax_par = array('name' => trim($ax_nutz));
            } else { $ax_par['text'] = $ax_nutz; }
            list($ax_h, $ax_f) = ax_befehl_ausfuehren($ax_akt, $ax_par, 'mqtt');
            $ax_zaehler['ausgefuehrt']++;
        }
        $ax_cfg2 = ax_config();
        if (empty($ax_cfg2['befehle_mqtt_ein']) || $ax_cfg2['mqtt_praefix'] !== $ax_cfg['mqtt_praefix']) { break; }
        @file_put_contents($ax_p['datadir'] . '/befehle.json', json_encode(array('pid' => getmypid(), 'ts' => time()) + $ax_zaehler));
    }
    @proc_terminate($ax_proz);
    @proc_close($ax_proz);
    @unlink($ax_p['datadir'] . '/befehle_kind.pid');
    if (time() - $ax_seit < 60) {
        ax_log_wenn_neu('befehle_ende', 'WARN', 'Befehlsabo: mosquitto_sub endete nach ' . (time() - $ax_seit) . ' s - neuer Versuch in ' . $ax_warte . ' s.', 3600);
        sleep($ax_warte);
        $ax_warte = min(60, $ax_warte * 2);
    } else {
        $ax_warte = 10;
    }
}
