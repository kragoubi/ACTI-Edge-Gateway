<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workstation ACTILOCK configuration.
 *
 * Maps a PLC (identified by IP) to specific ACTILOCK parameters:
 * resource, operation, user — overriding the global defaults from
 * actilock_connections.
 *
 * The Python bridge calls GET /api/v1/actilock/workstation-config/{plc_ip}
 * to resolve per-station parameters before dispatching to lib_actilock.so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_actilock_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
            $table->foreignId('actilock_connection_id')->constrained('actilock_connections')->cascadeOnDelete();

            // PLC identification (used to match incoming TCP connections)
            $table->string('plc_ip', 45)->default('');

            // Per-workstation ACTILOCK parameters
            $table->string('resource', 255)->default('');
            $table->string('operation', 255)->default('');
            $table->string('user', 255)->default('');
            $table->string('sfc_prefix', 50)->default('');  // optional SFC prefix

            // Optional: override site/system per station
            $table->string('site', 255)->default('');
            $table->string('system', 255)->default('');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // A PLC IP is unique per ACTILOCK connection
            $table->unique(['actilock_connection_id', 'plc_ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_actilock_configs');
    }
};
