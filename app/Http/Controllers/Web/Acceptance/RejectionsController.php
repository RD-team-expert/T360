<?php

namespace App\Http\Controllers\Web\Acceptance;

use App\Http\Controllers\Controller;
use App\Services\Acceptance\RejectionReasonCodesService;
use App\Services\Acceptance\RejectionImportValidationService;
use App\Services\Acceptance\AcceptanceImportExportService;
use App\Services\Acceptance\AcceptanceDataService;
use Illuminate\Http\Request;
use App\Http\Requests\Acceptance\StoreRejectionRequest;
use App\Http\Requests\Acceptance\UpdateRejectionRequest;
use App\Services\Acceptance\RejectionService;
use Inertia\Inertia;
use App\Http\Requests\Acceptance\StoreRejectionReasonCode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Tenant;
use App\Models\RejectionReasonCode;
use App\Models\Rejection;

/**
 * Class RejectionsController
 *
 * This controller manages rejection entries and rejection reason codes.
 * Now supports BOTH old structure and new acceptance structure.
 */
class RejectionsController extends Controller
{
    protected RejectionService $rejectionService;
    protected RejectionReasonCodesService $rejectionReasonCodesService;
    protected RejectionImportValidationService $rejectionImportValidationService;
    protected AcceptanceImportExportService $acceptanceImportExportService;
    protected AcceptanceDataService $acceptanceDataService;

    /**
     * Constructor.
     *
     * @param RejectionService $rejectionService
     * @param RejectionReasonCodesService $rejectionReasonCodesService
     * @param RejectionImportValidationService $rejectionImportValidationService
     * @param AcceptanceImportExportService $acceptanceImportExportService
     * @param AcceptanceDataService $acceptanceDataService
     */
    public function __construct(
        RejectionService $rejectionService,
        RejectionReasonCodesService $rejectionReasonCodesService,
        RejectionImportValidationService $rejectionImportValidationService,
        AcceptanceImportExportService $acceptanceImportExportService,
        AcceptanceDataService $acceptanceDataService
    ) {
        $this->rejectionService = $rejectionService;
        $this->rejectionReasonCodesService = $rejectionReasonCodesService;
        $this->rejectionImportValidationService = $rejectionImportValidationService;
        $this->acceptanceImportExportService = $acceptanceImportExportService;
        $this->acceptanceDataService = $acceptanceDataService;
    }

    /**
     * Display a list of rejections.
     *
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $viewType = $request->get('viewType', 'rejections');
        
        $user = Auth::user();
        $isSuperAdmin = is_null($user->tenant_id);
        
        // Base data that's common to both views
        $data = [
            'tenantId' => $user->tenant_id,
            'tenantSlug' => $isSuperAdmin ? null : ($user->tenant->slug ?? null),
            'isSuperAdmin' => $isSuperAdmin,
            'tenants' => $isSuperAdmin ? Tenant::all() : [],
            'rejection_reason_codes' => RejectionReasonCode::withTrashed()->get(),
            'permissions' => $user->getAllPermissions(),
            'dateFilter' => $request->get('dateFilter', 'yesterday'),
            'perPage' => $request->get('perPage', 10),
            'filters' => [
                'search' => $request->input('search', ''),
                'rejectionType' => $request->input('rejectionType', ''),
                'reasonCode' => $request->input('reasonCode', ''),
                'rejectionCategory' => $request->input('rejectionCategory', ''),
                'disputed' => $request->input('disputed', ''),
                'driverControllable' => $request->input('driverControllable', ''),
                'viewType' => $viewType,
            ],
        ];
        
        if ($viewType === 'acceptance') {
            // Get acceptance data from the new service methods
            $data['acceptances'] = $this->acceptanceDataService->getAcceptancesIndex($request);
            $data['rejections'] = ['data' => [], 'links' => []];
            
            // Get acceptance metrics and top drivers
            $data['acceptanceMetrics'] = $this->acceptanceDataService->getAcceptanceMetrics($request);
            $data['topDrivers'] = $this->acceptanceDataService->getTopDrivers($request);
            $data['acceptanceChartData'] = $this->acceptanceDataService->getAcceptanceChartData($request);
            
            // Set rejection-specific data to empty
            $data['rejection_breakdown'] = null;
            $data['line_chart_data'] = [];
            $data['average_acceptance'] = null;
            $data['weekNumber'] = null;
            $data['startWeekNumber'] = null;
            $data['endWeekNumber'] = null;
            $data['year'] = null;
            
        } else {
            // Get rejection data from existing service
            $rejectionData = $this->rejectionService->getRejectionsIndex();
            $data = array_merge($data, $rejectionData);
            $data['acceptances'] = ['data' => [], 'links' => []];
        }
        
        return Inertia::render('Rejections/Index', $data);
    }

    /**
     * Store a new rejection.
     * Now supports BOTH old and new structure.
     *
     * @param StoreRejectionRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreRejectionRequest $request)
    {
        $data = $request->validated();
        
        // Check if it's new structure (has 'type' field)
        if (isset($data['type']) && in_array($data['type'], ['advanced', 'block', 'load'])) {
            // NEW STRUCTURE - use AcceptanceDataService
            $this->acceptanceDataService->createRejection($data);
        } else {
            // OLD STRUCTURE - use existing RejectionService
            $this->rejectionService->createRejection($data);
        }
        
        return back()->with('success', 'Rejection created successfully.');
    }

    /**
     * Update an existing rejection.
     *
     * @param UpdateRejectionRequest $request
     * @param string $tenantSlug
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateRejectionRequest $request, $tenantSlug, $id)
    {
        $data = $request->validated();
        
        // Check if rejection uses new structure
        $rejection = Rejection::findOrFail($id);
        
        if ($rejection->type) {
            // NEW STRUCTURE - use AcceptanceDataService
            $this->acceptanceDataService->updateRejection($id, $data);
        } else {
            // OLD STRUCTURE - use existing RejectionService
            $this->rejectionService->updateRejection($id, $data);
        }
        
        return back()->with('success', 'Rejection updated successfully.');
    }

    /**
     * Update a rejection as Admin.
     *
     * @param UpdateRejectionRequest $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateAdmin(UpdateRejectionRequest $request, $id)
    {
        $data = $request->validated();
        
        // Check if rejection uses new structure
        $rejection = Rejection::findOrFail($id);
        
        if ($rejection->type) {
            // NEW STRUCTURE
            $this->acceptanceDataService->updateRejection($id, $data);
        } else {
            // OLD STRUCTURE
            $this->rejectionService->updateRejection($id, $data);
        }
        
        return back()->with('success', 'Rejection updated successfully.');
    }

    /**
     * Delete a rejection.
     *
     * @param string $tenantSlug
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($tenantSlug, $id)
    {
        $rejection = Rejection::findOrFail($id);
        
        if ($rejection->type) {
            // NEW STRUCTURE
            $this->acceptanceDataService->deleteRejection($id);
        } else {
            // OLD STRUCTURE
            $this->rejectionService->deleteRejection($id);
        }
        
        return back()->with('success', 'Rejection deleted successfully.');
    }

    /**
     * Delete a rejection as Admin.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyAdmin($id)
    {
        $rejection = Rejection::findOrFail($id);
        
        if ($rejection->type) {
            // NEW STRUCTURE
            $this->acceptanceDataService->deleteRejection($id);
        } else {
            // OLD STRUCTURE
            $this->rejectionService->deleteRejection($id);
        }
        
        return back()->with('success', 'Rejection deleted successfully.');
    }

    /**
     * Create a new rejection reason code.
     *
     * @param \App\Http\Requests\Acceptance\StoreRejectionReasonCode $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeCode(StoreRejectionReasonCode $request)
    {
        $this->rejectionReasonCodesService->createReasonCode($request->validated());
        return back()->with('success', 'Reason code created successfully.');
    }

    /**
     * Delete a rejection reason code.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyCode($id)
    {
        $this->rejectionReasonCodesService->deleteReasonCode($id);
        return back()->with('success', 'Reason code deleted successfully.');
    }

    /**
     * Restore a soft-deleted rejection reason code.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restoreCode($id)
    {
        $this->rejectionReasonCodesService->restoreReasonCode($id);
        return back()->with('success', 'Reason code restored successfully.');
    }

    /**
     * Permanently force delete a rejection reason code.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function forceDeleteCode($id)
    {
        $this->rejectionReasonCodesService->forceDeleteReasonCode($id);
        return back()->with('success', 'Reason code permanently deleted.');
    }

    /**
     * Delete multiple rejection records.
     *
     * @param Request $request
     * @param string|null $tenantSlug
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyBulk(Request $request, $tenantSlug = null)
    {
        $ids = $request->input('ids', []);
        $this->rejectionService->deleteMultipleRejections($ids);
        return redirect()->back()->with('success', 'Rejections deleted successfully.');
    }

    /**
     * Delete multiple rejection records as Admin.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyBulkAdmin(Request $request)
    {
        $ids = $request->input('ids', []);
        $this->rejectionService->deleteMultipleRejections($ids);
        return redirect()->back()->with('success', 'Rejections deleted successfully.');
    }

    /**
     * Validate CSV import.
     * Detects if it's old or new format and validates accordingly.
     *
     * @param Request $request
     * @param string|null $tenantSlug
     * @return \Illuminate\Http\RedirectResponse
     */
    public function validateImport(Request $request, $tenantSlug = null)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        try {
            // Detect format by reading headers
            $file = $request->file('file');
            
            $handle = fopen($file->getRealPath(), 'r');
            $rawHeaders = fgetcsv($handle, 0, ',');
            
            // Clean headers - remove BOM and trim
            $cleanHeaders = array_map(function($header) {
                // Remove UTF-8 BOM
                $header = str_replace("\xEF\xBB\xBF", '', $header);
                return trim($header);
            }, $rawHeaders);
            
            // Count rows
            $rowCount = 0;
            while (fgetcsv($handle, 0, ',') !== false) {
                $rowCount++;
            }
            fclose($handle);
            
            // Check if it's new format (use lowercase for detection)
            $headersLower = array_map('strtolower', $cleanHeaders);
            $isNewFormat = $this->detectNewFormat($headersLower);
            
            if ($isNewFormat) {
                // NEW FORMAT - Store file and create validation results
                $path = $request->file('file')->store('temp-imports');
                
                session(['acceptance_import_file_path' => $path]);
                session(['acceptance_import_format' => 'new']);
                
                // Return proper structure for frontend
                return back()->with('importValidation', [
                    'success' => true,
                    'format' => 'new',
                    'results' => [
                        'headers' => $cleanHeaders,
                        'summary' => [
                            'total' => $rowCount,
                            'valid' => $rowCount,
                            'invalid' => 0,
                        ],
                        'valid' => [],
                        'invalid' => [],
                    ],
                ]);
            } else {
                // OLD FORMAT - use existing validation
                session(['acceptance_import_format' => 'old']);
                
                $results = $this->rejectionImportValidationService
                    ->validateRejectionsCsv($request->file('file'));

                if (isset($results['header_error'])) {
                    session()->forget('acceptance_import_validation_results');
                    session()->forget('acceptance_import_file_path');

                    return back()->with('importValidation', [
                        'success' => false,
                        'format' => 'old',
                        'header_error' => $results['header_error'],
                        'results' => $results,
                    ]);
                }

                session(['acceptance_import_validation_results' => $results]);
                
                if (($results['summary']['invalid'] ?? 0) === 0) {
                    $path = $request->file('file')->store('temp-imports');
                    session(['acceptance_import_file_path' => $path]);
                } else {
                    session()->forget('acceptance_import_file_path');
                }

                return back()->with('importValidation', [
                    'success' => true,
                    'format' => 'old',
                    'results' => $results,
                ]);
            }
        } catch (\Exception $e) {
            session()->forget('acceptance_import_validation_results');
            session()->forget('acceptance_import_file_path');
            session()->forget('acceptance_import_format');
            
            return back()->with('importValidation', [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Confirm and execute import.
     * Routes to correct service based on format.
     *
     * @param Request $request
     * @param string|null $tenantSlug
     * @return \Illuminate\Http\RedirectResponse
     */
    public function confirmImport(Request $request, $tenantSlug = null)
    {
        try {
            $filePath = session('acceptance_import_file_path');
            $format = session('acceptance_import_format', 'old');
            
            if (!$filePath || !Storage::exists($filePath)) {
                return back()->with('error', 'Import session expired. Please upload the file again.');
            }
            
            // Get tenant_id from request (superadmin dropdown)
            $tenantId = $request->input('tenant_id');

            if (!$tenantId) {
                $user = Auth::user();
                $tenantId = $user->tenant_id ?? session('selected_tenant_id');
            }

            // Handle trips file if provided (for driver mapping)
            $driverMapData = [];
            
            if ($request->hasFile('trips_file')) {
                $tripsFile = $request->file('trips_file');
                
                // Validate trips file
                $tripsHandle = fopen($tripsFile->getRealPath(), 'r');
                if (!$tripsHandle) {
                    return back()->with('error', 'Could not open trips file.');
                }
                
                // Read headers and clean them
                $tripsHeaders = fgetcsv($tripsHandle, 0, ',');
                if ($tripsHeaders === false) {
                    fclose($tripsHandle);
                    return back()->with('error', 'Trips file appears to be empty or invalid.');
                }
                
                $tripsHeaders = array_map(function($header) {
                    return trim(str_replace("\xEF\xBB\xBF", '', $header));
                }, $tripsHeaders);
                
                // Parse all trips rows
                $tripsRows = [];
                $rowNumber = 0;
                while (($row = fgetcsv($tripsHandle, 0, ',')) !== false) {
                    $rowNumber++;
                    
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        continue;
                    }
                    
                    // Normalize row to match header count
                    if (count($row) < count($tripsHeaders)) {
                        $row = array_pad($row, count($tripsHeaders), '');
                    } else if (count($row) > count($tripsHeaders)) {
                        $row = array_slice($row, 0, count($tripsHeaders));
                    }
                    
                    try {
                        $tripsRows[] = array_combine($tripsHeaders, $row);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
                fclose($tripsHandle);
                
                if (empty($tripsRows)) {
                    return back()->with('error', 'No valid trips data found in the uploaded file.');
                }
                
                // Build enhanced driver map using the import export service
                $driverMapData = $this->acceptanceImportExportService->buildDriverMap($tripsRows);
            }

            // Get the stored file
            $storedFile = Storage::path($filePath);
            
            // Create UploadedFile instance
            $file = new \Illuminate\Http\UploadedFile(
                $storedFile,
                basename($filePath),
                mime_content_type($storedFile),
                null,
                true
            );

            // Prepare import request
            $importRequest = new Request();
            $importRequest->files->set('csv_file', $file);
            
            $mergeData = [
                'tenant_id' => $tenantId,
            ];
            
            // Add driver map data if available
            if (!empty($driverMapData)) {
                $mergeData['driver_map'] = $driverMapData;
            }
            
            $importRequest->merge($mergeData);

            // Route to correct service based on format
            if ($format === 'new') {
                // NEW FORMAT - use AcceptanceImportExportService
                $result = $this->acceptanceImportExportService->importRejections($importRequest);
                
                $message = "{$result['imported']} rows imported successfully.";
                
                // Add driver mapping info to message
                if (!empty($driverMapData)) {
                    $driversMatched = $result['drivers_matched'] ?? 0;
                    $message .= " Driver names mapped for {$driversMatched} loads.";
                }
                
                if (($result['created'] ?? 0) > 0) {
                    $message .= " {$result['created']} new records created.";
                }
                if (($result['updated'] ?? 0) > 0) {
                    $message .= " {$result['updated']} existing records updated.";
                }
                if (($result['skipped'] ?? 0) > 0) {
                    $message .= " {$result['skipped']} rows skipped (duplicates).";
                }
                if (!empty($result['errors'])) {
                    $message .= " " . count($result['errors']) . " errors occurred.";
                }
                
                // Clean up session
                Storage::delete($filePath);
                session()->forget([
                    'acceptance_import_file_path', 
                    'acceptance_import_validation_results', 
                    'acceptance_import_format'
                ]);
                
                return back()->with('success', $message);
                
            } else {
                // OLD FORMAT - use existing validation service
                // Note: Old format doesn't support driver mapping
                $this->rejectionImportValidationService->importRejections($importRequest);
                
                // Clean up session
                Storage::delete($filePath);
                session()->forget([
                    'acceptance_import_file_path', 
                    'acceptance_import_validation_results', 
                    'acceptance_import_format'
                ]);
                
                return back()->with('success', 'Rejections imported successfully.');
            }
            
        } catch (\Exception $e) {
            // Clean up session on error
            session()->forget([
                'acceptance_import_file_path', 
                'acceptance_import_validation_results', 
                'acceptance_import_format'
            ]);
            
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    /**
     * Download error report from validation.
     *
     * @param Request $request
     * @param string|null $tenantSlug
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadErrorReport(Request $request, $tenantSlug = null)
    {
        try {
            $results = session('acceptance_import_validation_results');
            
            if (!$results || empty($results['invalid'])) {
                return back()->with('error', 'No validation errors to download.');
            }

            $filePath = $this->rejectionImportValidationService
                ->generateErrorReport($results['invalid']);

            return response()->download($filePath)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to generate error report: ' . $e->getMessage());
        }
    }

    /**
     * Export rejections to CSV file.
     * Automatically detects and exports appropriate format.
     */
    public function export($tenantSlug = null)
    {
        try {
            // Check if there are new structure rejections
            $hasNewStructure = Rejection::whereNotNull('type')->exists();
            
            if ($hasNewStructure) {
                // Use new export service (exports new structure)
                return $this->acceptanceImportExportService->exportRejections();
            } else {
                // Use old export service (exports old structure)
                // Note: Old format export might need to be implemented
                return back()->with('error', 'Old format export not available. Please use new format.');
            }
        } catch (\Exception $e) {
            return back()->with('error', 'Export failed: ' . $e->getMessage());
        }
    }

    /**
     * Export rejections to CSV file for admin.
     */
    public function exportAdmin()
    {
        return $this->export(null);
    }

    /**
     * Detect if CSV is new format based on headers.
     *
     * @param array $headers (lowercase)
     * @return bool
     */
    protected function detectNewFormat(array $headers): bool
    {
        $newFormatIndicators = [
            'advance block rejection id',
            'expected blocks',
            'tendered blocks',
            'impacted blocks',
            'block acceptance status',
            'block rejection time',
            'trip id',
            'load status',
            'rejection bucket',
            'origin yard arrival time',
        ];

        foreach ($newFormatIndicators as $indicator) {
            if (in_array($indicator, $headers)) {
                return true;
            }
        }

        return false;
    }
}