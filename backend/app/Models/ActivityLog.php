<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class ActivityLog extends Model
{
    protected $fillable = ['user_id', 'action', 'description', 'meta', 'ip_address'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Catat aktivitas admin (siapa melakukan apa dan kapan). */
    public static function record(?User $user, string $action, string $description, array $meta = [], ?Request $request = null): self
    {
        return static::create([
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description,
            'meta' => $meta ?: null,
            'ip_address' => $request?->ip(),
        ]);
    }
}
