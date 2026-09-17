<?php

// Pruefstand fuer SamsungEhs (Muster: WPHub/WPModbusHub .tools/test-module.php).
// Kein Netzzugriff: SAMEHS_NasaBridgeClient::extractMessages() wird direkt
// mit vorgefertigten Byte-Strömen getestet statt über eine echte TCP-
// Verbindung. Zwei der Testpakete sind gegen die ECHTE Python-Referenz-
// implementierung (echoDaveD/ehs_sentinel_hacs_integration) verifiziert,
// siehe Kommentare an den jeweiligen Tests.
//
// Aufruf:  php .tools/test-module.php     (0 = alle Pruefungen bestanden)

error_reporting(E_ALL & ~E_DEPRECATED);

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ✅ $name\n";
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------------
// Mini-IPS: nur was SamsungEhs wirklich benutzt.
// ---------------------------------------------------------------------------

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT   = 2;
const VARIABLETYPE_STRING  = 3;
const KL_WARNING = 10205;
const KL_NOTIFY  = 10204;

$GLOBALS['ips'] = [
    'profiles'   => [],
    'variables'  => [],
    'nextVarId'  => 10000,
    'properties' => [],
    'log'        => [],
];

function IPS_VariableProfileExists(string $name): bool
{
    return isset($GLOBALS['ips']['profiles'][$name]);
}
function IPS_CreateVariableProfile(string $name, int $type): void
{
    $GLOBALS['ips']['profiles'][$name] = ['type' => $type, 'suffix' => '', 'digits' => 0];
}
function IPS_SetVariableProfileText(string $name, string $prefix, string $suffix): void
{
    $GLOBALS['ips']['profiles'][$name]['suffix'] = $suffix;
}
function IPS_SetVariableProfileDigits(string $name, int $digits): void
{
    $GLOBALS['ips']['profiles'][$name]['digits'] = $digits;
}
const TEST_ARCHIVE_INSTANCE_ID = 55555;
function IPS_GetInstanceListByModuleID(string $moduleID): array
{
    if ($moduleID === '{43192F0B-135B-4CE7-A0A7-1475603F3060}') {
        return [TEST_ARCHIVE_INSTANCE_ID];
    }
    return [];
}
function AC_GetLoggingStatus(int $archiveID, int $variableID): bool
{
    return $GLOBALS['ips']['archived'][$variableID] ?? false;
}
function AC_SetLoggingStatus(int $archiveID, int $variableID, bool $active): bool
{
    $GLOBALS['ips']['archived'][$variableID] = $active;
    return true;
}
function IPS_ApplyChanges(int $id): void
{
    $GLOBALS['ips']['applied'] = true;
}
function GetValue(int $id)
{
    foreach ($GLOBALS['ips']['variables'] as $v) {
        if ($v['id'] === $id) {
            return $v['value'];
        }
    }
    return null;
}

class IPSModule
{
    public $InstanceID = 12345;
    protected $attributes = [];
    protected $timers = [];
    public $status = 0;

    public function __construct()
    {
    }
    public function Create()
    {
    }
    public function ApplyChanges()
    {
    }
    protected function RegisterPropertyBoolean(string $name, bool $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterPropertyInteger(string $name, int $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterPropertyString(string $name, string $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterAttributeString(string $name, string $default): void
    {
        if (!isset($this->attributes[$name])) {
            $this->attributes[$name] = $default;
        }
    }
    protected function RegisterAttributeInteger(string $name, int $default): void
    {
        if (!isset($this->attributes[$name])) {
            $this->attributes[$name] = $default;
        }
    }
    protected function ReadAttributeInteger(string $name): int
    {
        return (int)($this->attributes[$name] ?? 0);
    }
    protected function WriteAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }
    protected function RegisterAttributeBoolean(string $name, bool $default): void
    {
        if (!isset($this->attributes[$name])) {
            $this->attributes[$name] = $default;
        }
    }
    protected function ReadAttributeBoolean(string $name): bool
    {
        return (bool)($this->attributes[$name] ?? false);
    }
    protected function WriteAttributeBoolean(string $name, bool $value): void
    {
        $this->attributes[$name] = $value;
    }
    protected function RegisterTimer(string $ident, int $interval, string $script): void
    {
        $this->timers[$ident] = $interval;
    }
    protected function SetTimerInterval(string $ident, int $interval): void
    {
        $this->timers[$ident] = $interval;
    }
    public function GetTimerInterval(string $ident): int
    {
        return $this->timers[$ident] ?? -1;
    }
    protected function ReadPropertyBoolean(string $name): bool
    {
        return (bool)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadPropertyInteger(string $name): int
    {
        return (int)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadPropertyString(string $name): string
    {
        return (string)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadAttributeString(string $name): string
    {
        return (string)($this->attributes[$name] ?? '');
    }
    protected function WriteAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }
    protected function SetStatus(int $status): void
    {
        $this->status = $status;
    }
    protected function GetStatus(): int
    {
        return $this->status;
    }
    protected function SendDebug(string $topic, string $text, int $format): void
    {
    }
    protected function LogMessage(string $text, int $type): void
    {
        $GLOBALS['ips']['log'][] = $text;
    }
    protected function UpdateFormField(string $field, string $key, $value): void
    {
        $GLOBALS['ips']['formFieldUpdates'][$field][$key] = $value;
    }
    protected function MaintainVariable(string $ident, string $name, int $type, string $profile, int $pos, bool $keep): void
    {
        if (!$keep) {
            unset($GLOBALS['ips']['variables'][$ident]);
            return;
        }
        if (!isset($GLOBALS['ips']['variables'][$ident])) {
            $GLOBALS['ips']['variables'][$ident] = [
                'name'    => $name,
                'type'    => $type,
                'profile' => $profile,
                'value'   => null,
                'id'      => $GLOBALS['ips']['nextVarId']++,
            ];
        }
    }
    protected function SetValue(string $ident, $value): void
    {
        if (isset($GLOBALS['ips']['variables'][$ident])) {
            $GLOBALS['ips']['variables'][$ident]['value'] = $value;
        }
    }
    protected function GetIDForIdent(string $ident)
    {
        if (!isset($GLOBALS['ips']['variables'][$ident])) {
            trigger_error("Ident $ident not found", E_USER_WARNING);
            return false;
        }
        return $GLOBALS['ips']['variables'][$ident]['id'];
    }
}

require __DIR__ . '/../SamsungEhs/module.php';

// ---------------------------------------------------------------------------
echo "Block 1: CRC16/XMODEM -- gegen echte Referenzwerte verifiziert\n";
// ---------------------------------------------------------------------------
// Beide Vektoren sind KEINE erfundenen Zahlen: Vektor A ist ein im Referenz-
// Repo (devtools/test.py) hinterlegtes, echtes Aufzeichnungsbeispiel; Vektor
// B wurde selbst gebaut und mit der echten Python-Klasse (NASAPacket.parse())
// gegengeprueft -- beide sind hier mit Pythons binascii.crc_hqx(data, 0)
// nachgerechnet (siehe Sitzungsverlauf).

$client = new SAMEHS_NasaBridgeClient('127.0.0.1', 8899);

// Vektor A: echtes Beispielpaket aus devtools/test.py, CRC laut Python-Referenz 0x5a06.
$vectorA = chr(0x20) . chr(0x00) . chr(0x00) . chr(0x80) . chr(0xff) . chr(0x00) . chr(0xc0) . chr(0x15) . chr(0xa6) . chr(0x01) . chr(0x40) . chr(0x76) . chr(0xff);
check('CRC16 Vektor A (echtes Beispielpaket) = 0x5a06', $client->crc16Xmodem($vectorA) === 0x5a06, '0x' . dechex($client->crc16Xmodem($vectorA)));

// ---------------------------------------------------------------------------
echo "Block 2: extractMessages() -- Paket-Erkennung und Dekodierung\n";
// ---------------------------------------------------------------------------

// Vektor B: selbst erzeugtes, aber gegen die echte NASAPacket.parse()-Klasse
// geprueftes Paket fuer Nachricht 0x8204 (NASA_OUTDOOR_OUT_TEMP) = 75 (7.5°C).
$packetB = hexToBin('32 00 12 10 00 00 ff 00 00 40 14 01 01 82 04 00 4b 85 5f 34');
$messagesB = $client->extractMessages($packetB);
check('Paket B wird erkannt (Nachricht 0x8204 vorhanden)', isset($messagesB[0x8204]));
check('Paket B: Rohwert 75 (-> 7.5°C nach Skalierung)', ($messagesB[0x8204] ?? null) === 75);

// Dasselbe Paket B, aber mit 5 zufaelligen Fuellbytes davor und danach --
// die Erkennung muss trotzdem funktionieren (realer Bus-Verkehr hat keine
// sauberen Paketgrenzen im Puffer).
$noisy = hexToBin('11 22 33 44 55') . $packetB . hexToBin('66 77 88 99 aa');
$messagesNoisy = $client->extractMessages($noisy);
check('Paket B wird auch mit Stoerbytes drumherum erkannt', ($messagesNoisy[0x8204] ?? null) === 75);

// Verstuemmeltes Paket (kaputte CRC): darf NICHT als gueltig durchgehen.
$corrupt = hexToBin('32 00 12 10 00 00 ff 00 00 40 14 01 01 82 04 00 ff ff ff 34');
$messagesCorrupt = $client->extractMessages($corrupt);
check('Paket mit falscher CRC wird verworfen (kein Fantasiewert)', !isset($messagesCorrupt[0x8204]));

// Unvollstaendiges Paket am Pufferende: wird uebersprungen, nicht falsch geparst.
$incomplete = hexToBin('32 00 12 10 00 00 ff 00 00 40 14 01 01 82');
$messagesIncomplete = $client->extractMessages($incomplete);
check('Unvollstaendiges Paket am Pufferende liefert nichts (kein Absturz)', count($messagesIncomplete) === 0);

// Negativer Temperaturwert (Winter, -35 = -3.5°C) -- Vorzeichenprobe ueber
// ein frisch gebautes Paket (Nachricht 0x8204, Payload 0xffdd = -35 als
// 16-Bit-Zweierkomplement). Nur die CRC muss dafuer neu berechnet werden --
// Aufbau sonst identisch zu Paket B.
$negBody = hexToBin('10 00 00 ff 00 00 40 14 01 01 82 04 ff dd');
$negCrc = $client->crc16Xmodem($negBody);
$negPacket = chr(0x32) . chr(0x00) . chr(0x12) . $negBody . chr(($negCrc >> 8) & 0xFF) . chr($negCrc & 0xFF) . chr(0x34);
$messagesNeg = $client->extractMessages($negPacket);
check('Negativer Rohwert korrekt vorzeichenbehaftet dekodiert (-35)', ($messagesNeg[0x8204] ?? null) === -35, json_encode($messagesNeg));

function hexToBin(string $hex): string
{
    return implode('', array_map(fn ($b) => chr(hexdec($b)), explode(' ', trim($hex))));
}

// ---------------------------------------------------------------------------
echo "Block 3: Lebenszyklus und Status\n";
// ---------------------------------------------------------------------------

$mod = new SamsungEhs();
$mod->Create();
check('SAMEHS_Active-Standard ist aus', $GLOBALS['ips']['properties']['SAMEHS_Active'] === false);
check('Port-Standard ist 8899', $GLOBALS['ips']['properties']['Port'] === 8899);

$mod->ApplyChanges();
check('Inaktiv: Status 104, kein Timer', $mod->status === 104 && $mod->GetTimerInterval('SAMEHS_UpdateTimer') === 0);

$GLOBALS['ips']['properties']['SAMEHS_Active'] = true;
$mod->ApplyChanges();
check('Aktiv ohne Host: Status 201', $mod->status === 201 && $mod->GetTimerInterval('SAMEHS_UpdateTimer') === 0);

$GLOBALS['ips']['properties']['Host'] = '192.168.1.60';
$mod->ApplyChanges();
check('Aktiv mit Host: Status 102, Timer läuft', $mod->status === 102 && $mod->GetTimerInterval('SAMEHS_UpdateTimer') === 60000);
check('Gemeinsames Profil NRG.Celsius wurde angelegt', IPS_VariableProfileExists('NRG.Celsius'));

// ---------------------------------------------------------------------------
echo "Block 4: maintainDeviceVariables() + GetFunctions()\n";
// ---------------------------------------------------------------------------

$maintainVars = new ReflectionMethod(SamsungEhs::class, 'maintainDeviceVariables');
$maintainVars->setAccessible(true);
$maintainVars->invoke($mod, [
    'Aussentemperatur'    => 7.5,
    'Vorlauftemperatur'   => 39.0,
    'Ruecklauftemperatur' => 33.0,
], true);

check('Erreichbar-Variable gesetzt', ($GLOBALS['ips']['variables']['Erreichbar']['value'] ?? null) === true);
check('Aussentemperatur-Variable gesetzt', ($GLOBALS['ips']['variables']['Aussentemperatur']['value'] ?? null) === 7.5);
check('Vorlauftemperatur-Variable gesetzt', ($GLOBALS['ips']['variables']['Vorlauftemperatur']['value'] ?? null) === 39.0);
check('Warmwasser-Variable NICHT angelegt (in diesem Zyklus nicht gesehen)', !isset($GLOBALS['ips']['variables']['Warmwasser']));

$functions = $mod->GetFunctions();
check('GetFunctions() liefert genau einen Eintrag', is_array($functions) && count($functions) === 1);
check('GetFunctions(): Type=heatpump, contractVersion 1.15', ($functions[0]['Type'] ?? '') === 'heatpump' && ($functions[0]['contractVersion'] ?? '') === '1.15');
check('GetFunctions(): outsideTempID zeigt auf die echte Variable', ($functions[0]['outsideTempID'] ?? 0) === $GLOBALS['ips']['variables']['Aussentemperatur']['id']);
check('GetFunctions(): mainInletTempID zeigt auf Ruecklauftemperatur', ($functions[0]['mainInletTempID'] ?? 0) === $GLOBALS['ips']['variables']['Ruecklauftemperatur']['id']);
check('GetFunctions(): dhwTempID = 0 (Warmwasser in diesem Zyklus nicht gesehen)', ($functions[0]['dhwTempID'] ?? -1) === 0);
check('GetFunctions(): reachable = true', ($functions[0]['reachable'] ?? null) === true);

$maintainVars->invoke($mod, [], false);
check('Nicht erreichbar: Erreichbar-Variable false', ($GLOBALS['ips']['variables']['Erreichbar']['value'] ?? null) === false);
check('Nicht erreichbar: alter Temperaturwert bleibt stehen (kein Reset)', ($GLOBALS['ips']['variables']['Aussentemperatur']['value'] ?? null) === 7.5);

// ---------------------------------------------------------------------------
echo "Block 5: Update() -- Zusammenspiel Bruecken-Client + Status\n";
// ---------------------------------------------------------------------------
// Ohne echtes Netz schlaegt die Verbindung zu einer nicht erreichbaren
// Adresse fehl -- Update() muss das als "nicht erreichbar" behandeln, nicht
// mit einem Fehler abbrechen. Bewusst eine sehr kurze Wartezeit (fsockopen-
// Timeout in NasaBridgeClient ist fest auf 3s) -- das Hoerfenster selbst
// wird bei einer fehlgeschlagenen Verbindung nicht mehr durchlaufen.

$GLOBALS['ips']['properties']['Host'] = '203.0.113.1'; // TEST-NET-3, garantiert nicht erreichbar
$GLOBALS['ips']['properties']['ListenSeconds'] = 1;
$mod->Update();
check('Update() an nicht erreichbarer Adresse setzt Status 201', $mod->status === 201);
check('Update() protokolliert die Nichterreichbarkeit', count($GLOBALS['ips']['log']) > 0);

$GLOBALS['ips']['properties']['SAMEHS_Active'] = false;
$logCountBefore = count($GLOBALS['ips']['log']);
$mod->Update();
check('Update() bei inaktivem Modul tut nichts', count($GLOBALS['ips']['log']) === $logCountBefore);

// ---------------------------------------------------------------------------
echo "Block 6: Formular -- Doku/Wozu/Über dieses Modul\n";
// ---------------------------------------------------------------------------

function findFormElement(array $items, string $name): ?array
{
    foreach ($items as $item) {
        if (($item['name'] ?? null) === $name) {
            return $item;
        }
        if (isset($item['items']) && is_array($item['items'])) {
            $found = findFormElement($item['items'], $name);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

$form = json_decode($mod->GetConfigurationForm(), true);
$purposePanel = findFormElement($form['elements'], 'PurposeIntroPanel');
check('"Wozu dieses Modul?"-Panel vorhanden', $purposePanel !== null);
$connectionPanel = findFormElement($form['elements'], 'ConnectionPanel');
check('Verbindungs-Panel mit Hörfenster-Feld vorhanden', $connectionPanel !== null && findFormElement([$connectionPanel], 'ListenSeconds') !== null || findFormElement($form['elements'], 'ListenSeconds') !== null);

$licenseHint = end($form['elements']);
check('"Über dieses Modul" steht ganz unten', ($licenseHint['caption'] ?? '') === '🧡  Über dieses Modul');
check('"Über dieses Modul" ist eingeklappt', ($licenseHint['expanded'] ?? true) === false);

$mod->AckPurposeIntro();
$formAfterAck = json_decode($mod->GetConfigurationForm(), true);
check('"Wozu dieses Modul?"-Panel verschwindet nach Bestätigen', findFormElement($formAfterAck['elements'], 'PurposeIntroPanel') === null);

// ---------------------------------------------------------------------------
echo "Block 7: Vollstaendigkeit der Methodenaufrufe\n";
// ---------------------------------------------------------------------------

foreach ([
    ['SamsungEhs/libs/NasaBridgeClient.php', SAMEHS_NasaBridgeClient::class],
    ['SamsungEhs/module.php', SamsungEhs::class],
] as [$file, $class]) {
    $src = file_get_contents(__DIR__ . '/../' . $file);
    preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m);
    $missing = [];
    foreach (array_unique($m[1]) as $method) {
        if (!method_exists($class, $method)) {
            $missing[] = $method;
        }
    }
    check("Alle \$this->…()-Aufrufe in $file definiert", count($missing) === 0, 'fehlt: ' . implode(', ', $missing));
}

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "Alle Pruefungen bestanden.\n";
    exit(0);
}
echo "$failures Pruefung(en) FEHLGESCHLAGEN.\n";
exit(1);
