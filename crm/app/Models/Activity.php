<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    public const TYPE_NOTE = 'note';
    public const TYPE_CALL = 'call';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_MEETING = 'meeting';
    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'lead_id',
        'actor_user_id',
        'type',
        'note',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
