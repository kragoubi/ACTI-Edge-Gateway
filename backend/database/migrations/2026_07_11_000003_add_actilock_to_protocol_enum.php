<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'actilock' to the machine_connections.protocol CHECK constraint.
 * Laravel's enum() creates a CHECK, not a PostgreSQL enum type.
 * We must drop the old constraint and recreate it with 'actilock' added.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE machine_connections DROP CONSTRAINT machine_connections_protocol_check');
        DB::statement("ALTER TABLE machine_connections ADD CONSTRAINT machine_connections_protocol_check CHECK (((protocol)::text = ANY ((ARRAY['mqtt'::character varying, 'opcua'::character varying, 'modbus'::character varying, 'rest'::character varying, 'actilock'::character varying])::text[])))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE machine_connections DROP CONSTRAINT machine_connections_protocol_check');
        DB::statement("ALTER TABLE machine_connections ADD CONSTRAINT machine_connections_protocol_check CHECK (((protocol)::text = ANY ((ARRAY['mqtt'::character varying, 'opcua'::character varying, 'modbus'::character varying, 'rest'::character varying])::text[])))");
    }
};
