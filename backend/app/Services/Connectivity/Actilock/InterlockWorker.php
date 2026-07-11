<?php

namespace App\Services\Connectivity\Actilock;

use Illuminate\Support\Facades\Log;

/**
 * Process-isolated wrapper around ActilockLibrary.
 *
 * The native lib_actilock.so can segfault the host PHP process on:
 * - Concurrent calls (non-reentrant)
 * - Calls without an active connection
 * - Connect() on an unreachable host
 *
 * Solution: run FFI calls in a dedicated child process connected via
 * stdin/stdout pipes. JSON-encoded requests/responses flow over the pipe.
 * If the child crashes, we detect it via proc_get_status() and respawn.
 *
 * Communication protocol:
 *   stdin →  {"method":"start","args":[...]}
 *   stdout → {"success":true,"response":"READY"}
 */
class InterlockWorker
{
    private const CRASH_EXIT_CODE = -11;

    private ?resource $process = null;

    private ?array $pipes = null;

    private int $crashCount = 0;

    private int $lastCrashHour = 0;

    public function __construct(
        private readonly string $soPath,
        private readonly int $timeoutSeconds = 5,
    ) {}

    /**
     * Ensure the worker process is alive, spawning if needed.
     */
    public function ensureWorker(): void
    {
        if ($this->isAlive()) {
            return;
        }

        $this->spawn();
    }

    /**
     * Call an ACTILOCK method through the isolated worker.
     *
     * @param  array<mixed>  $args
     * @return array{success: bool, response: string, error: ?string}
     */
    public function call(string $method, array $args): array
    {
        $this->ensureWorker();

        $request = json_encode([
            'method' => $method,
            'args' => $args,
        ], JSON_THROW_ON_ERROR);

        fwrite($this->pipes[0], $request."\n");
        fflush($this->pipes[0]);

        $response = $this->readResponse();

        if ($response === null) {
            $this->handleCrash("Worker returned no response for method={$method}");

            return ['success' => false, 'response' => '', 'error' => 'Worker unresponsive'];
        }

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return ['success' => false, 'response' => '', 'error' => 'Invalid worker response'];
        }

        return $decoded;
    }

    /**
     * Check if the worker process is still running.
     */
    public function isAlive(): bool
    {
        if ($this->process === null || $this->pipes === null) {
            return false;
        }

        $status = proc_get_status($this->process);

        if (! $status || ! $status['running']) {
            $this->cleanup();

            return false;
        }

        return true;
    }

    /**
     * Kill and respawn the worker.
     */
    public function respawn(): void
    {
        $this->kill();
        $this->spawn();
    }

    /**
     * Kill the worker process.
     */
    public function kill(): void
    {
        $this->cleanup();
    }

    /**
     * Get the number of crashes in the current hour.
     */
    public function getCrashCount(): int
    {
        $currentHour = (int) date('YmHi');

        if ($this->lastCrashHour !== $currentHour) {
            $this->crashCount = 0;
            $this->lastCrashHour = $currentHour;
        }

        return $this->crashCount;
    }

    public function __destruct()
    {
        $this->kill();
    }

    private function spawn(): void
    {
        $workerScript = $this->buildWorkerScript();
        $tmpFile = sys_get_temp_dir().'/aeg_worker_'.md5($this->soPath).'.php';
        file_put_contents($tmpFile, $workerScript);

        $this->process = proc_open(
            ['php', $tmpFile],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
        );

        if (! is_resource($this->process)) {
            throw new \RuntimeException('Failed to spawn InterlockWorker process');
        }

        stream_set_blocking($this->pipes[1], false);

        Log::info('InterlockWorker spawned', ['pid' => getmypid()]);
    }

    private function readResponse(): ?string
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (microtime(true) < $deadline) {
            $read = [$this->pipes[1]];
            $write = null;
            $except = null;

            $changed = stream_select($read, $write, $except, 0, 100_000);

            if ($changed > 0 && isset($read[0])) {
                $line = fgets($read[0]);

                if ($line !== false && $line !== '') {
                    return trim($line);
                }
            }

            if (! $this->isAlive()) {
                return null;
            }
        }

        return null;
    }

    private function handleCrash(string $reason): void
    {
        $this->crashCount++;
        $this->lastCrashHour = (int) date('YmHi');

        Log::critical('InterlockWorker crash', [
            'reason' => $reason,
            'crash_count_this_hour' => $this->crashCount,
        ]);

        $this->cleanup();

        if ($this->crashCount > 3) {
            Log::critical('InterlockWorker crash threshold exceeded', [
                'crashes' => $this->crashCount,
                'action' => 'Manual intervention required',
            ]);
        }
    }

    private function cleanup(): void
    {
        if ($this->pipes !== null) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = null;
        }

        if ($this->process !== null) {
            if (is_resource($this->process)) {
                proc_terminate($this->process, 9);
                proc_close($this->process);
            }
            $this->process = null;
        }
    }

    private function buildWorkerScript(): string
    {
        $soPath = addslashes($this->soPath);

        return <<<PHP
define('STX', 0x02);
define('ETX', 0x03);
define('SO_PATH', '{$soPath}');

\$ffi = FFI::cdef("
    bool ACTILOCK_Connect(const char* host, unsigned short port, const char* document, char** response);
    bool ACTILOCK_NetPing(const char* host);
    bool ACTILOCK_EngineVersion(char** response);
    bool ACTILOCK_LibraryVersion(char** response);
    bool ACTILOCK_Start(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, const char* manorder, char** response);
    bool ACTILOCK_Complete(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, char** response);
    bool ACTILOCK_QuickComplete(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, char** response);
    bool ACTILOCK_NcLogComplete(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, const char* nccode, const char* location, const char* nbdefault, const char* reference, const char* component, char** response);
    bool ACTILOCK_ProductStatus(const char* parameter, const char* sfc, char** response);
    bool ACTILOCK_IsExpectedAt(const char* sfc, const char* operation, char** response);
    bool ACTILOCK_IsItLockable(const char* sfc, char** response);
    bool ACTILOCK_NextOp(const char* router, const char* revision, const char* operation, char** response);
    bool ACTILOCK_InQueue(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, const char* nccode, char** response);
    const char* ACTILOCK_GetLastResponse(void);
", SO_PATH);

function callMethod(FFI \$ffi, string \$method, array \$args): array {
    \$response = \$ffi->new('char*');
    try {
        switch (\$method) {
            case 'connect':
                \$result = \$ffi->ACTILOCK_Connect(\$args[0], (int)\$args[1], \$args[2], FFI::addr(\$response));
                break;
            case 'netPing':
                \$result = \$ffi->ACTILOCK_NetPing(\$args[0]);
                return ['success' => (bool)\$result, 'response' => \$result ? 'OK' : 'UNREACHABLE'];
            case 'engineVersion':
                \$result = \$ffi->ACTILOCK_EngineVersion(FFI::addr(\$response));
                break;
            case 'libraryVersion':
                \$result = \$ffi->ACTILOCK_LibraryVersion(FFI::addr(\$response));
                break;
            case 'start':
                \$result = \$ffi->ACTILOCK_Start(\$args[0], \$args[1], \$args[2], \$args[3], \$args[4], \$args[5] ?? '', FFI::addr(\$response));
                break;
            case 'complete':
                \$result = \$ffi->ACTILOCK_Complete(\$args[0], \$args[1], \$args[2], \$args[3], \$args[4], FFI::addr(\$response));
                break;
            case 'quickComplete':
                \$result = \$ffi->ACTILOCK_QuickComplete(\$args[0], \$args[1], \$args[2], \$args[3], \$args[4], FFI::addr(\$response));
                break;
            case 'ncLogComplete':
                \$result = \$ffi->ACTILOCK_NcLogComplete(\$args[0], \$args[1], \$args[2], \$args[3], \$args[4], \$args[5], \$args[6] ?? '', \$args[7] ?? '', \$args[8] ?? '', \$args[9] ?? '', FFI::addr(\$response));
                break;
            case 'productStatus':
                \$result = \$ffi->ACTILOCK_ProductStatus(\$args[0], \$args[1], FFI::addr(\$response));
                break;
            case 'isExpectedAt':
                \$result = \$ffi->ACTILOCK_IsExpectedAt(\$args[0], \$args[1], FFI::addr(\$response));
                break;
            case 'isItLockable':
                \$result = \$ffi->ACTILOCK_IsItLockable(\$args[0], FFI::addr(\$response));
                break;
            case 'nextOp':
                \$result = \$ffi->ACTILOCK_NextOp(\$args[0], \$args[1], \$args[2], FFI::addr(\$response));
                break;
            case 'inQueue':
                \$result = \$ffi->ACTILOCK_InQueue(\$args[0], \$args[1], \$args[2], \$args[3], \$args[4], \$args[5] ?? '', FFI::addr(\$response));
                break;
            default:
                return ['success' => false, 'response' => '', 'error' => "Unknown method: {\$method}"];
        }
        \$text = \$response !== null ? (string)FFI::string(\$response) : '';
        return ['success' => (bool)\$result, 'response' => \$text];
    } catch (\\Throwable \$e) {
        return ['success' => false, 'response' => '', 'error' => \$e->getMessage()];
    }
}

stream_set_blocking(STDIN, false);
stream_set_blocking(STDOUT, true);

while (true) {
    \$line = fgets(STDIN);
    if (\$line === false || \$line === '') {
        usleep(10000);
        continue;
    }

    \$request = json_decode(trim(\$line), true);
    if (!is_array(\$request) || !isset(\$request['method'])) {
        echo json_encode(['success' => false, 'response' => '', 'error' => 'Invalid request']) . "\\n";
        fflush(STDOUT);
        continue;
    }

    \$result = callMethod(\$ffi, \$request['method'], \$request['args'] ?? []);
    echo json_encode(\$result) . "\\n";
    fflush(STDOUT);
}
PHP;
    }
}
