<?php
/**
 * Alexa NG - Endpunkt fuer Loxone und andere Plugins (unangemeldeter Bereich)
 *
 *   ?aktion=status                     frei     Statuszeile (auch ohne aktion)
 *   ?aktion=geraete                    frei     Geraeteliste OHNE Seriennummern (E5)
 *   ?aktion=geraete&json=1&token=A     Aktion   mit Seriennummer und Typ
 *   ?selftest=1&token=T                beide    SELFTEST;OK=1;TOKEN=OK, kein Amazon-Kontakt
 *   ?aktion=sprechen&token=T&geraet=..&text=..[&laut=0-100][&ssml=1][&dringend=1]
 *   ?aktion=ankuendigen&token=T&geraet=..&text=..[&titel=..]   (ab Werk aus)
 *   ?aktion=lautstaerke&token=T&geraet=..&wert=0-100
 *   ?aktion=routine&token=A&name=..[&geraet=..]               nur Aktionstoken
 *
 * T = Sprech- oder Aktionstoken, A = nur Aktionstoken (E4). GET und POST,
 * nie $_REQUEST (Cookies!). Jede Antwort nennt GRUND; jeder Weg schreibt
 * eine Protokollzeile mit der Adresse des Anrufers, nie mit dem Token.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

function ax_ende_roh($http, $zeile)
{
    if (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code((int) $http);
    }
    echo $zeile . "\n";
    exit;
}

ob_start();
try {
    $ax_lib = __DIR__ . '/ax_lib.php';
    if (!is_file($ax_lib)) {
        error_log('Alexa NG: ax_lib.php nicht gefunden, gesucht in: ' . $ax_lib);
        ax_ende_roh(500, 'ALEXANG;OK=0;GRUND=BIBLIOTHEK_FEHLT');
    }
    require_once $ax_lib;
    ax_nur_lesen(true);

    /* Parameter einmal zentral: nur GET und POST, erst is_string, dann Laenge. */
    $ax_par = array();
    $ax_falsch = array();
    foreach (array('aktion', 'token', 'geraet', 'text', 'laut', 'ssml', 'titel', 'wert', 'name', 'dringend',
                   'json', 'selftest') as $ax_k) {
        $ax_w = null;
        if (isset($_POST[$ax_k])) { $ax_w = $_POST[$ax_k]; } elseif (isset($_GET[$ax_k])) { $ax_w = $_GET[$ax_k]; }
        if ($ax_w === null) { continue; }
        if (!is_string($ax_w) || strlen($ax_w) > 8000) { $ax_falsch[] = $ax_k; continue; }
        $ax_par[$ax_k] = $ax_w;
    }
    $ax_wer = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '-';
    $ax_aktion = isset($ax_par['aktion']) ? $ax_par['aktion'] : (isset($ax_par['selftest']) ? 'selftest' : 'status');
    $ax_kopf = array('status' => 'ALEXANG', 'geraete' => 'GERAET', 'selftest' => 'SELFTEST', 'sprechen' => 'SPRECHEN',
                     'ankuendigen' => 'ANKUENDIGEN', 'lautstaerke' => 'LAUTSTAERKE', 'routine' => 'ROUTINE');
    if (!isset($ax_kopf[$ax_aktion])) {
        ax_log('WARN', 'Endpunkt: unbekannte Aktion (Laenge ' . strlen($ax_aktion) . ') von ' . $ax_wer);
        ax_ende_roh(400, 'ALEXANG;OK=0;GRUND=AKTION');
    }
    $K = $ax_kopf[$ax_aktion];
    if ($ax_falsch) {
        // Ein Feld wie token[] oder text[] wird abgewiesen, nie umgewandelt.
        ax_log('WARN', 'Endpunkt: ' . $ax_aktion . ' von ' . $ax_wer . ' - Parameter keine Zeichenkette: ' . implode(',', $ax_falsch));
        ax_ende_roh(in_array('token', $ax_falsch, true) ? 403 : 400,
            ax_zeile($K, array('OK' => 0, 'ERR' => in_array('token', $ax_falsch, true) ? 'TOKEN' : 'PARAMETER',
                               'GRUND' => in_array('token', $ax_falsch, true) ? 'TOKEN' : 'PARAMETER')));
    }
    $ax_cfg = ax_config();

    /* ---------------- frei lesbar ---------------- */
    if ($ax_aktion === 'status') {
        list($ax_h, $ax_f) = ax_status();
        ax_ende_roh($ax_h, ax_zeile('ALEXANG', $ax_f));
    }
    if ($ax_aktion === 'geraete' && empty($ax_par['json'])) {
        $ax_st = ax_geraete();
        if (!$ax_st) { ax_ende_roh(503, 'GERAET;OK=0;GRUND=KEINE_LISTE'); }
        $ax_z = array();
        foreach ($ax_st['liste'] as $ax_g) {
            $ax_z[] = ax_zeile('GERAET', array('NAME' => $ax_g['normal'], 'FAMILIE' => $ax_g['familie'],
                'ONLINE' => (int) $ax_g['online'], 'LAUT' => (int) $ax_g['laut']));
        }
        foreach ($ax_cfg['gruppen'] as $ax_gr) {
            $ax_z[] = ax_zeile('GRUPPE', array('NAME' => 'gruppe:' . $ax_gr['name'], 'GERAETE' => str_replace(',', ' ', $ax_gr['geraete'])));
        }
        ax_ende_roh(200, $ax_z ? implode("\n", $ax_z) : 'GERAET;OK=1;ANZAHL=0');
    }

    /* ---------------- Token (hash_equals, fail closed) ---------------- */
    $ax_sprech = (string) $ax_cfg['sprechtoken'];
    $ax_akt = (string) $ax_cfg['aktionstoken'];
    $ax_ist = isset($ax_par['token']) ? $ax_par['token'] : '';
    if ($ax_sprech === '' && $ax_akt === '') {
        ax_log_wenn_neu('kein_token_' . $ax_wer, 'WARN', 'Endpunkt: ' . $ax_aktion . ' von ' . $ax_wer . ' - kein Token eingerichtet.', 900);
        ax_ende_roh(403, ax_zeile($K, array('OK' => 0, 'ERR' => 'KEIN_TOKEN_EINGERICHTET', 'GRUND' => 'KEIN_TOKEN_EINGERICHTET')));
    }
    if (ax_fehlversuch($ax_wer, false)) {
        ax_log_wenn_neu('gesperrt_' . $ax_wer, 'WARN', 'Endpunkt: ' . $ax_wer . ' ist nach ' . AX_FEHLVERSUCHE . ' Fehlversuchen eine Stunde gesperrt.', 900);
        ax_ende_roh(429, ax_zeile($K, array('OK' => 0, 'ERR' => 'GESPERRT', 'GRUND' => 'GESPERRT')));
    }
    $ax_ist_akt = ($ax_ist !== '' && $ax_akt !== '' && hash_equals($ax_akt, $ax_ist));
    $ax_ist_sprech = ($ax_ist !== '' && $ax_sprech !== '' && hash_equals($ax_sprech, $ax_ist));
    if (!$ax_ist_akt && !$ax_ist_sprech) {
        ax_fehlversuch($ax_wer, true);
        ax_log_wenn_neu('token_' . $ax_wer, 'WARN', 'Endpunkt: ' . $ax_aktion . ' von ' . $ax_wer . ' - Token fehlt oder falsch (Laenge '
            . strlen($ax_ist) . ').', 900);
        ax_ende_roh(403, ax_zeile($K, array('OK' => 0, 'ERR' => 'TOKEN', 'GRUND' => 'TOKEN')));
    }

    /* ---------------- Selbsttest: prueft das Token, loest nichts aus ---------------- */
    if ($ax_aktion === 'selftest') {
        ax_ende_roh(200, 'SELFTEST;OK=1;TOKEN=OK;ART=' . ($ax_ist_akt ? 'AKTION' : 'SPRECH'));
    }

    /* Ab hier darf der Endpunkt Laufzeitdateien im Datenordner schreiben -
     * NACH der Tokenpruefung (Regeln/03). Die Konfiguration legt er nie an. */
    ax_nur_lesen(false);

    if ($ax_aktion === 'geraete') {
        if (!$ax_ist_akt) {
            ax_log('WARN', 'Endpunkt: geraete&json=1 von ' . $ax_wer . ' mit dem Sprechtoken abgewiesen.');
            ax_ende_roh(403, 'GERAET;OK=0;ERR=TOKEN;GRUND=SPRECHTOKEN_ZEIGT_KEINE_SERIENNUMMERN');
        }
        $ax_st = ax_geraete();
        if (!$ax_st) { ax_ende_roh(503, 'GERAET;OK=0;GRUND=KEINE_LISTE'); }
        ax_log('INFO', 'Endpunkt: Geraeteliste mit Seriennummern an ' . $ax_wer . '.');
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
        if (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode($ax_st, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit;
    }
    if ($ax_aktion === 'routine' && !$ax_ist_akt) {
        ax_log('WARN', 'Endpunkt: routine von ' . $ax_wer . ' mit dem Sprechtoken abgewiesen.');
        ax_ende_roh(403, 'ROUTINE;OK=0;ERR=TOKEN;GRUND=SPRECHTOKEN_STARTET_KEINE_ROUTINE');
    }
    list($ax_h, $ax_f) = ax_befehl_ausfuehren($ax_aktion, $ax_par, 'http');
    ax_ende_roh($ax_h, ax_zeile($K, $ax_f));
} catch (Throwable $ax_t) {
    if (function_exists('ax_log')) {
        ax_log('ERROR', 'Endpunkt abgestuerzt: ' . $ax_t->getMessage() . ' (' . basename($ax_t->getFile()) . ':' . $ax_t->getLine() . ')');
    }
    ax_ende_roh(500, 'ALEXANG;OK=0;GRUND=FEHLER');
}
