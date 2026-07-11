<?php

namespace App\Services\Connectivity\Actilock;

use App\Models\ActilockConnection;
use App\Models\ActilockInterlockLog;
use App\Services\Machine\RuntimeMonitor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates ACTILOCK interlock operations.
 *
 * Responsibilities:
 * - Route TCP frames from PLC to FFI calls via InterlockWorker
 * - Apply default values from ActilockConnection config
 * - Log every action in ActilockInterlockLog (ISA-95 audit trail)
 * - Increment counters on ActilockConnection
 * - Monitor connection health via RuntimeMonitor
 */
class ActilockService
{
    private ?InterlockWorker $worker = null;

    private ?TcpFrameParser $parser = null;

    public function __construct(
        private readonly RuntimeMonitor $monitor,
    ) {}

    /**
     * Process a raw TCP frame received from a PLC.
     *
     * @return array{success: bool, response: string, frame_code: ?int}
     */
    public function handleFrame(ActilockConnection $config, string $rawFrame, ?string $plcIp = null, ?int $plcPort = null): array
    {
        $parser = $this->getParser();
        $parsed = $parser->decode($rawFrame);

        if (! $parsed['valid']) {
            Log::warning('Malformed interlock frame', [
                'connection_id' => $config->id,
                'error' => $parsed['error'],
                'raw' => bin2hex($rawFrame),
            ]);

            $config->incrementRejected();

            return [
                'success' => false,
                'response' => '',
                'frame_code' => null,
                'error' => $parsed['error'],
            ];
        }

        $code = $parsed['code'];
        $payloadFields = $parser->parsePayload($parsed['payload']);

        $correlationId = Str::uuid()->toString();
        $startTime = microtime(true);

        try {
            $result = $this->dispatchByCode($config, $code, $payloadFields);
        } catch (\Throwable $e) {
            Log::error('Interlock dispatch failed', [
                'connection_id' => $config->id,
                'code' => sprintf('0x%02X', $code),
                'error' => $e->getMessage(),
            ]);

            $result = ['success' => false, 'response' => 'DISPATCH_ERROR'];
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->logInterlock($config, $code, $payloadFields, $result, $durationMs, $plcIp, $plcPort, $correlationId, $rawFrame, $this->buildResponseFrame($code, $result));

        $this->updateCounters($config, $code, $result['success']);

        $this->monitor->heartbeat("actilock:{$config->id}");

        return [
            'success' => $result['success'],
            'response' => $result['response'] ?? '',
            'frame_code' => $code,
        ];
    }

    /**
     * Connect to the ACTILOCK engine.
     */
    public function connect(ActilockConnection $config): array
    {
        $worker = $this->getWorker($config);

        $result = $worker->call('connect', [
            $config->engine_host,
            $config->engine_port,
            $config->document,
        ]);

        if ($result['success']) {
            $config->markConnected();
        } else {
            $config->markError($result['error'] ?? $result['response'] ?? 'Connection failed');
        }

        return $result;
    }

    /**
     * Probe TCP reachability of a host.
     */
    public function netPing(string $host): bool
    {
        $worker = $this->getGlobalWorker();

        $result = $worker->call('netPing', [$host]);

        return $result['success'];
    }

    /**
     * Get ACTILOCK engine version.
     */
    public function engineVersion(ActilockConnection $config): array
    {
        return $this->getWorker($config)->call('engineVersion', []);
    }

    /**
     * Get ACTILOCK library version.
     */
    public function libraryVersion(ActilockConnection $config): array
    {
        return $this->getWorker($config)->call('libraryVersion', []);
    }

    /**
     * Get the current status summary for monitoring.
     */
    public function getStatus(ActilockConnection $config): array
    {
        return [
            'id' => $config->id,
            'status' => $config->status,
            'status_message' => $config->status_message,
            'last_connected_at' => $config->last_connected_at?->toIso8601String(),
            'engine' => $config->engineAddress(),
            'worker_alive' => $this->worker?->isAlive() ?? false,
            'worker_crashes' => $this->worker?->getCrashCount() ?? 0,
            'interlocks_total' => $config->interlocks_total,
            'interlocks_rejected' => $config->interlocks_rejected,
            'start_count' => $config->start_count,
            'complete_count' => $config->complete_count,
            'nclog_count' => $config->nclog_count,
        ];
    }

    /**
     * Get the TCP frame parser instance.
     */
    public function getParser(): TcpFrameParser
    {
        if ($this->parser === null) {
            $this->parser = new TcpFrameParser;
        }

        return $this->parser;
    }

    /**
     * Build the TCP response frame for a given result.
     */
    public function buildResponseFrame(int $code, array $result): string
    {
        $parser = $this->getParser();

        $responseText = $result['response'] ?? '';

        if (! $result['success'] && $responseText !== '') {
            return $parser->encodeError($code, 'ACTILOCK_ERROR', $responseText);
        }

        if (! $result['success']) {
            return $parser->encodeError($code, 'NOK', $responseText);
        }

        return $parser->encodeResponse($code, $responseText);
    }

    /**
     * Shutdown the worker process cleanly.
     */
    public function shutdown(): void
    {
        if ($this->worker !== null) {
            $this->worker->kill();
            $this->worker = null;
        }
    }

    private function dispatchByCode(ActilockConnection $config, int $code, array $fields): array
    {
        $worker = $this->getWorker($config);
        $defaults = $this->getDefaults($config);

        return match ($code) {
            ActilockInterlockLog::FRAME_START => $worker->call('start', [
                $fields['SITE'] ?? $defaults['site'],
                $fields['SFC'] ?? '',
                $fields['RESOURCE'] ?? $defaults['resource'],
                $fields['OPERATION'] ?? $defaults['operation'],
                $fields['USER'] ?? $defaults['user'],
                $fields['MANORDER'] ?? '',
            ]),
            ActilockInterlockLog::FRAME_COMPLETE => $worker->call('complete', [
                $fields['SITE'] ?? $defaults['site'],
                $fields['SFC'] ?? '',
                $fields['RESOURCE'] ?? $defaults['resource'],
                $fields['OPERATION'] ?? $defaults['operation'],
                $fields['USER'] ?? $defaults['user'],
            ]),
            ActilockInterlockLog::FRAME_NCLOGCOMPLETE => $worker->call('ncLogComplete', [
                $fields['SITE'] ?? $defaults['site'],
                $fields['SFC'] ?? '',
                $fields['RESOURCE'] ?? $defaults['resource'],
                $fields['OPERATION'] ?? $defaults['operation'],
                $fields['USER'] ?? $defaults['user'],
                $fields['NCCODE'] ?? '',
                $fields['LOCATION'] ?? '',
                $fields['NBDEFAULT'] ?? '',
                $fields['REFERENCE'] ?? '',
                $fields['COMPONENT'] ?? '',
            ]),
            ActilockInterlockLog::FRAME_PRODUCTSTATUS => $worker->call('productStatus', [
                $fields['PARAMETER'] ?? 'STATUS',
                $fields['SFC'] ?? '',
            ]),
            default => ['success' => false, 'response' => '', 'error' => 'Unknown code'],
        };
    }

    private function getDefaults(ActilockConnection $config): array
    {
        return [
            'site' => $config->site,
            'resource' => $config->ressource,
            'operation' => $config->operation,
            'user' => $config->user,
        ];
    }

    private function logInterlock(
        ActilockConnection $config,
        int $code,
        array $fields,
        array $result,
        int $durationMs,
        ?string $plcIp,
        ?int $plcPort,
        string $correlationId,
        string $rawRequest,
        string $rawResponse,
    ): void {
        ActilockInterlockLog::create([
            'actilock_connection_id' => $config->id,
            'machine_connection_id' => $config->machine_connection_id,
            'frame_code' => $code,
            'frame_label' => ActilockInterlockLog::FRAME_LABELS[$code] ?? 'UNKNOWN',
            'plc_ip' => $plcIp,
            'plc_port' => $plcPort,
            'sfc' => $fields['SFC'] ?? null,
            'result' => $result['response'] ?? null,
            'operation' => $fields['OPERATION'] ?? $fields['OP'] ?? null,
            'user' => $fields['USER'] ?? null,
            'is_accepted' => $result['success'] ?? false,
            'actilock_response' => $result['response'] ?? null,
            'actilock_error' => $result['error'] ?? null,
            'duration_ms' => $durationMs,
            'ffi_success' => $result['success'] ?? false,
            'raw_request' => $rawRequest,
            'raw_response' => $rawResponse,
            'event_timestamp' => now(),
            'correlation_id' => $correlationId,
        ]);
    }

    private function updateCounters(ActilockConnection $config, int $code, bool $success): void
    {
        match ($code) {
            ActilockInterlockLog::FRAME_START => $config->incrementStartCount(),
            ActilockInterlockLog::FRAME_COMPLETE => $config->incrementCompleteCount(),
            ActilockInterlockLog::FRAME_NCLOGCOMPLETE => $config->incrementNclogCount(),
            default => null,
        };

        if (! $success) {
            $config->incrementRejected();
        }
    }

    private function getWorker(ActilockConnection $config): InterlockWorker
    {
        if ($this->worker === null) {
            $this->worker = new InterlockWorker(
                $config->lib_path,
                $config->ffi_timeout_seconds,
            );
        }

        $this->worker->ensureWorker();

        return $this->worker;
    }

    private function getGlobalWorker(): InterlockWorker
    {
        if ($this->worker === null) {
            $soPath = config('actilock.so_path', '/usr/lib/lib_actilock.so');
            $timeout = config('actilock.ffi_timeout_seconds', 5);

            $this->worker = new InterlockWorker($soPath, $timeout);
        }

        $this->worker->ensureWorker();

        return $this->worker;
    }
}
