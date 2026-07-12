<?php

namespace App\Services\Connectivity\Actilock;

/**
 * ISA-95 TCP frame encoder/decoder for the ACTILOCK interlock protocol.
 *
 * Frame structure:
 *   ┌──────┬────────┬─────┬──────────┬──────┐
 *   │ STX  │  CODE  │ LEN │ PAYLOAD  │  ETX │
 *   │ 1oct │ 1 oct  │1 oct│ Variable │ 1 oct│
 *   └──────┴────────┴─────┴──────────┴──────┘
 *
 * STX = 0x02, ETX = 0x03
 * CODE: 0x10=Start, 0x11=Complete, 0x12=NcLogComplete, 0x13=ProductStatus
 * LEN:  PAYLOAD byte length (1-255)
 * PAYLOAD: backtick-delimited key=value pairs
 */
class TcpFrameParser
{
    public const STX = 0x02;

    public const ETX = 0x03;

    public const CODE_START = 0x10;

    public const CODE_COMPLETE = 0x11;

    public const CODE_NCLOGCOMPLETE = 0x12;

    public const CODE_PRODUCTSTATUS = 0x13;

    const CODE_INIT = 0x14;

    const CODE_INQUEUE = 0x15;

    const CODE_QUICKCOMPLETE = 0x16;

    const CODE_ISEXPECTEDAT = 0x17;

    const CODE_ISITLOCKABLE = 0x18;

    const CODE_NEXTOP = 0x19;

    public const MAX_FRAME_SIZE = 1024;

    public const MIN_FRAME_SIZE = 4;

    public const VALID_CODES = [
        self::CODE_START,
        self::CODE_COMPLETE,
        self::CODE_NCLOGCOMPLETE,
        self::CODE_PRODUCTSTATUS,
        self::CODE_INIT,
        self::CODE_INQUEUE,
        self::CODE_QUICKCOMPLETE,
        self::CODE_ISEXPECTEDAT,
        self::CODE_ISITLOCKABLE,
        self::CODE_NEXTOP,
    ];

    private const DELIMITER = '`';

    /**
     * Parse a raw TCP frame into its components.
     *
     * @return array{valid: bool, code: ?int, payload: string, error: ?string}
     */
    public function decode(string $raw): array
    {
        $length = strlen($raw);

        if ($length < self::MIN_FRAME_SIZE) {
            return $this->invalid('FRAME_TOO_SHORT', "Frame too short: {$length} bytes (minimum ".self::MIN_FRAME_SIZE.')');
        }

        if (ord($raw[0]) !== self::STX) {
            return $this->invalid('MISSING_STX', sprintf('Invalid STX: 0x%02X (expected 0x02)', ord($raw[0])));
        }

        if (ord($raw[$length - 1]) !== self::ETX) {
            return $this->invalid('MISSING_ETX', sprintf('Invalid ETX: 0x%02X (expected 0x03)', ord($raw[$length - 1])));
        }

        $code = ord($raw[1]);

        if (! in_array($code, self::VALID_CODES, true)) {
            return $this->invalid('UNKNOWN_CODE', sprintf('Unknown frame code: 0x%02X', $code));
        }

        $declaredLen = ord($raw[2]);
        $payload = substr($raw, 3, $length - 4);

        if (strlen($payload) !== $declaredLen) {
            return $this->invalid('LEN_MISMATCH', "LEN={$declaredLen} but actual payload is ".strlen($payload).' bytes');
        }

        if ($declaredLen === 0) {
            return $this->invalid('EMPTY_PAYLOAD', 'Payload is empty');
        }

        return [
            'valid' => true,
            'code' => $code,
            'payload' => $payload,
            'error' => null,
        ];
    }

    /**
     * Parse payload into a key=value map (backtick-delimited).
     */
    public function parsePayload(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $parts = explode(self::DELIMITER, $payload);
        $result = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $eqPos = strpos($part, '=');
            if ($eqPos === false) {
                $result[$part] = '';
            } else {
                $key = substr($part, 0, $eqPos);
                $value = substr($part, $eqPos + 1);
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Build a raw TCP frame from code and payload.
     */
    public function encode(int $code, string $payload): string
    {
        if (! in_array($code, self::VALID_CODES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid frame code: 0x%02X', $code));
        }

        $len = strlen($payload);

        if ($len > 255) {
            throw new \InvalidArgumentException("Payload too large: {$len} bytes (max 255)");
        }

        $frame = chr(self::STX)
            .chr($code)
            .chr($len)
            .$payload
            .chr(self::ETX);

        if (strlen($frame) > self::MAX_FRAME_SIZE) {
            throw new \InvalidArgumentException('Frame exceeds maximum size of '.self::MAX_FRAME_SIZE.' bytes');
        }

        return $frame;
    }

    /**
     * Build a response frame echoing the request code.
     */
    public function encodeResponse(int $code, string $responsePayload): string
    {
        return $this->encode($code, $responsePayload);
    }

    /**
     * Build an error response frame.
     */
    public function encodeError(int $code, string $errorCode, string $message = ''): string
    {
        $payload = 'ERROR'.self::DELIMITER.$errorCode;

        if ($message !== '') {
            $payload .= self::DELIMITER.$message;
        }

        return $this->encode($code, $payload);
    }

    /**
     * Build a payload from key=value pairs.
     */
    public function buildPayload(array $fields): string
    {
        $parts = [];

        foreach ($fields as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return implode(self::DELIMITER, $parts);
    }

    private function invalid(string $code, string $message): array
    {
        return [
            'valid' => false,
            'code' => null,
            'payload' => '',
            'error' => "{$code}: {$message}",
        ];
    }
}
