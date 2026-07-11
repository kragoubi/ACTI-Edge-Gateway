<?php

namespace Tests\Unit\Services\Actilock;

use App\Services\Connectivity\Actilock\TcpFrameParser;
use PHPUnit\Framework\TestCase;

class TcpFrameParserTest extends TestCase
{
    private TcpFrameParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TcpFrameParser;
    }

    // ── Decode ────────────────────────────────────────────────────

    public function test_decode_valid_start_frame(): void
    {
        $payload = 'SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operator`MANORDER=OF123';
        $raw = chr(0x02).chr(0x10).chr(strlen($payload)).$payload.chr(0x03);

        $result = $this->parser->decode($raw);

        $this->assertTrue($result['valid']);
        $this->assertSame(TcpFrameParser::CODE_START, $result['code']);
        $this->assertSame($payload, $result['payload']);
        $this->assertNull($result['error']);
    }

    public function test_decode_valid_complete_frame(): void
    {
        $payload = 'SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operator';
        $raw = chr(0x02).chr(0x11).chr(strlen($payload)).$payload.chr(0x03);

        $result = $this->parser->decode($raw);

        $this->assertTrue($result['valid']);
        $this->assertSame(TcpFrameParser::CODE_COMPLETE, $result['code']);
    }

    public function test_decode_valid_nclogcomplete_frame(): void
    {
        $payload = 'SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operator`NCCODE=DEF001`LOCATION=STATION_1`NBDEFAULT=2`REFERENCE=REF001`COMPONENT=PART_A';
        $raw = chr(0x02).chr(0x12).chr(strlen($payload)).$payload.chr(0x03);

        $result = $this->parser->decode($raw);

        $this->assertTrue($result['valid']);
        $this->assertSame(TcpFrameParser::CODE_NCLOGCOMPLETE, $result['code']);
    }

    public function test_decode_valid_productstatus_frame(): void
    {
        $payload = 'PARAMETER=STATUS`SFC=20412441680852';
        $raw = chr(0x02).chr(0x13).chr(strlen($payload)).$payload.chr(0x03);

        $result = $this->parser->decode($raw);

        $this->assertTrue($result['valid']);
        $this->assertSame(TcpFrameParser::CODE_PRODUCTSTATUS, $result['code']);
    }

    public function test_decode_rejects_frame_too_short(): void
    {
        $result = $this->parser->decode(chr(0x02).chr(0x10));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('FRAME_TOO_SHORT', $result['error']);
    }

    public function test_decode_rejects_invalid_stx(): void
    {
        $payload = 'test';
        $raw = chr(0x00).chr(0x10).chr(strlen($payload)).$payload.chr(0x03);

        $result = $this->parser->decode($raw);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('MISSING_STX', $result['error']);
    }

    public function test_decode_rejects_invalid_etx(): void
    {
        $payload = 'test';
        $raw = chr(0x02).chr(0x10).chr(strlen($payload)).$payload.chr(0x00);

        $result = $this->parser->decode($raw);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('MISSING_ETX', $result['error']);
    }

    public function test_decode_rejects_unknown_code(): void
    {
        $payload = 'test';
        $raw = chr(0x02).chr(0xFF).chr(strlen($payload)).$payload.chr(0x03);

        $result = $this->parser->decode($raw);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('UNKNOWN_CODE', $result['error']);
    }

    public function test_decode_rejects_length_mismatch(): void
    {
        $payload = 'test';
        $raw = chr(0x02).chr(0x10).chr(10).$payload.chr(0x03);

        $result = $this->parser->decode($raw);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('LEN_MISMATCH', $result['error']);
    }

    public function test_decode_rejects_empty_payload(): void
    {
        $raw = chr(0x02).chr(0x10).chr(0).chr(0x03);

        $result = $this->parser->decode($raw);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('EMPTY_PAYLOAD', $result['error']);
    }

    // ── Parse payload ─────────────────────────────────────────────

    public function test_parse_payload_start_frame(): void
    {
        $payload = 'SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operator`MANORDER=OF123';
        $fields = $this->parser->parsePayload($payload);

        $this->assertSame('20412441680852', $fields['SFC']);
        $this->assertSame('R_TF_20412', $fields['RESOURCE']);
        $this->assertSame('OP_TF_20412', $fields['OPERATION']);
        $this->assertSame('operator', $fields['USER']);
        $this->assertSame('OF123', $fields['MANORDER']);
    }

    public function test_parse_payload_productstatus(): void
    {
        $payload = 'PARAMETER=STATUS`SFC=20412441680852';
        $fields = $this->parser->parsePayload($payload);

        $this->assertSame('STATUS', $fields['PARAMETER']);
        $this->assertSame('20412441680852', $fields['SFC']);
    }

    public function test_parse_payload_nclogcomplete(): void
    {
        $payload = 'SFC=SFC001`RESOURCE=RES001`OPERATION=OP001`USER=user01`NCCODE=DEF001`LOCATION=LOC1`NBDEFAULT=3`REFERENCE=REF001`COMPONENT=COMP_A';
        $fields = $this->parser->parsePayload($payload);

        $this->assertSame('SFC001', $fields['SFC']);
        $this->assertSame('DEF001', $fields['NCCODE']);
        $this->assertSame('LOC1', $fields['LOCATION']);
        $this->assertSame('3', $fields['NBDEFAULT']);
    }

    public function test_parse_payload_empty(): void
    {
        $fields = $this->parser->parsePayload('');
        $this->assertSame([], $fields);
    }

    // ── Encode ────────────────────────────────────────────────────

    public function test_encode_start_frame(): void
    {
        $payload = 'SFC=test_sfc`RESOURCE=res01';
        $frame = $this->parser->encode(TcpFrameParser::CODE_START, $payload);

        $this->assertSame(chr(0x02), $frame[0]);
        $this->assertSame(chr(0x10), $frame[1]);
        $this->assertSame(chr(strlen($payload)), $frame[2]);
        $this->assertSame($payload, substr($frame, 3, -1));
        $this->assertSame(chr(0x03), $frame[strlen($frame) - 1]);
    }

    public function test_encode_rejects_invalid_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->encode(0xFF, 'test');
    }

    public function test_encode_rejects_payload_too_large(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->encode(TcpFrameParser::CODE_START, str_repeat('A', 256));
    }

    public function test_encode_roundtrip(): void
    {
        $payload = 'SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operator';
        $frame = $this->parser->encode(TcpFrameParser::CODE_START, $payload);
        $decoded = $this->parser->decode($frame);

        $this->assertTrue($decoded['valid']);
        $this->assertSame(TcpFrameParser::CODE_START, $decoded['code']);
        $this->assertSame($payload, $decoded['payload']);
    }

    // ── Encode response / error ───────────────────────────────────

    public function test_encode_response(): void
    {
        $frame = $this->parser->encodeResponse(TcpFrameParser::CODE_START, 'READY');

        $decoded = $this->parser->decode($frame);
        $this->assertTrue($decoded['valid']);
        $this->assertSame('READY', $decoded['payload']);
    }

    public function test_encode_error(): void
    {
        $frame = $this->parser->encodeError(TcpFrameParser::CODE_START, 'TIMEOUT', 'ACTILOCK did not respond');

        $decoded = $this->parser->decode($frame);
        $this->assertTrue($decoded['valid']);
        $this->assertStringStartsWith('ERROR`TIMEOUT', $decoded['payload']);
        $this->assertStringContainsString('ACTILOCK did not respond', $decoded['payload']);
    }

    // ── Build payload ─────────────────────────────────────────────

    public function test_build_payload(): void
    {
        $payload = $this->parser->buildPayload([
            'SFC' => 'SFC001',
            'RESOURCE' => 'RES001',
            'OPERATION' => 'OP001',
        ]);

        $this->assertSame('SFC=SFC001`RESOURCE=RES001`OPERATION=OP001', $payload);
    }

    public function test_build_payload_roundtrip(): void
    {
        $fields = ['SFC' => 'SFC001', 'RESOURCE' => 'RES001', 'OPERATION' => 'OP001'];
        $payload = $this->parser->buildPayload($fields);
        $parsed = $this->parser->parsePayload($payload);

        $this->assertSame($fields, $parsed);
    }
}
