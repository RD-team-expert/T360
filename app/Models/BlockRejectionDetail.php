<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockRejectionDetail extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'block_rejection_details';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'rejection_id',
        'block_id',
        'driver_name',
        'block_start_at',
        'block_end_at',
        'rejected_at',
        'rejection_reason',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'block_start_at' => 'datetime',
        'block_end_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /**
     * Get the parent rejection record.
     */
    public function rejection(): BelongsTo
    {
        return $this->belongsTo(Rejection::class);
    }

    /**
     * Check if this block is accepted (no rejection reason).
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
     * Calculate and return the rejection bucket.
     * Derived from: block_start_at - rejected_at
     */
    public function getBucketAttribute(): string
    {
        if ($this->is_accepted) {
            return 'N/A';
        }

        $blockStart = Carbon::parse($this->block_start_at);
        $rejectedAt = Carbon::parse($this->rejected_at);
        
        // Calculate hours difference
        $hoursDiff = $rejectedAt->diffInHours($blockStart, false);
        
        if ($hoursDiff < 24) {
            return 'Less than 24 hours before start';
        }
        
        return '24+ hours before start';
    }

    /**
     * Get the penalty multiplier based on bucket.
     */
    public function getPenaltyMultiplierAttribute(): int
    {
        if ($this->is_accepted) {
            return 0;
        }

        $bucket = $this->bucket;
        
        if ($bucket === 'Less than 24 hours before start') {
            return 4;
        }
        
        return 1; // 24+ hours before start
    }

     protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d');
    }
}
