<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkstationActilockConfig extends Model
{
    use HasFactory;
    use SoftDeletesWithAudit;

    protected $fillable = [
        'workstation_id',
        'actilock_connection_id',
        'plc_ip',
        'resource',
        'operation',
        'user',
        'sfc_prefix',
        'site',
        'system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function actilockConnection(): BelongsTo
    {
        return $this->belongsTo(ActilockConnection::class);
    }

    /**
     * Find the per-workstation config for a given PLC IP and ACTILOCK connection.
     */
    public static function findByPlcIp(int $actilockConnectionId, string $plcIp): ?self
    {
        return static::where('actilock_connection_id', $actilockConnectionId)
            ->where('plc_ip', $plcIp)
            ->where('is_active', true)
            ->first();
    }
}
