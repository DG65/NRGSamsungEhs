# SamsungEhs — lokale NASA-Protokoll-Anbindung für Samsung-EHS-Wärmepumpen (IP-Symcon)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.2.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGSamsungEhs/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGSamsungEhs/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

## Übersicht

SamsungEhs liest eine Samsung-EHS-Wärmepumpe direkt über den internen **NASA-Bus** (RS485, F1/F2-Anschluss) aus — ohne Internet, ohne Herstellerkonto und **ohne** Samsungs offizielles Modbus-Zubehörmodul MIM-B19N. Ein einfacher RS485-zu-Ethernet-Adapter genügt.

Vierter Baustein der Wärmepumpen-Vertikale im NRG-Stack:

| Modul | Weg | Hersteller |
|---|---|---|
| [WPHub](https://github.com/DG65/NRGWPHub) | Cloud | Panasonic, Vaillant |
| [HeishaMon](https://github.com/DG65/NRGHeishaMon) | lokal, MQTT | nur Panasonic (HeishaMon-Platine) |
| [WPModbusHub](https://github.com/DG65/NRGWPModbusHub) | lokal, Modbus TCP | NIBE, Stiebel Eltron, LG, Samsung (nur mit MIM-B19N) |
| **SamsungEhs** | **lokal, NASA-Protokoll (RS485)** | **Samsung EHS, ohne MIM-B19N** |

Anders als Modbus (Anfrage/Antwort auf einzelne Register) ist NASA ein eigenes Paketprotokoll auf einem gemeinsam genutzten Bus, auf dem Außen- und Innengerät ohnehin laufend Statuswerte austauschen. SamsungEhs fragt deshalb nichts aktiv ab, sondern hört je Aktualisierungszyklus ein paar Sekunden mit und übernimmt, was in dieser Zeit vorbeikommt (bewusst kein Dauer-Mitschnitt — passt besser zu IP-Symcons Zyklusmodell).

## Status

Stand 0.2.0 (21.09.2026) — **bewusst nur lesend**. Drei getrennt zu bewertende Bausteine:

- **Paketformat und Prüfsumme (CRC-16/XMODEM)** sind gegen eine unabhängige, aktiv gepflegte Referenz-Implementierung verifiziert — ein eigens gebautes Testpaket wurde damit geparst und lieferte denselben Wert.
- **Alle sechs Werte sind seit 18.09.2026 an einer echten Anlage bestätigt** (Community-Tester, NASA-Bus über einen generischen Waveshare-RS485-Adapter): Außentemperatur, Vorlauf-, Rücklauftemperatur, Warmwasser Ist, Warmwasser Soll und Vorlauf-Soll kamen alle mit plausiblen Werten an — mit einem längeren Hörfenster (bis zu 60s einstellbar seit 0.1.4) auch die beiden zuvor selteneren Sollwerte. Zur Diagnose gibt die Instanz seit 0.1.3 über die IPS-eigene Debugausgabe alle im Hörfenster gesehenen NASA-Nachrichtennummern samt Rohwert aus.
- **Wer denselben NASA-Bus bereits für eine andere lokale Anbindung nutzt** (z. B. eine bestehende Home-Assistant-Integration über denselben RS485-zu-Ethernet-Adapter): viele dieser Adapter erlauben nur eine einzige aktive TCP-Verbindung gleichzeitig. Läuft der Adapter im Modus „TCP Client" fest verdrahtet auf ein anderes Ziel, kann SamsungEhs u. U. nur sporadisch mitlesen. Am saubersten: einen Adapter-Modus mit mehreren gleichzeitigen Verbindungen (z. B. „TCP Server" mit mehreren Clients, falls vom Adapter unterstützt) oder einen einfachen TCP-Fan-out auf der Gegenstelle.

Wer eine Samsung-EHS-Anlage mit einem generischen RS485-Adapter (kein MIM-B19N) hat und beim ersten echten Test helfen möchte: sehr willkommen, siehe Formular-Hinweis.

## Verbund

Teil des **NRG-Stack** — dem Energie-Modulverbund von DG65.

## Lizenz

PolyForm Noncommercial 1.0.0 — siehe [LICENSE](LICENSE). Privat frei, gewerblich lizenzpflichtig.
