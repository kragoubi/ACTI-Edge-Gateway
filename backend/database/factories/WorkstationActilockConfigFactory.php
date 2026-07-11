<?php

namespace Database\Factories;

use App\Models\ActilockConnection;
use App\Models\Workstation;
use App\Models\WorkstationActilockConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkstationActilockConfigFactory extends Factory
{
    protected $model = WorkstationActilockConfig::class;

    public function definition(): array
    {
        return [
            'workstation_id' => Workstation::factory(),
            'actilock_connection_id' => ActilockConnection::factory(),
            'plc_ip' => $this->faker->ipv4(),
            'resource' => 'R_' . strtoupper($this->faker->lexify('???_#####')),
            'operation' => 'OP_' . strtoupper($this->faker->lexify('???_#####')),
            'user' => $this->faker->username(),
            'sfc_prefix' => '',
            'site' => '',
            'system' => '',
            'is_active' => true,
        ];
    }
}
