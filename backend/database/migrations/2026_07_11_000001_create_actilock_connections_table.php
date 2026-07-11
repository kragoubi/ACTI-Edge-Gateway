<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACTILOCK interlock connection config, one row per machine_connection
 * of protocol 'actilock'. The interlock:serve daemon loads lib_actilock.so
 * via FFI and bridges TCP PLC frames to the ACTILOCK engine on VM#1.
 *
 * CDC reference: docs/actilock-cdc.md — sections 5, 12 (Phase 1)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actilock_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_connection_id')->constrained('machine_connections')->cascadeOnDelete();

            // ACTILOCK engine identification (VM#1)
            $table->string('document', 255)->default('');       // ACTILOCK document name
            $table->string('site', 255)->default('');           // Site identifier
            $table->string('system', 255)->default('');         // System identifier
            $table->string('ressource', 255)->default('');      // Resource identifier
            $table->string('operation', 255)->default('');      // Operation identifier
            $table->string('user', 255)->default('');           // User identifier for ACTILOCK

            // TCP server config (PLC → AEG)
            $table->string('listen_host', 45)->default('0.0.0.0');  // Bind address
            $table->unsignedSmallInteger('listen_port')->default(5000);  // TCP port for PLC connections
            $table->unsignedSmallInteger('max_plc_connections')->default(50);  // Max simultaneous PLC

            // ACTILOCK engine connection (AEG → VM#1)
            $table->string('engine_host', 255)->default('192.168.1.1');  // VM#1 ACTILOCK IP
            $table->unsignedSmallInteger('engine_port')->default(5000);  // VM#1 ACTILOCK port

            // FFI / process config
            $table->string('lib_path', 500)->default('/usr/lib/lib_actilock.so');  // Path to .so
            $table->unsignedSmallInteger('ffi_timeout_seconds')->default(5);  // FFI call timeout
            $table->unsignedSmallInteger('tcp_read_timeout_seconds')->default(5);  // TCP read timeout

            // Status tracking
            $table->string('status', 20)->default('disconnected');  // disconnected/connecting/connected/error
            $table->string('status_message')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->unsignedInteger('interlocks_total')->default(0);
            $table->unsignedInteger('interlocks_rejected')->default(0);
            $table->unsignedInteger('start_count')->default(0);
            $table->unsignedInteger('complete_count')->default(0);
            $table->unsignedInteger('nclog_count')->default(0);

            $table->timestamps();

            $table->unique('machine_connection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actilock_connections');
    }
};
