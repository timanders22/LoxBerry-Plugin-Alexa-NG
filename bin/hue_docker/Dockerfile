# Alexa NG - Abbild fuer die Hue-Probe auf eigener Netzadresse (Entscheidung Nr. 41)
#
# Gebaut am LoxBerry vom Plugin selbst (bin/hue_vorgang.php, docker build),
# nie aus einer fremden Quelle geholt: Grundlage ist nur das offizielle
# PHP-Abbild, dazu die Erweiterungen sockets (Beitritt zur SSDP-Gruppe
# 239.255.255.250) und pcntl (sauberes Anhalten bei docker stop).
#
# Im Abbild steht KEIN Code des Plugins. bin/ax_hue.php und
# bin/ax_hue_container.php werden beim Anlegen des Containers nur lesend
# eingehaengt; was dort laeuft, ist genau die installierte Fassung.
FROM php:8.4-cli
RUN docker-php-ext-install sockets pcntl
