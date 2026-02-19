<?php

namespace App\Services\Acceptance;

use App\Models\Rejection;
use App\Models\Tenant;
use App\Models\AdvancedRejectionDetail;
use App\Models\BlockRejectionDetail;
use App\Models\LoadRejectionDetail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class AcceptanceImportExportService
{
    protected AcceptanceDataService $dataService;
    
    // Chunk size for batch inserts
    private const CHUNK_SIZE = 100;

    public function __construct(
        AcceptanceDataService $dataService
    ) {
        $this->dataService = $dataService;
    }

    // ════════════════════════════════════════════════════════════════════════
    // MAIN IMPORT METHODS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * OPTIMIZED: Import rejections from CSV file using bulk operations
     * Now properly handles ACCEPTED → REJECTED transitions
     */
    public function importRejections(Request $request): array
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt',
        ]);
        
        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            throw new \Exception('Could not open the CSV file.');
        }

        $isSuperAdmin = Auth::user()->tenant_id === null;
        $driverMap = $request->input('driver_map', []);
        
        // ===== PASS 1: Read and clean headers =====
        $headers = fgetcsv($handle, 0, ',');
        $headers = array_map(function($header) {
            return trim(str_replace("\xEF\xBB\xBF", '', $header));
        }, $headers);
        
        $headerCount = count($headers);
        
        // Detect type
        $type = $this->detectTypeFromHeaders($headers);
        
        // ===== PASS 2: Read ALL rows into memory with intelligent duplicate handling =====
        $allRows = [];
        $rowNumber = 1;
        $duplicateCount = 0;
        
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $rowNumber++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Normalize column count
            $rowCount = count($row);
            if ($rowCount !== $headerCount) {
                if ($rowCount < $headerCount) {
                    $row = array_pad($row, $headerCount, '');
                } else {
                    $row = array_slice($row, 0, $headerCount);
                }
            }

            $data = array_combine($headers, $row);
            
            // Get tenant ID
            $tenantId = $this->resolveTenantId($request, $data, $isSuperAdmin);
            if (!$tenantId) {
                continue;
            }

            // Parse row data
            try {
                $rejectionData = $this->parseRowData($data, $type, $tenantId);
                
                // Get unique ID for this row
                $uniqueId = $this->getUniqueId($type, $rejectionData);
                
                if (empty($uniqueId)) {
                    continue;
                }

                // Intelligent duplicate handling
                if (!isset($allRows[$uniqueId])) {
                    // First time seeing this ID - just store it
                    $allRows[$uniqueId] = [
                        'unique_id' => $uniqueId,
                        'data' => $rejectionData,
                        'tenant_id' => $tenantId,
                        'row_number' => $rowNumber,
                        'raw_data' => $data
                    ];
                } else {
                    // We've seen this ID before - check which one to keep
                    $existingRow = $allRows[$uniqueId];
                    
                    // Get status from raw data for better detection
                    $existingStatus = strtoupper(trim($existingRow['raw_data']['Block Acceptance Status'] ?? ''));
                    $newStatus = strtoupper(trim($data['Block Acceptance Status'] ?? $data['block acceptance status'] ?? ''));
                    
                    $existingIsAccepted = $existingStatus === 'ACCEPTED' || empty($existingRow['data']['rejection_reason']);
                    $newIsRejected = $newStatus === 'REJECTED' || !empty($rejectionData['rejection_reason']);
                    
                    // If existing is ACCEPTED and new is REJECTED, REPLACE with the REJECTED version
                    if ($existingIsAccepted && $newIsRejected) {
                        // Replace with the REJECTED version
                        $allRows[$uniqueId] = [
                            'unique_id' => $uniqueId,
                            'data' => $rejectionData,
                            'tenant_id' => $tenantId,
                            'row_number' => $rowNumber,
                            'raw_data' => $data
                        ];
                    } else {
                        $duplicateCount++;
                    }
                }
                
            } catch (\Exception $e) {
                continue;
            }
        }
        
        fclose($handle);

        // Convert back to indexed array for processing
        $allRows = array_values($allRows);

        // ===== PASS 3: ONE BULK QUERY to get all existing records =====
        $existingMap = $this->buildExistingMap($type, $allRows);

        // ===== PASS 4: Classify rows (create vs update) =====
        $toCreate = [];
        $toUpdate = [];
        $errors = [];
        $driversMatched = 0;

        // Extract driver maps for easier access
        $loadIdToDriverMap = $driverMap['load_map'] ?? [];
        $operatorToDriverMap = $driverMap['operator_map'] ?? [];

        foreach ($allRows as $item) {
            try {
                // Apply driver mapping if this is a load type and we have driver map
                if ($type === 'load' && !empty($driverMap)) {
                    $loadId = $item['data']['load_id'] ?? null;
                    $operatorId = $this->findColumnValue($item['raw_data'], ['Operator ID', 'operator id', 'Operator', 'operator']);
                    
                    // Method 1: Try direct Load ID match
                    if (!empty($loadId) && isset($loadIdToDriverMap[$loadId])) {
                        $item['data']['driver_name'] = $loadIdToDriverMap[$loadId];
                        $driversMatched++;
                    }
                    // Method 2: Try Operator ID match
                    elseif (!empty($operatorId) && isset($operatorToDriverMap[$operatorId])) {
                        $item['data']['driver_name'] = $operatorToDriverMap[$operatorId];
                        $driversMatched++;
                    }
                }
                
                if (isset($existingMap[$item['unique_id']])) {
                    // EXISTING - check if this is an update
                    $existing = $existingMap[$item['unique_id']];
                    
                    // Check if this is an ACCEPTED → REJECTED transition
                    $oldDetail = $existing['detail'];
                    $rawData = $item['raw_data'];
                    
                    $isAcceptedToRejected = false;
                    
                    switch ($type) {
                        case 'block':
                            $oldHasRejection = !empty($oldDetail->rejection_reason);
                            $newHasRejection = !empty($rawData['Block Rejection Reason'] ?? $rawData['block rejection reason'] ?? '');
                            $newStatus = strtoupper(trim($rawData['Block Acceptance Status'] ?? $rawData['block acceptance status'] ?? 'REJECTED'));
                            
                            // If old was ACCEPTED (no reason) and new is REJECTED (has reason)
                            if (!$oldHasRejection && $newHasRejection && $newStatus === 'REJECTED') {
                                $isAcceptedToRejected = true;
                            }
                            break;
                            
                        case 'load':
                            $oldHasRejection = !empty($oldDetail->rejection_reason);
                            $newHasRejection = !empty($rawData['Rejection Reason'] ?? $rawData['rejection reason'] ?? '');
                            $newStatus = strtoupper(trim($rawData['Load Status'] ?? $rawData['load status'] ?? 'REJECTED'));
                            
                            if (!$oldHasRejection && $newHasRejection && $newStatus === 'REJECTED') {
                                $isAcceptedToRejected = true;
                            }
                            break;
                    }
                    
                    if ($isAcceptedToRejected) {
                        // This is an UPDATE (ACCEPTED → REJECTED)
                        // Set dispute status based on win condition
                        $winCondition = $this->detectWinCondition($type, $oldDetail, $rawData);
                        
                        if ($winCondition) {
                            $item['data']['dispute_status'] = 'won';
                            $item['data']['carrier_controllable'] = false;
                            $item['data']['penalty'] = 0;
                        } else {
                            $item['data']['dispute_status'] = 'none';
                        }
                        
                        // Preserve driver_controllable from existing
                        $item['data']['driver_controllable'] = $existing['rejection']->driver_controllable;
                        
                        $toUpdate[] = [
                            'id' => $existing['rejection']->id,
                            'data' => $item['data'],
                            'row_number' => $item['row_number']
                        ];
                        
                    } else {
                        // Check for win condition (REJECTED → ACCEPTED)
                        $winCondition = $this->detectWinCondition($type, $oldDetail, $rawData);
                        
                        if ($winCondition) {
                            $item['data']['dispute_status'] = 'won';
                            $item['data']['carrier_controllable'] = false;
                            $item['data']['penalty'] = 0;
                        } else {
                            // Preserve driver_controllable from existing
                            $item['data']['driver_controllable'] = $existing['rejection']->driver_controllable;
                        }
                        
                        $toUpdate[] = [
                            'id' => $existing['rejection']->id,
                            'data' => $item['data'],
                            'row_number' => $item['row_number']
                        ];
                    }
                } else {
                    // NEW - always create
                    $toCreate[] = [
                        'data' => $item['data'],
                        'row_number' => $item['row_number']
                    ];
                }
            } catch (\Exception $e) {
                $errors[] = "Row {$item['row_number']}: " . $e->getMessage();
            }
        }

        // ===== PASS 5: BULK INSERTS in chunks with proper error handling =====
        $created = 0;
        $updated = 0;
        $insertErrors = [];
        
        // Create new records in chunks
        if (!empty($toCreate)) {
            // Final duplicate check
            $uniqueToCreate = [];
            foreach ($toCreate as $item) {
                $uniqueId = $this->getUniqueId($type, $item['data']);
                if (!isset($uniqueToCreate[$uniqueId])) {
                    $uniqueToCreate[$uniqueId] = $item;
                }
            }
            $toCreate = array_values($uniqueToCreate);
            
            foreach (array_chunk($toCreate, self::CHUNK_SIZE) as $chunkIndex => $chunk) {
                try {
                    DB::beginTransaction();
                    
                    foreach ($chunk as $item) {
                        try {
                            $rejection = $this->dataService->createRejection($item['data']);
                            $created++;
                        } catch (\Exception $e) {
                            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                                continue;
                            } else {
                                throw $e;
                            }
                        }
                    }
                    
                    DB::commit();
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                        $insertErrors[] = "Create chunk " . ($chunkIndex + 1) . " failed: " . $e->getMessage();
                    }
                }
            }
        }

        // Update existing records in chunks
        if (!empty($toUpdate)) {
            foreach (array_chunk($toUpdate, self::CHUNK_SIZE) as $chunkIndex => $chunk) {
                try {
                    DB::beginTransaction();
                    
                    foreach ($chunk as $item) {
                        try {
                            $rejection = $this->dataService->updateRejection($item['id'], $item['data']);
                            $updated++;
                        } catch (\Exception $e) {
                            throw $e;
                        }
                    }
                    
                    DB::commit();
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    $insertErrors[] = "Update chunk " . ($chunkIndex + 1) . " failed: " . $e->getMessage();
                }
            }
        }

        return [
            'imported' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $duplicateCount,
            'duplicates_removed' => $duplicateCount,
            'drivers_matched' => $driversMatched,
            'errors' => array_merge($errors, $insertErrors),
        ];
    }

    /**
     * Build a map of Load ID → Driver Name by processing trips CSV sequentially
     */
    public function buildDriverMap(array $tripsRows): array
    {
        $driverMap = [];
        $operatorToDriverMap = [];
        $processedOperators = [];
        
        foreach ($tripsRows as $row) {
            // Find columns
            $loadId = $this->findColumnValue($row, ['Load ID', 'load id', 'Loads', 'loads']);
            $operatorId = $this->findColumnValue($row, ['Operator ID', 'operator id', 'Operator', 'operator']);
            $driverName = $this->findColumnValue($row, ['Driver Name', 'driver name', 'Driver', 'driver']);
            $estimatedCost = $this->findColumnValue($row, ['Estimated Cost', 'estimated cost', 'Cost', 'cost']);
            
            // Direct Load ID → Driver mapping (always do this)
            if (!empty($loadId) && !empty($driverName)) {
                $driverMap[$loadId] = $driverName;
            }
            
            // Build Operator → Driver map (only for first occurrence with estimated cost)
            if (!empty($operatorId) && !empty($driverName) && !isset($processedOperators[$operatorId]) && !empty($estimatedCost)) {
                $operatorToDriverMap[$operatorId] = $driverName;
                $processedOperators[$operatorId] = true;
            }
        }
        
        // Store both maps in the result
        return [
            'load_map' => $driverMap,
            'operator_map' => $operatorToDriverMap
        ];
    }

    /**
     * Bulk import block rejections — saves ALL rows (accepted + rejected).
     */
    public function bulkImportBlockRejections(array $rows, int $tenantId): array
    {
        // ── PASS 1: Accept ALL rows, only require Block ID ─────────────────
        $validRows = [];
        $blockIds  = [];

        foreach ($rows as $row) {
            $blockId = trim($row['Block ID'] ?? $row['block id'] ?? $row["\xEF\xBB\xBFBlock ID"] ?? '');

            if (empty($blockId)) {
                continue;
            }

            $validRows[] = $row;
            $blockIds[]  = $blockId;
        }

        if (empty($validRows)) {
            return ['imported' => 0, 'skipped' => count($rows)];
        }

        // ── PASS 2: ONE bulk query for all existing block records ──────────
        $existingMap = $this->buildBlockExistingMap($blockIds, $tenantId);

        // ── PASS 3: Classify into create / update ─────────────────────────
        $toCreate = [];
        $toUpdate = [];

        foreach ($validRows as $row) {
            $blockId    = trim($row['Block ID'] ?? $row['block id'] ?? $row["\xEF\xBB\xBFBlock ID"] ?? '');
            $status     = strtoupper(trim($row['Block Acceptance Status'] ?? $row['block acceptance status'] ?? ''));
            $isRejected = $status === 'REJECTED';

            $rejectionReason = $isRejected
                ? trim($row['Block Rejection Reason'] ?? $row['block rejection reason'] ?? '')
                : '';
            $bucket = $isRejected
                ? trim($row['Block Rejection Bucket'] ?? $row['block rejection bucket'] ?? '')
                : '';

            if (isset($existingMap[$blockId])) {
                $toUpdate[] = [
                    'existing'    => $existingMap[$blockId],
                    'row'         => $row,
                    'bucket'      => $bucket,
                    'is_rejected' => $isRejected,
                ];
            } else {
                $toCreate[] = [
                    'row'              => $row,
                    'block_id'         => $blockId,
                    'rejection_reason' => $rejectionReason,
                    'bucket'           => $bucket,
                    'is_rejected'      => $isRejected,
                ];
            }
        }

        // ── PASS 4: Bulk writes in chunked transactions ────────────────────
        $imported = 0;

        foreach (array_chunk($toCreate, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $tenantId, &$imported) {
                foreach ($chunk as $item) {
                    $this->createNewBlockRejection(
                        $item['row'],
                        $tenantId,
                        $item['block_id'],
                        $item['rejection_reason'],
                        $item['bucket']
                    );
                    $imported++;
                }
            });
        }

        foreach (array_chunk($toUpdate, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$imported) {
                foreach ($chunk as $item) {
                    $this->handleBlockReImport(
                        $item['existing']['rejection'],
                        $item['row'],
                        $item['bucket'],
                        $item['existing']['detail']
                    );
                    $imported++;
                }
            });
        }

        $skipped = count($rows) - $imported;
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Bulk import load rejections — saves ALL rows (accepted + rejected).
     * NOTE: This method is currently NOT used in the main import flow.
     * The driver mapping is handled directly in importRejections().
     * Kept for future optimization if needed.
     */
    public function bulkImportLoadRejections(array $rows, int $tenantId, array $driverMap = []): array
    {
        dd('bulkImportLoadRejections is currently not used. Driver mapping is handled in importRejections(). This method can be optimized and re-enabled in the future if needed.');
        // Extract the maps
        $loadIdToDriverMap = $driverMap['load_map'] ?? [];
        $operatorToDriverMap = $driverMap['operator_map'] ?? [];

        // ── PASS 1: Accept ALL rows, only require Load ID ──────────────────
        $validRows = [];
        $loadIds   = [];
        foreach ($rows as $row) {
            $loadId = trim($row['Loads'] ?? $row['loads'] ?? '');
            if (empty($loadId)) {
                $loadId = trim($row['Trip ID'] ?? $row['trip id'] ?? '');
            }

            if (empty($loadId)) {
                continue;
            }

            $validRows[] = $row;
            $loadIds[]   = $loadId;
        }

        if (empty($validRows)) {
            return ['imported' => 0, 'skipped' => count($rows), 'drivers_matched' => 0];
        }

        // ── PASS 2: ONE bulk query for all existing load records ───────────
        $existingMap = $this->buildLoadExistingMap($loadIds, $tenantId);

        // ── PASS 3: Classify ───────────────────────────────────────────────
        $toCreate = [];
        $toUpdate = [];
        $driversMatched = 0;
        $matchedByLoadId = 0;
        $matchedByOperator = 0;
        
        foreach ($validRows as $row) {
            $loadId = trim($row['Loads'] ?? $row['loads'] ?? '');
            if (empty($loadId)) {
                $loadId = trim($row['Trip ID'] ?? $row['trip id'] ?? '');
            }

            $operatorId = $this->findColumnValue($row, ['Operator ID', 'operator id', 'Operator', 'operator']);
            $status = strtoupper(trim($row['Load Status'] ?? $row['load status'] ?? ''));
            $isRejected = $status !== 'ACCEPTED';

            $rejectionReason = $isRejected
                ? trim($row['Rejection Reason'] ?? $row['rejection reason'] ?? '')
                : '';
            $bucket = $isRejected
                ? trim($row['Rejection Bucket'] ?? $row['rejection bucket'] ?? '')
                : '';
            
            $driverName = null;

            // Method 1: Try direct Load ID match from the driver map
            if (!empty($loadId) && isset($loadIdToDriverMap[$loadId])) {
                $driverName = $loadIdToDriverMap[$loadId];
                // Always overwrite the driver name in the row with the mapped driver name
                $row['driver_name'] = $driverName;
                $matchedByLoadId++;
                $driversMatched++;
            }
            // Method 2: Try Operator ID match from the operator map
            elseif (!empty($operatorId) && isset($operatorToDriverMap[$operatorId])) {
                $driverName = $operatorToDriverMap[$operatorId];
                // Always overwrite the driver name in the row with the mapped driver name
                $row['driver_name'] = $driverName;
                $matchedByOperator++;
                $driversMatched++;
            }

            // Continue with the process of creating or updating the load rejection
            if (isset($existingMap[$loadId])) {
                $toUpdate[] = [
                    'existing'    => $existingMap[$loadId],
                    'row'         => $row,
                    'bucket'      => $bucket,
                    'is_rejected' => $isRejected,
                    'driver_name' => $driverName,
                ];
            } else {
                $toCreate[] = [
                    'row'              => $row,
                    'load_id'          => $loadId,
                    'rejection_reason' => $rejectionReason,
                    'bucket'           => $bucket,
                    'is_rejected'      => $isRejected,
                    'driver_name'      => $driverName,
                ];
            }
        }

        // ── PASS 4: Bulk writes ────────────────────────────────────────────
        $imported = 0;

        foreach (array_chunk($toCreate, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $tenantId, &$imported) {
                foreach ($chunk as $item) {
                    $this->createNewLoadRejection(
                        $item['row'],
                        $tenantId,
                        $item['load_id'],
                        $item['rejection_reason'],
                        $item['bucket'],
                        $item['driver_name']
                    );
                    $imported++;
                }
            });
        }

        foreach (array_chunk($toUpdate, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$imported) {
                foreach ($chunk as $item) {
                    $this->handleLoadReImport(
                        $item['existing']['rejection'],
                        $item['row'],
                        $item['bucket'],
                        $item['existing']['detail'],
                        $item['driver_name']
                    );
                    $imported++;
                }
            });
        }

        $skipped = count($rows) - $imported;

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'drivers_matched' => $driversMatched,
            'matched_by_load_id' => $matchedByLoadId,
            'matched_by_operator' => $matchedByOperator,
        ];
    }
    
    /**
     * Bulk import advanced rejections from an array of CSV rows.
     */
    public function bulkImportAdvancedRejections(array $rows, int $tenantId): array
    {
        // ── PASS 1: Extract IDs ────────────────────────────────────────────
        $validRows        = [];
        $advancedBlockIds = [];

        foreach ($rows as $row) {
            $advancedBlockId = trim(
                $row['Advance block rejection ID']            ??
                $row['advance block rejection id']            ??
                $row["\xEF\xBB\xBFAdvance block rejection ID"] ?? ''
            );

            if (empty($advancedBlockId)) {
                continue;
            }

            $validRows[]        = $row;
            $advancedBlockIds[] = $advancedBlockId;
        }

        if (empty($validRows)) {
            return ['imported' => 0, 'skipped' => count($rows)];
        }

        // ── PASS 2: ONE bulk query for all existing advanced records ───────
        $existingMap = $this->buildAdvancedExistingMap($advancedBlockIds, $tenantId);

        // ── PASS 3: Classify ───────────────────────────────────────────────
        $toCreate = [];
        $toUpdate = [];

        foreach ($validRows as $row) {
            $advancedBlockId = trim(
                $row['Advance block rejection ID']            ??
                $row['advance block rejection id']            ??
                $row["\xEF\xBB\xBFAdvance block rejection ID"] ?? ''
            );
            $reason         = trim($row['Reason(s)'] ?? $row['reason(s)'] ?? $row['Reason'] ?? '');
            $impactedBlocks = (int) ($row['Impacted blocks'] ?? $row['impacted blocks'] ?? 0);

            if (isset($existingMap[$advancedBlockId])) {
                $toUpdate[] = [
                    'existing'        => $existingMap[$advancedBlockId],
                    'row'             => $row,
                    'reason'          => $reason,
                    'impacted_blocks' => $impactedBlocks,
                ];
            } else {
                $toCreate[] = [
                    'row'               => $row,
                    'advanced_block_id' => $advancedBlockId,
                    'reason'            => $reason,
                    'impacted_blocks'   => $impactedBlocks,
                ];
            }
        }

        // ── PASS 4: Bulk writes ────────────────────────────────────────────
        $imported = 0;

        foreach (array_chunk($toCreate, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $tenantId, &$imported) {
                foreach ($chunk as $item) {
                    $this->createNewAdvancedRejection(
                        $item['row'],
                        $tenantId,
                        $item['advanced_block_id'],
                        $item['reason']
                    );
                    $imported++;
                }
            });
        }

        foreach (array_chunk($toUpdate, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$imported) {
                foreach ($chunk as $item) {
                    $detail = $item['existing']['detail'];
                    $detail->update([
                        'week_start_at'   => $this->parseDateTime($item['row']['Week Start Date'] ?? $item['row']['week start date'] ?? ''),
                        'week_end_at'     => $this->parseDateTime($item['row']['Week End Date']   ?? $item['row']['week end date']   ?? ''),
                        'impacted_blocks' => $item['impacted_blocks'],
                        'reason'          => $item['reason'],
                    ]);

                    $imported++;
                }
            });
        }

        $skipped = count($rows) - $imported;
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    // ════════════════════════════════════════════════════════════════════════
    // SINGLE-ROW IMPORT METHODS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Import a single block rejection row — saves ALL statuses.
     */
    public function importBlockRejection(array $row, int $tenantId): void
    {
        DB::transaction(function () use ($row, $tenantId) {
            $blockId    = trim($row['Block ID'] ?? $row['block id'] ?? $row["\xEF\xBB\xBFBlock ID"] ?? '');
            $status     = strtoupper(trim($row['Block Acceptance Status'] ?? $row['block acceptance status'] ?? ''));
            $isRejected = $status === 'REJECTED';

            $rejectionReason = $isRejected
                ? trim($row['Block Rejection Reason'] ?? $row['block rejection reason'] ?? '')
                : '';
            $bucket = $isRejected
                ? trim($row['Block Rejection Bucket'] ?? $row['block rejection bucket'] ?? '')
                : '';

            if (empty($blockId)) {
                return;
            }

            $existingMap = $this->buildBlockExistingMap([$blockId], $tenantId);

            if (isset($existingMap[$blockId])) {
                $this->handleBlockReImport(
                    $existingMap[$blockId]['rejection'],
                    $row,
                    $bucket,
                    $existingMap[$blockId]['detail']
                );
            } else {
                $this->createNewBlockRejection($row, $tenantId, $blockId, $rejectionReason, $bucket);
            }
        });
    }

    /**
     * Import a single load rejection row — saves ALL statuses.
     */
    public function importLoadRejection(array $row, int $tenantId, ?string $driverName = null): void
    {
        DB::transaction(function () use ($row, $tenantId, $driverName) {
            $loadId = trim($row['Loads'] ?? $row['loads'] ?? '');
            if (empty($loadId)) {
                $loadId = trim($row['Trip ID'] ?? $row['trip id'] ?? '');
            }

            $status     = strtoupper(trim($row['Load Status'] ?? $row['load status'] ?? ''));
            $isRejected = $status !== 'ACCEPTED';

            $rejectionReason = $isRejected
                ? trim($row['Rejection Reason'] ?? $row['rejection reason'] ?? '')
                : '';
            $bucket = $isRejected
                ? trim($row['Rejection Bucket'] ?? $row['rejection bucket'] ?? '')
                : '';

            if (empty($loadId)) {
                return;
            }

            $existingMap = $this->buildLoadExistingMap([$loadId], $tenantId);

            if (isset($existingMap[$loadId])) {
                $this->handleLoadReImport(
                    $existingMap[$loadId]['rejection'],
                    $row,
                    $bucket,
                    $existingMap[$loadId]['detail'],
                    $driverName
                );
            } else {
                $this->createNewLoadRejection($row, $tenantId, $loadId, $rejectionReason, $bucket, $driverName);
            }
        });
    }

    /**
     * Import a single advanced rejection row.
     */
    public function importAdvancedRejection(array $row, int $tenantId): void
    {
        DB::transaction(function () use ($row, $tenantId) {
            $advancedBlockId = trim(
                $row['Advance block rejection ID']            ??
                $row['advance block rejection id']            ??
                $row["\xEF\xBB\xBFAdvance block rejection ID"] ?? ''
            );
            $reason         = trim($row['Reason(s)'] ?? $row['reason(s)'] ?? $row['Reason'] ?? '');

            if (empty($advancedBlockId)) {
                return;
            }

            $existingMap = $this->buildAdvancedExistingMap([$advancedBlockId], $tenantId);

            if (isset($existingMap[$advancedBlockId])) {
                $existingMap[$advancedBlockId]['detail']->update([
                    'week_start_at'   => $this->parseDateTime($row['Week Start Date'] ?? $row['week start date'] ?? ''),
                    'week_end_at'     => $this->parseDateTime($row['Week End Date']   ?? $row['week end date']   ?? ''),
                    'impacted_blocks' => (int) ($row['Impacted blocks'] ?? $row['impacted blocks'] ?? 0),
                    'reason'          => $reason,
                ]);
            } else {
                $this->createNewAdvancedRejection($row, $tenantId, $advancedBlockId, $reason);
            }
        });
    }

    /**
     * Assign driver names to load rejections using efficient operator-based mapping
     */
    public function assignDriverNamesToLoads(array $loadRows, array $tripsRows): array
    {
        // Step 1: Build a quick lookup map for Load ID → Driver Name from trips
        $loadIdToDriverMap = [];
        foreach ($tripsRows as $tripRow) {
            $loadId = $this->findColumnValue($tripRow, ['Load ID', 'load id', 'Loads', 'loads']);
            $driverName = $this->findColumnValue($tripRow, ['Driver Name', 'driver name', 'Driver', 'driver']);
            
            if (!empty($loadId) && !empty($driverName)) {
                $loadIdToDriverMap[$loadId] = $driverName;
            }
        }

        // Step 2: Build operator to driver map based on first occurrence with estimated cost
        $operatorToDriverMap = [];
        
        // Sort trips by row order to ensure we get the first occurrence
        $processedOperators = [];
        foreach ($tripsRows as $tripRow) {
            $operatorId = $this->findColumnValue($tripRow, ['Operator ID', 'operator id', 'Operator', 'operator']);
            $driverName = $this->findColumnValue($tripRow, ['Driver Name', 'driver name', 'Driver', 'driver']);
            $estimatedCost = $this->findColumnValue($tripRow, ['Estimated Cost', 'estimated cost', 'Cost', 'cost']);
            
            // Skip if no operator ID or driver name
            if (empty($operatorId) || empty($driverName)) {
                continue;
            }
            
            // Only set if we haven't processed this operator yet AND estimated cost is not empty
            if (!isset($processedOperators[$operatorId]) && !empty($estimatedCost)) {
                $operatorToDriverMap[$operatorId] = $driverName;
                $processedOperators[$operatorId] = true;
            }
        }

        // Step 3: Process each load row and assign driver names
        $assignedCount = 0;
        $alreadyHadDriver = 0;
        $assignedByLoadId = 0;
        $assignedByOperator = 0;
        
        foreach ($loadRows as &$loadRow) {
            // Check if driver name already exists
            $existingDriver = $this->findColumnValue($loadRow, ['Driver Name', 'driver name', 'Driver', 'driver']);
            if (!empty($existingDriver)) {
                $alreadyHadDriver++;
                continue;
            }
            
            $loadId = $this->findColumnValue($loadRow, ['Load ID', 'load id', 'Loads', 'loads']);
            $operatorId = $this->findColumnValue($loadRow, ['Operator ID', 'operator id', 'Operator', 'operator']);
            
            $assigned = false;
            
            // Method 1: Try direct Load ID match
            if (!empty($loadId) && isset($loadIdToDriverMap[$loadId])) {
                $driverName = $loadIdToDriverMap[$loadId];
                $loadRow['driver_name'] = $driverName;
                $loadRow['Driver Name'] = $driverName;
                $assigned = true;
                $assignedByLoadId++;
            }
            
            // Method 2: Try Operator ID with previous mapping
            if (!$assigned && !empty($operatorId) && isset($operatorToDriverMap[$operatorId])) {
                $driverName = $operatorToDriverMap[$operatorId];
                $loadRow['driver_name'] = $driverName;
                $loadRow['Driver Name'] = $driverName;
                $assigned = true;
                $assignedByOperator++;
            }
            
            if ($assigned) {
                $assignedCount++;
            }
        }
        
        return $loadRows;
    }

    // ════════════════════════════════════════════════════════════════════════
    // PRIVATE BULK LOOKUP HELPERS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Find column value by trying multiple possible names
     */
    private function findColumnValue(array $row, array $possibleNames): ?string
    {
        foreach ($possibleNames as $name) {
            if (isset($row[$name]) && !empty(trim($row[$name]))) {
                return trim($row[$name]);
            }
        }
        return null;
    }

    /**
     * Build a map of existing records in ONE bulk query
     */
    private function buildExistingMap(string $type, array $allRows): array
    {
        $uniqueIds = array_column($allRows, 'unique_id');
        $uniqueIds = array_filter(array_unique($uniqueIds));
        
        if (empty($uniqueIds)) {
            return [];
        }

        $map = [];

        switch ($type) {
            case 'advanced':
                $details = AdvancedRejectionDetail::whereIn('advanced_block_id', $uniqueIds)
                    ->with('rejection')
                    ->get();
                    
                foreach ($details as $detail) {
                    if ($detail->rejection) {
                        $map[$detail->advanced_block_id] = [
                            'rejection' => $detail->rejection,
                            'detail' => $detail
                        ];
                    }
                }
                break;

            case 'block':
                $details = BlockRejectionDetail::whereIn('block_id', $uniqueIds)
                    ->with('rejection')
                    ->get();
                    
                foreach ($details as $detail) {
                    if ($detail->rejection) {
                        $map[$detail->block_id] = [
                            'rejection' => $detail->rejection,
                            'detail' => $detail
                        ];
                    }
                }
                break;

            case 'load':
                $details = LoadRejectionDetail::whereIn('load_id', $uniqueIds)
                    ->with('rejection')
                    ->get();
                    
                foreach ($details as $detail) {
                    if ($detail->rejection) {
                        $map[$detail->load_id] = [
                            'rejection' => $detail->rejection,
                            'detail' => $detail
                        ];
                    }
                }
                break;
        }

        return $map;
    }

    /**
     * Load all existing block rejections matching the given block IDs in ONE query.
     */
    private function buildBlockExistingMap(array $blockIds, int $tenantId): array
    {
        if (empty($blockIds)) {
            return [];
        }

        $map     = [];
        $details = BlockRejectionDetail::whereIn('block_id', $blockIds)
            ->with(['rejection' => fn ($q) => $q->where('tenant_id', $tenantId)->where('type', 'block')])
            ->get();

        foreach ($details as $detail) {
            if ($detail->rejection) {
                $map[$detail->block_id] = [
                    'rejection' => $detail->rejection,
                    'detail'    => $detail,
                ];
            }
        }

        return $map;
    }

    /**
     * Load all existing load rejections matching the given load IDs in ONE query.
     */
    private function buildLoadExistingMap(array $loadIds, int $tenantId): array
    {
        if (empty($loadIds)) {
            return [];
        }

        $map     = [];
        $details = LoadRejectionDetail::whereIn('load_id', $loadIds)
            ->with(['rejection' => fn ($q) => $q->where('tenant_id', $tenantId)->where('type', 'load')])
            ->get();

        foreach ($details as $detail) {
            if ($detail->rejection) {
                $map[$detail->load_id] = [
                    'rejection' => $detail->rejection,
                    'detail'    => $detail,
                ];
            }
        }

        return $map;
    }

    /**
     * Load all existing advanced rejections matching the given IDs in ONE query.
     */
    private function buildAdvancedExistingMap(array $advancedBlockIds, int $tenantId): array
    {
        if (empty($advancedBlockIds)) {
            return [];
        }

        $map     = [];
        $details = AdvancedRejectionDetail::whereIn('advanced_block_id', $advancedBlockIds)
            ->with(['rejection' => fn ($q) => $q->where('tenant_id', $tenantId)->where('type', 'advanced')])
            ->get();

        foreach ($details as $detail) {
            if ($detail->rejection) {
                $map[$detail->advanced_block_id] = [
                    'rejection' => $detail->rejection,
                    'detail'    => $detail,
                ];
            }
        }

        return $map;
    }

    // ════════════════════════════════════════════════════════════════════════
    // RE-IMPORT HANDLERS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Handle re-import of an existing block rejection.
     */
    protected function handleBlockReImport(
        Rejection $rejection,
        array $row,
        string $newBucket,
        ?BlockRejectionDetail $detail = null
    ): void {
        $detail = $detail ?? $rejection->blockDetail;

        if (!$detail) {
            return;
        }

        $status     = strtoupper(trim($row['Block Acceptance Status'] ?? $row['block acceptance status'] ?? ''));
        $isRejected = $status === 'REJECTED';

        if ($isRejected) {
            $oldBucket       = $detail->bucket ?? '';
            $bucketWentBlank = !empty($oldBucket) && empty($newBucket);

            if ($bucketWentBlank) {
                // DISPUTED & WON
                $rejection->update([
                    'dispute_status'       => 'won',
                    'carrier_controllable' => false,
                    'penalty'              => 0,
                ]);

                $detail->update([
                    'bucket'               => '',
                    'rejection_reason'     => trim($row['Block Rejection Reason'] ?? $row['block rejection reason'] ?? ''),
                    'block_start_datetime' => $this->parseDateTime($row['Block start time'] ?? $row['block start time'] ?? ''),
                    'block_end_datetime'   => $this->parseDateTime($row['Block end time']   ?? $row['block end time']   ?? ''),
                    'rejection_datetime'   => $this->parseDateTime($row['Block rejection time'] ?? $row['block rejection time'] ?? ''),
                ]);

                return;
            }
        }

        // Normal re-import (rejected or accepted)
        $detail->update([
            'block_start_datetime' => $this->parseDateTime($row['Block start time'] ?? $row['block start time'] ?? ''),
            'block_end_datetime'   => $this->parseDateTime($row['Block end time']   ?? $row['block end time']   ?? ''),
            'rejection_datetime'   => $this->parseDateTime($row['Block rejection time'] ?? $row['block rejection time'] ?? ''),
            'rejection_reason'     => $isRejected
                ? trim($row['Block Rejection Reason'] ?? $row['block rejection reason'] ?? '')
                : null,
            'bucket'               => $isRejected ? $newBucket : null,
        ]);

        // If row is now ACCEPTED, reset penalty & controllable
        if (!$isRejected) {
            $rejection->update([
                'carrier_controllable' => false,
                'penalty'              => 0,
            ]);
        }
    }

    /**
     * Handle re-import of an existing load rejection.
     */
    protected function handleLoadReImport(
        Rejection $rejection,
        array $row,
        string $newBucket,
        ?LoadRejectionDetail $detail = null,
        ?string $newDriverName = null
    ): void {
        $detail = $detail ?? $rejection->loadDetail;

        if (!$detail) {
            return;
        }

        $status     = strtoupper(trim($row['Load Status'] ?? $row['load status'] ?? ''));
        $isRejected = $status !== 'ACCEPTED';

        // Prepare update data
        $updateData = [
            'origin_yard_arrival_datetime' => $this->parseDateTime($row['Origin Yard Arrival Time'] ?? $row['origin yard arrival time'] ?? ''),
            'rejection_reason'             => $isRejected
                ? trim($row['Rejection Reason'] ?? $row['rejection reason'] ?? '')
                : null,
            'rejection_bucket'             => $isRejected ? $newBucket : null,
        ];

        // Only update driver name if it's currently null and we have a new one
        if ($newDriverName && empty($detail->driver_name)) {
            $updateData['driver_name'] = $newDriverName;
        }

        if ($isRejected) {
            $oldBucket       = $detail->rejection_bucket ?? '';
            $bucketWentBlank = !empty($oldBucket) && empty($newBucket);

            if ($bucketWentBlank) {
                // DISPUTED & WON
                $rejection->update([
                    'dispute_status'       => 'won',
                    'carrier_controllable' => false,
                    'penalty'              => 0,
                ]);

                $updateData['rejection_bucket'] = '';
                $detail->update($updateData);

                return;
            }
        }

        // Normal re-import (rejected or accepted)
        $detail->update($updateData);

        // If row is now ACCEPTED, reset penalty & controllable
        if (!$isRejected) {
            $rejection->update([
                'carrier_controllable' => false,
                'penalty'              => 0,
            ]);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // CREATE METHODS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create a new block rejection record.
     */
    protected function createNewBlockRejection(
        array $row,
        int $tenantId,
        string $blockId,
        string $rejectionReason,
        string $bucket
    ): void {
        $status     = strtoupper(trim($row['Block Acceptance Status'] ?? $row['block acceptance status'] ?? ''));
        $isRejected = $status === 'REJECTED';

        $isCarrierControllable = $isRejected
            ? $this->dataService->isCarrierControllable($rejectionReason)
            : false;

        // Use data service for penalty calculation
        $penalty = $isRejected 
            ? $this->dataService->calculateBlockPenalty(
                $row['Block start time'] ?? $row['block start time'] ?? '',
                $row['Block rejection time'] ?? $row['block rejection time'] ?? '',
                false
              )
            : 0;

        $rejection = Rejection::create([
            'tenant_id'            => $tenantId,
            'type'                 => 'block',
            'penalty'              => $penalty,
            'carrier_controllable' => $isCarrierControllable,
            'driver_controllable'  => $isCarrierControllable,
            'dispute_status'       => 'none',
        ]);

        BlockRejectionDetail::create([
            'rejection_id'         => $rejection->id,
            'block_id'             => $blockId,
            'driver_name'          => null,
            'block_start_datetime' => $this->parseDateTime($row['Block start time'] ?? $row['block start time'] ?? ''),
            'block_end_datetime'   => $this->parseDateTime($row['Block end time']   ?? $row['block end time']   ?? ''),
            'rejection_datetime'   => $this->parseDateTime($row['Block rejection time'] ?? $row['block rejection time'] ?? ''),
            'rejection_reason'     => $isRejected ? ($rejectionReason ?: null) : null,
            'bucket'               => $isRejected ? ($bucket ?: null) : null,
        ]);
    }

    /**
     * Create a new load rejection record.
     */
    protected function createNewLoadRejection(
        array $row,
        int $tenantId,
        string $loadId,
        string $rejectionReason,
        string $bucket,
        ?string $driverName = null
    ): void {
        $status     = strtoupper(trim($row['Load Status'] ?? $row['load status'] ?? ''));
        $isRejected = $status !== 'ACCEPTED';

        $isCarrierControllable = $isRejected
            ? $this->dataService->isCarrierControllable($rejectionReason)
            : false;

        // Use data service for penalty calculation
        $penalty = $isRejected 
            ? $this->dataService->calculateLoadPenalty($bucket, false)
            : 0;

        $rejection = Rejection::create([
            'tenant_id'            => $tenantId,
            'type'                 => 'load',
            'penalty'              => $penalty,
            'carrier_controllable' => $isCarrierControllable,
            'driver_controllable'  => $isCarrierControllable,
            'dispute_status'       => 'none',
        ]);

        LoadRejectionDetail::create([
            'rejection_id'                 => $rejection->id,
            'load_id'                      => $loadId,
            'driver_name'                  => $driverName,
            'origin_yard_arrival_datetime' => $this->parseDateTime($row['Origin Yard Arrival Time'] ?? $row['origin yard arrival time'] ?? ''),
            'rejection_reason'             => $isRejected ? ($rejectionReason ?: null) : null,
            'rejection_bucket'             => $isRejected ? ($bucket ?: null) : null,
        ]);
    }

    /**
     * Create a new advanced rejection record.
     */
    protected function createNewAdvancedRejection(
        array $row,
        int $tenantId,
        string $advancedBlockId,
        string $reason
    ): void {
        $isCarrierControllable = $this->dataService->isCarrierControllable($reason);

        $rejection = Rejection::create([
            'tenant_id'            => $tenantId,
            'type'                 => 'advanced',
            'penalty'              => 0.85, // Fixed penalty
            'carrier_controllable' => $isCarrierControllable,
            'driver_controllable'  => $isCarrierControllable,
            'dispute_status'       => 'none',
        ]);

        AdvancedRejectionDetail::create([
            'rejection_id'      => $rejection->id,
            'advanced_block_id' => $advancedBlockId,
            'week_start_at'     => $this->parseDateTime($row['Week Start Date'] ?? $row['week start date'] ?? ''),
            'week_end_at'       => $this->parseDateTime($row['Week End Date']   ?? $row['week end date']   ?? ''),
            'impacted_blocks'   => (int) ($row['Impacted blocks'] ?? $row['impacted blocks'] ?? 0),
            'reason'            => $reason,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // UTILITY METHODS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Get unique ID from parsed data based on type
     */
    private function getUniqueId(string $type, array $data): ?string
    {
        return match($type) {
            'advanced' => $data['advanced_block_id'] ?? null,
            'block' => $data['block_id'] ?? null,
            'load' => $data['load_id'] ?? null,
            default => null,
        };
    }

    /**
     * Detect win condition from raw CSV data
     */
    private function detectWinCondition(string $type, $oldDetail, array $rawData): bool
    {
        switch ($type) {
            case 'load':
                $newBucket = trim($rawData['Rejection Bucket'] ?? $rawData['rejection bucket'] ?? '');
                return !empty($oldDetail->rejection_bucket) && empty($newBucket);

            case 'block':
                $newReason = trim($rawData['Block Rejection Reason'] ?? $rawData['block rejection reason'] ?? '');
                return !empty($oldDetail->rejection_reason) && empty($newReason);

            case 'advanced':
                $newReason = trim($rawData['Reason(s)'] ?? $rawData['reason(s)'] ?? $rawData['Reason'] ?? '');
                return !empty($oldDetail->reason) && empty($newReason);

            default:
                return false;
        }
    }

    /**
     * Resolve tenant ID from request or data
     */
    private function resolveTenantId(Request $request, array $data, bool $isSuperAdmin): ?int
    {
        $tenantId = $request->input('tenant_id');
        if ($tenantId) {
            return (int) $tenantId;
        }

        if (!$isSuperAdmin) {
            return Auth::user()->tenant_id;
        }

        $tenantName = $data['tenant_name'] ?? $data['Tenant Name'] ?? null;
        if ($tenantName) {
            $tenant = Tenant::where('name', $tenantName)->first();
            return $tenant?->id;
        }

        return null;
    }

    /**
     * Detect rejection type from CSV headers
     */
    protected function detectTypeFromHeaders(array $headers): string
    {
        $headers = array_map('strtolower', array_map('trim', $headers));

        if (in_array('advance block rejection id', $headers) || 
            in_array('expected blocks', $headers) ||
            in_array('tendered blocks', $headers)) {
            return 'advanced';
        }

        if (in_array('block id', $headers) || 
            in_array('block start time', $headers) ||
            in_array('block rejection time', $headers) ||
            in_array('block acceptance status', $headers)) {
            return 'block';
        }

        if (in_array('trip id', $headers) || 
            in_array('loads', $headers) ||
            in_array('load status', $headers)) {
            return 'load';
        }

        throw new \Exception('Unable to detect rejection type from CSV headers. Headers: ' . implode(', ', $headers));
    }

    /**
     * Parse row data based on type
     */
    protected function parseRowData(array $data, string $type, int $tenantId): array
    {
        $baseData = [
            'tenant_id' => $tenantId,
            'type' => $type,
            'dispute_status' => $this->parseDisputeStatus(
                $data['dispute_status'] ?? $data['Dispute Status'] ?? $data['Disputed'] ?? 'None'
            ),
        ];

        // Extract driver_name from data if it exists
        $driverName = null;
        if (isset($data['driver_name']) && !empty($data['driver_name'])) {
            $driverName = trim($data['driver_name']);
        } elseif (isset($data['Driver Name']) && !empty($data['Driver Name'])) {
            $driverName = trim($data['Driver Name']);
        }

        $parsedData = match($type) {
            'advanced' => array_merge($baseData, $this->parseAdvancedData($data)),
            'block' => array_merge($baseData, $this->parseBlockData($data)),
            'load' => array_merge($baseData, $this->parseLoadData($data)),
            default => throw new \Exception("Invalid type: {$type}"),
        };
        
        // Ensure driver_name is set in the parsed data
        if ($driverName && !isset($parsedData['driver_name'])) {
            $parsedData['driver_name'] = $driverName;
        }
        
        return $parsedData;
    }

    /**
     * Parse advanced rejection data
     */
    protected function parseAdvancedData(array $data): array
    {
        return [
            'advanced_block_id' => trim($data['Advance block rejection ID'] ?? $data['advance block rejection id'] ?? ''),
            'week_start_at' => $this->parseDateTime($data['Week Start Date'] ?? $data['week start date'] ?? null),
            'week_end_at' => $this->parseDateTime($data['Week End Date'] ?? $data['week end date'] ?? null),
            'impacted_blocks' => (int) ($data['Impacted blocks'] ?? $data['impacted blocks'] ?? 0),
            'reason' => trim($data['Reason(s)'] ?? $data['reason(s)'] ?? $data['Reason'] ?? ''),
        ];
    }

    /**
     * Parse block rejection data
     */
    protected function parseBlockData(array $data): array
    {
        $status = strtoupper(trim($data['Block Acceptance Status'] ?? $data['block acceptance status'] ?? 'REJECTED'));
        $isAccepted = $status === 'ACCEPTED';
        
        $rejectedAt = $this->parseDateTime($data['Block rejection time'] ?? $data['block rejection time'] ?? null);
        
        if (!$isAccepted && empty($rejectedAt)) {
            throw new \Exception('Rejected block missing rejected_at timestamp');
        }

        return [
            'block_id' => trim($data['Block ID'] ?? $data['block id'] ?? ''),
            'driver_name' => null,
            'block_start_at' => $this->parseDateTime($data['Block start time'] ?? $data['block start time'] ?? null),
            'block_end_at' => $this->parseDateTime($data['Block end time'] ?? $data['block end time'] ?? null),
            'rejected_at' => $rejectedAt,
            'rejection_reason' => $isAccepted ? null : trim($data['Block Rejection Reason'] ?? $data['block rejection reason'] ?? ''),
        ];
    }

    /**
     * Parse load rejection data
     */
    protected function parseLoadData(array $data): array
    {
        $status = strtoupper(trim($data['Load Status'] ?? $data['load status'] ?? 'REJECTED'));
        $isAccepted = $status === 'ACCEPTED';

        $rejectionBucket = trim($data['Rejection Bucket'] ?? $data['rejection bucket'] ?? '');
        
        $bucketMap = [
            'rejected after start time' => 'Rejected after start time',
            'rejected 0-6 hours before start' => 'Rejected 0-6 hours before start',
            'rejected 6+ hours before start' => 'Rejected 6+ hours before start',
            'less than 6 hours' => 'Rejected 0-6 hours before start',
            'more than 6 hours' => 'Rejected 6+ hours before start',
            'after start' => 'Rejected after start time',
        ];
        
        $normalizedBucket = $bucketMap[strtolower($rejectionBucket)] ?? $rejectionBucket;

        $loadId = trim($data['Loads'] ?? $data['loads'] ?? '');
        if (empty($loadId)) {
            $loadId = trim($data['Trip ID'] ?? $data['trip id'] ?? '');
        }

        // Extract driver name from data if available
        $driverName = null;
        
        if (isset($data['driver_name']) && !empty($data['driver_name'])) {
            $driverName = trim($data['driver_name']);
        } elseif (isset($data['Driver Name']) && !empty($data['Driver Name'])) {
            $driverName = trim($data['Driver Name']);
        } elseif (isset($data['driver name']) && !empty($data['driver name'])) {
            $driverName = trim($data['driver name']);
        }

        return [
            'load_id' => $loadId,
            'driver_name' => $driverName,
            'origin_yard_arrival_at' => $this->parseDateTime($data['Origin Yard Arrival Time'] ?? $data['origin yard arrival time'] ?? null),
            'rejection_reason' => $isAccepted ? null : trim($data['Rejection Reason'] ?? $data['rejection reason'] ?? ''),
            'rejection_bucket' => $isAccepted ? null : $normalizedBucket,
        ];
    }
    
    /**
     * Parse datetime from various formats
     */
    protected function parseDateTime(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);

        try {
            if (preg_match('/\b(CST|EST|PST|MST|CDT|EDT|PDT|MDT)\b/i', $value, $matches)) {
                $timezone = $matches[1];
                $dateString = trim(str_replace($timezone, '', $value));
                
                try {
                    $date = Carbon::createFromFormat('n/j/Y H:i', $dateString);
                    if ($date) {
                        return $date->format('Y-m-d H:i:s');
                    }
                } catch (\Exception $e) {
                    // Continue to next format
                }
            }

            $formats = [
                'n/j/Y H:i',
                'n/j/Y H:i:s',
                'm/d/Y H:i',
                'm/d/Y H:i:s',
                'Y-m-d H:i:s',
                'n/j/Y',
                'Y-m-d',
            ];

            foreach ($formats as $format) {
                try {
                    $date = Carbon::createFromFormat($format, $value);
                    if ($date) {
                        return $date->format('Y-m-d H:i:s');
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            return Carbon::parse($value)->format('Y-m-d H:i:s');

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse dispute status from string
     */
    protected function parseDisputeStatus(?string $value): string
    {
        if (empty($value)) {
            return 'none';
        }

        return match(strtolower(trim($value))) {
            'none', 'no' => 'none',
            'pending' => 'pending',
            'won', 'yes' => 'won',
            'lost' => 'lost',
            default => 'none',
        };
    }

    /**
     * Export rejections to CSV
     */
    public function exportRejections()
    {
        $user = Auth::user();
        $isSuperAdmin = is_null($user->tenant_id);

        $query = Rejection::with(['tenant', 'advancedDetail', 'blockDetail', 'loadDetail'])
            ->whereNotNull('type');

        if (!$isSuperAdmin) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $rejections = $query->get();

        if ($rejections->isEmpty()) {
            throw new \Exception('No rejection records to export.');
        }

        $grouped = $rejections->groupBy('type');

        $fileName = 'acceptances_export_' . now()->format('Y-m-d_His') . '.csv';
        $filePath = storage_path('app/temp/' . $fileName);

        if (!file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $file = fopen($filePath, 'w');

        foreach (['advanced', 'block', 'load'] as $type) {
            if (!isset($grouped[$type])) {
                continue;
            }

            fputcsv($file, [strtoupper($type) . ' REJECTIONS']);
            fputcsv($file, $this->getExportHeaders($type, $isSuperAdmin));

            foreach ($grouped[$type] as $rejection) {
                fputcsv($file, $this->formatExportRow($rejection, $type, $isSuperAdmin));
            }

            fputcsv($file, []);
        }

        fclose($file);

        return Response::download($filePath)->deleteFileAfterSend(true);
    }

    /**
     * Get export headers
     */
    protected function getExportHeaders(string $type, bool $isSuperAdmin): array
    {
        $baseHeaders = $isSuperAdmin ? ['Tenant Name'] : [];

        $typeHeaders = match($type) {
            'advanced' => [
                'Advanced Block ID',
                'Week Start',
                'Week End',
                'Impacted Blocks',
                'Reason',
            ],
            'block' => [
                'Block ID',
                'Driver Name',
                'Block Start',
                'Block End',
                'Rejected At',
                'Rejection Reason',
                'Bucket (Calculated)',
            ],
            'load' => [
                'Load ID',
                'Driver Name',
                'Origin Yard Arrival',
                'Rejection Reason',
                'Rejection Bucket',
            ],
            default => [],
        };

        $commonHeaders = [
            'Carrier Controllable',
            'Dispute Status',
            'Penalty',
        ];

        return array_merge($baseHeaders, $typeHeaders, $commonHeaders);
    }

    /**
     * Format export row
     */
    protected function formatExportRow(Rejection $rejection, string $type, bool $isSuperAdmin): array
    {
        $baseData = $isSuperAdmin ? [$rejection->tenant->name ?? '—'] : [];

        $detail = $rejection->detail;

        $typeData = match($type) {
            'advanced' => [
                $detail->advanced_block_id ?? '—',
                $detail->week_start_at ? $detail->week_start_at->format('Y-m-d H:i:s') : '—',
                $detail->week_end_at ? $detail->week_end_at->format('Y-m-d H:i:s') : '—',
                $detail->impacted_blocks ?? '—',
                $detail->reason ?? '—',
            ],
            'block' => [
                $detail->block_id ?? '—',
                $detail->driver_name ?? '—',
                $detail->block_start_at ? $detail->block_start_at->format('Y-m-d H:i:s') : '—',
                $detail->block_end_at ? $detail->block_end_at->format('Y-m-d H:i:s') : '—',
                $detail->rejected_at ? $detail->rejected_at->format('Y-m-d H:i:s') : '—',
                $detail->rejection_reason ?? '—',
                $detail->bucket ?? '—',
            ],
            'load' => [
                $detail->load_id ?? '—',
                $detail->driver_name ?? '—',
                $detail->origin_yard_arrival_at ? $detail->origin_yard_arrival_at->format('Y-m-d H:i:s') : '—',
                $detail->rejection_reason ?? '—',
                $detail->rejection_bucket ?? '—',
            ],
            default => [],
        };

        $commonData = [
            $rejection->carrier_controllable ? 'Yes' : 'No',
            ucfirst($rejection->dispute_status),
            $rejection->penalty,
        ];

        return array_merge($baseData, $typeData, $commonData);
    }
}