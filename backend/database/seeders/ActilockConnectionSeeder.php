<?php

namespace Database\Seeders;

use App\Models\ActilockConnection;
use App\Models\MachineConnection;
use Illuminate\Database\Seeder;

/**
 * Seeds a default ACTILOCK connection for development/demo.
 * Run: php artisan db:seed --class=ActilockConnectionSeeder
 */
class ActilockConnectionSeeder extends Seeder
{
    public function run(): void
    {
        $connection = MachineConnection::firstOrCreate(
            ['protocol' => MachineConnection::PROTOCOL_ACTILOCK, 'name' => 'AEG Default'],
            [
                'description' => 'ACTI Edge Gateway — default interlock connection',
                'is_active' => true,
                'status' => MachineConnection::STATUS_DISCONNECTED,
            ]
        );

        ActilockConnection::firstOrCreate(
            ['machine_connection_id' => $connection->id],
            [
                'document' => 'ACTILOCK_DEFAULT',
                'site' => 'SITE01',
                'system' => 'AEG-001',
                'ressource' => 'R1',
                'operation' => 'OP-100',
                'user' => 'admin',
                'listen_host' => '0.0.0.0',
                'listen_port' => 5000,
                'max_plc_connections' => 50,
                'engine_host' => '192.168.1.10',
                'engine_port' => 5000,
                'lib_path' => '/usr/lib/lib_actilock.so',
                'ffi_timeout_seconds' => 5,
                'tcp_read_timeout_seconds' => 5,
            ]
        );

        $this->command->info('ACTILOCK default connection seeded.');
    }
}
