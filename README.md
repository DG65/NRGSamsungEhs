# SamsungEhs — lokale NASA-Protokoll-Anbindung für Samsung-EHS-Wärmepumpen (IP-Symcon)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.1.0-blue)
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

Erster Stand (0.1.0, 17.09.2026) — **bewusst nur lesend** und **an keiner echten Anlage verifiziert** (kein Testkonto/-gerät vorhanden). Zwei getrennt zu bewertende Bausteine:

- **Paketformat und Prüfsumme (CRC-16/XMODEM)** sind gegen eine unabhängige, aktiv gepflegte Referenz-Implementierung verifiziert — ein eigens gebautes Testpaket wurde damit geparst und lieferte denselben Wert.
- **Die Zuordnung der sechs bisher ausgelesenen Werte** (Außentemperatur, Vorlauf/Rücklauf, Warmwasser Ist/Soll, Vorlauf-Soll) stammt aus derselben, aktiv gepflegten Community-Referenz — aber nicht live nachgemessen.

Wer eine Samsung-EHS-Anlage mit einem generischen RS485-Adapter (kein MIM-B19N) hat und beim ersten echten Test helfen möchte: sehr willkommen, siehe Formular-Hinweis.

## Verbund

Teil des **NRG-Stack** — dem Energie-Modulverbund von DG65.

## Lizenz

PolyForm Noncommercial 1.0.0 — siehe [LICENSE](LICENSE). Privat frei, gewerblich lizenzpflichtig.
