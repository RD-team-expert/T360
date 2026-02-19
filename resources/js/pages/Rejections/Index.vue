<template>
  <AppLayout
    :breadcrumbs="breadcrumbs"
    :tenantSlug="tenantSlug"
    :permissions="props.permissions"
  >
    <Head title="Acceptance" />

    <!-- responsive here -->
    <div
      class="w-full md:max-w-2xl lg:max-w-3xl xl:max-w-6xl lg:mx-auto m-0 p-2 md:p-4 lg:p-6 space-y-2 md:space-y-4 lg:space-y-6"
    >
      <!-- Success Message -->
      <Alert v-if="successMessage" variant="success">
        <AlertTitle>Success</AlertTitle>
        <AlertDescription>{{ successMessage }}</AlertDescription>
      </Alert>

      <!-- Error Message -->
      <Alert v-if="errorMessage" variant="destructive">
        <AlertTitle>Error</AlertTitle>
        <AlertDescription>{{ errorMessage }}</AlertDescription>
      </Alert>

      <!-- Actions Section -->
      <div
        class="mb-2 flex flex-col items-center justify-between px-2 sm:flex-row md:mb-4 lg:mb-6"
      >
        <h1
          class="text-lg font-bold text-gray-800 dark:text-gray-200 md:text-xl lg:text-2xl"
        >
          Acceptance
        </h1>

        <div class="flex flex-wrap gap-3 ml-3">
          <Button
            v-if="permissionNames.includes('acceptance.create')"
            class="px-2 py-0 md:px-4 md:py-2"
            @click="openForm()"
            variant="default"
          >
            <Icon name="plus" class="mr-1 h-4 w-4 md:mr-2" />
            Add Rejection
          </Button>

          <Button
            class="px-2 py-0 md:px-4 md:py-2"
            v-if="
              selectedRejections.length > 0 &&
              permissionNames.includes('acceptance.delete')
            "
            @click="confirmDeleteSelected()"
            variant="destructive"
          >
            <Icon name="trash" class="mr-1 h-4 w-4 md:mr-2" />
            Delete Selected ({{ selectedRejections.length }})
          </Button>

          <Button
            @click="showImportModal = true"
            v-if="permissionNames.includes('acceptance.import')"
            variant="secondary"
            class="px-2 py-0 md:px-4 md:py-2 shadow-sm hover:shadow transition-all"
          >
            <Icon name="upload" class="mr-1 h-4 w-4 md:mr-2" />
            Import CSV
          </Button>

          <Button
            class="px-2 py-0 md:px-4 md:py-2"
            @click.prevent="exportCSV"
            variant="outline"
            v-if="permissionNames.includes('acceptance.export')"
          >
            <Icon name="download" class="mr-1 h-4 w-4 md:mr-2" />
            Download CSV
          </Button>

          <Button
            class="px-2 py-0 md:px-4 md:py-2"
            v-if="isSuperAdmin"
            @click="openCodeModal()"
            variant="outline"
          >
            <Icon name="settings" class="mr-1 h-4 w-4 md:mr-2" />
            Manage Reason Codes
          </Button>
        </div>
      </div>

      <!-- Hidden Export Form -->
      <form ref="exportForm" :action="exportUrl" method="GET" class="hidden"></form>

      <!-- Date Filter Tabs -->
      <Card>
        <CardContent class="p-2 md:p-4 lg:p-6">
          <div class="flex flex-col items-center gap-2 md:items-start">
            <div class="flex flex-wrap gap-1 md:gap-2">
              <Button
                @click="selectDateFilter('yesterday')"
                variant="outline"
                size="sm"
                :class="{
                  'border-primary bg-primary/10 text-primary': activeTab === 'yesterday',
                }"
              >
                Yesterday
              </Button>
              <Button
                @click="selectDateFilter('current-week')"
                variant="outline"
                size="sm"
                :class="{
                  'border-primary bg-primary/10 text-primary':
                    activeTab === 'current-week',
                }"
              >
                WTD
              </Button>
              <Button
                @click="selectDateFilter('6w')"
                variant="outline"
                size="sm"
                :class="{
                  'border-primary bg-primary/10 text-primary': activeTab === '6w',
                }"
              >
                T6W
              </Button>
              <Button
                @click="selectDateFilter('quarterly')"
                variant="outline"
                size="sm"
                :class="{
                  'border-primary bg-primary/10 text-primary': activeTab === 'quarterly',
                }"
              >
                Quarterly
              </Button>
            </div>

            <div v-if="dateRange" class="text-sm text-muted-foreground">
              <span v-if="activeTab === 'yesterday' && dateRange.start">
                Showing data from {{ formatDate(dateRange.start) }}
              </span>
              <span v-else-if="dateRange.start && dateRange.end">
                Showing data from {{ formatDate(dateRange.start) }} to
                {{ formatDate(dateRange.end) }}
              </span>
              <span v-else>
                {{ dateRange.label }}
              </span>
              <span v-if="weekNumberText" class="ml-1">({{ weekNumberText }})</span>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Filters Section -->
      <Card class="mb-6">
        <CardHeader class="p-2 md:p-4 lg:p-6">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <CardTitle class="text-lg md:text-xl lg:text-2xl">Filters</CardTitle>

  <div class="ml-4 flex items-center gap-2 border-l pl-4">
  <span class="text-sm text-muted-foreground">View:</span>
  <div class="flex rounded-md border p-1 bg-muted/20">
    <Button
      @click="setViewType('rejections')"
      variant="ghost"
      size="sm"
      :class="[
        'px-3 py-1 text-xs rounded-md transition-all',
        filters.viewType === 'rejections' 
          ? 'bg-destructive text-destructive-foreground shadow-sm' 
          : 'hover:bg-muted'
      ]"
    >
      <Icon name="x-circle" class="mr-1 h-3 w-3" />
      Rejections
    </Button>
    <Button
      @click="setViewType('acceptance')"
      variant="ghost"
      size="sm"
      :class="[
        'px-3 py-1 text-xs rounded-md transition-all',
        filters.viewType === 'acceptance' 
          ? 'bg-primary text-primary-foreground shadow-sm' 
          : 'hover:bg-muted'
      ]"
    >
      <Icon name="check-circle" class="mr-1 h-3 w-3" />
     Accepted
    </Button>
  </div>
</div>

              <div
                v-if="!showFilters && hasActiveFilters"
                class="ml-4 flex flex-wrap gap-2"
              >
                <div
                  v-if="filters.search"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Search: {{ filters.search }}
                </div>
                <div
                  v-if="filters.rejectionType"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Type: <span class="capitalize">{{ filters.rejectionType }}</span>
                </div>
                <div
                  v-if="filters.reasonCode"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Reason: {{ getReasonCodeLabel(filters.reasonCode) }}
                </div>
                <div
                  v-if="filters.rejectionCategory"
                  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                >
                  Category: {{ getRejectionCategoryLabel(filters.rejectionCategory) }}
                </div>
            <div
  v-if="filters.disputeStatus"
  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
>
  Dispute: 
  <span class="capitalize ml-1">{{ filters.disputeStatus }}</span>
</div>
<div
  v-if="filters.controllable && filters.controllable.length > 0"
  class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
>
  Controllable: 
  <span class="ml-1">
    {{ filters.controllable.map(c => {
      if (c === 'carrier') return 'Carrier';
      if (c === 'driver') return 'Driver';
      if (c === 'none') return 'Not';
      if (c === 'both') return 'Both';
      return c;
    }).join(', ') }}
  </span>
</div>
              </div>
            </div>

            <Button variant="ghost" size="sm" @click="showFilters = !showFilters">
              {{ showFilters ? "Hide Filters" : "Show Filters" }}
              <Icon
                :name="showFilters ? 'chevron-up' : 'chevron-down'"
                class="ml-2 h-4 w-4"
              />
            </Button>
          </div>
        </CardHeader>

        <CardContent v-if="showFilters" class="p-2 md:p-4 lg:p-6">
          <div class="flex flex-col gap-1 md:gap-4">
            <div class="grid w-full grid-cols-1 gap-1 sm:grid-cols-3 md:gap-4">
              <div>
                <Label for="search">Search</Label>
                <Input
                  class="h-9 w-full px-1 py-1 md:px-2 md:py-1 lg:h-10 lg:px-3 lg:py-2"
                  id="search"
                  v-model="filters.search"
                  type="text"
                  placeholder="Search by driver name..."
                />
              </div>
            </div>

            <div class="grid w-full grid-cols-1 gap-4 sm:grid-cols-3">
              <div>
                <Label for="rejectionType">Rejection Type</Label>
                <select
                  id="rejectionType"
                  v-model="filters.rejectionType"
                  class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <option value="">All Types</option>
                  <option value="block">Block</option>
                  <option value="load">Load</option>
                  <option value="advanced">Advanced</option>

                </select>
              </div>

              <div>
                <Label for="reasonCode">Reason Code</Label>
                <select
                  id="reasonCode"
                  v-model="filters.reasonCode"
                  class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <option value="">All Reason Codes</option>
                  <option
                    v-for="code in rejection_reason_codes"
                    :key="code.id"
                    :value="code.id"
                  >
                    {{ code.reason_code }}
                  </option>
                </select>
              </div>

              <div>
                <Label for="rejectionCategory">Rejection From Start Time</Label>
                <select
                  id="rejectionCategory"
                  v-model="filters.rejectionCategory"
                  class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <option value="">All Categories</option>
                  <template
                    v-if="!filters.rejectionType || filters.rejectionType === 'block'"
                  >
                    <option value="more_than_24">More than 24 hours</option>
                    <option value="within_24">Within 24 hours</option>
                  </template>
                  <template
                    v-if="!filters.rejectionType || filters.rejectionType === 'load'"
                  >
                    <option value="more_than_6">More than 6 hours</option>
                    <option value="within_6">Within 6 hours</option>
                  </template>
                  <option value="after_start">After start time</option>
                </select>
              </div>
            </div>

            <div class="grid w-full grid-cols-1 gap-4 sm:grid-cols-2">
             <div>
  <Label for="disputeStatus">Dispute Status</Label>
  <select
    id="disputeStatus"
    v-model="filters.disputeStatus"
    class="flex h-10 w-full items-center rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
  >
    <option value="">All</option>
    <option value="none">None</option>
    <option value="pending">Pending</option>
    <option value="won">Won</option>
    <option value="lost">Lost</option>
  </select>
</div>

        <div>
  <Label>Controllable</Label>
  <div class="relative">
    <!-- Multi-select dropdown button -->
    <button
      type="button"
      @click="toggleControllableDropdown"
      class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
    >
      <span class="truncate">
        {{ getControllableDisplayText }}
      </span>
      <Icon name="chevron-down" class="h-4 w-4 opacity-50" />
    </button>
    
    <!-- Dropdown menu with checkboxes -->
    <div
      v-if="showControllableDropdown"
      class="absolute z-50 mt-1 w-full rounded-md border border-input bg-background shadow-lg"
    >
      <div class="p-2 space-y-2">
        <label class="flex items-center space-x-2 px-2 py-1 hover:bg-muted/50 rounded cursor-pointer">
          <input
            type="checkbox"
            v-model="filters.controllable"
            value="carrier"
            class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
          />
          <span class="text-sm">Carrier Controllable</span>
        </label>
        
        <label class="flex items-center space-x-2 px-2 py-1 hover:bg-muted/50 rounded cursor-pointer">
          <input
            type="checkbox"
            v-model="filters.controllable"
            value="driver"
            class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
          />
          <span class="text-sm">Driver Controllable</span>
        </label>
        
        <label class="flex items-center space-x-2 px-2 py-1 hover:bg-muted/50 rounded cursor-pointer">
          <input
            type="checkbox"
            v-model="filters.controllable"
            value="none"
            class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
          />
          <span class="text-sm">Not Controllable</span>
        </label>
        
        <label class="flex items-center space-x-2 px-2 py-1 hover:bg-muted/50 rounded cursor-pointer">
          <input
            type="checkbox"
            v-model="filters.controllable"
            value="both"
            class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
          />
          <span class="text-sm">Both</span>
        </label>
      </div>
      
      <div class="border-t p-2 flex justify-between">
        <Button 
          size="sm" 
          variant="ghost" 
          @click="clearControllable"
          class="text-xs"
        >
          Clear
        </Button>
        <Button 
          size="sm" 
          @click="showControllableDropdown = false"
          class="text-xs"
        >
          Apply
        </Button>
      </div>
    </div>
  </div>
  <p class="text-xs text-muted-foreground mt-1">
    Selected: {{ filters.controllable.length }} options
  </p>
</div>
            </div>

            <div class="flex justify-end space-x-2">
              <Button @click="resetFilters" variant="ghost" size="sm">
                <Icon name="rotate_ccw" class="mr-2 h-4 w-4" />
                Reset Filters
              </Button>
              <Button @click="applyFilters" variant="default" size="sm">
                <Icon name="filter" class="mr-2 h-4 w-4" />
                Apply Filters
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Acceptance Dashboard -->
      <AcceptanceDashboard
        v-if="!isSuperAdmin"
        :metricsData="acceptanceMetrics || {}"
        :driversData="bottomDrivers || []"
        :chartData="acceptanceChartData || {}"
        :averageAcceptance="average_acceptance || null"
        :currentDateFilter="props.dateRange?.label || ''"
        :currentFilters="filters || {}"
      />

      <!-- Rejections Table -->
      <Card class="mx-auto max-w-[95vw] overflow-x-auto md:max-w-[64vw] lg:max-w-full">
        <CardContent class="p-0">
         <div class="overflow-x-auto">
      <Table class="relative h-[500px] overflow-auto">
        <TableHeader>
          <TableRow class="sticky top-0 z-10 border-b bg-background hover:bg-background">
            <TableHead
              class="w-[50px]"
              v-if="permissionNames.includes('acceptance.delete') && filters.viewType === 'rejections'"
            >
              <div class="flex items-center justify-center">
                <input
                  type="checkbox"
                  @change="toggleSelectAll"
                  :checked="isAllSelected"
                  class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                />
              </div>
            </TableHead>

            <TableHead v-if="isSuperAdmin">Company Name</TableHead>

            <TableHead
  v-for="col in visibleColumns"
  :key="col"
  class="cursor-pointer"
  @click="sortBy(col)"

            >
              <div class="flex items-center">
                <div>{{ formatColumnName(col) }}</div>
                <!-- Sorting icons (keep existing) -->
              </div>
            </TableHead>

            <TableHead
              v-if="
                (permissionNames.includes('acceptance.update') ||
                permissionNames.includes('acceptance.delete')) &&
                filters.viewType === 'rejections'
              "
            >
              Actions
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          <TableRow v-if="filteredData.length === 0">
            <TableCell
              :colspan="getColspan()"
              class="py-8 text-center text-primary font-medium"
            >
              No {{ filters.viewType }} found matching your criteria
            </TableCell>
          </TableRow>

          <TableRow
            v-for="item in filteredData"
            :key="item.id"
            class="hover:bg-muted/50"
          >
            <TableCell
              class="text-center"
              v-if="permissionNames.includes('acceptance.delete') && filters.viewType === 'rejections'"
            >
              <input
                type="checkbox"
                :value="item.id"
                v-model="selectedRejections"
                class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
              />
            </TableCell>

            <TableCell v-if="isSuperAdmin">{{ item.tenant?.name || "—" }}</TableCell>

           <TableCell v-for="col in visibleColumns" :key="col" class="whitespace-nowrap">
              <template v-if="col === 'date'">
                {{ formatDate(item.date) }}
              </template>
              
              <!-- Rejection specific fields -->
              <template v-else-if="col === 'rejectiontype' && filters.viewType === 'rejections'">
                <span class="capitalize">{{ item.type }}</span>
              </template>
              
              <!-- Acceptance specific fields -->
              <template v-else-if="col === 'acceptancetype' && filters.viewType === 'acceptance'">
                <span class="capitalize">{{ item.type }}</span>
              </template>
              
              <template v-else-if="col === 'on_time_status'">
                <span :class="{
                  'text-green-600': item.on_time_status === 'on_time',
                  'text-yellow-600': item.on_time_status === 'late',
                  'text-blue-600': item.on_time_status === 'early',
                  'text-red-600': item.on_time_status === 'missed'
                }">
                  {{ item.on_time_status?.replace('_', ' ') || '—' }}
                </span>
              </template>
              
              <template v-else-if="col === 'performance_score'">
                {{ item.performance_score != null ? item.performance_score + '%' : '—' }}
              </template>
              
              <template v-else-if="col === 'driver_rating'">
                {{ item.driver_rating != null ? item.driver_rating + '/5' : '—' }}
              </template>
              
              <template v-else-if="col === 'destination_arrival_at'">
                {{ item.destination_arrival_at ? formatDate(item.destination_arrival_at) : '—' }}
              </template>
              
              <template v-else-if="col === 'accepted_at'">
                {{ item.accepted_at ? formatDate(item.accepted_at) : '—' }}
              </template>
              
              <!-- Rejection specific fields (keep existing) -->
              <template v-else-if="col === 'advanced_block_id' && filters.viewType === 'rejections'">
                {{ item.advanced_block_id ?? "—" }}
              </template>
              
              <template v-else-if="col === 'impacted_blocks' && filters.viewType === 'rejections'">
                {{ item.impacted_blocks ?? "—" }}
              </template>
              
              <template v-else-if="col === 'rejected_at' && filters.viewType === 'rejections'">
                {{ item.rejected_at ? formatDate(item.rejected_at) : "—" }}
              </template>
              
            <template v-else-if="col === 'bucket' && filters.viewType === 'rejections'">
  {{ item.bucket && item.bucket !== '' ? item.bucket : '—' }}
</template>

<template v-else-if="col === 'rejection_bucket' && filters.viewType === 'rejections'">
  {{ item.rejection_bucket && item.rejection_bucket !== '' ? item.rejection_bucket : '—' }}
</template>
<template v-else-if="col === 'reason' && filters.viewType === 'rejections'">
  {{ item.reason || item.raw_reason || '—' }}
</template>
              <template v-else-if="col === 'disputed' && filters.viewType === 'rejections'">
                {{ item.dispute_status ? item.dispute_status.charAt(0).toUpperCase() + item.dispute_status.slice(1) : "None" }}
              </template>
              
              <template v-else-if="col === 'drivercontrollable' && filters.viewType === 'rejections'">
                {{ item.driver_controllable === null ? "N/A" : item.driver_controllable ? "Yes" : "No" }}
              </template>
              
              <template v-else-if="col === 'carrier_controllable' && filters.viewType === 'rejections'">
                {{ item.carrier_controllable ? "Yes" : "No" }}
              </template>
              
              <template v-else-if="col === 'penalty' && filters.viewType === 'rejections'">
                {{ item.penalty != null ? item.penalty : "—" }}
              </template>
              
              <!-- Shared fields -->
              <template v-else-if="col === 'drivername'">
                {{ item.driver_name || "N/A" }}
              </template>
              
              <template v-else-if="col === 'block_id'">
                {{ item.block_id ?? "—" }}
              </template>
              
              <template v-else-if="col === 'block_start_at'">
                {{ item.block_start_at ? formatDate(item.block_start_at) : "—" }}
              </template>
              
              <template v-else-if="col === 'block_end_at'">
                {{ item.block_end_at ? formatDate(item.block_end_at) : "—" }}
              </template>
              
              <template v-else-if="col === 'load_id'">
                {{ item.load_id ?? "—" }}
              </template>
              
              <template v-else-if="col === 'origin_yard_arrival_at'">
                {{ item.origin_yard_arrival_at ? formatDate(item.origin_yard_arrival_at) : "—" }}
              </template>
              
              <!-- Fallback -->
              <template v-else>
                {{ item[col] ?? "—" }}
              </template>
            </TableCell>

            <TableCell
              v-if="
                (permissionNames.includes('acceptance.delete') ||
                permissionNames.includes('acceptance.update')) &&
                filters.viewType === 'rejections'
              "
            >
              <div class="flex space-x-2">
                <Button
                  size="sm"
                  @click="openForm(item)"
                  variant="warning"
                  v-if="permissionNames.includes('acceptance.update')"
                >
                  <Icon name="pencil" class="mr-1 h-4 w-4" />
                  Edit
                </Button>

                <Button
                  size="sm"
                  variant="destructive"
                  @click="confirmDeleteRejection(item.id)"
                  v-if="permissionNames.includes('acceptance.delete')"
                >
                  <Icon name="trash" class="mr-1 h-4 w-4" />
                  Delete
                </Button>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

         <!-- paginate -->
<div class="border-t bg-muted/20 px-4 py-3" v-if="currentData?.links">
  <div class="flex flex-col items-center justify-between gap-2 sm:flex-row">
    <div class="flex items-center gap-4 text-sm text-muted-foreground">
      <span
        >Showing {{ filteredData.length }} of
        {{ currentData.total || currentData.data?.length || 0 }} entries</span
      >

      <div
        class="flex w-full flex-col items-center gap-2 sm:w-auto sm:flex-row sm:gap-4"
      >
        <span class="text-sm">Show:</span>
        <select
          v-model="perPage"
          @change="changePerPage"
          class="h-8 rounded-md border border-input bg-background px-2 py-1 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
        >
          <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">
            {{ size }}
          </option>
        </select>
      </div>
    </div>

    <div class="flex flex-wrap">
      <Button
        v-for="link in currentData.links"
        :key="link.label"
        @click="visitPage(link.url)"
        :disabled="!link.url"
        variant="ghost"
        size="sm"
        class="mx-1"
        :class="{ 'border-primary bg-primary/10 text-primary': link.active }"
      >
        <span v-html="link.label"></span>
      </Button>
    </div>
  </div>
</div>
        </CardContent>
      </Card>

      <!-- Rejection Form Modal -->
      <Dialog v-model:open="formModal">
        <DialogContent class="max-w-[95vw] sm:max-w-[90vw] md:max-w-4xl">
          <DialogHeader class="px-4 sm:px-6">
            <DialogTitle class="text-lg sm:text-xl"
              >{{ selectedRejection ? "Edit" : "Add" }} Rejection</DialogTitle
            >
            <DialogDescription class="text-xs sm:text-sm">
              Fill in the details to {{ selectedRejection ? "update" : "add" }} a
              rejection.
            </DialogDescription>
          </DialogHeader>

          <RejectionForm
            :rejection="selectedRejection"
            :reasons="rejection_reason_codes"
            :tenants="tenants"
            :is-super-admin="isSuperAdmin"
            :tenant-slug="tenantSlug"
            @close="formModal = false"
            class="max-h-[75vh] overflow-y-auto p-4 sm:p-6"
          />
        </DialogContent>
      </Dialog>

      <!-- Code Manager Modal for Reason Codes -->
      <Dialog v-model:open="codeModal" v-if="isSuperAdmin">
        <DialogContent class="max-w-[95vw] sm:max-w-[90vw] md:max-w-2xl">
          <DialogHeader class="px-4 sm:px-6">
            <DialogTitle class="text-lg sm:text-xl">Manage Reason Codes</DialogTitle>
            <DialogDescription class="text-xs sm:text-sm">
              Create and manage reason codes for rejections.
            </DialogDescription>
          </DialogHeader>

          <div class="max-h-[75vh] space-y-4 overflow-y-auto p-4 sm:p-6">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-medium sm:text-base">Current Reason Codes</h3>
              <Button
                @click="openNewCodeForm"
                size="sm"
                variant="outline"
                class="h-8 px-3 text-xs sm:h-9 sm:px-4 sm:text-sm"
              >
                <Icon name="plus" class="mr-2 h-3 w-3 sm:h-4 sm:w-4" />
                Add New Code
              </Button>
            </div>

            <div class="max-h-[400px] overflow-y-auto rounded-md border">
              <div
                v-if="!rejection_reason_codes || rejection_reason_codes.length === 0"
                class="rounded-md border py-8 text-center text-xs text-muted-foreground sm:text-sm"
              >
                No reason codes found
              </div>

              <div v-else class="divide-y">
                <div
                  v-for="code in rejection_reason_codes"
                  :key="code.id"
                  class="group flex items-center justify-between p-3 hover:bg-muted/50"
                >
                  <div class="flex-1 cursor-pointer" @click="editCode(code)">
                    <div class="text-xs font-medium sm:text-sm">
                      {{ code.reason_code }}
                      <span
                        v-if="code.deleted_at"
                        class="ml-2 text-[0.65rem] text-red-500 sm:text-xs"
                      >
                        (Deleted)
                      </span>
                    </div>
                    <div
                      v-if="code.description"
                      class="mt-1 text-xs text-muted-foreground sm:text-sm"
                    >
                      {{ code.description }}
                    </div>
                  </div>

                  <div
                    class="flex space-x-1 opacity-0 transition-opacity group-hover:opacity-100"
                  >
                    <template v-if="isSuperAdmin">
                      <template v-if="code.deleted_at">
                        <Button
                          @click="restoreCode(code.id)"
                          size="sm"
                          variant="outline"
                          class="h-8 px-2 text-xs sm:h-9 sm:px-3 sm:text-sm"
                        >
                          <Icon name="refresh" class="mr-1 h-3 w-3 sm:h-3 sm:w-3" />
                          Restore
                        </Button>
                        <Button
                          @click="forceDeleteCode(code.id)"
                          size="sm"
                          variant="destructive"
                          class="h-8 px-2 text-xs sm:h-9 sm:px-3 sm:text-sm"
                        >
                          <Icon name="trash" class="mr-1 h-3 w-3 sm:h-3 sm:w-3" />
                          Delete
                        </Button>
                      </template>

                      <template v-else>
                        <Button
                          @click="confirmDeleteCode(code.id)"
                          size="sm"
                          variant="destructive"
                          class="h-8 px-2 text-xs sm:h-9 sm:px-3 sm:text-sm"
                        >
                          <Icon name="trash" class="mr-1 h-3 w-3 sm:h-3 sm:w-3" />
                          Delete
                        </Button>
                      </template>
                    </template>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="showCodeForm" class="space-y-4 rounded-md border p-4">
              <h3 class="text-sm font-medium sm:text-base">
                {{ editingCode ? "Edit" : "Add" }} Reason Code
              </h3>
              <div class="space-y-3">
                <div>
                  <Label for="reason_code" class="text-xs sm:text-sm">Code</Label>
                  <Input
                    id="reason_code"
                    v-model="codeForm.reason_code"
                    placeholder="Enter reason code"
                    class="h-9 text-xs sm:h-10 sm:text-sm"
                  />
                </div>

                <div>
                  <Label for="description" class="text-xs sm:text-sm">Description</Label>
                  <Input
                    id="description"
                    v-model="codeForm.description"
                    placeholder="Enter description"
                    class="h-9 text-xs sm:h-10 sm:text-sm"
                  />
                </div>

                <div class="flex justify-end space-x-2">
                  <Button
                    @click="cancelCodeEdit"
                    variant="ghost"
                    size="sm"
                    class="h-8 px-3 text-xs sm:h-9 sm:px-4 sm:text-sm"
                    >Cancel</Button
                  >
                  <Button
                    @click="saveCode"
                    variant="default"
                    size="sm"
                    class="h-8 px-3 text-xs sm:h-9 sm:px-4 sm:text-sm"
                    >Save</Button
                  >
                </div>
              </div>
            </div>
          </div>

          <DialogFooter class="px-4 sm:px-6">
            <Button
              @click="codeModal = false"
              variant="outline"
              class="h-9 px-4 py-1 text-xs sm:h-10 sm:text-sm"
              >Close</Button
            >
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Delete Code Confirmation Dialog -->
      <Dialog v-model:open="codeDeleteConfirm">
        <DialogContent class="max-w-[95vw] sm:max-w-md">
          <DialogHeader class="px-4 sm:px-6">
            <DialogTitle class="text-lg sm:text-xl">Confirm Deletion</DialogTitle>
            <DialogDescription class="text-xs sm:text-sm">
              Are you sure you want to delete this reason code? This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter class="px-4 sm:px-6">
            <Button
              type="button"
              @click="codeDeleteConfirm = false"
              variant="outline"
              class="h-9 px-4 py-1 text-xs sm:h-10 sm:text-sm"
            >
              Cancel
            </Button>
            <Button
              type="button"
              @click="deleteCode(codeToDelete)"
              variant="destructive"
              class="h-9 px-4 py-1 text-xs sm:h-10 sm:text-sm"
            >
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Bulk Delete Confirmation Dialog -->
      <Dialog v-model:open="showDeleteSelectedModal">
        <DialogContent class="max-w-[95vw] sm:max-w-md">
          <DialogHeader class="px-4 sm:px-6">
            <DialogTitle class="text-lg sm:text-xl">Confirm Bulk Deletion</DialogTitle>
            <DialogDescription class="text-xs sm:text-sm">
              Are you sure you want to delete {{ selectedRejections.length }} rejection
              records? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter class="px-4 sm:px-6">
            <Button
              type="button"
              @click="showDeleteSelectedModal = false"
              variant="outline"
              class="h-9 px-4 py-1 text-xs sm:h-10 sm:text-sm"
            >
              Cancel
            </Button>
            <Button
              type="button"
              @click="deleteSelectedRejections()"
              variant="destructive"
              class="h-9 px-4 py-1 text-xs sm:h-10 sm:text-sm"
            >
              Delete Selected
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>

    <!-- Delete Rejection Confirmation Dialog -->
    <Dialog v-model:open="showDeleteModal">
      <DialogContent class="max-w-[95vw] sm:max-w-md">
        <DialogHeader class="px-4 sm:px-6">
          <DialogTitle class="text-lg sm:text-xl">Confirm Deletion</DialogTitle>
          <DialogDescription class="text-xs sm:text-sm">
            Are you sure you want to delete this rejection record? This action cannot be
            undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="px-4 sm:px-6">
          <Button
            type="button"
            @click="showDeleteModal = false"
            variant="outline"
            class="h-9 px-4 py-1 text-xs sm:h-10 sm:text-sm"
          >
            Cancel
          </Button>
          <Button
            type="button"
            @click="deleteRejection(rejectionToDelete)"
            variant="destructive"
            class="h-9 px-4 py-1 text-xs sm:h-10 sm:text-sm"
          >
            Delete
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

   <!-- Import Validation Modal -->
<Dialog v-model:open="showImportModal">
  <DialogContent
    class="max-w-[95vw] sm:max-w-[90vw] md:max-w-5xl max-h-[90vh] overflow-hidden flex flex-col"
  >
    <DialogHeader class="px-4 sm:px-6 border-b pb-3">
      <div class="flex items-center gap-2">
        <Icon name="upload" class="h-5 w-5 text-primary" />
        <DialogTitle class="text-lg sm:text-xl font-semibold">
          Import Rejections
        </DialogTitle>
      </div>
      <DialogDescription class="text-xs sm:text-sm mt-1 text-muted-foreground">
        Upload a CSV file to import rejections. The file will be validated before import.
      </DialogDescription>
      
      <!-- Form for file upload -->
      <form id="importForm" enctype="multipart/form-data">
        <!-- SUPERADMIN TENANT SELECTOR -->
        <div v-if="isSuperAdmin" class="mb-4">
          <Label for="tenant-selector" class="text-sm font-medium text-gray-700">Select Tenant:</Label>
          <select
            id="tenant-select"
            v-model="selectedTenantId"
            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
            required
          >
            <option value="" disabled selected>Choose tenant...</option>
            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
              {{ tenant.name }} (ID: {{ tenant.id }})
            </option>
          </select>
        </div>
      </form>
    </DialogHeader>

    <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-4">
      <!-- Step 1: File Upload -->
      <div v-if="!importValidationResults">
        <div class="space-y-6">
          <!-- MAIN FILE - Rejection CSV -->
          <div class="rounded-lg border p-4 bg-muted/5">
            <h3 class="text-sm font-medium mb-3 flex items-center gap-2">
              <Icon name="file-spreadsheet" class="h-4 w-4 text-primary" />
              Rejection CSV File <span class="text-xs text-red-500">* Required</span>
            </h3>
            
            <!-- Dropzone -->
            <div
              class="flex flex-col items-center justify-center border-2 border-dashed rounded-lg p-8 bg-muted/20 transition-colors"
              :class="{
                'border-primary bg-primary/5': isDragging,
                'opacity-60 pointer-events-none': isValidating,
              }"
              @dragenter.prevent="onDragEnter"
              @dragover.prevent="onDragOver"
              @dragleave.prevent="onDragLeave"
              @drop.prevent="onDrop"
            >
              <Icon name="file-spreadsheet" class="h-12 w-12 text-muted-foreground mb-3" />

              <div class="text-center">
                <div class="text-sm font-medium">
                  <span class="text-primary">Drag & drop</span> your CSV here
                </div>
                <p class="text-xs text-muted-foreground mt-1">or</p>
              </div>

              <label class="cursor-pointer mt-3">
                <span class="text-sm font-medium text-primary hover:underline">
                  Choose CSV file
                </span>
                <input
                  ref="importFileInput"
                  type="file"
                  class="hidden"
                  @change="onImportInputChange"
                  accept=".csv,text/csv"
                  :disabled="isValidating"
                />
              </label>

              <p class="text-xs text-muted-foreground mt-2">CSV only</p>

              <div v-if="isDragging" class="mt-3 text-xs text-primary font-medium">
                Drop file to validate
              </div>
            </div>

            <!-- Template Download -->
            <div class="flex items-center gap-2 text-sm text-muted-foreground mt-3">
              <Icon name="info" class="h-4 w-4" />
              <a :href="templateUrl" download class="text-primary hover:underline">
                Download CSV Template
              </a>
            </div>
          </div>

          <!-- OPTIONAL TRIPS FILE FOR DRIVER MAPPING -->
          <div class="rounded-lg border p-4 bg-muted/10">
            <div class="flex items-center gap-2 mb-2">
              <Icon name="users" class="h-4 w-4 text-muted-foreground" />
              <h3 class="text-sm font-medium">Driver Mapping File (Optional)</h3>
            </div>
            
            <p class="text-xs text-muted-foreground mb-3">
              Upload a Trips CSV file to automatically map driver names to loads.
              The system will trace driver assignments sequentially by Operator ID.
            </p>

            <!-- Dropzone for trips file -->
            <div
              class="flex flex-col items-center justify-center border-2 border-dashed rounded-lg p-6 bg-muted/20 transition-colors"
              :class="{ 
                'border-primary bg-primary/5': isTripsDragging,
                'opacity-60 pointer-events-none': isValidating 
              }"
              @dragenter.prevent="onTripsDragEnter"
              @dragover.prevent="onTripsDragOver"
              @dragleave.prevent="onTripsDragLeave"
              @drop.prevent="onTripsDrop"
            >
              <Icon name="file-text" class="h-8 w-8 text-muted-foreground mb-2" />

              <div class="text-center">
                <div class="text-xs">
                  <span class="text-primary">Drag & drop</span> trips CSV here
                </div>
                <p class="text-xs text-muted-foreground mt-1">or</p>
              </div>

              <label class="cursor-pointer mt-2">
                <span class="text-xs font-medium text-primary hover:underline">
                  Choose Trips CSV file
                </span>
                <input
                  ref="tripsFileInput"
                  type="file"
                  class="hidden"
                  @change="onTripsInputChange"
                  accept=".csv,text/csv"
                  :disabled="isValidating"
                />
              </label>

              <p class="text-xs text-muted-foreground mt-2">CSV only</p>
            </div>

            <!-- Selected file indicator -->
            <div v-if="selectedTripsFile" class="mt-2 flex items-center gap-2 text-xs bg-green-50 dark:bg-green-900/10 p-2 rounded">
              <Icon name="check-circle" class="h-3 w-3 text-green-600 flex-shrink-0" />
              <span class="truncate flex-1">{{ selectedTripsFile.name }}</span>
              <button @click="clearTripsFile" class="text-red-500 hover:text-red-700 p-1">
                <Icon name="x" class="h-3 w-3" />
              </button>
            </div>
          </div>

          <div v-if="isValidating" class="flex items-center justify-center gap-2 p-4">
            <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
            <span class="text-sm text-muted-foreground">Validating CSV file...</span>
          </div>
        </div>
      </div>

      <!-- Step 2: Validation Results -->
      <div v-else class="space-y-4">
        <!-- CSV Headers chips -->
        <div
          v-if="importValidationResults.headers?.length"
          class="rounded-lg border p-3"
        >
          <div class="flex items-center justify-between">
            <div class="text-sm font-semibold">CSV Headers</div>
            <div class="text-xs text-muted-foreground">
              {{ importValidationResults.headers.length }} columns
            </div>
          </div>
          <div class="mt-2 flex flex-wrap gap-2">
            <span
              v-for="h in importValidationResults.headers"
              :key="h"
              class="rounded-full bg-muted px-2 py-0.5 text-xs"
            >
              {{ h }}
            </span>
          </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-3 gap-4">
          <Card class="border-2">
            <CardContent class="p-4 text-center">
              <div class="text-2xl font-bold">
                {{ importValidationResults.summary.total }}
              </div>
              <div class="text-sm text-muted-foreground">Total Rows</div>
            </CardContent>
          </Card>

          <Card class="border-2 border-green-500/50 bg-green-50 dark:bg-green-900/10">
            <CardContent class="p-4 text-center">
              <div class="text-2xl font-bold text-green-600">
                {{ importValidationResults.summary.valid }}
              </div>
              <div class="text-sm text-muted-foreground">Valid</div>
            </CardContent>
          </Card>

          <Card class="border-2 border-red-500/50 bg-red-50 dark:bg-red-900/10">
            <CardContent class="p-4 text-center">
              <div class="text-2xl font-bold text-red-600">
                {{ importValidationResults.summary.invalid }}
              </div>
              <div class="text-sm text-muted-foreground">Invalid</div>
            </CardContent>
          </Card>
        </div>

        <!-- Header Error -->
        <Alert v-if="importValidationResults.header_error" variant="destructive">
          <AlertTitle class="flex items-center gap-2">
            <Icon name="alert_circle" class="h-5 w-5" />
            Header Error
          </AlertTitle>
          <AlertDescription>
            {{ importValidationResults.header_error }}
          </AlertDescription>
        </Alert>

        <!-- Trips File Stats (if available) -->
        <div v-if="importValidationResults.trips_stats" class="rounded-lg border p-3 bg-blue-50 dark:bg-blue-900/10">
          <div class="flex items-center gap-2 mb-2">
            <Icon name="users" class="h-4 w-4 text-blue-600" />
            <h4 class="text-sm font-semibold">Trips File Stats</h4>
          </div>
          <div class="grid grid-cols-2 gap-2 text-xs">
            <div>Total rows: {{ importValidationResults.trips_stats.total_rows }}</div>
            <div>Unique loads mapped: {{ importValidationResults.trips_stats.mapped_loads }}</div>
          </div>
        </div>

        <!-- Invalid Rows Details -->
        <div v-if="importValidationResults.invalid?.length">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold text-red-600 flex items-center gap-2">
              <Icon name="alert-triangle" class="h-5 w-5" />
              Validation Errors ({{ importValidationResults.invalid.length }})
            </h3>

            <Button
              @click="downloadErrorReport"
              variant="outline"
              size="sm"
              class="flex items-center gap-2"
            >
              <Icon name="download" class="h-4 w-4" />
              Download Error Report
            </Button>
          </div>

          <div class="border rounded-lg overflow-hidden">
            <div class="max-h-96 overflow-y-auto">
              <Table>
                <TableHeader class="sticky top-0 bg-background">
                  <TableRow>
                    <TableHead class="w-20">Row #</TableHead>
                    <TableHead>Preview</TableHead>
                    <TableHead>Errors</TableHead>
                  </TableRow>
                </TableHeader>

                <TableBody>
                  <TableRow
                    v-for="row in importValidationResults.invalid"
                    :key="row.rowNumber"
                    class="hover:bg-muted/50"
                  >
                    <TableCell class="font-medium">{{ row.rowNumber }}</TableCell>

                    <TableCell class="text-sm text-muted-foreground">
                      <div class="flex flex-wrap gap-x-3 gap-y-1">
                        <span
                          v-for="p in row.preview || []"
                          :key="p.key"
                          class="whitespace-nowrap"
                        >
                          <span class="font-medium text-foreground">
                            {{ p.label }}:
                          </span>
                          {{ p.value }}
                        </span>

                        <span
                          v-if="!row.preview?.length"
                          class="italic text-muted-foreground"
                        >
                          —
                        </span>
                      </div>
                    </TableCell>

                    <TableCell>
                      <div class="space-y-1">
                        <div
                          v-for="(error, idx) in row.errors || []"
                          :key="idx"
                          class="text-xs text-red-600 flex items-start gap-1"
                        >
                          <Icon
                            name="x-circle"
                            class="h-3 w-3 mt-0.5 flex-shrink-0"
                          />
                          <span>{{ error }}</span>
                        </div>
                        <div
                          v-if="!row.errors?.length"
                          class="text-xs text-muted-foreground"
                        >
                          —
                        </div>
                      </div>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
          </div>
        </div>

        <!-- Valid Rows Preview -->
        <div v-if="importValidationResults.valid?.length">
          <h3
            class="text-lg font-semibold text-green-600 flex items-center gap-2 mb-3"
          >
            <Icon name="check-circle" class="h-5 w-5" />
            Valid Rows ({{ importValidationResults.valid.length }})
          </h3>

          <div class="text-sm text-muted-foreground mb-2">
            Showing first 5 valid rows
          </div>

          <div class="border rounded-lg overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Row #</TableHead>
                  <TableHead>Preview</TableHead>
                </TableRow>
              </TableHeader>

              <TableBody>
                <TableRow
                  v-for="row in importValidationResults.valid.slice(0, 5)"
                  :key="row.rowNumber"
                >
                  <TableCell class="font-medium">{{ row.rowNumber }}</TableCell>

                  <TableCell class="text-sm">
                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                      <span
                        v-for="p in row.preview || []"
                        :key="p.key"
                        class="whitespace-nowrap"
                      >
                        <span class="font-medium">{{ p.label }}:</span>
                        {{ p.value }}
                      </span>

                      <span
                        v-if="!row.preview?.length"
                        class="italic text-muted-foreground"
                      >
                        —
                      </span>
                    </div>
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Footer -->
    <div class="border-t p-4 flex justify-end gap-3">
      <Button @click="closeImportModal" variant="outline" :disabled="isImporting">
        Close
      </Button>

      <Button
        v-if="importValidationResults && importValidationResults.summary.valid > 0"
        @click="confirmImport"
        variant="default"
        :disabled="
          isImporting ||
          importValidationResults.summary.invalid > 0 ||
          Boolean(importValidationResults.header_error)
        "
        class="flex items-center gap-2"
      >
        <div
          v-if="isImporting"
          class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"
        ></div>
        <Icon v-else name="check" class="h-4 w-4" />
        {{
          isImporting
            ? "Importing..."
            : `Import ${importValidationResults.summary.valid} Rows`
        }}
      </Button>
    </div>
  </DialogContent>
</Dialog>
  </AppLayout>
</template>

<script setup>
import { Head, useForm, usePage, router } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";

import AcceptanceDashboard from "@/components/acceptance/AcceptanceDashboard.vue";
import Icon from "@/components/Icon.vue";
import RejectionForm from "@/components/RejectionForm.vue";

import {
  Alert,
  AlertDescription,
  AlertTitle,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Input,
  Label,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui";

import Button from "@/components/ui/button/Button.vue";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
   SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
 
} from '@/components/ui/select'  // Your shadcn path

import AppLayout from "@/layouts/AppLayout.vue";

const props = defineProps({
  rejections: {
    type: Object,
    default: () => ({ data: [], links: [] }),
  },
  tenantSlug: { type: String, default: null },
  tenantId: { type: [String, Number], default: null }, // ✅ Add this line
  rejection_reason_codes: Array,
  tenants: { type: Array, default: () => [] },
  isSuperAdmin: { type: Boolean, default: false },
  dateFilter: { type: String, default: "yesterday" },
  dateRange: { type: Object, default: () => ({ label: "All Time" }) },
  perPage: { type: Number, default: 10 },
  weekNumber: { type: Number, default: null },
  startWeekNumber: { type: Number, default: null },
  endWeekNumber: { type: Number, default: null },
  year: { type: Number, default: null },
  rejection_breakdown: { type: Object, default: null },
  line_chart_data: { type: Object, default: null },
  average_acceptance: { type: Number },
  filters: {
    type: Object,
    default: () => ({
      search: "",
      rejectionType: "",
      reasonCode: "",
      rejectionCategory: "",
      disputeStatus: "",     // ✅ Fixed
      controllable: [],      // ✅ Fixed - array
      viewType: "rejections", // Add this

    }),
  },
    acceptances: {
    type: Object,
    default: () => ({ data: [], links: [] }),
  },
  
  // Add acceptance metrics
  acceptanceMetrics: {
    type: Object,
    default: null,
  },
  // Add top drivers for acceptance view
  topDrivers: {
    type: Array,
    default: () => [],
  },
  permissions: Array,

});
console.log('Props received:', {
  viewType: props.filters?.viewType,
  hasAcceptances: !!props.acceptances,
  hasRejections: !!props.rejections,
  filters: props.filters
});


const tripsFileInput = ref(null);

const effectiveTenantId = computed(() => {
  if (props.isSuperAdmin) {
    return selectedTenantId.value
  }
  return props.tenantId
})

/** Rejection columns */
const rejectionColumns = [
  "date",
  "drivername",
  "rejectiontype",
  "advanced_block_id",
  "impacted_blocks",
  "block_id",
  "block_start_at",
  "block_end_at",
  "rejected_at",
  "bucket",
  "load_id",
  "origin_yard_arrival_at",
  "rejection_bucket",
  "reason",  // ← ADD THIS LINE
  "disputed",
  "drivercontrollable",
  "carrier_controllable",
  "penalty",
];

/** Acceptance columns */
const acceptanceColumns = [
  "date",
  "drivername",
  "acceptancetype",
  "block_id",
  "block_start_at",
  "block_end_at",
  "accepted_at",
  "load_id",
  "origin_yard_arrival_at",
  "destination_arrival_at",
  "on_time_status",
  "performance_score",
  "driver_rating",
];

/** Computed columns based on view type */
const tableColumns = computed(() => {
  return filters.value.viewType === 'rejections' 
    ? rejectionColumns 
    : acceptanceColumns;
});

// Determine which columns actually have data
const visibleColumns = computed(() => {
  const data = filteredData.value;
  if (data.length === 0) return tableColumns.value;
  
  // Always show these core columns even if empty
  const alwaysShow = [
    'date', 
    'drivername', 
    'rejectiontype', 
    'disputed', 
    'drivercontrollable', 
    'carrier_controllable', 
    'penalty'
  ];
  
  return tableColumns.value.filter(col => {
    // Always show core columns
    if (alwaysShow.includes(col)) return true;
    
    // Check if any row has data for this column
    return data.some(item => {
      const value = item[col];
      // Check for null, undefined, empty string, or placeholder
      return value !== null && 
             value !== undefined && 
             value !== '' && 
             value !== '—' && 
             value !== 'N/A';
    });
  });
});


const page = usePage();

/** --- Import Modal state --- */
const showImportModal = ref(false);
const importValidationResults = ref(null);
const isValidating = ref(false);
const isImporting = ref(false);

/** Drag & drop state */
const importFileInput = ref(null);
const isDragging = ref(false);
let dragDepth = 0;


const selectedTenantId = ref(null)
const tenants = props.tenants || []  // From controller
const isSuperAdmin = props.isSuperAdmin
/** Prevent browser from opening the file if dropped outside the dropzone */
onMounted(() => {
  const prevent = (e) => e.preventDefault();
  window.addEventListener("dragover", prevent);
  window.addEventListener("drop", prevent);

  onUnmounted(() => {
    window.removeEventListener("dragover", prevent);
    window.removeEventListener("drop", prevent);
  });
});

/** Week number label */
const weekNumberText = computed(() => {
  if (
    (activeTab.value === "yesterday" || activeTab.value === "current-week") &&
    props.weekNumber &&
    props.year
  ) {
    return `Week ${props.weekNumber}, ${props.year}`;
  }

  if (
    (activeTab.value === "6w" || activeTab.value === "quarterly") &&
    props.startWeekNumber &&
    props.endWeekNumber &&
    props.year
  ) {
    return `Weeks ${props.startWeekNumber}-${props.endWeekNumber}, ${props.year}`;
  }

  return "";
});



/** Breadcrumbs */
const breadcrumbs = [
  {
    title: props.tenantSlug ? "Dashboard" : "Admin Dashboard",
    href: props.tenantSlug
      ? route("dashboard", { tenantSlug: props.tenantSlug })
      : route("admin.dashboard"),
  },
  {
    title: "Acceptance",
    href: props.tenantSlug
      ? route("acceptance.index", { tenantSlug: props.tenantSlug })
      : route("acceptance.index.admin"),
  },
];

/** Page state */
const formModal = ref(false);
const codeModal = ref(false);
const selectedRejection = ref(null);
const errorMessage = ref("");
const successMessage = ref("");
const activeTab = ref(props.dateFilter || "full");
const perPage = ref(props.perPage || 10);
const selectedRejections = ref([]);
const showDeleteSelectedModal = ref(false);
const exportForm = ref(null);
const showFilters = ref(false);
const showDeleteModal = ref(false);
const rejectionToDelete = ref(null);

/** Code management */
const showCodeForm = ref(false);
const editingCode = ref(null);
const codeForm = ref({ reason_code: "", description: "" });
const codeDeleteConfirm = ref(false);
const codeToDelete = ref(null);

// /** Columns */
// const tableColumns = [
//   "date",
//   "drivername",
//   "rejectiontype",

//   // Advanced-only fields
//   "advanced_block_id",
//   "impacted_blocks",

//   // Block-only fields
//   "block_id",
//   "block_start_at",
//   "block_end_at",
//   "rejected_at",
//   "bucket",

//   // Load-only fields
//   "load_id",
//   "origin_yard_arrival_at",
//   "rejection_bucket",

//   // Shared flags
//   "disputed",
//   "drivercontrollable",
//   "carrier_controllable",
//   "penalty",
// ];

/** Sorting */
const sortColumn = ref("date");
const sortDirection = ref("desc");

/** Filters */
const filters = ref({ 
  search: "",
  rejectionType: "",
  reasonCode: "",
  rejectionCategory: "",
  disputeStatus: "",  // Changed from 'disputed'
  controllable: [],  // Changed from string to array
  viewType: 'rejections',
  ...props.filters ,
});
console.log('Initialized filters:', filters.value);
// Make sure to convert props.filters.controllable to array if it exists
if (props.filters?.controllable && !Array.isArray(props.filters.controllable)) {
  filters.value.controllable = props.filters.controllable ? [props.filters.controllable] : [];
}

// Add a watcher to sync with props when they change
watch(() => props.filters, (newFilters) => {
  if (newFilters && Object.keys(newFilters).length > 0) {
    console.log('Syncing filters with props:', newFilters);
    filters.value = {
      ...filters.value,
      ...newFilters
    };
  }
}, { deep: true, immediate: true });


watch(
  () => filters.value.rejectionType,
  (newType, oldType) => {
    if (newType !== oldType && filters.value.rejectionCategory) {
      const blockCategories = [
        "advanced_rejection",
        "more_than_24",
        "within_24",
        "after_start",
      ];
      const loadCategories = ["more_than_6", "within_6", "after_start"];

      if (
        newType === "block" &&
        !blockCategories.includes(filters.value.rejectionCategory)
      ) {
        filters.value.rejectionCategory = "";
      }

      if (
        newType === "load" &&
        !loadCategories.includes(filters.value.rejectionCategory)
      ) {
        filters.value.rejectionCategory = "";
      }
    }
  }
);

// /** Filtered & sorted */
// const filteredRejections = computed(() => {
//   let result = [...props.rejections.data];

//   result.sort((a, b) => {
//     let valA = a[sortColumn.value];
//     let valB = b[sortColumn.value];

//     if (sortColumn.value === "reason_code") {
//       valA = a.reason_code?.reason_code || "";
//       valB = b.reason_code?.reason_code || "";
//     }

//     if (valA === null) return 1;
//     if (valB === null) return -1;

//     if (typeof valA === "string") {
//       valA = valA.toLowerCase();
//       valB = valB.toLowerCase();
//     }

//     if (valA < valB) return sortDirection.value === "asc" ? -1 : 1;
//     if (valA > valB) return sortDirection.value === "asc" ? 1 : -1;
//     return 0;
//   });

//   return result;
// });

// Add these with your other refs (around line 400-500)
const selectedTripsFile = ref(null);
const isTripsDragging = ref(false);
let tripsDragDepth = 0;
/** Filtered & sorted data based on view type */
const filteredData = computed(() => {
  // Choose the right data source based on view type
  let result = filters.value.viewType === 'rejections' 
    ? [...(props.rejections?.data || [])]
    : [...(props.acceptances?.data || [])];

  // Apply sorting
  result.sort((a, b) => {
    let valA = a[sortColumn.value];
    let valB = b[sortColumn.value];

    if (sortColumn.value === "reason_code") {
      valA = a.reason_code?.reason_code || "";
      valB = b.reason_code?.reason_code || "";
    }

    if (valA === null) return 1;
    if (valB === null) return -1;

    if (typeof valA === "string") {
      valA = valA.toLowerCase();
      valB = valB.toLowerCase();
    }

    if (valA < valB) return sortDirection.value === "asc" ? -1 : 1;
    if (valA > valB) return sortDirection.value === "asc" ? 1 : -1;
    return 0;
  });

  return result;
});
/** Trips file handlers */
function onTripsInputChange(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  
  validateTripsFile(file);
  event.target.value = ""; // reset
}

function validateTripsFile(file) {
  const isCsv = file.type === "text/csv" || file.name.toLowerCase().endsWith(".csv");
  if (!isCsv) {
    errorMessage.value = "Trips file must be a CSV file.";
    setTimeout(() => (errorMessage.value = ""), 4000);
    return;
  }
  
  selectedTripsFile.value = file;
  successMessage.value = `Trips file selected: ${file.name}`;
  setTimeout(() => (successMessage.value = ""), 3000);
}

function onTripsDragEnter() {
  tripsDragDepth += 1;
  isTripsDragging.value = true;
}

function onTripsDragOver() {
  isTripsDragging.value = true;
}

function onTripsDragLeave() {
  tripsDragDepth -= 1;
  if (tripsDragDepth <= 0) {
    tripsDragDepth = 0;
    isTripsDragging.value = false;
  }
}

function onTripsDrop(e) {
  tripsDragDepth = 0;
  isTripsDragging.value = false;
  
  const file = e.dataTransfer?.files?.[0];
  if (!file) return;
  
  validateTripsFile(file);
}

function clearTripsFile() {
  selectedTripsFile.value = null;
  if (importFileInput.value) {
    importFileInput.value.value = "";
  }
}
// Keep filteredRejections for backward compatibility in other parts of the code
const filteredRejections = computed(() => {
  return filters.value.viewType === 'rejections' ? filteredData.value : [];
});

function sortBy(column) {
  if (sortColumn.value === column) {
    sortDirection.value = sortDirection.value === "asc" ? "desc" : "asc";
  } else {
    sortColumn.value = column;
    sortDirection.value = "asc";
  }
}

function applyFilters() {
  const routeName = props.tenantSlug
    ? route("acceptance.index", { tenantSlug: props.tenantSlug })
    : route("acceptance.index.admin");

  // Create params object with all filters
  const params = {
    ...filters.value,
    perPage: perPage.value,
    dateFilter: activeTab.value,
    viewType: filters.value.viewType, // Explicitly include viewType
  };
  
  console.log('Applying filters with params:', params);
  
  router.get(routeName, params, {
    preserveState: true,
    preserveScroll: true,
  });
}

// Watch for viewType changes to auto-apply filters
watch(() => filters.value.viewType, () => {
  applyFilters();
});

function setViewType(type) {
  if (filters.value.viewType === type) {
    console.log('View type already set to:', type);
    return;
  }
  
  console.log('Changing view type from', filters.value.viewType, 'to', type);
  filters.value.viewType = type;
  
  // Clear rejection-specific filters when switching to acceptance
 if (type === 'acceptance') {
  filters.value.rejectionType = '';
  filters.value.reasonCode = '';
  filters.value.rejectionCategory = '';
  filters.value.disputeStatus = '';     // ✅ Fixed
  filters.value.controllable = [];      // ✅ Fixed - reset to empty array
}
  
  applyFilters();
}


function resetFilters() {
  filters.value = {
    search: "",
    dateFrom: "",
    dateTo: "",
    rejectionType: "",
    reasonCode: "",
    rejectionCategory: "",
    disputeStatus: "",  // Changed
    controllable: [],  // Changed to empty array
    viewType: filters.value.viewType, // Keep the current view type
  };
  applyFilters();
}

/** Modals */
const openForm = (rejection = null) => {
  selectedRejection.value = rejection;
  formModal.value = true;
};

const openCodeModal = () => {
  codeModal.value = true;
  showCodeForm.value = false;
  editingCode.value = null;
};

/** Code manager */
const openNewCodeForm = () => {
  codeForm.value = { reason_code: "", description: "" };
  editingCode.value = null;
  showCodeForm.value = true;
};

const editCode = (code) => {
  codeForm.value = {
    reason_code: code.reason_code,
    description: code.description || "",
  };
  editingCode.value = code.id;
  showCodeForm.value = true;
};

const cancelCodeEdit = () => {
  showCodeForm.value = false;
  editingCode.value = null;
};

const confirmDeleteCode = (id) => {
  codeToDelete.value = id;
  codeDeleteConfirm.value = true;
};

const saveCode = () => {
  const form = useForm({
    reason_code: codeForm.value.reason_code,
    description: codeForm.value.description,
  });

  const routeName = editingCode.value
    ? props.isSuperAdmin
      ? "rejection_reason_codes.update.admin"
      : "rejection_reason_codes.update"
    : props.isSuperAdmin
    ? "rejection_reason_codes.store.admin"
    : "rejection_reason_codes.store";

  const routeParams = editingCode.value
    ? props.isSuperAdmin
      ? { code: editingCode.value }
      : { tenantSlug: props.tenantSlug, code: editingCode.value }
    : props.isSuperAdmin
    ? {}
    : { tenantSlug: props.tenantSlug };

  const method = editingCode.value ? form.put : form.post;

  method.call(form, route(routeName, routeParams), {
    onSuccess: () => {
      successMessage.value = editingCode.value
        ? "Reason code updated successfully."
        : "Reason code created successfully.";
      showCodeForm.value = false;
      editingCode.value = null;
      router.reload({ only: ["rejection_reason_codes"] });
    },
    onError: (errors) => {
      console.error(errors);
    },
  });
};

/** Controllable dropdown state */
const showControllableDropdown = ref(false);

// Close dropdown when clicking outside
onMounted(() => {
  const handleClickOutside = (e) => {
    if (showControllableDropdown.value && !e.target.closest('.relative')) {
      showControllableDropdown.value = false;
    }
  };
  
  document.addEventListener('click', handleClickOutside);
  
  onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
  });
});

function toggleControllableDropdown() {
  showControllableDropdown.value = !showControllableDropdown.value;
}

function clearControllable() {
  filters.value.controllable = [];
}

// Display text for the button
const getControllableDisplayText = computed(() => {
  if (!filters.value.controllable || filters.value.controllable.length === 0) {
    return 'All';
  }
  
  const selected = filters.value.controllable;
  const labels = {
    carrier: 'Carrier',
    driver: 'Driver',
    none: 'Not',
    both: 'Both'
  };
  
  if (selected.length === 1) {
    return labels[selected[0]] || selected[0];
  }
  
  return `${selected.length} selected`;
});



const deleteCode = (id) => {
  const form = useForm({});
  const routeName = props.isSuperAdmin
    ? "rejection_reason_codes.destroy.admin"
    : "rejection_reason_codes.destroy";
  const routeParams = props.isSuperAdmin
    ? { id: id }
    : { tenantSlug: props.tenantSlug, code: id };

  form.delete(route(routeName, routeParams), {
    onSuccess: () => {
      successMessage.value = "Reason code deleted successfully.";
      codeDeleteConfirm.value = false;
      router.reload({ only: ["rejection_reason_codes"] });
    },
  });
};

const confirmDeleteRejection = (id) => {
  rejectionToDelete.value = id;
  showDeleteModal.value = true;
};

const deleteRejection = (id) => {
  const form = useForm({});
  const routeName = props.isSuperAdmin
    ? "acceptance.destroy.admin"
    : "acceptance.destroy";
  const routeParams = props.isSuperAdmin
    ? { rejection: id }
    : { tenantSlug: props.tenantSlug, rejection: id };

  form.delete(route(routeName, routeParams), {
    preserveScroll: true,
    onSuccess: () => {
      successMessage.value = "Rejection deleted successfully.";
      showDeleteModal.value = false;
    },
  });
};

/** Pagination */
const visitPage = (url) => {
  if (url) {
    const urlObj = new URL(url);
    const baseUrl = urlObj.origin + urlObj.pathname;

    router.get(baseUrl, {
      ...filters.value,
      perPage: perPage.value,
      dateFilter: activeTab.value,
      viewType: filters.value.viewType,
      page: urlObj.searchParams.get("page") || 1,
    }, {
      preserveState: true,
      preserveScroll: true,
    });
  }
}

/** Date filter */
function selectDateFilter(filter) {
  activeTab.value = filter;

  const routeName = props.tenantSlug
    ? route("acceptance.index", { tenantSlug: props.tenantSlug })
    : route("acceptance.index.admin");

  router.get(routeName, {
    ...filters.value,
    perPage: perPage.value,
    dateFilter: filter,
  });
}

/** Per page */
function changePerPage() {
  const routeName = props.tenantSlug
    ? route("acceptance.index", { tenantSlug: props.tenantSlug })
    : route("acceptance.index.admin");

  router.get(routeName, {
    ...filters.value,
    perPage: perPage.value,
    dateFilter: activeTab.value,
  });
}

/** Format date */
function formatDate(dateStr) {
  if (!dateStr) return "";
  const parts = dateStr.split("-");
  if (parts.length !== 3) return dateStr;
  const [year, month, day] = parts;
  return `${Number(month)}/${Number(day)}/${year}`;
}

// Format column names nicely
function formatColumnName(col) {
  const columnMap = {
    // Basic fields
    'date': 'Date',
    'drivername': 'Driver-name',
    'rejectiontype': 'Rejection-type',
    'acceptancetype': 'Acceptance-type',
    
    // Advanced fields
    'advanced_block_id': 'Advanced Block ID',
    'impacted_blocks': 'Impacted Blocks',
    
    // Block fields
    'block_id': 'Block ID',
    'block_start_at': 'Block Start',
    'block_end_at': 'Block End',
    'rejected_at': 'Rejected At',
    'bucket': 'Bucket',
    
    // Load fields
    'load_id': 'Load ID',
    'origin_yard_arrival_at': 'Origin Arrival',
    'rejection_bucket': 'Rejection Bucket',
    'reason': 'Reason',
    'destination_arrival_at': 'Destination Arrival',
    
    // Status fields
    'on_time_status': 'On Time Status',
    'performance_score': 'Performance',
    'driver_rating': 'Driver Rating',
    'accepted_at': 'Accepted At',
    
    // Control fields
    'disputed': 'Disputed',
    'drivercontrollable': 'Driver-Controllable',
    'carrier_controllable': 'Carrier Controllable',
    'penalty': 'Penalty',
    
    // Category fields
    'rejectioncategory': 'Rejection From Start',
  };
  
  // Return mapped name if it exists, otherwise format the original
  return columnMap[col] || col
    .replace(/_/g, " ")
    .split(" ")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}

function getColspan() {
  let base = visibleColumns.value.length + (isSuperAdmin ? 1 : 0);
  if (filters.value.viewType === 'rejections' && permissionNames.value.includes('acceptance.delete')) {
    base += 1; // Checkbox column
  }
  if (filters.value.viewType === 'rejections' && 
      (permissionNames.value.includes('acceptance.delete') || permissionNames.value.includes('acceptance.update'))) {
    base += 1; // Actions column
  }
  return base;
}

// Get current data source for pagination
const currentData = computed(() => {
  return filters.value.viewType === 'rejections' ? props.rejections : props.acceptances;
});


/** Auto-hide success */
watch(successMessage, (newValue) => {
  if (newValue) {
    setTimeout(() => {
      successMessage.value = "";
    }, 5000);
  }
});

/** Dashboard data */
const acceptanceMetrics = computed(() => {
  if (
    !props.rejection_breakdown?.by_category ||
    !props.rejection_breakdown?.by_category.total_rejections
  )
    return null;

  const categoryData = props.rejection_breakdown.by_category;
  const type = props.filters.rejectionType;

  if (type) {
    return {
      totalRejections: categoryData[`total_${type}_rejections`] || 0,
      afterStartCount: categoryData[`after_start_${type}_count`] || 0,
      moreThan24Count: categoryData[`more_than_24_${type}_count`] || 0,
      within24Count: categoryData[`within_24_${type}_count`] || 0,
      advancedRejectionCount: categoryData[`advanced_rejection_${type}_count`] || 0,
      moreThan6Count: categoryData[`more_than_6_${type}_count`] || 0,
      within6Count: categoryData[`within_6_${type}_count`] || 0,
      by_category: true,
    };
  } else {
    return {
      totalRejections: categoryData.total_rejections || 0,
      afterStartCount: categoryData.after_start_count || 0,
      moreThan24Count: categoryData.more_than_24_count || 0,
      within24Count: categoryData.within_24_count || 0,
      advancedRejectionCount: categoryData.advanced_rejection_count || 0,
      moreThan6Count: categoryData.more_than_6_count || 0,
      within6Count: categoryData.within_6_count || 0,
      by_category: true,
    };
  }
});

const bottomDrivers = computed(() => {
  if (props.filters.rejectionType == "load")
    return props.rejection_breakdown?.bottom_five_drivers.load || [];

  if (props.filters.rejectionType == "block")
    return props.rejection_breakdown?.bottom_five_drivers.block || [];

  return props.rejection_breakdown?.bottom_five_drivers.total || [];
});

const acceptanceChartData = computed(() => {
  if (!props.line_chart_data || props.line_chart_data.length === 0) {
    return {
      labels: [],
      datasets: [
        {
          label: "Acceptance Performance",
          data: [],
          borderColor: "#3b82f6",
          backgroundColor: "rgba(59, 130, 246, 0.1)",
          tension: 0.3,
        },
      ],
    };
  }

  return {
    labels: props.line_chart_data.map((item) => item.date),
    datasets: [
      {
        label: "Acceptance Performance",
        data: props.line_chart_data.map((item) => item.acceptancePerformance),
        borderColor: "#3b82f6",
        backgroundColor: "rgba(59, 130, 246, 0.1)",
        tension: 0.3,
      },
    ],
  };
});

/** Reason code soft delete helpers */
function restoreCode(id) {
  router.post(
    route("rejection_reason_codes.restore.admin", { id }),
    {},
    {
      onSuccess: () => {
        successMessage.value = "Reason code restored successfully";
        setTimeout(() => (successMessage.value = ""), 3000);
      },
    }
  );
}

function forceDeleteCode(id) {
  if (
    confirm(
      "Are you sure you want to permanently delete this reason code? This action cannot be undone."
    )
  ) {
    router.delete(route("rejection_reason_codes.forceDelete.admin", { id }), {
      onSuccess: () => {
        successMessage.value = "Reason code permanently deleted";
        setTimeout(() => (successMessage.value = ""), 3000);
      },
    });
  }
}

/** Select all rows */
const isAllSelected = computed(() => {
  return (
    filteredRejections.value.length > 0 &&
    selectedRejections.value.length === filteredRejections.value.length
  );
});

function toggleSelectAll(event) {
  if (event.target.checked) {
    selectedRejections.value = filteredRejections.value.map((r) => r.id);
  } else {
    selectedRejections.value = [];
  }
}

function confirmDeleteSelected() {
  if (selectedRejections.value.length > 0) {
    showDeleteSelectedModal.value = true;
  }
}

function deleteSelectedRejections() {
  const form = useForm({ ids: selectedRejections.value });

  const routeName = props.isSuperAdmin
    ? "acceptance.destroyBulk.admin"
    : "acceptance.destroyBulk";
  const routeParams = props.isSuperAdmin ? {} : { tenantSlug: props.tenantSlug };

  form.delete(route(routeName, routeParams), {
    preserveScroll: true,
    onSuccess: () => {
      successMessage.value = `${selectedRejections.value.length} rejection records deleted successfully.`;
      selectedRejections.value = [];
      showDeleteSelectedModal.value = false;
    },
    onError: (errors) => {
      console.error(errors);
    },
  });
}

/** ✅ Import: input change */
function onImportInputChange(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  handleImportFile(file);

  // reset so choosing same file again fires change
  event.target.value = "";
}



/** ✅ Import: shared handler (drop + input) */
function handleImportFile(file) {
  if (!file) return;

  const isCsv =
    file.type === "text/csv" ||
    file.name.toLowerCase().endsWith(".csv") ||
    file.type === "";

  if (!isCsv) {
    errorMessage.value = "Please upload a valid CSV file.";
    setTimeout(() => (errorMessage.value = ""), 4000);
    return;
  }

  isValidating.value = true;

  const formData = new FormData();
  formData.append("file", file);

  const endpoint = props.isSuperAdmin
    ? route("acceptance.validateImport.admin")
    : route("acceptance.validateImport", { tenantSlug: props.tenantSlug });

  router.post(endpoint, formData, {
    forceFormData: true,
    preserveScroll: true,
    only: ["flash"],
    onFinish: () => {
      isValidating.value = false;
    },
    onError: () => {
      isValidating.value = false;
      errorMessage.value = "Failed to validate CSV file";
    },
  });
}

/** ✅ Drag handlers */
function onDragEnter() {
  dragDepth += 1;
  isDragging.value = true;
}

function onDragOver() {
  isDragging.value = true;
}

function onDragLeave() {
  dragDepth -= 1;
  if (dragDepth <= 0) {
    dragDepth = 0;
    isDragging.value = false;
  }
}

function onDrop(e) {
  dragDepth = 0;
  isDragging.value = false;

  const file = e.dataTransfer?.files?.[0];
  if (!file) return;

  handleImportFile(file);
}

/** Confirm import */
/** Confirm import */
async function confirmImport() {
  if (!importValidationResults.value) return;
  if ((importValidationResults.value.summary?.invalid ?? 0) > 0) return;
  if (importValidationResults.value.header_error) return;

  // Safe tenant_id extraction
  let tenantId = null
  if (props.isSuperAdmin) {
    if (!selectedTenantId.value) {
      errorMessage.value = "Please select a tenant!";
      return;
    }
    tenantId = selectedTenantId.value
  } else {
    tenantId = props.tenantId
  }

  if (!tenantId) {
    errorMessage.value = "No tenant available!";
    return;
  }

  isImporting.value = true;

  // Create FormData with session file + tenant_id + optional trips file
  const formData = new FormData();
  formData.append('tenant_id', tenantId.toString());
  formData.append('format', 'new');  // or detect from session
  
  // 🔥 NEW: Append trips file if selected
  if (selectedTripsFile.value) {
    formData.append('trips_file', selectedTripsFile.value);
    console.log('Appending trips file:', selectedTripsFile.value.name);
  }

  const endpoint = props.isSuperAdmin
    ? route("acceptance.confirmImport.admin")
    : route("acceptance.confirmImport", { tenantSlug: props.tenantSlug });

  try {
    await router.post(endpoint, formData, {
      preserveScroll: true,
      forceFormData: true,  // CRITICAL for FormData!
      onSuccess: () => {
        successMessage.value = `Imported ${importValidationResults.value.summary?.valid ?? 0} rejections`;
        if (selectedTripsFile.value) {
          successMessage.value += ' with driver mapping';
        }
        closeImportModal();
      },
      onError: (errors) => {
        errorMessage.value = errors.message || "Import failed";
      },
      onFinish: () => {
        isImporting.value = false;
      },
    });
  } catch (error) {
    console.error('Import error:', error);
  }
}

function downloadErrorReport() {
  const endpoint = props.isSuperAdmin
    ? route("acceptance.downloadErrorReport.admin")
    : route("acceptance.downloadErrorReport", { tenantSlug: props.tenantSlug });

  window.location.href = endpoint;
}

function closeImportModal() {
  showImportModal.value = false;
  importValidationResults.value = null;
  isValidating.value = false;
  isImporting.value = false;

  // reset drag UI state
  isDragging.value = false;
  dragDepth = 0;
  
  // 🔥 NEW: Clear trips file
  selectedTripsFile.value = null;
  isTripsDragging.value = false;
  tripsDragDepth = 0;

  // clear file input if possible
  if (importFileInput.value) importFileInput.value.value = "";
  
  // clear trips file input if it exists
  const tripsInput = document.querySelector('input[ref="tripsFileInput"]');
  if (tripsInput) tripsInput.value = "";
}

function exportCSV() {
  if (filteredRejections.value.length === 0) {
    errorMessage.value = "No data available to export";
    setTimeout(() => {
      errorMessage.value = "";
    }, 3000);
    return;
  }

  if (exportForm.value) {
    exportForm.value.submit();
  }
}

/** Export URL */
const exportUrl = computed(() => {
  return props.tenantSlug
    ? route("acceptance.export", { tenantSlug: props.tenantSlug })
    : route("acceptance.export.admin");
});

/** Template URL */
const templateUrl = computed(() => {
  return "/storage/upload-data-temps/Rejections Template.csv";
});

/** Close dropdown when clicking outside (kept from your original) */
const showUploadOptions = ref(false);

onMounted(() => {
  const handleClickOutside = (e) => {
    if (showUploadOptions.value && !e.target.closest(".relative")) {
      showUploadOptions.value = false;
    }
  };

  document.addEventListener("click", handleClickOutside);

  onUnmounted(() => {
    document.removeEventListener("click", handleClickOutside);
  });
});

/** Active filters */
const hasActiveFilters = computed(() => {
  return (
    filters.value.search ||
    filters.value.dateFrom ||
    filters.value.dateTo ||
    filters.value.rejectionType ||
    filters.value.reasonCode ||
    filters.value.rejectionCategory ||
    filters.value.disputeStatus ||
    (filters.value.controllable && filters.value.controllable.length > 0)  // Changed
  );
});

function getReasonCodeLabel(codeId) {
  if (!codeId) return "";
  const code = props.rejection_reason_codes.find((c) => c.id == codeId);
  return code ? code.reason_code : "";
}

function getRejectionCategoryLabel(category) {
  if (!category) return "—";

  const labels = {
    more_than_6: "More than 6 hrs",
    within_6: "Within 6 hrs",
    after_start: "After start",
    more_than_24: "More than 24 hrs",
    within_24: "Within 24 hrs",
    advanced_rejection: "Advanced Rejection",
  };

  return labels[category] || category;
}

const permissionNames = computed(() => props.permissions.map((p) => p.name));

/** Listen for server validation payload */
watch(
  () => page.props.flash?.importValidation,
  (payload) => {
    if (!payload) return;

    if (payload.results) {
      importValidationResults.value = payload.results;

      if (payload.header_error) {
        importValidationResults.value.header_error = payload.header_error;
      }

      showImportModal.value = true;
      return;
    }

    if (payload.message) {
      errorMessage.value = payload.message;
    }
  },
  { immediate: true }
);
</script>
