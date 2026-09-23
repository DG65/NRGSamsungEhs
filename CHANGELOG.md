# Changelog — NRG-Stack SamsungEhs

## 0.2.1 (Build 7) — 23.09.2026

- **"Was ist Neu" auf die verbundweite `NEWS_VERSIONS`-Konvention umgestellt** (Dashboard/Dietmar 23.09.2026, per EMS weitergegeben): zeigt jetzt gezielt nur die Lücke zwischen der zuletzt bestätigten und der gerade installierten Version ("🆕 Neu bis Version X"). Bestätigen merkt sich die tatsächlich installierte Bibliotheksversion, nicht nur den letzten Eintrag-Schlüssel. Rein internes Verhalten, keine Registeränderung.

## 0.2.0 (Build 6) — 21.09.2026

- **Statuszeile im Formular.** Im Bereich „NASA-Bus-Zugang“ steht jetzt eine live berechnete Zeile (SUITE.md „Verbund-Verbindungen im Formular sichtbar machen“), die sagt, was am Bus tatsächlich ankommt: ✅ alle sechs Werte im Hörfenster gesehen, mit Werten und Alter; ⚠️ Adapter nicht erreichbar (mit Hinweis, dass viele Adapter nur EINE TCP-Verbindung gleichzeitig erlauben, z. B. wenn schon Home Assistant daran hängt), Adapter erreicht aber keine der bekannten Nachrichten gesehen (mit Zahl der übrigen gesehenen Nachrichten), einzelne Werte im letzten Hörfenster nicht vorbeigekommen (beim Namen genannt, sie bleiben auf dem letzten Stand) oder Hörfenster viel zu lange her; ℹ️ ausgeschaltet, nicht eingerichtet oder noch kein Hörfenster gelaufen; ⛔ IP-Adresse fehlt, rot. Damit sieht ein Tester wie zuvor bei den Sollwerten sofort, ob ein längeres Hörfenster hilft, ohne die Debugausgabe zu öffnen. Neu dafür: drei Attribute je Instanz (Zeitpunkt des letzten Hörfensters, nicht gesehene Felder, Zahl der Nachrichtennummern auf dem Bus). Prüfstand +14 Prüfungen, acht Mutationen der Zielstellen gefangen.

## 0.1.4 (Build 5) — 18.09.2026

- **Hörfenster bis 60s statt bisher 10s.** Auf Wunsch des Testers (Simon, siehe 0.1.3): sein Multi-Client-Adapter-Modus scheiterte (Waveshare interpretiert die Bytes dann fälschlich als Modbus-RTU), er testet jetzt die Fan-out-Route über Home Assistant und wollte währenddessen länger mithören können. `ListenSeconds`-Obergrenze in `Update()` und Formular auf 60s angehoben, Hinweistext ergänzt (mögliche Kollision mit der IPS-eigenen Skript-Ausführungszeit ab ca. 30s, sowie: ein langes Hörfenster verlängert den tatsächlichen Zyklus über das eingestellte Aktualisierungsintervall hinaus).
- **Ergebnis:** Mit 60s Hörfenster kamen bei Simon jetzt alle sechs `MESSAGES`-Felder an (vorher vier von sechs) — Adresse und Faktor 10 damit für die komplette Registerkarte an echter Hardware bestätigt. Die beiden zuvor fehlenden Sollwerte (Warmwasser Soll, Vorlauf-Soll) waren also nur ein zu kurzes Hörfenster, keine falschen Nachrichtennummern.

## 0.1.3 (Build 4) — 18.09.2026

- **Erste Live-Bestätigung an echter Hardware.** Community-Tester "sunnyww"/Simon hat SamsungEhs an seiner Anlage installiert: vier der sechs Nachrichtennummern (Außentemperatur, Vorlauf-, Rücklauftemperatur, Warmwasser Ist) liefern plausible, korrekt skalierte Werte. Warmwasser Soll und Vorlauf-Soll kamen im Test nicht an — vermutlich seltener gesendete Nachrichten, die ins 3s-Hörfenster nicht reinfielen. Zur Diagnose gibt `Update()` jetzt über die IPS-eigene Debugausgabe (`SendDebug()`) alle im Hörfenster tatsächlich gesehenen NASA-Nachrichtennummern samt Rohwert aus — auch unbekannte, nicht nur die sechs bisher ausgewerteten. Hilft sowohl beim Nachvollziehen fehlender Felder als auch bei künftigen `MESSAGES`-Ergänzungen.

## 0.1.2 (Build 3) — 18.09.2026

- **Forum-Hinweis-Panel verlinkt den echten Vorstellungsthread.** Der Thread ist seit heute live (Dietmar). Neues, einmalig dismissibles Panel „💬 Feedback im Symcon-Forum" (Muster WPHub), eingehängt zwischen den Fachpanels und „🧡 Über dieses Modul". 6 neue Tests.

## 0.1.1 (Build 2) — 18.09.2026

- **Erster Beta-Release.** `beta`-Branch angelegt (bisher gab es nur `ems-integration`). Vor dem Wechsel geprüft: `php -l` + voller Testlauf grün, Store-Review-Checkliste Punkt 12 (Neuinstallations-Simulation) durchgegangen — keine eigenen Objekt-/Variablen-IDs, PLZ/Adressen im Formular-Code, `library.json` nur die 8 Store-Felder, `vendor: "Samsung"` (Gerätehersteller, korrekt für dieses herstellergebundene Modul). `migrationsvergleich.php` (SUITE.md 9e) entfällt für diesen ersten Beta-Stand — kein Vorgänger-Stand zum Vergleichen. Zusätzlich: `SAMEHS_NasaBridgeClient::listen()` mit einem selbst gebauten, echten TCP-Testserver über eine echte Verbindung verifiziert (nicht nur gegen die Testattrappe) — Verbindungsaufbau und Paket-Erkennung inklusive Störbytes bestätigt korrekt. `LICENSE_URL` zeigt jetzt auf `beta` statt `ems-integration`.

## 0.1.0 (Build 1) — 17.09.2026

- **Erster Stand.** Lokale Anbindung von Samsung-EHS-Wärmepumpen über das interne NASA-Protokoll (RS485-Bus, F1/F2-Anschluss) — vierter Baustein der Wärmepumpen-Vertikale, ergänzt WPHub (Cloud), HeishaMon (lokal, nur Panasonic) und WPModbusHub (lokal, Modbus, aber nur Samsung-Anlagen MIT dem offiziellen MIM-B19N-Zubehörmodul). Bewusst kein "Hub" im Namen — ein Hersteller, ein Protokoll, analog zu HeishaMon. `SAMEHS_NasaBridgeClient` hört je Zyklus ein konfigurierbares Zeitfenster lang auf den laufenden Bus-Verkehr, statt aktiv Werte abzufragen. Ausgelesen werden Außentemperatur, Vorlauf-/Rücklauftemperatur sowie Warmwasser Ist/Soll und Vorlauf-Soll. Vertrag `SAMEHS_GetFunctions()` kompatibel zu WPHub/WPModbusHub/HeishaMon (`Type=>'heatpump'`, contractVersion 1.15). **Paketformat und CRC-16/XMODEM-Prüfsumme gegen eine unabhängige, aktiv gepflegte Referenz-Implementierung verifiziert** — ein selbst gebautes Testpaket wurde damit erfolgreich geparst. Die Zuordnung der sechs Nachrichtennummern stammt aus derselben Quelle, ist aber **an keiner echten Anlage nachgemessen**. Bewusst nur lesend. 35 Tests.
