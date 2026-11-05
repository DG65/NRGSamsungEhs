🇩🇪 [Deutsche Fassung](README.md)

# SamsungEhs — local NASA protocol connection for Samsung EHS heat pumps (Symcon)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Module_Version-0.4.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGSamsungEhs/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGSamsungEhs/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

## Overview

SamsungEhs reads a Samsung EHS heat pump directly via the internal **NASA bus** (RS485, F1/F2 connection) — without internet, without a manufacturer account and **without** Samsung's official Modbus accessory module MIM-B19N. A simple RS485-to-Ethernet adapter is enough.

Fourth building block of the heat pump line in the NRG-Stack:

| Module | Route | Manufacturers |
|---|---|---|
| [WPHub](https://github.com/DG65/NRGWPHub) | cloud | Panasonic, Vaillant |
| [HeishaMon](https://github.com/DG65/NRGHeishaMon) | local, MQTT | Panasonic only (HeishaMon board) |
| [WPModbusHub](https://github.com/DG65/NRGWPModbusHub) | local, Modbus TCP | NIBE, Stiebel Eltron, LG, Samsung (only with the MIM-B19N) |
| **SamsungEhs** | **local, NASA protocol (RS485)** | **Samsung EHS, without the MIM-B19N** |

Unlike Modbus (request/response on individual registers), NASA is a packet protocol of its own on a shared bus on which the outdoor and indoor unit exchange status values all the time anyway. SamsungEhs therefore queries nothing actively: in every update cycle it listens for a few seconds and takes over what passes by in that time (deliberately not a permanent recording — this fits Symcon's cycle model better).

## Status

As of 0.4.0 (5 Nov 2026) — **read-only by design**. Three parts to be judged separately:

- **Packet format and checksum (CRC-16/XMODEM)** are verified against an independent, actively maintained reference implementation — a test packet built for that purpose was parsed with it and gave the same value.
- **All six values have been confirmed on a real system since 18 Sep 2026** (community tester, NASA bus via a generic Waveshare RS485 adapter): outdoor temperature, flow and return temperature, hot water actual, hot water setpoint and flow setpoint all arrived with plausible values — with a longer listening window (adjustable up to 60 s since 0.1.4) also the two setpoints that are sent less often. For diagnosis the instance outputs all NASA message numbers seen in the listening window, with their raw values, in Symcon's own debug output (since 0.1.3). Heating zone 2 (0.3.0) is checked against a bus dump of a tester with two heating circuits.
- **If you already use the same NASA bus for another local connection** (e.g. an existing Home Assistant integration through the same RS485-to-Ethernet adapter): many of these adapters allow only a single active TCP connection at a time. If the adapter runs in "TCP client" mode hard-wired to another target, SamsungEhs may only be able to listen in sporadically. Cleanest: an adapter mode with several simultaneous connections (e.g. "TCP server" with several clients, if the adapter supports it) or a simple TCP fan-out on the other side.

If you have a Samsung EHS system with a generic RS485 adapter (no MIM-B19N) and would like to help with the first real test: very welcome, see the note in the form.

## Languages

The module is bilingual (German/English): form, status messages, notes and the names of new variables follow the Symcon language. The source texts are English, the German version lives in `SamsungEhs/locale.json`. Existing variables and settings stay unchanged.

## Part of the NRG-Stack

Part of the **NRG-Stack** — DG65's energy module family.

## License

PolyForm Noncommercial 1.0.0 — see [LICENSE](LICENSE). Free for private use, commercial use requires a license.
