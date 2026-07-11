<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for every interlock request/response cycle.
 * One row per TCP frame processed (Start, Complete, NcLogComplete, ProductStatus).
 * Used for ISA-95 compliance traceability and diagnostics.
 *
 * CDC reference: docs/actilock-cdc.md — sections C, D
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actilock_interlock_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actilock_connection_id')->constrained('actilock_connections')->cascadeOnDelete();
            $table->foreignId('machine_connection_id')->nullable()->constrained('machine_connections')->nullOnDelete();

            // Frame identification
            $table->unsignedTinyInteger('frame_code');       // 0x10=Start, 0x11=Complete, 0x12=NcLog, 0x13=ProductStatus
            $table->string('frame_label', 20);               // Human-readable: START, COMPLETE, NCLOGCOMPLETE, PRODUCTSTATUS

            // PLC source
            $table->string('plc_ip', 45)->nullable();        // PLC IP address
            $table->unsignedSmallInteger('plc_port')->nullable();  // PLC source port

            // SFC / Production context (extracted from frame payload)
            $table->string('sfc', 255)->nullable();          // Serial Fabrication Code
            $table->string('result', 255)->nullable();       // Result value (OK/KO/...)
            $table->string('operation', 255)->nullable();    // Operation identifier
            $table->string('user', 255)->nullable();         // User/operator identifier

            // ACTILOCK response
            $table->boolean('is_accepted');                  // ACTILOCK accepted the request
            $table->string('actilock_response', 500)->nullable();  // Raw response from lib_actilock.so
            $table->string('actilock_error', 500)->nullable();     // Error message if call failed

            // Performance tracking
            $table->unsignedInteger('duration_ms');          // Total processing time in milliseconds
            $table->boolean('ffi_success')->default(true);   // Did the FFI call succeed?

            // Raw data for debugging
            $table->text('raw_request')->nullable();         // Raw TCP frame received
            $table->text('raw_response')->nullable();        // Raw TCP frame sent back

            $table->timestamp('event_timestamp', 6);         // Microsecond precision
            $table->uuid('correlation_id')->nullable();      // Link to MachineEvent

            $table->timestamps();

            $table->index(['actilock_connection_id', 'event_timestamp']);
            $table->index(['frame_code']);
            $table->index(['sfc']);
            $table->index(['is_accepted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actilock_interlock_logs');
    }
};
