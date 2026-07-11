<?php

namespace App\Services\Connectivity\Actilock;

use Illuminate\Support\Facades\Log;

/**
 * PHP FFI binding to the native lib_actilock.so library.
 *
 * IMPORTANT CONSTRAINTS:
 * - The .so is NON-REENTRANT: concurrent calls = segfault.
 * - A segfault kills the entire PHP process — always isolate via InterlockWorker.
 * - Every call requires an active connection (Connect() first), otherwise segfault.
 * - Connect() on an unreachable host can crash — probe TCP first.
 */
class ActilockLibrary
{
    private ?FFI $ffi = null;

    private bool $connected = false;

    private const CDEF = <<<'C'
        /* Connection */
        bool ACTILOCK_Connect(const char* host, unsigned short port, const char* document, char** response);
        bool ACTILOCK_NetPing(const char* host);

        /* Info */
        bool ACTILOCK_EngineVersion(char** response);
        bool ACTILOCK_LibraryVersion(char** response);

        /* Product lifecycle */
        bool ACTILOCK_Init(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, const char* manorder, char** response);
        bool ACTILOCK_Start(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, const char* manorder, char** response);
        bool ACTILOCK_Complete(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, char** response);
        bool ACTILOCK_QuickComplete(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, char** response);

        /* Defects */
        bool ACTILOCK_NcLogComplete(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, const char* nccode, const char* location, const char* nbdefault, const char* reference, const char* component, char** response);

        /* Queries */
        bool ACTILOCK_ProductStatus(const char* parameter, const char* sfc, char** response);
        bool ACTILOCK_IsExpectedAt(const char* sfc, const char* operation, char** response);
        bool ACTILOCK_IsItLockable(const char* sfc, char** response);
        bool ACTILOCK_NextOp(const char* router, const char* revision, const char* operation, char** response);

        /* Queue */
        bool ACTILOCK_InQueue(const char* site, const char* sfc, const char* resource, const char* operation, const char* user, const char* nccode, char** response);

        /* Last response */
        const char* ACTILOCK_GetLastResponse(void);
    C;

    public function __construct(private readonly string $soPath) {}

    public function load(): void
    {
        if ($this->ffi !== null) {
            return;
        }

        if (! file_exists($this->soPath)) {
            throw new \RuntimeException("Actilock library not found: {$this->soPath}");
        }

        $this->ffi = FFI::cdef(self::CDEF, $this->soPath);

        Log::info('ActilockLibrary loaded', ['path' => $this->soPath]);
    }

    public function connect(string $host, int $port, string $document): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_Connect($host, (int) $port, $document, FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function netPing(string $host): array
    {
        $this->ensureLoaded();

        $result = $this->ffi->ACTILOCK_NetPing($host);

        return ['success' => (bool) $result, 'response' => $result ? 'OK' : 'UNREACHABLE'];
    }

    public function engineVersion(): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_EngineVersion(FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function libraryVersion(): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_LibraryVersion(FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function start(string $site, string $sfc, string $resource, string $operation, string $user, string $manorder = ''): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_Start($site, $sfc, $resource, $operation, $user, $manorder, FFI::addr($response));

        $parsed = $this->parseResult($result, $response);
        $this->connected = $parsed['success'];

        return $parsed;
    }

    public function complete(string $site, string $sfc, string $resource, string $operation, string $user): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_Complete($site, $sfc, $resource, $operation, $user, FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function quickComplete(string $site, string $sfc, string $resource, string $operation, string $user): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_QuickComplete($site, $sfc, $resource, $operation, $user, FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function ncLogComplete(
        string $site,
        string $sfc,
        string $resource,
        string $operation,
        string $user,
        string $nccode,
        string $location = '',
        string $nbdefault = '',
        string $reference = '',
        string $component = '',
    ): array {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_NcLogComplete(
            $site, $sfc, $resource, $operation, $user,
            $nccode, $location, $nbdefault, $reference, $component,
            FFI::addr($response),
        );

        return $this->parseResult($result, $response);
    }

    public function productStatus(string $parameter, string $sfc): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_ProductStatus($parameter, $sfc, FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function isExpectedAt(string $sfc, string $operation): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_IsExpectedAt($sfc, $operation, FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function isItLockable(string $sfc): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_IsItLockable($sfc, FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function nextOp(string $router, string $revision, string $operation): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_NextOp($router, $revision, $operation, FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function inQueue(string $site, string $sfc, string $resource, string $operation, string $user, string $nccode = ''): array
    {
        $this->ensureLoaded();

        $response = $this->ffi->new('char*');
        $result = $this->ffi->ACTILOCK_InQueue($site, $sfc, $resource, $operation, $user, $nccode, FFI::addr($response));

        return $this->parseResult($result, $response);
    }

    public function getLastResponse(): string
    {
        $this->ensureLoaded();

        return (string) $this->ffi->ACTILOCK_GetLastResponse();
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function unload(): void
    {
        $this->ffi = null;
        $this->connected = false;
    }

    private function ensureLoaded(): void
    {
        if ($this->ffi === null) {
            $this->load();
        }
    }

    private function parseResult(bool $result, FFI\CData $response): array
    {
        $text = $response !== null ? (string) FFI::string($response) : '';

        if ($result && $text !== '') {
            $this->connected = true;
        }

        return [
            'success' => (bool) $result,
            'response' => $text,
        ];
    }
}
