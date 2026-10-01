# LoxBerry-Plugin: Alexa NG

Version 0.9.0

Lässt **Amazon-Echo-Geräte** sprechen, was Loxone oder ein anderes Plugin
sagen will: Ansagen an ein Gerät, an eine Gruppe oder an alle, Lautstärke
setzen, freigegebene Routinen starten. Dazu eine Statuszeile und eine
Geräteliste für Loxone und ein MQTT-Anschluss.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x,
mit der curl-Erweiterung). Kein Node, kein Python, kein Docker.

> **Am Gerät teilweise erprobt (01.10.2026).** Die Anmeldung im eigenen Browser
> (Weg b) hat am echten Amazon-Konto funktioniert: Token, Cookies und
> Geräteliste kamen, und eine Testansage an einen Echo kam an. Die übrigen
> Punkte unter „Noch am Gerät zu messen“ stehen noch aus.

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
5. Im Reiter **Geräte** eine **Testansage** schicken.
6. Im Reiter **Einbindung in Loxone** die Vorlagen herunterladen
   (`VI_alexang.xml` für die Statuszeile, `VQ_alexang.xml` für die Ansagen)
   und in Loxone Config importieren. Die dort gezeigten Adressen enthalten das
   Sprechtoken bereits.
7. Im Reiter **Test** nachsehen: jede Zeile ist eine Frage; ein Haken nur für
   Gemessenes, ein Strich heißt „nicht feststellbar“.

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

**`geraet`** ist eine Kommaliste aus Normalnamen, `gruppe:<name>` oder `alle`;
der Amazon-Anzeigename wird ebenfalls angenommen. Ohne `geraet` gilt das
Standardgerät. Ein unbekannter Name ergibt **404** `GRUND=GERAET_UNBEKANNT` —
nie einen Rückfall auf „alle“. Geräte, die offline sind, werden ausgelassen
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

### Aus anderen Plugins (Sprachsteuerung, Abfahrts-Assistent)

Beide haben den Ansagemodus „eigene Vorlage“. Vorlage:

```
http://{ip}/plugins/alexang/?aktion=sprechen&token=<SPRECHTOKEN>&geraet={zones}&text={text}
```

IP `127.0.0.1`, Zonen = Normalnamen (kommagetrennt, ohne Leerzeichen, oder
`gruppe:unten`). `{vol}` **nicht** verwenden — das Feld steht dort auf dem
Maß des Music Servers (8 %); eine Lautstärke fest mit `&laut=35` anhängen.

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

Kein Thema geht leer hinaus. Ein Gerät, das aus der Amazon-Liste
verschwindet, bekommt einmal `-1`. Der **Ansagetext geht nie** über MQTT.

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
  mindestens 1 s zwischen zwei Aufrufen an Amazon.
* **Ansagetexte stehen nicht im Protokoll**, nur ihre Länge. Ein Haken für
  die Fehlersuche schreibt sie gekürzt auf 60 Zeichen. Nie im Protokoll:
  Token, Cookies, csrf, Kundennummer, Seriennummern, Autorisierungscode.
* **Sicherung:** „Einstellungen sichern“ nimmt die Amazon-Anmeldung **nur mit
  Haken** auf (ab Werk aus) — die Datei ist dann ein Zugang zu Ihrem
  Amazon-Konto.
* Die Schnittstelle ist **inoffiziell**; Amazon kann sie jederzeit ändern.

### Wo Zugangsdaten liegen

| Datei | Inhalt | Rechte |
|---|---|---|
| `config/plugins/alexang/amazon.json` | Erneuerungs-Token, Seriennummer der Anmeldung | 0600 |
| `config/plugins/alexang/alexang.json` | Einstellungen, beide Token | 0600 |
| `config/plugins/alexang.backup*.json` | Zweitschriften für Upgrades | 0600 |
| `data/plugins/alexang/sitzung.json` | Sitzungscookies, csrf | 0600 |
| `data/plugins/alexang/pkce.json` | offene Anmeldung, höchstens 30 min | 0600 |

Die Deinstallation hält das Befehlsabo an, meldet das Gerät bei Amazon ab
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

## Voraussetzungen

- Amazon-Konto auf amazon.de mit mindestens einem Echo
- PHP mit curl-Erweiterung (LoxBerry-Standard)
- Paket **mosquitto-clients** (wird bei der Installation mitinstalliert)

## Lizenz

MIT — siehe [LICENSE](LICENSE).
