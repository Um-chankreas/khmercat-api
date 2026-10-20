<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an admin did (approve, ban, delete …), for the activity log.
 */
class AdminAction extends Model
{
    protected $fillable = ['admin_id', 'action', 'target_type', 'target_id', 'summary', 'reason'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public static function record(string $action, string $targetType, int $targetId, string $summary, ?string $reason = null): void
    {
        self::create([
            'admin_id' => auth()->id(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => mb_substr($summary, 0, 255),
            'reason' => $reason,
        ]);
    }
}
