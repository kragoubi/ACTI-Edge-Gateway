<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add soft-delete columns missing from the original actilock_connections migration.
 * SoftDeletesWithAudit requires deleted_at + deleted_by_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actilock_connections', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by_id')->nullable();
            $table->foreign('deleted_by_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('actilock_connections', function (Blueprint $table) {
            $table->dropForeign(['deleted_by_id']);
            $table->dropColumn('deleted_by_id');
            $table->dropSoftDeletes();
        });
    }
};
