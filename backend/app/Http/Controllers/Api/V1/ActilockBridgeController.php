<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActilockConnection;
use App\Models\ActilockInterlockLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * REST API endpoints for the Python interlock bridge.
 *
 * The Python bridge (interlock_bridge.py) calls these endpoints to:
 * - Log interlock events (audit trail)
 * - Update connection status (health monitoring)
 * - Increment counters (real-time stats)
 */
class ActilockBridgeController extends Controller
{
    /**
     * Health check — used by the Python bridge to verify Laravel is reachable.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'actilock-bridge',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log an interlock event from the Python bridge.
     *
     * Creates an ActilockInterlockLog entry for the ISA-95 audit trail.
     */
    public function storeEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['required', 'integer', 'exists:actilock_connections,id'],
            'frame_code' => ['required', 'integer', 'in:16,17,18,19'],
            'frame_label' => ['required', 'string', 'max:50'],
            'plc_ip' => ['nullable', 'string', 'max:45'],
            'plc_port' => ['nullable', 'integer'],
            'sfc' => ['nullable', 'string', 'max:255'],
            'result' => ['nullable', 'string'],
            'operation' => ['nullable', 'string', 'max:255'],
            'user' => ['nullable', 'string', 'max:255'],
            'is_accepted' => ['required', 'boolean'],
            'actilock_response' => ['nullable', 'string'],
            'actilock_error' => ['nullable', 'string'],
            'duration_ms' => ['nullable', 'integer'],
            'ffi_success' => ['required', 'boolean'],
            'raw_request' => ['nullable', 'string'],
            'raw_response' => ['nullable', 'string'],
            'correlation_id' => ['nullable', 'string', 'max:36'],
        ]);

        $connection = ActilockConnection::findOrFail($data['connection_id']);

        ActilockInterlockLog::create([
            'actilock_connection_id' => $data['connection_id'],
            'machine_connection_id' => $connection->machine_connection_id,
            'frame_code' => $data['frame_code'],
            'frame_label' => $data['frame_label'],
            'plc_ip' => $data['plc_ip'],
            'plc_port' => $data['plc_port'],
            'sfc' => $data['sfc'],
            'result' => $data['result'],
            'operation' => $data['operation'],
            'user' => $data['user'],
            'is_accepted' => $data['is_accepted'],
            'actilock_response' => $data['actilock_response'],
            'actilock_error' => $data['actilock_error'],
            'duration_ms' => $data['duration_ms'],
            'ffi_success' => $data['ffi_success'],
            'raw_request' => $data['raw_request'],
            'raw_response' => $data['raw_response'],
            'event_timestamp' => now(),
            'correlation_id' => $data['correlation_id'] ?? Str::uuid()->toString(),
        ]);

        return response()->json(['status' => 'ok'], 201);
    }

    /**
     * Update connection status from the Python bridge.
     *
     * Called by the bridge when:
     - It connects/disconnects from ACTILOCK engine
     - Health check passes/fails
     - Version info changes
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['required', 'integer', 'exists:actilock_connections,id'],
            'status' => ['required', 'string', 'in:connected,disconnected,error,connecting'],
            'status_message' => ['nullable', 'string'],
            'version' => ['nullable', 'string', 'max:100'],
            'connected' => ['required', 'boolean'],
        ]);

        $connection = ActilockConnection::findOrFail($data['connection_id']);

        $update = [
            'status' => $data['status'],
            'status_message' => $data['status_message'],
        ];

        if ($data['connected']) {
            $update['last_connected_at'] = now();
        }

        $connection->update($update);

        // Also update the parent MachineConnection
        $connection->connection()->update([
            'status' => $data['status'],
            'status_message' => $data['status_message'],
            'last_connected_at' => $data['connected'] ? now() : $connection->connection->last_connected_at,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Increment a counter on the connection.
     *
     * Called by the bridge after processing each frame.
     */
    public function increment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['required', 'integer', 'exists:actilock_connections,id'],
            'field' => ['required', 'string', 'in:start_count,complete_count,nclog_count,interlocks_rejected,interlocks_total'],
        ]);

        $connection = ActilockConnection::findOrFail($data['connection_id']);

        $connection->increment($data['field']);

        if ($data['field'] !== 'interlocks_rejected' && $data['field'] !== 'interlocks_total') {
            $connection->increment('interlocks_total');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Get connection config for the Python bridge.
     *
     * Returns the full ActilockConnection config so the bridge
     * can be configured from the admin UI.
     */
    public function getConfig(Request $request, int $id): JsonResponse
    {
        $connection = ActilockConnection::with('connection')->findOrFail($id);

        return response()->json([
            'id' => $connection->id,
            'machine_connection_id' => $connection->machine_connection_id,
            'name' => $connection->connection->name,
            'document' => $connection->document,
            'site' => $connection->site,
            'system' => $connection->system,
            'listen_host' => $connection->listen_host,
            'listen_port' => $connection->listen_port,
            'max_plc_connections' => $connection->max_plc_connections,
            'engine_host' => $connection->engine_host,
            'engine_port' => $connection->engine_port,
            'lib_path' => $connection->lib_path,
            'ffi_timeout_seconds' => $connection->ffi_timeout_seconds,
            'tcp_read_timeout_seconds' => $connection->tcp_read_timeout_seconds,
            'status' => $connection->status,
            'status_message' => $connection->status_message,
        ]);
    }

    /**
     * Test connection to ACTILOCK engine (called from admin UI).
     *
     * Makes a TCP probe to verify the engine is reachable.
     */
    public function testConnection(Request $request, int $id): JsonResponse
    {
        $connection = ActilockConnection::findOrFail($id);

        $host = $connection->engine_host;
        $port = $connection->engine_port;

        try {
            $fp = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($fp) {
                fclose($fp);

                return response()->json([
                    'status' => 'ok',
                    'message' => "TCP connection to {$host}:{$port} successful",
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => "Connection failed: {$errstr} (code: {$errno})",
                ], 422);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => "Connection failed: {$e->getMessage()}",
            ], 422);
        }
    }
}
