<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Alexa NG.
 *
 * Gruppe 6a / MQTT (1.0.6; Bauvorschrift wz_bild/GRUPPE6A_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe6_ax.txt
 * Abschnitt 3.3, ENTSCHEIDUNGEN_GRUPPE6_ALEXANG.md; Muster vb_g5_pw_bau/bin/befehle_zuordnung.php). Die Bruecke selbst
 * (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese
 * Datei. Sie ersetzt bin/ax_befehle.php und bin/dienst.sh (bis 1.0.5 der eigene Befehlsdienst).
 *
 * Die Themennamen sind GENAU die des bisherigen Befehlsabos (ax_befehle.php, Kopf): bestehende Loxone-Befehle auf
 * <praefix>/befehl/... laufen ohne Aenderung weiter; neu sind die Quittung <praefix>/befehl/<name>/quittung, das
 * Zusammenlegen, die Sperrzeit und der zweite Haken.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft DIESELBEN Funktionen wie der HTTP-Endpunkt webfrontend/html/index.php - ax_befehl_ausfuehren(),
 * ax_radio_ausfuehren(), ax_loxsperre_setzen() - mit Quelle 'mqtt' und gibt ueber ax_befehl_bruecke() array(bool ok,
 * string grund) zurueck. Die Tokenpruefung des Endpunkts ersetzen die Anmeldung am Broker und die Haken
 * (Entwurf gemeinsam E13).
 *
 * Haken (ENTSCHEIDUNGEN Gruppe 6a Punkt 2): "Befehle ueber MQTT annehmen" (befehle_mqtt_ein) schaltet die Bruecke;
 * Ansagen, Ankuendigungen, Lautstaerke, Routinen und Radio greifen ein und brauchen zusaetzlich den zweiten Haken
 * "Echos ueber MQTT steuern" (befehle_mqtt_steuern; fehlt er in einer Konfiguration bis 1.0.5, gilt der Wert von
 * befehle_mqtt_ein - ax_config()). Routinen brauchen als dritte Stufe "Routine per MQTT erlauben"
 * (befehle_routine_ein), je Befehl frisch gelesen (Fremdbefund F11). Die Sperre der Ansagen greift nicht ein (macht
 * nur leiser); sperre_ein, radio_ein, ankuendigen_ein und routinen_frei prueft weiter das Plugin selbst.
 */
if (!function_exists('ax_befehl_bruecke')) {
    $ax_bb_kand = array();
    if (getenv('LBHOMEDIR') && getenv('LBPPLUGINDIR')) {
        $ax_bb_kand[] = rtrim((string) getenv('LBHOMEDIR'), '/') . '/webfrontend/html/plugins/' . basename((string) getenv('LBPPLUGINDIR')) . '/ax_lib.php';
    }
    if (basename(dirname(__DIR__)) === 'plugins') {
        $ax_bb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ax_lib.php';
    }
    $ax_bb_kand[] = dirname(__DIR__) . '/webfrontend/html/ax_lib.php';
    foreach ($ax_bb_kand as $ax_bb_k) {
        if (is_file($ax_bb_k)) { require_once $ax_bb_k; break; }
    }
}
$ax_bb_text = 'Das Plugin hat eine eigene, einstellbare Wiederholbremse (gleicher Text je Echo, ab Werk 30 s, 0 = aus). '
    . 'Eine Brueckensperre von 60 s wuerde sie uebersteuern - zweimal Klingeln binnen einer Minute muss ansagbar sein '
    . '(Entwurf ax 3.3).';
$ax_bb_laut = 'Schieberegler senden Folgen; die Bruecke legt gleiche Namen im Lesepuffer zusammen, die Stundengrenze des '
    . 'Plugins schuetzt Amazon (Entwurf ax 3.3).';
$ax_bb_radio = 'Radiotasten 3 - Aus - 3 binnen einer Minute muessen ankommen; das Plugin meldet gleiche Sollwerte binnen 60 s '
    . 'selbst als UNVERAENDERT (AX_RADIO_GLEICH_S, verglichen mit dem zuletzt bestaetigt gesendeten; Entwurf ax 3.3).';
$ax_bb_sperre = 'Kein Amazon-Kontakt; einen gleichen Wert erkennt das Plugin als UNVERAENDERT (Entwurf ax 3.3).';
$ax_bb_routine = 'Eine Routine soll bewusst zweimal in einer Minute startbar sein; 10 s fangen Prellen ab, auch wenn die '
    . 'Wiederholbremse des Plugins auf 0 steht (Entwurf ax 3.3).';
$ax_bb_sprechen = function ($w, $t) {
    return ax_befehl_bruecke(ax_befehl_ausfuehren('sprechen', array('geraet' => (string) $t['geraet'], 'text' => (string) $w), 'mqtt'));
};
$ax_bb_gruppe = function ($w, $t) {
    return ax_befehl_bruecke(ax_befehl_ausfuehren('sprechen', array('geraet' => 'gruppe:' . (string) $t['gruppe'], 'text' => (string) $w), 'mqtt'));
};
$ax_bb_ankuendigen = function ($w, $t) {
    return ax_befehl_bruecke(ax_befehl_ausfuehren('ankuendigen', array('geraet' => (string) $t['geraet'], 'text' => (string) $w), 'mqtt'));
};
$ax_bb_lautstaerke = function ($w, $t) {
    return ax_befehl_bruecke(ax_befehl_ausfuehren('lautstaerke', array('geraet' => (string) $t['geraet'], 'wert' => (string) $w), 'mqtt'));
};
$ax_bb_routine_tun = function ($w, $t) {
    // Dritte Stufe, je Befehl frisch gelesen (Fremdbefund F11): ohne "Routine per MQTT erlauben" startet keine Routine.
    $c = ax_config();
    if (empty($c['befehle_routine_ein'])) { return array(false, 'ROUTINE_MQTT_AUS'); }
    return ax_befehl_bruecke(ax_befehl_ausfuehren('routine', array('name' => (string) $w), 'mqtt'));
};
$ax_bb_sperre_tun = function ($w, $t) {
    return ax_befehl_bruecke(ax_loxsperre_setzen((string) $w, 'mqtt'));
};
$ax_bb_radio_tun = function ($w, $t) {
    if ((string) $w === 'stopp') {
        return ax_befehl_bruecke(ax_radio_ausfuehren('radio_stopp', array('zone' => (string) $t['zone']), 'mqtt'));
    }
    return ax_befehl_bruecke(ax_radio_ausfuehren('radio', array('zone' => (string) $t['zone'], 'nr' => (string) $w), 'mqtt'));
};
$ax_bb_radio_laut = function ($w, $t) {
    return ax_befehl_bruecke(ax_radio_ausfuehren('radio_laut', array('zone' => (string) $t['zone'], 'wert' => (string) $w), 'mqtt'));
};
$ax_bb_sender = array('stopp');
for ($ax_bb_i = 0; $ax_bb_i <= 50; $ax_bb_i++) { $ax_bb_sender[] = (string) $ax_bb_i; }
return array(
    'fassung' => 1,
    'linie'   => 'alexang',
    'plugin'  => 'Alexa NG',
    'geladen' => function_exists('ax_config') && function_exists('ax_befehl_ausfuehren') && function_exists('ax_radio_ausfuehren')
                 && function_exists('ax_loxsperre_setzen') && function_exists('ax_befehl_bruecke') && function_exists('ax_befehle_einstellungen'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'teile' => array('geraet' => '[a-z0-9_]{1,40}', 'gruppe' => '[a-z0-9_]{1,40}', 'zone' => '[1-9]|1[0-9]|2[0-4]|alle'),
    'ausser' => array('geraet' => array('gruppe')),
    'einstellungen' => function () {
        return ax_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        ax_log($stufe, $text);
    },
    'befehle' => array(
        '{geraet}/sprechen' => array('art' => 'text', 'max_byte' => 4000, 'eingreifend' => true, 'sperre_s' => 0,
                                     'begruendung' => $ax_bb_text, 'titel' => 'Alexa NG Ansage (MQTT)',
                                     'kachel' => 'BEFEHL.K_SPRECHEN', 'quittung' => 'ausgefuehrt', 'tun' => $ax_bb_sprechen),
        'gruppe/{gruppe}/sprechen' => array('art' => 'text', 'max_byte' => 4000, 'eingreifend' => true, 'sperre_s' => 0,
                                     'begruendung' => $ax_bb_text, 'titel' => 'Alexa NG Ansage an Gruppe (MQTT)',
                                     'kachel' => 'BEFEHL.K_GRUPPE', 'quittung' => 'ausgefuehrt', 'tun' => $ax_bb_gruppe),
        '{geraet}/ankuendigen' => array('art' => 'text', 'max_byte' => 4000, 'eingreifend' => true, 'sperre_s' => 0,
                                     'begruendung' => $ax_bb_text, 'titel' => 'Alexa NG Ankündigung (MQTT)',
                                     'kachel' => 'BEFEHL.K_ANKUENDIGEN', 'quittung' => 'ausgefuehrt', 'tun' => $ax_bb_ankuendigen),
        '{geraet}/lautstaerke' => array('art' => 'ganz', 'min' => 0, 'max' => 100, 'eingreifend' => true, 'sperre_s' => 0,
                                     'begruendung' => $ax_bb_laut, 'titel' => 'Alexa NG Lautstärke (MQTT)',
                                     'kachel' => 'BEFEHL.K_LAUT', 'quittung' => 'ausgefuehrt', 'tun' => $ax_bb_lautstaerke),
        'routine' => array('art' => 'text', 'max_byte' => 80, 'eingreifend' => true, 'sperre_s' => 10,
                           'begruendung' => $ax_bb_routine, 'titel' => 'Alexa NG Routine starten (MQTT)',
                           'kachel' => 'BEFEHL.K_ROUTINE', 'quittung' => 'ausgefuehrt', 'tun' => $ax_bb_routine_tun),
        'sperre' => array('art' => 'schalter', 'eingreifend' => false, 'sperre_s' => 0, 'begruendung' => $ax_bb_sperre,
                          'titel' => 'Alexa NG Ansagen sperren (MQTT)', 'kachel' => 'BEFEHL.K_SPERRE',
                          'quittung' => 'ausgefuehrt', 'tun' => $ax_bb_sperre_tun),
        'radio/{zone}' => array('art' => 'auswahl', 'werte' => $ax_bb_sender, 'eingreifend' => true, 'sperre_s' => 0,
                                'begruendung' => $ax_bb_radio, 'titel' => 'Alexa NG Radio Sender (MQTT)',
                                'kachel' => 'BEFEHL.K_RADIO', 'quittung' => 'ausgefuehrt', 'tun' => $ax_bb_radio_tun),
        'radio/{zone}/laut' => array('art' => 'ganz', 'min' => 0, 'max' => 100, 'eingreifend' => true, 'sperre_s' => 0,
                                     'begruendung' => $ax_bb_laut, 'titel' => 'Alexa NG Radio Lautstärke (MQTT)',
                                     'kachel' => 'BEFEHL.K_RADIO_LAUT', 'quittung' => 'ausgefuehrt', 'tun' => $ax_bb_radio_laut),
    ),
);
