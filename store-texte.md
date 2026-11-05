# Store-Texte SamsungEhs 0.4.0 (Entwurf, nicht eingereicht)

Bundle `eu.dg.samsungehs`. Namen: „NRG-Stack SamsungEhs“ (de und en). Dokumentation: de `https://github.com/DG65/NRGSamsungEhs/blob/main/README.md`, en `https://github.com/DG65/NRGSamsungEhs/blob/main/README.en.md`. Kategorien wie bisher (aus dem Store übernehmen, `publish_module`). Beta zuerst; Stable nur, wenn Dietmar es will (SUITE.md 9: Zweisprachigkeit zählt als relevant für alle).

## Beschreibung (de)

SamsungEhs liest eine Samsung-EHS-Wärmepumpe direkt über den internen NASA-Bus (RS485, F1/F2-Anschluss) aus - ohne Internet, ohne Herstellerkonto und ohne Samsungs offizielles Modbus-Zubehörmodul MIM-B19N. Ein einfacher RS485-zu-Ethernet-Adapter genügt. Vierter Baustein der Wärmepumpen-Vertikale im NRG-Stack, neben WPHub (Herstellerclouds), HeishaMon (lokal, Panasonic) und WPModbusHub (lokal per Modbus).

Das Modul fragt nichts aktiv ab, sondern hört je Aktualisierung ein einstellbares Zeitfenster auf dem ohnehin laufenden Bus mit. Ausgelesen werden Außentemperatur, Vorlauf-/Rücklauftemperatur, Warmwasser Ist/Soll und die Vorlauf-Sollwerte von bis zu zwei Heizzonen. Über denselben Vertrag wie WPHub/HeishaMon stehen die Werte im ganzen NRG-Stack-Verbund zur Verfügung.

Bewusst nur lesend. Die Nachrichtennummern sind an echten Anlagen von Testern bestätigt, Paketformat und Prüfsumme gegen eine unabhängige Referenz geprüft. Das Logging im Archiv schaltet das Modul nie von selbst ein, dafür gibt es einen Knopf im Formular. Die Oberfläche ist zweisprachig (Deutsch/Englisch).

## Description (en)

SamsungEhs reads a Samsung EHS heat pump directly via the internal NASA bus (RS485, F1/F2 connection) - without internet, without a manufacturer account and without Samsung's official Modbus accessory module MIM-B19N. A simple RS485-to-Ethernet adapter is enough. Fourth building block of the heat pump line in the NRG-Stack, next to WPHub (manufacturer clouds), HeishaMon (local, Panasonic) and WPModbusHub (local via Modbus).

The module does not query anything actively: in every update it listens for an adjustable time window on the bus that is running anyway. It reads outdoor temperature, flow/return temperature, hot water actual/setpoint and the flow setpoints of up to two heating zones. Through the same contract as WPHub/HeishaMon the values are available across the whole NRG-Stack.

Read-only by design. The message numbers are confirmed on real systems by testers, packet format and checksum were checked against an independent reference. The module never switches on archive logging by itself, there is a button in the form for that. The interface is bilingual (German/English).

## Versionsinformation (de)

- Neu: Das Modul ist jetzt zweisprachig (Deutsch/Englisch). Formular, Statusmeldungen, Hinweise und die Namen neuer Variablen folgen der Symcon-Sprache.
- Neu: Englische Dokumentation (README.en.md).
- Fix: Der Hinweistext "Wozu dieses Modul?" sagte noch, die Nachrichten seien ungeprüft, obwohl Tester sie an echten Anlagen bestätigt haben.
- Hinweis: Variablen, Einstellungen und Funktionen sind unverändert, bestehende Instanzen laufen weiter.

## Release notes (en)

- New: The module is now bilingual (German/English). Form, status messages, notes and the names of new variables follow the Symcon language.
- New: English documentation (README.en.md).
- Fix: The note "What is this module for?" still said the messages were unverified, although testers have confirmed them on real systems.
- Note: Variables, settings and functions are unchanged, existing instances keep running.
