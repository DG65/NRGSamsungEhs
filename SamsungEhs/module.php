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
// Registerkarte (Nachrichtennummern) aus echoDaveD/ehs_sentinel_hacs_
// integration (data/nasa_repository.yml, umfangreiche, aktiv gepflegte
// Sammlung) -- NICHT an echter Hardware verifiziert (Stand 17.09.2026,
// kein Testkonto/-geraet vorhanden). Paketformat UND CRC16-Algorithmus
// dagegen direkt gegen die Referenz-Python-Implementierung geprueft (siehe
// NasaBridgeClient.php-Kopf) -- hoehere Sicherheit als bei den reinen
// Namens-/Adress-Zuordnungen. Bewusst nur lesend.

class SamsungEhs extends IPSModule
{
    const NEWS_VERSION = '0.1.0';

    // Bekannte NASA-Nachrichtennummern -> Ident/Bezeichnung. Alle bisher
    // aufgenommenen Werte sind laut nasa_repository.yml vorzeichenbehaftete
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

        $purposeIntro = $this->PurposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }
        if ($this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION) {
            array_unshift($form['elements'], [
                'type'     => 'ExpansionPanel',
                'name'     => 'NewsPanel',
                'caption'  => '🆕 Neu in Version ' . self::NEWS_VERSION,
                'expanded' => true,
                'items'    => [
                    ['type' => 'Label', 'caption' => '• Erste Version: hört auf den lokalen NASA-Bus (RS485, F1/F2) mit und liest Außentemperatur, Vorlauf/Rücklauf sowie Warmwasser-Werte -- ohne Samsungs offizielles Modbus-Zubehörmodul.'],
                    ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SAMEHS_AckNews($id);'],
                ],
            ]);
        }

        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
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

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
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
                ['type' => 'Label', 'caption' => 'Bewusst nur lesend und Stand heute ungeprüft an echter Hardware -- die Nachrichtennummern stammen aus einer aktiv gepflegten Community-Referenz (echoDaveD/ehs_sentinel), Paketformat und Prüfsumme wurden aber gegen deren echten Code verifiziert.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SAMEHS_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    private const LICENSE_URL = 'https://github.com/DG65/NRGSamsungEhs/blob/ems-integration/LICENSE';
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
        $seconds = max(1, min(10, $this->ReadPropertyInteger('ListenSeconds')));
        $raw = $client->listen((float)$seconds);

        if ($raw === null) {
            $this->maintainDeviceVariables([], false);
            $this->SetStatus(201);
            $this->LogMessage('NASA-Bruecke nicht erreichbar (' . $host . ':' . $this->ReadPropertyInteger('Port') . '): ' . $client->lastError, KL_WARNING);
            return;
        }

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
