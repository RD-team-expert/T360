<template>
  <form @submit.prevent="submit" class="space-y-6">
    <!-- Tenant dropdown for SuperAdmin users -->
    <div v-if="isSuperAdmin" class="space-y-2">
      <Label>Company Name</Label>
      <div class="relative">
        <select
          v-model="form.tenant_id"
          class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 appearance-none"
          required
        >
          <option :value="null" disabled>Select Company</option>
          <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
            {{ tenant.name }}
          </option>
        </select>
        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
          <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
          </svg>
        </div>
      </div>
    </div>

    <!-- Type Selection -->
    <div class="space-y-2" v-if="!form.id || form.rejection_type">
      <Label>Type</Label>
      <div class="relative">
        <select
          v-model="form.type"
          class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 appearance-none"
        >
          <option value="block">Block</option>
          <option value="load">Load</option>
          <option value="advanced">Advanced</option>
        </select>
        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
          <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
          </svg>
        </div>
      </div>
    </div>

    <!-- Date Field -->
    <div class="space-y-2">
      <Label>Date</Label>
      <Input type="date" v-model="form.date" class="w-full" />
    </div>

    <!-- Driver Name -->
    <div class="space-y-2">
      <Label>Driver Name</Label>
      <Input v-model="form.driver_name" class="w-full" placeholder="Enter driver name" />
    </div>

    <!-- ========== BLOCK FIELDS ========== -->
    <div v-if="form.type === 'block'" class="space-y-4 border rounded-lg p-4 bg-muted/5">
      <h3 class="font-medium text-sm">Block Details</h3>
      
      <div class="space-y-2">
        <Label>Block ID</Label>
        <Input v-model="form.block_id" class="w-full" placeholder="Enter block ID" />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="space-y-2">
          <Label>Block Start</Label>
          <Input type="datetime-local" v-model="form.block_start_at" class="w-full" />
        </div>

        <div class="space-y-2">
          <Label>Block End</Label>
          <Input type="datetime-local" v-model="form.block_end_at" class="w-full" />
        </div>
      </div>

      <div class="space-y-2">
        <Label>Rejected At</Label>
        <Input type="datetime-local" v-model="form.rejected_at" class="w-full" />
      </div>

      <div class="space-y-2">
        <Label>Rejection Reason</Label>
        <Input v-model="form.rejection_reason" class="w-full" placeholder="Enter rejection reason" />
      </div>
    </div>

    <!-- ========== LOAD FIELDS ========== -->
    <div v-else-if="form.type === 'load'" class="space-y-4 border rounded-lg p-4 bg-muted/5">
      <h3 class="font-medium text-sm">Load Details</h3>
      
      <div class="space-y-2">
        <Label>Load ID</Label>
        <Input v-model="form.load_id" class="w-full" placeholder="Enter load ID" />
      </div>

      <div class="space-y-2">
        <Label>Origin Yard Arrival</Label>
        <Input type="datetime-local" v-model="form.origin_yard_arrival_at" class="w-full" />
      </div>

      <div class="space-y-2">
        <Label>Rejection Reason</Label>
        <Input v-model="form.load_rejection_reason" class="w-full" placeholder="Enter rejection reason" />
      </div>

      <div class="space-y-2">
        <Label>Rejection Bucket</Label>
        <Input v-model="form.rejection_bucket" class="w-full" placeholder="Enter rejection bucket" />
      </div>
    </div>

    <!-- ========== ADVANCED FIELDS ========== -->
    <div v-else-if="form.type === 'advanced'" class="space-y-4 border rounded-lg p-4 bg-muted/5">
      <h3 class="font-medium text-sm">Advanced Details</h3>
      
      <div class="space-y-2">
        <Label>Advanced Block ID</Label>
        <Input v-model="form.advanced_block_id" class="w-full" placeholder="Enter advanced block ID" />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="space-y-2">
          <Label>Week Start</Label>
          <Input type="date" v-model="form.week_start_at" class="w-full" />
        </div>

        <div class="space-y-2">
          <Label>Week End</Label>
          <Input type="date" v-model="form.week_end_at" class="w-full" />
        </div>
      </div>

      <div class="space-y-2">
        <Label>Impacted Blocks</Label>
        <Input type="number" min="1" v-model.number="form.impacted_blocks" class="w-full" />
      </div>

      <div class="space-y-2">
        <Label>Reason</Label>
        <Input v-model="form.reason" class="w-full" placeholder="Enter reason" />
      </div>
    </div>

    <!-- ========== OLD STRUCTURE FIELDS (only shown when editing old records) ========== -->
    <div v-if="form.rejection_type" class="space-y-4 border rounded-lg p-4 bg-muted/5">
      <h3 class="font-medium text-sm">Legacy Fields</h3>
      
      <!-- Rejection Category -->
      <div class="space-y-2">
        <Label>Rejection Category</Label>
        <div class="relative">
          <select
            v-model="form.rejection_category"
            class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 appearance-none"
          >
            <option value="">Select Category</option>
            <option
              v-for="category in availableCategories"
              :key="category.value"
              :value="category.value"
            >
              {{ category.label }}
            </option>
          </select>
          <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2">
            <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Reason Code -->
      <div class="space-y-2">
        <Label>Reason Code</Label>
        <div class="relative">
          <select
            v-model="form.reason_code_id"
            class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 appearance-none"
          >
            <option :value="null">Select Reason Code</option>
            <option v-for="reason in reasons" :key="reason.id" :value="reason.id">
              {{ reason.reason_code }}
            </option>
          </select>
          <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2">
            <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </div>
        </div>
      </div>

      <!-- 🔥 FIXED: Replaced checkbox with dropdown for dispute_status in old structure -->
      <div class="space-y-2">
        <Label>Dispute Status</Label>
        <div class="relative">
          <select
            v-model="form.dispute_status"
            class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 appearance-none"
          >
            <option value="none">None</option>
            <option value="pending">Pending</option>
            <option value="won">Won</option>
            <option value="lost">Lost</option>
          </select>
          <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2">
            <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </div>
        </div>
        <p class="text-xs text-muted-foreground">Affects score calculations</p>
      </div>
    </div>

    <!-- ========== STATUS FIELDS (Dispute + Carrier + Driver) ========== -->
    <div class="border-t pt-4">
      <h3 class="font-medium text-sm mb-4">Status & Control</h3>
      
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Dispute Status - For new structure (already has dropdown) -->
        <div v-if="!form.rejection_type" class="space-y-2">
          <Label>Dispute Status</Label>
          <div class="relative">
            <select
              v-model="form.dispute_status"
              class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 appearance-none"
            >
              <option value="none">None</option>
              <option value="pending">Pending</option>
              <option value="won">Won</option>
              <option value="lost">Lost</option>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2">
              <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </div>
          </div>
          <p class="text-xs text-muted-foreground">Affects score calculations</p>
        </div>

        <!-- Carrier Controllable -->
        <div class="space-y-2" :class="{ 'md:col-span-2': form.rejection_type, 'md:col-span-1': !form.rejection_type }">
          <Label>Carrier Controllable</Label>
          <div class="relative">
            <select
              v-model="form.carrier_controllable"
              class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 appearance-none"
            >
              <option :value="null">N/A</option>
              <option :value="true">Yes</option>
              <option :value="false">No</option>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2">
              <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </div>
          </div>
          <p class="text-xs text-muted-foreground">Affects company score when Yes</p>
        </div>

        <!-- Driver Controllable -->
        <div class="space-y-2">
          <Label>Driver Controllable</Label>
          <div class="relative">
            <select
              v-model="form.driver_controllable"
              class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 appearance-none"
            >
              <option :value="null">N/A</option>
              <option :value="true">Yes</option>
              <option :value="false">No</option>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2">
              <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </div>
          </div>
          <p class="text-xs text-muted-foreground">Affects driver score when Yes</p>
        </div>
      </div>
    </div>

    <!-- Form Actions -->
    <div class="flex justify-end space-x-2 pt-4 border-t">
      <Button
        type="button"
        @click="emit('close')"
        variant="outline"
      >
        Cancel
      </Button>
      <Button type="submit" :disabled="form.processing">
        {{ form.id ? "Update" : "Create" }}
      </Button>
    </div>
  </form>
</template>

<script setup lang="ts">
import { ref, watch, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import Input from "@/components/ui/input/Input.vue";
import Button from "@/components/ui/button/Button.vue";
import Checkbox from "@/components/ui/checkbox/Checkbox.vue";
import Label from "@/components/ui/label/Label.vue";

const props = defineProps({
  rejection: { type: Object, default: null },
  reasons: { type: Array, default: () => [] },
  tenants: { type: Array, default: () => [] },
  isSuperAdmin: { type: Boolean, default: false },
  tenantSlug: { type: String, default: null },
});

const emit = defineEmits(["close"]);

// Determine if this is an old structure rejection
const isOldStructure = computed(() => {
  return props.rejection?.rejection_type || props.rejection?.rejection_category;
});

const form = useForm({
  id: props.rejection?.id ?? null,
  tenant_id: props.isSuperAdmin ? (props.rejection?.tenant_id ?? null) : null,
  
  // this is just a helper display date you already use on the index
  date: props.rejection?.date || "",
  
  // NEW STRUCTURE MAIN TYPE
  type: props.rejection?.type || "block", // 'advanced' | 'block' | 'load'
  
  // OLD STRUCTURE FIELDS
  rejection_type: props.rejection?.rejection_type || "",
  rejection_category: props.rejection?.rejection_category || "",
  reason_code_id: props.rejection?.reason_code_id || null,
  disputed: props.rejection?.disputed || false, // Keep for backward compatibility
  
  // Advanced detail fields
  advanced_block_id: props.rejection?.advanced_block_id || "",
  week_start_at: props.rejection?.week_start_at || "",
  week_end_at: props.rejection?.week_end_at || "",
  impacted_blocks: props.rejection?.impacted_blocks || 1,
  reason: props.rejection?.raw_reason || "",
  
  // Block detail fields
  block_id: props.rejection?.block_id || "",
  driver_name: props.rejection?.driver_name || "",
  block_start_at: props.rejection?.block_start_at || "",
  block_end_at: props.rejection?.block_end_at || "",
  rejected_at: props.rejection?.rejected_at || "",
  rejection_reason: props.rejection?.raw_reason || "",
  
  // Load detail fields
  load_id: props.rejection?.load_id || "",
  origin_yard_arrival_at: props.rejection?.origin_yard_arrival_at || "",
  load_rejection_reason: props.rejection?.raw_reason || "",
  rejection_bucket: props.rejection?.rejection_bucket || "",
  
  // New-structure flags
  dispute_status: props.rejection?.dispute_status || "none", // 'none' | 'pending' | 'won' | 'lost'
  carrier_controllable:
    props.rejection?.carrier_controllable === null
      ? null
      : Boolean(props.rejection?.carrier_controllable),
  driver_controllable:
    props.rejection?.driver_controllable === null
      ? null
      : Boolean(props.rejection?.driver_controllable),
});

// Computed property for available categories based on rejection type
const availableCategories = computed(() => {
  if (form.rejection_type === "block") {
    return [
      { value: "advanced_rejection", label: "Advanced Rejection" },
      { value: "more_than_24", label: "More than 24 hrs" },
      { value: "within_24", label: "Within 24 hrs" },
      { value: "after_start", label: "After start" },
    ];
  } else if (form.rejection_type === "load") {
    return [
      { value: "more_than_6", label: "More than 6 hrs" },
      { value: "within_6", label: "Within 6 hrs" },
      { value: "after_start", label: "After start" },
    ];
  }
  return [];
});

// Watch for changes in the rejection prop and update the form accordingly.
watch(
  () => props.rejection,
  (newVal) => {
    if (!newVal) {
      form.reset();
      form.type = "block";
      form.impacted_blocks = 1;
      form.dispute_status = "none";
      form.disputed = false;
      return;
    }
    
    form.id = newVal.id;
    form.tenant_id = newVal.tenant_id;
    form.date = newVal.date || "";
    form.type = newVal.type || "block";
    form.rejection_type = newVal.rejection_type || "";
    form.rejection_category = newVal.rejection_category || "";
    form.reason_code_id = newVal.reason_code_id || null;
    form.disputed = newVal.disputed || false;
    
    // Advanced
    form.advanced_block_id = newVal.advanced_block_id || "";
    form.week_start_at = newVal.week_start_at || "";
    form.week_end_at = newVal.week_end_at || "";
    form.impacted_blocks = newVal.impacted_blocks || 1;
    form.reason = newVal.raw_reason || "";
    
    // Block
    form.block_id = newVal.block_id || "";
    form.driver_name = newVal.driver_name || "";
    form.block_start_at = newVal.block_start_at || "";
    form.block_end_at = newVal.block_end_at || "";
    form.rejected_at = newVal.rejected_at || "";
    form.rejection_reason = newVal.raw_reason || "";
    
    // Load
    form.load_id = newVal.load_id || "";
    form.origin_yard_arrival_at = newVal.origin_yard_arrival_at || "";
    form.load_rejection_reason = newVal.raw_reason || "";
    form.rejection_bucket = newVal.rejection_bucket || "";
    
    // Flags
    form.dispute_status = newVal.dispute_status || "none";
    form.carrier_controllable =
      newVal.carrier_controllable === null
        ? null
        : Boolean(newVal.carrier_controllable);
    form.driver_controllable =
      newVal.driver_controllable === null
        ? null
        : Boolean(newVal.driver_controllable);
  },
  { immediate: true, deep: true }
);

// Helper function to convert date from display format to database format
const convertDateToDatabaseFormat = (dateStr: string): string => {
  if (!dateStr) return dateStr;
  
  // Check if it's in MM/DD/YYYY HH:MM AM/PM format (from screenshot)
  const dateTimeMatch = dateStr.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})\s*(AM|PM)/i);
  if (dateTimeMatch) {
    const [_, month, day, year, hour, minute, ampm] = dateTimeMatch;
    let hour24 = parseInt(hour);
    if (ampm.toUpperCase() === 'PM' && hour24 < 12) hour24 += 12;
    if (ampm.toUpperCase() === 'AM' && hour24 === 12) hour24 = 0;
    return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')} ${hour24.toString().padStart(2, '0')}:${minute}:00`;
  }
  
  // Check if it's in MM/DD/YYYY format (just date)
  const dateMatch = dateStr.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
  if (dateMatch) {
    const [_, month, day, year] = dateMatch;
    return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
  }
  
  // If it's already in ISO format (from datetime-local input), convert to proper format
  if (dateStr.includes('T')) {
    const [datePart, timePart] = dateStr.split('T');
    if (timePart) {
      return `${datePart} ${timePart}:00`;
    }
    return datePart;
  }
  
  return dateStr;
};

const submit = () => {
  // Create a clean copy of the form data
  const submitData = { ...form.data() };
  
  // Convert all date fields to database format
  if (submitData.type === 'load' && submitData.origin_yard_arrival_at) {
    submitData.origin_yard_arrival_at = convertDateToDatabaseFormat(submitData.origin_yard_arrival_at);
  }
  
  if (submitData.type === 'advanced') {
    if (submitData.week_start_at) {
      submitData.week_start_at = convertDateToDatabaseFormat(submitData.week_start_at);
    }
    if (submitData.week_end_at) {
      submitData.week_end_at = convertDateToDatabaseFormat(submitData.week_end_at);
    }
  }
  
  if (submitData.type === 'block') {
    if (submitData.block_start_at) {
      submitData.block_start_at = convertDateToDatabaseFormat(submitData.block_start_at);
    }
    if (submitData.block_end_at) {
      submitData.block_end_at = convertDateToDatabaseFormat(submitData.block_end_at);
    }
    if (submitData.rejected_at) {
      submitData.rejected_at = convertDateToDatabaseFormat(submitData.rejected_at);
    }
  }
  
  // Also check the main date field
  if (submitData.date) {
    submitData.date = convertDateToDatabaseFormat(submitData.date);
  }
  
  // If this is a new structure rejection (has type but no rejection_type)
  if (form.type && !form.rejection_type) {
    // Remove old structure fields that aren't needed
    delete submitData.rejection_type;
    delete submitData.rejection_category;
    delete submitData.reason_code_id;
    delete submitData.disputed; // Remove the old disputed field
  }
  
  // Clean up based on type to avoid sending unnecessary fields
  if (submitData.type === 'advanced') {
    // Remove block and load specific fields
    delete submitData.block_id;
    delete submitData.block_start_at;
    delete submitData.block_end_at;
    delete submitData.rejected_at;
    delete submitData.rejection_reason;
    delete submitData.load_id;
    delete submitData.origin_yard_arrival_at;
    delete submitData.load_rejection_reason;
    delete submitData.rejection_bucket;
  } else if (submitData.type === 'block') {
    // Remove advanced and load specific fields
    delete submitData.advanced_block_id;
    delete submitData.week_start_at;
    delete submitData.week_end_at;
    delete submitData.impacted_blocks;
    delete submitData.reason;
    delete submitData.load_id;
    delete submitData.origin_yard_arrival_at;
    delete submitData.load_rejection_reason;
    delete submitData.rejection_bucket;
  } else if (submitData.type === 'load') {
    // Remove advanced and block specific fields
    delete submitData.advanced_block_id;
    delete submitData.week_start_at;
    delete submitData.week_end_at;
    delete submitData.impacted_blocks;
    delete submitData.reason;
    delete submitData.block_id;
    delete submitData.block_start_at;
    delete submitData.block_end_at;
    delete submitData.rejected_at;
    delete submitData.rejection_reason;
  }
  
  const isEdit = !!form.id;
  const routeName = props.isSuperAdmin
    ? isEdit
      ? "acceptance.update.admin"
      : "acceptance.store.admin"
    : isEdit
      ? "acceptance.update"
      : "acceptance.store";
      
  const routeParams = props.isSuperAdmin
    ? isEdit
      ? { rejection: form.id }
      : {}
    : isEdit
      ? { tenantSlug: props.tenantSlug, rejection: form.id }
      : { tenantSlug: props.tenantSlug };
      
  const method = isEdit ? "put" : "post";
  
  console.log('Submitting data (converted):', submitData);
  
  form.transform(() => submitData)[method](route(routeName, routeParams), {
    onSuccess: () => emit("close"),
    onError: (errors) => {
      console.error('Form submission errors:', errors);
    },
  });
};
</script>