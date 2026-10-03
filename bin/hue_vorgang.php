<?php
/**
 * Alexa NG - Hue-Probe auf eigener Netzadresse: Container anlegen oder
 * entfernen, im Hintergrund (Entscheidung Nr. 41, alexa5; Vorabfassung, nicht
 * am Geraet erprobt).
 *
 * Gestartet von der Oberflaeche nach dem Speichern oder Zurueckspielen und
 * vom Takt (hoechstens alle 10 Minuten) ueber ax_hue_vorgang_starten(). Im
 * Hintergrund, weil das erste Bauen des Abbilds (docker build aus
 * bin/hue_docker/Dockerfile) Minuten dauert und ein haengendes Docker die
 * Seite nicht aufhalten soll (Bauart MGiSmart bin/gateway_vorgang.php).
 *
 * Der Stand steht in data/plugins/<ordner>/hue_vorgang.json (Auftrag,
 * Zustand, Schritt, Grund); der Reiter Test zeigt ihn. Ein zweiter Vorgang
 * waehrend eines laufenden endet sofort (Sperrdatei mit Prozessnummer, kein
 * flock: die Sperre vererbte sich sonst an jedes Kind von proc_open,
 * Regeln/03).
 *
 * Aufruf: php hue_vorgang.php anlegen|entfernen
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

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
    fwrite(STDERR, 'hue_vorgang.php: ax_lib.php nicht gefunden, gesucht in: ' . implode(', ', $ax_kand) . "\n");
    exit(1);
}
ax_keine_wurzel_abbruch('hue_vorgang.php');
$ax_p = ax_paths();
ini_set('log_errors', '1');
ini_set('error_log', $ax_p['log']);

$ax_auftrag = (isset($argv[1]) && count($argv) === 2) ? (string) $argv[1] : '';
if (!in_array($ax_auftrag, array('anlegen', 'entfernen'), true)) {
    fwrite(STDERR, "Aufruf: hue_vorgang.php anlegen|entfernen\n");
    exit(2);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "hue_vorgang.php: nicht als root starten - der Container liefe sonst als root.\n");
    exit(1);
}

/* ---------------- Sperrdatei (ein Vorgang zur Zeit) ---------------- */
$ax_sperre = $ax_p['datadir'] . '/hue_vorgang.sperre';
if (!is_dir($ax_p['datadir'])) { @mkdir($ax_p['datadir'], 0775, true); }
$ax_fh = @fopen($ax_sperre, 'x');
if ($ax_fh === false) {
    $ax_alt = (int) trim((string) @file_get_contents($ax_sperre));
    if ($ax_alt > 0 && $ax_alt !== getmypid() && ax_hue_vorgang_prozess($ax_alt)) {
        fwrite(STDERR, 'Ein anderer Vorgang laeuft gerade (PID ' . $ax_alt . ").\n");
        exit(3);
    }
    @unlink($ax_sperre);                       // verwaist: der Prozess lebt nicht mehr
    $ax_fh = @fopen($ax_sperre, 'x');
    if ($ax_fh === false) { fwrite(STDERR, "Sperrdatei nicht anzulegen.\n"); exit(3); }
}
fwrite($ax_fh, getmypid() . "\n");
fclose($ax_fh);
register_shutdown_function(function () use ($ax_sperre) {
    if ((int) trim((string) @file_get_contents($ax_sperre)) === getmypid()) { @unlink($ax_sperre); }
});

/* ---------------- Vorgang ---------------- */
$ax_v = ax_hue_vorgang();
$ax_start = ($ax_v['zustand'] === 'gestartet' && $ax_v['vorgang'] === $ax_auftrag && $ax_v['start'] > 0) ? $ax_v['start'] : time();
$ax_schritt = function ($s) use ($ax_auftrag, $ax_start) {
    ax_hue_vorgang_schreiben(array('vorgang' => $ax_auftrag, 'zustand' => 'laeuft', 'pid' => getmypid(), 'start' => $ax_start,
                                   'ende' => 0, 'schritt' => $s, 'grund' => '', 'text' => '', 'ergebnis' => ''));
};
$ax_schritt('beginn');
if ($ax_auftrag === 'anlegen') {
    list($ax_ok, $ax_grund, $ax_text, $ax_erg) = ax_hue_docker_anlegen($ax_schritt);
} else {
    list($ax_ok, $ax_grund, $ax_text, $ax_erg) = ax_hue_docker_entfernen($ax_schritt);
}
ax_hue_vorgang_schreiben(array('vorgang' => $ax_auftrag, 'zustand' => $ax_ok ? 'fertig' : 'fehler', 'pid' => getmypid(),
                               'start' => $ax_start, 'ende' => time(), 'schritt' => '', 'grund' => $ax_grund, 'text' => $ax_text,
                               'ergebnis' => $ax_erg));
ax_log($ax_ok ? 'INFO' : 'WARN', 'Hue-Probe (eigene Netzadresse): ' . $ax_auftrag . ' ' . ($ax_ok ? 'fertig' : 'gescheitert')
    . ' - ' . $ax_erg . ($ax_grund !== '' ? ', Grund ' . $ax_grund : '') . ($ax_text !== '' ? ' (' . $ax_text . ')' : '') . '.');
exit($ax_ok ? 0 : 1);
