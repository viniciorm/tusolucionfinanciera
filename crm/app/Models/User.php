<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'google_subject',
        'role',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isAdvisor(): bool
    {
        return $this->role === 'advisor';
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'actor_user_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'assigned_user_id');
    }

    public function leadManagements(): HasMany
    {
        return $this->hasMany(LeadManagement::class, 'assigned_user_id');
    }
}
