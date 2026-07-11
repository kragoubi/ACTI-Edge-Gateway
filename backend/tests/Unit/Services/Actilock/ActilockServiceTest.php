<?php

namespace Tests\Unit\Services\Actilock;

use App\Models\ActilockConnection;
use App\Models\ActilockInterlockLog;
use App\Models\MachineConnection;
use App\Services\Connectivity\Actilock\ActilockService;
use App\Services\Machine\RuntimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ActilockServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActilockService $service;

    private RuntimeMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monitor = Mockery::mock(RuntimeMonitor::class);
        $this->monitor->shouldReceive('heartbeat')->andReturnNull();

        $this->service = new ActilockService($this->monitor);
    }

    protected function tearDown(): void
    {
        $this->service->shutdown();
        Mockery::close();
        parent::tearDown();
    }

    private function createConnection(): ActilockConnection
    {
        $machineConn = MachineConnection::create([
            'name' => 'Test ACTILOCK',
            'protocol' => MachineConnection::PROTOCOL_ACTILOCK,
            'is_active' => true,
            'status' => MachineConnection::STATUS_DISCONNECTED,
        ]);

        return ActilockConnection::create([
            'machine_connection_id' => $machineConn->id,
            'site' => 'TEST_SITE',
            'ressource' => 'R_TEST',
            'operation' => 'OP_TEST',
            'user' => 'test_user',
            'engine_host' => '127.0.0.1',
            'engine_port' => 5000,
            'listen_host' => '0.0.0.0',
            'listen_port' => 5001,
            'lib_path' => '/usr/lib/lib_actilock.so',
            'ffi_timeout_seconds' => 5,
            'tcp_read_timeout_seconds' => 5,
            'status' => ActilockConnection::STATUS_DISCONNECTED,
        ]);
    }

    // ── Parser integration ────────────────────────────────────────

    public function test_get_parser_returns_singleton(): void
    {
        $parser1 = $this->service->getParser();
        $parser2 = $this->service->getParser();

        $this->assertSame($parser1, $parser2);
    }

    public function test_build_response_frame_ready(): void
    {
        $frame = $this->service->buildResponseFrame(
            ActilockInterlockLog::FRAME_START,
            ['success' => true, 'response' => 'READY'],
        );

        $parser = $this->service->getParser();
        $decoded = $parser->decode($frame);

        $this->assertTrue($decoded['valid']);
        $this->assertSame(ActilockInterlockLog::FRAME_START, $decoded['code']);
        $this->assertSame('READY', $decoded['payload']);
    }

    public function test_build_response_frame_error(): void
    {
        $frame = $this->service->buildResponseFrame(
            ActilockInterlockLog::FRAME_START,
            ['success' => false, 'response' => 'HOLD', 'error' => 'SFC not expected'],
        );

        $parser = $this->service->getParser();
        $decoded = $parser->decode($frame);

        $this->assertTrue($decoded['valid']);
        $this->assertStringStartsWith('ERROR`ACTILOCK_ERROR`HOLD', $decoded['payload']);
    }

    // ── Frame handling with mocked worker ─────────────────────────

    public function test_handle_frame_rejects_malformed_frame(): void
    {
        $config = $this->createConnection();

        $result = $this->service->handleFrame($config, 'garbage');

        $this->assertFalse($result['success']);
        $this->assertNull($result['frame_code']);
        $this->assertArrayHasKey('error', $result);

        $config->refresh();
        $this->assertSame(1, $config->interlocks_rejected);
    }

    public function test_handle_frame_logs_rejection_without_log_entry(): void
    {
        $config = $this->createConnection();

        $result = $this->service->handleFrame($config, 'garbage');

        $this->assertFalse($result['success']);

        $logCount = ActilockInterlockLog::count();
        $this->assertSame(0, $logCount, 'Malformed frames should not create interlock log entries');

        $config->refresh();
        $this->assertSame(1, $config->interlocks_rejected);
    }

    // ── Status ────────────────────────────────────────────────────

    public function test_get_status_returns_structured_data(): void
    {
        $config = $this->createConnection();

        $status = $this->service->getStatus($config);

        $this->assertArrayHasKey('id', $status);
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('engine', $status);
        $this->assertArrayHasKey('interlocks_total', $status);
        $this->assertSame($config->id, $status['id']);
        $this->assertSame('disconnected', $status['status']);
    }

    // ── Model helpers ─────────────────────────────────────────────

    public function test_actilock_connection_status_transitions(): void
    {
        $config = $this->createConnection();

        $config->markConnected();
        $config->refresh();
        $this->assertSame(ActilockConnection::STATUS_CONNECTED, $config->status);
        $this->assertNotNull($config->last_connected_at);

        $config->markError('test error');
        $config->refresh();
        $this->assertSame(ActilockConnection::STATUS_ERROR, $config->status);
        $this->assertSame('test error', $config->status_message);

        $config->markDisconnected('manual');
        $config->refresh();
        $this->assertSame(ActilockConnection::STATUS_DISCONNECTED, $config->status);
    }

    public function test_actilock_connection_counters(): void
    {
        $config = $this->createConnection();

        $config->incrementStartCount();
        $config->refresh();
        $this->assertSame(1, $config->start_count);
        $this->assertSame(1, $config->interlocks_total);

        $config->incrementCompleteCount();
        $config->refresh();
        $this->assertSame(1, $config->complete_count);

        $config->incrementNclogCount();
        $config->refresh();
        $this->assertSame(1, $config->nclog_count);

        $config->incrementRejected();
        $config->refresh();
        $this->assertSame(1, $config->interlocks_rejected);
    }

    public function test_actilock_connection_status_color(): void
    {
        $config = $this->createConnection();

        $this->assertSame('slate', $config->statusColor());

        $config->markConnected();
        $this->assertSame('green', $config->statusColor());

        $config->markError('err');
        $this->assertSame('red', $config->statusColor());

        $config->update(['status' => ActilockConnection::STATUS_CONNECTING]);
        $this->assertSame('yellow', $config->statusColor());
    }

    public function test_actilock_connection_address_helpers(): void
    {
        $config = $this->createConnection();

        $this->assertSame('0.0.0.0:5001', $config->listenAddress());
        $this->assertSame('127.0.0.1:5000', $config->engineAddress());
    }

    // ── InterlockLog model ────────────────────────────────────────

    public function test_interlock_log_frame_labels(): void
    {
        $this->assertSame('START', ActilockInterlockLog::FRAME_LABELS[ActilockInterlockLog::FRAME_START]);
        $this->assertSame('COMPLETE', ActilockInterlockLog::FRAME_LABELS[ActilockInterlockLog::FRAME_COMPLETE]);
        $this->assertSame('NCLOGCOMPLETE', ActilockInterlockLog::FRAME_LABELS[ActilockInterlockLog::FRAME_NCLOGCOMPLETE]);
        $this->assertSame('PRODUCTSTATUS', ActilockInterlockLog::FRAME_LABELS[ActilockInterlockLog::FRAME_PRODUCTSTATUS]);
    }

    public function test_interlock_log_frame_label_method(): void
    {
        $config = $this->createConnection();

        $log = ActilockInterlockLog::create([
            'actilock_connection_id' => $config->id,
            'machine_connection_id' => $config->machine_connection_id,
            'frame_code' => ActilockInterlockLog::FRAME_START,
            'frame_label' => 'START',
            'is_accepted' => true,
            'duration_ms' => 15,
            'ffi_success' => true,
            'event_timestamp' => now(),
        ]);

        $this->assertSame('START', $log->frameLabel());
    }
}
