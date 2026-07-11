<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActilockConnection extends Model
{
    use HasFactory;
    use SoftDeletesWithAudit;

    const STATUS_DISCONNECTED = 'disconnected';

    const STATUS_CONNECTING = 'connecting';

    const STATUS_CONNECTED = 'connected';

    const STATUS_ERROR = 'error';

    protected $fillable = [
        'machine_connection_id',
        'document',
        'site',
        'system',
        'ressource',
        'operation',
        'user',
        'listen_host',
        'listen_port',
        'max_plc_connections',
        'engine_host',
        'engine_port',
        'lib_path',
        'ffi_timeout_seconds',
        'tcp_read_timeout_seconds',
        'status',
        'status_message',
        'last_connected_at',
        'interlocks_total',
        'interlocks_rejected',
        'start_count',
        'complete_count',
        'nclog_count',
    ];

    protected function casts(): array
    {
        return [
            'listen_port' => 'integer',
            'max_plc_connections' => 'integer',
            'engine_port' => 'integer',
            'ffi_timeout_seconds' => 'integer',
            'tcp_read_timeout_seconds' => 'integer',
            'last_connected_at' => 'datetime',
            'interlocks_total' => 'integer',
            'interlocks_rejected' => 'integer',
            'start_count' => 'integer',
            'complete_count' => 'integer',
            'nclog_count' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MachineConnection::class, 'machine_connection_id');
    }

    public function interlockLogs(): HasMany
    {
        return $this->hasMany(ActilockInterlockLog::class);
    }

    // -- Status helpers --

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_CONNECTED => 'green',
            self::STATUS_CONNECTING => 'yellow',
            self::STATUS_ERROR => 'red',
            default => 'slate',
        };
    }

    public function markConnected(): void
    {
        $this->update([
            'status' => self::STATUS_CONNECTED,
            'status_message' => null,
            'last_connected_at' => now(),
        ]);
    }

    public function markDisconnected(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_DISCONNECTED,
            'status_message' => $reason,
        ]);
    }

    public function markError(string $message): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'status_message' => $message,
        ]);
    }

    // -- Counters --

    public function incrementStartCount(): void
    {
        $this->increment('start_count');
        $this->increment('interlocks_total');
    }

    public function incrementCompleteCount(): void
    {
        $this->increment('complete_count');
    }

    public function incrementNclogCount(): void
    {
        $this->increment('nclog_count');
    }

    public function incrementRejected(): void
    {
        $this->increment('interlocks_rejected');
    }

    // -- Address helpers --

    public function listenAddress(): string
    {
        return "{$this->listen_host}:{$this->listen_port}";
    }

    public function engineAddress(): string
    {
        return "{$this->engine_host}:{$this->engine_port}";
    }
}
