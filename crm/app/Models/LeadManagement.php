<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadManagement extends Model
{
    protected $table = 'lead_management';

    public const STATUS_NUEVO = 'Nuevo';
    public const STATUS_CONTACTADO = 'Contactado';
    public const STATUS_EN_SEGUIMIENTO = 'En seguimiento';
    public const STATUS_REUNION_AGENDADA = 'Reunión agendada';
    public const STATUS_CONVERTIDO = 'Convertido';
    public const STATUS_NO_INTERESADO = 'No interesado';

    public const VALID_STATUSES = [
        self::STATUS_NUEVO,
        self::STATUS_CONTACTADO,
        self::STATUS_EN_SEGUIMIENTO,
        self::STATUS_REUNION_AGENDADA,
        self::STATUS_CONVERTIDO,
        self::STATUS_NO_INTERESADO,
    ];

    protected $fillable = [
        'lead_id',
        'assigned_user_id',
        'status',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
