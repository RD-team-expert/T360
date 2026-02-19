<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Facades\Auth;

/**
 * Class Rejection
 *
 * Represents a rejection event with details such as type, penalty, and associated reason code.
 *
 * Properties:
 * - rejection_type: Either 'block' or 'load'.
 * - rejection_category: Indicates timing of rejection (more_than_6, within_6, after_start).
 * - penalty: The numeric penalty associated.
 *
 * Relationships:
 * - Belongs to a Tenant.
 * - Belongs to a RejectionReasonCode.
 */
class Rejection extends Model
{
     protected $fillable = [
        'tenant_id',
        'type',
        'penalty',
        'carrier_controllable',
        'dispute_status',
        // Old fields - will be removed after migration
        'date',
        'rejection_type',
        'driver_name',
        'rejection_category',
        'reason_code_id',
        'disputed',
        'driver_controllable',
    ];

     /**
      * The attributes that should be cast to native types.
      */
     protected $casts = [
        'carrier_controllable' => 'boolean',
        'date' => 'datetime',  // ← Change from 'datetime' to 'date'
        'disputed' => 'boolean',
        'driver_controllable' => 'boolean',
    ];
    /**
     * Get the rejection reason code associated with the rejection.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reasonCode()
    {
        return $this->belongsTo(RejectionReasonCode::class);
    }

    /**
     * Get the tenant associated with the rejection.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    /**
     * Get the advanced rejection details.
     */
    public function advancedDetail()
    {
        return $this->hasOne(AdvancedRejectionDetail::class);
    }

    /**
     * Get the block rejection details.
     */
    public function blockDetail()
    {
        return $this->hasOne(BlockRejectionDetail::class);
    }

    /**
     * Get the load rejection details.
     */
    public function loadDetail()
    {
        return $this->hasOne(LoadRejectionDetail::class);
    }

    /**
     * Get the detail based on type (polymorphic helper).
     */
    public function getDetailAttribute()
    {
        return match($this->type) {
            'advanced' => $this->advancedDetail,
            'block' => $this->blockDetail,
            'load' => $this->loadDetail,
            default => null,
        };
    }

    /**
     * Scope to filter by tenant (multi-tenancy).
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Check if dispute affects company score.
     */
    public function getAffectsCompanyScoreAttribute(): bool
    {
        // If dispute is won, it doesn't affect company score
        if ($this->dispute_status === 'won') {
            return false;
        }

        return true;
    }

    /**
     * Check if affects driver score.
     */
    public function getAffectsDriverScoreAttribute(): bool
    {
        // If dispute is won AND not carrier controllable, exclude from driver score
        if ($this->dispute_status === 'won' && !$this->carrier_controllable) {
            return false;
        }

        return true;
    }

    protected function serializeDate(\DateTimeInterface $date)
{
    return $date->format('Y-m-d');
}

    /**
     * Boot the model and apply the TenantScope if a user is authenticated.
     *
     * @return void
     */
    protected static function booted()
    {
        if (Auth::check()) {
            static::addGlobalScope(new TenantScope);
        }
    }
}
