<?php
/**
 * Alexa NG - Oberflaeche
 * Reiter: Einstellungen | Amazon-Anmeldung | Geraete | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * BAUVORSCHRIFT (Regeln/03): Bibliothek, Konfiguration, Wachposten,
 * Reiterwahl, ALLE Handler samt Downloads und Umleitung, erst dann
 * lbheader(), dann HTML. Jeder POST endet mit 303 (PRG); das Ergebnis reist
 * als Einmalmeldung (data/.../einmalmeldung.json, 0600, 120 s, nur beim GET
 * gelesen). Geheimnisse reisen nie mit und stehen nie im Formular.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

if (basename(dirname(__DIR__)) === 'plugins') {
    $ax_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/ax_lib.php');
} else {
    $ax_kandidaten = array(dirname(__DIR__) . '/html/ax_lib.php');
}
$ax_geladen = false;
foreach ($ax_kandidaten as $ax_cand) {
    if (is_file($ax_cand)) { require_once $ax_cand; $ax_geladen = true; break; }
}
if (!$ax_geladen || !function_exists('ax_config')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Alexa NG</h2><p>ax_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p><ul>';
    foreach ($ax_kandidaten as $ax_cand) { echo '<li><code>' . htmlspecialchars($ax_cand, ENT_QUOTES, 'UTF-8') . '</code></li>'; }
    echo '</ul>';
    exit;
}
$ax_p = ax_paths();
if ($ax_p['lbhome'] !== '' && is_file($ax_p['lbhome'] . '/libs/phplib/loxberry_system.php')) {
    require_once $ax_p['lbhome'] . '/libs/phplib/loxberry_system.php';
    require_once $ax_p['lbhome'] . '/libs/phplib/loxberry_web.php';
    $ax_p = ax_paths();
}
$ax_datadir = $ax_p['datadir'];

/* ---------------- Einmalmeldung und Umleitung ---------------- */
function ax_ui_flash_datei($d) { return rtrim($d, '/') . '/einmalmeldung.json'; }
function ax_ui_flash_lesen($d)
{
    $f = ax_ui_flash_datei($d);
    if (!is_file($f)) { return array(); }
    $x = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($x) || !isset($x['zeit']) || time() - (int) $x['zeit'] > 120 || (int) $x['zeit'] > time()) { return array(); }
    return $x;
}
function ax_ui_umleiten($d, $tab, array $inhalt)
{
    $inhalt['tab'] = $tab;
    $inhalt['zeit'] = time();
    ax_write_json(ax_ui_flash_datei($d), $inhalt, 0600);
    header('Location: index.php?form=' . rawurlencode(preg_replace('/^tab-/', '', $tab)), true, 303);
    exit;
}

/* ---------------- X-2: Eingaben nach einer Beanstandung ----------------
 * Mit der Einmalmeldung reisen die Felder des EINEN beanstandeten Formulars;
 * nie Token, nie ein Erneuerungs-Token, nie ein Code. */
function ax_ui_felder($formular)
{
    if ($formular === 'settings') {
        return array('aktiv', 'standardgeraet', 'gruppe_name', 'gruppe_geraete', 'bremse_fenster_s', 'mindestabstand_s',
                     'stundengrenze', 'ruhe_ein', 'ruhe_von', 'ruhe_bis', 'ankuendigen_ein', 'routinen_frei',
                     'texte_protokollieren', 'sperre_ein', 'musik_ein', 'musik_stundengrenze', 'musik_sender',
                     'radio_ein', 'radio_zonen', 'hue_ein', 'hue_port', 'hue_art', 'hue_ip', 'hue_schnittstelle',
                     'hue_probe_lampe', 'hue_l_id', 'hue_l_name', 'hue_l_art', 'hue_l_kuerzel', 'hue_l_frei', 'hue_echos');
    }
    if ($formular === 'mqtt') { return array('mqtt_ein', 'mqtt_praefix', 'befehle_mqtt_ein', 'befehle_routine_ein'); }
    return array();
}
function ax_ui_bean($feld = null, $idx = null)
{
    static $liste = array();
    if ($feld !== null) {
        $n = (string) $feld . ($idx !== null ? '[' . (int) $idx . ']' : '');
        if (!in_array($n, $liste, true)) { $liste[] = $n; }
    }
    return $liste;
}
function ax_ui_tauglich($w) { return is_string($w) && strlen($w) <= 2100 && preg_match('//u', $w) === 1; }
function ax_ui_sammeln($formular)
{
    $werte = array();
    foreach (ax_ui_felder($formular) as $f) {
        if (!isset($_POST[$f])) { continue; }
        $w = $_POST[$f];
        if (is_array($w)) {
            $z = array();
            foreach ($w as $k => $v) {
                // alexa6: bis 60 Zeilen (Lampentabelle: 50 Lampen und die leeren Zeilen dahinter)
                if (count($z) < 60 && preg_match('/^\d{1,2}\z/', (string) $k) && ax_ui_tauglich($v)) { $z[(string) (int) $k] = $v; }
            }
            $werte[$f] = $z;
        } elseif (ax_ui_tauglich($w)) {
            $werte[$f] = $w;
        }
    }
    return array('formular' => $formular, 'werte' => $werte, 'falsch' => ax_ui_bean());
}
function ax_ui_eingaben($setzen = null)
{
    static $e = null;
    if ($setzen !== null) {
        $e = null;
        if (is_array($setzen) && isset($setzen['formular'], $setzen['werte'], $setzen['falsch'])
            && is_string($setzen['formular']) && is_array($setzen['werte']) && is_array($setzen['falsch'])
            && ax_ui_felder($setzen['formular'])) {
            $falsch = array();
            foreach ($setzen['falsch'] as $n) { if (is_string($n) && preg_match('/^[a-z_]+(\[\d{1,2}\])?\z/', $n)) { $falsch[] = $n; } }
            $e = array('formular' => $setzen['formular'], 'werte' => $setzen['werte'], 'falsch' => $falsch);
        }
    }
    return $e;
}
function ax_ui_aktiv($feld)
{
    $e = ax_ui_eingaben();
    return $e !== null && in_array($feld, ax_ui_felder($e['formular']), true);
}
/** Wert eines Textfelds: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function ax_ui_w($feld, $gespeichert, $idx = null)
{
    if (ax_ui_aktiv($feld)) {
        $e = ax_ui_eingaben();
        $w = isset($e['werte'][$feld]) ? $e['werte'][$feld] : null;
        if ($idx !== null) { $w = (is_array($w) && isset($w[(string) (int) $idx])) ? $w[(string) (int) $idx] : null; }
        if (is_string($w)) { return $w; }
    }
    return (string) $gespeichert;
}
function ax_ui_h($feld, $gespeichert)
{
    if (!ax_ui_aktiv($feld)) { return (bool) $gespeichert; }
    $e = ax_ui_eingaben();
    return isset($e['werte'][$feld]);
}
function ax_ui_m($feld, $idx = null)
{
    $e = ax_ui_eingaben();
    $n = (string) $feld . ($idx !== null ? '[' . (int) $idx . ']' : '');
    return ($e !== null && in_array($n, $e['falsch'], true)) ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
function ax_ui_post($k) { return (isset($_POST[$k]) && is_string($_POST[$k])) ? trim($_POST[$k]) : ''; }
/**
 * Fassung 2 (alexa6): die Lampenliste aus dem Formular - je Zeile
 * hue_l_id (verborgen, bleibt beim Umbenennen), hue_l_name, hue_l_art,
 * hue_l_kuerzel, hue_l_frei. Eine Zeile ohne Name und Kuerzel entfaellt (so
 * wird eine Lampe geloescht). Jede Beanstandung markiert ihr Feld (X-2) und
 * wird gesammelt; gespeichert wird nur, wenn keine kam (Nr. 16, Nr. 19).
 * Rueckgabe array(liste, naechste_id, meldungen, vorschlaege zeile => kuerzel).
 */
function ax_ui_hue_lampen(array $alt)
{
    $feld = function ($f) { return (isset($_POST[$f]) && is_array($_POST[$f])) ? $_POST[$f] : array(); };
    $ids = $feld('hue_l_id');
    $namen = $feld('hue_l_name');
    $arten = $feld('hue_l_art');
    $kz = $feld('hue_l_kuerzel');
    $frei = $feld('hue_l_frei');
    $idx = array();
    foreach (array($ids, $namen, $arten, $kz, $frei) as $a) {
        foreach (array_keys($a) as $k) { if (preg_match('/^\d{1,2}\z/', (string) $k)) { $idx[(int) $k] = 1; } }
    }
    ksort($idx);
    $text = function ($a, $i) { return (isset($a[$i]) && is_string($a[$i])) ? trim($a[$i]) : ''; };
    $alt_ids = array();
    $max = 1;
    foreach ($alt['hue_lampen'] as $l) { $alt_ids[(int) $l['id']] = 1; $max = max($max, (int) $l['id']); }
    $naechste = max((int) $alt['hue_lampe_naechste'], $max + 1);
    $liste = array();
    $hw = array();
    $vor = array();
    $ges_id = array();
    $ges_n = array();
    $ges_k = array();
    $zeilen = 0;
    $satz = function ($i, $g) { return sprintf(ax_t('MELDUNG.LAMPE_ZEILE'), $i + 1, ax_grund_text($g)); };
    foreach (array_keys($idx) as $i) {
        $name = $text($namen, $i);
        $k = $text($kz, $i);
        $art = $text($arten, $i);
        $id = $text($ids, $i);
        $f = (isset($frei[$i]) && $frei[$i] === '1') ? 1 : 0;
        if ($name === '' && $k === '') { continue; }
        $zeilen++;
        $fehl = false;
        if ($name === '') { $hw[] = $satz($i, 'LAMPE_NAME_FEHLT'); ax_ui_bean('hue_l_name', $i); $fehl = true; }
        if ($id !== '' && (!preg_match('/^[1-9][0-9]{0,3}\z/', $id) || !isset($alt_ids[(int) $id]) || isset($ges_id[(int) $id]))) {
            $hw[] = $satz($i, 'LAMPE_ID_FREMD');
            ax_ui_bean('hue_l_name', $i);
            $fehl = true;
        }
        if ($k === '' && $name !== '') {
            $vor[$i] = ax_hue_kuerzel_vorschlag($name, 0);
            $hw[] = $satz($i, 'LAMPE_KUERZEL_FEHLT|' . $vor[$i]);
            ax_ui_bean('hue_l_kuerzel', $i);
            $fehl = true;
        }
        if ($fehl) { continue; }
        if ($id !== '') { $ges_id[(int) $id] = 1; }
        $zeile = array('id' => $id !== '' ? (int) $id : $naechste++, 'name' => $name, 'art' => $art, 'kuerzel' => $k, 'frei' => $f);
        $g = '';
        if (ax_wert_pruefen('hue_lampen', array($zeile), $g) === null) {
            $hw[] = $satz($i, $g);
            $kk = explode('|', $g);
            $ziel = array('LAMPE_KUERZEL' => 'hue_l_kuerzel', 'LAMPE_ART' => 'hue_l_art', 'LAMPE_GEFAHR' => 'hue_l_frei');
            ax_ui_bean(isset($ziel[$kk[0]]) ? $ziel[$kk[0]] : 'hue_l_name', $i);
            if ($kk[0] === 'LAMPE_KUERZEL') { $vor[$i] = ax_hue_kuerzel_vorschlag($name, 0); }
            continue;
        }
        $nn = ax_name_normal($name);
        if (isset($ges_n[$nn])) { $hw[] = $satz($i, 'LAMPE_NAME_DOPPELT|' . str_replace('|', '/', $name)); ax_ui_bean('hue_l_name', $i); continue; }
        if (isset($ges_k[$k])) { $hw[] = $satz($i, 'LAMPE_KUERZEL_DOPPELT|' . $k); ax_ui_bean('hue_l_kuerzel', $i); continue; }
        $ges_n[$nn] = 1;
        $ges_k[$k] = 1;
        $liste[] = $zeile;
    }
    if ($zeilen > AX_HUE_LAMPEN_MAX) {
        $hw[] = sprintf(ax_t('MELDUNG.LAMPEN_ZU_VIELE'), $zeilen, AX_HUE_LAMPEN_MAX);
        ax_ui_bean('hue_l_name', AX_HUE_LAMPEN_MAX);
    }
    return array($liste, $naechste, $hw, $vor);
}

/* Drei Stellen gehoeren zusammen: Reiterleiste, Bereiche, diese Positivliste. */
$ax_muster = '/^tab-(settings|amazon|geraete|mqtt|loxone|test|log)$/';
$ax_methode = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
$ax_post = ($ax_methode === 'POST');
$ax_tab = ($ax_post && isset($_POST['activetab']) && is_string($_POST['activetab']) && preg_match($ax_muster, $_POST['activetab']))
        ? $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && is_string($_GET['form']) && preg_match($ax_muster, 'tab-' . $_GET['form'])) {
    $ax_tab = 'tab-' . $_GET['form'];
}

/* ---------------- Konfiguration heilen, Token beim ersten Oeffnen ---------------- */
$ax_heil = ax_config_heilen(true);
$ax_amz_heil = ax_amazon_heilen();
if ($ax_amz_heil !== '') { $ax_heil[] = $ax_amz_heil; }
$ax_cfg = ax_config();

/* ---------------- Wachposten ---------------- */
if ($ax_post && !ax_formtoken_ok($ax_cfg)) {
    $ax_post = false;
    ax_ui_umleiten($ax_datadir, $ax_tab, array('fehler' => ax_t('MELDUNG.FORMTOKEN')));
}

/* ---------------- Downloads (vor lbheader, ohne Umleitung) ---------------- */
if ($ax_post && isset($_POST['download'])) {
    $ax_host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $ax_dl = is_string($_POST['download']) ? $_POST['download'] : '';
    $ax_v = ($ax_dl === 'vo') ? ax_vorlage_aus($ax_host, $ax_cfg) : (($ax_dl === 'vr') ? ax_vorlage_radio($ax_host, $ax_cfg)
        : (($ax_dl === 'vh') ? ax_vorlage_hue_ein($ax_host, $ax_cfg) : (($ax_dl === 'vq') ? ax_vorlage_hue_aus($ax_host, $ax_cfg) : ax_vorlage_ein($ax_host))));
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $ax_v[0] . '"');
    header('Content-Length: ' . strlen($ax_v[1]));
    echo $ax_v[1];
    exit;
}
if ($ax_post && isset($_POST['ax_sichern'])) {
    $ax_mit = !empty($_POST['sich_amazon']);
    $ax_js = ax_sicherung_bauen($ax_cfg, $ax_mit);
    if ($ax_js !== false) {
        ax_log('INFO', 'Einstellungen gesichert (Download' . ($ax_mit ? ', mit Amazon-Anmeldung' : '') . ').');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="alexang_einstellungen_' . date('Ymd_His') . '.json"');
        echo $ax_js;
        exit;
    }
    ax_ui_umleiten($ax_datadir, 'tab-settings', array('fehler' => ax_t('SICH.SCHREIBFEHLER')));
}

/* ---------------- Einstellungen speichern ---------------- */
if ($ax_post && isset($_POST['save'])) {
    $ax_alt = ax_config();
    $ax_neu = $ax_alt;
    $ax_hw = array();
    $ax_feld = function ($k, $w) use (&$ax_neu, &$ax_hw) {
        $g = '';
        $gut = ax_wert_pruefen($k, $w, $g);
        if ($gut === null) {
            $ax_hw[] = sprintf(ax_t('MELDUNG.FELD_ABGEWIESEN'), ax_t('FELD.' . strtoupper($k)), ax_grund_text($g));
            ax_ui_bean($k);
            return;
        }
        $ax_neu[$k] = $gut;
    };
    foreach (array('aktiv', 'ruhe_ein', 'ankuendigen_ein', 'texte_protokollieren', 'sperre_ein', 'musik_ein', 'radio_ein', 'hue_ein') as $ax_k) {
        $ax_neu[$ax_k] = empty($_POST[$ax_k]) ? 0 : 1;
    }
    foreach (array('standardgeraet', 'bremse_fenster_s', 'mindestabstand_s', 'stundengrenze', 'ruhe_von', 'ruhe_bis',
                   'musik_stundengrenze') as $ax_k) {
        $ax_feld($ax_k, ax_ui_post($ax_k));
    }
    // H1: der Port wird geprueft, wenn das Formular ihn sendet (das eigene tut es
    // immer); fehlt das Feld ganz, bleibt der gespeicherte Wert - nichts ersetzt.
    if (array_key_exists('hue_port', $_POST)) { $ax_feld('hue_port', ax_ui_post('hue_port')); }
    // Nr. 41 (alexa5): Art, eigene IP und Schnittstelle - geprueft, wenn das Formular sie sendet.
    // Bei "eigene Netzadresse" muss die IP ins Netz der Schnittstelle passen und frei sein
    // (die Belegt-Probe entfaellt, wenn sich IP und Schnittstelle nicht aendern: dann traegt
    // sie womoeglich schon der eigene Container, und den sieht der LoxBerry nicht - macvlan).
    foreach (array('hue_art', 'hue_ip', 'hue_schnittstelle') as $ax_k) {
        if (array_key_exists($ax_k, $_POST)) { $ax_feld($ax_k, ax_ui_post($ax_k)); }
    }
    if (ax_hue_art($ax_neu) === 'docker' && !array_intersect(array('hue_art', 'hue_ip', 'hue_schnittstelle'), ax_ui_bean())) {
        $ax_hue_gleich = ax_hue_art($ax_alt) === 'docker' && $ax_alt['hue_ip'] === $ax_neu['hue_ip']
                         && $ax_alt['hue_schnittstelle'] === $ax_neu['hue_schnittstelle'];
        $ax_hg = ax_hue_ip_pruefen($ax_neu['hue_ip'], $ax_neu['hue_schnittstelle'], !$ax_hue_gleich);
        if ($ax_hg !== '') {
            $ax_hf = ax_hue_ip_feld($ax_hg);
            $ax_hw[] = sprintf(ax_t('MELDUNG.FELD_ABGEWIESEN'), ax_t('FELD.' . strtoupper($ax_hf)), ax_grund_text($ax_hg));
            ax_ui_bean($ax_hf);
        }
    }
    // Fassung 2 (alexa6): Lampen fuer Alexa, Probe-Lampe und freigegebene Echos - nur, wenn das
    // Formular den Abschnitt sendet (Merkmal hue_lampen_form; das eigene tut es immer). Fehlt er,
    // bleibt alles wie gespeichert - nichts wird ersetzt.
    $ax_hl_vor = array();
    if (isset($_POST['hue_lampen_form'])) {
        $ax_neu['hue_probe_lampe'] = empty($_POST['hue_probe_lampe']) ? 0 : 1;
        list($ax_hl, $ax_hl_naechste, $ax_hl_hw, $ax_hl_vor) = ax_ui_hue_lampen($ax_alt);
        foreach ($ax_hl_hw as $ax_m) { $ax_hw[] = $ax_m; }
        if (!$ax_hl_hw) {
            $ax_feld('hue_lampen', $ax_hl);
            $ax_feld('hue_lampe_naechste', $ax_hl_naechste);
        }
        $ax_el = array();
        foreach (preg_split('/[\s,;]+/', ax_ui_post('hue_echos')) as $ax_x) { if ($ax_x !== '') { $ax_el[] = $ax_x; } }
        $ax_eg = '';
        if (ax_wert_pruefen('hue_echos', $ax_el, $ax_eg) === null) {
            $ax_hw[] = sprintf(ax_t('MELDUNG.FELD_ABGEWIESEN'), ax_t('FELD.HUE_ECHOS'), ax_grund_text($ax_eg));
            ax_ui_bean('hue_echos');
        } else {
            $ax_eg = ax_hue_echos_pruefen($ax_el, $ax_neu['hue_schnittstelle']);
            if ($ax_eg !== '') {
                $ax_hw[] = sprintf(ax_t('MELDUNG.FELD_ABGEWIESEN'), ax_t('FELD.HUE_ECHOS'), ax_grund_text($ax_eg));
                ax_ui_bean('hue_echos');
            } else {
                $ax_neu['hue_echos'] = $ax_el;
            }
        }
    }
    $ax_gn = isset($_POST['gruppe_name']) && is_array($_POST['gruppe_name']) ? $_POST['gruppe_name'] : array();
    $ax_gg = isset($_POST['gruppe_geraete']) && is_array($_POST['gruppe_geraete']) ? $_POST['gruppe_geraete'] : array();
    $ax_gruppen = array();
    for ($ax_i = 0; $ax_i < 10; $ax_i++) {
        $ax_n = (isset($ax_gn[$ax_i]) && is_string($ax_gn[$ax_i])) ? trim($ax_gn[$ax_i]) : '';
        $ax_g = (isset($ax_gg[$ax_i]) && is_string($ax_gg[$ax_i])) ? preg_replace('/\s+/', '', $ax_gg[$ax_i]) : '';
        if ($ax_n === '' && $ax_g === '') { continue; }
        if ($ax_n === '' || $ax_g === '') {
            $ax_hw[] = sprintf(ax_t('MELDUNG.GRUPPE_HALB'), $ax_i + 1);
            ax_ui_bean($ax_n === '' ? 'gruppe_name' : 'gruppe_geraete', $ax_i);
            continue;
        }
        $ax_gruppen[] = array('name' => $ax_n, 'geraete' => $ax_g);
        $ax_grund = '';
        if (ax_wert_pruefen('gruppen', array(array('name' => $ax_n, 'geraete' => $ax_g)), $ax_grund) === null) {
            $ax_hw[] = sprintf(ax_t('MELDUNG.GRUPPE_ZEILE'), $ax_i + 1, ax_grund_text($ax_grund));
            ax_ui_bean('gruppe_name', $ax_i);
            ax_ui_bean('gruppe_geraete', $ax_i);
        }
    }
    $ax_feld('gruppen', $ax_gruppen);
    $ax_rl = array();
    foreach (preg_split('/\r?\n/', isset($_POST['routinen_frei']) && is_string($_POST['routinen_frei']) ? $_POST['routinen_frei'] : '') as $ax_r) {
        if (trim($ax_r) !== '') { $ax_rl[] = trim($ax_r); }
    }
    $ax_feld('routinen_frei', $ax_rl);
    // Senderliste der Musik-Probe: je Zeile "Nummer = Sendername | anbieter"
    // (anbieter tunein oder amazon, ohne Angabe tunein). Eine unlesbare Zeile
    // ist eine Beanstandung; gespeichert wird dann nichts (Nr. 16).
    $ax_sl = array();
    $ax_sl_fehl = false;
    $ax_zn = 0;
    foreach (preg_split('/\r?\n/', isset($_POST['musik_sender']) && is_string($_POST['musik_sender']) ? $_POST['musik_sender'] : '') as $ax_r) {
        $ax_zn++;
        $ax_r = trim($ax_r);
        if ($ax_r === '') { continue; }
        $ax_teil = explode('=', $ax_r, 2);
        $ax_rest = isset($ax_teil[1]) ? $ax_teil[1] : '';
        $ax_anb = 'tunein';
        $ax_strich = strrpos($ax_rest, '|');
        if ($ax_strich !== false) {
            $ax_anb = trim(substr($ax_rest, $ax_strich + 1));
            $ax_rest = substr($ax_rest, 0, $ax_strich);
        }
        $ax_snr = trim($ax_teil[0]);
        $ax_sname = trim($ax_rest);
        if (count($ax_teil) !== 2 || !preg_match('/^[1-9][0-9]?\z/', $ax_snr) || $ax_sname === '') {
            $ax_hw[] = sprintf(ax_t('MELDUNG.SENDER_ZEILE'), $ax_zn);
            $ax_sl_fehl = true;
            continue;
        }
        $ax_sl[] = array('nr' => (int) $ax_snr, 'name' => $ax_sname, 'anbieter' => $ax_anb);
    }
    if ($ax_sl_fehl) { ax_ui_bean('musik_sender'); } else { $ax_feld('musik_sender', $ax_sl); }
    // Radio je Zone (Z1): je Zeile "Nummer = Ziel" - Normalname, amazon:<name>
    // oder gruppe:<name>. Eine unlesbare Zeile oder eine eigene Gruppe, die es
    // nicht gibt, ist eine Beanstandung; gespeichert wird dann nichts (Nr. 16).
    $ax_rz = array();
    $ax_rz_fehl = false;
    $ax_zn = 0;
    foreach (preg_split('/\r?\n/', isset($_POST['radio_zonen']) && is_string($_POST['radio_zonen']) ? $_POST['radio_zonen'] : '') as $ax_r) {
        $ax_zn++;
        $ax_r = trim($ax_r);
        if ($ax_r === '') { continue; }
        $ax_teil = explode('=', $ax_r, 2);
        $ax_znr = trim($ax_teil[0]);
        $ax_zziel = isset($ax_teil[1]) ? trim($ax_teil[1]) : '';
        if (count($ax_teil) !== 2 || !preg_match('/^[1-9][0-9]?\z/', $ax_znr) || (int) $ax_znr > AX_RADIO_ZONEN_MAX || !ax_radio_ziel_form($ax_zziel)) {
            $ax_hw[] = sprintf(ax_t('MELDUNG.ZONE_ZEILE'), $ax_zn);
            $ax_rz_fehl = true;
            continue;
        }
        $ax_rz[] = array('zone' => (int) $ax_znr, 'ziel' => $ax_zziel);
    }
    if (!$ax_rz_fehl) {
        $ax_rz_gr = ax_radio_gruppen_fehlen($ax_rz, $ax_gruppen);
        if ($ax_rz_gr) {
            $ax_hw[] = sprintf(ax_t('MELDUNG.ZONE_GRUPPE'), implode(', ', $ax_rz_gr));
            $ax_rz_fehl = true;
        }
    }
    if ($ax_rz_fehl) { ax_ui_bean('radio_zonen'); } else { $ax_feld('radio_zonen', $ax_rz); }
    if ($ax_hw) {
        array_unshift($ax_hw, ax_t('MELDUNG.EINGABEN_ZURUECK'));
        $ax_ein = ax_ui_sammeln('settings');
        // alexa6: ein vorgeschlagenes Kuerzel steht nach der Beanstandung im Feld - gespeichert ist es nicht.
        foreach ($ax_hl_vor as $ax_i => $ax_v) {
            if (!isset($ax_ein['werte']['hue_l_kuerzel']) || !is_array($ax_ein['werte']['hue_l_kuerzel'])) { $ax_ein['werte']['hue_l_kuerzel'] = array(); }
            $ax_ein['werte']['hue_l_kuerzel'][(string) (int) $ax_i] = $ax_v;
        }
        ax_ui_umleiten($ax_datadir, 'tab-settings', array('hinweise' => $ax_hw, 'eingaben' => $ax_ein));
    }
    if (!ax_config_speichern($ax_neu)) {
        ax_ui_umleiten($ax_datadir, 'tab-settings', array('fehler' => sprintf(ax_t('MELDUNG.SPEICHERN_FEHL'), $ax_p['config'])));
    }
    ax_log('INFO', 'Einstellungen gespeichert (aktiv=' . $ax_neu['aktiv'] . ', Stundengrenze ' . $ax_neu['stundengrenze']
        . ', Bremse ' . $ax_neu['bremse_fenster_s'] . ' s, Ankuendigen ' . $ax_neu['ankuendigen_ein']
        . ', Sperre aus Loxone ' . $ax_neu['sperre_ein'] . ', Musik-Probe ' . $ax_neu['musik_ein']
        . ' (' . count($ax_neu['musik_sender']) . ' Sender, ' . $ax_neu['musik_stundengrenze'] . '/h)'
        . ', Radio je Zone ' . $ax_neu['radio_ein'] . ' (' . count($ax_neu['radio_zonen']) . ' Zonen)'
        . ', Hue-Probe ' . $ax_neu['hue_ein'] . ' (' . (ax_hue_art($ax_neu) === 'docker'
            ? 'eigene Netzadresse ' . $ax_neu['hue_ip'] . ' an ' . $ax_neu['hue_schnittstelle'] : 'Port ' . $ax_neu['hue_port']) . ')).');
    ax_log('INFO', 'Hue-Lampen: ' . count($ax_neu['hue_lampen']) . ' in der Liste, Probe-Lampe ' . $ax_neu['hue_probe_lampe']
        . ', freigegebene Echos ' . ($ax_neu['hue_echos'] ? implode(' ', $ax_neu['hue_echos']) : 'alle im Heimnetz') . '.');
    // Z1: Ziele, die die Geraeteliste nicht kennt, sind kein Fehler beim Speichern
    // (die Liste kann alt sein) - aber ein Hinweis; am Endpunkt gibt es dafuer 404.
    $ax_rz_unbek = array();
    $ax_st_s = $ax_neu['radio_zonen'] ? ax_geraete() : null;
    if ($ax_st_s) {
        $ax_kennt = array();
        foreach ($ax_st_s['liste'] as $ax_g) { $ax_kennt[$ax_g['normal']] = $ax_g['familie']; }
        foreach ($ax_neu['radio_zonen'] as $ax_z) {
            if (strpos($ax_z['ziel'], 'gruppe:') === 0) { continue; }
            $ax_zname = preg_replace('/^amazon:/', '', $ax_z['ziel']);
            if (!isset($ax_kennt[$ax_zname]) || (strpos($ax_z['ziel'], 'amazon:') === 0 && $ax_kennt[$ax_zname] !== 'WHA')) {
                $ax_rz_unbek[] = $ax_z['zone'] . ' = ' . $ax_z['ziel'];
            }
        }
    }
    $ax_hw = $ax_rz_unbek ? array(sprintf(ax_t('MELDUNG.ZONE_ZIEL_UNBEKANNT'), implode(', ', $ax_rz_unbek))) : array();
    // alexa6: die Probe-Lampe bleibt neben den ersten eigenen Lampen sichtbar - gesagt, nicht still geaendert.
    if (!$ax_alt['hue_lampen'] && $ax_neu['hue_lampen'] && !empty($ax_neu['hue_probe_lampe'])) { $ax_hw[] = ax_t('MELDUNG.HUE_PROBE_LAMPE_NOCH'); }
    // H1: die Hue-Probe nachziehen (starten, anhalten, neuer Port) und sagen, was geschah.
    $ax_hue_t = ax_hue_nachziehen($ax_neu, $ax_alt);
    if ($ax_hue_t !== '') { $ax_hw[] = $ax_hue_t; }
    ax_ui_umleiten($ax_datadir, 'tab-settings', array('gespeichert' => 1) + ($ax_hw ? array('hinweise' => $ax_hw) : array()));
}

/* ---------------- Token neu wuerfeln (je eins, mit Warnung) ---------------- */
if ($ax_post && isset($_POST['token_neu'])) {
    $ax_welches = ($_POST['token_neu'] === 'aktion') ? 'aktionstoken' : 'sprechtoken';
    if (empty($_POST['token_neu_ja'])) {
        ax_ui_umleiten($ax_datadir, 'tab-settings', array('fehler' => ax_t('MELDUNG.BESTAETIGUNG_FEHLT')));
    }
    $ax_neu = ax_config();
    do { $ax_neu[$ax_welches] = ax_token_erzeugen(); }
    while ($ax_neu['sprechtoken'] === $ax_neu['aktionstoken']);
    if (!ax_config_speichern($ax_neu)) {
        ax_ui_umleiten($ax_datadir, 'tab-settings', array('fehler' => sprintf(ax_t('MELDUNG.SPEICHERN_FEHL'), $ax_p['config'])));
    }
    ax_log('INFO', 'Konfiguration: ' . $ax_welches . ' auf Wunsch neu gewuerfelt.');
    ax_ui_umleiten($ax_datadir, 'tab-settings', array('meldung' => ax_t($ax_welches === 'aktionstoken' ? 'MELDUNG.AKTIONSTOKEN_NEU' : 'MELDUNG.SPRECHTOKEN_NEU')));
}

/* ---------------- Amazon: Weg (b2) vorbereiten ---------------- */
if ($ax_post && isset($_POST['pkce_start'])) {
    $ax_adr = ax_pkce_starten();
    ax_ui_umleiten($ax_datadir, 'tab-amazon', $ax_adr !== '' ? array('meldung' => ax_t('AMZ.PKCE_BEREIT'))
        : array('fehler' => ax_t('AMZ.PKCE_FEHL')));
}
/* ---------------- Amazon: Code einloesen ---------------- */
if ($ax_post && isset($_POST['pkce_einloesen'])) {
    $ax_ein = isset($_POST['pkce_code']) ? $_POST['pkce_code'] : '';
    if (!function_exists('curl_init')) {
        ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => ax_grund_text('CURL_FEHLT')));
    }
    list($ax_ok, $ax_g) = ax_pkce_einloesen(is_string($ax_ein) ? $ax_ein : null);
    if (!$ax_ok) {
        ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => sprintf(ax_t('AMZ.CODE_FEHL'), ax_grund_text($ax_g))));
    }
    $ax_sp = ax_sperre();
    list($ax_pr_ok, $ax_pr_g) = $ax_sp ? ax_amazon_status_pruefen() : array(false, 'BESCHAEFTIGT');
    ax_sperre_frei($ax_sp);
    ax_ui_umleiten($ax_datadir, 'tab-amazon', array('meldung' => $ax_pr_ok ? ax_t('AMZ.CODE_OK')
        : sprintf(ax_t('AMZ.CODE_OK_PROBE_FEHL'), ax_grund_text($ax_pr_g))));
}
/* ---------------- Amazon: Weg (a) Token einfuegen ---------------- */
if ($ax_post && isset($_POST['token_einfuegen'])) {
    $ax_rt = isset($_POST['refresh_token']) ? $_POST['refresh_token'] : '';
    if (!is_string($ax_rt) || trim($ax_rt) === '') {
        ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => ax_t('AMZ.TOKEN_LEER')));
    }
    if (!ax_refresh_form_ok($ax_rt)) {
        // Abgewiesen, nicht zurechtgebogen - auch kein trim(): Leerraum im Token ist ein Fehler.
        ax_log('WARN', 'Anmeldung: eingefuegtes Token hat nicht die Form Atnr|... (Laenge ' . strlen($ax_rt) . ') - nichts gespeichert.');
        ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => ax_t('AMZ.TOKEN_FORM')));
    }
    if (!function_exists('curl_init')) {
        ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => ax_grund_text('CURL_FEHLT')));
    }
    $ax_sp = ax_sperre();
    if (!$ax_sp) { ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => ax_grund_text('BESCHAEFTIGT'))); }
    list($ax_ok, $ax_g) = ax_amazon_tauschen($ax_rt);
    ax_sperre_frei($ax_sp);
    if (!$ax_ok) {
        ax_log('WARN', 'Anmeldung: Probetausch mit dem eingefuegten Token gescheitert (' . $ax_g . ') - nichts gespeichert.');
        ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => sprintf(ax_t('AMZ.TOKEN_ABGELEHNT'),
            ax_grund_text($ax_g === 'ABGELAUFEN' ? 'ANMELDUNG_ABGELAUFEN' : $ax_g))));
    }
    if (!ax_amazon_speichern(array('refresh_token' => $ax_rt, 'weg' => 'a', 'device_serial' => ''))) {
        ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => sprintf(ax_t('MELDUNG.SPEICHERN_FEHL'), $ax_p['amazon'])));
    }
    ax_log('INFO', 'Anmeldung: Token eingefuegt (Weg a), Probetausch gelungen, gespeichert.');
    $ax_sp = ax_sperre();
    list($ax_pr_ok, $ax_pr_g) = $ax_sp ? ax_amazon_status_pruefen() : array(false, 'BESCHAEFTIGT');
    ax_sperre_frei($ax_sp);
    ax_ui_umleiten($ax_datadir, 'tab-amazon', array('meldung' => $ax_pr_ok ? ax_t('AMZ.TOKEN_OK')
        : sprintf(ax_t('AMZ.TOKEN_OK_PROBE_FEHL'), ax_grund_text($ax_pr_g))));
}
/* ---------------- Amazon: abmelden und oertlich loeschen ---------------- */
if ($ax_post && isset($_POST['abmelden'])) {
    if (empty($_POST['abmelden_ja'])) {
        ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => ax_t('MELDUNG.BESTAETIGUNG_FEHLT')));
    }
    $ax_nur_oertlich = !empty($_POST['nur_oertlich']);
    if (!$ax_nur_oertlich) {
        list($ax_ok, $ax_g) = function_exists('curl_init') ? ax_amazon_abmelden() : array(false, 'CURL_FEHLT');
        if (!$ax_ok && $ax_g !== 'ANMELDUNG') {
            ax_ui_umleiten($ax_datadir, 'tab-amazon', array('fehler' => sprintf(ax_t('AMZ.ABMELDEN_FEHL'), ax_grund_text($ax_g))));
        }
    }
    $ax_weg = ax_amazon_loeschen();
    ax_log('INFO', 'Anmeldung: oertlich geloescht' . ($ax_nur_oertlich ? ' (ohne Amazon zu fragen).' : ' (bei Amazon abgemeldet).'));
    ax_ui_umleiten($ax_datadir, 'tab-amazon', $ax_weg ? array('meldung' => ax_t($ax_nur_oertlich ? 'AMZ.GELOESCHT_OERTLICH' : 'AMZ.GELOESCHT'))
        : array('fehler' => ax_t('AMZ.LOESCHEN_FEHL')));
}

/* ---------------- Geraete ---------------- */
if ($ax_post && isset($_POST['geraete_holen'])) {
    if (!ax_amazon()) { ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => ax_grund_text('ANMELDUNG'))); }
    $ax_sp = ax_sperre();
    if (!$ax_sp) { ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => ax_grund_text('BESCHAEFTIGT'))); }
    list($ax_ok, $ax_g, $ax_st) = ax_geraete_holen();
    ax_sperre_frei($ax_sp);
    ax_ui_umleiten($ax_datadir, 'tab-geraete', $ax_ok ? array('meldung' => sprintf(ax_t('GER.GEHOLT'), (int) $ax_st['konto_gesamt'], count($ax_st['liste'])))
        : array('fehler' => sprintf(ax_t('GER.HOLEN_FEHL'), ax_grund_text($ax_g))));
}
if ($ax_post && isset($_POST['testansage'])) {
    $ax_zu = is_string($_POST['testansage']) ? $_POST['testansage'] : '';
    $ax_zurueck = (isset($_POST['activetab']) && $_POST['activetab'] === 'tab-test') ? 'tab-test' : 'tab-geraete';
    // Reiter Test ohne Standardgeraet: das Geraet kommt aus der Auswahl "testgeraet".
    // Keine Wahl oder ein unbekanntes Geraet ist eine Beanstandung (Nr. 16/19): nichts
    // gesendet, nie ein stiller Rueckfall auf das Standardgeraet; die Wahl kommt markiert zurueck.
    $ax_wahl = null;
    if ($ax_zu === '' && $ax_zurueck === 'tab-test' && array_key_exists('testgeraet', $_POST)) {
        $ax_wahl = is_string($_POST['testgeraet']) ? trim($_POST['testgeraet']) : "\0";
        $ax_zu = $ax_wahl;
    }
    $ax_rueck = ($ax_wahl !== null && preg_match('/^[a-z0-9_]{1,40}\z/', $ax_wahl)) ? array('testgeraet' => $ax_wahl) : array();
    if ($ax_wahl === '') {
        ax_ui_umleiten($ax_datadir, $ax_zurueck, array('fehler' => ax_grund_text('KEIN_GERAET'), 'testgeraet_falsch' => 1));
    }
    if (!preg_match('/^[a-z0-9_]{1,40}\z/', $ax_zu)) {
        ax_ui_umleiten($ax_datadir, $ax_zurueck, array('fehler' => ax_grund_text('GERAET')) + ($ax_wahl !== null ? array('testgeraet_falsch' => 1) : array()));
    }
    list($ax_h, $ax_f) = ax_befehl_ausfuehren('sprechen', array('geraet' => $ax_zu, 'text' => ax_t('GER.TESTSATZ')), 'oberflaeche');
    $ax_grund = isset($ax_f['GRUND']) ? (string) $ax_f['GRUND'] : '-';
    $ax_erg = !empty($ax_f['OK']) && empty($ax_f['UEBERSPRUNGEN'])
        ? array('meldung' => sprintf(ax_t('GER.TEST_OK'), $ax_zu, ax_zeile('SPRECHEN', $ax_f)))
        : array('fehler' => sprintf(ax_t('GER.TEST_FEHL'), $ax_zu, $ax_h, ax_grund_text($ax_grund)));
    if ($ax_wahl !== null && in_array($ax_grund, array('GERAET', 'GERAET_UNBEKANNT', 'GERAET_VERSCHWUNDEN'), true)) { $ax_erg['testgeraet_falsch'] = 1; }
    ax_ui_umleiten($ax_datadir, $ax_zurueck, $ax_erg + $ax_rueck);
}

/* ---------------- Reiter Geraete: verschwundenes Geraet austragen (alexa4 N4) ----------------
 * Nur mit Bestaetigungshaken; nimmt die Zuordnung Seriennummer -> Normalname
 * aus der Namenszuordnung und den Eintrag aus "verschwunden". Ein Geraet, das
 * Amazon noch meldet, wird nie ausgetragen. PRG; fehlt der Haken, kommt er
 * markiert zurueck (X-2). */
if ($ax_post && isset($_POST['austragen'])) {
    $ax_wer = (is_string($_POST['austragen']) && preg_match('/^[a-z0-9_]{1,40}\z/', $_POST['austragen'])) ? $_POST['austragen'] : '';
    if (empty($_POST['austragen_ja'])) {
        ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => ax_t('MELDUNG.BESTAETIGUNG_FEHLT'), 'austragen_falsch' => 1));
    }
    list($ax_ok, $ax_g) = ax_geraet_austragen($ax_wer);
    if (!$ax_ok) {
        ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => sprintf(ax_t('GER.AUSTRAGEN_FEHL'), $ax_wer !== '' ? $ax_wer : '-', ax_grund_text($ax_g))));
    }
    list($ax_orte) = ax_name_verwendung($ax_wer, $ax_cfg);
    ax_ui_umleiten($ax_datadir, 'tab-geraete', array('meldung' => sprintf(ax_t('GER.AUSGETRAGEN'), $ax_wer))
        + ($ax_orte ? array('hinweise' => array(sprintf(ax_t('GER.AUSGETRAGEN_ORTE'), $ax_wer, implode(', ', $ax_orte)))) : array()));
}

/* ---------------- MQTT speichern (eigenes Formular, eigener Handler) ---------------- */
if ($ax_post && isset($_POST['save_mqtt'])) {
    $ax_alt = ax_config();
    $ax_neu = $ax_alt;
    $ax_hw = array();
    foreach (array('mqtt_ein', 'befehle_mqtt_ein', 'befehle_routine_ein') as $ax_k) { $ax_neu[$ax_k] = empty($_POST[$ax_k]) ? 0 : 1; }
    $ax_g = '';
    $ax_pr = ax_wert_pruefen('mqtt_praefix', ax_ui_post('mqtt_praefix'), $ax_g);
    if ($ax_pr === null) {
        $ax_hw[] = sprintf(ax_t('MELDUNG.FELD_ABGEWIESEN'), ax_t('FELD.MQTT_PRAEFIX'), ax_grund_text($ax_g));
        ax_ui_bean('mqtt_praefix');
    } else {
        $ax_neu['mqtt_praefix'] = $ax_pr;
    }
    if ($ax_hw) {
        array_unshift($ax_hw, ax_t('MELDUNG.EINGABEN_ZURUECK'));
        ax_ui_umleiten($ax_datadir, 'tab-mqtt', array('hinweise' => $ax_hw, 'eingaben' => ax_ui_sammeln('mqtt')));
    }
    if (!ax_config_speichern($ax_neu)) {
        ax_ui_umleiten($ax_datadir, 'tab-mqtt', array('fehler' => sprintf(ax_t('MELDUNG.SPEICHERN_FEHL'), $ax_p['config'])));
    }
    $ax_erg = array('gespeichert' => 1, 'hinweise' => array());
    // Praefixwechsel oder MQTT aus: unter dem BISHERIGEN Praefix abraeumen und voll senden.
    if ($ax_alt['mqtt_praefix'] !== $ax_neu['mqtt_praefix'] || (!empty($ax_alt['mqtt_ein']) && empty($ax_neu['mqtt_ein']))) {
        list($ax_n, $ax_ger, $ax_ueb) = ax_mqtt_raeumen($ax_alt['mqtt_praefix']);
        if ($ax_n >= 0) { $ax_erg['hinweise'][] = sprintf(ax_t('MQTT.GERAEUMT'), $ax_alt['mqtt_praefix'], $ax_ger, $ax_ueb); }
    }
    if (is_file($ax_datadir . '/mqtt_letzte.json')) { @unlink($ax_datadir . '/mqtt_letzte.json'); }
    ax_abo_datei($ax_neu['mqtt_praefix'], true);
    ax_hue_docker_datei_nachziehen($ax_neu);   // Nr. 41: MQTT-Werte fuer den Container (ohne Docker-Aufruf)
    ax_log('INFO', 'MQTT gespeichert (ein=' . $ax_neu['mqtt_ein'] . ', Praefix ' . $ax_neu['mqtt_praefix']
        . ', Befehle ' . $ax_neu['befehle_mqtt_ein'] . ', Routine ' . $ax_neu['befehle_routine_ein'] . ').');
    // Das Befehlsabo nachziehen und sagen, was geschah.
    $ax_st = ax_dienst_status();
    if ($ax_st !== null) {
        $ax_soll = !empty($ax_neu['befehle_mqtt_ein']) && !empty($ax_neu['mqtt_ein']) && !empty($ax_neu['aktiv']);
        if ($ax_soll && ($ax_st === false || $ax_alt['mqtt_praefix'] !== $ax_neu['mqtt_praefix'])) {
            if ($ax_st) { ax_dienst('stop'); }
            list($ax_d_ok, $ax_d_t) = ax_dienst('start');
            $ax_erg['hinweise'][] = $ax_d_ok ? ax_t('MQTT.DIENST_GESTARTET') : sprintf(ax_t('MQTT.DIENST_FEHL'), $ax_d_t);
        } elseif (!$ax_soll && $ax_st) {
            list($ax_d_ok) = ax_dienst('stop');
            $ax_erg['hinweise'][] = $ax_d_ok ? ax_t('MQTT.DIENST_ANGEHALTEN') : sprintf(ax_t('MQTT.DIENST_FEHL'), '');
        }
    }
    ax_ui_umleiten($ax_datadir, 'tab-mqtt', $ax_erg);
}

/* ---------------- Einstellungen zurueckspielen ---------------- */
if ($ax_post && isset($_POST['ax_zurueck'])) {
    $ax_hw = array();
    $ax_erg = array();
    if (!isset($_FILES['ax_sicherung']) || !is_array($_FILES['ax_sicherung']) || !isset($_FILES['ax_sicherung']['tmp_name'])
        || !is_string($_FILES['ax_sicherung']['tmp_name']) || !@is_uploaded_file($_FILES['ax_sicherung']['tmp_name'])) {
        $ax_hw[] = ax_t('SICH.KEINE_DATEI');
    } elseif ((int) $_FILES['ax_sicherung']['size'] > 65536) {
        $ax_hw[] = ax_t('SICH.ZU_GROSS');
    } else {
        list($ax_neu, $ax_meld, $ax_n, $ax_amz) = ax_sicherung_lesen((string) @file_get_contents($_FILES['ax_sicherung']['tmp_name']));
        if ($ax_neu === null) {
            $ax_hw[] = ax_t('SICH.ABGELEHNT') . ' ' . implode(' ', $ax_meld);
        } elseif (ax_config_speichern($ax_neu)) {
            $ax_erg['meldung'] = sprintf(ax_t('SICH.UEBERNOMMEN'), $ax_n);
            foreach ($ax_meld as $ax_m) { $ax_hw[] = $ax_m; }
            if ($ax_amz !== null) {
                $ax_hw[] = ax_amazon_speichern($ax_amz) ? ax_t('SICH.AMAZON_UEBERNOMMEN') : ax_t('SICH.AMAZON_FEHL');
            } else {
                $ax_hw[] = ax_t('SICH.OHNE_AMAZON');
            }
            if (is_file($ax_datadir . '/mqtt_letzte.json')) { @unlink($ax_datadir . '/mqtt_letzte.json'); }
            ax_abo_datei($ax_neu['mqtt_praefix'], true);
            ax_log('INFO', 'Konfiguration: Sicherung zurueckgespielt (' . (int) $ax_n . ' Werte' . ($ax_amz !== null ? ', mit Anmeldung' : '') . ').');
            $ax_hw[] = ax_t('SICH.DIENST_NACHGEZOGEN');
            $ax_hue_t = ax_hue_nachziehen($ax_neu, $ax_cfg);
            if ($ax_hue_t !== '') { $ax_hw[] = $ax_hue_t; }
        } else {
            $ax_hw[] = ax_t('SICH.SCHREIBFEHLER');
        }
    }
    $ax_erg['hinweise'] = $ax_hw;
    ax_ui_umleiten($ax_datadir, 'tab-settings', $ax_erg);
}

/* ---------------- Reiter Geraete: Routinen bei Amazon anzeigen (R3, nur lesend) ---------------- */
if ($ax_post && isset($_POST['routinen_anzeigen'])) {
    if (!ax_amazon()) {
        ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => sprintf(ax_t('GER.ROUTINEN_FEHL'), ax_grund_text('ANMELDUNG'))));
    }
    if (!function_exists('curl_init')) {
        ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => sprintf(ax_t('GER.ROUTINEN_FEHL'), ax_grund_text('CURL_FEHLT'))));
    }
    $ax_sp = ax_sperre();
    if (!$ax_sp) {
        ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => sprintf(ax_t('GER.ROUTINEN_FEHL'), ax_grund_text('BESCHAEFTIGT'))));
    }
    list($ax_ok, $ax_g, $ax_rl) = ax_amazon_routinen();
    ax_sperre_frei($ax_sp);
    if (!$ax_ok) {
        ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => sprintf(ax_t('GER.ROUTINEN_FEHL'), ax_grund_text($ax_g))));
    }
    // Nur Name und Sprachausloeser - kein Inhalt, keine Kennung (R3).
    $ax_anz = array();
    foreach ($ax_rl as $ax_r) {
        $ax_anz[] = array('name' => ax_anzeige_text($ax_r['name']),
                          'ausloeser' => array_values(array_filter(array_map('ax_anzeige_text', array_slice($ax_r['ausloeser'], 0, 5)), 'strlen')));
        if (count($ax_anz) >= 200) { break; }
    }
    if (!ax_write_json($ax_datadir . '/routinen.json', array('stand' => time(), 'liste' => $ax_anz), 0600)) {
        ax_ui_umleiten($ax_datadir, 'tab-geraete', array('fehler' => sprintf(ax_t('MELDUNG.SPEICHERN_FEHL'), $ax_datadir . '/routinen.json')));
    }
    ax_log('INFO', 'Routinen bei Amazon angezeigt: ' . count($ax_anz) . ' (nur Namen und Sprachausloeser).');
    ax_ui_umleiten($ax_datadir, 'tab-geraete', array('meldung' => sprintf(ax_t('GER.ROUTINEN_GEHOLT'), count($ax_anz))));
}

/* ---------------- Reiter Test: freigegebene Routine starten (R2) ----------------
 * Dieselbe Funktion wie aktion=routine; das Aktionstoken bleibt intern (es steht
 * in keinem Formular). Nur Namen aus der Freigabeliste, nie ein stiller Rueckfall
 * auf das Standardgeraet. PRG: das Ergebnis reist in der Einmalmeldung. */
if ($ax_post && isset($_POST['routine_start'])) {
    $ax_rn = (isset($_POST['test_routine']) && is_string($_POST['test_routine'])) ? $_POST['test_routine'] : null;
    $ax_rg = (isset($_POST['test_routine_geraet']) && is_string($_POST['test_routine_geraet'])) ? trim($_POST['test_routine_geraet']) : null;
    $ax_rueck = array();
    $ax_rn_frei = ($ax_rn !== null && in_array($ax_rn, $ax_cfg['routinen_frei'], true));
    if ($ax_rn_frei) { $ax_rueck['test_routine'] = $ax_rn; }
    if ($ax_rg !== null && preg_match('/^[a-z0-9_]{1,40}\z/', $ax_rg)) { $ax_rueck['test_routine_geraet'] = $ax_rg; }
    if (!$ax_rn_frei) {
        ax_ui_umleiten($ax_datadir, 'tab-test', array('fehler' => ax_grund_text('ROUTINE_NICHT_FREIGEGEBEN'), 'test_routine_falsch' => 1) + $ax_rueck);
    }
    if ($ax_rg === null || $ax_rg === '') {
        ax_ui_umleiten($ax_datadir, 'tab-test', array('fehler' => ax_grund_text('KEIN_GERAET'), 'test_routine_geraet_falsch' => 1) + $ax_rueck);
    }
    if (!isset($ax_rueck['test_routine_geraet'])) {
        ax_ui_umleiten($ax_datadir, 'tab-test', array('fehler' => ax_grund_text('GERAET'), 'test_routine_geraet_falsch' => 1) + $ax_rueck);
    }
    list($ax_h, $ax_f) = ax_befehl_ausfuehren('routine', array('name' => $ax_rn, 'geraet' => $ax_rg), 'oberflaeche');
    $ax_z = ax_zeile('ROUTINE', $ax_f);
    $ax_grund = isset($ax_f['GRUND']) ? (string) $ax_f['GRUND'] : '-';
    $ax_erg = !empty($ax_f['OK'])
        ? array('meldung' => sprintf(ax_t('TEST.ROUTINE_OK'), $ax_rn, $ax_rg, $ax_z))
        : array('fehler' => sprintf(ax_t('TEST.ROUTINE_FEHL'), $ax_rn, $ax_rg, $ax_h, ax_grund_text($ax_grund), $ax_z));
    if (in_array($ax_grund, array('GERAET', 'GERAET_UNBEKANNT', 'GERAET_VERSCHWUNDEN', 'GERAETE_OFFLINE'), true)) { $ax_erg['test_routine_geraet_falsch'] = 1; }
    if (in_array($ax_grund, array('ROUTINE_NICHT_FREIGEGEBEN', 'ROUTINE_UNBEKANNT'), true)) { $ax_erg['test_routine_falsch'] = 1; }
    ax_ui_umleiten($ax_datadir, 'tab-test', $ax_erg + $ax_rueck);
}

/* ---------------- Reiter Test: Musik-Probe (B4) ----------------
 * Dieselbe Funktion wie aktion=musik_probe/musik_stopp. Mit Nummer aus der
 * Senderliste gilt deren Anbieter; ein Name geht mit dem gewaehlten Anbieter.
 * Beanstandete Felder kommen markiert zurueck (X-2). */
if ($ax_post && (isset($_POST['musik_start']) || isset($_POST['musik_halt']))) {
    $ax_start = isset($_POST['musik_start']);
    $ax_mw = function ($k) { return (isset($_POST[$k]) && is_string($_POST[$k])) ? trim($_POST[$k]) : null; };
    $ax_mg = $ax_mw('musik_geraet');
    $ax_mn = $ax_mw('musik_nr');
    $ax_ms = $ax_mw('musik_sender_name');
    $ax_ma = $ax_mw('musik_anbieter');
    $ax_ids = ax_musik_anbieter();
    $ax_rueck = array();
    if ($ax_mg !== null && preg_match('/^[a-z0-9_]{1,40}\z/', $ax_mg)) { $ax_rueck['musik_geraet'] = $ax_mg; }
    if ($ax_mn !== null && preg_match('/^[1-9][0-9]?\z/', $ax_mn)) { $ax_rueck['musik_nr'] = $ax_mn; }
    if ($ax_ms !== null && $ax_ms !== '' && ax_ui_tauglich($ax_ms) && strlen($ax_ms) <= 400) { $ax_rueck['musik_sender_name'] = $ax_ms; }
    if ($ax_ma !== null && isset($ax_ids[$ax_ma])) { $ax_rueck['musik_anbieter'] = $ax_ma; }
    if ($ax_mg === null || $ax_mg === '' || !isset($ax_rueck['musik_geraet'])) {
        ax_ui_umleiten($ax_datadir, 'tab-test', array('fehler' => ax_grund_text($ax_mg === null || $ax_mg === '' ? 'KEIN_GERAET' : 'GERAET'),
            'musik_falsch' => array('musik_geraet')) + $ax_rueck);
    }
    $ax_par = array('geraet' => $ax_mg);
    if ($ax_start) {
        if ($ax_mn !== null && $ax_mn !== '') { $ax_par['nr'] = $ax_mn; }
        if ($ax_ms !== null && $ax_ms !== '') {
            $ax_par['sender'] = $ax_ms;
            $ax_par['anbieter'] = ($ax_ma !== null) ? $ax_ma : '';
        }
    }
    list($ax_h, $ax_f) = ax_befehl_ausfuehren($ax_start ? 'musik_probe' : 'musik_stopp', $ax_par, 'oberflaeche');
    $ax_z = ax_zeile('MUSIK', $ax_f);
    $ax_grund = isset($ax_f['GRUND']) ? (string) $ax_f['GRUND'] : '-';
    $ax_erg = !empty($ax_f['OK'])
        ? array('meldung' => sprintf(ax_t('TEST.MUSIK_OK'), $ax_z))
        : array('fehler' => sprintf(ax_t('TEST.MUSIK_FEHL'), $ax_h, ax_grund_text($ax_grund), $ax_z));
    $ax_mf = array('NR_UND_SENDER' => array('musik_nr', 'musik_sender_name'), 'SENDER_FEHLT' => array('musik_nr', 'musik_sender_name'),
                   'NR' => array('musik_nr'), 'SENDER_UNBEKANNT' => array('musik_nr'), 'SENDER' => array('musik_sender_name'),
                   'ANBIETER' => array('musik_anbieter'), 'EIN_ZIEL' => array('musik_geraet'), 'GERAET' => array('musik_geraet'),
                   'GERAET_UNBEKANNT' => array('musik_geraet'), 'GERAET_VERSCHWUNDEN' => array('musik_geraet'), 'GRUPPE_UNBEKANNT' => array('musik_geraet'),
                   'GERAETE_OFFLINE' => array('musik_geraet'));
    if (isset($ax_mf[$ax_grund])) { $ax_erg['musik_falsch'] = $ax_mf[$ax_grund]; }
    ax_ui_umleiten($ax_datadir, 'tab-test', $ax_erg + $ax_rueck);
}

/* ---------------- Reiter Test: Radio je Zone (Z6) ----------------
 * Dieselbe Funktion wie aktion=radio/radio_stopp; das Aktionstoken bleibt
 * intern (es steht in keinem Formular). PRG: das Ergebnis reist in der
 * Einmalmeldung; beanstandete Felder kommen markiert zurueck (X-2). */
if ($ax_post && (isset($_POST['radio_start']) || isset($_POST['radio_halt']))) {
    $ax_start = isset($_POST['radio_start']);
    $ax_rw = function ($k) { return (isset($_POST[$k]) && is_string($_POST[$k])) ? trim($_POST[$k]) : null; };
    $ax_rzone = $ax_rw('radio_zone');
    $ax_rnr = $ax_rw('radio_nr');
    $ax_rueck = array();
    if ($ax_rzone !== null && preg_match('/^([1-9][0-9]?|alle)\z/', $ax_rzone)) { $ax_rueck['radio_zone'] = $ax_rzone; }
    if ($ax_rnr !== null && preg_match('/^[1-9][0-9]?\z/', $ax_rnr)) { $ax_rueck['radio_nr'] = $ax_rnr; }
    if ($ax_rzone === null || $ax_rzone === '') {
        ax_ui_umleiten($ax_datadir, 'tab-test', array('fehler' => ax_grund_text('ZONE'), 'radio_falsch' => array('radio_zone')) + $ax_rueck);
    }
    if ($ax_start && ($ax_rnr === null || $ax_rnr === '')) {
        ax_ui_umleiten($ax_datadir, 'tab-test', array('fehler' => ax_grund_text('NR'), 'radio_falsch' => array('radio_nr')) + $ax_rueck);
    }
    $ax_par = array('zone' => $ax_rzone);
    if ($ax_start) { $ax_par['nr'] = $ax_rnr; }
    list($ax_h, $ax_f) = ax_radio_ausfuehren($ax_start ? 'radio' : 'radio_stopp', $ax_par, 'oberflaeche');
    $ax_z = ax_zeile('RADIO', $ax_f);
    $ax_grund = isset($ax_f['GRUND']) ? (string) $ax_f['GRUND'] : '-';
    $ax_erg = !empty($ax_f['OK'])
        ? array('meldung' => sprintf(ax_t('TEST.RADIO_OK'), $ax_z))
        : array('fehler' => sprintf(ax_t('TEST.RADIO_FEHL'), $ax_h, ax_grund_text($ax_grund), $ax_z));
    $ax_rf = array('ZONE' => array('radio_zone'), 'ZONE_UNBEKANNT' => array('radio_zone'), 'GERAET_UNBEKANNT' => array('radio_zone'),
                   'GERAET_VERSCHWUNDEN' => array('radio_zone'),
                   'GRUPPE_UNBEKANNT' => array('radio_zone'), 'GERAETE_OFFLINE' => array('radio_zone'),
                   'NR' => array('radio_nr'), 'SENDER_UNBEKANNT' => array('radio_nr'));
    if (isset($ax_rf[$ax_grund])) { $ax_erg['radio_falsch'] = $ax_rf[$ax_grund]; }
    ax_ui_umleiten($ax_datadir, 'tab-test', $ax_erg + $ax_rueck);
}

/* Ein POST, den kein Handler kannte: trotzdem umleiten. */
if ($ax_post) {
    ax_ui_umleiten($ax_datadir, $ax_tab, array());
}

/* ---------------- GET: Ergebnis des vorigen Absendens ---------------- */
$ax_flash = ax_ui_flash_lesen($ax_datadir);
if (!empty($ax_flash['tab']) && is_string($ax_flash['tab']) && preg_match($ax_muster, $ax_flash['tab']) && !isset($_GET['form'])) {
    $ax_tab = $ax_flash['tab'];
}
$ax_gespeichert = !empty($ax_flash['gespeichert']);
$ax_fehler = isset($ax_flash['fehler']) && is_string($ax_flash['fehler']) ? $ax_flash['fehler'] : '';
$ax_meldung = isset($ax_flash['meldung']) && is_string($ax_flash['meldung']) ? $ax_flash['meldung'] : '';
$ax_hinweise = isset($ax_flash['hinweise']) && is_array($ax_flash['hinweise']) ? $ax_flash['hinweise'] : array();
// Reiter Test: die zuletzt gewaehlte Testansage-Auswahl (nur ein Normalname) und ob sie beanstandet war.
$ax_testgeraet = (isset($ax_flash['testgeraet']) && is_string($ax_flash['testgeraet']) && preg_match('/^[a-z0-9_]{1,40}\z/', $ax_flash['testgeraet']))
    ? $ax_flash['testgeraet'] : '';
$ax_testgeraet_falsch = !empty($ax_flash['testgeraet_falsch']);
$ax_aus_falsch = !empty($ax_flash['austragen_falsch']);
// Reiter Test: Routine starten (R2) und Musik-Probe (B4) - die zurueckgegebene Wahl.
$ax_fl = function ($k, $muster) use ($ax_flash) {
    return (isset($ax_flash[$k]) && is_string($ax_flash[$k]) && preg_match($muster, $ax_flash[$k])) ? $ax_flash[$k] : '';
};
$ax_tr = (isset($ax_flash['test_routine']) && is_string($ax_flash['test_routine'])) ? $ax_flash['test_routine'] : '';
$ax_tr_falsch = !empty($ax_flash['test_routine_falsch']);
$ax_trg = $ax_fl('test_routine_geraet', '/^[a-z0-9_]{1,40}\z/');
$ax_trg_falsch = !empty($ax_flash['test_routine_geraet_falsch']);
$ax_mz = array('musik_geraet' => $ax_fl('musik_geraet', '/^[a-z0-9_]{1,40}\z/'), 'musik_nr' => $ax_fl('musik_nr', '/^[1-9][0-9]?\z/'),
               'musik_sender_name' => $ax_fl('musik_sender_name', '/^[^\x00-\x1F\x7F]{1,400}\z/u'),
               'musik_anbieter' => $ax_fl('musik_anbieter', '/^(tunein|amazon)\z/'));
$ax_mz_falsch = (isset($ax_flash['musik_falsch']) && is_array($ax_flash['musik_falsch']))
    ? array_values(array_intersect($ax_flash['musik_falsch'], array_keys($ax_mz))) : array();
$ax_mzm = function ($k) use ($ax_mz_falsch) { return in_array($k, $ax_mz_falsch, true) ? ' class="sm-beanstandet" aria-invalid="true"' : ''; };
// Reiter Test: Radio je Zone (Z6) - die zurueckgegebene Wahl und was beanstandet war.
$ax_rzf = array('radio_zone' => $ax_fl('radio_zone', '/^([1-9][0-9]?|alle)\z/'), 'radio_nr' => $ax_fl('radio_nr', '/^[1-9][0-9]?\z/'));
$ax_rzf_falsch = (isset($ax_flash['radio_falsch']) && is_array($ax_flash['radio_falsch']))
    ? array_values(array_intersect(array_filter($ax_flash['radio_falsch'], 'is_string'), array_keys($ax_rzf))) : array();
$ax_rzm = function ($k) use ($ax_rzf_falsch) { return in_array($k, $ax_rzf_falsch, true) ? ' class="sm-beanstandet" aria-invalid="true"' : ''; };
ax_ui_eingaben(isset($ax_flash['eingaben']) ? $ax_flash['eingaben'] : array());
foreach ($ax_heil as $ax_m) { $ax_hinweise[] = $ax_m; }

$ax_cfg = ax_config();
$ax_lage = ax_config_lage();
$ax_amz = ax_amazon_lage();
$ax_bef = ax_anmeldung_befund();
$ax_sitz = ax_sitzung_lesen();
$ax_st = ax_geraete();
$ax_takt = ax_takt_lesen();
$ax_letzte = ax_json_lesen($ax_datadir . '/letzte.json');
$ax_pkce = ax_pkce_lage();
$ax_gw = ax_mqtt_gateway_info();
$ax_host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
$ax_basis = ax_endpunkt_basis($ax_host);
$ax_sprech = (string) $ax_cfg['sprechtoken'];
$ax_aktion = (string) $ax_cfg['aktionstoken'];
$ax_std = $ax_cfg['standardgeraet'] !== '' ? $ax_cfg['standardgeraet'] : 'kueche';
$ax_zeit = function ($ts) { return (int) $ts > 0 ? date('d.m.Y H:i:s', (int) $ts) : ax_t('ALLG.NIE'); };
// K1: der Zustand der Sperre aus Loxone - eine Stelle fuer Einstellungen und Reiter Test.
$ax_sperre_l = ax_loxsperre_lesen();
$ax_sperre_zeile = function () use ($ax_cfg, $ax_sperre_l, $ax_zeit) {
    if (empty($ax_cfg['sperre_ein'])) { return array(3, ax_t('TEST.A_SPERRE_AUS')); }
    if (!$ax_sperre_l['bekannt']) { return array(2, ax_t('TEST.A_SPERRE_UNBEKANNT')); }
    if ($ax_sperre_l['gesperrt'] === 1) {
        return array(2, sprintf(ax_t('TEST.A_SPERRE_GESPERRT'), $ax_zeit($ax_sperre_l['seit']), $ax_sperre_l['quelle'] !== '' ? $ax_sperre_l['quelle'] : '-'));
    }
    return array(1, sprintf(ax_t('TEST.A_SPERRE_OFFEN'), $ax_zeit($ax_sperre_l['seit']), $ax_sperre_l['quelle'] !== '' ? $ax_sperre_l['quelle'] : '-'));
};
$ax_rt_anz = ax_json_lesen($ax_datadir . '/routinen.json');
$ax_radio_text = implode("\n", array_map(function ($z) { return $z['zone'] . ' = ' . $z['ziel']; }, $ax_cfg['radio_zonen']));
$ax_senderliste_text = implode("\n", array_map(function ($z) { return $z['nr'] . ' = ' . $z['name'] . ' | ' . $z['anbieter']; }, $ax_cfg['musik_sender']));

if (class_exists('LBWeb', false)) {
    LBWeb::lbheader('Alexa NG', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln - bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Jede Tabelle mit mehr als sechs Spalten oder mit Eingabefeldern kommt in
   sm-breit (Hausvorlage). */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen (Hausvorlage).
   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }

/* ---- Ab hier Ergaenzungen dieses Plugins, nicht Teil der Hausvorlage ---- */
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap textarea, .sm-wrap input[type=time] {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-wrap .sm-haken { display: inline-flex; align-items: center; gap: 8px; font-weight: normal; margin: 8px 0; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1 1 200px; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-grau { color: #888; }
.sm-gelb { color: #b07800; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-wrap input[type=checkbox].sm-beanstandet { outline: 2px solid #c62828; outline-offset: 2px; }
/* Welle Bild (Entscheidung 45): Bild der Bausteine aus dem gemeinsamen Musterprojekt. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
</style>
<div class="sm-wrap">

<?php if ($ax_gespeichert) { ?><div class="sm-alert sm-ok"><b><?= ax_e(ax_t('SEITE.GESPEICHERT')) ?></b></div><?php } ?>
<?php if ($ax_meldung !== '') { ?><div class="sm-alert sm-ok"><?= ax_e($ax_meldung) ?></div><?php } ?>
<?php if ($ax_fehler !== '') { ?><div class="sm-alert sm-err"><b><?= ax_e(ax_t('SEITE.FEHLER')) ?></b> <?= ax_e($ax_fehler) ?></div><?php } ?>
<?php if ($ax_hinweise) { ?><div class="sm-alert sm-warn"><?php foreach ($ax_hinweise as $ax_h) { if (is_string($ax_h)) { echo ax_e($ax_h) . '<br>'; } } ?></div><?php } ?>
<?php if (ax_attrappe_aktiv()) { ?><div class="sm-warnung"><?= ax_e(ax_t('SEITE.ATTRAPPE')) ?></div><?php } ?>
<?php
// K2: Hinweisbalken ueber allen Reitern - abgelaufene Anmeldung, stehender Takt.
$ax_talter_b = ax_alter($ax_takt['ts']);
$ax_cron_b = ($ax_p['lbhome'] !== '') ? (glob($ax_p['lbhome'] . '/system/cron/cron.*/' . $ax_p['plugin']) ?: array()) : array();
$ax_balken = array();
if ($ax_amz['form'] && $ax_bef['befund'] === 'ABGELAUFEN') {
    $ax_balken[] = array(ax_t('BALKEN.ABGELAUFEN'), 'index.php?form=amazon', ax_t('BALKEN.ZU_AMAZON'));
}
if ($ax_p['lbhome'] !== '' && ($ax_talter_b > AX_OK_GRENZE_S || ($ax_talter_b < 0 && !$ax_cron_b))) {
    $ax_balken[] = array($ax_talter_b < 0 ? ax_t('BALKEN.TAKT_NIE') : sprintf(ax_t('BALKEN.TAKT_STEHT'), ax_dauer_text($ax_talter_b)),
                         'index.php?form=test', ax_t('BALKEN.ZU_TEST'));
}
foreach ($ax_balken as $ax_bk) { ?><div class="sm-alert sm-err ax-balken" role="alert"><b><?= ax_e($ax_bk[0]) ?></b> <a href="<?= ax_e($ax_bk[1]) ?>"><?= ax_e($ax_bk[2]) ?></a></div>
<?php } ?>

<?php
// Kopf (Entscheidung Nr. 43, seit 1.0.1): Statusuebersicht ueber den Reitern, immer sichtbar.
// Die Kacheln standen bis 1.0.0 im Reiter Einstellungen. Den Befehlsdienst fragte die
// Seite schon bisher bei jedem Aufbau ab (Reiter Test) - die Abfrage steht jetzt hier.
$ax_kopf_dienst = empty($ax_cfg['befehle_mqtt_ein']) ? false : ax_dienst_status();
?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?= ax_e(ax_t('KOPF.EIGENSCHAFT')) ?></th><th><?= ax_e(ax_t('KOPF.WERT')) ?></th></tr>
<tr><td><?= ax_e(ax_t('KOPF.DIENST')) ?></td>
    <?php if (empty($ax_cfg['befehle_mqtt_ein'])) { ?><td><?= ax_e(ax_t('ALLG.AUS')) ?></td>
    <?php } elseif ($ax_kopf_dienst === null) { ?><td><?= ax_e(ax_t('KOPF.NICHT')) ?></td>
    <?php } else { ?><td class="<?= $ax_kopf_dienst ? 'sm-an' : 'sm-aus' ?>"><?= ax_e($ax_kopf_dienst ? ax_t('KOPF.LAEUFT') : ax_t('KOPF.STEHT')) ?></td><?php } ?></tr>
<tr><td><?= ax_e(ax_t('KACHEL.PLUGIN')) ?></td>
    <td><?= !empty($ax_cfg['aktiv']) ? ax_e(ax_t('ALLG.EIN')) : ax_e(ax_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= ax_e(ax_t('KACHEL.ANMELDUNG')) ?></td>
    <td><?= $ax_amz['form'] ? ($ax_bef['befund'] === 'ABGELAUFEN' ? ax_e(ax_t('KACHEL.ABGELAUFEN')) : ax_e(ax_t('ALLG.JA'))) : ax_e(ax_t('ALLG.NEIN')) ?></td></tr>
<tr><td><?= ax_e(ax_t('KACHEL.GERAETE')) ?></td>
    <td><?= $ax_st ? count($ax_st['liste']) : '–' ?></td></tr>
<tr><td><?= ax_e(ax_t('KACHEL.TAKT')) ?></td>
    <td><?= ax_e(ax_dauer_text(ax_alter($ax_takt['ts']))) ?></td></tr>
</table>

<div class="sm-tabs">
    <a class="sm-tab<?= $ax_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= ax_e(ax_t('REITER.EINSTELLUNGEN')) ?></a>
    <a class="sm-tab<?= $ax_tab === 'tab-amazon' ? ' sm-active' : '' ?>" data-ziel="tab-amazon" href="index.php?form=amazon"><?= ax_e(ax_t('REITER.AMAZON')) ?></a>
    <a class="sm-tab<?= $ax_tab === 'tab-geraete' ? ' sm-active' : '' ?>" data-ziel="tab-geraete" href="index.php?form=geraete"><?= ax_e(ax_t('REITER.GERAETE')) ?></a>
    <a class="sm-tab<?= $ax_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt">MQTT</a>
    <a class="sm-tab<?= $ax_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?= ax_e(ax_t('REITER.LOXONE')) ?></a>
    <a class="sm-tab<?= $ax_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?form=test"><?= ax_e(ax_t('REITER.TEST')) ?></a>
    <a class="sm-tab<?= $ax_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?form=log"><?= ax_e(ax_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $ax_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><b><?= ax_e(ax_t('EINST.WAS_IST_DAS_KURZ')) ?></b> <?= ax_e(ax_t('EINST.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ax_e(ax_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ax_e(ax_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<h2><?= ax_e(ax_t('EINST.H_ALLGEMEIN')) ?></h2>
<label class="sm-haken"><input data-role="none" type="checkbox" name="aktiv" value="1"<?= ax_ui_h('aktiv', !empty($ax_cfg['aktiv'])) ? ' checked' : '' ?><?= ax_ui_m('aktiv') ?>> <?= ax_e(ax_t('EINST.L_AKTIV')) ?></label>
<div class="sm-small"><?= ax_e(ax_t('EINST.AKTIV_HILFE')) ?></div>
<label for="standardgeraet"><?= ax_e(ax_t('EINST.L_STANDARD')) ?></label>
<?php
$ax_sg = ax_ui_w('standardgeraet', $ax_cfg['standardgeraet']);
// Genau ein sprechfaehiges Geraet und noch kein Standardgeraet: im Formular
// vorauswaehlen. Gespeichert wird erst mit "Speichern"; eine zurueckgegebene
// Eingabe nach einer Beanstandung (auch "keines") hat Vorrang.
$ax_sg_vorschlag = false;
if ($ax_sg === '' && $ax_cfg['standardgeraet'] === '' && !ax_ui_aktiv('standardgeraet') && $ax_st) {
    $ax_sprechfaehig = array();
    foreach ($ax_st['liste'] as $ax_g) { if ($ax_g['familie'] !== 'WHA') { $ax_sprechfaehig[] = $ax_g['normal']; } }
    if (count($ax_sprechfaehig) === 1) { $ax_sg = $ax_sprechfaehig[0]; $ax_sg_vorschlag = true; }
}
?>
<select data-role="none" name="standardgeraet" id="standardgeraet"<?= ax_ui_m('standardgeraet') ?>>
<option value=""<?= $ax_sg === '' ? ' selected' : '' ?>><?= ax_e(ax_t('EINST.O_KEIN')) ?></option>
<?php if ($ax_st) { foreach ($ax_st['liste'] as $ax_g) { if ($ax_g['familie'] === 'WHA') { continue; } ?>
<option value="<?= ax_e($ax_g['normal']) ?>"<?= $ax_sg === $ax_g['normal'] ? ' selected' : '' ?>><?= ax_e($ax_g['normal'] . ' (' . $ax_g['anzeige'] . ')') ?></option>
<?php } } ?>
<?php if ($ax_sg !== '' && (!$ax_st || !in_array($ax_sg, array_map(function ($g) { return $g['normal']; }, $ax_st['liste']), true))) { ?>
<option value="<?= ax_e($ax_sg) ?>" selected><?= ax_e($ax_sg . ' – ' . ax_t('EINST.O_UNBEKANNT')) ?></option>
<?php } ?>
</select>
<?php if ($ax_sg_vorschlag) { ?><div class="sm-small"><?= ax_e(ax_t('EINST.STANDARD_VORSCHLAG')) ?></div><?php } ?>
<div class="sm-small"><?= ax_e(ax_t('EINST.STANDARD_HILFE')) ?></div>

<h2><?= ax_e(ax_t('EINST.H_GRUPPEN')) ?></h2>
<div class="sm-small"><?= ax_e(ax_t('EINST.GRUPPEN_HILFE')) ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:30%"><?= ax_e(ax_t('EINST.T_GRUPPE')) ?></th><th style="width:70%"><?= ax_e(ax_t('EINST.T_GERAETE')) ?></th></tr>
<?php for ($ax_i = 0; $ax_i < 10; $ax_i++) {
    $ax_z = isset($ax_cfg['gruppen'][$ax_i]) ? $ax_cfg['gruppen'][$ax_i] : array('name' => '', 'geraete' => ''); ?>
<tr><td><input data-role="none" type="text" name="gruppe_name[<?= (int) $ax_i ?>]" value="<?= ax_e(ax_ui_w('gruppe_name', $ax_z['name'], $ax_i)) ?>"<?= ax_ui_m('gruppe_name', $ax_i) ?> placeholder="unten"></td>
    <td><input data-role="none" type="text" name="gruppe_geraete[<?= (int) $ax_i ?>]" value="<?= ax_e(ax_ui_w('gruppe_geraete', $ax_z['geraete'], $ax_i)) ?>"<?= ax_ui_m('gruppe_geraete', $ax_i) ?> placeholder="kueche,wohnzimmer"></td></tr>
<?php } ?>
</table>
</div>

<h2><?= ax_e(ax_t('EINST.H_BREMSE')) ?></h2>
<div class="sm-row">
    <div><label for="bremse_fenster_s"><?= ax_e(ax_t('EINST.L_FENSTER')) ?></label>
        <input data-role="none" type="number" id="bremse_fenster_s" name="bremse_fenster_s" min="0" max="3600" value="<?= ax_e(ax_ui_w('bremse_fenster_s', $ax_cfg['bremse_fenster_s'])) ?>"<?= ax_ui_m('bremse_fenster_s') ?>>
        <div class="sm-small"><?= ax_e(ax_t('EINST.FENSTER_HILFE')) ?></div></div>
    <div><label for="mindestabstand_s"><?= ax_e(ax_t('EINST.L_ABSTAND')) ?></label>
        <input data-role="none" type="number" id="mindestabstand_s" name="mindestabstand_s" min="0" max="600" value="<?= ax_e(ax_ui_w('mindestabstand_s', $ax_cfg['mindestabstand_s'])) ?>"<?= ax_ui_m('mindestabstand_s') ?>>
        <div class="sm-small"><?= ax_e(ax_t('EINST.ABSTAND_HILFE')) ?></div></div>
    <div><label for="stundengrenze"><?= ax_e(ax_t('EINST.L_STUNDE')) ?></label>
        <input data-role="none" type="number" id="stundengrenze" name="stundengrenze" min="10" max="240" value="<?= ax_e(ax_ui_w('stundengrenze', $ax_cfg['stundengrenze'])) ?>"<?= ax_ui_m('stundengrenze') ?>>
        <div class="sm-small"><?= ax_e(ax_t('EINST.STUNDE_HILFE')) ?></div></div>
</div>

<h2><?= ax_e(ax_t('EINST.H_RUHE')) ?></h2>
<label class="sm-haken"><input data-role="none" type="checkbox" name="ruhe_ein" value="1"<?= ax_ui_h('ruhe_ein', !empty($ax_cfg['ruhe_ein'])) ? ' checked' : '' ?><?= ax_ui_m('ruhe_ein') ?>> <?= ax_e(ax_t('EINST.L_RUHE')) ?></label>
<div class="sm-row">
    <div><label for="ruhe_von"><?= ax_e(ax_t('EINST.L_VON')) ?></label><input data-role="none" type="time" id="ruhe_von" name="ruhe_von" value="<?= ax_e(ax_ui_w('ruhe_von', $ax_cfg['ruhe_von'])) ?>"<?= ax_ui_m('ruhe_von') ?>></div>
    <div><label for="ruhe_bis"><?= ax_e(ax_t('EINST.L_BIS')) ?></label><input data-role="none" type="time" id="ruhe_bis" name="ruhe_bis" value="<?= ax_e(ax_ui_w('ruhe_bis', $ax_cfg['ruhe_bis'])) ?>"<?= ax_ui_m('ruhe_bis') ?>></div>
</div>
<div class="sm-small"><?= ax_e(ax_t('EINST.RUHE_HILFE')) ?></div>

<h2><?= ax_e(ax_t('EINST.H_FREIGABEN')) ?></h2>
<label class="sm-haken"><input data-role="none" type="checkbox" name="ankuendigen_ein" value="1"<?= ax_ui_h('ankuendigen_ein', !empty($ax_cfg['ankuendigen_ein'])) ? ' checked' : '' ?><?= ax_ui_m('ankuendigen_ein') ?>> <?= ax_e(ax_t('EINST.L_ANKUENDIGEN')) ?></label>
<div class="sm-warnung"><?= ax_e(ax_t('EINST.ANKUENDIGEN_HILFE')) ?></div>
<label class="sm-haken"><input data-role="none" type="checkbox" name="texte_protokollieren" value="1"<?= ax_ui_h('texte_protokollieren', !empty($ax_cfg['texte_protokollieren'])) ? ' checked' : '' ?><?= ax_ui_m('texte_protokollieren') ?>> <?= ax_e(ax_t('EINST.L_TEXTE_LOG')) ?></label>
<div class="sm-small"><?= ax_e(ax_t('EINST.TEXTE_LOG_HILFE')) ?></div>

<h2 id="routinen"><?= ax_e(ax_t('EINST.H_ROUTINEN')) ?></h2>
<label for="routinen_frei"><?= ax_e(ax_t('EINST.L_ROUTINEN')) ?></label>
<textarea data-role="none" id="routinen_frei" name="routinen_frei" rows="4" placeholder="<?= ax_e(ax_t('EINST.P_ROUTINEN')) ?>"<?= ax_ui_m('routinen_frei') ?>><?= ax_e(ax_ui_w('routinen_frei', implode("\n", $ax_cfg['routinen_frei']))) ?></textarea>
<div class="sm-small"><?= ax_e(ax_t('EINST.ROUTINEN_HILFE')) ?> <a href="index.php?form=geraete#routinen_amazon"><?= ax_e(ax_t('EINST.ZU_ROUTINEN_AMAZON')) ?></a></div>
<div class="sm-hinweis"><?= ax_e(ax_t('EINST.NAMEN_HILFE')) ?></div>

<h2 id="sperre"><?= ax_e(ax_t('EINST.H_SPERRE')) ?></h2>
<label class="sm-haken"><input data-role="none" type="checkbox" name="sperre_ein" value="1"<?= ax_ui_h('sperre_ein', !empty($ax_cfg['sperre_ein'])) ? ' checked' : '' ?><?= ax_ui_m('sperre_ein') ?>> <?= ax_e(ax_t('EINST.L_SPERRE')) ?></label>
<div class="sm-small"><?= ax_e(ax_t('EINST.SPERRE_HILFE')) ?></div>
<?php list(, $ax_sperre_text) = $ax_sperre_zeile(); ?>
<div class="sm-small"><?= ax_e(sprintf(ax_t('EINST.SPERRE_JETZT'), $ax_sperre_text)) ?></div>

<h2 id="musik"><?= ax_e(ax_t('EINST.H_MUSIK')) ?></h2>
<label class="sm-haken"><input data-role="none" type="checkbox" name="musik_ein" value="1"<?= ax_ui_h('musik_ein', !empty($ax_cfg['musik_ein'])) ? ' checked' : '' ?><?= ax_ui_m('musik_ein') ?>> <?= ax_e(ax_t('EINST.L_MUSIK')) ?></label>
<div class="sm-warnung"><?= ax_e(ax_t('EINST.MUSIK_HILFE')) ?></div>
<div class="sm-row">
    <div><label for="musik_stundengrenze"><?= ax_e(ax_t('EINST.L_MUSIK_STUNDE')) ?></label>
        <input data-role="none" type="number" id="musik_stundengrenze" name="musik_stundengrenze" min="10" max="240" value="<?= ax_e(ax_ui_w('musik_stundengrenze', $ax_cfg['musik_stundengrenze'])) ?>"<?= ax_ui_m('musik_stundengrenze') ?>>
        <div class="sm-small"><?= ax_e(ax_t('EINST.MUSIK_STUNDE_HILFE')) ?></div></div>
</div>
<h2 id="sender"><?= ax_e(ax_t('EINST.H_SENDER')) ?></h2>
<label for="musik_sender"><?= ax_e(ax_t('EINST.L_MUSIK_SENDER')) ?></label>
<textarea data-role="none" id="musik_sender" name="musik_sender" rows="5" placeholder="<?= ax_e(ax_t('EINST.P_MUSIK_SENDER')) ?>"<?= ax_ui_m('musik_sender') ?>><?= ax_e(ax_ui_w('musik_sender', $ax_senderliste_text)) ?></textarea>
<div class="sm-small"><?= ax_e(ax_t('EINST.MUSIK_SENDER_HILFE')) ?></div>

<h2 id="radio"><?= ax_e(ax_t('EINST.H_RADIO')) ?></h2>
<label class="sm-haken"><input data-role="none" type="checkbox" name="radio_ein" value="1"<?= ax_ui_h('radio_ein', !empty($ax_cfg['radio_ein'])) ? ' checked' : '' ?><?= ax_ui_m('radio_ein') ?>> <?= ax_e(ax_t('EINST.L_RADIO')) ?></label>
<div class="sm-warnung"><?= ax_e(ax_t('EINST.RADIO_HILFE')) ?></div>
<label for="radio_zonen"><?= ax_e(ax_t('EINST.L_RADIO_ZONEN')) ?></label>
<textarea data-role="none" id="radio_zonen" name="radio_zonen" rows="6" placeholder="<?= ax_e(ax_t('EINST.P_RADIO_ZONEN')) ?>"<?= ax_ui_m('radio_zonen') ?>><?= ax_e(ax_ui_w('radio_zonen', $ax_radio_text)) ?></textarea>
<div class="sm-small"><?= ax_e(ax_t('EINST.RADIO_ZONEN_HILFE')) ?></div>

<h2 id="hue"><?= ax_e(ax_t('EINST.H_HUE')) ?></h2>
<label class="sm-haken"><input data-role="none" type="checkbox" name="hue_ein" value="1"<?= ax_ui_h('hue_ein', !empty($ax_cfg['hue_ein'])) ? ' checked' : '' ?><?= ax_ui_m('hue_ein') ?>> <?= ax_e(ax_t('EINST.L_HUE')) ?></label>
<div class="sm-warnung"><?= ax_e(ax_t('EINST.HUE_HILFE')) ?></div>
<div class="sm-row">
    <div><label for="hue_port"><?= ax_e(ax_t('FELD.HUE_PORT')) ?></label>
        <input data-role="none" type="number" id="hue_port" name="hue_port" min="1024" max="65535" value="<?= ax_e(ax_ui_w('hue_port', $ax_cfg['hue_port'])) ?>"<?= ax_ui_m('hue_port') ?>>
        <div class="sm-small"><?= ax_e(ax_t('EINST.HUE_PORT_HILFE')) ?></div></div>
</div>
<?php
// Nr. 41: Art, eigene IP, Schnittstelle. Die Auswahl der Schnittstellen kommt aus "ip -o link show";
// eine gespeicherte, die es nicht (mehr) gibt, bleibt waehlbar und wird beim Speichern beanstandet.
$ax_hue_art_w = ax_ui_w('hue_art', $ax_cfg['hue_art']);
$ax_hue_if_w = ax_ui_w('hue_schnittstelle', $ax_cfg['hue_schnittstelle']);
$ax_hue_if = ax_hue_schnittstellen();
if (!in_array($ax_hue_if_w, $ax_hue_if, true)) { $ax_hue_if[] = $ax_hue_if_w; }
?>
<div class="sm-row">
    <div><label for="hue_art"><?= ax_e(ax_t('FELD.HUE_ART')) ?></label>
        <select data-role="none" name="hue_art" id="hue_art"<?= ax_ui_m('hue_art') ?>>
        <option value="loxberry"<?= $ax_hue_art_w !== 'docker' ? ' selected' : '' ?>><?= ax_e(ax_t('EINST.O_HUE_LOXBERRY')) ?></option>
        <option value="docker"<?= $ax_hue_art_w === 'docker' ? ' selected' : '' ?>><?= ax_e(ax_t('EINST.O_HUE_DOCKER')) ?></option>
        </select>
        <div class="sm-small"><?= ax_e(ax_t('EINST.HUE_ART_HILFE')) ?></div></div>
    <div><label for="hue_ip"><?= ax_e(ax_t('FELD.HUE_IP')) ?></label>
        <input data-role="none" type="text" id="hue_ip" name="hue_ip" maxlength="15" placeholder="<?= ax_e(ax_t('EINST.P_HUE_IP')) ?>" value="<?= ax_e(ax_ui_w('hue_ip', $ax_cfg['hue_ip'])) ?>"<?= ax_ui_m('hue_ip') ?>>
        <div class="sm-small"><?= ax_e(ax_t('EINST.HUE_IP_HILFE')) ?></div></div>
    <div><label for="hue_schnittstelle"><?= ax_e(ax_t('FELD.HUE_SCHNITTSTELLE')) ?></label>
        <select data-role="none" name="hue_schnittstelle" id="hue_schnittstelle"<?= ax_ui_m('hue_schnittstelle') ?>>
<?php foreach ($ax_hue_if as $ax_n) { ?>
        <option value="<?= ax_e($ax_n) ?>"<?= $ax_n === $ax_hue_if_w ? ' selected' : '' ?>><?= ax_e($ax_n) ?></option>
<?php } ?>
        </select>
        <div class="sm-small"><?= ax_e(ax_t('EINST.HUE_SCHNITTSTELLE_HILFE')) ?></div></div>
</div>
<div class="sm-hinweis"><?= ax_e(ax_t('EINST.HUE_DOCKER_HINWEIS')) ?></div>
<h3 id="hue_lampen"><?= ax_e(ax_t('EINST.H_HUE_LAMPEN')) ?></h3>
<input data-role="none" type="hidden" name="hue_lampen_form" value="1">
<div class="sm-small"><?= ax_e(ax_t('EINST.HUE_LAMPEN_HILFE')) ?></div>
<label class="sm-haken"><input data-role="none" type="checkbox" name="hue_probe_lampe" value="1"<?= ax_ui_h('hue_probe_lampe', !empty($ax_cfg['hue_probe_lampe'])) ? ' checked' : '' ?><?= ax_ui_m('hue_probe_lampe') ?>> <?= ax_e(ax_t('EINST.L_HUE_PROBE_LAMPE')) ?></label>
<div class="sm-small"><?= ax_e(ax_t('EINST.HUE_PROBE_LAMPE_HILFE')) ?></div>
<?php
// Fassung 2 (alexa6): je Lampe eine Zeile, dazu drei leere zum Ergaenzen. Nach einer Beanstandung kommen
// genau die eingegebenen Zeilen zurueck (X-2), ein vorgeschlagenes Kuerzel steht dann im Feld.
$ax_hl_e = ax_ui_eingaben();
$ax_hl_akt = $ax_hl_e !== null && ax_ui_aktiv('hue_l_name');
$ax_hl_n = count($ax_cfg['hue_lampen']) + 3;
if ($ax_hl_akt) {
    foreach (array('hue_l_id', 'hue_l_name', 'hue_l_art', 'hue_l_kuerzel', 'hue_l_frei') as $ax_f) {
        if (isset($ax_hl_e['werte'][$ax_f]) && is_array($ax_hl_e['werte'][$ax_f])) {
            foreach (array_keys($ax_hl_e['werte'][$ax_f]) as $ax_k) { $ax_hl_n = max($ax_hl_n, (int) $ax_k + 2); }
        }
    }
}
$ax_hl_n = min($ax_hl_n, AX_HUE_LAMPEN_MAX + 3);
?>
<div class="sm-breit">
<table class="sm-tbl" id="hue_lampen_tabelle">
<tr><th style="width:5%">#</th><th style="width:33%"><?= ax_e(ax_t('EINST.T_HUE_NAME')) ?></th><th style="width:17%"><?= ax_e(ax_t('EINST.T_HUE_ART')) ?></th><th style="width:25%"><?= ax_e(ax_t('EINST.T_HUE_KUERZEL')) ?></th><th style="width:20%"><?= ax_e(ax_t('EINST.T_HUE_FREI')) ?></th></tr>
<?php for ($ax_i = 0; $ax_i < $ax_hl_n; $ax_i++) {
    $ax_l = isset($ax_cfg['hue_lampen'][$ax_i]) ? $ax_cfg['hue_lampen'][$ax_i] : null;
    $ax_la = ax_ui_w('hue_l_art', $ax_l ? $ax_l['art'] : 'schalter', $ax_i);
    $ax_lf = $ax_hl_akt ? isset($ax_hl_e['werte']['hue_l_frei'][(string) $ax_i]) : ($ax_l && !empty($ax_l['frei'])); ?>
<tr><td><?= $ax_i + 1 ?><input data-role="none" type="hidden" name="hue_l_id[<?= $ax_i ?>]" value="<?= ax_e(ax_ui_w('hue_l_id', $ax_l ? $ax_l['id'] : '', $ax_i)) ?>"></td>
<td><input data-role="none" type="text" name="hue_l_name[<?= $ax_i ?>]" aria-label="<?= ax_e(ax_t('EINST.T_HUE_NAME')) ?> <?= $ax_i + 1 ?>" value="<?= ax_e(ax_ui_w('hue_l_name', $ax_l ? $ax_l['name'] : '', $ax_i)) ?>"<?= ax_ui_m('hue_l_name', $ax_i) ?>></td>
<td><select data-role="none" name="hue_l_art[<?= $ax_i ?>]" aria-label="<?= ax_e(ax_t('EINST.T_HUE_ART')) ?> <?= $ax_i + 1 ?>"<?= ax_ui_m('hue_l_art', $ax_i) ?>>
<option value="schalter"<?= $ax_la !== 'dimmer' && $ax_la !== 'licht' ? ' selected' : '' ?>><?= ax_e(ax_t('EINST.O_HUE_SCHALTER')) ?></option>
<option value="licht"<?= $ax_la === 'licht' ? ' selected' : '' ?>><?= ax_e(ax_t('EINST.O_HUE_LICHT')) ?></option>
<option value="dimmer"<?= $ax_la === 'dimmer' ? ' selected' : '' ?>><?= ax_e(ax_t('EINST.O_HUE_DIMMER')) ?></option>
</select></td>
<td><input data-role="none" type="text" name="hue_l_kuerzel[<?= $ax_i ?>]" aria-label="<?= ax_e(ax_t('EINST.T_HUE_KUERZEL')) ?> <?= $ax_i + 1 ?>" placeholder="<?= ax_e(ax_t('EINST.P_HUE_KUERZEL')) ?>" value="<?= ax_e(ax_ui_w('hue_l_kuerzel', $ax_l ? $ax_l['kuerzel'] : '', $ax_i)) ?>"<?= ax_ui_m('hue_l_kuerzel', $ax_i) ?>></td>
<td><label class="sm-haken"><input data-role="none" type="checkbox" name="hue_l_frei[<?= $ax_i ?>]" value="1"<?= $ax_lf ? ' checked' : '' ?><?= ax_ui_m('hue_l_frei', $ax_i) ?>> <?= ax_e(ax_t('EINST.L_HUE_FREI')) ?></label></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= ax_e(ax_t('EINST.HUE_LAMPEN_ZEILEN_HILFE')) ?></div>
<label for="hue_echos"><?= ax_e(ax_t('FELD.HUE_ECHOS')) ?></label>
<input data-role="none" type="text" id="hue_echos" name="hue_echos" placeholder="<?= ax_e(ax_t('EINST.P_HUE_ECHOS')) ?>" value="<?= ax_e(ax_ui_w('hue_echos', implode(', ', $ax_cfg['hue_echos']))) ?>"<?= ax_ui_m('hue_echos') ?>>
<div class="sm-small"><?= ax_e(ax_t('EINST.HUE_ECHOS_HILFE')) ?></div>
<div class="sm-small"><a href="index.php?form=test#hue_probe"><?= ax_e(ax_t('EINST.ZU_HUE_TEST')) ?></a></div>
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?= ax_e(ax_t('SEITE.K_SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ax_e(ax_t('EINST.H_TOKEN')) ?></h2>
<div class="sm-small"><?= ax_e(ax_t('EINST.TOKEN_HILFE')) ?></div>
<table class="sm-tbl">
<tr><th style="width:25%"><?= ax_e(ax_t('EINST.T_TOKEN')) ?></th><th style="width:75%"><?= ax_e(ax_t('EINST.T_BEISPIEL')) ?></th></tr>
<tr><td><?= ax_e(ax_t('EINST.SPRECHTOKEN')) ?></td><td><span class="sm-mono"><?= ax_e($ax_basis . '?aktion=sprechen&token=' . $ax_sprech . '&geraet=' . $ax_std . '&text=Hallo') ?></span></td></tr>
<tr><td><?= ax_e(ax_t('EINST.AKTIONSTOKEN')) ?></td><td><span class="sm-mono"><?= ax_e($ax_basis . '?aktion=routine&token=' . $ax_aktion . '&name=Gute%20Nacht&geraet=' . $ax_std) ?></span></td></tr>
</table>
<?php if ($ax_sprech !== '' && $ax_sprech === $ax_aktion) { ?><div class="sm-alert sm-err"><?= ax_e(ax_t('EINST.TOKEN_GLEICH')) ?></div><?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<label class="sm-haken"><input data-role="none" type="checkbox" name="token_neu_ja" value="1"> <?= ax_e(ax_t('EINST.L_TOKEN_JA')) ?></label>
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="sprech"><?= ax_e(ax_t('EINST.K_SPRECH_NEU')) ?></button>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="aktion"><?= ax_e(ax_t('EINST.K_AKTION_NEU')) ?></button>
</div>
<div class="sm-warnung"><?= ax_e(ax_t('EINST.TOKEN_NEU_WARNUNG')) ?></div>
</form>

<h2><?= ax_e(ax_t('SICH.H')) ?></h2>
<div class="sm-warnung"><?= ax_e(ax_t('SICH.WARNUNG')) ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<label class="sm-haken"><input data-role="none" type="checkbox" name="sich_amazon" value="1"> <?= ax_e(ax_t('SICH.L_AMAZON')) ?></label>
<?php if ($ax_lage['abgewiesen']) { ?><div class="sm-alert sm-warn"><?= ax_e(sprintf(ax_t('SICH.X3_WARNUNG'), implode(', ', array_keys($ax_lage['abgewiesen'])))) ?></div><?php } ?>
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ax_sichern" value="1"><?= ax_e(ax_t('SICH.K_SICHERN')) ?></button>
</div>
</form>
<form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<input data-role="none" type="file" name="ax_sicherung" accept=".json,application/json">
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ax_zurueck" value="1"><?= ax_e(ax_t('SICH.K_ZURUECK')) ?></button>
</div>
<div class="sm-small"><?= ax_e(ax_t('SICH.HILFE')) ?></div>
</form>
</div>

<!-- ================= Reiter: Amazon-Anmeldung ================= -->
<div class="sm-seite<?= $ax_tab === 'tab-amazon' ? ' sm-active' : '' ?>" id="tab-amazon">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= ax_e(ax_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ax_e(ax_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= ax_e(ax_t('AMZ.H_ZUSTAND')) ?></h2>
<table class="sm-tbl">
<tr><th style="width:40%"><?= ax_e(ax_t('AMZ.T_FRAGE')) ?></th><th style="width:60%"><?= ax_e(ax_t('AMZ.T_ANTWORT')) ?></th></tr>
<tr><td><?= ax_e(ax_t('AMZ.F_HINTERLEGT')) ?></td><td><?= $ax_amz['form'] ? ax_e(sprintf(ax_t('AMZ.A_HINTERLEGT'), $ax_amz['laenge'], $ax_amz['weg'] === 'b' ? ax_t('AMZ.WEG_B') : ax_t('AMZ.WEG_A'))) : ax_e(ax_t('ALLG.NEIN')) ?></td></tr>
<tr><td><?= ax_e(ax_t('AMZ.F_ANGEMELDET')) ?></td><td><?= ax_e($ax_zeit($ax_amz['angemeldet_am'])) ?></td></tr>
<tr><td><?= ax_e(ax_t('AMZ.F_BESTAETIGT')) ?></td><td><?= ax_e($ax_zeit(isset($ax_bef['bestaetigt']) ? $ax_bef['bestaetigt'] : 0)) ?><?= $ax_bef['befund'] !== '' && $ax_bef['befund'] !== 'OK' ? ' – <span class="sm-aus">' . ax_e(ax_grund_text($ax_bef['befund'] === 'ABGELAUFEN' ? 'ANMELDUNG_ABGELAUFEN' : $ax_bef['befund'])) . '</span>' : '' ?></td></tr>
<tr><td><?= ax_e(ax_t('AMZ.F_COOKIES')) ?></td><td><?= ax_e($ax_zeit($ax_sitz ? $ax_sitz['getauscht_am'] : 0)) ?></td></tr>
<tr><td><?= ax_e(ax_t('AMZ.F_NAME')) ?></td><td><?= ax_e($ax_amz['weg'] === 'b' ? AX_GERAET_NAME : ax_t('AMZ.NAME_A')) ?></td></tr>
</table>
<div class="sm-small"><?= ax_e(ax_t('AMZ.KEINE_LAUFZEIT')) ?></div>

<h2><?= ax_e(ax_t('AMZ.H_WEG_B')) ?></h2>
<div class="sm-step"><?= ax_e(ax_t('AMZ.WEG_B_TEXT')) ?></div>
<div class="sm-hinweis"><?= ax_e(ax_t('AMZ.WEG_B_UNGEMESSEN')) ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-amazon">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="pkce_start" value="1"><?= ax_e(ax_t('AMZ.K_PKCE_START')) ?></button>
<?php if ($ax_pkce && preg_match('#^(https://www\.amazon\.de/ap/signin\?|http://127\.0\.0\.1:[0-9]+/www\.amazon\.de/ap/signin\?)#', $ax_pkce['adresse'])) { ?>
    <a class="sm-btn sm-b-technik" href="<?= ax_e($ax_pkce['adresse']) ?>" target="_blank" rel="noopener noreferrer"><?= ax_e(ax_t('AMZ.K_SEITE_OEFFNEN')) ?></a>
<?php } ?>
</div>
<?php if ($ax_pkce) { ?><div class="sm-small"><?= ax_e(sprintf(ax_t('AMZ.PKCE_OFFEN'), ax_spanne_text(1800 - ax_alter($ax_pkce['seit'])))) ?></div><?php } ?>
</form>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-amazon">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<label for="pkce_code"><?= ax_e(ax_t('AMZ.L_CODE')) ?></label>
<input data-role="none" type="text" id="pkce_code" name="pkce_code" value="" autocomplete="off" placeholder="https://www.amazon.de/ap/maplanding?...openid.oa2.authorization_code=...">
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="pkce_einloesen" value="1"><?= ax_e(ax_t('AMZ.K_EINLOESEN')) ?></button>
</div>
<div class="sm-small"><?= ax_e(ax_t('AMZ.CODE_HILFE')) ?></div>
</form>

<h2><?= ax_e(ax_t('AMZ.H_WEG_A')) ?></h2>
<div class="sm-step"><?= ax_e(ax_t('AMZ.WEG_A_TEXT')) ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-amazon">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<label for="refresh_token"><?= ax_e(ax_t('AMZ.L_TOKEN')) ?></label>
<input data-role="none" type="password" id="refresh_token" name="refresh_token" value="" autocomplete="off" placeholder="<?= ax_e($ax_amz['form'] ? sprintf(ax_t('AMZ.P_HINTERLEGT'), $ax_amz['laenge']) : 'Atnr|...') ?>">
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_einfuegen" value="1"><?= ax_e(ax_t('AMZ.K_TOKEN')) ?></button>
</div>
<div class="sm-small"><?= ax_e(ax_t('AMZ.TOKEN_HILFE')) ?></div>
</form>

<h2><?= ax_e(ax_t('AMZ.H_ABMELDEN')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-amazon">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<label class="sm-haken"><input data-role="none" type="checkbox" name="abmelden_ja" value="1"> <?= ax_e(ax_t('AMZ.L_ABMELDEN_JA')) ?></label>
<label class="sm-haken"><input data-role="none" type="checkbox" name="nur_oertlich" value="1"> <?= ax_e(ax_t('AMZ.L_NUR_OERTLICH')) ?></label>
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="abmelden" value="1"><?= ax_e(ax_t('AMZ.K_ABMELDEN')) ?></button>
</div>
<div class="sm-small"><?= ax_e(ax_t('AMZ.ABMELDEN_HILFE')) ?></div>
</form>
</div>

<!-- ================= Reiter: Geraete ================= -->
<div class="sm-seite<?= $ax_tab === 'tab-geraete' ? ' sm-active' : '' ?>" id="tab-geraete">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ax_e(ax_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ax_e(ax_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= ax_e(ax_t('GER.H')) ?></h2>
<?php if ($ax_st) { ?>
<div class="sm-small"><?= ax_e(sprintf(ax_t('GER.ZAEHLER'), (int) $ax_st['konto_gesamt'], count($ax_st['liste']), $ax_zeit($ax_st['stand']))) ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-geraete">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ax_e(ax_t('GER.T_ANZEIGE')) ?></th><th><?= ax_e(ax_t('GER.T_NORMAL')) ?></th><th><?= ax_e(ax_t('GER.T_FAMILIE')) ?></th><th><?= ax_e(ax_t('GER.T_ONLINE')) ?></th><th><?= ax_e(ax_t('GER.T_LAUT')) ?></th><th><?= ax_e(ax_t('GER.T_GRUPPEN')) ?></th><th><?= ax_e(ax_t('GER.T_SCHALTEN')) ?></th></tr>
<?php foreach ($ax_st['liste'] as $ax_g) {
    $ax_in = array();
    foreach ($ax_cfg['gruppen'] as $ax_gr) { if (in_array($ax_g['normal'], explode(',', $ax_gr['geraete']), true)) { $ax_in[] = $ax_gr['name']; } } ?>
<tr><td><?= ax_e($ax_g['anzeige']) ?></td><td><span class="sm-mono"><?= ax_e($ax_g['normal']) ?></span></td><td><?= ax_e($ax_g['familie']) ?></td>
    <td><?= !empty($ax_g['online']) ? '<span class="sm-an">' . ax_e(ax_t('ALLG.JA')) . '</span>' : '<span class="sm-aus">' . ax_e(ax_t('ALLG.NEIN')) . '</span>' ?></td>
    <td><?= (int) $ax_g['laut'] >= 0 ? (int) $ax_g['laut'] . ' %' : '–' ?></td><td><?= ax_e($ax_in ? implode(', ', $ax_in) : '–') ?></td>
    <td><?php if ($ax_g['familie'] !== 'WHA') { ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="testansage" value="<?= ax_e($ax_g['normal']) ?>"><?= ax_e(ax_t('GER.K_TEST')) ?></button><?php } else { echo ax_e(ax_t('GER.WHA')); } ?></td></tr>
<?php } ?>
<?php foreach ($ax_st['verschwunden'] as $ax_g) { ?>
<tr><td><?= ax_e($ax_g['anzeige'] !== '' ? $ax_g['anzeige'] : '–') ?></td><td><span class="sm-mono"><?= ax_e($ax_g['normal']) ?></span></td><td><?= ax_e($ax_g['familie'] !== '' ? $ax_g['familie'] : '–') ?></td>
    <td><span class="sm-aus"><?= ax_e(sprintf(ax_t('GER.VERSCHWUNDEN_SEIT'), $ax_zeit($ax_g['seit']))) ?></span></td><td>–</td><td>–</td>
    <td><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="austragen" value="<?= ax_e($ax_g['normal']) ?>"><?= ax_e(ax_t('GER.K_AUSTRAGEN')) ?></button></td></tr>
<?php } ?>
</table>
</div>
<?php if ($ax_st['verschwunden']) { ?>
<label class="sm-haken"><input data-role="none" type="checkbox" name="austragen_ja" value="1"<?= $ax_aus_falsch ? ' class="sm-beanstandet" aria-invalid="true"' : '' ?>> <?= ax_e(ax_t('GER.L_AUSTRAGEN_JA')) ?></label>
<div class="sm-small"><?= ax_e(ax_t('GER.AUSTRAGEN_HILFE')) ?></div>
<?php } ?>
</form>
<?php foreach ($ax_st['verschwunden'] as $ax_g) { list($ax_orte, $ax_nicht) = ax_name_verwendung($ax_g['normal'], $ax_cfg); ?>
<div class="sm-warnung"><?= ax_e(sprintf(ax_t('GER.VERSCHWUNDEN_HINWEIS'), $ax_g['normal'], $ax_zeit($ax_g['seit']),
    $ax_orte ? implode(', ', $ax_orte) : ax_t('GER.ORT_KEINER')) . ($ax_nicht ? ' ' . sprintf(ax_t('GER.ORT_NICHT_LESBAR'), implode(', ', $ax_nicht)) : '')) ?></div>
<?php } ?>
<?php } else { ?>
<div class="sm-hinweis"><?= ax_e(ax_t('GER.KEINE')) ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-geraete">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="geraete_holen" value="1"><?= ax_e(ax_t('GER.K_HOLEN')) ?></button>
</div>
</form>
<div class="sm-small"><?= ax_e(ax_t('GER.HILFE')) ?></div>
<div class="sm-hinweis"><?= ax_e(ax_t('EINST.NAMEN_HILFE')) ?></div>

<h2 id="routinen_amazon"><?= ax_e(ax_t('GER.H_ROUTINEN')) ?></h2>
<div class="sm-small"><?= ax_e(ax_t('GER.ROUTINEN_HILFE')) ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-geraete">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="routinen_anzeigen" value="1"><?= ax_e(ax_t('GER.K_ROUTINEN')) ?></button>
</div>
</form>
<?php if (is_array($ax_rt_anz) && isset($ax_rt_anz['liste']) && is_array($ax_rt_anz['liste'])) {
    $ax_frei_klein = array_map(function ($r) { return strtolower(trim($r)); }, $ax_cfg['routinen_frei']); ?>
<div class="sm-small"><?= ax_e(sprintf(ax_t('GER.ROUTINEN_STAND'), count($ax_rt_anz['liste']), $ax_zeit(isset($ax_rt_anz['stand']) ? $ax_rt_anz['stand'] : 0))) ?></div>
<?php if ($ax_rt_anz['liste']) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:40%"><?= ax_e(ax_t('GER.T_ROUTINE')) ?></th><th style="width:45%"><?= ax_e(ax_t('GER.T_AUSLOESER')) ?></th><th style="width:15%"><?= ax_e(ax_t('GER.T_FREI')) ?></th></tr>
<?php foreach ($ax_rt_anz['liste'] as $ax_r) {
    if (!is_array($ax_r) || !isset($ax_r['name']) || !is_string($ax_r['name'])) { continue; }
    $ax_aus = (isset($ax_r['ausloeser']) && is_array($ax_r['ausloeser'])) ? array_filter($ax_r['ausloeser'], 'is_string') : array();
    $ax_ist_frei = in_array(strtolower(trim($ax_r['name'])), $ax_frei_klein, true);
    foreach ($ax_aus as $ax_u) { if (in_array(strtolower(trim($ax_u)), $ax_frei_klein, true)) { $ax_ist_frei = true; } } ?>
<tr><td><?= ax_e($ax_r['name'] !== '' ? $ax_r['name'] : '–') ?></td><td><?= ax_e($ax_aus ? implode(' · ', $ax_aus) : '–') ?></td><td><?= $ax_ist_frei ? '<span class="sm-an">' . ax_e(ax_t('ALLG.JA')) . '</span>' : ax_e(ax_t('ALLG.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<div class="sm-hinweis"><?= ax_e(ax_t('GER.ROUTINEN_KEINE')) ?></div>
<?php } ?>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $ax_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ax_e(ax_t('LEGENDE.AKTION')) ?></span>
</div>
<?php if ($ax_gw['gefunden'] && !$ax_gw['autostart']) { ?><div class="sm-warnung"><?= ax_e(ax_t('MQTT.AUTOSTART_WARN')) ?></div><?php } ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<label class="sm-haken"><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= ax_ui_h('mqtt_ein', !empty($ax_cfg['mqtt_ein'])) ? ' checked' : '' ?><?= ax_ui_m('mqtt_ein') ?>> <?= ax_e(ax_t('MQTT.L_EIN')) ?></label>
<label for="mqtt_praefix"><?= ax_e(ax_t('FELD.MQTT_PRAEFIX')) ?></label>
<input data-role="none" type="text" id="mqtt_praefix" name="mqtt_praefix" value="<?= ax_e(ax_ui_w('mqtt_praefix', $ax_cfg['mqtt_praefix'])) ?>"<?= ax_ui_m('mqtt_praefix') ?>>
<div class="sm-small"><?= ax_e(ax_t('MQTT.PRAEFIX_HILFE')) ?></div>
<label class="sm-haken"><input data-role="none" type="checkbox" name="befehle_mqtt_ein" value="1"<?= ax_ui_h('befehle_mqtt_ein', !empty($ax_cfg['befehle_mqtt_ein'])) ? ' checked' : '' ?><?= ax_ui_m('befehle_mqtt_ein') ?>> <?= ax_e(ax_t('MQTT.L_BEFEHLE')) ?></label>
<div class="sm-small"><?= ax_e(ax_t('MQTT.BEFEHLE_HILFE')) ?></div>
<label class="sm-haken"><input data-role="none" type="checkbox" name="befehle_routine_ein" value="1"<?= ax_ui_h('befehle_routine_ein', !empty($ax_cfg['befehle_routine_ein'])) ? ' checked' : '' ?><?= ax_ui_m('befehle_routine_ein') ?>> <?= ax_e(ax_t('MQTT.L_ROUTINE')) ?></label>
<div class="sm-small"><?= ax_e(ax_t('MQTT.ROUTINE_HILFE')) ?></div>
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_mqtt" value="1"><?= ax_e(ax_t('SEITE.K_SPEICHERN')) ?></button>
</div>
</form>
<h2><?= ax_e(ax_t('MQTT.H_ABO')) ?></h2>
<?php list(, $ax_abo_da) = ax_abo_datei($ax_cfg['mqtt_praefix']); ?>
<div class="sm-hinweis">
<?php if ($ax_gw['fassung'] >= 2) { echo ax_e(ax_t('MQTT.ABO_V2')); }
      elseif ($ax_gw['fassung'] === 1) { echo ax_e(sprintf(ax_t($ax_abo_da ? 'MQTT.ABO_V1_DA' : 'MQTT.ABO_V1'), $ax_cfg['mqtt_praefix'] . '/#')); }
      else { echo ax_e(sprintf(ax_t('MQTT.ABO_UNBEKANNT'), $ax_cfg['mqtt_praefix'] . '/#')); } ?>
</div>
<h2><?= ax_e(ax_t('MQTT.H_THEMEN')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:38%"><?= ax_e(ax_t('MQTT.T_THEMA')) ?></th><th style="width:47%"><?= ax_e(ax_t('MQTT.T_BEDEUTUNG')) ?></th><th style="width:15%"><?= ax_e(ax_t('MQTT.T_RETAINED')) ?></th></tr>
<?php foreach (ax_mqtt_themen() as $ax_th => $ax_d) { ?>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/' . $ax_th) ?></span></td><td><?= ax_e(ax_t($ax_d[1])) ?></td><td><?= $ax_d[0] ? ax_e(ax_t('ALLG.JA')) : ax_e(ax_t('ALLG.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= ax_e(ax_t('MQTT.THEMEN_HILFE')) ?></div>
<h2><?= ax_e(ax_t('MQTT.H_BEFEHLE')) ?></h2>
<table class="sm-tbl">
<tr><th style="width:50%"><?= ax_e(ax_t('MQTT.T_THEMA')) ?></th><th style="width:50%"><?= ax_e(ax_t('MQTT.T_NUTZLAST')) ?></th></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/<name>/sprechen') ?></span></td><td><?= ax_e(ax_t('MQTT.N_TEXT')) ?></td></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/<name>/lautstaerke') ?></span></td><td>0–100</td></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/<name>/ankuendigen') ?></span></td><td><?= ax_e(ax_t('MQTT.N_TEXT')) ?></td></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/gruppe/<gruppe>/sprechen') ?></span></td><td><?= ax_e(ax_t('MQTT.N_TEXT')) ?></td></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/routine') ?></span></td><td><?= ax_e(ax_t('MQTT.N_ROUTINE')) ?></td></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/sperre') ?></span></td><td><?= ax_e(ax_t('MQTT.N_SPERRE')) ?></td></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/radio/<zone>') ?></span></td><td><?= ax_e(ax_t('MQTT.N_RADIO')) ?></td></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/radio/<zone>/laut') ?></span></td><td><?= ax_e(ax_t('MQTT.N_RADIO_LAUT')) ?></td></tr>
</table>
<div class="sm-small"><?= ax_e(ax_t('MQTT.BEFEHLE_RETAIN')) ?></div>
<h2 id="hue_rueck"><?= ax_e(ax_t('MQTT.H_HUE_RUECK')) ?></h2>
<table class="sm-tbl">
<tr><th style="width:50%"><?= ax_e(ax_t('MQTT.T_THEMA')) ?></th><th style="width:50%"><?= ax_e(ax_t('MQTT.T_NUTZLAST')) ?></th></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/hue/<kuerzel>/status') ?></span></td><td><?= ax_e(ax_t('MQTT.N_HUE_STATUS')) ?></td></tr>
<tr><td><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/hue/<kuerzel>/status_helligkeit') ?></span></td><td><?= ax_e(ax_t('MQTT.N_HUE_HELL')) ?></td></tr>
</table>
<div class="sm-small"><?= ax_e(ax_t('MQTT.HUE_RUECK_HILFE')) ?></div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $ax_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= ax_e(ax_t('LEGENDE.TECHNIK')) ?></span>
</div>
<div class="sm-step"><b>1.</b> <?= ax_e(ax_t('LOX.S1')) ?></div>
<div class="sm-step"><b>2.</b> <?= ax_e(ax_t('LOX.S2')) ?><br><span class="sm-mono"><?= ax_e($ax_basis . '?aktion=status') ?></span>
<table class="sm-tbl">
<tr><th style="width:22%"><?= ax_e(ax_t('LOX.T_FELD')) ?></th><th style="width:30%"><?= ax_e(ax_t('LOX.T_SUCHTEXT')) ?></th><th style="width:48%"><?= ax_e(ax_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ax_status_felder() as $ax_f => $ax_d) { ?>
<tr><td><?= ax_e($ax_f) ?></td><td><span class="sm-mono"><?= ax_e(ax_check($ax_f)) ?></span></td><td><?= ax_e(ax_t('KACHEL.' . $ax_f)) ?></td></tr>
<?php } ?>
</table></div>
<div class="sm-step"><b>3.</b> <?= ax_e(ax_t('LOX.S3')) ?><br>
<span class="sm-mono"><?= ax_e(preg_replace('#/plugins/.*$#', '', $ax_basis)) ?></span><br>
<span class="sm-mono"><?= ax_e('/plugins/' . $ax_p['plugin'] . '/?token=' . $ax_sprech . '&aktion=sprechen&geraet=' . $ax_std . '&text=<v>') ?></span><br>
<span class="sm-mono"><?= ax_e('/plugins/' . $ax_p['plugin'] . '/?token=' . $ax_sprech . '&aktion=lautstaerke&geraet=' . $ax_std . '&wert=<v>') ?></span></div>
<div class="sm-step"><b>4.</b> <?= ax_e(ax_t('LOX.S4')) ?></div>
<div class="sm-step"><b>5.</b> <?= ax_e(ax_t('LOX.S5')) ?></div>
<div class="sm-step"><b>6.</b> <?= ax_e(ax_t('LOX.S6')) ?>
<table class="sm-tbl">
<tr><th style="width:5%">#</th><th style="width:25%"><?= ax_e(ax_t('LOX.T_BAUSTEIN')) ?></th><th style="width:20%"><?= ax_e(ax_t('LOX.T_NAME')) ?></th><th style="width:25%"><?= ax_e(ax_t('LOX.T_PARAMETER')) ?></th><th style="width:25%"><?= ax_e(ax_t('LOX.T_EINGAENGE')) ?></th></tr>
<tr><td>1</td><td><?= ax_e(ax_t('LOX.B1_TYP')) ?></td><td>Alexa NG</td><td><?= ax_e(ax_t('LOX.B1_PAR')) ?></td><td>–</td></tr>
<tr><td>2</td><td><?= ax_e(ax_t('LOX.B2_TYP')) ?></td><td>Alexa NG OK</td><td><span class="sm-mono"><?= ax_e(ax_check('OK')) ?></span></td><td>–</td></tr>
<tr><td>3</td><td><?= ax_e(ax_t('LOX.B3_TYP')) ?></td><td>Alexa NG Ansagen</td><td><?= ax_e(ax_t('LOX.B3_PAR')) ?></td><td>–</td></tr>
<tr><td>4</td><td><?= ax_e(ax_t('LOX.B4_TYP')) ?></td><td><?= ax_e('Alexa ' . $ax_std . ' sprechen') ?></td><td><?= ax_e(ax_t('LOX.B4_PAR')) ?></td><td><?= ax_e(ax_t('LOX.B4_EIN')) ?></td></tr>
<tr><td>5</td><td><?= ax_e(ax_t('LOX.B5_TYP')) ?></td><td><?= ax_e(ax_t('LOX.B5_NAME')) ?></td><td><?= ax_e(ax_t('LOX.B5_PAR')) ?></td><td><?= ax_e(ax_t('LOX.B5_EIN')) ?></td></tr>
<?php $ax_rz1 = $ax_cfg['radio_zonen'] ? (string) (int) $ax_cfg['radio_zonen'][0]['zone'] : '1'; ?>
<tr><td>6</td><td><?= ax_e(ax_t('LOX.B3_TYP')) ?></td><td>Alexa NG Radio</td><td><?= ax_e(ax_t('LOX.B6_PAR')) ?></td><td>–</td></tr>
<tr><td>7</td><td><?= ax_e(ax_t('LOX.B4_TYP')) ?></td><td><?= ax_e('Alexa Radio Zone ' . $ax_rz1 . ' Sender') ?></td><td><?= ax_e(ax_t('LOX.B7_PAR')) ?></td><td><?= ax_e(ax_t('LOX.B7_EIN')) ?></td></tr>
<tr><td>8</td><td><?= ax_e(ax_t('LOX.B4_TYP')) ?></td><td><?= ax_e('Alexa Radio Zone ' . $ax_rz1 . ' Stopp') ?></td><td><?= ax_e(ax_t('LOX.B8_PAR')) ?></td><td><?= ax_e(ax_t('LOX.B8_EIN')) ?></td></tr>
<tr><td>9</td><td><?= ax_e(ax_t('LOX.B4_TYP')) ?></td><td><?= ax_e('Alexa Radio Zone ' . $ax_rz1 . ' Lautstärke') ?></td><td><?= ax_e(ax_t('LOX.B9_PAR')) ?></td><td><?= ax_e(ax_t('LOX.B9_EIN')) ?></td></tr>
<tr><td>10</td><td><?= ax_e(ax_t('LOX.B4_TYP')) ?></td><td>Alexa Radio alle Zonen Sender</td><td><?= ax_e(ax_t('LOX.B10_PAR')) ?></td><td><?= ax_e(ax_t('LOX.B10_EIN')) ?></td></tr>
<tr><td>11</td><td><?= ax_e(ax_t('LOX.B11_TYP')) ?></td><td><?= ax_e(sprintf(ax_t('LOX.B11_NAME'), $ax_rz1)) ?></td><td><?= ax_e(ax_t('LOX.B11_PAR')) ?></td><td><?= ax_e(ax_t('LOX.B11_EIN')) ?></td></tr>
<?php
// Fassung 2 (alexa6): Baustein-Liste fuer eine Lampe (die erste der Liste, sonst Platzhalter). Logik in
// Grossbuchstaben wie in Loxone Config (Nr. 39).
$ax_hl1 = $ax_cfg['hue_lampen'] ? $ax_cfg['hue_lampen'][0] : array('name' => '<Name>', 'kuerzel' => '<kuerzel>', 'art' => 'dimmer');
$ax_hlt = $ax_cfg['mqtt_praefix'] . '/hue/' . $ax_hl1['kuerzel'] . '/';
$ax_hudp = ax_mqtt_udpport();
?>
<tr><td>12</td><td><?= ax_e(ax_t('LOX.B12_TYP')) ?></td><td><?= ax_e('Alexa ' . $ax_hl1['name'] . ' ein') ?></td><td><?= ax_e(sprintf(ax_t('LOX.B12_PAR'), ax_hue_gateway_name($ax_cfg, $ax_hl1['kuerzel'], 'ein'))) ?></td><td>–</td></tr>
<tr><td>13</td><td>NICHT</td><td><?= ax_e('Alexa ' . $ax_hl1['name'] . ' aus') ?></td><td>–</td><td><?= ax_e(ax_t('LOX.B13_EIN')) ?></td></tr>
<tr><td>14</td><td><?= ax_e(ax_t('LOX.B14_TYP')) ?></td><td><?= ax_e(ax_t('LOX.B14_NAME')) ?></td><td><?= ax_e(ax_t('LOX.B14_PAR')) ?></td><td><?= ax_e(ax_t('LOX.B14_EIN')) ?></td></tr>
<tr><td>15</td><td><?= ax_e(ax_t('LOX.B12_TYP')) ?></td><td><?= ax_e('Alexa ' . $ax_hl1['name'] . ' Helligkeit') ?></td><td><?= ax_e(sprintf(ax_t('LOX.B15_PAR'), ax_hue_gateway_name($ax_cfg, $ax_hl1['kuerzel'], 'helligkeit'))) ?></td><td>–</td></tr>
<tr><td>16</td><td><?= ax_e(ax_t('LOX.B3_TYP')) ?></td><td>Alexa NG Lampen-Rückmeldung</td><td><?= ax_e(sprintf(ax_t('LOX.B16_PAR'), '/dev/udp/' . ax_hue_udp_host($ax_host) . '/' . ($ax_hudp > 0 ? $ax_hudp : '?'))) ?></td><td>–</td></tr>
<tr><td>17</td><td><?= ax_e(ax_t('LOX.B4_TYP')) ?></td><td><?= ax_e('Alexa ' . $ax_hl1['name'] . ' Rückmeldung') ?></td><td><?= ax_e(sprintf(ax_t('LOX.B17_PAR'), 'publish ' . $ax_hlt . 'status 1', 'publish ' . $ax_hlt . 'status 0')) ?></td><td><?= ax_e(ax_t('LOX.B17_EIN')) ?></td></tr>
<tr><td>18</td><td><?= ax_e(ax_t('LOX.B4_TYP')) ?></td><td><?= ax_e('Alexa ' . $ax_hl1['name'] . ' Rückmeldung Helligkeit') ?></td><td><?= ax_e(sprintf(ax_t('LOX.B18_PAR'), 'publish ' . $ax_hlt . 'status_helligkeit <v>')) ?></td><td><?= ax_e(ax_t('LOX.B18_EIN')) ?></td></tr>
</table>
<div class="sm-small"><?= ax_e(ax_t('LOX.B_HINWEIS')) ?></div>
<div class="sm-small"><?= ax_e(ax_t('LOX.B_HUE_HINWEIS')) ?></div>
<div class="sm-small"><?= ax_e(ax_t('LOX.B_RADIO_HINWEIS')) ?></div>
<figure class="sm-bild">
<img src="einbindung_loxone.png" alt="<?= ax_e(ax_t('LOX.BILD_ALT')) ?>" loading="lazy">
<figcaption><?= ax_e(ax_t('LOX.BILD_UNTERSCHRIFT')) ?></figcaption>
</figure>
<div class="sm-small"><?= ax_e(ax_t('LOX.MUSTERPROJEKT')) ?> <a href="https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt" target="_blank" rel="noopener">LoxBerry-Plugins Musterprojekt</a></div></div>
<div class="sm-step"><b>7.</b> <?= ax_e(ax_t('LOX.S7')) ?></div>
<div class="sm-step"><b>8.</b> <?= ax_e(ax_t('LOX.S8_SPERRE')) ?><br>
<span class="sm-mono"><?= ax_e($ax_basis . '?aktion=sperre&token=' . $ax_aktion . '&wert=1') ?></span><br>
<span class="sm-mono"><?= ax_e($ax_basis . '?aktion=sperre&token=' . $ax_aktion . '&wert=0') ?></span><br>
<span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/sperre') ?></span> <?= ax_e(ax_t('MQTT.N_SPERRE')) ?></div>
<div class="sm-step"><b>9.</b> <?= ax_e(ax_t('LOX.S9_MUSIK')) ?><br>
<span class="sm-mono"><?= ax_e('/plugins/' . $ax_p['plugin'] . '/?token=' . $ax_aktion . '&aktion=musik_probe&geraet=' . $ax_std . '&nr=<v>') ?></span><br>
<span class="sm-mono"><?= ax_e('/plugins/' . $ax_p['plugin'] . '/?token=' . $ax_aktion . '&aktion=musik_stopp&geraet=' . $ax_std) ?></span></div>
<div class="sm-step" id="radio_loxone"><b>10.</b> <?= ax_e(ax_t('LOX.S10_RADIO')) ?>
<?php if (empty($ax_cfg['radio_ein'])) { ?><div class="sm-hinweis"><?= ax_e(ax_t('LOX.RADIO_AUS')) ?> <a href="index.php?form=settings#radio"><?= ax_e(ax_t('TEST.ZU_RADIO')) ?></a></div><?php } ?>
<table class="sm-tbl">
<tr><th style="width:5%">#</th><th style="width:95%"><?= ax_e(ax_t('LOX.T_SCHRITT')) ?></th></tr>
<tr><td>a</td><td><?= ax_e(ax_t('LOX.RADIO_B1')) ?></td></tr>
<tr><td>b</td><td><?= ax_e(ax_t('LOX.RADIO_B2')) ?></td></tr>
<tr><td>c</td><td><?= ax_e(ax_t('LOX.RADIO_B3')) ?></td></tr>
<tr><td>d</td><td><?= ax_e(ax_t('LOX.RADIO_B4')) ?></td></tr>
<tr><td>e</td><td><?= ax_e(ax_t('LOX.RADIO_B5')) ?></td></tr>
<tr><td>f</td><td><?= ax_e(ax_t('LOX.RADIO_B6')) ?></td></tr>
</table>
<?php if ($ax_cfg['radio_zonen']) { $ax_rpfad = '/plugins/' . $ax_p['plugin'] . '/?token=' . $ax_aktion; ?>
<div class="sm-small"><?= ax_e(ax_t('LOX.RADIO_ADRESSEN')) ?> <span class="sm-mono"><?= ax_e(preg_replace('#/plugins/.*$#', '', $ax_basis)) ?></span></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:8%"><?= ax_e(ax_t('TEST.T_ZONE')) ?></th><th style="width:17%"><?= ax_e(ax_t('TEST.T_ZIEL')) ?></th><th style="width:75%"><?= ax_e(ax_t('LOX.T_RADIO_BEFEHLE')) ?></th></tr>
<?php foreach (array_merge($ax_cfg['radio_zonen'], array(array('zone' => 'alle', 'ziel' => ax_t('LOX.RADIO_ALLE_ZIEL')))) as $ax_z) { ?>
<tr><td><?= ax_e((string) $ax_z['zone']) ?></td><td><span class="sm-mono"><?= ax_e($ax_z['ziel']) ?></span></td><td>
<span class="sm-mono"><?= ax_e($ax_rpfad . '&aktion=radio&zone=' . $ax_z['zone'] . '&nr=<v>') ?></span><br>
<span class="sm-mono"><?= ax_e($ax_rpfad . '&aktion=radio_stopp&zone=' . $ax_z['zone']) ?></span><br>
<span class="sm-mono"><?= ax_e($ax_rpfad . '&aktion=radio_laut&zone=' . $ax_z['zone'] . '&wert=<v>') ?></span></td></tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<div class="sm-hinweis"><?= ax_e(ax_t('LOX.RADIO_KEINE_ZONE')) ?> <a href="index.php?form=settings#radio"><?= ax_e(ax_t('TEST.ZU_RADIO')) ?></a></div>
<?php } ?>
<span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/radio/<zone>') ?></span> <?= ax_e(ax_t('MQTT.N_RADIO')) ?><br>
<span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/befehl/radio/<zone>/laut') ?></span> <?= ax_e(ax_t('MQTT.N_RADIO_LAUT')) ?></div>
<div class="sm-step" id="hue_loxone"><b>11.</b> <?= ax_e(ax_t('LOX.S11_HUE')) ?>
<?php if (empty($ax_cfg['hue_ein'])) { ?><div class="sm-hinweis"><?= ax_e(ax_t('LOX.HUE_AUS')) ?> <a href="index.php?form=settings#hue_lampen"><?= ax_e(ax_t('TEST.ZU_HUE')) ?></a></div><?php } ?>
<?php if ($ax_cfg['hue_lampen']) { ?>
<div class="sm-breit">
<table class="sm-tbl" id="hue_lampen_loxone">
<tr><th style="width:22%"><?= ax_e(ax_t('TEST.T_HUE_LAMPE')) ?></th><th style="width:34%"><?= ax_e(ax_t('LOX.T_HUE_EINGAENGE')) ?></th><th style="width:44%"><?= ax_e(ax_t('LOX.T_HUE_RUECK')) ?></th></tr>
<?php foreach ($ax_cfg['hue_lampen'] as $ax_l) { $ax_hlt = $ax_cfg['mqtt_praefix'] . '/hue/' . $ax_l['kuerzel'] . '/'; ?>
<tr><td><?= ax_e($ax_l['name']) ?> (<?= ax_e(ax_t($ax_l['art'] === 'dimmer' ? 'EINST.O_HUE_DIMMER' : ($ax_l['art'] === 'licht' ? 'EINST.O_HUE_LICHT' : 'EINST.O_HUE_SCHALTER'))) ?>)</td>
<td><span class="sm-mono"><?= ax_e(ax_hue_gateway_name($ax_cfg, $ax_l['kuerzel'], 'ein')) ?></span><?php if ($ax_l['art'] === 'dimmer') { ?><br><span class="sm-mono"><?= ax_e(ax_hue_gateway_name($ax_cfg, $ax_l['kuerzel'], 'helligkeit')) ?></span><?php } ?></td>
<td><span class="sm-mono"><?= ax_e('publish ' . $ax_hlt . 'status 1') ?></span> / <span class="sm-mono">0</span><?php if ($ax_l['art'] === 'dimmer') { ?><br><span class="sm-mono"><?= ax_e('publish ' . $ax_hlt . 'status_helligkeit <v>') ?></span><?php } ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= ax_e(sprintf(ax_t('LOX.HUE_UDP'), '/dev/udp/' . ax_hue_udp_host($ax_host) . '/' . (ax_mqtt_udpport() > 0 ? ax_mqtt_udpport() : '?'))) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= ax_e(ax_t('LOX.HUE_KEINE_LAMPE')) ?> <a href="index.php?form=settings#hue_lampen"><?= ax_e(ax_t('TEST.ZU_HUE')) ?></a></div>
<?php } ?>
</div>
<h2><?= ax_e(ax_t('LOX.H_VORLAGEN')) ?></h2>
<div class="sm-small"><?= ax_e(ax_t('LOX.VORLAGEN_TEXT')) ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="vi"><?= ax_e(ax_t('LOX.K_VI')) ?></button>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="vo"><?= ax_e(ax_t('LOX.K_VO')) ?></button>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="vr"<?= $ax_cfg['radio_zonen'] ? '' : ' disabled' ?>><?= ax_e(ax_t('LOX.K_VR')) ?></button>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="vh"<?= $ax_cfg['hue_lampen'] ? '' : ' disabled' ?>><?= ax_e(ax_t('LOX.K_VH')) ?></button>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="vq"<?= ($ax_cfg['hue_lampen'] && ax_mqtt_udpport() > 0) ? '' : ' disabled' ?>><?= ax_e(ax_t('LOX.K_VQH')) ?></button>
</div>
</form>
<div class="sm-warnung"><?= ax_e(ax_t('LOX.VO_VERTRAULICH')) ?></div>
<div class="sm-warnung"><?= ax_e(ax_t('LOX.VR_VERTRAULICH')) ?></div>
<h2><?= ax_e(ax_t('LOX.H_PLUGINS')) ?></h2>
<div class="sm-small"><?= ax_e(ax_t('LOX.PLUGINS_TEXT')) ?></div>
<div class="sm-pre"><?= ax_e('http://{ip}/plugins/' . $ax_p['plugin'] . '/?aktion=sprechen&token=' . $ax_sprech . '&geraet={zones}&text={text}') ?></div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $ax_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<?php
$ax_pr = array();
$ax_zeile = function ($stand, $frage, $antwort) use (&$ax_pr) { $ax_pr[] = array($stand, $frage, $antwort); };
// Stand: 1 Haken, 0 Kreuz, -1 nicht feststellbar, 2 Hinweis (gelb), 3 ausgeschaltet (grau)
if (!$ax_amz['datei']) { $ax_zeile(2, ax_t('TEST.F_ANMELDUNG'), ax_t('TEST.A_KEINE_ANMELDUNG')); }
elseif (!$ax_amz['form']) { $ax_zeile(0, ax_t('TEST.F_ANMELDUNG'), ax_t('TEST.A_ANMELDUNG_FORM')); }
elseif ($ax_amz['rechte'] !== '' && $ax_amz['rechte'] !== '0600' && DIRECTORY_SEPARATOR !== '\\') { $ax_zeile(0, ax_t('TEST.F_ANMELDUNG'), sprintf(ax_t('TEST.A_ANMELDUNG_RECHTE'), $ax_amz['rechte'])); }
else { $ax_zeile(1, ax_t('TEST.F_ANMELDUNG'), sprintf(ax_t('TEST.A_ANMELDUNG_OK'), $ax_amz['laenge'], $ax_amz['weg'])); }
if (!$ax_amz['form']) { $ax_zeile(-1, ax_t('TEST.F_GUELTIG'), ax_t('TEST.A_GUELTIG_NIE')); }
elseif ($ax_bef['befund'] === '') { $ax_zeile(-1, ax_t('TEST.F_GUELTIG'), ax_t('TEST.A_GUELTIG_NIE')); }
elseif ($ax_bef['befund'] === 'OK') { $ax_zeile(1, ax_t('TEST.F_GUELTIG'), sprintf(ax_t('TEST.A_GUELTIG_OK'), $ax_zeit($ax_bef['zeit']))); }
elseif ($ax_bef['befund'] === 'ABGELAUFEN') { $ax_zeile(0, ax_t('TEST.F_GUELTIG'), ax_t('TEST.A_GUELTIG_ABGELAUFEN')); }
elseif ($ax_bef['befund'] === 'AMAZON_UNERWARTET') { $ax_zeile(2, ax_t('TEST.F_GUELTIG'), ax_t('TEST.A_GUELTIG_UNERWARTET')); }
else { $ax_zeile(2, ax_t('TEST.F_GUELTIG'), sprintf(ax_t('TEST.A_GUELTIG_GESTOERT'), ax_grund_text($ax_bef['befund']), $ax_zeit($ax_bef['zeit']))); }
$ax_zeile($ax_sitz ? 1 : -1, ax_t('TEST.F_COOKIES'), $ax_sitz ? sprintf(ax_t('TEST.A_COOKIES'), $ax_zeit($ax_sitz['getauscht_am'])) : ax_t('ALLG.NIE'));
if (!$ax_st) { $ax_zeile(-1, ax_t('TEST.F_GERAETE'), ax_t('TEST.A_GERAETE_NIE')); }
else {
    $ax_n = 0; $ax_on = 0;
    foreach ($ax_st['liste'] as $ax_g) { if ($ax_g['familie'] !== 'WHA') { $ax_n++; if (!empty($ax_g['online'])) { $ax_on++; } } }
    if ($ax_n === 0) { $ax_zeile(2, ax_t('TEST.F_GERAETE'), sprintf(ax_t('TEST.A_GERAETE_LEER'), (int) $ax_st['konto_gesamt'])); }
    else { $ax_zeile(1, ax_t('TEST.F_GERAETE'), sprintf(ax_t('TEST.A_GERAETE'), $ax_n, $ax_on, (int) $ax_st['konto_gesamt'] - count($ax_st['liste']))); }
    $ax_doppel = array();
    foreach ($ax_st['liste'] as $ax_g) { if (preg_match('/_[0-9]+$/', $ax_g['normal']) && ax_name_normal($ax_g['anzeige']) !== $ax_g['normal']) { $ax_doppel[] = $ax_g['normal']; } }
    $ax_zeile($ax_doppel ? 2 : 1, ax_t('TEST.F_DOPPEL'), $ax_doppel ? sprintf(ax_t('TEST.A_DOPPEL'), implode(', ', $ax_doppel)) : ax_t('TEST.A_DOPPEL_KEINE'));
    $ax_fehlt = array();
    $ax_normal = array_map(function ($g) { return $g['normal']; }, $ax_st['liste']);
    foreach ($ax_cfg['gruppen'] as $ax_gr) { foreach (explode(',', $ax_gr['geraete']) as $ax_gn) { if (!in_array($ax_gn, $ax_normal, true)) { $ax_fehlt[] = $ax_gr['name'] . ':' . $ax_gn; } } }
    if ($ax_cfg['standardgeraet'] !== '' && !in_array($ax_cfg['standardgeraet'], $ax_normal, true)) { $ax_fehlt[] = ax_t('EINST.L_STANDARD') . ':' . $ax_cfg['standardgeraet']; }
    $ax_zeile($ax_fehlt ? 2 : 1, ax_t('TEST.F_GRUPPEN'), $ax_fehlt ? sprintf(ax_t('TEST.A_GRUPPEN_FEHLT'), implode(', ', $ax_fehlt)) : sprintf(ax_t('TEST.A_GRUPPEN_OK'), count($ax_cfg['gruppen'])));
    // K4: Geraete, die Amazon nicht mehr meldet
    $ax_vw = array();
    foreach ($ax_st['verschwunden'] as $ax_g) { $ax_vw[] = $ax_g['normal'] . ' (' . $ax_zeit($ax_g['seit']) . ')'; }
    $ax_zeile($ax_vw ? 2 : 1, ax_t('TEST.F_VERSCHWUNDEN'), $ax_vw ? sprintf(ax_t('TEST.A_VERSCHWUNDEN'), implode(', ', $ax_vw)) : ax_t('TEST.A_VERSCHWUNDEN_KEINE'));
}
$ax_zeile(is_array($ax_letzte) ? ((int) $ax_letzte['ergebnis'] ? 1 : 2) : -1, ax_t('TEST.F_LETZTE'), is_array($ax_letzte)
    ? sprintf(ax_t('TEST.A_LETZTE'), $ax_zeit($ax_letzte['zeit']), (string) $ax_letzte['aktion'], (string) $ax_letzte['geraet'], (int) $ax_letzte['ergebnis'], ax_grund_text((string) $ax_letzte['grund']))
    : ax_t('TEST.A_LETZTE_KEINE'));
if ($ax_tab === 'tab-test') {
    list($ax_s, $ax_a) = ax_selbstprobe($ax_cfg);
    $ax_zeile($ax_s, ax_t('TEST.F_SELBST'), $ax_a);
} else {
    $ax_zeile(-1, ax_t('TEST.F_SELBST'), ax_t('TEST.A_SELBST_NUR_REITER'));
}
$ax_cron = ($ax_p['lbhome'] !== '') ? (glob($ax_p['lbhome'] . '/system/cron/cron.*/' . $ax_p['plugin']) ?: array()) : array();
$ax_talter = ax_alter($ax_takt['ts']);
if ($ax_p['lbhome'] === '') { $ax_zeile(-1, ax_t('TEST.F_TAKT'), ax_t('TEST.A_TAKT_KEINE_WURZEL')); }
elseif (!$ax_cron && $ax_talter < 0) { $ax_zeile(-1, ax_t('TEST.F_TAKT'), ax_t('TEST.A_TAKT_NIE')); }
elseif ($ax_talter >= 0 && $ax_talter <= AX_OK_GRENZE_S) { $ax_zeile(1, ax_t('TEST.F_TAKT'), sprintf(ax_t('TEST.A_TAKT_OK'), ax_dauer_text($ax_talter), implode(', ', $ax_cron) ?: '–')); }
else { $ax_zeile(0, ax_t('TEST.F_TAKT'), sprintf(ax_t('TEST.A_TAKT_ALT'), ax_dauer_text($ax_talter), implode(', ', $ax_cron) ?: '–')); }
if (empty($ax_cfg['befehle_mqtt_ein'])) { $ax_zeile(3, ax_t('TEST.F_BEFEHLE'), ax_t('TEST.A_BEFEHLE_AUS')); }
else {
    $ax_ds = $ax_kopf_dienst;   // Abfrage im Kopf (Nr. 43, 1.0.1)
    $ax_zeile($ax_ds === null ? -1 : ($ax_ds ? 1 : 0), ax_t('TEST.F_BEFEHLE'), $ax_ds === null ? ax_t('TEST.A_BEFEHLE_NICHT') : ($ax_ds ? ax_t('TEST.A_BEFEHLE_LAEUFT') : ax_t('TEST.A_BEFEHLE_STEHT')));
}
// H2 (alexa4): Laeuft die Hue-Probe? Mit Selbstprobe, wenn dieser Reiter offen ist.
// Nr. 41 (alexa5): je Art. Bei eigener Netzadresse wird Docker nur bei offenem Reiter Test gefragt.
$ax_hart = ax_hue_art($ax_cfg);
$ax_hz = ax_hue_lesen($ax_hart);
$ax_hd = null;
$ax_hs = (empty($ax_cfg['hue_ein']) || $ax_hart === 'docker') ? false : ax_hue_dienst_status();
if (empty($ax_cfg['hue_ein'])) { $ax_hsatz = ax_t('TEST.A_HUE_AUS'); $ax_zeile(3, ax_t('TEST.F_HUE'), $ax_hsatz); }
elseif ($ax_hart === 'docker') {
    $ax_hd = ax_hue_docker_befund($ax_cfg, $ax_tab === 'tab-test');
    $ax_hsatz = $ax_hd[1];
    $ax_zeile($ax_hd[0], ax_t('TEST.F_HUE'), $ax_hsatz);
    if ($ax_hd[0] === 1 && $ax_tab === 'tab-test') {
        list($ax_s, $ax_a) = ax_hue_selbstprobe_docker($ax_hd[3]);
        $ax_zeile($ax_s, ax_t('TEST.F_HUE_D_PROBE'), $ax_a);
    }
}
elseif ($ax_hs === null) { $ax_hsatz = ax_t('TEST.A_HUE_NICHT'); $ax_zeile(-1, ax_t('TEST.F_HUE'), $ax_hsatz); }
elseif ($ax_hs) {
    $ax_hsatz = sprintf(ax_t('TEST.A_HUE_LAEUFT'), (int) $ax_cfg['hue_port'], $ax_zeit($ax_hz['start']));
    $ax_zeile(1, ax_t('TEST.F_HUE'), $ax_hsatz);
    if ($ax_tab === 'tab-test') {
        list($ax_s, $ax_a) = ax_hue_selbstprobe($ax_cfg);
        $ax_zeile($ax_s, ax_t('TEST.F_HUE_PROBE'), $ax_a);
    }
} else {
    $ax_hsatz = sprintf(ax_t('TEST.A_HUE_STEHT'), $ax_hz['fehler'] !== '' ? ax_grund_text($ax_hz['fehler']) : '–');
    $ax_zeile(0, ax_t('TEST.F_HUE'), $ax_hsatz);
}
// K1: Zustand der Sperre aus Loxone - auch "kein Wert nach dem Update" sichtbar.
list($ax_s, $ax_a) = $ax_sperre_zeile();
$ax_zeile($ax_s, ax_t('TEST.F_SPERRE'), $ax_a);
$ax_eb = ax_config_erstbefund();
$ax_heilzeile = in_array('kaputt', $ax_eb['schritte'], true) || in_array('kaputt_fest', $ax_eb['schritte'], true) ? 0
              : (in_array('aus_zweit', $ax_eb['schritte'], true) || in_array('token_aus_zweit', $ax_eb['schritte'], true) ? 2 : 1);
$ax_zeile($ax_heilzeile, ax_t('TEST.F_HEIL'), sprintf(ax_t('TEST.A_HEIL'), $ax_eb['zustand'] !== '' ? $ax_eb['zustand'] : '-',
    $ax_eb['schritte'] ? implode(', ', $ax_eb['schritte']) : '-', $ax_eb['datei'] !== '' ? $ax_eb['datei'] : '-'));
list($ax_roh) = ax_config_roh();
$ax_vorg = array_keys(ax_vorgaben());
$ax_dab = is_array($ax_roh) ? array_intersect($ax_vorg, array_keys($ax_roh)) : array();
$ax_fe = array_diff($ax_vorg, $ax_dab);
$ax_zeile($ax_fe ? 0 : 1, ax_t('TEST.F_VOLL'), $ax_fe ? sprintf(ax_t('TEST.A_VOLL_FEHLT'), count($ax_dab), count($ax_vorg), implode(', ', $ax_fe))
    : sprintf(ax_t('TEST.A_VOLL_OK'), count($ax_dab), count($ax_vorg)));
$ax_zeile(($ax_sprech === '' || $ax_aktion === '') ? 0 : ($ax_sprech === $ax_aktion ? 0 : 1), ax_t('TEST.F_TOKEN'),
    ($ax_sprech === '' || $ax_aktion === '') ? ax_t('TEST.A_TOKEN_LEER') : ($ax_sprech === $ax_aktion ? ax_t('TEST.A_TOKEN_GLEICH') : ax_t('TEST.A_TOKEN_OK')));
$ax_quelle = (string) @file_get_contents(__FILE__);
list($ax_s, $ax_a) = ax_pruef_reiter($ax_quelle, $ax_muster);
$ax_zeile($ax_s, ax_t('TEST.F_REITER'), $ax_a);
list($ax_s, $ax_a) = ax_pruef_formulare($ax_quelle);
$ax_zeile($ax_s, ax_t('TEST.F_FORMULARE'), $ax_a);
list($ax_s, $ax_a) = ax_pruef_themen();
$ax_zeile($ax_s, ax_t('TEST.F_THEMEN'), $ax_a);
list($ax_s, $ax_a) = ax_pruef_vorlagen($ax_cfg);
$ax_zeile($ax_s, ax_t('TEST.F_VORLAGEN'), $ax_a);
if (empty($ax_cfg['mqtt_ein'])) { $ax_zeile(3, ax_t('TEST.F_MQTT'), ax_t('TEST.A_MQTT_AUS')); }
else { $ax_zeile(ax_has_mosquitto() ? 1 : 0, ax_t('TEST.F_MQTT'), ax_has_mosquitto() ? ax_t('TEST.A_MQTT_OK') : ax_t('TEST.A_MQTT_KEIN')); }
$ax_zeile(!$ax_gw['gefunden'] ? -1 : ($ax_gw['autostart'] ? 1 : 2), ax_t('TEST.F_GATEWAY'), !$ax_gw['gefunden'] ? ax_t('TEST.A_GATEWAY_NICHT')
    : sprintf(ax_t('TEST.A_GATEWAY'), $ax_gw['autostart'] ? ax_t('ALLG.EIN') : ax_t('ALLG.AUS'), (int) $ax_gw['fassung']));
$ax_zeile(function_exists('curl_init') ? 1 : 0, ax_t('TEST.F_CURL'), function_exists('curl_init') ? ax_t('TEST.A_CURL_OK') : ax_t('TEST.A_CURL_FEHLT'));
$ax_zahl = array(1 => 0, 0 => 0, -1 => 0, 2 => 0, 3 => 0);
foreach ($ax_pr as $ax_z) { $ax_zahl[$ax_z[0]]++; }
$ax_klasse = $ax_zahl[0] ? 'sm-alert sm-err' : (($ax_zahl[-1] || $ax_zahl[2]) ? 'sm-alert sm-warn' : 'sm-alert sm-ok');
?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ax_e(ax_t('LEGENDE.AKTION')) ?></span>
</div>
<h2><?= ax_e(ax_t('TEST.H')) ?></h2>
<div class="<?= $ax_klasse ?>"><?= ax_e(sprintf(ax_t('TEST.ZAHLEN'), $ax_zahl[1], $ax_zahl[0], $ax_zahl[2], $ax_zahl[-1], $ax_zahl[3], count($ax_pr))) ?></div>
<table class="sm-tbl">
<tr><th style="width:5%"></th><th style="width:35%"><?= ax_e(ax_t('TEST.T_FRAGE')) ?></th><th style="width:60%"><?= ax_e(ax_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach ($ax_pr as $ax_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($ax_z[0] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($ax_z[0] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    elseif ($ax_z[0] === 2) { echo '<span class="sm-gelb">&#9679;</span>'; }
    elseif ($ax_z[0] === 3) { echo '<span class="sm-grau">&#9679;</span>'; }
    else { echo '<span class="sm-grau">–</span>'; } ?></td><td><?= ax_e($ax_z[1]) ?></td><td><?= ax_e($ax_z[2]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?= ax_e(ax_t('TEST.LEGENDE_ZEICHEN')) ?></div>
<h3><?= ax_e(ax_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-small"><?= ax_e(ax_t('TEST.SCHALTEN_TEXT')) ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<?php
// Geraete, an die eine Testansage gehen kann (Amazon-Gruppen WHA nicht).
$ax_test_liste = array();
if ($ax_st) { foreach ($ax_st['liste'] as $ax_g) { if ($ax_g['familie'] !== 'WHA') { $ax_test_liste[$ax_g['normal']] = $ax_g['anzeige']; } } }
?>
<div class="sm-knopfreihe">
<?php if ($ax_cfg['standardgeraet'] !== '') { ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="testansage" value="<?= ax_e($ax_cfg['standardgeraet']) ?>"><?= ax_e(sprintf(ax_t('TEST.K_TESTANSAGE'), $ax_cfg['standardgeraet'])) ?></button>
<?php } elseif ($ax_test_liste) {
    // Kein Standardgeraet: Geraet hier waehlen. Genau eines: vorausgewaehlt; eine
    // zurueckgegebene Wahl hat Vorrang (auch eine beanstandete).
    $ax_tw = $ax_testgeraet !== '' ? $ax_testgeraet : (count($ax_test_liste) === 1 ? (string) key($ax_test_liste) : ''); ?>
    <label for="testgeraet"><?= ax_e(ax_t('TEST.L_TESTGERAET')) ?></label>
    <select data-role="none" name="testgeraet" id="testgeraet"<?= $ax_testgeraet_falsch ? ' class="sm-beanstandet" aria-invalid="true"' : '' ?>>
    <option value=""<?= $ax_tw === '' ? ' selected' : '' ?>><?= ax_e(ax_t('TEST.O_WAEHLEN')) ?></option>
<?php foreach ($ax_test_liste as $ax_tn => $ax_ta) { ?>
    <option value="<?= ax_e($ax_tn) ?>"<?= $ax_tw === (string) $ax_tn ? ' selected' : '' ?>><?= ax_e($ax_tn . ' (' . $ax_ta . ')') ?></option>
<?php } ?>
<?php if ($ax_tw !== '' && !isset($ax_test_liste[$ax_tw])) { ?>
    <option value="<?= ax_e($ax_tw) ?>" selected><?= ax_e($ax_tw . ' – ' . ax_t('EINST.O_UNBEKANNT')) ?></option>
<?php } ?>
    </select>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="testansage" value=""><?= ax_e(ax_t('TEST.K_TESTANSAGE_GEWAEHLT')) ?></button>
<?php } else { ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="testansage" value="" disabled><?= ax_e(sprintf(ax_t('TEST.K_TESTANSAGE'), '–')) ?></button>
<?php } ?>
</div>
<?php if ($ax_cfg['standardgeraet'] === '' && $ax_test_liste) { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.TESTANSAGE_WAEHLEN')) ?> <a href="index.php?form=settings#standardgeraet"><?= ax_e(ax_t('TEST.ZU_EINSTELLUNGEN')) ?></a></div>
<?php } elseif ($ax_cfg['standardgeraet'] === '') { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.TESTANSAGE_GESPERRT')) ?> <a href="index.php?form=geraete"><?= ax_e(ax_t('TEST.ZU_GERAETE')) ?></a></div>
<?php } ?>
</form>

<h3 id="routine_start"><?= ax_e(ax_t('TEST.H_ROUTINE')) ?></h3>
<div class="sm-small"><?= ax_e(ax_t('TEST.ROUTINE_TEXT')) ?></div>
<?php
$ax_rt_wahl = $ax_tr !== '' ? $ax_tr : (count($ax_cfg['routinen_frei']) === 1 ? (string) $ax_cfg['routinen_frei'][0] : '');
$ax_rg_wahl = $ax_trg !== '' ? $ax_trg : ($ax_cfg['standardgeraet'] !== '' ? $ax_cfg['standardgeraet']
            : (count($ax_test_liste) === 1 ? (string) key($ax_test_liste) : ''));
$ax_rt_frei = $ax_cfg['routinen_frei'] && $ax_test_liste;
?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<div class="sm-row">
    <div><label for="test_routine"><?= ax_e(ax_t('TEST.L_ROUTINE')) ?></label>
    <select data-role="none" name="test_routine" id="test_routine"<?= $ax_tr_falsch ? ' class="sm-beanstandet" aria-invalid="true"' : '' ?>>
    <option value=""<?= $ax_rt_wahl === '' ? ' selected' : '' ?>><?= ax_e(ax_t('TEST.O_WAEHLEN')) ?></option>
<?php foreach ($ax_cfg['routinen_frei'] as $ax_rn) { ?>
    <option value="<?= ax_e($ax_rn) ?>"<?= $ax_rt_wahl === $ax_rn ? ' selected' : '' ?>><?= ax_e($ax_rn) ?></option>
<?php } ?>
    </select></div>
    <div><label for="test_routine_geraet"><?= ax_e(ax_t('TEST.L_ROUTINE_GERAET')) ?></label>
    <select data-role="none" name="test_routine_geraet" id="test_routine_geraet"<?= $ax_trg_falsch ? ' class="sm-beanstandet" aria-invalid="true"' : '' ?>>
    <option value=""<?= $ax_rg_wahl === '' ? ' selected' : '' ?>><?= ax_e(ax_t('TEST.O_WAEHLEN')) ?></option>
<?php foreach ($ax_test_liste as $ax_tn => $ax_ta) { ?>
    <option value="<?= ax_e($ax_tn) ?>"<?= $ax_rg_wahl === (string) $ax_tn ? ' selected' : '' ?>><?= ax_e($ax_tn . ' (' . $ax_ta . ')') ?></option>
<?php } ?>
<?php if ($ax_rg_wahl !== '' && !isset($ax_test_liste[$ax_rg_wahl])) { ?>
    <option value="<?= ax_e($ax_rg_wahl) ?>" selected><?= ax_e($ax_rg_wahl . ' – ' . ax_t('EINST.O_UNBEKANNT')) ?></option>
<?php } ?>
    </select></div>
</div>
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="routine_start" value="1"<?= $ax_rt_frei ? '' : ' disabled' ?>><?= ax_e(ax_t('TEST.K_ROUTINE')) ?></button>
</div>
<?php if (!$ax_cfg['routinen_frei']) { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.ROUTINE_GESPERRT_LISTE')) ?> <a href="index.php?form=settings#routinen"><?= ax_e(ax_t('TEST.ZU_ROUTINEN')) ?></a></div>
<?php } elseif (!$ax_test_liste) { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.ROUTINE_GESPERRT_GERAETE')) ?> <a href="index.php?form=geraete"><?= ax_e(ax_t('TEST.ZU_GERAETE')) ?></a></div>
<?php } ?>
</form>

<h3 id="musik_probe"><?= ax_e(ax_t('TEST.H_MUSIK')) ?></h3>
<div class="sm-warnung"><?= ax_e(ax_t('TEST.MUSIK_HILFE')) ?></div>
<?php
// Ziele der Musik: sprechfaehige Geraete und Amazon-Gruppen (Mehrraum-Musikgruppen).
$ax_musik_liste = array();
if ($ax_st) {
    foreach ($ax_st['liste'] as $ax_g) {
        $ax_musik_liste[$ax_g['normal']] = $ax_g['anzeige'] . ($ax_g['familie'] === 'WHA' ? ' – ' . ax_t('GER.WHA') : '');
    }
}
$ax_mg_wahl = $ax_mz['musik_geraet'] !== '' ? $ax_mz['musik_geraet'] : ($ax_cfg['standardgeraet'] !== '' ? $ax_cfg['standardgeraet']
            : (count($ax_test_liste) === 1 ? (string) key($ax_test_liste) : ''));
$ax_mn_wahl = $ax_mz['musik_nr'] !== '' ? $ax_mz['musik_nr'] : '';
$ax_ma_wahl = $ax_mz['musik_anbieter'] !== '' ? $ax_mz['musik_anbieter'] : 'tunein';
$ax_musik_frei = !empty($ax_cfg['musik_ein']) && $ax_musik_liste;
?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<div class="sm-row">
    <div><label for="musik_nr"><?= ax_e(ax_t('TEST.L_MUSIK_NR')) ?></label>
    <select data-role="none" name="musik_nr" id="musik_nr"<?= $ax_mzm('musik_nr') ?>>
    <option value=""<?= $ax_mn_wahl === '' ? ' selected' : '' ?>><?= ax_e(ax_t('TEST.O_EIGENER_NAME')) ?></option>
<?php foreach ($ax_cfg['musik_sender'] as $ax_z) { ?>
    <option value="<?= (int) $ax_z['nr'] ?>"<?= $ax_mn_wahl === (string) $ax_z['nr'] ? ' selected' : '' ?>><?= ax_e($ax_z['nr'] . ' – ' . $ax_z['name'] . ' (' . $ax_z['anbieter'] . ')') ?></option>
<?php } ?>
    </select></div>
    <div><label for="musik_sender_name"><?= ax_e(ax_t('TEST.L_MUSIK_NAME')) ?></label>
    <input data-role="none" type="text" id="musik_sender_name" name="musik_sender_name" maxlength="100" value="<?= ax_e($ax_mz['musik_sender_name']) ?>"<?= $ax_mzm('musik_sender_name') ?>></div>
    <div><label for="musik_anbieter"><?= ax_e(ax_t('TEST.L_MUSIK_ANBIETER')) ?></label>
    <select data-role="none" name="musik_anbieter" id="musik_anbieter"<?= $ax_mzm('musik_anbieter') ?>>
    <option value="tunein"<?= $ax_ma_wahl === 'tunein' ? ' selected' : '' ?>>TuneIn</option>
    <option value="amazon"<?= $ax_ma_wahl === 'amazon' ? ' selected' : '' ?>>Amazon Music</option>
    </select></div>
    <div><label for="musik_geraet"><?= ax_e(ax_t('TEST.L_MUSIK_GERAET')) ?></label>
    <select data-role="none" name="musik_geraet" id="musik_geraet"<?= $ax_mzm('musik_geraet') ?>>
    <option value=""<?= $ax_mg_wahl === '' ? ' selected' : '' ?>><?= ax_e(ax_t('TEST.O_WAEHLEN')) ?></option>
<?php foreach ($ax_musik_liste as $ax_tn => $ax_ta) { ?>
    <option value="<?= ax_e($ax_tn) ?>"<?= $ax_mg_wahl === (string) $ax_tn ? ' selected' : '' ?>><?= ax_e($ax_tn . ' (' . $ax_ta . ')') ?></option>
<?php } ?>
<?php if ($ax_mg_wahl !== '' && !isset($ax_musik_liste[$ax_mg_wahl])) { ?>
    <option value="<?= ax_e($ax_mg_wahl) ?>" selected><?= ax_e($ax_mg_wahl . ' – ' . ax_t('EINST.O_UNBEKANNT')) ?></option>
<?php } ?>
    </select></div>
</div>
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="musik_start" value="1"<?= $ax_musik_frei ? '' : ' disabled' ?>><?= ax_e(ax_t('TEST.K_MUSIK_START')) ?></button>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="musik_halt" value="1"<?= $ax_musik_frei ? '' : ' disabled' ?>><?= ax_e(ax_t('TEST.K_MUSIK_STOPP')) ?></button>
</div>
<?php if (empty($ax_cfg['musik_ein'])) { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.MUSIK_GESPERRT')) ?> <a href="index.php?form=settings#musik"><?= ax_e(ax_t('TEST.ZU_MUSIK')) ?></a></div>
<?php } elseif (!$ax_musik_liste) { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.ROUTINE_GESPERRT_GERAETE')) ?> <a href="index.php?form=geraete"><?= ax_e(ax_t('TEST.ZU_GERAETE')) ?></a></div>
<?php } ?>
</form>

<h3 id="radio_test"><?= ax_e(ax_t('TEST.H_RADIO')) ?></h3>
<div class="sm-warnung"><?= ax_e(ax_t('TEST.RADIO_HILFE')) ?></div>
<?php
$ax_rstand = ax_radio_lesen();
$ax_rz_liste = $ax_cfg['radio_zonen'];
$ax_rz_nummern = array_map(function ($z) { return (string) $z['zone']; }, $ax_rz_liste);
$ax_rzone_wahl = $ax_rzf['radio_zone'] !== '' ? $ax_rzf['radio_zone'] : (count($ax_rz_liste) === 1 ? (string) $ax_rz_liste[0]['zone'] : '');
$ax_rnr_wahl = $ax_rzf['radio_nr'] !== '' ? $ax_rzf['radio_nr'] : (count($ax_cfg['musik_sender']) === 1 ? (string) $ax_cfg['musik_sender'][0]['nr'] : '');
$ax_rnr_liste = array_map(function ($z) { return (string) $z['nr']; }, $ax_cfg['musik_sender']);
$ax_radio_frei = !empty($ax_cfg['radio_ein']) && $ax_rz_liste;
?>
<?php if ($ax_rz_liste) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:8%"><?= ax_e(ax_t('TEST.T_ZONE')) ?></th><th style="width:20%"><?= ax_e(ax_t('TEST.T_ZIEL')) ?></th><th style="width:32%"><?= ax_e(ax_t('TEST.T_BESTAETIGT')) ?></th><th style="width:40%"><?= ax_e(ax_t('TEST.T_LETZTER')) ?></th></tr>
<?php foreach ($ax_rz_liste as $ax_z) {
    $ax_re = isset($ax_rstand[$ax_z['zone']]) ? $ax_rstand[$ax_z['zone']] : null;
    if (!$ax_re || $ax_re['zustand'] < 0) { $ax_rb = '–'; }
    elseif ($ax_re['zustand'] === 1) { $ax_rb = sprintf(ax_t('TEST.RADIO_SPIELT'), $ax_re['sender'], $ax_zeit($ax_re['t_sender'])); }
    else { $ax_rb = sprintf(ax_t('TEST.RADIO_GESTOPPT'), $ax_zeit($ax_re['t_sender'])); }
    if ($ax_re && $ax_re['laut'] >= 0) { $ax_rb .= ' · ' . sprintf(ax_t('TEST.RADIO_LAUT'), $ax_re['laut']); }
    $ax_rl = (!$ax_re || $ax_re['zeit'] <= 0) ? ax_t('TEST.RADIO_KEIN_BEFEHL')
        : sprintf(ax_t('TEST.RADIO_LETZTER'), $ax_zeit($ax_re['zeit']), $ax_re['befehl'],
            $ax_re['ergebnis'] === 1 ? ($ax_re['grund'] === 'UNVERAENDERT' ? ax_grund_text('UNVERAENDERT') : ax_t('TEST.RADIO_GESENDET')) : ax_grund_text($ax_re['grund']),
            $ax_re['quelle'] === 'oberflaeche' ? ax_t('TEST.WEG_OBERFLAECHE') : strtoupper($ax_re['quelle'])); ?>
<tr><td><?= (int) $ax_z['zone'] ?></td><td><span class="sm-mono"><?= ax_e($ax_z['ziel']) ?></span></td><td><?= ax_e($ax_rb) ?></td><td><?= ax_e($ax_rl) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="formtoken" value="<?= ax_e(ax_formtoken($ax_cfg)) ?>">
<div class="sm-row">
    <div><label for="radio_zone"><?= ax_e(ax_t('TEST.L_RADIO_ZONE')) ?></label>
    <select data-role="none" name="radio_zone" id="radio_zone"<?= $ax_rzm('radio_zone') ?>>
    <option value=""<?= $ax_rzone_wahl === '' ? ' selected' : '' ?>><?= ax_e(ax_t('TEST.O_WAEHLEN')) ?></option>
<?php foreach ($ax_rz_liste as $ax_z) { ?>
    <option value="<?= (int) $ax_z['zone'] ?>"<?= $ax_rzone_wahl === (string) $ax_z['zone'] ? ' selected' : '' ?>><?= ax_e($ax_z['zone'] . ' – ' . $ax_z['ziel']) ?></option>
<?php } ?>
<?php if ($ax_rz_liste || $ax_rzone_wahl === 'alle') { ?>
    <option value="alle"<?= $ax_rzone_wahl === 'alle' ? ' selected' : '' ?>><?= ax_e(ax_t('TEST.O_ALLE_ZONEN')) ?></option>
<?php } ?>
<?php if ($ax_rzone_wahl !== '' && $ax_rzone_wahl !== 'alle' && !in_array($ax_rzone_wahl, $ax_rz_nummern, true)) { ?>
    <option value="<?= ax_e($ax_rzone_wahl) ?>" selected><?= ax_e($ax_rzone_wahl . ' – ' . ax_t('EINST.O_UNBEKANNT')) ?></option>
<?php } ?>
    </select></div>
    <div><label for="radio_nr"><?= ax_e(ax_t('TEST.L_RADIO_NR')) ?></label>
    <select data-role="none" name="radio_nr" id="radio_nr"<?= $ax_rzm('radio_nr') ?>>
    <option value=""<?= $ax_rnr_wahl === '' ? ' selected' : '' ?>><?= ax_e(ax_t('TEST.O_WAEHLEN')) ?></option>
<?php foreach ($ax_cfg['musik_sender'] as $ax_z) { ?>
    <option value="<?= (int) $ax_z['nr'] ?>"<?= $ax_rnr_wahl === (string) $ax_z['nr'] ? ' selected' : '' ?>><?= ax_e($ax_z['nr'] . ' – ' . $ax_z['name'] . ' (' . $ax_z['anbieter'] . ')') ?></option>
<?php } ?>
<?php if ($ax_rnr_wahl !== '' && !in_array($ax_rnr_wahl, $ax_rnr_liste, true)) { ?>
    <option value="<?= ax_e($ax_rnr_wahl) ?>" selected><?= ax_e($ax_rnr_wahl . ' – ' . ax_t('EINST.O_UNBEKANNT')) ?></option>
<?php } ?>
    </select></div>
</div>
<div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="radio_start" value="1"<?= ($ax_radio_frei && $ax_cfg['musik_sender']) ? '' : ' disabled' ?>><?= ax_e(ax_t('TEST.K_RADIO_START')) ?></button>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="radio_halt" value="1"<?= $ax_radio_frei ? '' : ' disabled' ?>><?= ax_e(ax_t('TEST.K_RADIO_STOPP')) ?></button>
</div>
<?php if (empty($ax_cfg['radio_ein'])) { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.RADIO_GESPERRT')) ?> <a href="index.php?form=settings#radio"><?= ax_e(ax_t('TEST.ZU_RADIO')) ?></a></div>
<?php } elseif (!$ax_rz_liste) { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.RADIO_KEINE_ZONE')) ?> <a href="index.php?form=settings#radio"><?= ax_e(ax_t('TEST.ZU_RADIO')) ?></a></div>
<?php } elseif (!$ax_cfg['musik_sender']) { ?>
<div class="sm-small"><?= ax_e(ax_t('TEST.RADIO_KEIN_SENDER')) ?> <a href="index.php?form=settings#sender"><?= ax_e(ax_t('TEST.ZU_SENDER')) ?></a></div>
<?php } ?>
</form>

<h3 id="hue_probe"><?= ax_e(ax_t('TEST.H_HUE')) ?></h3>
<div class="sm-warnung"><?= ax_e(ax_t('TEST.HUE_HILFE')) ?></div>
<?php
$ax_hzs = $ax_hz['suchen'];
$ax_hue_wann = function ($e) use ($ax_zeit) { return sprintf(ax_t('TEST.HUE_ZULETZT'), (int) $e['anzahl'], $e['ip'] !== '' ? $e['ip'] : '–', $ax_zeit($e['zeit'])); };
$ax_hue_zeilen = array(
    array(ax_t('TEST.F_HUE'), $ax_hsatz),
    array(ax_t('TEST.F_HUE_SUCHE'), $ax_hzs['anzahl'] > 0
        ? sprintf(ax_t('TEST.A_HUE_SUCHE'), $ax_hzs['ip'], $ax_zeit($ax_hzs['zeit']), $ax_hzs['st'], (int) $ax_hzs['anzahl'], (int) $ax_hzs['beantwortet'])
        : ax_t('TEST.A_HUE_SUCHE_KEINE')),
    array(ax_t('TEST.F_HUE_BESCHREIBUNG'), $ax_hz['beschreibung']['anzahl'] > 0 ? $ax_hue_wann($ax_hz['beschreibung']) : ax_t('TEST.A_HUE_NOCH_NICHT')),
    array(ax_t('TEST.F_HUE_ABFRAGE'), $ax_hz['abfragen']['anzahl'] > 0 ? $ax_hue_wann($ax_hz['abfragen']) : ax_t('TEST.A_HUE_NOCH_NICHT')),
    array(ax_t('TEST.F_HUE_SCHALTEN'), $ax_hz['schalten']['anzahl'] > 0
        ? $ax_hue_wann($ax_hz['schalten']) . ' · ' . sprintf(ax_t('TEST.A_HUE_SCHALTEN'), $ax_hz['schalten']['ein'] ? ax_t('ALLG.EIN') : ax_t('ALLG.AUS'),
            (int) $ax_hz['schalten']['mqtt'], (int) $ax_hz['schalten']['mqtt_nicht'], $ax_cfg['mqtt_praefix'] . '/hue_probe/ein')
        : ax_t('TEST.A_HUE_NOCH_NICHT')),
);
if ($ax_hd !== null) { foreach ($ax_hd[2] as $ax_z) { $ax_hue_zeilen[] = $ax_z; } }   // Nr. 41: eigene Netzadresse
// Fassung 2 (alexa6): abgewiesene Echos und die Rueckmeldung aus Loxone.
$ax_hab = $ax_hz['abgewiesen'];
$ax_hab_l = array();
foreach ($ax_hab['absender'] as $ax_hip => $ax_he) { $ax_hab_l[] = $ax_hip . ' (' . (int) $ax_he['anzahl'] . ')'; }
$ax_hue_zeilen[] = array(ax_t('TEST.F_HUE_ABGEWIESEN'), $ax_hab['anzahl'] > 0
    ? sprintf(ax_t('TEST.A_HUE_ABGEWIESEN'), (int) $ax_hab['anzahl'], implode(', ', $ax_hab_l), $ax_zeit($ax_hab['zeit']))
    : ($ax_cfg['hue_echos'] ? sprintf(ax_t('TEST.A_HUE_ABGEWIESEN_KEINE_LISTE'), implode(', ', $ax_cfg['hue_echos'])) : ax_t('TEST.A_HUE_ABGEWIESEN_KEINE')));
$ax_hmo = $ax_hz['mqtt_abo'];
if (!$ax_cfg['hue_lampen']) {
    $ax_hmo_t = ax_t('TEST.A_HUE_ABO_OHNE');
} elseif ($ax_hmo['verbunden']) {
    $ax_hmo_t = sprintf(ax_t('TEST.A_HUE_ABO_JA'), $ax_zeit($ax_hmo['seit']), (int) $ax_hmo['empfangen'],
        $ax_hmo['zeit'] > 0 ? $ax_zeit($ax_hmo['zeit']) : ax_t('ALLG.NIE'), (int) $ax_hmo['ungueltig'], (int) $ax_hmo['unbekannt']);
} else {
    $ax_hmo_t = sprintf(ax_t('TEST.A_HUE_ABO_NEIN'), $ax_hmo['fehler'] !== '' ? ax_grund_text('ABO_' . $ax_hmo['fehler']) : '–');
}
$ax_hue_zeilen[] = array(ax_t('TEST.F_HUE_ABO'), $ax_hmo_t);
?>
<table class="sm-tbl" id="hue_tabelle">
<tr><th style="width:40%"><?= ax_e(ax_t('TEST.T_FRAGE')) ?></th><th style="width:60%"><?= ax_e(ax_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach ($ax_hue_zeilen as $ax_z) { ?>
<tr><td><?= ax_e($ax_z[0]) ?></td><td><?= ax_e($ax_z[1]) ?></td></tr>
<?php } ?>
</table>
<?php if ($ax_hzs['absender']) { ?>
<div class="sm-breit">
<table class="sm-tbl" id="hue_absender">
<tr><th><?= ax_e(ax_t('TEST.T_HUE_ABSENDER')) ?></th><th><?= ax_e(ax_t('TEST.T_HUE_SUCHEN')) ?></th><th><?= ax_e(ax_t('TEST.T_ERSTE')) ?></th><th><?= ax_e(ax_t('TEST.T_LETZTE')) ?></th><th>ST</th></tr>
<?php foreach ($ax_hzs['absender'] as $ax_hip => $ax_he) { ?>
<tr><td><span class="sm-mono"><?= ax_e($ax_hip) ?></span></td><td><?= (int) $ax_he['anzahl'] ?></td><td><?= ax_e($ax_zeit($ax_he['erste'])) ?></td><td><?= ax_e($ax_zeit($ax_he['zeit'])) ?></td><td><span class="sm-mono"><?= ax_e($ax_he['st']) ?></span></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<?php if ($ax_cfg['hue_lampen']) { ?>
<div class="sm-breit">
<table class="sm-tbl" id="hue_lampen_test">
<tr><th style="width:20%"><?= ax_e(ax_t('TEST.T_HUE_LAMPE')) ?></th><th style="width:18%"><?= ax_e(ax_t('TEST.T_HUE_ZUSTAND')) ?></th><th style="width:20%"><?= ax_e(ax_t('TEST.T_HUE_ABFRAGE')) ?></th><th style="width:24%"><?= ax_e(ax_t('TEST.T_HUE_SCHALTUNG')) ?></th><th style="width:18%"><?= ax_e(ax_t('TEST.T_HUE_MQTT')) ?></th></tr>
<?php foreach ($ax_cfg['hue_lampen'] as $ax_l) {
    $ax_ls = isset($ax_hz['lampen'][(string) $ax_l['id']]) ? $ax_hz['lampen'][(string) $ax_l['id']] : null;
    if ($ax_ls === null || $ax_ls['zeit'] === 0) {
        $ax_lz = ax_t('TEST.A_HUE_NOCH_NICHT');
    } else {
        $ax_lz = ($ax_ls['ein'] ? ax_t('ALLG.EIN') : ax_t('ALLG.AUS')) . ($ax_l['art'] === 'dimmer' && $ax_ls['ein'] ? ' · ' . ax_hue_bri_prozent($ax_ls['bri']) . ' %' : '')
            . ' · ' . sprintf(ax_t($ax_ls['quelle'] === 'loxone' ? 'TEST.HUE_QUELLE_LOXONE' : 'TEST.HUE_QUELLE_ALEXA'), $ax_zeit($ax_ls['zeit']));
    }
    $ax_lab = ($ax_ls && $ax_ls['abfrage']['anzahl'] > 0) ? $ax_hue_wann($ax_ls['abfrage']) : ax_t('TEST.A_HUE_NOCH_NICHT');
    $ax_lsc = ($ax_ls && $ax_ls['schalten']['anzahl'] > 0) ? $ax_hue_wann($ax_ls['schalten']) . ($ax_ls['schalten']['wert'] !== '' ? ' · ' . $ax_ls['schalten']['wert'] : '') : ax_t('TEST.A_HUE_NOCH_NICHT');
    $ax_lmq = $ax_ls ? sprintf(ax_t('TEST.A_HUE_LAMPE_MQTT'), (int) $ax_ls['schalten']['mqtt'], (int) $ax_ls['schalten']['mqtt_nicht'],
        (int) $ax_ls['schalten']['gleich'], (int) $ax_ls['schalten']['gebremst']) : '–'; ?>
<tr><td><?= ax_e($ax_l['name']) ?><br><span class="sm-mono"><?= ax_e($ax_cfg['mqtt_praefix'] . '/hue/' . $ax_l['kuerzel']) ?></span> · ID <?= (int) $ax_l['id'] ?></td><td><?= ax_e($ax_lz) ?></td><td><?= ax_e($ax_lab) ?></td><td><?= ax_e($ax_lsc) ?></td><td><?= ax_e($ax_lmq) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<div class="sm-small"><?= ax_e(sprintf(ax_t('TEST.HUE_NEBEN'), (int) $ax_hz['andere'], (int) $ax_hz['eigene']['anzahl'])) ?> <a href="index.php?form=settings#hue"><?= ax_e(ax_t('TEST.ZU_HUE')) ?></a></div>

<h3 id="absender"><?= ax_e(ax_t('TEST.H_ABSENDER')) ?></h3>
<div class="sm-small"><?= ax_e(ax_t('TEST.ABSENDER_TEXT')) ?></div>
<?php $ax_abs = ax_absender_lesen(); if ($ax_abs) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= ax_e(ax_t('TEST.T_WEG')) ?></th><th><?= ax_e(ax_t('TEST.T_ADRESSE')) ?></th><th><?= ax_e(ax_t('TEST.T_ABSENDER')) ?></th><th><?= ax_e(ax_t('TEST.T_ERSTE')) ?></th><th><?= ax_e(ax_t('TEST.T_LETZTE')) ?></th><th><?= ax_e(ax_t('TEST.T_HEUTE')) ?></th><th><?= ax_e(ax_t('TEST.T_GESAMT')) ?></th><th><?= ax_e(ax_t('TEST.T_GESENDET')) ?></th><th><?= ax_e(ax_t('TEST.T_AKTIONEN')) ?></th><th><?= ax_e(ax_t('TEST.T_NICHT')) ?></th></tr>
<?php foreach ($ax_abs as $ax_z) {
    $ax_ak = array();
    foreach ($ax_z['aktionen'] as $ax_k => $ax_n) { $ax_ak[] = $ax_k . ' ' . $ax_n; }
    $ax_gr = array();
    foreach ($ax_z['gruende'] as $ax_k => $ax_n) { $ax_gr[] = $ax_k . ' ' . $ax_n; } ?>
<tr><td><?= ax_e($ax_z['weg'] === 'oberflaeche' ? ax_t('TEST.WEG_OBERFLAECHE') : strtoupper($ax_z['weg'])) ?></td><td><span class="sm-mono"><?= ax_e($ax_z['adresse']) ?></span></td><td><?= ax_e($ax_z['absender']) ?></td>
    <td><?= ax_e($ax_zeit($ax_z['erste'])) ?></td><td><?= ax_e($ax_zeit($ax_z['letzte'])) ?></td><td><?= (int) $ax_z['heute'] ?></td><td><?= (int) $ax_z['gesamt'] ?></td><td><?= (int) $ax_z['gesendet'] ?></td>
    <td><?= ax_e($ax_ak ? implode(', ', $ax_ak) : '–') ?></td><td><?= ax_e($ax_gr ? implode(', ', $ax_gr) : '–') ?></td></tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<div class="sm-hinweis"><?= ax_e(ax_t('TEST.ABSENDER_LEER')) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $ax_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= ax_e(ax_t('REITER.LOG')) ?></h2>
<div class="sm-small"><?= ax_e(ax_t('LOG.TEXT')) ?> <span class="sm-mono"><?= ax_e($ax_p['log']) ?></span></div>
<?php if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
<?php $ax_ll = ax_log_ende(200); if ($ax_ll) { ?>
<div class="sm-log"><?= ax_e(implode("\n", $ax_ll)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= ax_e(sprintf(ax_t('LOG.LEER'), ax_dauer_text(ax_alter($ax_takt['ts'])))) ?></div>
<?php } ?>
</div>
</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.getAttribute('data-ziel') === id); });
        document.querySelectorAll('.sm-seite').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    // Der Reiter Test laedt neu - seine Pruefzeilen (eigener Endpunkt) laufen
    // nur, wenn er serverseitig der offene ist. Die uebrigen schaltet das
    // Skript ohne Neuladen um, damit Eingaben erhalten bleiben.
    tabs.forEach(function (t) {
        if (t.getAttribute('data-ziel') === 'tab-test') { return; }
        t.addEventListener('click', function (ereignis) {
            ereignis.preventDefault();
            activate(t.getAttribute('data-ziel'));
            if (window.history && window.history.replaceState) { window.history.replaceState(null, '', t.getAttribute('href')); }
        });
    });
    activate(<?= json_encode($ax_tab) ?>);
})();
</script>
<?php
if (class_exists('LBWeb', false)) {
    LBWeb::lbfooter();
}
