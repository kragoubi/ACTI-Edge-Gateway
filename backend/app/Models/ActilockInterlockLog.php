<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log for every interlock request/response cycle.
 * Records the full lifecycle of a TCP frame from PLC through ACTILOCK.
 */
class ActilockInterlockLog extends Model
{
    public $timestamps = false;

    const FRAME_START = 0x10;

    const FRAME_COMPLETE = 0x11;

    const FRAME_NCLOGCOMPLETE = 0x12;

    const FRAME_PRODUCTSTATUS = 0x13;

    const FRAME_LABELS = [
        self::FRAME_START => 'START',
        self::FRAME_COMPLETE => 'COMPLETE',
        self::FRAME_NCLOGCOMPLETE => 'NCLOGCOMPLETE',
        self::FRAME_PRODUCTSTATUS => 'PRODUCTSTATUS',
    ];

    protected $fillable = [
        'actilock_connection_id',
        'machine_connection_id',
        'frame_code',
        'frame_label',
        'plc_ip',
        'plc_port',
        'sfc',
        'result',
        'operation',
        'user',
        'is_accepted',
        'actilock_response',
        'actilock_error',
        'duration_ms',
        'ffi_success',
        'raw_request',
        'raw_response',
        'event_timestamp',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'frame_code' => 'integer',
            'plc_port' => 'integer',
            'is_accepted' => 'boolean',
            'duration_ms' => 'integer',
            'ffi_success' => 'boolean',
            'event_timestamp' => 'datetime',
        ];
    }

    public function actilockConnection(): BelongsTo
    {
        return $this->belongsTo(ActilockConnection::class);
    }

    public function machineConnection(): BelongsTo
    {
        return $this->belongsTo(MachineConnection::class);
    }

    public function frameLabel(): string
    {
        return self::FRAME_LABELS[$this->frame_code] ?? 'UNKNOWN';
    }
}
