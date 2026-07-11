<?php

namespace App\Http\Controllers\Web\Admin\Connectivity;

use App\Http\Controllers\Controller;
use App\Models\MachineConnection;
use App\Models\WorkstationActilockConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkstationActilockConfigController extends Controller
{
    public function index(MachineConnection $machineConnection)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);
        $machineConnection->load('actilockConnection');

        $actilock = $machineConnection->actilockConnection;
        abort_unless($actilock, 404);

        $configs = WorkstationActilockConfig::where('actilock_connection_id', $actilock->id)
            ->orderBy('plc_ip')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'plc_ip' => $c->plc_ip,
                'workstation_id' => $c->workstation_id,
                'resource' => $c->resource,
                'operation' => $c->operation,
                'user' => $c->user,
                'sfc_prefix' => $c->sfc_prefix,
                'site' => $c->site,
                'system' => $c->system,
                'is_active' => $c->is_active,
                'created_at' => $c->created_at->diffForHumans(),
            ]);

        return Inertia::render('admin/connectivity/actilock/WorkstationConfigIndex', [
            'connection' => [
                'id' => $machineConnection->id,
                'name' => $machineConnection->name,
            ],
            'configs' => $configs,
        ]);
    }

    public function create(MachineConnection $machineConnection)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);

        return Inertia::render('admin/connectivity/actilock/WorkstationConfigCreate', [
            'connection' => [
                'id' => $machineConnection->id,
                'name' => $machineConnection->name,
            ],
        ]);
    }

    public function store(Request $request, MachineConnection $machineConnection)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);
        $actilock = $machineConnection->actilockConnection;
        abort_unless($actilock, 404);

        $data = $this->validateData($request, $actilock->id);

        WorkstationActilockConfig::create([
            'actilock_connection_id' => $actilock->id,
            'plc_ip' => $data['plc_ip'],
            'workstation_id' => $data['workstation_id'] ?? null,
            'resource' => $data['resource'] ?? '',
            'operation' => $data['operation'] ?? '',
            'user' => $data['user'] ?? '',
            'sfc_prefix' => $data['sfc_prefix'] ?? '',
            'site' => $data['site'] ?? '',
            'system' => $data['system'] ?? '',
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('admin.connectivity.actilock.workstation-config.index', $machineConnection)
            ->with('success', __('Workstation config created.'));
    }

    public function edit(MachineConnection $machineConnection, WorkstationActilockConfig $config)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);

        return Inertia::render('admin/connectivity/actilock/WorkstationConfigEdit', [
            'connection' => [
                'id' => $machineConnection->id,
                'name' => $machineConnection->name,
            ],
            'config' => [
                'id' => $config->id,
                'plc_ip' => $config->plc_ip,
                'workstation_id' => $config->workstation_id,
                'resource' => $config->resource,
                'operation' => $config->operation,
                'user' => $config->user,
                'sfc_prefix' => $config->sfc_prefix,
                'site' => $config->site,
                'system' => $config->system,
                'is_active' => $config->is_active,
            ],
        ]);
    }

    public function update(Request $request, MachineConnection $machineConnection, WorkstationActilockConfig $config)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);
        $actilock = $machineConnection->actilockConnection;
        abort_unless($actilock, 404);

        $data = $this->validateData($request, $actilock->id, $config->id);

        $config->update([
            'plc_ip' => $data['plc_ip'],
            'workstation_id' => $data['workstation_id'] ?? null,
            'resource' => $data['resource'] ?? '',
            'operation' => $data['operation'] ?? '',
            'user' => $data['user'] ?? '',
            'sfc_prefix' => $data['sfc_prefix'] ?? '',
            'site' => $data['site'] ?? '',
            'system' => $data['system'] ?? '',
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('admin.connectivity.actilock.workstation-config.index', $machineConnection)
            ->with('success', __('Workstation config updated.'));
    }

    public function destroy(MachineConnection $machineConnection, WorkstationActilockConfig $config)
    {
        abort_unless($machineConnection->protocol === MachineConnection::PROTOCOL_ACTILOCK, 404);

        $config->delete();

        return redirect()->route('admin.connectivity.actilock.workstation-config.index', $machineConnection)
            ->with('success', __('Workstation config deleted.'));
    }

    private function validateData(Request $request, int $actilockConnectionId, ?int $exceptId = null): array
    {
        $uniquePlcRule = "unique:workstation_actilock_configs,plc_ip,{$actilockConnectionId},actilock_connection_id";

        if ($exceptId) {
            $uniquePlcRule .= ",{$exceptId},id";
        }

        return $request->validate([
            'plc_ip' => ['required', 'string', 'max:45', $uniquePlcRule],
            'workstation_id' => ['nullable', 'integer', 'exists:workstations,id'],
            'resource' => ['nullable', 'string', 'max:255'],
            'operation' => ['nullable', 'string', 'max:255'],
            'user' => ['nullable', 'string', 'max:255'],
            'sfc_prefix' => ['nullable', 'string', 'max:50'],
            'site' => ['nullable', 'string', 'max:255'],
            'system' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
