<?php
/**
 * Alexa NG - Takt (cron.05min) und Helfer fuer die Deinstallation
 *
 *   php ax_takt.php                 ein Takt: Konfiguration vervollstaendigen,
 *                                   Anmeldung alle 30 min pruefen, Geraeteliste
 *                                   alle 6 h, Lebenszeichen, Waechter Befehlsabo
 *                                   und Hue-Probe
 *   php ax_takt.php --abmelden      bei Amazon abmelden (Deinstallation), rc 0/1
 *   php ax_takt.php --mqtt-raeumen  eigene retained Themen abraeumen, rc 0/1
 *
 * Der Takt ist die einzige Stelle, die den Zustand fortschreibt (Regeln/03);
 * Oberflaeche und Endpunkt lesen ihn. Er ruft Amazon nur unter der
 * Amazon-Sperre und wartet dort hoechstens 8 s - ein Takt, der nicht
 * drankommt, wird nachgeholt, er stapelt sich nicht.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$ax_schalter = array();
foreach ($argv as $ax_i => $ax_a) {
    if ($ax_i === 0) { continue; }
    if (!in_array($ax_a, array('--abmelden', '--mqtt-raeumen', '--hue-entfernen'), true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $ax_a . "\n");
        exit(2);
    }
    $ax_schalter[] = $ax_a;
}

/* Die Bibliothek: installiert unter <Wurzel>/webfrontend/html/plugins/<ordner>,
 * im ausgepackten Archiv unter ../webfrontend/html. Keine feste Zahl "..". */
$ax_kand = array();
if (getenv('LBHOMEDIR') && getenv('LBPPLUGINDIR')) {
    $ax_kand[] = rtrim(getenv('LBHOMEDIR'), '/') . '/webfrontend/html/plugins/' . basename(getenv('LBPPLUGINDIR')) . '/ax_lib.php';
}
if (basename(dirname(__DIR__)) === 'plugins') {
    $ax_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ax_lib.php';
}
$ax_kand[] = dirname(__DIR__) . '/webfrontend/html/ax_lib.php';
$ax_da = false;
foreach ($ax_kand as $ax_k) {
    if (is_file($ax_k)) { require_once $ax_k; $ax_da = true; break; }
}
if (!$ax_da) {
    fwrite(STDERR, 'ax_takt.php: ax_lib.php nicht gefunden, gesucht in: ' . implode(', ', $ax_kand) . "\n");
    exit(1);
}
ax_keine_wurzel_abbruch('ax_takt.php');
$ax_p = ax_paths();
ini_set('log_errors', '1');
ini_set('error_log', $ax_p['log']);

/* ---------------- Helfer der Deinstallation ---------------- */
if (in_array('--abmelden', $ax_schalter, true)) {
    if (!ax_amazon()) { echo "KEINE_ANMELDUNG\n"; exit(0); }
    list($ax_ok, $ax_g) = ax_amazon_abmelden();
    echo ($ax_ok ? 'ABGEMELDET' : 'NICHT_ABGEMELDET;GRUND=' . $ax_g) . "\n";
    exit($ax_ok ? 0 : 1);
}
if (in_array('--mqtt-raeumen', $ax_schalter, true)) {
    $ax_cfg = ax_config();
    list($ax_n, $ax_ger, $ax_uebrig) = ax_mqtt_raeumen($ax_cfg['mqtt_praefix']);
    echo 'PRAEFIX=' . $ax_cfg['mqtt_praefix'] . ';GEFUNDEN=' . $ax_n . ';GERAEUMT=' . $ax_ger . ';UEBRIG=' . $ax_uebrig . "\n";
    exit(($ax_n >= 0 && $ax_uebrig === 0) ? 0 : 1);
}

/* Nr. 41: Container und Netz der Hue-Probe entfernen (Deinstallation, preupgrade).
 * Ausgabe HUE_ENTFERNT;CONTAINER=n;NETZ=n;BILD=<abbild> | HUE_NICHTS |
 * HUE_KEIN_DOCKER | HUE_FEHL;GRUND=<grund> (rc 1). */
if (in_array('--hue-entfernen', $ax_schalter, true)) {
    list($ax_ok, $ax_g, $ax_txt, $ax_erg) = ax_hue_docker_entfernen(function ($s) { });
    $ax_e = explode('|', $ax_erg);
    if (!$ax_ok) { echo 'HUE_FEHL;GRUND=' . $ax_g . "\n"; exit(1); }
    if ($ax_e[0] === 'KEIN_DOCKER') { echo "HUE_KEIN_DOCKER\n"; exit(0); }
    if ($ax_e[0] === 'NICHTS') { echo "HUE_NICHTS\n"; exit(0); }
    $ax_nm = ax_hue_docker_namen();
    ax_log('INFO', 'Hue-Probe (eigene Netzadresse): Container und Netz entfernt (' . $ax_erg . ').');
    echo 'HUE_ENTFERNT;CONTAINER=' . (int) $ax_e[1] . ';NETZ=' . (int) $ax_e[2] . ';BILD=' . $ax_nm['bild'] . "\n";
    exit(0);
}

/* ---------------- Ein Takt ---------------- */
if (!is_dir($ax_p['datadir'])) { @mkdir($ax_p['datadir'], 0775, true); }
$ax_sp = @fopen($ax_p['datadir'] . '/takt.lock', 'c');
if ($ax_sp === false || !flock($ax_sp, LOCK_EX | LOCK_NB)) { exit(0); }   // ein Takt laeuft schon

ax_config_heilen(false);
ax_amazon_heilen();
$ax_cfg = ax_config();
$ax_t = ax_takt_lesen();
$ax_t['zaehler'] = ((int) $ax_t['zaehler'] + 1) % 1000;
$ax_ok = 1;
$ax_amz = ax_amazon();
$ax_anmeldung = false;

if (!empty($ax_cfg['aktiv']) && $ax_amz && function_exists('curl_init')) {
    $ax_bef = ax_anmeldung_befund();
    $ax_faellig_status = (time() - (int) $ax_t['status_ts'] >= AX_STATUS_S) || (int) $ax_t['status_ts'] > time();
    $ax_faellig_geraete = (time() - (int) $ax_t['geraete_ts'] >= AX_GERAETE_S) || (int) $ax_t['geraete_ts'] > time()
                          || !ax_geraete();
    if ($ax_bef['befund'] === 'ABGELAUFEN') {
        // Nicht bei jedem Takt erneut gegen Amazon laufen: einmal je 30 min.
        $ax_faellig_geraete = false;
    }
    if ($ax_faellig_status || $ax_faellig_geraete) {
        $ax_sperre = ax_sperre(AX_SPERRE_WARTE_S);
        if ($ax_sperre) {
            if ($ax_faellig_status) {
                list($ax_s_ok, $ax_s_g) = ax_amazon_status_pruefen();
                $ax_t['status_ts'] = time();
                if (!$ax_s_ok) { $ax_ok = 0; }
            }
            if ($ax_faellig_geraete && ax_anmeldung_befund()['befund'] !== 'ABGELAUFEN') {
                list($ax_g_ok, $ax_g_g) = ax_geraete_holen();
                if ($ax_g_ok) { $ax_t['geraete_ts'] = time(); } else { $ax_ok = 0; }
            }
            ax_sperre_frei($ax_sperre);
        }
    }
    $ax_bef = ax_anmeldung_befund();
    $ax_anmeldung = ($ax_bef['befund'] === '' || $ax_bef['befund'] === 'OK' || $ax_bef['befund'] === 'AMAZON'
                     || $ax_bef['befund'] === 'AMAZON_RATE' || $ax_bef['befund'] === 'NETZ');
    if ($ax_bef['befund'] !== '' && $ax_bef['befund'] !== 'OK') { $ax_ok = 0; }
} else {
    $ax_ok = 0;
}

/* ---------------- Waechter des Befehlsabos ---------------- */
$ax_laeuft = false;
$ax_status = ax_dienst_status();
if ($ax_status !== null) {
    $ax_laeuft = $ax_status;
    $ax_soll = !empty($ax_cfg['befehle_mqtt_ein']) && !empty($ax_cfg['mqtt_ein']) && !empty($ax_cfg['aktiv']);
    if ($ax_soll && !$ax_laeuft && time() - (int) $ax_t['dienst_versuch'] >= 600 && !is_file($ax_p['marke'])) {
        $ax_t['dienst_versuch'] = time();
        list($ax_d_ok, $ax_d_text) = ax_dienst('start');
        ax_log($ax_d_ok ? 'INFO' : 'WARN', 'Takt: Befehlsabo ' . ($ax_d_ok ? 'gestartet.' : 'liess sich nicht starten: ' . $ax_d_text));
        $ax_laeuft = (bool) ax_dienst_status();
    } elseif (!$ax_soll && $ax_laeuft) {
        list($ax_d_ok) = ax_dienst('stop');
        ax_log('INFO', 'Takt: Befehlsabo angehalten (ausgeschaltet).');
        $ax_laeuft = !$ax_d_ok;
    }
}

/* ---------------- Waechter der Hue-Probe (Bauliste alexa4 H1) ----------------
 * Sie laeuft nur, solange der Haken gesetzt ist: fehlt er, haelt der Takt sie
 * an (der Dienst endet auch selbst binnen 5 s); ist er gesetzt und sie laeuft
 * nicht, ein Startversuch hoechstens alle 10 Minuten. */
$ax_hs = ax_hue_dienst_status();
if ($ax_hs !== null) {
    $ax_hsoll = ax_hue_soll_lb($ax_cfg);   // Nr. 41: nur bei Art loxberry
    if ($ax_hsoll && !$ax_hs && time() - (int) $ax_t['hue_versuch'] >= 600 && !is_file($ax_p['marke'])) {
        $ax_t['hue_versuch'] = time();
        list($ax_d_ok, $ax_d_text) = ax_hue_dienst('start');
        ax_log($ax_d_ok ? 'INFO' : 'WARN', 'Takt: Hue-Probe ' . ($ax_d_ok ? 'gestartet.' : 'liess sich nicht starten: ' . $ax_d_text));
    } elseif (!$ax_hsoll && $ax_hs) {
        ax_hue_dienst('stop');
        ax_log('INFO', 'Takt: Hue-Probe angehalten (ausgeschaltet).');
    }
}

/* ---------------- Waechter der Hue-Probe auf eigener Netzadresse (Nr. 41) ----------------
 * Docker wird nur gefragt, wenn die Art "docker" eingeschaltet ist oder ein
 * Container angelegt wurde (Merker) - ab Werk nie. Laeuft er nicht, passt er
 * nicht mehr zur Einstellung oder ist er ausgeschaltet: hoechstens alle 10
 * Minuten ein Vorgang (anlegen bzw. entfernen), nie waehrend eines Updates. */
$ax_hm = ax_hue_docker_merk();
if (DIRECTORY_SEPARATOR !== '\\' && (ax_hue_soll_docker($ax_cfg) || !empty($ax_hm['angelegt'])) && !is_file($ax_p['marke'])) {
    $ax_hv = ax_hue_vorgang();
    if (!in_array($ax_hv['zustand'], array('gestartet', 'laeuft'), true) && time() - (int) $ax_t['hue_d_versuch'] >= 600) {
        $ax_hauftrag = ax_hue_docker_takt_auftrag($ax_cfg);
        if ($ax_hauftrag !== '') {
            $ax_t['hue_d_versuch'] = time();
            list($ax_d_ok, $ax_d_g) = ax_hue_vorgang_starten($ax_hauftrag);
            ax_log_wenn_neu('hue_d_takt_' . $ax_hauftrag, $ax_d_ok ? 'INFO' : 'WARN', 'Takt: Hue-Probe (eigene Netzadresse) - Vorgang ' . $ax_hauftrag
                . ($ax_d_ok ? ' gestartet.' : ' liess sich nicht starten: ' . $ax_d_g), 3600);
        }
    }
}

$ax_t['ts'] = time();
$ax_t['ok'] = $ax_ok;
ax_write_json($ax_p['datadir'] . '/takt.json', $ax_t, 0644);
ax_abo_datei($ax_cfg['mqtt_praefix'], true);
ax_mqtt_takt($ax_cfg, $ax_t, $ax_laeuft, $ax_anmeldung);
flock($ax_sp, LOCK_UN);
exit(0);
