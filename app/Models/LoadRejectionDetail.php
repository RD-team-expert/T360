<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadRejectionDetail extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'load_rejection_details';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'rejection_id',
        'load_id',
        'driver_name',
        'origin_yard_arrival_at',
        'rejection_reason',
        'rejection_bucket',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'origin_yard_arrival_at' => 'datetime',
    ];

    /**
     * Get the parent rejection record.
     */
    public function rejection(): BelongsTo
    {
        return $this->belongsTo(Rejection::class);
    }

    /**
     * Check if this load is accepted (no rejection reason).
     */
    public function getIsAcceptedAttribute(): bool
    {
        return empty($this->rejection_reason);
    }

    /**
     * Get human-readable status.
     */
    public function getStatusAttribute(): string
    {
        return $this->is_accepted ? 'Accepted' : 'Rejected';
    }

    /**
     * Get the rejection bucket (stored from CSV, NOT calculated).
     */
    public function getBucketAttribute(): string
    {
        if ($this->is_accepted) {
            return 'N/A';
        }

        return $this->rejection_bucket ?? 'Unknown';
    }

    /**
     * Get the penalty multiplier based on bucket.
     */
    public function getPenaltyMultiplierAttribute(): int
    {
        if ($this->is_accepted) {
            return 0;
        }

        // Map bucket string to penalty multiplier
        return match($this->rejection_bucket) {
            'Rejected after start time' => 8,
            'Rejected 0-6 hours before start' => 4,
            'Rejected 6+ hours before start' => 1,
            default => 1, // Default to lowest penalty if unknown
        };
    }

     protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d');
    }
}
