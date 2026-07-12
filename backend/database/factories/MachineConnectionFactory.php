<?php

namespace Database\Factories;

use App\Models\MachineConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MachineConnection>
 */
class MachineConnectionFactory extends Factory
{
    protected $model = MachineConnection::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'protocol' => fake()->randomElement(['mqtt', 'opcua', 'modbus', 'rest']),
            'is_active' => true,
            'status' => MachineConnection::STATUS_DISCONNECTED,
            'status_message' => null,
            'last_connected_at' => null,
            'messages_received' => 0,
        ];
    }

    public function actilock(): static
    {
        return $this->state(fn () => [
            'protocol' => MachineConnection::PROTOCOL_ACTILOCK,
        ]);
    }
}
