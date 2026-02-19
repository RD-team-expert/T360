<?php

namespace App\Http\Requests\Acceptance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Models\Rejection;
use Carbon\Carbon;

class UpdateRejectionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        // Get the rejection ID from the route
        $rejectionId = $this->route('rejection');
        $rejection = Rejection::with(['blockDetail', 'loadDetail', 'advancedDetail'])->find($rejectionId);
        
        if (!$rejection) {
            return [];
        }
        
        // Check if this is a new structure rejection (has type field)
        $isNewStructure = !is_null($rejection->type);
        
        if ($isNewStructure) {
            return $this->getNewStructureRules($rejection);
        } else {
            return $this->getOldStructureRules();
        }
    }

    /**
     * Rules for NEW acceptance structure.
     */
    protected function getNewStructureRules($rejection)
    {
        $type = $rejection->type;
        
        // Base rules for all types
        $rules = [
            'tenant_id' => 'sometimes|exists:tenants,id',
            'carrier_controllable' => 'sometimes|boolean',
            'driver_controllable' => 'sometimes|nullable|boolean',
            'dispute_status' => 'sometimes|nullable|in:none,pending,won,lost',
        ];
        
        // Type-specific rules with flexible date validation
        switch ($type) {
            case 'advanced':
                $rules = array_merge($rules, [
                    'advanced_block_id' => 'sometimes|string|unique:advanced_rejection_details,advanced_block_id,' . $rejection->advancedDetail?->id,
                    'driver_name' => 'sometimes|nullable|string',  // ← ADDED: driver_name for advanced rejections
                    'week_start_at' => [
                        'sometimes',
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (empty($value)) return;
                            if (!$this->validateFlexibleDate($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD or MM/DD/YYYY format.');
                            }
                        },
                    ],
                    'week_end_at' => [
                        'sometimes',
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (empty($value)) return;
                            if (!$this->validateFlexibleDate($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD or MM/DD/YYYY format.');
                            }
                        },
                    ],
                    'impacted_blocks' => 'sometimes|integer|min:0',
                    'reason' => 'sometimes|string',
                ]);
                break;
                
            case 'block':
                $rules = array_merge($rules, [
                    'block_id' => 'sometimes|string|unique:block_rejection_details,block_id,' . $rejection->blockDetail?->id,
                    'driver_name' => 'sometimes|nullable|string',
                    'block_start_at' => [
                        'sometimes',
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (empty($value)) return;
                            if (!$this->validateFlexibleDateTime($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD HH:MM:SS or MM/DD/YYYY HH:MM AM/PM format.');
                            }
                        },
                    ],
                    'block_end_at' => [
                        'sometimes',
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (empty($value)) return;
                            if (!$this->validateFlexibleDateTime($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD HH:MM:SS or MM/DD/YYYY HH:MM AM/PM format.');
                            }
                        },
                    ],
                    'rejected_at' => [
                        'sometimes',
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (empty($value)) return;
                            if (!$this->validateFlexibleDateTime($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD HH:MM:SS or MM/DD/YYYY HH:MM AM/PM format.');
                            }
                        },
                    ],
                    'rejection_reason' => 'sometimes|nullable|string',
                ]);
                break;
                
            case 'load':
                $rules = array_merge($rules, [
                    'load_id' => 'sometimes|string|unique:load_rejection_details,load_id,' . $rejection->loadDetail?->id,
                    'driver_name' => 'sometimes|nullable|string',
                    'origin_yard_arrival_at' => [
                        'sometimes',
                        'nullable',
                        function ($attribute, $value, $fail) {
                            if (empty($value)) return;
                            if (!$this->validateFlexibleDate($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD or MM/DD/YYYY format.');
                            }
                        },
                    ],
                    'rejection_reason' => 'sometimes|nullable|string',
                    'rejection_bucket' => 'sometimes|nullable|string',
                ]);
                break;
        }
        
        return $rules;
    }

    /**
     * Rules for OLD rejection structure.
     */
    protected function getOldStructureRules()
    {
        return [
            'date' => [
                'sometimes',
                function ($attribute, $value, $fail) {
                    if (empty($value)) return;
                    if (!$this->validateFlexibleDate($value)) {
                        $fail('The date format is invalid. Please use YYYY-MM-DD or MM/DD/YYYY format.');
                    }
                },
            ],
            'driver_name' => 'sometimes|string',
            'rejection_type' => 'sometimes|in:block,load',
            'rejection_category' => [
                'sometimes',
                function ($attribute, $value, $fail) {
                    $type = $this->input('rejection_type');
                    if (!$type) {
                        return;
                    }
                    
                    // Valid categories for block type
                    $blockCategories = ['after_start', 'within_24', 'more_than_24', 'advanced_rejection'];
                    // Valid categories for load type
                    $loadCategories = ['after_start', 'within_6', 'more_than_6'];
                    
                    if ($type === 'block' && !in_array($value, $blockCategories)) {
                        $fail('The rejection category is invalid for block type rejections.');
                    }
                    
                    if ($type === 'load' && !in_array($value, $loadCategories)) {
                        $fail('The rejection category is invalid for load type rejections.');
                    }
                },
            ],
            'reason_code_id' => [
                'sometimes',
                Rule::exists('rejection_reason_codes', 'id')->whereNull('deleted_at'),
            ],
            'disputed' => 'sometimes|boolean',
            'driver_controllable' => 'nullable|boolean',
            'tenant_id' => 'sometimes|exists:tenants,id',
        ];
    }
    
    /**
     * Validate flexible date formats (YYYY-MM-DD or MM/DD/YYYY)
     */
    protected function validateFlexibleDate($value): bool
    {
        if (empty($value)) {
            return true;
        }

        try {
            // Check if it's in MM/DD/YYYY format
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
                $date = Carbon::createFromFormat('m/d/Y', $value);
                return $date !== false;
            }
            
            // Check if it's in YYYY-MM-DD format
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $date = Carbon::createFromFormat('Y-m-d', $value);
                return $date !== false;
            }
            
            // If it contains time, extract just the date part
            if (strpos($value, ' ') !== false) {
                $parts = explode(' ', $value);
                return $this->validateFlexibleDate($parts[0]);
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate flexible datetime formats
     */
    protected function validateFlexibleDateTime($value): bool
    {
        if (empty($value)) {
            return true;
        }

        try {
            // Check if it's in MM/DD/YYYY HH:MM AM/PM format
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}\s+\d{1,2}:\d{2}\s*(AM|PM)$/i', $value)) {
                $date = Carbon::createFromFormat('m/d/Y h:i A', $value);
                return $date !== false;
            }
            
            // Check if it's in YYYY-MM-DD HH:MM:SS format
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $value)) {
                $date = Carbon::createFromFormat('Y-m-d H:i:s', $value);
                return $date !== false;
            }
            
            // Check if it's in YYYY-MM-DD HH:MM format (without seconds)
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $value)) {
                $date = Carbon::createFromFormat('Y-m-d H:i', $value);
                return $date !== false;
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    protected function prepareForValidation()
    {
        // Only auto-set tenant_id if not a super admin
        if (!is_null(Auth::user()->tenant_id) && !$this->has('tenant_id')) { 
            $this->merge(['tenant_id' => Auth::user()->tenant_id]); 
        }
    }
    
    public function messages()
    {
        return [
            'rejection_category.required' => 'The rejection category field is required.',
            'type.required' => 'The rejection type field is required.',
            'type.in' => 'The rejection type must be advanced, block, or load.',
            'advanced_block_id.unique' => 'This advanced block ID already exists.',
            'block_id.unique' => 'This block ID already exists.',
            'load_id.unique' => 'This load ID already exists.',
        ];
    }
}