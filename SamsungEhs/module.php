<?php

require_once __DIR__ . '/libs/NasaBridgeClient.php';

// NRG-Stack SamsungEhs -- lokale Anbindung von Samsung-EHS-Waermepumpen
// ueber das interne NASA-Protokoll (RS485-Bus am F1/F2-Anschluss), analog
// zu HeishaMon (ein Hersteller, ein Protokoll, daher bewusst KEIN "Hub" im
// Namen -- die *Hub-Module buendeln mehrere Hersteller hinter einer
// gemeinsamen Anbindung, siehe WPHub/MeterHub/WPModbusHub).
//
// Vierter Baustein der Waermepumpen-Vertikale im Verbund:
//   WPHub         -- Herstellerclouds (Panasonic, Vaillant)
//   HeishaMon     -- lokal, nur Panasonic (HeishaMon-Platine)
//   WPModbusHub   -- lokal per Modbus TCP (NIBE, Stiebel Eltron, LG,
//                     Samsung -- aber NUR ueber das offizielle
//                     Modbus-Zubehoermodul MIM-B19N)
//   SamsungEhs    -- lokal per NASA-Protokoll (RS485), fuer Samsung-EHS-
//                     Anlagen OHNE dieses Zubehoermodul -- ein generischer
//                     RS485-zu-Ethernet-Adapter genuegt
//
// Anders als Modbus (Anfrage/Antwort auf einzelne Register) ist NASA ein
// eigenes Paketprotokoll auf einem gemeinsam genutzten Bus, auf dem Aussen-/
// Innengeraet ohnehin staendig Statuswerte austauschen -- das Modul fragt
// deshalb nichts aktiv ab, sondern hoert je Zyklus ein begrenztes
// Zeitfenster lang mit (siehe SAMEHS_NasaBridgeClient) und uebernimmt die
// zuletzt gesehenen Werte der bekannten Nachrichtennummern.
//
// Vertrag SAMEHS_GetFunctions() kompatibel zu WPHub/WPModbusHub/HeishaMon
// (Type=>'heatpump', contractVersion 1.15, dieselben Feldnamen).
//
// Registerkarte (Nachrichtennummern) aus einer umfangreichen, aktiv
// gepflegten, unabhaengigen Sammlung -- NICHT an echter Hardware
// verifiziert (Stand 17.09.2026, kein Testkonto/-geraet vorhanden).
// Paketformat UND CRC16-Algorithmus dagegen direkt gegen eine unabhaengige
// Referenz-Implementierung geprueft (siehe NasaBridgeClient.php-Kopf) --
// hoehere Sicherheit als bei den reinen Namens-/Adress-Zuordnungen.
// Bewusst nur lesend.

class SamsungEhs extends IPSModule
{
    // Verbund-Konvention "NEWS_VERSIONS" (SUITE.md "Einheitliche Formular-Optik" Punkt 1,
    // Dashboard/Dietmar 23.09.2026, EMS-Weitergabe) -- siehe WPModbusHub/module.php.
    const NEWS_VERSIONS = [
        '0.2.0' => [
            'Neue Statuszeile im Bereich „NASA-Bus-Zugang“: zeigt live, ob der Adapter erreichbar ist, ob der Bus bekannte Nachrichten liefert, welche Werte im letzten Hörfenster angekommen sind und welche fehlten.',
            'Hörfenster je Aktualisierung bis 60s einstellbar (seit 0.1.4) -- praktisch für die Fehlersuche bei selten gesendeten Werten.',
        ],
    ];
    private const LIBRARY_GUID = '{3BAE8FBC-ADF3-4BF6-8D3C-F04FAC043121}';

    // Bekannte NASA-Nachrichtennummern -> Ident/Bezeichnung. Alle bisher
    // aufgenommenen Werte sind laut Quelle vorzeichenbehaftete
    // 2-Byte-Werte mit Faktor 10 ("arithmetic: value / 10"). Bewusst NUR
    // Felder aufgenommen, deren Ist/Soll-Richtung in der Quelle eindeutig
    // war.
    const MESSAGES = [
        0x8204 => ['ident' => 'Aussentemperatur',    'caption' => 'Außentemperatur',            'scale' => 10],
        0x4238 => ['ident' => 'Vorlauftemperatur',   'caption' => 'Vorlauftemperatur',           'scale' => 10],
        0x4236 => ['ident' => 'Ruecklauftemperatur', 'caption' => 'Rücklauftemperatur',          'scale' => 10],
        0x4237 => ['ident' => 'Warmwasser',          'caption' => 'Warmwasser',                  'scale' => 10],
        0x4235 => ['ident' => 'WarmwasserSoll',      'caption' => 'Warmwasser Sollwert',         'scale' => 10],
        0x4247 => ['ident' => 'Zone1Soll',           'caption' => 'Heizzone 1 Solltemperatur (Vorlauf-Soll)', 'scale' => 10],
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 8899);
        $this->RegisterPropertyInteger('ListenSeconds', 3);
        $this->RegisterPropertyBoolean('SAMEHS_Active', false);
        $this->RegisterPropertyInteger('SAMEHS_Interval', 60);

        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeInteger('LastSeenAt', 0);
        // Fuer die Statuszeile im Formular: Zeitpunkt des letzten Hoerfensters (auch
        // erfolglos), dabei nicht gesehene bekannte Felder (Idents, Komma-getrennt) und
        // Zahl der verschiedenen Nachrichtennummern auf dem Bus (-1 = Adapter nicht erreicht).
        $this->RegisterAttributeInteger('LastCycleAt', 0);
        $this->RegisterAttributeString('LastMissing', '');
        $this->RegisterAttributeInteger('LastBusCount', 0);
        // Einmalig dismissible Forum-Hinweis (SUITE.md "Einheitliche Formular-
        // Optik", Forumsthread seit 18.09.2026 live), siehe ForumHint().
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('SAMEHS_UpdateTimer', 0, 'SAMEHS_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ensureSharedProfiles();

        $active   = $this->ReadPropertyBoolean('SAMEHS_Active');
        $interval = max(30, $this->ReadPropertyInteger('SAMEHS_Interval'));
        $hasHost  = trim($this->ReadPropertyString('Host')) !== '';

        if (!$active) {
            $this->SetTimerInterval('SAMEHS_UpdateTimer', 0);
            $this->SetStatus(104);
        } elseif (!$hasHost) {
            $this->SetTimerInterval('SAMEHS_UpdateTimer', 0);
            $this->SetStatus(201);
        } else {
            $this->SetTimerInterval('SAMEHS_UpdateTimer', $interval * 1000);
            $this->SetStatus(102);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $libraryVersion = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';
        $this->updateFormElement($form['elements'], 'VersionInfo', [
            'caption' => 'ℹ️ SamsungEhs Version ' . $libraryVersion . ' -- lokale NASA-Protokoll-Anbindung für Samsung-EHS-Wärmepumpen.',
        ]);

        [$statusText, $statusColor] = $this->statusLine();
        $this->updateFormElement($form['elements'], 'ConnectionStatus', ['caption' => $statusText, 'color' => $statusColor]);

        $purposeIntro = $this->PurposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }
        $newsBanner = $this->newsBanner();
        if ($newsBanner !== null) {
            array_unshift($form['elements'], $newsBanner);
        }

        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }

        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    /**
     * Merkt sich Ergebnis und Zeitpunkt des letzten Hoerfensters -- Grundlage der
     * Statuszeile im Formular. $raw = NULL: Adapter nicht erreicht.
     */
    private function recordCycle(?array $raw): void
    {
        $this->WriteAttributeInteger('LastCycleAt', time());
        if ($raw === null) {
            $this->WriteAttributeInteger('LastBusCount', -1);
            $this->WriteAttributeString('LastMissing', implode(',', array_column(self::MESSAGES, 'ident')));
            return;
        }
        $this->WriteAttributeInteger('LastBusCount', count($raw));
        $missing = [];
        foreach (self::MESSAGES as $msgNum => $def) {
            if (!array_key_exists($msgNum, $raw)) {
                $missing[] = $def['ident'];
            }
        }
        $this->WriteAttributeString('LastMissing', implode(',', $missing));
    }

    private function captionOf(string $ident): string
    {
        foreach (self::MESSAGES as $def) {
            if ($def['ident'] === $ident) {
                return $def['caption'];
            }
        }
        return $ident;
    }

    private function ageText(int $timestamp): string
    {
        $sec = max(0, time() - $timestamp);
        if ($sec < 120) {
            return 'vor ' . $sec . ' s';
        }
        if ($sec < 7200) {
            return 'vor ' . intdiv($sec, 60) . ' min';
        }
        if ($sec < 172800) {
            return 'vor ' . intdiv($sec, 3600) . ' h';
        }
        return 'vor ' . intdiv($sec, 86400) . ' Tagen';
    }

    /**
     * Zuletzt uebernommene Werte als Text, z. B. "Außentemperatur 21,2 °C, ...".
     * Nur Felder, zu denen schon eine Variable existiert.
     */
    private function lastValuesText(): string
    {
        $parts = [];
        foreach (self::MESSAGES as $def) {
            $id = $this->contractFieldID($def['ident']);
            if ($id === 0) {
                continue;
            }
            $parts[] = $def['caption'] . ' ' . number_format((float)GetValue($id), 1, ',', '') . ' °C';
        }
        return implode(', ', $parts);
    }

    /**
     * Statuszeile fuer das Formular (SUITE.md "Verbund-Verbindungen im Formular
     * sichtbar machen"), live berechnet: [Text, Farbe], Farbe -1 = Standard.
     */
    private function statusLine(): array
    {
        $active   = $this->ReadPropertyBoolean('SAMEHS_Active');
        $hasHost  = trim($this->ReadPropertyString('Host')) !== '';
        $interval = max(30, $this->ReadPropertyInteger('SAMEHS_Interval'));
        $listen   = max(1, min(60, $this->ReadPropertyInteger('ListenSeconds')));

        if (!$active) {
            if (!$hasHost) {
                return ['ℹ️ Noch nicht eingerichtet: keine IP-Adresse des RS485-Adapters eingetragen. Danach „SamsungEhs aktiv“ einschalten und übernehmen.', -1];
            }
            return ['ℹ️ Ausgeschaltet -- „SamsungEhs aktiv“ einschalten und übernehmen, dann wird der NASA-Bus abgehört.', -1];
        }
        if (!$hasHost) {
            return ['⛔ Pflichtangabe fehlt: die IP-Adresse des RS485-Adapters.', 0xFF0000];
        }

        $lastCycle = $this->ReadAttributeInteger('LastCycleAt');
        if ($lastCycle === 0) {
            return ['ℹ️ Noch kein Hörfenster gelaufen -- das erste folgt innerhalb von ' . $interval . ' s nach dem Übernehmen.', -1];
        }

        $lastSeen = $this->ReadAttributeInteger('LastSeenAt');
        $values = $this->lastValuesText();
        $knownCount = count(self::MESSAGES);
        $busCount = $this->ReadAttributeInteger('LastBusCount');
        $missing = array_filter(explode(',', $this->ReadAttributeString('LastMissing')));

        if ($busCount < 0) {
            $text = '⚠️ Der RS485-Adapter ist nicht erreichbar (letzter Versuch ' . $this->ageText($lastCycle) . '; letzte Werte ' . ($lastSeen > 0 ? $this->ageText($lastSeen) : 'noch nie') . '). IP-Adresse und Port prüfen. Viele Adapter erlauben nur EINE TCP-Verbindung gleichzeitig -- hängt schon eine andere Anwendung (z. B. Home Assistant) daran?';
            if ($values !== '') {
                $text .= ' Letzte bekannte Werte: ' . $values . '.';
            }
            return [$text, -1];
        }
        if (count($missing) === $knownCount) {
            return ['⚠️ Adapter erreicht, aber im Hörfenster (' . $listen . ' s) kam keine der ' . $knownCount . ' bekannten NASA-Nachrichten vorbei (' . $busCount . ' andere Nachricht' . ($busCount === 1 ? '' : 'en') . ' gesehen, ' . $this->ageText($lastCycle) . '). Hörfenster verlängern und den Anschluss an F1/F2 prüfen.', -1];
        }
        if (time() - $lastCycle > 3 * $interval + $listen + 10) {
            return ['⚠️ Das letzte Hörfenster liegt ' . str_replace('vor ', '', $this->ageText($lastCycle)) . ' zurück, erwartet wären ' . $interval . ' s -- Timer und Instanzstatus prüfen. Letzte Werte: ' . $values . '.', -1];
        }
        if (count($missing) > 0) {
            $names = array_map(fn($ident) => $this->captionOf($ident), $missing);
            return ['⚠️ Bus liefert, aber ' . count($names) . ' von ' . $knownCount . ' Werten kamen im letzten Hörfenster (' . $listen . ' s) nicht vorbei: ' . implode(', ', $names) . ' -- sie bleiben auf dem letzten Stand, ein längeres Hörfenster kann helfen. Gesehen ' . $this->ageText($lastCycle) . ': ' . $values . '.', -1];
        }
        return ['✅ NASA-Bus wird abgehört, alle ' . $knownCount . ' Werte im Hörfenster gesehen (' . $this->ageText($lastCycle) . '): ' . $values . '.', -1];
    }

    private function updateFormElement(array &$items, string $name, array $patch): bool
    {
        foreach ($items as &$item) {
            if (($item['name'] ?? null) === $name) {
                $item = array_merge($item, $patch);
                return true;
            }
            if (isset($item['items']) && is_array($item['items'])) {
                if ($this->updateFormElement($item['items'], $name, $patch)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Siehe WPModbusHub/module.php::BaseVersion(). */
    private function BaseVersion(string $v): string
    {
        return preg_replace('/-.*$/', '', $v) ?? $v;
    }

    /** Siehe WPModbusHub/module.php::newsBanner(). */
    private function newsBanner(): ?array
    {
        $seen = (string) $this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach (self::NEWS_VERSIONS as $ver => $lines) {
            if ($seen === '' || version_compare($ver, $seen, '>')) {
                $pending[$ver] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        $multi = count($pending) > 1;
        foreach ($pending as $ver => $lines) {
            if ($multi) {
                $items[] = ['type' => 'Label', 'caption' => 'Version ' . $ver . ':'];
            }
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SAMEHS_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu bis Version ' . $latest, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $ver = is_array($lib) ? $this->BaseVersion((string) ($lib['Version'] ?? '')) : '';
        if ($ver === '') {
            $ver = (string) array_key_last(self::NEWS_VERSIONS);
        }
        $this->WriteAttributeString('SeenNews', $ver);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'PurposeIntroPanel',
            'expanded' => true,
            'caption'  => '👋  Wozu dieses Modul?',
            'items'    => [
                ['type' => 'Label', 'caption' => 'SamsungEhs liest eine Samsung-EHS-Wärmepumpe direkt über den internen NASA-Bus (RS485, F1/F2-Anschluss) aus -- ohne Internet, ohne Herstellerkonto und ohne Samsungs offizielles Modbus-Zubehörmodul (MIM-B19N). Dafür reicht ein einfacher RS485-zu-Ethernet-Adapter.'],
                ['type' => 'Label', 'caption' => 'Bewusst nur lesend und Stand heute ungeprüft an echter Hardware -- die Nachrichtennummern stammen aus einer aktiv gepflegten Community-Referenz, Paketformat und Prüfsumme wurden aber gegen eine unabhängige Referenz-Implementierung verifiziert.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SAMEHS_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    // Forumsthread seit 18.09.2026 live (Dietmar).
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-samsungehs-lokale-anbindung-fuer-samsung-ehs-waermepumpen-ueber-das-interne-nasa-protokoll-rs485/144422';

    /**
     * Symcon-Forum-Hinweis -- SUITE.md "Einheitliche Formular-Optik", nach den
     * Fachpanels, vor "Über dieses Modul". Einmalig dismissible, kein
     * Versionsbezug (Muster WPHub ForumHint()/AckForumHint()).
     */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'ForumHintPanel',
            'expanded' => true,
            'caption'  => '💬  Feedback im Symcon-Forum',
            'items'    => [
                ['type' => 'Label', 'caption' => 'Fragen, Fehler oder Erfahrungsberichte -- dafür gibt es den SamsungEhs-Forumsthread.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SAMEHS_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    // Zeigt auf beta (erster Store-Release-Branch, siehe SUITE.md-Stolperfalle
    // 01.09.2026: nicht blind auf main verlinken -- main existiert fuer dieses
    // Repo noch nicht). Aktive Entwicklung bleibt auf ems-integration.
    private const LICENSE_URL = 'https://github.com/DG65/NRGSamsungEhs/blob/beta/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    private function LicenseHint(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'caption'  => '🧡  Über dieses Modul',
            'items'    => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function Update(): void
    {
        if (!$this->ReadPropertyBoolean('SAMEHS_Active')) {
            return;
        }
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(201);
            return;
        }
        $client = new SAMEHS_NasaBridgeClient($host, $this->ReadPropertyInteger('Port'));
        $seconds = max(1, min(60, $this->ReadPropertyInteger('ListenSeconds')));
        $raw = $client->listen((float)$seconds);

        $this->recordCycle($raw);
        if ($raw === null) {
            $this->maintainDeviceVariables([], false);
            $this->SetStatus(201);
            $this->LogMessage('NASA-Bruecke nicht erreichbar (' . $host . ':' . $this->ReadPropertyInteger('Port') . '): ' . $client->lastError, KL_WARNING);
            return;
        }

        // Diagnose fuer Tester: alle im Hoerfenster gesehenen Nachrichtennummern
        // (nicht nur die bekannten aus MESSAGES) samt Rohwert, sichtbar ueber
        // die IPS-eigene Instanz-Debugausgabe. Hilft z. B. zu erkennen, ob ein
        // erwartetes Setpoint-Register schlicht nicht im Zeitfenster broadcastet
        // wurde (Bus traegt es seltener als die staendig gesendeten Messwerte),
        // und liefert Rohdaten fuer kuenftige MESSAGES-Ergaenzungen.
        $this->SendDebug(
            'NASA-Nachrichten im Hörfenster',
            implode(', ', array_map(fn($k, $v) => sprintf('0x%04X=%d', $k, $v), array_keys($raw), $raw)),
            0
        );

        $values = [];
        foreach (self::MESSAGES as $msgNum => $def) {
            if (array_key_exists($msgNum, $raw)) {
                $values[$def['ident']] = $raw[$msgNum] / $def['scale'];
            }
        }
        $reachable = count($values) > 0;
        $this->maintainDeviceVariables($values, $reachable);
        if ($reachable) {
            $this->WriteAttributeInteger('LastSeenAt', time());
        }

        $this->SetStatus($reachable ? 102 : 201);
        if (!$reachable) {
            $this->LogMessage('In ' . $seconds . 's Hörfenster keine bekannte NASA-Nachricht auf dem Bus gesehen (' . $host . ':' . $this->ReadPropertyInteger('Port') . ').', KL_WARNING);
        }
    }

    private function maintainDeviceVariables(array $values, bool $reachable): void
    {
        $pos = 0;
        $this->MaintainVariable('Erreichbar', 'Erreichbar', VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $pos++, true);
        $this->SetValue('Erreichbar', $reachable);

        foreach (self::MESSAGES as $def) {
            $ident = $def['ident'];
            if (!array_key_exists($ident, $values)) {
                continue;
            }
            $this->MaintainVariable($ident, $def['caption'], VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->ensureArchived($ident);
            $this->SetValue($ident, (float)$values[$ident]);
        }
    }

    private function ensureArchived(string $ident): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false) {
            return;
        }
        $archiveIDs = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (!is_array($archiveIDs) || count($archiveIDs) === 0) {
            return;
        }
        try {
            if (!AC_GetLoggingStatus($archiveIDs[0], $id)) {
                AC_SetLoggingStatus($archiveIDs[0], $id, true);
                IPS_ApplyChanges($archiveIDs[0]);
            }
        } catch (\Throwable $e) {
            // Archivierung ist ein Komfortfeature, kein Zyklus-Abbruch wert.
        }
    }

    /**
     * NRG-Stack-Vertrag fuer Waermepumpen, konsistent zu WPHub/WPModbusHub/
     * HeishaMon (Type=>'heatpump', contractVersion 1.15, dieselben
     * Feldnamen). PowerID/EnergyID bleiben 0 -- die bisherige Nachrichten-
     * auswahl deckt nur Temperaturen ab.
     */
    public function GetFunctions()
    {
        $reachableID = @$this->GetIDForIdent('Erreichbar');
        return [[
            'contractVersion'      => '1.15',
            'Type'                 => 'heatpump',
            'Caption'              => 'Samsung EHS',
            'PowerID'              => 0,
            'EnergyID'             => 0,
            'Measured'             => false,
            'unit'                 => 'W',
            'reachable'            => ($reachableID === false) ? false : (bool)GetValue($reachableID),
            'outsideTempID'        => $this->contractFieldID('Aussentemperatur'),
            'outdoorTemperatureID' => $this->contractFieldID('Aussentemperatur'),
            'z1WaterTempID'        => 0,
            'z1WaterTargetTempID'  => $this->contractFieldID('Zone1Soll'),
            'z2WaterTempID'        => 0,
            'z2WaterTargetTempID'  => 0,
            'dhwTempID'            => $this->contractFieldID('Warmwasser'),
            'dhwTargetTempID'      => $this->contractFieldID('WarmwasserSoll'),
            'mainInletTempID'      => $this->contractFieldID('Ruecklauftemperatur'),
            'mainOutletTempID'     => $this->contractFieldID('Vorlauftemperatur'),
            'bufferTempID'         => 0,
            'quietModeID'          => 0,
            'ecoComfortModeID'     => 0,
            'holidayTimerID'       => 0,
            'operatingModeNormID'  => 0,
            'operatingModeID'      => 0,
            'managedBy'            => 'samehs',
            'lastSeenAt'           => $this->ReadAttributeInteger('LastSeenAt'),
        ]];
    }

    private function contractFieldID(string $ident): int
    {
        $id = @$this->GetIDForIdent($ident);
        return ($id === false) ? 0 : (int)$id;
    }

    private function ensureSharedProfiles(): void
    {
        if (!IPS_VariableProfileExists('NRG.Celsius')) {
            IPS_CreateVariableProfile('NRG.Celsius', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.Celsius', '', ' °C');
            IPS_SetVariableProfileDigits('NRG.Celsius', 1);
        }
    }
}
