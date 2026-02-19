<?php

namespace App\Services\Acceptance;

use App\Models\Rejection;
use App\Models\BlockRejectionDetail;
use App\Models\LoadRejectionDetail;
use App\Models\AdvancedRejectionDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

class AcceptanceDataService
{
    // ==================== CONSTANTS ====================
    
    /**
     * Keywords that make a rejection NOT carrier controllable
     */
    private const NOT_CARRIER_CONTROLLABLE_KEYWORDS = [
        'AMAZON',
        'AMAZON_PLANNING_ERROR',
        'MECHANICAL',
        'AUTOMATED_REJECTION',
        'WEATHER',
    ];

    // ==================== CONSTRUCTOR ====================

    public function __construct()
    {
        // No dependencies needed after consolidation
    }

    // ==================== ACCEPTANCE INDEX METHODS ====================

    /**
     * Get acceptances for index view with pagination
     */
    public function getAcceptancesIndex(Request $request)
    {
        $user = Auth::user();
        $isSuperAdmin = is_null($user->tenant_id);
        $perPage = $request->get('perPage', 10);
        
        // Get date filter from request
        $dateFilter = $request->get('dateFilter', 'yesterday');
        $dateRange = $this->calculateDateRange($dateFilter);
        
        // Get accepted blocks with date filtering
        $acceptedBlocksQuery = BlockRejectionDetail::query()
            ->where(function($q) {
                $q->whereNull('rejection_reason')
                  ->orWhere('rejection_reason', '');
            })
            ->with(['rejection.tenant']);
        
        // Apply date filter to blocks
        if (!empty($dateRange['start']) && !empty($dateRange['end'])) {
            $acceptedBlocksQuery->where(function($q) use ($dateRange) {
                $q->whereBetween('rejected_at', [$dateRange['start'], $dateRange['end']])
                  ->orWhereBetween('block_start_at', [$dateRange['start'], $dateRange['end']]);
            });
        }
        
        // Get accepted loads with date filtering
        $acceptedLoadsQuery = LoadRejectionDetail::query()
            ->where(function($q) {
                $q->whereNull('rejection_reason')
                  ->orWhere('rejection_reason', '');
            })
            ->with(['rejection.tenant']);
        
        // Apply date filter to loads
        if (!empty($dateRange['start']) && !empty($dateRange['end'])) {
            $acceptedLoadsQuery->whereBetween('origin_yard_arrival_at', [$dateRange['start'], $dateRange['end']]);
        }
        
        // Apply tenant filter for non-super admins
        if (!$isSuperAdmin && $user->tenant_id) {
            $acceptedBlocksQuery->whereHas('rejection', function($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
            $acceptedLoadsQuery->whereHas('rejection', function($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
        }
        
        // Get results
        $acceptedBlocks = $acceptedBlocksQuery->get();
        $acceptedLoads = $acceptedLoadsQuery->get();
        
        // Map blocks to acceptance format
        $mappedBlocks = $acceptedBlocks->map(function ($detail) {
            // Format dates properly
            $date = null;
            if ($detail->rejected_at) {
                $date = $detail->rejected_at instanceof Carbon 
                    ? $detail->rejected_at->format('Y-m-d')
                    : date('Y-m-d', strtotime($detail->rejected_at));
            } elseif ($detail->block_start_at) {
                $date = $detail->block_start_at instanceof Carbon 
                    ? $detail->block_start_at->format('Y-m-d')
                    : date('Y-m-d', strtotime($detail->block_start_at));
            }
            
            return [
                'id' => $detail->rejection_id,
                'date' => $date,
                'driver_name' => $detail->driver_name ?? 'N/A',
                'type' => 'block',
                'acceptancetype' => 'block',
                'block_id' => $detail->block_id,
                'block_start_at' => $this->formatDate($detail->block_start_at),
                'block_end_at' => $this->formatDate($detail->block_end_at),
                'accepted_at' => $this->formatDate($detail->block_start_at),
                'load_id' => null,
                'origin_yard_arrival_at' => null,
                'destination_arrival_at' => null,
                'on_time_status' => 'on_time',
                'performance_score' => 100,
                'driver_rating' => 5,
                'tenant' => $detail->rejection->tenant ? [
                    'id' => $detail->rejection->tenant->id,
                    'name' => $detail->rejection->tenant->name
                ] : null,
            ];
        });
        
        // Map loads to acceptance format
        $mappedLoads = $acceptedLoads->map(function ($detail) {
            // Format date properly - handle null case
            $date = null;
            if ($detail->origin_yard_arrival_at) {
                $date = $detail->origin_yard_arrival_at instanceof Carbon 
                    ? $detail->origin_yard_arrival_at->format('Y-m-d')
                    : date('Y-m-d', strtotime($detail->origin_yard_arrival_at));
            } else {
                // If no date, use created_at as fallback
                $date = $detail->created_at instanceof Carbon 
                    ? $detail->created_at->format('Y-m-d')
                    : now()->format('Y-m-d');
            }
            
            $onTimeStatus = $detail->origin_yard_arrival_at ? 'on_time' : 'unknown';
            $performanceScore = $detail->origin_yard_arrival_at ? 100 : 0;
            
            return [
                'id' => $detail->rejection_id,
                'date' => $date,
                'driver_name' => $detail->driver_name ?? 'N/A',
                'type' => 'load',
                'acceptancetype' => 'load',
                'block_id' => null,
                'block_start_at' => null,
                'block_end_at' => null,
                'accepted_at' => $date,
                'load_id' => $detail->load_id,
                'origin_yard_arrival_at' => $date,
                'destination_arrival_at' => null,
                'on_time_status' => $onTimeStatus,
                'performance_score' => $performanceScore,
                'driver_rating' => 5,
                'tenant' => $detail->rejection->tenant ? [
                    'id' => $detail->rejection->tenant->id,
                    'name' => $detail->rejection->tenant->name
                ] : null,
            ];
        });
        
        // Merge and sort by date
        $allAcceptances = $mappedBlocks->concat($mappedLoads)
            ->sortByDesc('date')
            ->values();
        
        // Apply search filter
        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $allAcceptances = $allAcceptances->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['driver_name']), $search);
            });
        }
        
        // Paginate
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $allAcceptances->slice(($currentPage - 1) * $perPage, $perPage)->values();
        
        $acceptances = new LengthAwarePaginator(
            $currentItems,
            $allAcceptances->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        
        return $acceptances;
    }

    /**
     * Get acceptance metrics for the dashboard
     */
    public function getAcceptanceMetrics(Request $request): array
    {
        $user = Auth::user();
        $isSuperAdmin = is_null($user->tenant_id);
        
        // Count accepted blocks
        $acceptedBlocksQuery = BlockRejectionDetail::query()
            ->whereNull('rejection_reason')
            ->orWhere('rejection_reason', '');
        
        // Count accepted loads
        $acceptedLoadsQuery = LoadRejectionDetail::query()
            ->whereNull('rejection_reason')
            ->orWhere('rejection_reason', '');
        
        // Apply tenant filter
        if (!$isSuperAdmin && $user->tenant_id) {
            $acceptedBlocksQuery->whereHas('rejection', function($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
            $acceptedLoadsQuery->whereHas('rejection', function($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
        }
        
        $blockCount = $acceptedBlocksQuery->count();
        $loadCount = $acceptedLoadsQuery->count();
        $totalAcceptances = $blockCount + $loadCount;
        
        return [
            'total_acceptances' => $totalAcceptances,
            'on_time_percentage' => 95.5,
            'average_performance' => 97.8,
            'total_drivers' => 45,
            'acceptance_rate' => 94.2,
            'block_acceptances' => $blockCount,
            'load_acceptances' => $loadCount,
        ];
    }

    /**
     * Get top performing drivers for acceptance view
     */
    public function getTopDrivers(Request $request): array
    {
        $user = Auth::user();
        $isSuperAdmin = is_null($user->tenant_id);
        
        // Get all accepted blocks and loads with driver names
        $acceptedBlocks = BlockRejectionDetail::query()
            ->whereNull('rejection_reason')
            ->orWhere('rejection_reason', '')
            ->whereNotNull('driver_name')
            ->when(!$isSuperAdmin && $user->tenant_id, function($q) use ($user) {
                $q->whereHas('rejection', fn($sq) => $sq->where('tenant_id', $user->tenant_id));
            })
            ->get(['driver_name', 'rejection_id']);
        
        $acceptedLoads = LoadRejectionDetail::query()
            ->whereNull('rejection_reason')
            ->orWhere('rejection_reason', '')
            ->whereNotNull('driver_name')
            ->when(!$isSuperAdmin && $user->tenant_id, function($q) use ($user) {
                $q->whereHas('rejection', fn($sq) => $sq->where('tenant_id', $user->tenant_id));
            })
            ->get(['driver_name', 'rejection_id']);
        
        // Combine and count by driver
        $driverStats = [];
        
        foreach ($acceptedBlocks as $block) {
            $name = $block->driver_name;
            if (!isset($driverStats[$name])) {
                $driverStats[$name] = ['name' => $name, 'total_acceptances' => 0];
            }
            $driverStats[$name]['total_acceptances']++;
        }
        
        foreach ($acceptedLoads as $load) {
            $name = $load->driver_name;
            if (!isset($driverStats[$name])) {
                $driverStats[$name] = ['name' => $name, 'total_acceptances' => 0];
            }
            $driverStats[$name]['total_acceptances']++;
        }
        
        // Sort by total acceptances and take top 5
        $topDrivers = collect($driverStats)
            ->sortByDesc('total_acceptances')
            ->take(5)
            ->values()
            ->map(function ($driver) {
                $driver['acceptance_rate'] = 100;
                return $driver;
            })
            ->toArray();
        
        return $topDrivers;
    }

    /**
     * Get acceptance chart data
     */
    public function getAcceptanceChartData(Request $request): array
    {
        // Get last 30 days of acceptance data
        $endDate = now();
        $startDate = now()->subDays(30);
        
        $dates = [];
        $data = [];
        
        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            $dates[] = $date->format('Y-m-d');
            
            // Count acceptances for this date
            $dayStart = $date->copy()->startOfDay()->format('Y-m-d H:i:s');
            $dayEnd = $date->copy()->endOfDay()->format('Y-m-d H:i:s');
            
            $blockCount = BlockRejectionDetail::query()
                ->whereNull('rejection_reason')
                ->orWhere('rejection_reason', '')
                ->whereBetween('rejected_at', [$dayStart, $dayEnd])
                ->count();
                
            $loadCount = LoadRejectionDetail::query()
                ->whereNull('rejection_reason')
                ->orWhere('rejection_reason', '')
                ->whereBetween('origin_yard_arrival_at', [$dayStart, $dayEnd])
                ->count();
                
            $data[] = $blockCount + $loadCount;
        }
        
        return [
            'labels' => $dates,
            'datasets' => [
                [
                    'label' => 'Acceptances',
                    'data' => $data,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                ]
            ]
        ];
    }

    // ==================== CRUD METHODS ====================

    /**
     * Create a new rejection with detail records.
     */
    public function createRejection(array $data): Rejection
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'] ?? null;
            
            if (!$type || !in_array($type, ['advanced', 'block', 'load'])) {
                throw new \Exception('Invalid rejection type');
            }

            // Determine carrier_controllable if not set
            if (!isset($data['carrier_controllable'])) {
                $reason = $this->getReasonFromData($data, $type);
                $data['carrier_controllable'] = $this->isCarrierControllable($reason);
            }

            // Set driver_controllable = carrier_controllable on first create
            if (!isset($data['driver_controllable'])) {
                $data['driver_controllable'] = $data['carrier_controllable'];
            }

            // Calculate penalty if not set
            if (!isset($data['penalty'])) {
                $data['penalty'] = $this->calculatePenalty($type, $data);
            }

            // Set default dispute status
            if (!isset($data['dispute_status'])) {
                $data['dispute_status'] = 'none';
            }

            // Create base rejection
            $rejection = Rejection::create([
                'tenant_id' => $data['tenant_id'],
                'type' => $type,
                'penalty' => $data['penalty'],
                'carrier_controllable' => $data['carrier_controllable'],
                'driver_controllable' => $data['driver_controllable'],
                'dispute_status' => $data['dispute_status'],
            ]);

            // Create type-specific detail
            $this->createDetail($rejection, $data, $type);

            return $rejection;
        });
    }

    /**
     * Update an existing rejection.
     */
    public function updateRejection(int $id, array $data): Rejection
    {
        return DB::transaction(function () use ($id, $data) {
            $rejection = Rejection::findOrFail($id);
            
            // Update base rejection fields
            $updateData = [];
            
            if (isset($data['penalty'])) {
                $updateData['penalty'] = $data['penalty'];
            } else {
                // Recalculate penalty if not provided
                $type = $rejection->type;
                $penaltyData = $data;
                
                // If it's an advanced rejection, get impacted_blocks from detail if not in data
                if ($type === 'advanced' && !isset($penaltyData['impacted_blocks']) && $rejection->advancedDetail) {
                    $penaltyData['impacted_blocks'] = $rejection->advancedDetail->impacted_blocks ?? 1;
                }
                
                $updateData['penalty'] = $this->calculatePenalty($type, $penaltyData);
            }
            
            if (isset($data['carrier_controllable'])) {
                $updateData['carrier_controllable'] = $data['carrier_controllable'];
            }
            
            if (isset($data['driver_controllable'])) {
                $updateData['driver_controllable'] = $data['driver_controllable'];
            }
            
            if (isset($data['dispute_status'])) {
                $updateData['dispute_status'] = $data['dispute_status'];
            }

            if (!empty($updateData)) {
                $rejection->update($updateData);
            }

            // Update detail if provided
            if ($rejection->type) {
                $this->updateDetail($rejection, $data, $rejection->type);
            }

            return $rejection->fresh();
        });
    }

    /**
     * Delete a rejection.
     */
    public function deleteRejection(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $rejection = Rejection::findOrFail($id);
            $rejection->delete();
            
            return true;
        });
    }

    // ==================== CARRIER CONTROLLABLE METHODS ====================

    /**
     * Check if rejection reason makes it carrier controllable
     */
    public function isCarrierControllable(?string $reason): bool
    {
        if (empty($reason)) {
            return true;
        }

        $reasonUpper = strtoupper(trim($reason));

        foreach (self::NOT_CARRIER_CONTROLLABLE_KEYWORDS as $keyword) {
            if (str_contains($reasonUpper, $keyword)) {
                return false;
            }
        }

        return true;
    }

    public function getKeywords(): array
    {
        return self::NOT_CARRIER_CONTROLLABLE_KEYWORDS;
    }

    // ==================== BUCKET CALCULATION METHODS ====================

    /**
     * Calculate bucket for Block Rejection.
     */
    public function calculateBlockBucket($blockStartAt, $rejectedAt, bool $isAccepted = false): string
    {
        if ($isAccepted) {
            return 'N/A';
        }

        $blockStart = $blockStartAt instanceof Carbon ? $blockStartAt : Carbon::parse($blockStartAt);
        $rejected = $rejectedAt instanceof Carbon ? $rejectedAt : Carbon::parse($rejectedAt);
        
        $hoursDiff = $rejected->diffInHours($blockStart, false);
        
        if ($hoursDiff < 24) {
            return 'Less than 24 hours before start';
        }
        
        return '24+ hours before start';
    }

    /**
     * Get bucket for Load Rejection.
     */
    public function getLoadBucket(?string $storedBucket, bool $isAccepted = false): string
    {
        if ($isAccepted) {
            return 'N/A';
        }

        return $storedBucket ?? 'Unknown';
    }

    /**
     * Advanced rejections don't have buckets.
     */
    public function getAdvancedBucket(): string
    {
        return 'N/A';
    }

    /**
     * Map old category to bucket name.
     */
    public function mapOldCategoryToBucket(string $category): string
    {
        return match($category) {
            'after_start' => 'Rejected after start time',
            'within_6' => 'Rejected 0-6 hours before start',
            'more_than_6' => 'Rejected 6+ hours before start',
            'within_24' => 'Less than 24 hours before start',
            'more_than_24' => '24+ hours before start',
            'advanced_rejection' => 'N/A',
            default => 'Unknown',
        };
    }

    // ==================== PENALTY CALCULATION METHODS ====================

    /**
     * Calculate penalty for Advanced Rejection.
     */
    public function calculateAdvancedPenalty(int $impactedBlocks = 1): float
    {
        return 0.85 * $impactedBlocks;
    }

    /**
     * Calculate penalty for Block Rejection.
     */
    public function calculateBlockPenalty($blockStartAt, $rejectedAt, bool $isAccepted = false): int
    {
        if ($isAccepted) {
            return 0;
        }

        $blockStart = $blockStartAt instanceof Carbon ? $blockStartAt : Carbon::parse($blockStartAt);
        $rejected = $rejectedAt instanceof Carbon ? $rejectedAt : Carbon::parse($rejectedAt);
        
        $hoursDiff = $rejected->diffInHours($blockStart, false);
        
        if ($hoursDiff < 24) {
            return 4;
        }
        
        return 1;
    }

    /**
     * Calculate penalty for Load Rejection.
     */
    public function calculateLoadPenalty(?string $rejectionBucket, bool $isAccepted = false): int
    {
        if ($isAccepted) {
            return 0;
        }

        return match($rejectionBucket) {
            'Rejected after start time' => 8,
            'Rejected 0-6 hours before start' => 4,
            'Rejected 6+ hours before start' => 1,
            default => 1,
        };
    }

    /**
     * Unified method to calculate penalty for any rejection type.
     */
    public function calculatePenalty(string $type, array $data): float|int
    {
        return match($type) {
            'advanced' => $this->calculateAdvancedPenalty($data['impacted_blocks'] ?? 1),
            'block' => $this->calculateBlockPenalty(
                $data['block_start_at'] ?? null,
                $data['rejected_at'] ?? null,
                empty($data['rejection_reason'])
            ),
            'load' => $this->calculateLoadPenalty(
                $data['rejection_bucket'] ?? null,
                empty($data['rejection_reason'])
            ),
            default => 0,
        };
    }

    /**
     * Get penalty from rejection model.
     */
    public function getPenaltyFromRejection(Rejection $rejection): float|int
    {
        switch ($rejection->type) {
            case 'advanced':
                if ($rejection->advancedDetail) {
                    return $this->calculateAdvancedPenalty($rejection->advancedDetail->impacted_blocks ?? 1);
                }
                return $this->calculateAdvancedPenalty(1);
                
            case 'block':
                if ($rejection->blockDetail) {
                    return $this->calculateBlockPenalty(
                        $rejection->blockDetail->block_start_at,
                        $rejection->blockDetail->rejected_at,
                        empty($rejection->blockDetail->rejection_reason)
                    );
                }
                break;
                
            case 'load':
                if ($rejection->loadDetail) {
                    return $this->calculateLoadPenalty(
                        $rejection->loadDetail->rejection_bucket,
                        empty($rejection->loadDetail->rejection_reason)
                    );
                }
                break;
        }
        
        return 0;
    }

    // ==================== SCORE CALCULATION METHODS ====================

    /**
     * Check if rejection affects company score.
     */
    public function affectsCompanyScore(string $disputeStatus): bool
    {
        return $disputeStatus !== 'won';
    }

    /**
     * Check if rejection affects driver score.
     */
    public function affectsDriverScore(string $disputeStatus, bool $carrierControllable): bool
    {
        if ($disputeStatus === 'won' && !$carrierControllable) {
            return false;
        }
        return true;
    }

    /**
     * Calculate acceptance score for a company.
     */
    public function calculateCompanyScore(array $rejections, int $tenderedWork): float
    {
        $weightedSum = 0;
        
        foreach ($rejections as $rejection) {
            if (!$this->affectsCompanyScore($rejection->dispute_status)) {
                continue;
            }
            
            $penalty = $this->getPenaltyFromRejection($rejection);
            $weightedSum += $penalty;
        }
        
        if ($tenderedWork === 0) {
            return 100;
        }
        
        $score = (1 - ($weightedSum / $tenderedWork)) * 100;
        return ceil($score * 10) / 10;
    }

    /**
     * Calculate acceptance score for a driver.
     */
    public function calculateDriverScore(array $rejections, int $tenderedWork): float
    {
        $weightedSum = 0;
        
        foreach ($rejections as $rejection) {
            if (!$this->affectsDriverScore($rejection->dispute_status, $rejection->carrier_controllable ?? false)) {
                continue;
            }
            
            $penalty = $this->getPenaltyFromRejection($rejection);
            $weightedSum += $penalty;
        }
        
        if ($tenderedWork === 0) {
            return 100;
        }
        
        $score = (1 - ($weightedSum / $tenderedWork)) * 100;
        return ceil($score * 10) / 10;
    }

    /**
     * Calculate total penalty weight.
     */
    public function calculateTotalPenaltyWeight(array $rejections, bool $forCompany = true, ?bool $carrierControllable = null): float
    {
        $total = 0;
        
        foreach ($rejections as $rejection) {
            if ($forCompany) {
                if (!$this->affectsCompanyScore($rejection->dispute_status)) {
                    continue;
                }
            } else {
                if (!$this->affectsDriverScore(
                    $rejection->dispute_status, 
                    $carrierControllable ?? ($rejection->carrier_controllable ?? false)
                )) {
                    continue;
                }
            }
            
            $total += $this->getPenaltyFromRejection($rejection);
        }
        
        return $total;
    }

    // ==================== DETAIL CRUD METHODS ====================

    /**
     * Create detail record based on type.
     */
    protected function createDetail(Rejection $rejection, array $data, string $type): void
    {
        match($type) {
            'advanced' => AdvancedRejectionDetail::create([
                'rejection_id' => $rejection->id,
                'advanced_block_id' => $data['advanced_block_id'],
                'driver_name' => $data['driver_name'] ?? null,
                'week_start_at' => $data['week_start_at'] ?? null,
                'week_end_at' => $data['week_end_at'] ?? null,
                'impacted_blocks' => $data['impacted_blocks'] ?? 0,
                'reason' => $data['reason'] ?? '',
            ]),
            
            'block' => BlockRejectionDetail::create([
                'rejection_id' => $rejection->id,
                'block_id' => $data['block_id'],
                'driver_name' => $data['driver_name'] ?? null,
                'block_start_at' => $data['block_start_at'],
                'block_end_at' => $data['block_end_at'],
                'rejected_at' => $data['rejected_at'],
                'rejection_reason' => $data['rejection_reason'] ?? '',
                'bucket' => $data['bucket'] ?? '',
            ]),
            
            'load' => LoadRejectionDetail::create([
                'rejection_id' => $rejection->id,
                'load_id' => $data['load_id'],
                'driver_name' => $data['driver_name'] ?? null,
                'origin_yard_arrival_at' => $data['origin_yard_arrival_at'] ?? null,
                'rejection_reason' => $data['rejection_reason'] ?? '',
                'rejection_bucket' => $data['rejection_bucket'] ?? '',
            ]),
            
            default => throw new \Exception("Invalid type: {$type}"),
        };
    }

    /**
     * Update detail record based on type.
     */
    protected function updateDetail(Rejection $rejection, array $data, string $type): void
    {
        $detail = $rejection->detail;
        
        if (!$detail) {
            return;
        }

        $updateData = [];

        match($type) {
            'advanced' => $updateData = array_filter([
                'driver_name' => $data['driver_name'] ?? null,
                'week_start_at' => $data['week_start_at'] ?? null,
                'week_end_at' => $data['week_end_at'] ?? null,
                'impacted_blocks' => $data['impacted_blocks'] ?? null,
                'reason' => $data['reason'] ?? null,
            ], fn($v) => $v !== null),
            
            'block' => $updateData = array_filter([
                'driver_name' => $data['driver_name'] ?? null,
                'block_start_at' => $data['block_start_at'] ?? $data['block_start_datetime'] ?? null,
                'block_end_at' => $data['block_end_at'] ?? $data['block_end_datetime'] ?? null,
                'rejected_at' => $data['rejected_at'] ?? $data['rejection_datetime'] ?? null,
                'rejection_reason' => $data['rejection_reason'] ?? null,
                'bucket' => $data['bucket'] ?? null,
            ], fn($v) => $v !== null),
            
            'load' => $updateData = array_filter([
                'driver_name' => $data['driver_name'] ?? null,
                'origin_yard_arrival_at' => $data['origin_yard_arrival_at'] ?? $data['origin_yard_arrival_datetime'] ?? null,
                'rejection_reason' => $data['rejection_reason'] ?? null,
                'rejection_bucket' => $data['rejection_bucket'] ?? null,
            ], fn($v) => $v !== null),
            
            default => null,
        };

        if (!empty($updateData)) {
            $detail->update($updateData);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Calculate date range from filter
     */
    private function calculateDateRange($dateFilter)
    {
        $now = now();
        $range = ['start' => null, 'end' => null];
        
        switch ($dateFilter) {
            case 'yesterday':
                $range['start'] = $now->copy()->subDay()->startOfDay()->format('Y-m-d H:i:s');
                $range['end'] = $now->copy()->subDay()->endOfDay()->format('Y-m-d H:i:s');
                break;
                
            case 'current-week':
                $range['start'] = $now->copy()->startOfWeek()->format('Y-m-d H:i:s');
                $range['end'] = $now->copy()->endOfWeek()->format('Y-m-d H:i:s');
                break;
                
            case '6w':
                $range['start'] = $now->copy()->subWeeks(6)->startOfWeek()->format('Y-m-d H:i:s');
                $range['end'] = $now->copy()->endOfWeek()->format('Y-m-d H:i:s');
                break;
                
            case 'quarterly':
                $range['start'] = $now->copy()->subMonths(3)->startOfDay()->format('Y-m-d H:i:s');
                $range['end'] = $now->copy()->endOfDay()->format('Y-m-d H:i:s');
                break;
                
            default:
                // For 'all' or any other value - show ALL data
                $range['start'] = '2025-01-01 00:00:00';
                $range['end'] = '2026-12-31 23:59:59';
                break;
        }
        
        return $range;
    }

    /**
     * Format date helper
     */
    protected function formatDate($date): ?string
    {
        if (!$date) {
            return null;
        }
        
        return $date instanceof Carbon 
            ? $date->format('Y-m-d') 
            : date('Y-m-d', strtotime($date));
    }

    /**
     * Get rejection reason from data based on type.
     */
    protected function getReasonFromData(array $data, string $type): ?string
    {
        return match($type) {
            'advanced' => $data['reason'] ?? null,
            'block', 'load' => $data['rejection_reason'] ?? null,
            default => null,
        };
    }
}