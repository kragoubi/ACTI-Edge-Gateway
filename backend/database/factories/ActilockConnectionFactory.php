<?php

namespace Database\Factories;

use App\Models\ActilockConnection;
use App\Models\MachineConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActilockConnection>
 */
class ActilockConnectionFactory extends Factory
{
    protected $model = ActilockConnection::class;

    public function definition(): array
    {
        return [
            'machine_connection_id' => MachineConnection::factory(),
            'document' => fake()->word(),
            'site' => fake()->city(),
            'system' => 'AEG-'.fake()->unique()->numberBetween(1, 999),
            'ressource' => strtoupper(fake()->randomLetter().fake()->numberBetween(1, 99)),
            'operation' => 'OP-'.fake()->numberBetween(100, 999),
            'user' => fake()->userName(),
            'listen_host' => '0.0.0.0',
            'listen_port' => fake()->numberBetween(5000, 5999),
            'max_plc_connections' => 50,
            'engine_host' => '192.168.1.'.fake()->numberBetween(1, 254),
            'engine_port' => 5000,
            'lib_path' => '/usr/lib/lib_actilock.so',
            'ffi_timeout_seconds' => 5,
            'tcp_read_timeout_seconds' => 5,
            'status' => ActilockConnection::STATUS_DISCONNECTED,
            'status_message' => null,
            'last_connected_at' => null,
            'interlocks_total' => 0,
            'interlocks_rejected' => 0,
            'start_count' => 0,
            'complete_count' => 0,
            'nclog_count' => 0,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActilockConnection::STATUS_CONNECTED,
            'last_connected_at' => now(),
        ]);
    }

    public function error(?string $message = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActilockConnection::STATUS_ERROR,
            'status_message' => $message ?? fake()->sentence(),
        ]);
    }
}
