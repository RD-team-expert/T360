<?php

namespace App\Services\Acceptance;

use App\Models\Rejection;
use App\Models\RejectionReasonCode;
use Illuminate\Support\Facades\Auth;
use App\Models\Tenant;
use App\Services\Filtering\FilteringService;
use Carbon\Carbon;
use App\Services\Summaries\RejectionBreakdownService;
use Illuminate\Support\Facades\DB;

/**
 * Class RejectionService
 *
 * Contains business logic for rejection management and reason code operations.
 * Now supports BOTH old and new structure rejections using the consolidated service.
 */
class RejectionService
{
    protected FilteringService $filteringService;
    protected RejectionBreakdownService $rejectionBreakdownService;
    protected AcceptanceDataService $acceptanceDataService;

    /**
     * Constructor.
     *
     * @param FilteringService $filteringService
     * @param RejectionBreakdownService $rejectionBreakdownService
     * @param AcceptanceDataService $acceptanceDataService
     */
    public function __construct(
        FilteringService $filteringService,
        RejectionBreakdownService $rejectionBreakdownService,
        AcceptanceDataService $acceptanceDataService
    ) {
        $this->filteringService = $filteringService;
        $this->rejectionBreakdownService = $rejectionBreakdownService;
        $this->acceptanceDataService = $acceptanceDataService;
    }

    /**
     * Get rejection data for the index view.
     * Now includes both old and new structure rejections.
     *
     * @return array
     */
    public function getRejectionsIndex(): array
    {
        $user = Auth::user();
        $isSuperAdmin = is_null($user->tenant_id);
        
        // Get filtering parameters
        $dateFilter = $this->filteringService->getDateFilter();
        $perPage = $this->filteringService->getPerPage();
        
        // Build query - now includes new structure relationships
        $query = Rejection::with([
            'tenant',
            'reasonCode' => function ($query) {
                $query->withTrashed();
            },
            'blockDetail',
            'loadDetail',
            'advancedDetail'
        ]);
        
        // Apply date filtering
        $dateRange = [];
        
        if (!empty($dateFilter) && $dateFilter !== 'all') {
            $dummyQuery = Rejection::query();
            $this->filteringService->applyDateFilter($dummyQuery, $dateFilter, 'created_at', $dateRange);
            
            if (!empty($dateRange['start']) && !empty($dateRange['end'])) {
                $startDate = $dateRange['start'];
                $endDate = $dateRange['end'];
                
                $query->where(function($q) use ($startDate, $endDate) {
                    $q->whereBetween('created_at', [$startDate, $endDate])
                      ->orWhereHas('blockDetail', fn($sub) => 
                          $sub->whereRaw('DATE(rejected_at) BETWEEN ? AND ?', [$startDate, $endDate])
                      )
                      ->orWhereHas('loadDetail', fn($sub) => 
                          $sub->whereRaw('DATE(origin_yard_arrival_at) BETWEEN ? AND ?', [$startDate, $endDate])
                      )
                      ->orWhereHas('advancedDetail', fn($sub) => 
                          $sub->whereRaw('DATE(week_start_at) BETWEEN ? AND ?', [$startDate, $endDate])
                      );
                });
            }
        }
        
        $request = request();
        
        // Search filter
        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(driver_name) LIKE ?', ["%{$search}%"])
                  ->orWhereHas('blockDetail', function($subQ) use ($search) {
                      $subQ->whereRaw('LOWER(driver_name) LIKE ?', ["%{$search}%"]);
                  })
                  ->orWhereHas('loadDetail', function($subQ) use ($search) {
                      $subQ->whereRaw('LOWER(driver_name) LIKE ?', ["%{$search}%"]);
                  });
            });
        }

        // View type filter - BLOCKS with specific reasons, ALL LOADS and ADVANCED
        if ($request->has('viewType') && $request->input('viewType') === 'rejections') {
            $specificReasons = [
                'DRIVER_ATTRITION',
                'DRIVER_PERSONAL_CONFLICT',
                'HOURS_OF_SERVICE_CARRIER',
                'MEDICAL',
                'RATES'
            ];
            
            $query->where(function($q) use ($specificReasons) {
                // BLOCK REJECTIONS - Only with specific reasons
                $q->whereHas('blockDetail', function($sq) use ($specificReasons) {
                    $sq->whereIn('rejection_reason', $specificReasons);
                })
                // LOAD REJECTIONS - ALL rejected loads (any reason)
                ->orWhereHas('loadDetail', function($sq) {
                    $sq->whereNotNull('rejection_reason')
                       ->where('rejection_reason', '!=', '');
                })
                // ADVANCED REJECTIONS - ALL advanced rejections (any reason)
                ->orWhereHas('advancedDetail');
            });
        }

        // Rejection Type filter
        if ($request->filled('rejectionType')) {
            $rejType = $request->input('rejectionType');
            
            $query->where(function($q) use ($rejType) {
                $q->where('rejection_type', $rejType)
                  ->orWhere('type', $rejType);
            });
        }

        // Reason Code filter - Handle specific reasons for blocks, but allow all for others
        if ($request->filled('reasonCode')) {
            $reasonCode = $request->input('reasonCode');
            
            $specificReasons = [
                'DRIVER_ATTRITION',
                'DRIVER_PERSONAL_CONFLICT',
                'HOURS_OF_SERVICE_CARRIER',
                'MEDICAL',
                'RATES'
            ];
            
            $query->where(function($q) use ($reasonCode, $specificReasons) {
                // Old structure
                $q->where('reason_code_id', $reasonCode);
                
                // Block rejections - use exact match for specific reasons, LIKE for others
                $q->orWhereHas('blockDetail', function($sq) use ($reasonCode, $specificReasons) {
                    if (in_array($reasonCode, $specificReasons)) {
                        $sq->where('rejection_reason', $reasonCode);
                    } else {
                        $sq->where('rejection_reason', 'LIKE', "%{$reasonCode}%");
                    }
                });
                
                // Load rejections - always use LIKE (any reason)
                $q->orWhereHas('loadDetail', function($sq) use ($reasonCode) {
                    $sq->where('rejection_reason', 'LIKE', "%{$reasonCode}%");
                });
                
                // Advanced rejections - always use LIKE (any reason)
                $q->orWhereHas('advancedDetail', function($sq) use ($reasonCode) {
                    $sq->where('reason', 'LIKE', "%{$reasonCode}%");
                });
            });
        }

        // Rejection Category filter
        if ($request->filled('rejectionCategory')) {
            $category = $request->input('rejectionCategory');
            
            $query->where(function($q) use ($category) {
                $q->where('rejection_category', $category);
                
                switch ($category) {
                    case 'after_start':
                        $q->orWhereHas('loadDetail', function($sq) {
                            $sq->where('rejection_bucket', 'LIKE', '%after start%');
                        });
                        $q->orWhereHas('blockDetail', function($sq) {
                            $sq->whereRaw('TIMESTAMPDIFF(HOUR, rejected_at, block_start_at) < 0');
                        });
                        break;
                        
                    case 'within_24':
                        $q->orWhereHas('blockDetail', function($sq) {
                            $sq->whereRaw('TIMESTAMPDIFF(HOUR, rejected_at, block_start_at) BETWEEN 0 AND 23');
                        });
                        break;
                        
                    case 'more_than_24':
                        $q->orWhereHas('blockDetail', function($sq) {
                            $sq->whereRaw('TIMESTAMPDIFF(HOUR, rejected_at, block_start_at) >= 24');
                        });
                        break;
                        
                    case 'within_6':
                        $q->orWhereHas('loadDetail', function($sq) {
                            $sq->where('rejection_bucket', 'LIKE', '%0-6%');
                        });
                        break;
                        
                    case 'more_than_6':
                        $q->orWhereHas('loadDetail', function($sq) {
                            $sq->where('rejection_bucket', 'LIKE', '%6+%')
                              ->orWhere('rejection_bucket', 'LIKE', '%more than 6%');
                        });
                        break;
                        
                    case 'advanced_rejection':
                        $q->orWhere('type', 'advanced');
                        break;
                }
            });
        }

        // Disputed filter
        if ($request->filled('disputed')) {
            $disputed = $request->boolean('disputed');
            
            $query->where(function($q) use ($disputed) {
                $q->where('disputed', $disputed);
                
                if ($disputed) {
                    $q->orWhereIn('dispute_status', ['pending', 'won', 'lost']);
                } else {
                    $q->orWhere('dispute_status', 'none');
                }
            });
        }

        // Controllable filter
        if ($request->filled('controllable') && is_array($request->input('controllable'))) {
            $controllableValues = $request->input('controllable');
            
            $query->where(function($q) use ($controllableValues) {
                $q->where(function($sub) use ($controllableValues) {
                    foreach ($controllableValues as $value) {
                        switch ($value) {
                            case 'carrier':
                                $sub->orWhere('carrier_controllable', true);
                                break;
                            case 'driver':
                                $sub->orWhere('driver_controllable', true);
                                break;
                            case 'none':
                                $sub->orWhere(function($noneQ) {
                                    $noneQ->where(function($n) {
                                        $n->where('carrier_controllable', false)
                                          ->orWhereNull('carrier_controllable');
                                    })->where(function($n) {
                                        $n->where('driver_controllable', false)
                                          ->orWhereNull('driver_controllable');
                                    });
                                });
                                break;
                            case 'both':
                                $sub->orWhere(function($bothQ) {
                                    $bothQ->where('carrier_controllable', true)
                                           ->where('driver_controllable', true);
                                });
                                break;
                        }
                    }
                });
            });
        }
        
        // Paginate results
        $rejections = $query->paginate($perPage);
        
        // Transform rejections using the consolidated service
        $rejections->getCollection()->transform(function ($rejection) {
            if ($rejection->type) {
                $detail = null;
                
                switch ($rejection->type) {
                    case 'block':
                        $detail = $rejection->blockDetail;
                        if ($detail) {
                            $rejection->date = $detail->rejected_at?->format('Y-m-d');
                            $rejection->driver_name = $detail->driver_name ?? 'N/A';
                            $rejection->rejection_type = 'block';
                            
                            // Use consolidated service for bucket calculation
                            $bucket = $this->acceptanceDataService->calculateBlockBucket(
                                $detail->block_start_at,
                                $detail->rejected_at,
                                empty($detail->rejection_reason)
                            );
                            
                            // Map bucket to category
                            if (str_contains($bucket, 'After start') || str_contains($bucket, 'after start')) {
                                $rejection->rejection_category = 'after_start';
                            } elseif (str_contains($bucket, 'Less than 24')) {
                                $rejection->rejection_category = 'within_24';
                            } elseif (str_contains($bucket, '24+ hours')) {
                                $rejection->rejection_category = 'more_than_24';
                            } else {
                                $rejection->rejection_category = null;
                            }
                            
                            $rejection->reason_code = (object)[
                                'reason_code' => $detail->rejection_reason ?? 'N/A',
                                'deleted_at' => null
                            ];
                            
                            $rejection->disputed = in_array($rejection->dispute_status, ['pending', 'won', 'lost']);
                            $rejection->driver_controllable = $rejection->carrier_controllable;
                            
                            // Use consolidated service for carrier controllable check
                            $rejection->carrier_controllable = $this->acceptanceDataService->isCarrierControllable($detail->rejection_reason);
                        }
                        break;
                        
                    case 'load':
                        $detail = $rejection->loadDetail;
                        if ($detail) {
                            $rejection->date = $detail->origin_yard_arrival_at?->format('Y-m-d');
                            $rejection->driver_name = $detail->driver_name ?? 'N/A';
                            $rejection->rejection_type = 'load';
                            
                            // Use consolidated service for load bucket
                            $bucket = $this->acceptanceDataService->getLoadBucket(
                                $detail->rejection_bucket,
                                empty($detail->rejection_reason)
                            );
                            
                            if (str_contains($bucket, 'after start')) {
                                $rejection->rejection_category = 'after_start';
                            } elseif (str_contains($bucket, '0-6 hours')) {
                                $rejection->rejection_category = 'within_6';
                            } elseif (str_contains($bucket, '6+ hours')) {
                                $rejection->rejection_category = 'more_than_6';
                            } else {
                                $rejection->rejection_category = null;
                            }
                            
                            $rejection->reason_code = (object)[
                                'reason_code' => $detail->rejection_reason ?? 'N/A',
                                'deleted_at' => null
                            ];
                            
                            $rejection->disputed = in_array($rejection->dispute_status, ['pending', 'won', 'lost']);
                            $rejection->driver_controllable = $rejection->carrier_controllable;
                            
                            // Use consolidated service for carrier controllable check
                            $rejection->carrier_controllable = $this->acceptanceDataService->isCarrierControllable($detail->rejection_reason);
                        }
                        break;
                        
                    case 'advanced':
                        $detail = $rejection->advancedDetail;
                        if ($detail) {
                            $rejection->date = $detail->week_start_at?->format('Y-m-d');
                            $rejection->driver_name = $detail->driver_name ?? 'N/A';
                            $rejection->rejection_type = 'advanced';
                            $rejection->rejection_category = 'advanced_rejection';
                            
                            // Use consolidated service for penalty calculation
                            $rejection->penalty = $this->acceptanceDataService->calculatePenalty('advanced', [
                                'impacted_blocks' => $detail->impacted_blocks ?? 1,
                            ]);
                            
                            $rejection->reason_code = (object)[
                                'reason_code' => $detail->reason ?? 'N/A',
                                'deleted_at' => null
                            ];
                            
                            $rejection->disputed = in_array($rejection->dispute_status, ['pending', 'won', 'lost']);
                            $rejection->driver_controllable = null;
                            
                            // Use consolidated service for carrier controllable check
                            $rejection->carrier_controllable = $this->acceptanceDataService->isCarrierControllable($detail->reason);
                        }
                        break;
                }
            }
            
            return $rejection;
        });
        
        // Week number calculations
        $weekNumber = null;
        $startWeekNumber = null;
        $endWeekNumber = null;
        $year = null;
        
        if (!empty($dateRange) && isset($dateRange['start'])) {
            $startDate = Carbon::parse($dateRange['start']);
            $year = $startDate->year;
            
            if (in_array($dateFilter, ['current-week'])) {
                $weekNumber = $this->weekNumberSundayStart($startDate);
                $startWeekNumber = $endWeekNumber = null;
            } else if (in_array($dateFilter, ['6w','quarterly'])) {
                $weekNumber = null;
                $startWeekNumber = $this->weekNumberSundayStart($startDate);
                $endWeekNumber = isset($dateRange['end']) ? 
                    $this->weekNumberSundayStart(Carbon::parse($dateRange['end'])) : 
                    $startWeekNumber;
            }
        }
        
        $rejectionBreakdown = $this->rejectionBreakdownService->getRejectionBreakdownDetailsPage(
            $dateRange['start'] ?? null, 
            $dateRange['end'] ?? null
        );
        
        $lineChartData = $this->rejectionBreakdownService->getLineChartData(
            $dateRange['start'] ?? null, 
            $dateRange['end'] ?? null
        );
        
        $filters = [
            'search' => (string) $request->input('search', ''),
            'rejectionType' => (string) $request->input('rejectionType', ''),
            'reasonCode' => (string) $request->input('reasonCode', ''),
            'rejectionCategory' => (string) $request->input('rejectionCategory', ''),
            'disputed' => (string) $request->input('disputed', ''),
            'driverControllable' => (string) $request->input('driverControllable', ''),
        ];
        
        $permissions = Auth::user()->getAllPermissions();

        return [
            'rejections'           => $rejections,
            'tenantSlug'           => $isSuperAdmin ? null : $user->tenant->slug,
            'isSuperAdmin'         => $isSuperAdmin,
            'tenants'              => $isSuperAdmin ? Tenant::all() : [],
            'rejection_reason_codes' => RejectionReasonCode::withTrashed()->get(),
            'dateFilter'           => $dateFilter,
            'dateRange'            => $dateRange,
            'perPage'              => $perPage,
            'weekNumber'           => $weekNumber,
            'startWeekNumber'      => $startWeekNumber,
            'endWeekNumber'        => $endWeekNumber,
            'year'                 => $year,
            'rejection_breakdown'  => $rejectionBreakdown,
            'line_chart_data'      => $lineChartData['chartData'] ?? [],
            'average_acceptance'   => $lineChartData['averageAcceptance'] ?? null,
            'filters' => $filters,
            'permissions' => $permissions,
        ];
    }

    /**
     * Get the week‐of‐year for a Carbon date, where weeks run Sunday → Saturday.
     *
     * @param  Carbon  $date
     * @return int
     */
    private function weekNumberSundayStart(Carbon $date): int
    {
        $dayOfYear = $date->dayOfYear;
        $firstDayDow = $date->copy()->startOfYear()->dayOfWeek;
        return (int) ceil(($dayOfYear + $firstDayDow) / 7);
    }

    /**
     * Create a new rejection.
     *
     * @param array $data
     * @return void
     */
    public function createRejection(array $data)
    {
        $user = Auth::user();
        $data['tenant_id'] = is_null($user->tenant_id) ? $data['tenant_id'] : $user->tenant_id;
        
        // Use the consolidated service for penalty calculation
        if (isset($data['type'])) {
            // New structure - use type-based calculation
            $data['penalty'] = $this->acceptanceDataService->calculatePenalty($data['type'], $data);
        } else {
            // Old structure - use category-based calculation
            $data['penalty'] = match ($data['rejection_category'] ?? '') {
                'more_than_6' => 1,
                'within_6'    => 4,
                'after_start' => 8,
                'within_24'   => 4,
                'more_than_24' => 1,
                'advanced_rejection' => 0.8,
                default => 0,
            };
        }
        
        // Set carrier controllable using consolidated service
        if (!isset($data['carrier_controllable']) && isset($data['rejection_reason'])) {
            $data['carrier_controllable'] = $this->acceptanceDataService->isCarrierControllable($data['rejection_reason']);
        }
        
        Rejection::create($data);
    }

    /**
     * Update an existing rejection.
     *
     * @param int $id
     * @param array $data
     * @return void
     */
    public function updateRejection($id, array $data)
    {
        $user = Auth::user();
        $data['tenant_id'] = is_null($user->tenant_id) ? $data['tenant_id'] : $user->tenant_id;
        
        $rejection = Rejection::findOrFail($id);
        
        // Use the consolidated service for penalty calculation
        if (isset($data['type']) || $rejection->type) {
            $type = $data['type'] ?? $rejection->type;
            
            $penaltyData = $data;
            
            if ($type === 'advanced' && $rejection->advancedDetail) {
                $penaltyData['impacted_blocks'] = $rejection->advancedDetail->impacted_blocks ?? 1;
            }
            
            $data['penalty'] = $this->acceptanceDataService->calculatePenalty($type, $penaltyData);
        } else {
            $data['penalty'] = match ($data['rejection_category'] ?? $rejection->rejection_category) {
                'more_than_6' => 1,
                'within_6'    => 4,
                'after_start' => 8,
                'within_24'   => 4,
                'more_than_24' => 1,
                'advanced_rejection' => 0.8,
                default => 0,
            };
        }
        
        // Update carrier controllable if reason changed
        if (isset($data['rejection_reason']) && !isset($data['carrier_controllable'])) {
            $data['carrier_controllable'] = $this->acceptanceDataService->isCarrierControllable($data['rejection_reason']);
        }
        
        $rejection->update($data);
    }

    /**
     * Delete a rejection.
     *
     * @param int $id
     * @return void
     */
    public function deleteRejection($id)
    {
        $rejection = Rejection::findOrFail($id);
        $rejection->delete();
    }

    /**
     * Delete multiple rejection records.
     *
     * @param array $ids Array of rejection IDs to delete
     * @return void
     */
    public function deleteMultipleRejections(array $ids)
    {
        if (empty($ids)) {
            return;
        }
        
        $query = Rejection::whereIn('id', $ids);
        
        $user = Auth::user();
        if (!is_null($user->tenant_id)) {
            $query->where('tenant_id', $user->tenant_id);
        }
        
        $query->delete();
    }
}