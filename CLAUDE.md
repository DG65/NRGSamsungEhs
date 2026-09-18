# SamsungEhs — Übergabe-Kontext für die neue Sitzung

Angelegt am 17.09.2026, direkt im Anschluss an WPModbusHub, als Reaktion auf einen echten
Forumskommentar (sunnyww, WPHub-Thread): eine Samsung-EHS-Wärmepumpe, angebunden über einen
generischen RS485-Adapter und das proprietäre NASA-Protokoll — **nicht** über Samsungs
offizielles Modbus-Zubehörmodul MIM-B19N (das deckt bereits WPModbusHub ab). Name von
Dietmar bestätigt ("SamsungEhs", Präfix `SAMEHS` — bewusst kein "Hub": ein Hersteller, ein
Protokoll, siehe Namensdiskussion unten).

## Warum ein eigenes Modul statt eines Zusatzes in WPModbusHub

Dietmars eigene Frage: "Und wenn wir beide Versionen reinnehmen?" — Antwort/Einordnung: NASA
ist kein Modbus, nur dieselbe RS485-Verkabelung. Ein zweites, komplett anderes
Nachrichtenformat in ein Modul zu packen, das im Namen "Modbus" verspricht, hätte die
Namensehrlichkeit gebrochen — dieselbe Überlegung, die HeishaMon (Panasonic lokal-
proprietär) von WPHub (Cloud) getrennt hat. Dietmar stimmte zu ("Top").

## Warum kein "Hub" im Namen

Die `*Hub`-Module bündeln in diesem Verbund immer MEHRERE Hersteller hinter einer
gemeinsamen Anbindung (WPHub: Panasonic+Vaillant, MeterHub: viele Zählerhersteller,
WPModbusHub: NIBE+Stiebel Eltron+LG+Samsung). SamsungEhs ist wie HeishaMon: ein Hersteller,
ein Protokoll — deshalb kein "Hub". Dietmars eigene Beobachtung, im Gespräch bestätigt.

## Protokoll — Kernfakten (wichtig für jede Erweiterung)

**NASA ist kein Modbus.** Eigenes Paketformat auf dem internen RS485-Bus (F1/F2), über den
Außen-/Innengerät/Wifi-Kit ohnehin laufend Statuswerte austauschen:

- Paket: `[Start=0x32][Größe(2 Byte)][Quell-Adressklasse/-kanal/-adresse][Ziel-
  Adressklasse/-kanal/-adresse][Info/Version/Retry-Byte][Typ/Datentyp-Byte][Paketnummer]
  [Kapazität][Nachrichten...][CRC16(2 Byte)][Ende=0x34]`
- CRC16 = **CRC-16/XMODEM** (Polynom 0x1021, Startwert 0) über die Bytes von der
  Quell-Adressklasse bis zum letzten Nachrichtenbyte (NICHT Start/Größe/CRC/Ende selbst).
- Jede Nachricht: 2-Byte-Nummer (die Bits 9-10 kodieren selbst den Payload-Typ: 0=1 Byte,
  1=2 Byte, 2=4 Byte, 3=variable Länge/Struktur — v1 wertet nur 0/1/2 aus), danach der
  Payload als big-endian, vorzeichenbehaftet.
- Referenz-Implementierung: eine aktiv gepflegte, unabhängige Open-Source-Implementierung
  des NASA-Protokolls, umfangreiche Nachrichten-Datenbank (8600+ Zeilen).

**Verifiziert, nicht nur übernommen (17.09.2026):** Paketformat + CRC-Algorithmus wurden
gegen die ECHTE Referenz-Klasse geprüft — ein selbst gebautes Testpaket (Nachricht 0x8204
"Outdoor temperature" = 75) wurde mit der Referenz-Implementierung geparst und lieferte
denselben Wert wie der PHP-Nachbau. Zusätzlich validiert ein im Referenz-Projekt als
Beispiel hinterlegtes, echtes Aufzeichnungspaket (Nachricht 0x4076) mit demselben
CRC16-Nachbau korrekt. Siehe `.tools/test-module.php` Block 1/2 für die exakten
Testvektoren und wie sie zustande kamen.

**Teilweise live bestätigt (18.09.2026):** Community-Tester "sunnyww"/Simon (derselbe Nutzer,
dessen Forumskommentar dieses Modul angestoßen hat) hat SamsungEhs an seiner echten Anlage
installiert (Waveshare RS485-zu-Ethernet-Adapter, lokal F1/F2 abgegriffen). Vier der sechs
`MESSAGES`-Einträge kamen mit plausiblen Werten an: Aussentemperatur 20.3°C,
Vorlauftemperatur 25.3°C, Ruecklauftemperatur 24.8°C, Warmwasser 44.8°C — Adresse UND Faktor
10 damit für diese vier Felder bestätigt. `WarmwasserSoll` (0x4235) und `Zone1Soll`/
Vorlauf-Soll (0x4247) blieben leer -- vermutlich broadcasten Sollwert-Nachrichten seltener
als die staendig aktualisierten Messwerte und fielen im 3s-Hoerfenster (Default
`ListenSeconds`) nicht rein, kein Hinweis auf falsche Nachrichtennummern. Zur Diagnose gibt
`Update()` seit 0.1.3 ueber `SendDebug()` alle im Hoerfenster gesehenen Nachrichtennummern
samt Rohwert aus (auch unbekannte, nicht nur die sechs aus `MESSAGES`) -- hilft sowohl beim
Pruefen der beiden fehlenden Felder (laengeres Hoerfenster? andere Update-Zyklen abwarten?)
als auch bei kuenftigen MESSAGES-Ergaenzungen.

**Bekannte Betriebs-Falle, noch nicht im Modul geloest:** Simons Waveshare-Adapter laeuft im
Modus "TCP Client" fest verdrahtet auf eine bestehende Home-Assistant-NASA-Anbindung
(Destination-IP/Port), "Multi-host" ist deaktiviert. Viele dieser RS485-zu-Ethernet-Adapter
erlauben nur EINE aktive TCP-Verbindung gleichzeitig -- SamsungEhs und eine bereits
laufende Home-Assistant-Integration konkurrieren dann um denselben Socket, was das
beobachtete Flackern zwischen "OK" und "nicht erreichbar" erklaeren wuerde (kein
SamsungEhs-Bug, sondern eine Adapter-/Netzwerktopologie-Frage). Noch offen: ob Simons
Adapter im Server-Modus mehrere gleichzeitige Clients erlaubt, oder ob ein TCP-Fan-out auf
der Home-Assistant-Seite noetig ist. Kein Code-Fix vorgesehen, solange nicht klar ist, was
beim Tester tatsaechlich hilft -- ggf. spaeter ein Hinweis-Popup im Formular ("NASA-Bus wird
bereits von einer anderen Anwendung genutzt?").

## Architektur — bewusst anders als Modbus-basierte Module

Kein Anfrage/Antwort-Schema. `SAMEHS_NasaBridgeClient::listen($seconds)` verbindet sich zum
RS485-zu-TCP-Adapter, sammelt für ein konfigurierbares Zeitfenster (Property
`ListenSeconds`, 1-10s) alle eintreffenden Bytes und extrahiert daraus alle vollständigen,
CRC-gültigen Pakete (`extractMessages()` — öffentlich, damit der Prüfstand sie direkt mit
vorgefertigten Byte-Strömen testen kann, ohne echtes TCP). Ungültige/unvollständige Pakete
werden übersprungen (Resync durch Ein-Byte-Vorruecken), kein Absturz.

**Bewusst NICHT gebaut (v1):** Paket-Versand (Anfragen aktiv stellen) — reiner Passiv-
Mitschnitt genügt, da der Bus ohnehin ständig Statuswerte trägt. Strukturtyp-Nachrichten
(Payload-Typ 3, variable Länge) werden erkannt, aber nicht ausgewertet — keiner der sechs
Zielwerte braucht das.

## Was bewusst NICHT Teil von v1 ist

- Keine Steuerbefehle (nur lesend).
- Keine Leistungs-/Energiezähler.
- ~~Kein Forum-Hinweis-Panel~~ — erledigt 18.09.2026: Thread ist live
  (https://community.symcon.de/t/modul-nrg-stack-samsungehs-lokale-anbindung-fuer-samsung-ehs-waermepumpen-ueber-das-interne-nasa-protokoll-rs485/144422),
  Panel `ForumHint()`/`AckForumHint()` verlinkt (0.1.2).
- ~~Kein News-Panel-Inhalt über die Erstversion hinaus~~ — erster echter Inhalt seit 0.1.3
  (Diagnose-Debugausgabe, siehe oben).

## Branch-Modell

`ems-integration` bleibt der aktive Entwicklungsbranch. Seit 18.09.2026 existiert zusätzlich
`beta` (erster Store-Release-Branch). **Seit 18.09.2026 (Dietmars Entscheidung): beide
Branches laufen automatisch gleich** — jeder Push nach `ems-integration` geht im selben Zug
auch nach `beta`, kein manuelles Nachziehen mehr nötig. `main` existiert für dieses Repo noch
nicht.

## Verbund-Manifest SUITE.md

Lokal unter `/Users/dietmar/Nextcloud/Claude/SUITE.md`, kein GitHub-Remote.
