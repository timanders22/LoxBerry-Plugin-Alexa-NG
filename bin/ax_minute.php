<?php
/**
 * Alexa NG - Minutentakt (cron.01min, seit 1.0.24): feste Abgleichzeiten der Geraeteliste
 *
 *   php ax_minute.php   ist eine feste Abgleichzeit (Einstellung abgleich_zeiten, ab Werk leer) faellig, gleicht er
 *                       die Geraeteliste einmal ab - unter der Amazon-Sperre, einmal je Zeit und Tag, nachgeholt bis
 *                       15 min danach (ax_abgleich_minute()). Ohne Zeiten endet er sofort, fragt Amazon nie und
 *                       schreibt nichts. rc 0 = nichts zu tun oder erledigt, 1 = Abgleich misslungen, 2 = Aufruf.
 *
 * Bauart ax_takt.php: Bibliothek aus der installierten Lage, ohne LoxBerry-Wurzel nichts tun. Eine eigene Sperre
 * (minute.lock, close-on-exec) verhindert, dass sich zwei Minutentakte stapeln.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

if (isset($argv) && count($argv) > 1) {
    fwrite(STDERR, 'ax_minute.php: keine Schalter vorgesehen (' . $argv[1] . ")\n");
    exit(2);
}

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
    fwrite(STDERR, 'ax_minute.php: ax_lib.php nicht gefunden, gesucht in: ' . implode(', ', $ax_kand) . "\n");
    exit(1);
}
ax_keine_wurzel_abbruch('ax_minute.php');
$ax_p = ax_paths();
ini_set('log_errors', '1');
ini_set('error_log', $ax_p['log']);

// Der billige Weg zuerst: ohne Zeiten oder ohne Datenordner (Takt noch nie gelaufen) nichts tun, nichts anlegen.
$ax_cfg = ax_config();
if (!$ax_cfg['abgleich_zeiten'] || !is_dir($ax_p['datadir'])) { exit(0); }

$ax_ms = @fopen($ax_p['datadir'] . '/minute.lock', 'ce');
if ($ax_ms === false) { $ax_ms = @fopen($ax_p['datadir'] . '/minute.lock', 'c'); }
if ($ax_ms === false || !flock($ax_ms, LOCK_EX | LOCK_NB)) { exit(0); }   // ein Minutentakt laeuft schon

try {
    list($ax_rc, $ax_was) = ax_abgleich_minute();
} catch (Throwable $ax_x) {
    ax_log_wenn_neu('minute_ausnahme', 'WARN', 'Minutentakt: ' . get_class($ax_x) . ': ' . substr($ax_x->getMessage(), 0, 200), 3600);
    $ax_rc = 1;
}
flock($ax_ms, LOCK_UN);
fclose($ax_ms);
exit((int) $ax_rc);
