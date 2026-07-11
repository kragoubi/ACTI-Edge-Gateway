<?php

namespace App\Http\Controllers\Web\Admin\Connectivity;

use App\Http\Controllers\Controller;
use App\Models\ActilockConnection;
use App\Models\MachineConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ActilockConnectionController extends Controller
{
    public function index()
    {
        $connections = MachineConnection::where('protocol', MachineConnection::PROTOCOL_ACTILOCK)
            ->with('actilockConnection')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/connectivity/actilock/Index', [
            'connections' => $connections->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'is_active' => $c->is_active,
                'status' => $c->status,
                'status_color' => $c->statusColor(),
                'engine_host' => $c->actilockConnection?->engine_host,
                'engine_port' => $c->actilockConnection?->engine_port,
                'listen_host' => $c->actilockConnection?->listen_host,
                'listen_port' => $c->actilockConnection?->listen_port,
                'interlocks_total' => $c->actilockConnection?->interlocks_total ?? 0,
                'last_connected_at' => $c->last_connected_at?->diffForHumans(),
            ]),
        ]);
    }

    public function create()
    {
        return Inertia::render('admin/connectivity/actilock/Create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        DB::transaction(function () use ($data, $request) {
            $connection = MachineConnection::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'protocol' => MachineConnection::PROTOCOL_ACTILOCK,
                'is_active' => $request->boolean('is_active'),
                'status' => MachineConnection::STATUS_DISCONNECTED,
            ]);

            ActilockConnection::create([
                'machine_connection_id' => $connection->id,
                'document' => $data['document'] ?? '',
                'site' => $data['site'] ?? '',
                'system' => $data['system'] ?? '',
                'listen_host' => $data['listen_host'] ?? '0.0.0.0',
                'listen_port' => $data['listen_port'] ?? 5000,
                'max_plc_connections' => $data['max_plc_connections'] ?? 50,
                'engine_host' => $data['engine_host'] ?? '192.168.1.1',
                'engine_port' => $data['engine_port'] ?? 5000,
                'lib_path' => $data['lib_path'] ?? '/usr/lib/lib_actilock.so',
                'ffi_timeout_seconds' => $data['ffi_timeout_seconds'] ?? 5,
                'tcp_read_timeout_seconds' => $data['tcp_read_timeout_seconds'] ?? 5,
            ]);
        });

        return redirect()->route('admin.connectivity.actilock.index')
            ->with('success', __('ACTILOCK connection created.'));
    }

    public function show(MachineConnection $machineConnection)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);
        $machineConnection->load('actilockConnection');
        $actilock = $machineConnection->actilockConnection;

        return Inertia::render('admin/connectivity/actilock/Show', [
            'connection' => [
                'id' => $machineConnection->id,
                'name' => $machineConnection->name,
                'description' => $machineConnection->description,
                'is_active' => $machineConnection->is_active,
                'status' => $machineConnection->status,
                'status_color' => $machineConnection->statusColor(),
                'status_message' => $machineConnection->status_message,
                'actilock' => $actilock ? [
                    'document' => $actilock->document,
                    'site' => $actilock->site,
                    'system' => $actilock->system,
                    'listen_host' => $actilock->listen_host,
                    'listen_port' => $actilock->listen_port,
                    'max_plc_connections' => $actilock->max_plc_connections,
                    'engine_host' => $actilock->engine_host,
                    'engine_port' => $actilock->engine_port,
                    'lib_path' => $actilock->lib_path,
                    'ffi_timeout_seconds' => $actilock->ffi_timeout_seconds,
                    'tcp_read_timeout_seconds' => $actilock->tcp_read_timeout_seconds,
                    'interlocks_total' => $actilock->interlocks_total,
                    'interlocks_rejected' => $actilock->interlocks_rejected,
                    'start_count' => $actilock->start_count,
                    'complete_count' => $actilock->complete_count,
                    'nclog_count' => $actilock->nclog_count,
                    'last_connected_at' => $actilock->last_connected_at?->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    public function edit(MachineConnection $machineConnection)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);
        $machineConnection->load('actilockConnection');
        $actilock = $machineConnection->actilockConnection;

        return Inertia::render('admin/connectivity/actilock/Edit', [
            'connection' => [
                'id' => $machineConnection->id,
                'name' => $machineConnection->name,
                'description' => $machineConnection->description,
                'is_active' => $machineConnection->is_active,
                'actilock' => $actilock ? [
                    'document' => $actilock->document,
                    'site' => $actilock->site,
                    'system' => $actilock->system,
                    'listen_host' => $actilock->listen_host,
                    'listen_port' => $actilock->listen_port,
                    'max_plc_connections' => $actilock->max_plc_connections,
                    'engine_host' => $actilock->engine_host,
                    'engine_port' => $actilock->engine_port,
                    'lib_path' => $actilock->lib_path,
                    'ffi_timeout_seconds' => $actilock->ffi_timeout_seconds,
                    'tcp_read_timeout_seconds' => $actilock->tcp_read_timeout_seconds,
                ] : null,
            ],
        ]);
    }

    public function update(Request $request, MachineConnection $machineConnection)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);
        $data = $this->validateData($request);

        DB::transaction(function () use ($machineConnection, $data, $request) {
            $machineConnection->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ]);

            $actilock = $machineConnection->actilockConnection;
            if ($actilock) {
                $actilock->update([
                    'document' => $data['document'] ?? '',
                    'site' => $data['site'] ?? '',
                    'system' => $data['system'] ?? '',
                    'listen_host' => $data['listen_host'] ?? '0.0.0.0',
                    'listen_port' => $data['listen_port'] ?? 5000,
                    'max_plc_connections' => $data['max_plc_connections'] ?? 50,
                    'engine_host' => $data['engine_host'] ?? '192.168.1.1',
                    'engine_port' => $data['engine_port'] ?? 5000,
                    'lib_path' => $data['lib_path'] ?? '/usr/lib/lib_actilock.so',
                    'ffi_timeout_seconds' => $data['ffi_timeout_seconds'] ?? 5,
                    'tcp_read_timeout_seconds' => $data['tcp_read_timeout_seconds'] ?? 5,
                ]);
            }
        });

        return redirect()->route('admin.connectivity.actilock.show', $machineConnection)
            ->with('success', __('ACTILOCK connection updated.'));
    }

    public function destroy(MachineConnection $machineConnection)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);
        $machineConnection->delete();

        return redirect()->route('admin.connectivity.actilock.index')
            ->with('success', __('ACTILOCK connection deleted.'));
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'document' => ['nullable', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],
            'system' => ['nullable', 'string', 'max:255'],
            'listen_host' => ['required', 'string', 'max:45'],
            'listen_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'max_plc_connections' => ['required', 'integer', 'min:1', 'max:200'],
            'engine_host' => ['required', 'string', 'max:255'],
            'engine_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'lib_path' => ['required', 'string', 'max:500'],
            'ffi_timeout_seconds' => ['required', 'integer', 'min:1', 'max:30'],
            'tcp_read_timeout_seconds' => ['required', 'integer', 'min:1', 'max:30'],
        ]);
    }
}
