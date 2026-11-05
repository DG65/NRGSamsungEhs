<?php

require_once __DIR__ . '/libs/SAMEHS_Text.php';
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
    // Die Zeilen sind englische Quelltexte, die deutsche Fassung steht in locale.json.
    const NEWS_VERSIONS = [
        '0.2.0' => [
            'New status line in the "NASA bus access" section: it shows live whether the adapter is reachable, whether the bus delivers known messages, which values arrived in the last listening window and which were missing.',
            'The listening window per update can be set up to 60 s (since 0.1.4) -- handy for troubleshooting rarely sent values.',
        ],
        '0.3.0' => [
            'New values "Heating zone 2 actual"/"Heating zone 2 target" for systems with two heating circuits.',
        ],
        '0.3.1' => [
            'Logging in the Symcon archive is no longer switched on automatically. In the "General" section there is the button "Enable logging for the measured values", so you enable it deliberately (Symcon review). Logging that is already enabled stays untouched.',
        ],
        '0.4.0' => [
            'The module is now fully bilingual (German/English): form, status messages, notes and the names of new variables follow the Symcon language. Existing variables and all settings stay as they are.',
        ],
    ];
    private const LIBRARY_GUID = '{3BAE8FBC-ADF3-4BF6-8D3C-F04FAC043121}';

    // Bekannte NASA-Nachrichtennummern -> Ident/Bezeichnung ('caption' ist ein englischer Quelltext,
    // uebersetzt wird erst beim Anzeigen). Alle bisher
    // aufgenommenen Werte sind laut Quelle vorzeichenbehaftete
    // 2-Byte-Werte mit Faktor 10 ("arithmetic: value / 10"). Bewusst NUR
    // Felder aufgenommen, deren Ist/Soll-Richtung in der Quelle eindeutig
    // war.
    const MESSAGES = [
        0x8204 => ['ident' => 'Aussentemperatur',    'caption' => 'Outdoor temperature',            'scale' => 10],
        0x4238 => ['ident' => 'Vorlauftemperatur',   'caption' => 'Flow temperature',           'scale' => 10],
        0x4236 => ['ident' => 'Ruecklauftemperatur', 'caption' => 'Return temperature',          'scale' => 10],
        0x4237 => ['ident' => 'Warmwasser',          'caption' => 'Hot water',                  'scale' => 10],
        0x4235 => ['ident' => 'WarmwasserSoll',      'caption' => 'Hot water setpoint',         'scale' => 10],
        0x4247 => ['ident' => 'Zone1Soll',           'caption' => 'Heating zone 1 target temperature (flow setpoint)', 'scale' => 10],
        // Heizzone 2 (Forum-Post #8, 23./24.09.2026, "Gollum"/Ralf -- zwei
        // Heizkreise, zweiter fehlte). Kandidaten aus derselben Nachrichten-
        // Referenz wie die uebrigen sechs Felder (VAR_IN_TEMP_ZONE2_F/
        // VAR_IN_TEMP_TARGET_ZONE2_F), an Ralfs eigenem Bus-Dump gegengeprueft:
        // 0x42D4 steht in JEDEM Zyklus mit plausiblem Wert (~22,8 °C), 0x42D6
        // nur in manchen Zyklen (~21,0 °C) -- genau das erwartete Muster
        // Ist-Wert (staendig gesendet) vs. Soll-Wert (seltener), analog zu
        // WarmwasserSoll/Zone1Soll bei Simons Erstverifikation. Noch NICHT an
        // Ralfs eigener Anzeige gegengeprueft (Rueckmeldung ausstehend).
        0x42D4 => ['ident' => 'Zone2Ist',            'caption' => 'Heating zone 2 actual temperature', 'scale' => 10],
        0x42D6 => ['ident' => 'Zone2Soll',           'caption' => 'Heating zone 2 target temperature (flow setpoint)', 'scale' => 10],
    ];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Client und Statuszeile uebersetzen ueber SAMEHS_Text (englische Quelltexte, Deutsch in locale.json); jeder
        // Text fragt Symcon hoechstens einmal je Prozess (SUITE.md Stolperstein 24).
        SAMEHS_Text::setzen(SAMEHS_Text::mitMerkliste(function (string $text): string {
            return $this->Translate($text);
        }));
    }

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
        // Formulartexte der form.json sind englische Quelltexte; die dynamisch gebauten Teile unten
        // uebersetzen sich selbst (nicht doppelt uebersetzen).
        $form = SAMEHS_Text::formular(json_decode(file_get_contents(__DIR__ . '/form.json'), true));

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $libraryVersion = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';
        $this->updateFormElement($form['elements'], 'VersionInfo', [
            'caption' => SAMEHS_Text::t('ℹ️ SamsungEhs version %s -- local NASA protocol connection for Samsung EHS heat pumps.', $libraryVersion),
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
                return SAMEHS_Text::t($def['caption']);
            }
        }
        return $ident;
    }

    private function ageText(int $timestamp): string
    {
        $sec = max(0, time() - $timestamp);
        if ($sec < 120) {
            return SAMEHS_Text::t('%d s ago', $sec);
        }
        if ($sec < 7200) {
            return SAMEHS_Text::t('%d min ago', intdiv($sec, 60));
        }
        if ($sec < 172800) {
            return SAMEHS_Text::t('%d h ago', intdiv($sec, 3600));
        }
        return SAMEHS_Text::t('%d days ago', intdiv($sec, 86400));
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
            $parts[] = SAMEHS_Text::t($def['caption']) . ' ' . SAMEHS_Text::zahl((float)GetValue($id)) . ' °C';
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
                return [SAMEHS_Text::t('ℹ️ Not set up yet: no IP address of the RS485 adapter entered. Then switch "SamsungEhs active" on and apply.'), -1];
            }
            return [SAMEHS_Text::t('ℹ️ Switched off -- switch "SamsungEhs active" on and apply, then the NASA bus is listened to.'), -1];
        }
        if (!$hasHost) {
            return [SAMEHS_Text::t('⛔ Required entry missing: the IP address of the RS485 adapter.'), 0xFF0000];
        }

        $lastCycle = $this->ReadAttributeInteger('LastCycleAt');
        if ($lastCycle === 0) {
            return [SAMEHS_Text::t('ℹ️ No listening window has run yet -- the first follows within %d s after applying.', $interval), -1];
        }

        $lastSeen = $this->ReadAttributeInteger('LastSeenAt');
        $values = $this->lastValuesText();
        $knownCount = count(self::MESSAGES);
        $busCount = $this->ReadAttributeInteger('LastBusCount');
        $missing = array_filter(explode(',', $this->ReadAttributeString('LastMissing')));

        if ($busCount < 0) {
            $text = SAMEHS_Text::t('⚠️ The RS485 adapter is not reachable (last attempt %s; last values %s). Check IP address and port. Many adapters allow only ONE TCP connection at a time -- is another application (e.g. Home Assistant) already connected to it?', $this->ageText($lastCycle), $lastSeen > 0 ? $this->ageText($lastSeen) : SAMEHS_Text::t('never'));
            if ($values !== '') {
                $text .= ' ' . SAMEHS_Text::t('Last known values: %s.', $values);
            }
            return [$text, -1];
        }
        if (count($missing) === $knownCount) {
            return [$busCount === 1
                ? SAMEHS_Text::t('⚠️ Adapter reached, but none of the %d known NASA messages passed by in the listening window (%d s) (1 other message seen, %s). Extend the listening window and check the connection at F1/F2.', $knownCount, $listen, $this->ageText($lastCycle))
                : SAMEHS_Text::t('⚠️ Adapter reached, but none of the %d known NASA messages passed by in the listening window (%d s) (%d other messages seen, %s). Extend the listening window and check the connection at F1/F2.', $knownCount, $listen, $busCount, $this->ageText($lastCycle)), -1];
        }
        if (time() - $lastCycle > 3 * $interval + $listen + 10) {
            return [SAMEHS_Text::t('⚠️ The last listening window was %s, one is expected every %d s -- check the timer and the instance status. Last values: %s.', $this->ageText($lastCycle), $interval, $values), -1];
        }
        if (count($missing) > 0) {
            $names = array_map(fn($ident) => $this->captionOf($ident), $missing);
            return [SAMEHS_Text::t('⚠️ The bus delivers, but %d of %d values did not pass by in the last listening window (%d s): %s -- they stay at their last state, a longer listening window can help. Seen %s: %s.', count($names), $knownCount, $listen, implode(', ', $names), $this->ageText($lastCycle), $values), -1];
        }
        return [SAMEHS_Text::t('✅ NASA bus is being listened to, all %d values seen in the listening window (%s): %s.', $knownCount, $this->ageText($lastCycle), $values), -1];
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
                $items[] = ['type' => 'Label', 'caption' => SAMEHS_Text::t('Version %s:', $ver)];
            }
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => '• ' . SAMEHS_Text::t($line)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => SAMEHS_Text::t('Understood – do not show again'), 'onClick' => 'SAMEHS_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => SAMEHS_Text::t('🆕 New up to version %s', $latest), 'expanded' => true, 'items' => $items];
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
            'caption'  => SAMEHS_Text::t('👋  What is this module for?'),
            'items'    => [
                ['type' => 'Label', 'caption' => SAMEHS_Text::t('SamsungEhs reads a Samsung EHS heat pump directly via the internal NASA bus (RS485, F1/F2 connection) -- without internet, without a manufacturer account and without Samsung\'s official Modbus accessory module (MIM-B19N). A simple RS485-to-Ethernet adapter is enough.')],
                ['type' => 'Label', 'caption' => SAMEHS_Text::t('Read-only by design. The message numbers come from an actively maintained community reference and were confirmed on real systems by testers; packet format and checksum were verified against an independent reference implementation.')],
                ['type' => 'Button', 'caption' => SAMEHS_Text::t('Understood – do not show again'), 'onClick' => 'SAMEHS_AckPurposeIntro($id);'],
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
            'caption'  => SAMEHS_Text::t('💬  Feedback in the Symcon forum'),
            'items'    => [
                ['type' => 'Label', 'caption' => SAMEHS_Text::t('Questions, bugs or experience reports -- the SamsungEhs forum thread is the place for that.')],
                ['type' => 'Button', 'caption' => SAMEHS_Text::t('To the forum thread'), 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => SAMEHS_Text::t('Understood – do not show again'), 'onClick' => 'SAMEHS_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    // Seit 27.10.2026 ist main der Release-Branch (beta ist identisch, ems-integration abgeschafft).
    private const LICENSE_URL = 'https://github.com/DG65/NRGSamsungEhs/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    private function LicenseHint(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'caption'  => SAMEHS_Text::t('🧡  About this module'),
            'items'    => [
                ['type' => 'Label', 'caption' => SAMEHS_Text::t('Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.')],
                ['type' => 'Label', 'caption' => SAMEHS_Text::t('License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use needs a separate license from the rights holder.')],
                ['type' => 'Button', 'caption' => SAMEHS_Text::t('View license text'), 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => SAMEHS_Text::t('Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu')],
                ['type' => 'Label', 'caption' => SAMEHS_Text::t('Do you like the module and still want to leave something? I would be happy about a small donation — completely voluntary, nothing owed in return.')],
                ['type' => 'Button', 'caption' => SAMEHS_Text::t('☕  Donate via PayPal'), 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
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
            $this->LogMessage(SAMEHS_Text::t('NASA bridge not reachable (%s:%d): %s', $host, $this->ReadPropertyInteger('Port'), $client->lastError), KL_WARNING);
            return;
        }

        // Diagnose fuer Tester: alle im Hoerfenster gesehenen Nachrichtennummern
        // (nicht nur die bekannten aus MESSAGES) samt Rohwert, sichtbar ueber
        // die IPS-eigene Instanz-Debugausgabe. Hilft z. B. zu erkennen, ob ein
        // erwartetes Setpoint-Register schlicht nicht im Zeitfenster broadcastet
        // wurde (Bus traegt es seltener als die staendig gesendeten Messwerte),
        // und liefert Rohdaten fuer kuenftige MESSAGES-Ergaenzungen.
        $this->SendDebug(
            SAMEHS_Text::t('NASA messages in the listening window'),
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
            $this->LogMessage(SAMEHS_Text::t('No known NASA message seen on the bus within the %d s listening window (%s:%d).', $seconds, $host, $this->ReadPropertyInteger('Port')), KL_WARNING);
        }
    }

    private function maintainDeviceVariables(array $values, bool $reachable): void
    {
        $pos = 0;
        $this->MaintainVariable('Erreichbar', SAMEHS_Text::t('Reachable'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $pos++, true);
        $this->SetValue('Erreichbar', $reachable);

        foreach (self::MESSAGES as $def) {
            $ident = $def['ident'];
            if (!array_key_exists($ident, $values)) {
                continue;
            }
            $this->MaintainVariable($ident, SAMEHS_Text::t($def['caption']), VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->SetValue($ident, (float)$values[$ident]);
        }
    }

    /**
     * Schaltet das Logging im Symcon-Archiv fuer die Messwerte (Fliesskomma-
     * Variablen) dieser Instanz ein -- AUSSCHLIESSLICH auf Klick im Formular.
     * Symcon-Review 30.10.2026: ein Modul darf Variablen nicht von selbst
     * archivieren, der Nutzer muss das bewusst einschalten. Schaltet nie etwas
     * aus, was schon laeuft.
     */
    public function EnableArchiving(): string
    {
        try {
            $archiveIDs = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (!is_array($archiveIDs) || count($archiveIDs) === 0) {
                return SAMEHS_Text::t('❌ No archive instance found -- logging cannot be enabled.');
            }
            $archiveID = (int)$archiveIDs[0];
            $new = 0;
            $already = 0;
            foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
                if (IPS_GetObject($id)['ObjectType'] !== OBJECTTYPE_VARIABLE || IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_FLOAT) {
                    continue;
                }
                if (AC_GetLoggingStatus($archiveID, $id)) {
                    $already++;
                    continue;
                }
                AC_SetLoggingStatus($archiveID, $id, true);
                $new++;
            }
            if ($new > 0) {
                IPS_ApplyChanges($archiveID);
            }
            if ($new === 0 && $already === 0) {
                return SAMEHS_Text::t('ℹ️ No measured values yet -- wait for a poll first, then click again.');
            }
            return $already > 0
                ? SAMEHS_Text::t('✅ Logging enabled for %d measured value(s) (%d were already enabled).', $new, $already)
                : SAMEHS_Text::t('✅ Logging enabled for %d measured value(s).', $new);
        } catch (\Throwable $e) {
            return SAMEHS_Text::t('❌ Logging could not be enabled: %s', $e->getMessage());
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
            'z2WaterTempID'        => $this->contractFieldID('Zone2Ist'),
            'z2WaterTargetTempID'  => $this->contractFieldID('Zone2Soll'),
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
