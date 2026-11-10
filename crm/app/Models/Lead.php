<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    protected $fillable = [
        'source_key',
        'source_sheet',
        'meta_id',
        'name',
        'email',
        'phone',
        'phone_search',
        'received_at_raw',
        'received_at',
        'campaign',
        'form',
        'notification_status',
        'source_assigned_to',
        'reconciliation_pending',
        'original',
        'last_seen_at',
    ];

    protected $casts = [
        'original' => 'array',
        'reconciliation_pending' => 'boolean',
        'received_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function management(): HasOne
    {
        return $this->hasOne(LeadManagement::class, 'lead_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'lead_id')->latest();
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'lead_id');
    }

    /**
     * Scope query to only leads the given user is authorized to view.
     * Admin: sees all leads.
     * Advisor: sees only leads where lead_management.assigned_user_id === user->id.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('management', function (Builder $q) use ($user) {
            $q->where('assigned_user_id', $user->id);
        });
    }
}
