<?php

// SAMEHS_NasaBridgeClient -- liest das Samsung-NASA-Protokoll (der interne
// Bus zwischen Aussen-/Innengeraet/Wifi-Kit am F1/F2-Anschluss, RS485) ueber
// einen RS485-zu-TCP-Bruecken-Adapter (z. B. Waveshare RS485-Ethernet,
// Elfin EW11, ESPHome-Stream-Server -- siehe Formular-Hilfe). KEIN Modbus:
// eigenes Paketformat mit Start-/Endbyte, CRC16 und einer festen
// Nachrichtennummer je Messwert.
//
// Anders als WPHub/WPModbusHub fragt dieses Modul nichts aktiv ab, sondern
// hoert waehrend eines begrenzten Zeitfensters auf den ohnehin laufenden
// Bus-Verkehr (Aussen-/Innengeraet tauschen periodisch Statuswerte aus) und
// uebernimmt die zuletzt gesehenen Werte der bekannten Nachrichtennummern.
// Bewusst rein lesend fuer die erste Version -- keine eigenen Pakete senden.
//
// Protokoll-Referenz: echoDaveD/ehs_sentinel_hacs_integration
// (nasa_packet.py/nasa_message.py/data/nasa_repository.yml), aktiv gepflegte
// Home-Assistant-Integration. Paketaufbau UND CRC16-Algorithmus (CRC-16/
// XMODEM, Polynom 0x1021, Startwert 0) gegen die dortige Referenz-
// Implementierung verifiziert -- ein selbst erzeugtes Testpaket
// (Nachricht 0x8204 "Outdoor temperature") wurde mit der echten Python-
// Klasse geparst und lieferte denselben Wert wie dieser PHP-Nachbau (siehe
// .tools/test-module.php, Block 2). Zusaetzlich verifiziert: ein echtes,
// im Referenz-Repo als Beispiel hinterlegtes Aufzeichnungspaket
// (Nachricht 0x4076) validiert mit demselben CRC16-Nachbau korrekt.
//
// Globaler Klassenname bewusst mit SAMEHS_-Praefix (Verbund-Konvention
// 25.07.2026).

class SAMEHS_NasaBridgeClient
{
    const PACKET_START = 0x32;
    const PACKET_END   = 0x34;

    public $host;
    public $port;
    public $lastError = '';

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Verbindet fuer hoechstens $seconds Sekunden, sammelt alle in dieser
     * Zeit eintreffenden Bytes und extrahiert daraus jedes gueltige NASA-
     * Paket. Rueckgabe: [messageNumber(int) => signedValue(int)] -- bei
     * mehrfach gesehener Nachricht gewinnt der zuletzt eingetroffene Wert.
     * NULL nur, wenn ueberhaupt keine Verbindung zustande kam.
     */
    public function listen(float $seconds): ?array
    {
        $this->lastError = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            $this->lastError = 'connect: ' . $errstr;
            return null;
        }
        stream_set_blocking($sock, false);

        $buffer = '';
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 4096);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            } else {
                // Kurze Pause statt Busy-Loop, solange nichts ansteht.
                usleep(20000);
            }
        }
        @fclose($sock);

        return $this->extractMessages($buffer);
    }

    /**
     * Sucht im gepufferten Byte-Strom alle gueltigen NASA-Pakete und liefert
     * deren Nachrichten zusammengefuehrt zurueck. Oeffentlich (statt privat),
     * damit der Pruefstand sie direkt mit einem vorgegebenen Byte-Strom
     * testen kann, ohne echtes TCP.
     */
    public function extractMessages(string $buffer): array
    {
        $out = [];
        $len = strlen($buffer);
        $i = 0;
        while ($i < $len) {
            if (ord($buffer[$i]) !== self::PACKET_START) {
                $i++;
                continue;
            }
            if ($i + 3 > $len) {
                break; // Groessenfeld noch nicht vollstaendig eingetroffen.
            }
            $size = (ord($buffer[$i + 1]) << 8) | ord($buffer[$i + 2]);
            $packetLen = $size + 2; // siehe parse(): packet_size+2 == len(packet)
            if ($packetLen < 14 || $packetLen > 512) {
                $i++; // Unplausible Groesse -- kein echtes Startbyte, weitersuchen.
                continue;
            }
            if ($i + $packetLen > $len) {
                break; // Paket noch nicht vollstaendig eingetroffen.
            }
            $candidate = substr($buffer, $i, $packetLen);
            $messages = $this->parsePacket($candidate);
            if ($messages === null) {
                $i++; // CRC/Formatfehler -- Startbyte war Zufallstreffer, weitersuchen.
                continue;
            }
            foreach ($messages as $num => $val) {
                $out[$num] = $val;
            }
            $i += $packetLen;
        }
        return $out;
    }

    /**
     * Zerlegt EIN vollstaendiges, laengengeprueftes Paket. NULL bei
     * ungueltiger CRC oder unplausiblem Aufbau (kein Wurf einer Exception --
     * im echten Bus-Verkehr sind Fehltreffer beim Resync normal, keine
     * Stoerung).
     */
    private function parsePacket(string $packet): ?array
    {
        $n = strlen($packet);
        if ($n < 14 || ord($packet[0]) !== self::PACKET_START || ord($packet[$n - 1]) !== self::PACKET_END) {
            return null;
        }
        $capacity = ord($packet[12]);
        $crcExpected = (ord($packet[$n - 3]) << 8) | ord($packet[$n - 2]);
        $crcActual = $this->crc16Xmodem(substr($packet, 3, $n - 3 - 3));
        if ($crcActual !== $crcExpected) {
            return null;
        }

        $out = [];
        $rest = substr($packet, 13, $n - 13 - 3);
        $depth = 0;
        while ($depth < $capacity && strlen($rest) > 2) {
            $msgNum = (ord($rest[0]) << 8) | ord($rest[1]);
            $msgType = ($msgNum & 0x0600) >> 9;
            $payloadSize = [0 => 1, 1 => 2, 2 => 4][$msgType] ?? null;
            if ($payloadSize === null || strlen($rest) < 2 + $payloadSize) {
                break; // Strukturtyp (3, variable Laenge) wird v1 nicht ausgewertet.
            }
            $payload = substr($rest, 2, $payloadSize);
            $out[$msgNum] = $this->signedFromBytes($payload);
            $rest = substr($rest, 2 + $payloadSize);
            $depth++;
        }
        return $out;
    }

    private function signedFromBytes(string $bytes): int
    {
        $value = 0;
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        $bits = $len * 8;
        $signBit = 1 << ($bits - 1);
        if ($value & $signBit) {
            $value -= (1 << $bits);
        }
        return $value;
    }

    /** CRC-16/XMODEM (Polynom 0x1021, Startwert 0) -- identisch zu Pythons binascii.crc_hqx(data, 0). */
    public function crc16Xmodem(string $data): int
    {
        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8);
            for ($b = 0; $b < 8; $b++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return $crc;
    }
}
