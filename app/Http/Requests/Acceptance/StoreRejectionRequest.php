<?php

namespace App\Http\Requests\Acceptance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class StoreRejectionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        // Always use new structure rules since we've unified the form
        return $this->getNewStructureRules();
    }

    /**
     * Rules for unified acceptance structure.
     */
    protected function getNewStructureRules()
    {
        $type = $this->input('type');
        
        // Base rules for all types
        $rules = [
            'type' => 'required|in:advanced,block,load',
            'tenant_id' => 'required|exists:tenants,id',
            'carrier_controllable' => 'nullable|boolean',
            'driver_controllable' => 'nullable|boolean',
            'dispute_status' => 'required|in:none,pending,won,lost', // Made required
            'date' => 'nullable|date', // Optional display date
            'driver_name' => 'nullable|string|max:255',
        ];
        
        // Type-specific rules
        switch ($type) {
            case 'advanced':
                $rules = array_merge($rules, [
                    'advanced_block_id' => [
                        'required',
                        'string',
                        // Only check uniqueness if it's a new record (no id)
                        $this->route('rejection') 
                            ? Rule::unique('advanced_rejection_details', 'advanced_block_id')->ignore($this->route('rejection'), 'rejection_id')
                            : 'unique:advanced_rejection_details,advanced_block_id'
                    ],
                    'week_start_at' => [
                        'required',
                        function ($attribute, $value, $fail) {
                            if (!$this->validateFlexibleDate($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD or MM/DD/YYYY format.');
                            }
                        },
                    ],
                    'week_end_at' => [
                        'required',
                        function ($attribute, $value, $fail) {
                            if (!$this->validateFlexibleDate($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD or MM/DD/YYYY format.');
                            }
                        },
                    ],
                    'impacted_blocks' => 'required|integer|min:1',
                    'reason' => 'required|string',
                ]);
                break;
                
            case 'block':
                $rules = array_merge($rules, [
                    'block_id' => [
                        'required',
                        'string',
                        $this->route('rejection')
                            ? Rule::unique('block_rejection_details', 'block_id')->ignore($this->route('rejection'), 'rejection_id')
                            : 'unique:block_rejection_details,block_id'
                    ],
                    'block_start_at' => [
                        'required',
                        function ($attribute, $value, $fail) {
                            if (!$this->validateFlexibleDateTime($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD HH:MM:SS or MM/DD/YYYY HH:MM AM/PM format.');
                            }
                        },
                    ],
                    'block_end_at' => [
                        'required',
                        function ($attribute, $value, $fail) {
                            if (!$this->validateFlexibleDateTime($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD HH:MM:SS or MM/DD/YYYY HH:MM AM/PM format.');
                            }
                        },
                    ],
                    'rejected_at' => [
                        'required',
                        function ($attribute, $value, $fail) {
                            if (!$this->validateFlexibleDateTime($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD HH:MM:SS or MM/DD/YYYY HH:MM AM/PM format.');
                            }
                        },
                    ],
                    'rejection_reason' => 'nullable|string',
                ]);
                break;
                
            case 'load':
                $rules = array_merge($rules, [
                    'load_id' => [
                        'required',
                        'string',
                        $this->route('rejection')
                            ? Rule::unique('load_rejection_details', 'load_id')->ignore($this->route('rejection'), 'rejection_id')
                            : 'unique:load_rejection_details,load_id'
                    ],
                    'origin_yard_arrival_at' => [
                        'required',
                        function ($attribute, $value, $fail) {
                            if (!$this->validateFlexibleDateTime($value)) {
                                $fail('The ' . $attribute . ' format is invalid. Please use YYYY-MM-DD HH:MM:SS or MM/DD/YYYY HH:MM AM/PM format.');
                            }
                        },
                    ],
                    'load_rejection_reason' => 'nullable|string',
                    'rejection_bucket' => 'nullable|string',
                ]);
                break;
        }
        
        return $rules;
    }

    /**
     * Validate flexible date formats (YYYY-MM-DD or MM/DD/YYYY)
     */
    protected function validateFlexibleDate($value): bool
    {
        if (empty($value)) {
            return false;
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
            return false;
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
        // Set tenant_id from authenticated user if not provided
        if (!is_null(Auth::user()->tenant_id) && !$this->has('tenant_id')) {
            $this->merge(['tenant_id' => Auth::user()->tenant_id]);
        }
        
        // Set default values for new structure fields
        if ($this->has('type')) {
            // Ensure dispute_status has a default
            if (!$this->has('dispute_status') || empty($this->input('dispute_status'))) {
                $this->merge(['dispute_status' => 'none']);
            }
            
            // Ensure carrier_controllable defaults to null if not set
            if (!$this->has('carrier_controllable')) {
                $this->merge(['carrier_controllable' => null]);
            }
            
            // Ensure driver_controllable defaults to null if not set
            if (!$this->has('driver_controllable')) {
                $this->merge(['driver_controllable' => null]);
            }
        }
    }
    
    public function messages()
    {
        return [
            'type.required' => 'The rejection type field is required.',
            'type.in' => 'The rejection type must be advanced, block, or load.',
            'dispute_status.required' => 'The dispute status field is required.',
            'dispute_status.in' => 'The dispute status must be none, pending, won, or lost.',
            
            // Advanced messages
            'advanced_block_id.required' => 'The advanced block ID is required.',
            'advanced_block_id.unique' => 'This advanced block ID already exists.',
            'week_start_at.required' => 'The week start date is required.',
            'week_end_at.required' => 'The week end date is required.',
            'impacted_blocks.required' => 'The number of impacted blocks is required.',
            'impacted_blocks.min' => 'The number of impacted blocks must be at least 1.',
            'reason.required' => 'The reason field is required.',
            
            // Block messages
            'block_id.required' => 'The block ID is required.',
            'block_id.unique' => 'This block ID already exists.',
            'block_start_at.required' => 'The block start time is required.',
            'block_end_at.required' => 'The block end time is required.',
            'rejected_at.required' => 'The rejected at time is required.',
            
            // Load messages
            'load_id.required' => 'The load ID is required.',
            'load_id.unique' => 'This load ID already exists.',
            'origin_yard_arrival_at.required' => 'The origin yard arrival time is required.',
        ];
    }
}