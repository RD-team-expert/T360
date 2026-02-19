<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvancedRejectionDetail extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'advanced_rejection_details';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'rejection_id',
        'advanced_block_id',
        'driver_name',
        'week_start_at',
        'week_end_at',
        'impacted_blocks',
        'reason',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'week_start_at' => 'datetime',
        'week_end_at' => 'datetime',
        'impacted_blocks' => 'integer',
    ];

    /**
     * Get the parent rejection record.
     */
    public function rejection(): BelongsTo
    {
        return $this->belongsTo(Rejection::class);
    }

    /**
     * Check if this advanced rejection is accepted (has no reason).
     * Advanced rejections ALWAYS have reasons, so always rejected.
     */
    public function getIsAcceptedAttribute(): bool
    {
        return false; // Advanced rejections are always rejections
    }

    /**
     * Get human-readable status.
     */
    public function getStatusAttribute(): string
    {
        return 'Rejected';
    }

   protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d');
    }
}
