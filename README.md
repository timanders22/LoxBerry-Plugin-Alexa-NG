# LoxBerry-Plugin: Alexa NG

Version 1.0.0

Lässt **Amazon-Echo-Geräte** sprechen, was Loxone oder ein anderes Plugin
sagen will: Ansagen an ein Gerät, an eine Gruppe oder an alle, Lautstärke
setzen, freigegebene Routinen starten. Dazu eine Statuszeile und eine
Geräteliste für Loxone und ein MQTT-Anschluss.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x,
mit der curl-Erweiterung). Kein Node, kein Python; Docker nur für die
Hue-Probe auf eigener Netzadresse (ab Werk aus).

> **Erste normale Fassung (1.0.0).** Am Echo gemessen sind Anmeldung, Ansage,
> Routinen, Musik-Probe, Radio je Zone an einem Echo und die Hue-Nachbildung
> mit den Lampen für Alexa – siehe „Am Echo gemessen“ unter „Fassung 1.0.0“.
> Was noch aussteht, steht unter „Noch am Gerät zu messen“.

## Fassung 1.0.0

Erste normale Fassung – keine Vorabfassung mehr. Am Verhalten ändert sich gegenüber 0.9.7 nichts; neu sind nur
Texte: der Hinweis zum Wechsel der Lampenart (Einstellungen, Hilfe, README), die Messstände in Einstellungen und
Hilfe und dieser Abschnitt. Zusammengefasst aus 0.9.5 bis 0.9.7:

* **Hue-Nachbildung auf eigener Netzadresse** (0.9.5, Entscheidung Nr. 41): Mit der Art „eigene Netzadresse
  (Docker)“ legt das Plugin einen Container mit eigener IP im Heimnetz an, der auf Port 80 und 1900 lauscht – für
  Echos, die eine Bridge nur dort abfragen. Apache, Webport und Loxone-Adressen des LoxBerry bleiben unberührt.
  Ab Werk aus.
* **Fassung 2 „Alexa → Loxone“** (0.9.6, 0.9.7): Lampen für Alexa aus einer Freigabeliste, in drei Arten –
  **Schalter** (Steckdosen, Pumpen), **Licht (an/aus)** (Lampen ohne Dimmen) und **Dimmer**. Ein Echo schaltet sie
  lokal, ohne Cloud und ohne Skill; das Plugin sendet flüchtig `alexang/hue/<kürzel>/ein` und beim Dimmer
  `…/helligkeit` über MQTT. Liste ab Werk leer, Nachbildung ab Werk aus.
* **Dimmer einschalten** (0.9.7): „Alexa, schalte … ein“ sendet beim Dimmer nur `ein` = `1`; `helligkeit` geht
  nur hinaus, wenn Alexa eine Helligkeit nennt.
* **Art einer Lampe ändern:** Wird die Art einer Lampe geändert (Schalter, Licht, Dimmer), die Lampe in der
  Alexa-App löschen und danach „Geräte suchen“. Alexa merkt sich die Art je Gerät, in der App lässt sie sich nicht
  ändern (gemessen 03.10.2026 am Echo).
* Konfigurationen und Sicherungen von 0.9.4 bis 0.9.7 bleiben gültig.

### Am Echo gemessen

* **Anmeldung** am echten Amazon-Konto (01.10.2026).
* **Ansage** an einen Echo (01.10.2026).
* **Routinen** aus der Freigabeliste (02.10.2026).
* **Musik-Probe:** ein Sender nach Nummer spielt am Echo (02.10.2026).
* **Radio je Zone** an einem Echo: Start, Senderwechsel und Stopp (02./03.10.2026).
* **Hue-Nachbildung auf eigener IP, Port 80:** der Echo findet sie, fragt sie ab und schaltet (03.10.2026).
* **Lampenarten** in der Alexa-App (03.10.2026): Schalter = An-/Ausschalter, Licht (an/aus) = Lampe (nach Löschen
  und neuem „Geräte suchen“), Dimmer = Lampe mit Helligkeit. Dimmer „ein“ sendet nur `ein` = `1`.

**Nicht gemessen:** zwei Sender in zwei Zonen gleichzeitig, die Rückmeldung aus Loxone (`…/status`,
`…/status_helligkeit`), die NICHT-Flanke (Baustein-Liste Zeile 13) beim Start des Miniservers und der Wechsel des
Docker-Abbilds bei einem Update.

## Fassung 0.9.7

Zwei Änderungen an den Lampen für Alexa (Entscheidung Nr. 42, Vorabfassung) nach der Gerätemessung vom 03.10.2026.
Gemessen mit Attrappen für Amazon, Docker, `ip` und den MQTT-Broker unter PHP 7.4, 8.3 (WSL) und 8.5; die
Nachbildung lief dabei in einem eigenen Netz-Namensraum, nie im Heimnetz.

* **Gemessen am Gerät (03.10.2026, mit 0.9.6):** Ein, aus und dimmen kamen als MQTT an. Die Art „Schalter“ zeigt
  die Alexa-App als An-/Ausschalter, „Dimmer“ als Licht.
* **Neue Art „Licht (an/aus)“** für Lampen ohne Dimmen: Die Nachbildung meldet sie als Licht ohne Helligkeit, damit
  Raumbefehle wie „Alexa, Licht aus“ sie einschließen. Sie sendet wie ein Schalter nur
  `alexang/hue/<kürzel>/ein`. „Schalter“ bleibt für Steckdosen, Pumpen und Ähnliches. Ob die Alexa-App die neue Art
  als Licht zeigt, ist am Echo noch nicht gemessen.
* **Dimmer einschalten ohne Helligkeit:** „Alexa, schalte … ein“ sendet beim Dimmer nur noch `ein` = `1`, keine
  Helligkeit. `helligkeit` geht nur hinaus, wenn Alexa eine Helligkeit nennt. Vorher schickte das Plugin beim
  Einschalten die zuletzt bekannte Helligkeit mit, und bei „auf 40 Prozent“ sprang der Loxone-Dimmer kurz auf 100 %,
  bevor `40` kam. Aus bleibt `ein` = `0` und `helligkeit` = `0`.
* Bestehende Lampen behalten ihre Art. Konfigurationen und Sicherungen von 0.9.4 bis 0.9.6 bleiben gültig.

## Fassung 0.9.6

Fassung 2 „Alexa → Loxone“ (Vorabfassung, ab Werk aus, Liste ab Werk leer) und vier Korrekturen aus der
Gerätemessung vom 03.10.2026. Gemessen mit Attrappen für Amazon, Docker, `ip`, `ping` und den MQTT-Broker unter
PHP 7.4, 8.3 (WSL) und 8.5; die Nachbildung lief dabei in einem eigenen Netz-Namensraum, nie im Heimnetz.

* **Gemessen am Gerät (03.10.2026, mit 0.9.5):** Auf eigener Netzadresse (Port 80) fand ein Echo die Nachbildung,
  die Alexa-App fand „Loxone Probe“, der Echo fragte die Lampe ab und schaltete sie; `alexang/hue_probe/ein` kam
  über MQTT an. Damit trägt der Weg, und Fassung 2 ist gebaut.
* **Lampen für Alexa** (Reiter **Einstellungen**, Abschnitt „Alexa → Loxone“): eine Freigabeliste mit höchstens
  50 Lampen, je Zeile Name (wie Alexa ihn nennt, höchstens 32 Zeichen, eindeutig), Art **Schalter** oder **Dimmer**
  und ein Kürzel für das MQTT-Thema. Ein fehlendes oder ungültiges Kürzel wird beanstandet; ein Vorschlag aus dem
  Namen steht danach im Feld, gespeichert wird erst nach dem nächsten Speichern. Jede Lampe hat eine feste ID, die
  beim Umbenennen bleibt – Alexa ordnet nach ihr. Namen mit Tür, Tor, Garage, Alarm oder Schloss (auch englisch)
  brauchen den Haken „bewusst freigeben“.
* **Befehl an Loxone** über MQTT, flüchtig: `alexang/hue/<kürzel>/ein` = `1`/`0`, beim Dimmer zusätzlich
  `alexang/hue/<kürzel>/helligkeit` = `1`–`100` (aus der Hue-Helligkeit 1–254, gerundet; `0` nur bei aus).
* **Zustand zurück:** Loxone darf `alexang/hue/<kürzel>/status` (`0`/`1`) und `…/status_helligkeit` (`0`–`100`)
  melden; die Nachbildung abonniert beide selbst und zeigt Alexa dann den echten Zustand. Ohne Rückmeldung gilt
  der zuletzt von Alexa gesetzte Wert.
* **Nur freigegebene Echos:** ein neues Feld nimmt die IP-Adressen der Echos, die schalten dürfen; leer (ab Werk)
  heißt alle Geräte im Heimnetz. Anfragen anderer Adressen bekommen eine Fehlerantwort und werden im Reiter Test
  gezählt.
* **Schutz:** derselbe Wert binnen 2 s geht nur einmal hinaus; mehr als 10 Schaltbefehle je Lampe und Minute
  bekommen eine Fehlerantwort und stehen im Protokoll.
* **Probe-Lampe** „Loxone Probe“: bleibt mit eigenem Haken (ab Werk an, damit ein Update nichts wegnimmt).
* **Einbindung in Loxone:** Baustein-Liste und zwei Vorlagen – die Eingänge des MQTT-Gateways je Lampe und ein
  virtueller Ausgang an den UDP-Eingang des Gateways für die Rückmeldung.
* **Reiter Test:** je Lampe Zustand, letzte Abfrage und Schaltung (Zeit, Echo-Adresse, Wert, MQTT gesendet oder
  nicht), abgewiesene Echos, Lage der Rückmeldung.
* Die Lampenliste steht in der Sicherung; Sicherungen von 0.9.4 und 0.9.5 bleiben gültig.
* **Korrekturen:** Der Reiter Test schreibt „„Anlegen“ läuft seit 33 s (Schritt: Abbild)“ statt „seit vor 33 s
  (Schritt abbild)“, ebenso alle Sätze dieser Art (auch „noch gültig für etwa …“ bei der Amazon-Anmeldung). Der
  Container protokolliert in der Zeitzone des Plugins statt in UTC. Der Messstand schreibt die Absender immer in
  derselben Form. Nach einem neu gebauten Abbild entfernt das Plugin seine alten eigenen Abbilder
  (`lb-alexang-hue-php:*`, nie ein fremdes, nie eines, das ein Container noch benutzt).

## Fassung 0.9.5

Hue-Probe auf eigener Netzadresse (Vorabfassung, ab Werk aus; nie einzeln veröffentlicht, enthalten in 0.9.6).

* **Warum:** Am 02.10.2026 suchte ein Echo die Hue-Probe von 0.9.4, bekam Antwort und holte `description.xml` auf
  Port 8380 – die Lampen fragte es aber nie ab. Es braucht die Bridge auf Port 80, und den hält der Webserver des
  LoxBerry.
* **Neue Art „eigene Netzadresse (Docker)“** im Reiter Einstellungen: das Plugin legt einen Container
  `lb-alexang-hue` mit eigener IP im Heimnetz an (Netz `lb-alexang-macvlan`, macvlan an `eth0` oder einer anderen
  gewählten Schnittstelle) und hängt ihn zusätzlich an das Docker-Netz `bridge`, damit er den MQTT-Broker auf dem
  LoxBerry erreicht. Die Nachbildung lauscht dort auf Port 80 und 1900. Apache, Webport und Loxone-Adressen bleiben
  unberührt; keine Datei des LoxBerry wird geändert.
* **Eigene IP:** Pflicht bei dieser Art, ab Werk leer. Sie muss im Netz der Schnittstelle liegen, darf nicht `.0`,
  `.255`, die Adresse des LoxBerry oder die des Routers sein und muss frei sein (beim Speichern mit ping und ARP
  nachgesehen); sonst wird sie beanstandet, und nichts wird gespeichert. In der Fritzbox außerhalb des DHCP-Bereichs
  wählen oder reservieren. Vom LoxBerry selbst ist die eigene Adresse nicht erreichbar (macvlan) – das ist normal.
* **Im Container** läuft nur der eigene Dienst des Plugins auf dem offiziellen Abbild `php:8.4-cli` (mit sockets und
  pcntl, beim ersten Mal am LoxBerry gebaut – Internet nötig, einige Minuten), als Benutzer des Plugins, ohne
  Fähigkeiten, mit nur lesendem Dateisystem und `--restart unless-stopped`.
* Anlegen, Neuanlegen und Entfernen laufen nach dem Speichern im Hintergrund; der Takt sieht alle 5 Minuten nach und
  legt höchstens alle 10 Minuten neu an. Ohne Haken oder bei Art „auf dem LoxBerry“ werden Container und Netz
  entfernt; das Abbild bleibt.
* Reiter Test: Docker, Abbild, Netz, Container, letzter Vorgang und eine Selbstprobe über die Brückenadresse des
  Containers; jeder Fehler mit eigenem Grund.
* Deinstallation und Update entfernen Container und Netz und sagen es im Installationsprotokoll.

## Fassung 0.9.4

Hue-Probe, Radio-Stopp über Radiotasten, Geräte austragen (Vorabfassung).
Gemessen mit der Amazon-Attrappe unter PHP 7.4, 8.3 (WSL) und 8.5; die Hue-Probe nur in einem eigenen Netz-Namensraum
(WSL, ohne Weg nach außen) gegen eine Echo-Attrappe. Am Gerät noch nicht.

* **Hue-Probe** (Vorstufe zu Fassung 2 „Steuerung“, ab Werk aus, nicht am Gerät erprobt): Haken „Hue-Probe (nicht am
  Gerät erprobt)“ im Reiter Einstellungen. Ein eigener Dienst beantwortet die Suche nach einer Hue-Bridge (SSDP, UDP 1900,
  nur als Antwort an den Suchenden) und stellt auf Port 8380 (einstellbar 1024–65535, nicht 80) eine einzige Lampe
  „Loxone Probe“ bereit. Schaltet ein Echo sie, geht nur `alexang/hue_probe/ein` (1/0, flüchtig) über MQTT hinaus –
  keine Verbindung zu Loxone-Steuerungen. Der Reiter Test zeigt, ob der Dienst läuft und antwortet, ob eine Suche
  angekommen ist (Absender-IP, Zeit) und ob ein Echo die Beschreibung geholt, die Lampe abgefragt oder geschaltet hat.
  Ehrlich: neuere Echo-Geräte unterstützen die lokale Hue-Suche zum Teil nicht mehr – genau das misst die Probe.
* **Radio je Zone:** `nr=0` hält die Zone an (Antwort `STOPP=1`) – Radiotasten in Loxone senden 0, wenn keine Taste
  gewählt ist; ein eigener Stopp-Taster ist nicht mehr nötig. Über MQTT gilt dasselbe für die Nutzlast `0`. Jede
  Stopp-Antwort trägt jetzt `STOPP=1`, auch bei `zone=alle`.
* **Senderliste** mit eigener Überschrift „Sender (für Musik-Probe und Radio je Zone)“; sie braucht keinen der Haken.
* **Musik-Probe bei 429** wie Radio: kein zweiter Versuch, 503 `GRUND=AMAZON_RATE;WARTE=60`, danach 60 s Musik-Pause
  für Musik-Probe und Radio (429 `GRUND=AMAZON_PAUSE`); Ansagen gehen weiter.
* **Verschwundene Geräte:** Knopf „austragen“ im Reiter Geräte (mit Haken zur Bestätigung) – der Normalname wird frei.
  Ein Aufruf an ein verschwundenes Gerät antwortet mit 404 `GRUND=GERAET_VERSCHWUNDEN` statt `GERAET_UNBEKANNT`.
* **Einbindung in Loxone:** die Baustein-Liste nennt jetzt auch die Vorlage „Radio je Zone“ (virtueller Ausgang, Befehle
  Sender/Stopp/Lautstärke, Radiotasten).
* Deinstallation und Update halten die Hue-Probe an.

## Fassung 0.9.3

* **Radio je Zone** (Stufe 3, ab Werk aus, mehrere Zonen nicht am Gerät erprobt): Zonentabelle 1–24 im Reiter
  Einstellungen, je Zeile `Nummer = Ziel` – ein Echo (Normalname), eine Mehrraum-Musikgruppe der Alexa-App
  (`amazon:<name>`, als Ganzes) oder eine eigene Gruppe (`gruppe:<name>`, jedes Gerät einzeln). Gespielt wird ein
  Sender nach Nummer aus der Senderliste der Musik-Probe.
* Befehle nur mit dem Aktionstoken: `aktion=radio&zone=<n|alle>&nr=<sender>`, `aktion=radio_stopp&zone=<n|alle>`,
  `aktion=radio_laut&zone=<n|alle>&wert=0..100`. Antwort `RADIO;OK=…;ZONE=…;NR=…;GERAET=…`, Fehler mit `GRUND`
  (404 unbekannte Zone oder Sender, 409 aus, 429 Grenze, 503 beschäftigt). `zone=alle` spielt in allen Zonen
  denselben Sender, nacheinander mit mindestens 1 s Abstand; je Zone ein Aufruf gibt jeder Zone ihren eigenen Sender.
* Über MQTT (mit eingeschaltetem Befehlsabo): `alexang/befehl/radio/<zone>` mit Nummer oder `stopp`,
  `alexang/befehl/radio/<zone>/laut`. Je Zone flüchtig `alexang/radio/<zone>/sender` und `…/zustand` – das, was
  zuletzt bestätigt gesendet wurde, nicht, was der Echo spielt.
* Bremsen: derselbe Sender, ein Stopp oder dieselbe Lautstärke in derselben Zone binnen 60 s ergibt
  `UNVERAENDERT=1`; die Musik-Stundengrenze gilt für alle Zonen zusammen und für den ganzen Befehl; antwortet Amazon
  mit 429, hält das Plugin an (`GRUND=AMAZON_RATE`, `OFFEN=n`) und nimmt 60 s lang keine Radiobefehle an
  (429 `GRUND=AMAZON_PAUSE`) – kein zweiter Versuch.
* Die Sperre aus Loxone und die Ruhezeit gelten für Radio nicht.
* Reiter Einbindung in Loxone: Schritt 10 mit allen Adressen je Zone und der Vorlage „Radio je Zone“ (virtueller
  Ausgang: je Zone Sender, Stopp, Lautstärke, dazu alle Zonen; trägt das Aktionstoken).
* Reiter Test: Knöpfe „Zone abspielen“ und „Zone stoppen“ mit Auswahl von Zone und Sender, dazu je Zone eine Zeile
  mit dem zuletzt bestätigt gesendeten Zustand und dem letzten Befehl.
* Die Selbstprüfung „Sind die Vorlagen wohlgeformt?“ prüft jetzt alle drei Vorlagen.

## Fassung 0.9.2

Gemessen mit der Amazon-Attrappe unter PHP 7.4, 8.3 (WSL) und 8.5; am Gerät noch nicht.

* **Sperre aus Loxone** (ab Werk aus): `aktion=sperre&wert=1|0` mit dem Aktionstoken oder MQTT
  `alexang/befehl/sperre`. Gesperrt werden Ansagen und Ankündigungen übersprungen (`GRUND=GESPERRT`),
  `dringend=1` geht durch, Lautstärke, Routinen und Musik-Probe gelten weiter. Nach einem Update gilt „offen“,
  bis Loxone sie neu setzt; der Reiter Test zeigt den Zustand.
* **Hinweisbalken** über allen Reitern, wenn die Amazon-Anmeldung abgelaufen ist oder der Takt steht.
* **Wer hat etwas ausgelöst?** Reiter Test: je Weg, Adresse und Absender nur Zähler (heute, gesamt,
  gesendet, übrige nach Grund); Plugins nennen sich mit `absender=<name>`.
* **Verschwundene Echos** bleiben im Reiter Geräte sichtbar („verschwunden seit …“), mit dem Hinweis, wo der
  Name noch steht (Standardgerät, Gruppen, Ausgabeart „Alexa-NG“ der fünf Plugins).
* **Sprachsichere Namen:** Hilfe und README erklären, welche Namen sich eignen.
* **Routinen:** eigenes Feld „Routinen, die Loxone starten darf (z. B. Radio an)“ unter der Überschrift
  Routinen; „Routinen bei Amazon anzeigen“ im Reiter Geräte (nur Namen und Sprachauslöser);
  „Freigegebene Routine jetzt starten“ im Reiter Test.
* **Musik-Probe** (Stufe 3, ab Werk aus, nicht am Gerät erprobt): `aktion=musik_probe` mit `nr=` aus einer
  Senderliste (Hauptweg) oder `sender=`, `aktion=musik_stopp`, beide nur mit dem Aktionstoken; eigene
  Stundengrenze (ab Werk 30); Knöpfe im Reiter Test. Inoffizielle Schnittstelle, kein beliebiger Stream,
  keine eigene MP3.
* „Einstellungen sichern“ warnt, wenn ein gespeicherter Wert beim Lesen abgewiesen wurde (Feld `_warnung`
  und gelber Kasten am Knopf).

## Fassung 0.9.1

Vorabfassung. Nachbesserungen nach den ersten Messungen am echten Konto (01.10.2026).
Gemessen mit einer Amazon-Attrappe unter PHP 7.4 und 8.5; Anmeldung und Ansage sind am echten Konto belegt (0.9.0).

* **Aus anderen Plugins:** Die README beschreibt jetzt die Ausgabeart „Alexa-NG“
  (POST `aktion=sprechen`, Sprechtoken wie ein Kennwort, nie in einer Adresse)
  und nennt die Plugins, die sie schon haben: Sprachsteuerung lokal ab 0.11.12,
  Spotpreis Octopus ab 1.1.18, Abfuhrkalender (AWM & iCal) ab 1.4.17,
  Abfahrts-Assistent ab 1.6.19, FerienFeiertage ab 1.2.18. Die URL-Vorlage steht
  nur noch als Rückfall für fremde Plugins da.
* **Testansage ohne Standardgerät:** Im Reiter Test lässt sich das Gerät jetzt
  auswählen (bei nur einem Gerät vorausgewählt). Ohne Geräteliste bleibt der
  Knopf gesperrt und nennt den Grund.
* Selbstprüfung: Die Zeile „Läuft das Befehlsabo?“ erklärt, dass der
  MQTT-Befehlseingang ab Werk aus ist – der graue Punkt ist kein Fehler.
* Neue Symbole (vom Hausherrn).

## Fassung 0.9.0 — Fassung 1 „Ansagen“

Die erste Fassung. Sie kann:

* **Sprechen** (`Alexa.Speak`) an ein Gerät, mehrere Geräte, eine eigene
  Gruppe, eine Amazon-Gruppe oder alle — mehrere Geräte in **einem** Aufruf
  an Amazon, parallel.
* **Lautstärke** setzen; mit `laut=` wird sie nur für die Ansage gesetzt und
  danach auf den vorherigen Wert zurückgestellt.
* **Ankündigen** (`AlexaAnnouncement`) — ab Werk **aus**, weil am Gerät
  unerprobt.
* **Routinen** starten — nur aus einer Freigabeliste (ab Werk leer) und nur
  mit dem Aktionstoken.
* Lange Texte (bis 1000 Zeichen) an Satzgrenzen auf mehrere Teile verteilen;
  SSML nur mit ausdrücklichem Schalter.
* Eine **Befehlsbremse**: derselbe Text an dasselbe Gerät innerhalb von 30 s
  wird nicht wiederholt, höchstens 60 Befehle je Stunde (einstellbar 10–240),
  wahlweise ein Mindestabstand und eine Ruhezeit.
* **MQTT** hinaus (Zustand, Geräte, letzte Ansage) und — ab Werk aus — ein
  Befehlseingang über MQTT.

Nachgebessert am 01.10.2026 nach der ersten Messung am Gerät (die Fassung
bleibt 0.9.0):

* Im Reiter Test steht neben dem gesperrten Knopf „Testansage an –“ der
  Grund (kein Standardgerät) mit einem Verweis auf den Reiter Einstellungen.
* Gibt es genau ein sprechfähiges Gerät und noch kein Standardgerät, ist es
  im Reiter Einstellungen vorausgewählt; gespeichert wird es erst mit
  „Speichern“.
* „Zuletzt von Amazon bestätigt“ rückt mit jeder erfolgreichen Antwort von
  Amazon vor (Ansage, Geräteliste, Cookie-Erneuerung), nicht nur mit der
  Statusprüfung alle 30 Minuten.

Nicht in dieser Fassung: Alexa → Loxone (Sprachbefehle ins Haus, geplant als
Fassung 2 über eine Hue-Nachbildung), andere Amazon-Länder als amazon.de.

## Wie es funktioniert

Amazon bietet für Ansagen keine offene Schnittstelle an. Das Plugin spricht
dieselbe Web-Schnittstelle wie die Alexa-Webseite: Mit einem
**Erneuerungs-Token** (`Atnr|…`) holt es bei Bedarf einen Satz Sitzungscookies
(höchstens 24 h alt, bei einer Ablehnung durch Amazon sofort neu) und schickt
jede Ansage als Befehlsfolge an `alexa.amazon.de/api/behaviors/preview`.

```
Loxone / Plugin ─ HTTP ─ Alexa NG (LoxBerry) ─ HTTPS ─ alexa.amazon.de ─ Echo
```

Befehle gehen unter einer Sperre nacheinander hinaus, mit mindestens 1 s
Abstand; wer länger als 8 s auf die Sperre warten müsste, bekommt
`503 GRUND=BESCHAEFTIGT`. Ein Takt alle fünf Minuten prüft alle 30 Minuten die
Anmeldung, holt alle 6 Stunden die Geräteliste und sendet das Lebenszeichen.

## Einrichten

1. Plugin installieren. Der Reiter **Einstellungen** öffnet sich; dabei
   werden die beiden Token (Sprechtoken, Aktionstoken) angelegt.
2. Im Reiter **Amazon-Anmeldung** anmelden (siehe unten).
3. Im Reiter **Geräte** „Geräteliste neu holen“. Jedes sprechfähige Gerät
   bekommt einen **Normalnamen** (klein, ä→ae, ö→oe, ü→ue, ß→ss, sonst `_`),
   z. B. „Küche Echo“ → `kueche_echo`. Diesen Namen verwenden Loxone und die
   Plugins. Zwei gleiche Namen bekommen `_2`; ein vergebener Normalname wird
   nie umbenannt.
4. Im Reiter **Einstellungen** ein **Standardgerät** wählen und bei Bedarf
   eigene Gruppen anlegen (`unten = kueche_echo,wohnzimmer`).
5. Im Reiter **Geräte** eine **Testansage** schicken. Im Reiter **Test** geht
   die Testansage an das Standardgerät; ist keines eingestellt, wählt man das
   Gerät dort aus der Geräteliste (ohne Geräteliste bleibt der Knopf gesperrt
   und die Seite sagt, warum).
6. Im Reiter **Einbindung in Loxone** die Vorlagen herunterladen
   (`VI_alexang.xml` für die Statuszeile, `VQ_alexang.xml` für die Ansagen)
   und in Loxone Config importieren. Die dort gezeigten Adressen enthalten das
   Sprechtoken bereits.
7. Im Reiter **Test** nachsehen: jede Zeile ist eine Frage; ein Haken nur für
   Gemessenes, ein Strich heißt „nicht feststellbar“, ein grauer Punkt
   „ausgeschaltet“. Ab Werk sind das Befehlsabo (Befehle über MQTT) und die
   Sperre aus Loxone ausgeschaltet; die Zusammenfassung zählt sie als
   „2 ausgeschaltet“, das ist kein Fehler.

Ist die Amazon-Anmeldung abgelaufen oder steht der Takt länger als
15 Minuten, steht über **jedem** Reiter ein roter Hinweisbalken mit dem Weg zur
Behebung.

### Sprachsichere Namen

* Für Ansagen aus Loxone und den Plugins zählt der **Normalname**, nicht die
  Aussprache. Gut sind kurze, eindeutige Namen, die nur ein Gerät meinen
  (`kueche`, `flur_oben`).
* Endet ein Normalname auf `_2`, heißen bei Amazon zwei Geräte gleich: dort
  eines umbenennen und die Geräteliste neu holen. Ein vergebener Normalname
  bleibt dabei stehen; `alle` und `gruppe` sind vergeben.
* Meldet Amazon ein Gerät nicht mehr (getauscht, entfernt), bleibt es im
  Reiter **Geräte** als „verschwunden seit …“ stehen. Darunter steht, wo sein
  Name noch eingetragen ist: Standardgerät, eigene Gruppen und die
  Ausgabeart „Alexa-NG“ der Plugins Sprachsteuerung lokal, Octopus Dynamic,
  Abfuhrkalender, Abfahrts-Assistent und Ferien und Feiertage (soweit ihre
  Konfiguration lesbar ist; gelesen wird nur das Gerätefeld). Mit dem Knopf
  **„austragen“** (und dem Haken zur Bestätigung) verschwindet die Zeile, und
  der Normalname ist wieder frei; bis dahin antwortet ein Aufruf an das Gerät
  mit 404 `GRUND=GERAET_VERSCHWUNDEN`. Ein Gerät, das Amazon noch meldet, lässt
  sich nicht austragen.
* Für den Start einer Routine aus Loxone zählt nur der Name in der Freigabe.
  Spricht man eine Routine auch selbst, Sprachauslöser **ohne** Musik-,
  Sender- oder Genrewörter wählen (nicht „Radio an“, sondern z. B.
  „Loxone Abendlicht“) – sonst fängt Alexa sie als Musikwunsch ab.

### Amazon-Anmeldung Schritt für Schritt

Das Plugin sieht **nie** Ihr Amazon-Kennwort, Ihre E-Mail-Adresse oder einen
Zwei-Faktor-Code. Es gibt keinen Anmelde-Proxy. Gespeichert wird nur das
Erneuerungs-Token, in `amazon.json` mit den Rechten 0600; angezeigt wird es nie
wieder.

**Weg (b): Anmeldung im eigenen Browser — der Hauptweg** *(am echten Konto
erprobt am 01.10.2026)*

1. Reiter **Amazon-Anmeldung** → **„Anmeldung vorbereiten“**. Das Plugin
   erzeugt einen Einmalschlüssel (PKCE) und eine Geräte-Seriennummer; beides
   gilt 30 Minuten.
2. **„Anmeldeseite bei Amazon öffnen“**. Es öffnet sich ein neues Fenster
   direkt bei `www.amazon.de`. Dort melden Sie sich an — mit Ihrem Kennwort,
   Ihrer Zwei-Faktor-Bestätigung und gegebenenfalls einer Bot-Prüfung. Das
   alles bleibt zwischen Ihrem Browser und Amazon.
3. Danach zeigt Amazon eine **leere oder fehlerhafte Seite**. Das ist
   erwartet. Ihre Adresse beginnt mit `https://www.amazon.de/ap/maplanding`
   und enthält `openid.oa2.authorization_code=…`. Die **ganze Adresse**
   kopieren. (Steht sie nicht in der Adresszeile: F12 → Netzwerk → die
   Anfrage `maplanding` → Adresse kopieren.)
4. Zurück im Plugin die Adresse (oder nur den Code) in **„Umleitungsadresse
   oder Code“** einfügen und **„Code einlösen“** drücken — zügig, der Code
   gilt vermutlich nur wenige Minuten.
5. Das Plugin tauscht den Code bei Amazon gegen ein Erneuerungs-Token und
   prüft es sofort. Die Einmalmeldung sagt „Angemeldet“ oder nennt den Grund.
   Der Code wird weder gespeichert noch protokolliert.
6. Im Amazon-Konto erscheint ein Gerät **„LoxBerry Alexa-NG“**; dort lässt
   sich die Anmeldung auch widerrufen.

**Weg (a): Token einfügen — der Rückfall**

Nimmt Amazon den Weg (b) nicht an, erzeugen Sie ein Erneuerungs-Token
`Atnr|…` mit einem Werkzeug Ihrer Wahl auf Ihrem Rechner (Alexa2Lox nennt
`alexa-cookie-cli`) und fügen es im Feld **„Erneuerungs-Token“** ein. Das
Plugin prüft es sofort bei Amazon und speichert es **nur**, wenn Amazon es
annimmt. Ein leeres Feld löscht nichts.

**Abmelden:** „Bei Amazon abmelden und Token löschen“ (mit Rückfrage) meldet
das Gerät bei Amazon ab und löscht das Token. Scheitert die Abmeldung, bleibt
das Token stehen; mit dem Haken „Nur hier löschen“ wird es trotzdem entfernt —
dann das Gerät im Amazon-Konto unter *Geräte* von Hand austragen.

**Abgelaufen:** Lehnt Amazon das Token ab, antwortet der Endpunkt
`503 GRUND=ANMELDUNG_ABGELAUFEN`, `status/anmeldung` geht auf 0, der Reiter
Test wird rot, und es kommt **genau eine** LoxBerry-Benachrichtigung. Dann
erneut anmelden. Ein Ablaufdatum liefert Amazon nicht; die Seite nennt
deshalb nur „zuletzt von Amazon bestätigt“.

## Endpunkte

Adresse: `http://<loxberry>/plugins/alexang/`. GET und POST (lange Texte per
POST). Antwort `text/plain`, jede Antwort nennt `GRUND`.

Ohne Token, lesend:

| Aufruf | Antwort |
|---|---|
| `?aktion=status` (auch ohne `aktion`) | `ALEXANG;OK=1;ANMELDUNG=1;GERAETE=5;ONLINE=4;ALTER=37;ZAEHLER=512;LETZTE_OK=1;LETZTE_ALTER=842`; `OK=0`, wenn das Lebenszeichen älter als 15 min ist; ohne Anmeldung **503** `GRUND=ANMELDUNG` |
| `?aktion=geraete` | eine Zeile je Gerät, `GERAET;NAME=kueche;FAMILIE=ECHO;ONLINE=1;LAUT=35` — **ohne** Seriennummer |

Mit Token (`T` = Sprech- **oder** Aktionstoken, `A` = nur Aktionstoken):

| Aufruf | Token | Wirkung |
|---|---|---|
| `?selftest=1&token=T` | T | `SELFTEST;OK=1;TOKEN=OK` — prüft nur das Token, kein Amazon-Kontakt |
| `?aktion=sprechen&token=T&geraet=…&text=…[&laut=0-100][&ssml=1][&dringend=1]` | T | Ansage; `SPRECHEN;OK=1;GERAETE=2;TEILE=1;UNVERAENDERT=0` |
| `?aktion=ankuendigen&token=T&geraet=…&text=…[&titel=…]` | T | Ankündigung; ab Werk 409 `GRUND=ANKUENDIGEN_AUS` |
| `?aktion=lautstaerke&token=T&geraet=…&wert=0-100` | T | Lautstärke setzen |
| `?aktion=routine&token=A&name=…[&geraet=…]` | A | freigegebene Routine; mit Sprechtoken 403 |
| `?aktion=geraete&json=1&token=A` | A | Geräteliste als JSON mit Seriennummer und Typ (Fehlersuche) |
| `?aktion=sperre&token=A&wert=1` bzw. `wert=0` | A | Sperre aus Loxone setzen bzw. aufheben; `SPERRE;OK=1;GESPERRT=1;UNVERAENDERT=0`; ab Werk 409 `GRUND=SPERRE_AUS` |
| `?aktion=musik_probe&token=A&geraet=…&nr=1-50` | A | Musik-Probe mit dem Sender Nummer `nr` aus der Senderliste; `MUSIK;OK=1;GERAET=kueche;ANBIETER=tunein;NR=1;SUCHE=EIGEN;UNVERAENDERT=0`; ab Werk 409 `GRUND=MUSIK_AUS` |
| `?aktion=musik_probe&token=A&geraet=…&sender=…[&anbieter=tunein\|amazon]` | A | dasselbe mit einem Sendernamen (Zusatz), Anbieter ab Werk `tunein` |
| `?aktion=musik_stopp&token=A&geraet=…` | A | Musik anhalten; `MUSIK;OK=1;GERAET=kueche;STOPP=1` |
| `?aktion=radio&token=A&zone=1-24\|alle&nr=0-50` | A | Radio je Zone: Sender Nummer `nr` aus der Senderliste in der Zone; `RADIO;OK=1;ZONE=2;NR=5;GERAET=kueche;ANBIETER=tunein;SUCHE=EIGEN;UNVERAENDERT=0;OFFLINE=0`; `nr=0` hält die Zone an wie `radio_stopp` (`STOPP=1`); ab Werk 409 `GRUND=RADIO_AUS` |
| `?aktion=radio_stopp&token=A&zone=1-24\|alle` | A | Radio der Zone anhalten; `RADIO;OK=1;ZONE=2;NR=0;GERAET=kueche;STOPP=1;UNVERAENDERT=0;OFFLINE=0` |
| `?aktion=radio_laut&token=A&zone=1-24\|alle&wert=0-100` | A | Lautstärke der Zone; `RADIO;OK=1;ZONE=2;WERT=30;GERAET=kueche;UNVERAENDERT=0;OFFLINE=0` |

**`geraet`** ist eine Kommaliste aus Normalnamen, `gruppe:<name>` oder `alle`;
der Amazon-Anzeigename wird ebenfalls angenommen. Ohne `geraet` gilt das
Standardgerät. Ein unbekannter Name ergibt **404** `GRUND=GERAET_UNBEKANNT` —
nie einen Rückfall auf „alle“. Gehört der Name einem verschwundenen Gerät
(Amazon meldet es nicht mehr), heißt der Grund `GERAET_VERSCHWUNDEN`, ebenfalls
mit 404. Geräte, die offline sind, werden ausgelassen
und gezählt (`OFFLINE=n`); sind alle offline, 503.

**`text`**: 1–1000 Zeichen UTF-8, ohne Steuerzeichen. `text=0` oder ein
leerer Text ergibt 200 `UEBERSPRUNGEN=1` (für den Statusbaustein). `<` oder
`>` nur mit `ssml=1`, und dann `<speak>…</speak>` mit höchstens 250 Zeichen.
**`dringend=1`** übergeht die Ruhezeit, nicht die Bremse.

**Antwortcodes:** 200 gesendet, übersprungen oder unverändert · 400 Parameter
falsch · 403 Token · 404 Gerät unbekannt · 409 Plugin aus bzw. nicht
freigegeben · 429 Bremse (`BREMSE;WARTE=s`, `STUNDENGRENZE`) · 503 Anmeldung
fehlt oder abgelaufen, Amazon gestört (`AMAZON`, `AMAZON_RATE`, `NETZ`,
`AMAZON_UNERWARTET`), beschäftigt · 500 interner Fehler.

Nach 20 Fehlversuchen mit dem Token je Absender und Stunde ist der Absender
eine Stunde gesperrt.

**`absender=<name>`** (freiwillig, `a–z`, `0–9`, `_`, `-`, höchstens 32
Zeichen) nennt das aufrufende Plugin. Der Reiter **Test** zählt je Weg
(HTTP, MQTT, Oberfläche), Adresse und Absender: ersten und letzten Aufruf,
heute, gesamt, davon gesendet, die übrigen nach Grund — nur Zähler, nie ein
Text oder Token. Ein ungültiger Name ergibt 400 `GRUND=ABSENDER`.

### Sperre aus Loxone

Ab Werk aus (Reiter **Einstellungen**, Haken „Sperre aus Loxone annehmen“).
Loxone setzt sie etwa bei Abwesend, Gäste oder Schlafen:
`?aktion=sperre&token=<Aktionstoken>&wert=1`, aufheben mit `wert=0`, oder über
MQTT `alexang/befehl/sperre` mit `1`/`0` (Befehlseingang nötig). Gesperrt
antworten `sprechen` und `ankuendigen` mit `200 UEBERSPRUNGEN=1;GRUND=GESPERRT`;
`dringend=1` geht durch. Lautstärke, Routinen und die Musik-Probe gelten
weiter. Der letzte Wert bleibt erhalten; nach einem Update ist er weg (er liegt
im Datenordner), dann gilt „offen“, bis Loxone ihn neu setzt — der Reiter
**Test** zeigt das als gelbe Zeile. Die vollständigen Adressen stehen im Reiter
**Einbindung in Loxone**.

### Routinen

Das Feld **„Routinen, die Loxone starten darf (z. B. Radio an)“** steht im
Reiter **Einstellungen** unter der eigenen Überschrift **Routinen**; je Zeile
ein Name oder Sprachauslöser, genau wie in der Alexa-App. Die Namen zeigt der
Reiter **Geräte** mit **„Routinen bei Amazon anzeigen“** — nur Name und
Sprachauslöser, über dieselbe Abfrage wie der Start, ohne Inhalt und ohne
Kennungen; die Spalte „freigegeben“ sagt, was schon eingetragen ist. Im Reiter
**Test** startet **„Freigegebene Routine jetzt starten“** eine Routine aus der
Liste auf einem gewählten Gerät; die Antwortzeile steht in der Meldung, ein
Neuladen der Seite löst nichts aus.

### Musik-Probe (Stufe 3)

Ab Werk aus (Reiter **Einstellungen**, Haken „Musik-Probe erlauben (an einem
Echo gemessen)“). Sie spielt einen Sender über die Suche des Echos
(`Alexa.Music.PlaySearchPhrase`) — eine **inoffizielle** Schnittstelle; an
einem Echo gemessen (02.10.2026, TuneIn, Ton nach etwa 3 s). Gespielt wird nur, was Amazon selbst bei TuneIn oder
Amazon Music findet: **kein beliebiger Stream, keine Adresse, keine eigene
MP3**.

* **Senderliste** (Reiter Einstellungen, eigene Überschrift „Sender (für
  Musik-Probe und Radio je Zone)“; sie gilt für beide und braucht keinen der
  Haken): je Zeile `Nummer = Sendername |
  anbieter`, Nummer 1–50, `anbieter` `tunein` oder `amazon` (ohne Angabe
  `tunein`). Hauptweg ist `nr=<Nummer>` — passend zu den Radiotasten 1–16 in
  Loxone; `sender=<Name>` ist der Zusatz. Eine unbekannte Nummer ergibt 404
  `GRUND=SENDER_UNBEKANNT`, `nr` und `sender` zugleich 400.
* **Ziel:** genau ein Gerät oder eine **Amazon-Gruppe** (Mehrraum-Musikgruppe
  der Alexa-App; sie wird als Ganzes angesprochen). Eine Kommaliste, `alle`
  und eigene Gruppen werden abgewiesen (`GRUND=EIN_ZIEL`).
* **Bremse:** eigene Stundengrenze (ab Werk 30, 10–240, getrennt von den
  Ansagen, darüber 429 `GRUND=MUSIK_STUNDENGRENZE`), derselbe Sender am
  selben Gerät innerhalb der Wiederholbremse ergibt `UNVERAENDERT`, eine
  besetzte Sperre 503 `BESCHAEFTIGT`.
* **429 von Amazon:** wie bei Radio je Zone kein zweiter Versuch. Die Antwort
  ist 503 `GRUND=AMAZON_RATE;WARTE=60`, und 60 s lang antworten Musik-Probe und
  Radio mit 429 `GRUND=AMAZON_PAUSE` (eine gemeinsame Musik-Pause); Ansagen
  gehen weiter.
* Vor dem Abspielen lässt das Plugin die Suchphrase von Amazon prüfen;
  `SUCHE=AMAZON` heißt, Amazon hat sie bereinigt, `SUCHE=EIGEN`, es galt die
  eigene Bereinigung.
* Im Reiter **Test**: Knöpfe **„Musik-Probe“** und **„Musik stoppen“** mit
  Auswahl aus der Senderliste und des Geräts.

### Radio je Zone (Stufe 3, mehrere Zonen nicht am Gerät erprobt)

Ab Werk aus (Reiter **Einstellungen**, Haken „Radio je Zone erlauben“). Es
baut auf dem direkten Musikbefehl der Musik-Probe auf; am Gerät belegt ist
ein Echo (Ton nach etwa 3 s). Mehrere Zonen gleichzeitig sind nur an einer
Amazon-Attrappe geprüft. Kein beliebiger Stream, keine Adresse, keine eigene
MP3.

* **Zonentabelle** (Reiter Einstellungen): je Zeile `Nummer = Ziel`, Nummer
  1–24. Ziel ist der Normalname eines Echos (`kueche`), `amazon:<name>` für
  eine Mehrraum-Musikgruppe der Alexa-App (als Ganzes angesprochen) oder
  `gruppe:<name>` für eine eigene Gruppe (jedes Gerät einzeln, nicht
  synchron). Eine unlesbare Zeile oder eine eigene Gruppe, die es nicht gibt,
  wird beanstandet; gespeichert wird dann nichts. Ein Ziel, das die
  Geräteliste nicht kennt, ergibt nach dem Speichern einen Hinweis und am
  Endpunkt 404.
* **Sender** nach Nummer aus der Senderliste (`nr=1-50`). **`nr=0` hält die
  Zone an** (wie `radio_stopp`, Antwort `STOPP=1`): Radiotasten in Loxone
  senden 0, wenn keine Taste gewählt ist – ein eigener Stopp-Taster ist dafür
  nicht nötig. Über MQTT gilt dasselbe für die Nutzlast `0`.
* **Alle gleich, jede anders:** `zone=alle` mit einem Sender — alle Zonen
  spielen denselben Sender; die Zonen gehen nacheinander mit mindestens 1 s
  Abstand hinaus. Je Zone ein eigener Aufruf gibt jeder Zone ihren eigenen
  Sender. Die Antwort auf `zone=alle` zählt:
  `RADIO;OK=1;ZONE=alle;NR=5;GERAET=alle;ANBIETER=tunein;SUCHE=EIGEN;ZONEN=3;GESENDET=3;UNVERAENDERT=0;OFFLINE=0;FEHLER=0`.
* **Bremsen:** derselbe Sender, ein Stopp oder dieselbe Lautstärke in
  derselben Zone binnen 60 s ergibt `UNVERAENDERT=1` und sendet nichts. Die
  Musik-Stundengrenze (ab Werk 30) gilt für alle Zonen zusammen und für den
  ganzen Befehl — reicht sie nicht, geht nichts hinaus (429
  `GRUND=MUSIK_STUNDENGRENZE`). Antwortet Amazon mit 429, hält das Plugin an:
  die übrigen Zonen werden nicht versucht (`OFFEN=n`), die Antwort sagt 503
  `GRUND=AMAZON_RATE;WARTE=60`, und 60 s lang antworten Radiobefehle und die Musik-Probe mit 429
  `GRUND=AMAZON_PAUSE`. Ein zweiter Versuch geschieht nicht.
* **Fehler:** 400 `ZONE`, `NR`, `WERT` · 404 `ZONE_UNBEKANNT`,
  `SENDER_UNBEKANNT`, `GERAET_UNBEKANNT`, `GERAET_VERSCHWUNDEN`, `GRUPPE_UNBEKANNT` · 409 `RADIO_AUS`
  · 429 `MUSIK_STUNDENGRENZE`, `AMAZON_PAUSE` · 503 `BESCHAEFTIGT`,
  `GERAETE_OFFLINE`, `AMAZON_RATE`, `AMAZON`, `NETZ`.
* **Sperre aus Loxone und Ruhezeit gelten für Radio nicht** (wie für die
  Musik-Probe).
* **Zustand:** Der Reiter **Test** zeigt je Zone, was zuletzt bestätigt
  gesendet wurde, und den letzten Befehl; über MQTT gehen flüchtig
  `alexang/radio/<zone>/sender` und `…/zustand`. „Bestätigt“ heißt: Amazon
  hat den Befehl angenommen — was der Echo tatsächlich spielt, meldet Amazon
  nicht.
* **Loxone:** Reiter **Einbindung in Loxone**, Schritt 10, mit allen Adressen
  je Zone und der Vorlage „Radio je Zone“ (virtueller Ausgang, je Zone
  Sender, Stopp und Lautstärke, dazu alle Zonen; trägt das Aktionstoken).
* Im Reiter **Test**: Knöpfe **„Zone abspielen“** und **„Zone stoppen“** mit
  Auswahl von Zone und Sender.

### Hue-Probe (Fassung 2)

Fassung 2 „Steuerung“ (Alexa schaltet Loxone, lokal ohne Cloud) soll eine
Hue-Bridge nachbilden. Gebaut wird sie erst, wenn gemessen ist, dass ein Echo
die Nachbildung erkennt. Diese Probe misst genau das. Ab Werk aus (Reiter
**Einstellungen**, Abschnitt Hue-Probe, Haken „Hue-Nachbildung
einschalten“).

* Mit Haken läuft ein eigener Dienst (`bin/ax_hue.php`, gestartet über
  `bin/hue_dienst.sh`; der Takt hält ihn ohne Haken an). Er beantwortet die
  Suche nach einer Bridge (SSDP auf UDP 1900, Gruppe 239.255.255.250) mit
  einer Antwort an den Suchenden und stellt auf einem eigenen Port (ab Werk
  **8380**, einstellbar 1024–65535) `description.xml` und die Hue-Schnittstelle
  `/api/<user>/lights` mit genau einer Lampe **„Loxone Probe“** bereit. Er sendet
  nie von sich aus ins Netz. Port 80 gehört dem LoxBerry-Webserver und bleibt
  unberührt.
* Schaltet ein Echo die Lampe, geht nur `alexang/hue_probe/ein` (`1`/`0`,
  flüchtig) über MQTT hinaus — **keine Verbindung zu Loxone-Steuerungen**.
* Ablauf: Haken setzen, Speichern (die Meldung sagt, ob der Dienst läuft); in
  der Alexa-App „Geräte suchen“ (Philips Hue) oder „Alexa, suche Geräte“;
  danach „Alexa, schalte Loxone Probe ein“; im Reiter **Test**, Abschnitt
  Hue-Probe, nachsehen: ob eine Suche angekommen ist (Absender-IP, Zeit), ob
  ein Echo die Beschreibung geholt, die Lampe abgefragt oder geschaltet hat
  (Zähler).
* Ehrlich: Echo-Geräte der neueren Generation unterstützen die lokale
  Hue-Suche zum Teil nicht mehr, manche nur auf Port 80. Kommt keine Suche an
  oder bleibt die Abfrage aus, ist das ein Messergebnis, kein Fehler des
  Plugins.
* Braucht die PHP-Erweiterung **sockets** (für den Beitritt zur SSDP-Gruppe);
  fehlt sie, endet der Dienst mit `SOCKETS_FEHLT`, und der Reiter Test zeigt es.

#### Eigene Netzadresse (Docker, Entscheidung Nr. 41)

Gemessen am 02.10.2026: das Echo suchte, bekam Antwort und holte
`description.xml` auf Port 8380 – die Lampen fragte es aber nie ab. Es braucht
die Bridge auf **Port 80**, und den hält der Webserver des LoxBerry. Deshalb
gibt es im Abschnitt Hue-Probe die **Art**:

* **auf dem LoxBerry (Port wie bisher)** – ab Werk, wie in 0.9.4;
* **eigene Netzadresse (Docker)** – das Plugin legt einen Container
  `lb-alexang-hue` an, mit **eigener IP im Heimnetz** (Netz
  `lb-alexang-macvlan`, macvlan an der gewählten Schnittstelle, ab Werk
  `eth0`) und zusätzlich am Docker-Netz `bridge`, damit er den MQTT-Broker auf
  dem LoxBerry erreicht. Dort lauscht die Nachbildung auf **Port 80** (HTTP)
  und **1900** (SSDP); `LOCATION` und `URLBase` nennen die eigene IP. Apache,
  Webport, Loxone-Adressen und die Dateien des LoxBerry bleiben unberührt.

Einrichten: Haken „Hue-Probe“, Art „eigene Netzadresse (Docker)“, **eigene IP**
eintragen (Muster `192.168.178.x`), Speichern. Die IP muss im Netz der
Schnittstelle liegen, darf nicht `.0`/`.255`, nicht die Adresse des LoxBerry
und nicht die des Routers sein und muss frei sein (beim Speichern mit ping
und ARP nachgesehen); sonst wird sie beanstandet, und nichts wird gespeichert.
In der Fritzbox muss sie **außerhalb des DHCP-Bereichs** liegen oder dort
reserviert sein. **Vom LoxBerry selbst ist die eigene Adresse nicht erreichbar
(macvlan) – das ist normal**; der Reiter Test fragt den Container über seine
Brückenadresse.

* Im Container läuft nur der eigene Dienst dieses Plugins (`bin/ax_hue.php`
  mit `bin/ax_hue_container.php`, nur lesend eingehängt) auf dem offiziellen
  Abbild `php:8.4-cli` mit den Erweiterungen sockets und pcntl. Das Abbild
  `lb-alexang-hue-php:<prüfsumme>` baut das Plugin beim ersten Mal am LoxBerry
  aus `bin/hue_docker/Dockerfile` – dafür braucht es Internet und einige
  Minuten; die Meldung sagt dann, dass der Vorgang im Hintergrund weiterläuft.
* Der Container läuft als Benutzer des Plugins, ohne Fähigkeiten
  (`--cap-drop ALL`), mit nur lesendem Dateisystem und `--restart
  unless-stopped`. Messstand und seine Konfiguration liegen unter
  `data/plugins/alexang/hue/`, die Protokollzeilen im Protokoll des Plugins.
* Anlegen, Neuanlegen (andere IP oder Schnittstelle) und Entfernen laufen nach
  dem Speichern im Hintergrund; der Takt sieht alle 5 Minuten nach und legt
  höchstens alle 10 Minuten neu an. Ohne Haken oder bei Art „auf dem
  LoxBerry“ werden Container und Netz entfernt; das Abbild bleibt (von Hand:
  `docker image rm lb-alexang-hue-php:<prüfsumme>`, der Name steht in der
  Meldung).
* Gründe im Reiter Test, je mit eigenem Text: Docker fehlt, kein Zugriff auf
  Docker, Docker-Dienst aus, IP belegt, Netz anlegen gescheitert (zum Beispiel
  weil schon ein anderes macvlan-Netz an derselben Schnittstelle hängt), Abbild
  bauen gescheitert, fremder Container oder fremdes Netz gleichen Namens (bleibt
  unberührt).
* Deinstallation und Update entfernen Container und Netz (nachgesehen, mit
  Meldung im Installationsprotokoll).

#### Lampen für Alexa (Fassung 2)

Alexa schaltet Lampen, die Loxone steuert – lokal, ohne Cloud und ohne Skill. Die Nachbildung zeigt einem Echo
die Lampen der **Freigabeliste** (Reiter **Einstellungen**, Abschnitt „Alexa → Loxone“); ab Werk ist die Liste leer
und die Nachbildung aus. Am Echo gemessen (03.10.2026, eigene Netzadresse): die Probe-Lampe und Lampen der Liste
in allen drei Arten – siehe „Am Echo gemessen“ unter „Fassung 1.0.0“.

* **Je Lampe:** Name, wie Alexa ihn nennt (höchstens 32 Zeichen, eindeutig – Groß- und Kleinschreibung und Umlaute
  zählen gleich), Art **Schalter** (Steckdosen, Pumpen; die Alexa-App zeigt einen An-/Ausschalter), **Licht
  (an/aus)** (seit 0.9.7, für Lampen ohne Dimmen, damit Raumbefehle sie einschließen; die Alexa-App zeigt sie als
  Lampe, der Raumbefehl selbst ist nicht gemessen)
  oder **Dimmer**, Kürzel für das MQTT-Thema (`a`–`z`, `0`–`9`, `_`). Fehlt das
  Kürzel oder passt es nicht, wird es beanstandet, und ein Vorschlag aus dem Namen steht danach im Feld. Höchstens
  50 Lampen.
* **Tür, Tor, Garage, Alarm, Schloss** (auch `door`, `gate`, `lock`): solche Namen brauchen den Haken „bewusst
  freigeben“. Die Prüfung ist bewusst weit – auch „Monitor“ enthält „tor“; dann genügt der Haken.
* **Feste ID:** Jede Lampe behält ihre ID, auch beim Umbenennen; Alexa ordnet nach ihr. Umbenennen deshalb hier,
  speichern, in der Alexa-App „Geräte suchen“ – erst danach, falls nötig, den Namen in der Alexa-App ändern. Eine
  gelöschte ID wird nie wieder vergeben. ID 1 gehört der Probe-Lampe.
* **Art ändern:** Wird die Art einer Lampe geändert (Schalter, Licht, Dimmer), die Lampe in der Alexa-App löschen
  und danach „Geräte suchen“. Alexa merkt sich die Art je Gerät, in der App lässt sie sich nicht ändern (gemessen
  03.10.2026 am Echo).
* **Befehl an Loxone** (MQTT, flüchtig, nie retained):

  | Thema | Wert |
  |---|---|
  | `alexang/hue/<kürzel>/ein` | `1` ein, `0` aus |
  | `alexang/hue/<kürzel>/helligkeit` | nur Dimmer und nur, wenn Alexa eine Helligkeit setzt: `1`–`100` (Hue-Helligkeit 1–254, gerundet); `0` bei aus |

  „Alexa, Küche auf 50 Prozent“ sendet `ein` = `1` und `helligkeit` = `50`; „Alexa, schalte Küche ein“ sendet
  beim Dimmer nur `ein` = `1` (seit 0.9.7 – die Helligkeit wählt dann Loxone). Derselbe Wert binnen 2 s geht nur
  einmal hinaus; mehr als 10 Schaltbefehle je Lampe und Minute bekommen eine Fehlerantwort der Hue-Schnittstelle
  und stehen im Protokoll (mit Namen und Wert).
* **Zustand zurück:** Meldet Loxone `alexang/hue/<kürzel>/status` (`0`/`1`) und beim Dimmer
  `…/status_helligkeit` (`0`–`100`), zeigt Alexa den echten Zustand. Die Nachbildung abonniert beide Themen selbst
  (im Container über die Docker-Brücke). Ohne Rückmeldung gilt der zuletzt von Alexa gesetzte Wert.
* **Nur freigegebene Echos:** Im Feld „Nur diese Echos dürfen schalten“ stehen die IP-Adressen der Echos (Muster
  `192.168.178.x`, höchstens 20); leer heißt alle Geräte im Heimnetz. Anfragen anderer Adressen bekommen eine
  Fehlerantwort und werden im Reiter Test gezählt. `description.xml` und die Suche bleiben für alle offen – sie
  verraten keine Lampe.
* **Probe-Lampe** „Loxone Probe“ (eigener Haken, ab Werk an): bleibt neben den eigenen Lampen sichtbar, bis der
  Haken weg ist; sie schaltet weiter nur `alexang/hue_probe/ein`.
* **Einbindung in Loxone:** Die Eingänge legt das MQTT-Gateway beim ersten Schalten selbst an
  (`alexang_hue_<kürzel>_ein`, `…_helligkeit`; Abo `alexang/#`), oder die Vorlage **VI_alexang_hue.xml** legt sie
  an. Die Rückmeldung geht über einen virtuellen Ausgang an den UDP-Eingang des MQTT-Gateways
  (`/dev/udp/<LoxBerry>/<UDP-Eingang>`, Befehl `publish alexang/hue/<kürzel>/status 1`), Vorlage
  **VQ_alexang_hue.xml**. Die Baustein-Liste steht im Reiter „Einbindung in Loxone“.
* **Reiter Test:** je Lampe Zustand (mit Quelle Alexa oder Loxone), letzte Abfrage und letzte Schaltung (Zeit,
  Echo-Adresse, Wert), MQTT gesendet, nicht gesendet, wegen gleichen Werts unterdrückt oder gebremst; dazu die
  abgewiesenen Absender und die Lage des Abos für die Rückmeldung.

### Aus anderen Plugins

Diese Plugins haben die Ausgabeart **„Alexa-NG“** (dort ab Werk nicht
gewählt) und brauchen keine Vorlage:

| Plugin | ab Fassung |
|---|---|
| [Sprachsteuerung lokal](https://github.com/timanders22/LoxBerry-Plugin-Sprachsteuerung) | 0.11.12 |
| [Spotpreis Octopus](https://github.com/timanders22/LoxBerry-Plugin-Spotpreis-Octopus) | 1.1.18 |
| [Abfuhrkalender (AWM & iCal)](https://github.com/timanders22/LoxBerry-Plugin-AWM-Abfuhr) | 1.4.17 |
| [Abfahrts-Assistent](https://github.com/timanders22/LoxBerry-Plugin-Abfahrtsassistent) | 1.6.19 |
| [FerienFeiertage](https://github.com/timanders22/LoxBerry-Plugin-FerienFeiertage) | 1.2.18 |

Folgen sollen: Spotpreis aWATTar, Weissware, Robonect, Saugroboter-Valetudo.

Dort trägt man ein:

* **Gerät:** ein Normalname, eine Kommaliste, `gruppe:<name>` oder `alle`;
  leer = das Standardgerät von Alexa-NG.
* je nach Plugin eine **Lautstärke** (leer = bleibt).
* das **Sprechtoken** aus dem Reiter *Einstellungen* von Alexa-NG.

Das Plugin schickt die Ansage per **POST** an
`http://127.0.0.1[:Port]/plugins/alexang/index.php` mit `aktion=sprechen`,
`token`, `geraet` und `text`. Das Sprechtoken steht damit nur im Körper der
Anfrage, nie in einer Adresse, und wird dort **wie ein Kennwort** behandelt.
Als gesendet gilt nur `SPRECHEN;OK=1`; sonst nennen Protokoll und Reiter
*Test* des aufrufenden Plugins HTTP-Code und `GRUND`.

**Rückfall für fremde Plugins** mit einer URL-Vorlage (z. B. Ansagemodus
„eigene Vorlage“):

```
http://{ip}/plugins/alexang/?aktion=sprechen&token=<SPRECHTOKEN>&geraet={zones}&text={text}
```

Hier steht das Sprechtoken in der Adresse und kann im Zugriffsprotokoll des
Webservers landen — nur nehmen, wo es keine Ausgabeart „Alexa-NG“ gibt.
IP `127.0.0.1`, Zonen = Normalnamen (kommagetrennt, ohne Leerzeichen, oder
`gruppe:unten`). `{vol}` **nicht** verwenden — das Feld steht dort meist auf
dem Maß des Music Servers (8 %); eine Lautstärke fest mit `&laut=35` anhängen.

## MQTT

Präfix `alexang` (einstellbar). Gesendet mit `mosquitto_pub` direkt an den
Broker aus der LoxBerry-Konfiguration, nicht über den UDP-Eingang.

| Thema | Wert | retained |
|---|---|---|
| `alexang/status/ok` | 1/0 | nie |
| `alexang/status/ts` | Unix-Sekunden | nie |
| `alexang/status/zaehler` | 0…999 | nie |
| `alexang/status/befehle` | 1/0, Befehlsabo läuft | nie |
| `alexang/status/anmeldung` | 1/0 | nie |
| `alexang/geraete/anzahl` | Zahl | ja |
| `alexang/geraet/<name>/online` | 1/0 | ja |
| `alexang/geraet/<name>/lautstaerke` | 0–100, `-1` ohne Aussage | ja |
| `alexang/letzte/zeit` | Unix-Sekunden | ja |
| `alexang/letzte/geraet` | Normalname(n) | ja |
| `alexang/letzte/ergebnis` | 1/0 | ja |
| `alexang/letzte/grund` | Grund, `-` ohne Grund | ja |
| `alexang/radio/<zone>/sender` | Sendernummer, zuletzt bestätigt gesendet; `0` nach Stopp | nie |
| `alexang/radio/<zone>/zustand` | `1` Start, `0` Stopp, zuletzt bestätigt gesendet | nie |
| `alexang/hue_probe/ein` | `1`/`0`: ein Echo hat die Lampe „Loxone Probe“ der Hue-Probe geschaltet | nie |
| `alexang/hue/<kürzel>/ein` | `1`/`0`: Alexa schaltet eine Lampe der Freigabeliste (Befehl an Loxone) | nie |
| `alexang/hue/<kürzel>/helligkeit` | nur Dimmer, wenn Alexa eine Helligkeit setzt: `1`–`100`; `0` bei aus (Befehl an Loxone) | nie |

Kein Thema geht leer hinaus. Ein Gerät, das aus der Amazon-Liste
verschwindet, bekommt einmal `-1` und bleibt im Reiter **Geräte** als
„verschwunden“ sichtbar. Der **Ansagetext geht nie** über MQTT.

**Gateway Fassung 1:** Das Abo `alexang/#` steht in `mqtt_subscriptions.cfg`.
**Gateway Fassung 2:** nichts eintragen, Datenpunkte anhaken. Der Reiter MQTT
sagt, welche Fassung läuft.

**Befehlseingang** (Reiter MQTT, ab Werk aus). Ein Dienst abonniert:

| Thema | Nutzlast |
|---|---|
| `alexang/befehl/<name>/sprechen` | Text |
| `alexang/befehl/<name>/ankuendigen` | Text (nur mit Freigabe) |
| `alexang/befehl/<name>/lautstaerke` | 0–100 |
| `alexang/befehl/gruppe/<g>/sprechen` | Text |
| `alexang/befehl/routine` | Name (nur mit eigenem Haken und Freigabe) |
| `alexang/befehl/sperre` | `1` sperren, `0` öffnen (nur mit Haken „Sperre aus Loxone annehmen“) |
| `alexang/befehl/radio/<zone>` | Sendernummer; `0` oder `stopp` hält an; `<zone>` ist `1`–`24` oder `alle` (nur mit Haken „Radio je Zone erlauben“) |
| `alexang/befehl/radio/<zone>/laut` | 0–100 |

**Rückmeldung der Lampen** (Fassung 2): die Nachbildung selbst abonniert `alexang/hue/<kürzel>/status`
(`0`/`1`) und `alexang/hue/<kürzel>/status_helligkeit` (`0`–`100`); dafür braucht es den Befehlseingang nicht.

Zurückbehaltene (retained) Befehle werden **verworfen**, nicht ausgeführt.
Es gelten dieselbe Prüfung und Bremse wie am Endpunkt; das Ergebnis steht in
`alexang/letzte/*`.

## Sicherheit und Grenzen

* **Zwei Token.** Das **Sprechtoken** erlaubt Sprechen, Ankündigen und
  Lautstärke und gehört in Loxone und in die anderen Plugins. Das
  **Aktionstoken** erlaubt zusätzlich Routinen und die Geräteliste mit
  Seriennummern. Beide sind getrennt neu würfelbar; die Selbstprüfung ist rot,
  wenn sie gleich sind.
* **Routinen** nur aus der Freigabeliste (ab Werk leer). Eine Routine kann
  Schlösser öffnen oder Einkäufe auslösen — nur eintragen, was aus dem
  Heimnetz ausgelöst werden darf.
* **Nur amazon.de.**
* **Höchstens 60 Befehle je Stunde** ab Werk (10–240), Wiederholbremse 30 s,
  mindestens 1 s zwischen zwei Aufrufen an Amazon. Musik-Probe und Radio je
  Zone zählen zusammen getrennt (ab Werk 30 je Stunde).
* **Sperre aus Loxone, Musik-Probe, Radio je Zone** und
  `sperre`/`musik_*`/`radio*` am Endpunkt nur mit dem **Aktionstoken**; alle
  ab Werk aus.
* **Ansagetexte stehen nicht im Protokoll**, nur ihre Länge. Ein Haken für
  die Fehlersuche schreibt sie gekürzt auf 60 Zeichen. Nie im Protokoll:
  Token, Cookies, csrf, Kundennummer, Seriennummern, Autorisierungscode.
* **Sicherung:** „Einstellungen sichern“ nimmt die Amazon-Anmeldung **nur mit
  Haken** auf (ab Werk aus) — die Datei ist dann ein Zugang zu Ihrem
  Amazon-Konto.
* **Hue-Probe** (ab Werk aus): wie eine echte Hue-Bridge ohne Anmeldung im
  Heimnetz erreichbar — jeder im Netz kann die Probe-Lampe schalten. Das
  bewirkt nur die flüchtige MQTT-Meldung `alexang/hue_probe/ein`, nichts an
  Loxone. Mit eigener Netzadresse liegt der Broker-Zugang aus `general.json`
  zusätzlich in `data/plugins/alexang/hue/hue_container.json` (0600), den der
  Container liest.
* Die Schnittstelle ist **inoffiziell**; Amazon kann sie jederzeit ändern.

### Wo Zugangsdaten liegen

| Datei | Inhalt | Rechte |
|---|---|---|
| `config/plugins/alexang/amazon.json` | Erneuerungs-Token, Seriennummer der Anmeldung | 0600 |
| `config/plugins/alexang/alexang.json` | Einstellungen, beide Token | 0600 |
| `config/plugins/alexang.backup*.json` | Zweitschriften für Upgrades | 0600 |
| `data/plugins/alexang/sitzung.json` | Sitzungscookies, csrf | 0600 |
| `data/plugins/alexang/pkce.json` | offene Anmeldung, höchstens 30 min | 0600 |

Die Deinstallation hält das Befehlsabo und die Hue-Probe an, entfernt Container und Netz der Hue-Probe auf eigener Netzadresse, meldet das Gerät bei Amazon ab
(scheitert das, steht eine Warnung im Installationsprotokoll), räumt die
zurückbehaltenen MQTT-Themen ab und löscht die Zweitschriften.

## Am Gerät belegt (01.10.2026)

- **Anmeldung Weg (b)** am echten Amazon-Konto: Code eingelöst, Token
  gespeichert, Cookies getauscht, Geräteliste geholt (ein sprechfähiges
  Gerät, drei andere ausgelassen).
- **Ansage an ein Gerät** (`sprechen`, ein Echo):
  `SPRECHEN;OK=1;GERAETE=1;TEILE=1;UNVERAENDERT=0;OFFLINE=0`, die Ansage kam an.
- Damit auch: die curl-Erweiterung ist vorhanden, der Cookie-Tausch
  und der csrf-Weg funktionieren.
- **Hue-Probe auf eigener Netzadresse** (03.10.2026, Fassung 0.9.5): Abbild am
  Gerät gebaut (etwa 2,5 Minuten), Container lief, ein Echo suchte, holte
  `description.xml`, fragte die Lampe 7-mal ab und schaltete sie 2-mal; die
  Alexa-App fand „Loxone Probe“, `alexang/hue_probe/ein` wurde 2-mal gesendet.
- **Lampen für Alexa** (03.10.2026, Fassung 0.9.6): die Alexa-App fand einen
  Schalter und einen Dimmer der Liste; ein, aus und dimmen kamen als MQTT an.
  Den Schalter zeigt die App als An-/Ausschalter, den Dimmer als Licht.

## Noch am Gerät zu messen

1. Weg (b) im Einzelnen: wie lange gilt der Code, welcher Name erscheint im
   Amazon-Konto?
2. Textgrenze je Teil (angenommen 250 Zeichen), SSML, Aussprache von Umlauten,
   Zahlen, Uhrzeiten.
3. Mehrere Geräte zugleich, Verhalten bei „Nicht stören“, bei laufender
   Musik, an Amazon-Gruppen und am Echo Show.
4. Ankündigen: Gong, Anzeige, Voraussetzung „Ankündigungen erlaubt“.
5. Tatsächliche Rate-Grenze bei Amazon.
6. Laufzeit von Token und Cookies im Alltag.
7. Routine mit Umlauten im Namen.
8. Abmelden: verschwindet das Gerät aus dem Konto, ist das Token danach
   ungültig?
9. Loxone: Text über `<v>` am virtuellen Ausgang, Kodierung, Antwortzeit.
10. MQTT-Gateway: kommen die Themen am Miniserver an?
11. Musik-Probe: spielt ein Sender nach Nummer (TuneIn, Amazon Music) auf
    einem Echo und auf einer Amazon-Gruppe, hält „Musik stoppen“ an, wie
    genau muss der Name sein, nimmt Amazon die Prüfung der Suchphrase an
    (`SUCHE=AMAZON`)?
12. Sperre aus Loxone: setzt Loxone sie zuverlässig (HTTP und MQTT)?
13. Verschwundene Geräte: Hinweis nach einem Echo-Tausch; sind die
    Konfigurationen der fünf Plugins am Gerät lesbar?
14. Radio je Zone: eine Zone, dann alle; mit einem zweiten Echo zwei Zonen
    mit verschiedenen Sendern gleichzeitig; eine Amazon-Gruppe als Zone
    (synchron?); Stopp und Lautstärke je Zone; ab wie vielen Befehlen je
    Minute Amazon mit 429 antwortet.
15. Radiotasten: kommt ohne gewählte Taste `nr=0` an (Reiter Logdateien,
    Zeile `RADIO_STOPP von …, nr=0 als Stopp`), und hält die Zone an?
16. Hue-Probe: kommt die Suche eines Echos an (Absender-IP), holt er
    `description.xml`, findet die Alexa-App „Loxone Probe“, schaltet „Alexa,
    schalte Loxone Probe ein“ die Lampe (Zähler, MQTT `hue_probe/ein`)? Ist
    Port 8380 am Gerät frei, und reicht er dem Echo, oder braucht er Port 80?
    (Gemessen 02.10.2026: Suche und `description.xml` ja, Abfrage nein.)
17. Hue-Probe auf eigener Netzadresse: baut das Plugin das Abbild am Gerät,
    legt es Netz und Container an (`docker ps`), findet das Echo die
    Nachbildung auf Port 80 der eigenen IP und fragt es die Lampe ab, kommt
    `hue_probe/ein` über die Brücke beim Broker an? (Gemessen 03.10.2026: ja.)
18. Lampen für Alexa (Fassung 2): findet die Alexa-App zwei Lampen der Liste
    (Schalter und Dimmer), kommen `hue/<kürzel>/ein` und `…/helligkeit` beim
    Broker und am Miniserver an, zeigt Alexa nach einer Rückmeldung aus
    Loxone den echten Zustand, und wie verhält sich ein Echo, dessen Adresse
    nicht freigegeben ist? (Gemessen 03.10.2026: Schalter und Dimmer
    gefunden, ein, aus und dimmen kamen als MQTT an.)
19. Seit 0.9.7: zeigt die Alexa-App eine Lampe der Art „Licht (an/aus)“ als
    Licht, und schließt „Alexa, Licht aus“ im Raum sie ein? Sendet „Alexa,
    schalte <Dimmer> ein“ nur `ein` und „auf 40 Prozent“ genau `ein` und
    `helligkeit` = `40`? (Gemessen 03.10.2026: die App zeigt „Licht (an/aus)“
    nach Löschen und neuem Suchen als Lampe; Dimmer „ein“ sendet nur `ein`.
    Der Raumbefehl ist nicht gemessen.)

## Voraussetzungen

- Amazon-Konto auf amazon.de mit mindestens einem Echo
- PHP mit curl-Erweiterung (LoxBerry-Standard)
- Paket **mosquitto-clients** (wird bei der Installation mitinstalliert)
- Nur für die Hue-Probe (ab Werk aus): die PHP-Erweiterung **sockets** (am
  LoxBerry unter PHP 7.4 vorhanden), ein freier TCP-Port (ab Werk 8380) und
  UDP 1900
- Nur für die Hue-Probe auf eigener Netzadresse: **Docker** mit Zugriff für den
  Benutzer loxberry, Netztreiber macvlan, Internet beim ersten Anlegen (Abbild
  `php:8.4-cli`), eine freie IP im Heimnetz

## Lizenz

MIT — siehe [LICENSE](LICENSE).
