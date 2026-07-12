<?php

namespace Tests\Feature\Web\Admin\Connectivity;

use App\Models\ActilockConnection;
use App\Models\MachineConnection;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationActilockConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkstationActilockConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private MachineConnection $connection;
    private ActilockConnection $actilock;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->connection = MachineConnection::create([
            'name' => 'Test ACTILOCK',
            'protocol' => MachineConnection::PROTOCOL_ACTILOCK,
            'is_active' => true,
            'status' => MachineConnection::STATUS_DISCONNECTED,
        ]);

        $this->actilock = ActilockConnection::factory()->create([
            'machine_connection_id' => $this->connection->id,
        ]);
    }

    // ── Index ──────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.connectivity.actilock.workstation-config.index', $this->connection))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_list_configs(): void
    {
        WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $this->actilock->id,
            'plc_ip' => '192.168.10.51',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.connectivity.actilock.workstation-config.index', $this->connection))
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/connectivity/actilock/WorkstationConfigIndex')
                ->has('configs')
            );
    }

    public function test_index_returns_only_configs_for_this_connection(): void
    {
        WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $this->actilock->id,
            'plc_ip' => '10.0.0.1',
        ]);

        $otherConnection = MachineConnection::create([
            'name' => 'Other',
            'protocol' => MachineConnection::PROTOCOL_ACTILOCK,
            'is_active' => true,
            'status' => 'disconnected',
        ]);
        $otherActilock = ActilockConnection::factory()->create([
            'machine_connection_id' => $otherConnection->id,
        ]);
        WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $otherActilock->id,
            'plc_ip' => '10.0.0.2',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.connectivity.actilock.workstation-config.index', $this->connection));

        $response->assertOk();
        $this->assertDatabaseCount('workstation_actilock_configs', 2);
        $this->assertDatabaseHas('workstation_actilock_configs', [
            'actilock_connection_id' => $this->actilock->id,
            'plc_ip' => '10.0.0.1',
        ]);
        $this->assertDatabaseHas('workstation_actilock_configs', [
            'actilock_connection_id' => $otherActilock->id,
            'plc_ip' => '10.0.0.2',
        ]);
    }

    // ── Create ─────────────────────────────────────────────

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.connectivity.actilock.workstation-config.create', $this->connection))
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/connectivity/actilock/WorkstationConfigCreate')
            );
    }

    // ── Store ──────────────────────────────────────────────

    public function test_admin_can_create_config(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.connectivity.actilock.workstation-config.store', $this->connection), [
                'plc_ip' => '192.168.10.51',
                'resource' => 'STATION_01',
                'operation' => 'ASSEMBLY',
                'user' => 'OPERATOR_01',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('workstation_actilock_configs', [
            'actilock_connection_id' => $this->actilock->id,
            'plc_ip' => '192.168.10.51',
            'resource' => 'STATION_01',
            'operation' => 'ASSEMBLY',
            'user' => 'OPERATOR_01',
        ]);
    }

    public function test_store_requires_plc_ip(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.connectivity.actilock.workstation-config.store', $this->connection), [
                'resource' => 'STATION_01',
            ])
            ->assertSessionHasErrors('plc_ip');
    }

    public function test_store_rejects_duplicate_plc_ip_for_same_connection(): void
    {
        WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $this->actilock->id,
            'plc_ip' => '192.168.10.51',
        ]);

        $before = DB::table('workstation_actilock_configs')
            ->where('actilock_connection_id', $this->actilock->id)
            ->count();

        $this->actingAs($this->admin)
            ->post(route('admin.connectivity.actilock.workstation-config.store', $this->connection), [
                'plc_ip' => '192.168.10.51',
                'resource' => 'OTHER',
            ]);

        $after = DB::table('workstation_actilock_configs')
            ->where('actilock_connection_id', $this->actilock->id)
            ->count();

        $this->assertSame($before, $after, 'Duplicate PLC IP should not create a new config');
    }

    public function test_same_plc_ip_allowed_on_different_connection(): void
    {
        $otherConnection = MachineConnection::create([
            'name' => 'Other ACTILOCK',
            'protocol' => MachineConnection::PROTOCOL_ACTILOCK,
            'is_active' => true,
            'status' => 'disconnected',
        ]);
        $otherActilock = ActilockConnection::create([
            'machine_connection_id' => $otherConnection->id,
            'document' => 'test',
            'site' => 'S2',
            'system' => 'SYS2',
            'listen_host' => '0.0.0.0',
            'listen_port' => 5001,
            'max_plc_connections' => 50,
            'engine_host' => '192.168.1.2',
            'engine_port' => 5000,
            'lib_path' => '/usr/lib/lib_actilock.so',
            'ffi_timeout_seconds' => 5,
            'tcp_read_timeout_seconds' => 5,
            'status' => 'disconnected',
        ]);

        WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $this->actilock->id,
            'plc_ip' => '192.168.10.51',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.connectivity.actilock.workstation-config.store', $otherConnection), [
                'plc_ip' => '192.168.10.51',
                'resource' => 'SAME_IP_DIFF_CONN',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('workstation_actilock_configs', [
            'actilock_connection_id' => $otherActilock->id,
            'plc_ip' => '192.168.10.51',
        ]);
    }

    // ── Edit ───────────────────────────────────────────────

    public function test_admin_can_access_edit_form(): void
    {
        $config = WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $this->actilock->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.connectivity.actilock.workstation-config.edit', [$this->connection, $config]))
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/connectivity/actilock/WorkstationConfigEdit')
                ->has('config')
            );
    }

    // ── Update ─────────────────────────────────────────────

    public function test_admin_can_update_config(): void
    {
        $config = WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $this->actilock->id,
            'plc_ip' => '192.168.10.51',
            'resource' => 'OLD_RESOURCE',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.connectivity.actilock.workstation-config.update', [$this->connection, $config]), [
                'plc_ip' => '192.168.10.51',
                'resource' => 'NEW_RESOURCE',
                'operation' => 'TEST_OPS',
                'user' => 'TEST_USER',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('workstation_actilock_configs', [
            'id' => $config->id,
            'resource' => 'NEW_RESOURCE',
            'operation' => 'TEST_OPS',
        ]);
    }

    public function test_update_requires_plc_ip(): void
    {
        $config = WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $this->actilock->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.connectivity.actilock.workstation-config.update', [$this->connection, $config]), [
                'resource' => 'X',
            ])
            ->assertSessionHasErrors('plc_ip');
    }

    // ── Delete ─────────────────────────────────────────────

    public function test_admin_can_delete_config(): void
    {
        $config = WorkstationActilockConfig::factory()->create([
            'actilock_connection_id' => $this->actilock->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.connectivity.actilock.workstation-config.destroy', [$this->connection, $config]))
            ->assertRedirect();

        $this->assertSoftDeleted('workstation_actilock_configs', ['id' => $config->id]);
    }

    // ── 404 guards ─────────────────────────────────────────

    public function test_non_actilock_connection_returns_404(): void
    {
        $mqttConnection = MachineConnection::create([
            'name' => 'MQTT',
            'protocol' => 'mqtt',
            'is_active' => true,
            'status' => 'disconnected',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.connectivity.actilock.workstation-config.index', $mqttConnection))
            ->assertStatus(404);
    }

    public function test_connection_without_actilock_record_returns_404(): void
    {
        $noActilock = MachineConnection::create([
            'name' => 'ACTILOCK no record',
            'protocol' => MachineConnection::PROTOCOL_ACTILOCK,
            'is_active' => true,
            'status' => 'disconnected',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.connectivity.actilock.workstation-config.index', $noActilock))
            ->assertStatus(404);
    }
}
