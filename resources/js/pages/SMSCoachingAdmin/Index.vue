<script setup>
import { computed, watch, ref, nextTick } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import {
    TrendingUp,
    TrendingDown,
    ChevronDown,
    X,
    MessageSquare,
    SlidersHorizontal,
    Search,
    RotateCcw,
    Eye,
} from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    Card,
    CardHeader,
    CardTitle,
    CardContent,
    Button,
    Input,
    Label,
    Badge,
    Separator,
    Tabs,
    TabsList,
    TabsTrigger,
    TabsContent,
    AlertDialog,
    AlertDialogContent,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogAction,
    AlertDialogCancel,
    Checkbox,
    Dialog,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui';
import { DialogScrollContent } from '@/components/ui/dialog';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { useToast } from '@/components/ui/toast/use-toast';

const props = defineProps({
    permissions: Array,
    tenants: Array,
    metrics: Array,
    globalMessages: Object,
    globalThresholds: Object,
    messageOverrides: Array,
    thresholdOverrides: Array,
    placeholders: Array,
});

const breadcrumbs = computed(() => [
    { title: 'Admin Dashboard', href: route('admin.dashboard') },
    { title: 'SMS Coaching', href: route('admin.sms-coaching.index') },
]);

const statusOptions = [
    { value: 'good', label: 'Good' },
    { value: 'minor_improvement', label: 'Needs Improvement' },
    { value: 'bad', label: 'Bad' },
];

const activeTab = ref('configure');

const thresholdMetrics = computed(() =>
    (props.metrics || []).filter((metric) => metric.thresholds)
);

const selectedGlobalMetricKey = ref('general');
const selectedGlobalMetric = computed(() =>
    thresholdMetrics.value.find((m) => m.key === selectedGlobalMetricKey.value) || null
);

const selectedOverrideType = ref('message');

const viewingMessage = ref(null);

const clone = (value, fallback) => JSON.parse(JSON.stringify(value || fallback));

const globalMessagesForm = useForm({
    messages: clone(props.globalMessages, {}),
});

const globalThresholdsForm = useForm({
    thresholds: clone(props.globalThresholds, {}),
});

const messageOverrideForm = useForm({
    tenant_ids: [],
    metric_key: 'general',
    status: '',
    message: '',
});

const thresholdOverrideForm = useForm({
    tenant_ids: [],
    metric_key: '',
    good: '',
    minor_improvement: '',
    bad: '',
});

const { toast } = useToast();

const placeholderQuery = ref('');
const activeField = ref(null);
const activeEditor = ref(null);
const pendingDelete = ref(null);
const messageTenantsOpen = ref(false);
const thresholdTenantsOpen = ref(false);
const editorRefs = ref({});

// Clear active editor state when switching metrics to avoid stale refs causing freeze
watch(selectedGlobalMetricKey, () => {
    activeField.value = null;
    activeEditor.value = null;
});

watch(
    () => messageOverrideForm.metric_key,
    (metric) => {
        if (metric === 'general') {
            messageOverrideForm.status = '';
        } else if (!messageOverrideForm.status) {
            messageOverrideForm.status = 'good';
        }
    }
);

const ensureGlobalMessagePath = (metricKey, statusKey) => {
    if (!globalMessagesForm.messages) globalMessagesForm.messages = {};
    if (!globalMessagesForm.messages[metricKey]) globalMessagesForm.messages[metricKey] = {};
    if (typeof globalMessagesForm.messages[metricKey][statusKey] !== 'string') {
        globalMessagesForm.messages[metricKey][statusKey] = '';
    }
};

const ensureGlobalThresholdPath = (metricKey) => {
    if (!globalThresholdsForm.thresholds) globalThresholdsForm.thresholds = {};
    if (!globalThresholdsForm.thresholds[metricKey]) {
        globalThresholdsForm.thresholds[metricKey] = { good: '', minor_improvement: '', bad: '' };
    }
};

watch(
    thresholdMetrics,
    (metrics) => {
        ensureGlobalMessagePath('general', 'general');
        metrics.forEach((metric) => {
            ensureGlobalThresholdPath(metric.key);
            statusOptions.forEach((status) => ensureGlobalMessagePath(metric.key, status.value));
        });
    },
    { immediate: true }
);

const submitGlobalMessages = () => {
    globalMessagesForm.post(route('admin.sms-coaching.messages.global'), {
        preserveScroll: true,
        onSuccess: () => toast({ title: 'Saved', description: 'Global messages updated.' }),
    });
};

const submitGlobalThresholds = () => {
    globalThresholdsForm.post(route('admin.sms-coaching.thresholds.global'), {
        preserveScroll: true,
        onSuccess: () => toast({ title: 'Saved', description: 'Global thresholds updated.' }),
    });
};

const submitMessageOverride = () => {
    messageOverrideForm.post(route('admin.sms-coaching.messages.overrides'), {
        preserveScroll: true,
        onSuccess: () => {
            messageOverrideForm.reset('message');
            const editor = editorRefs.value.override;
            if (editor) editor.innerHTML = '';
            toast({ title: 'Saved', description: 'Message override saved.' });
        },
    });
};

const submitThresholdOverride = () => {
    thresholdOverrideForm.post(route('admin.sms-coaching.thresholds.overrides'), {
        preserveScroll: true,
        onSuccess: () => {
            thresholdOverrideForm.reset('good', 'minor_improvement', 'bad');
            toast({ title: 'Saved', description: 'Threshold override saved.' });
        },
    });
};

const confirmDelete = (type, override) => {
    pendingDelete.value = { type, override };
};

const executePendingDelete = () => {
    if (!pendingDelete.value) return;
    const { type, override } = pendingDelete.value;
    const routeName = type === 'message'
        ? 'admin.sms-coaching.messages.overrides.delete'
        : 'admin.sms-coaching.thresholds.overrides.delete';
    const param = type === 'message' ? { message: override.id } : { threshold: override.id };
    router.delete(route(routeName, param), {
        preserveScroll: true,
        onSuccess: () => toast({
            title: 'Removed',
            description: `${type === 'message' ? 'Message' : 'Threshold'} override removed.`,
        }),
    });
    pendingDelete.value = null;
};

const normalizeTenantId = (id) => {
    const number = Number(id);
    return Number.isNaN(number) ? id : number;
};

const isTenantSelected = (form, id) => {
    const normalizedId = normalizeTenantId(id);
    return form.tenant_ids.some((tenantId) => normalizeTenantId(tenantId) === normalizedId);
};

const setTenantSelected = (form, id, checked) => {
    const normalizedId = normalizeTenantId(id);
    const selected = isTenantSelected(form, normalizedId);
    if (checked && !selected) form.tenant_ids.push(normalizedId);
    if (!checked && selected) {
        form.tenant_ids = form.tenant_ids.filter(
            (tenantId) => normalizeTenantId(tenantId) !== normalizedId
        );
    }
};

const toggleTenant = (form, id) => setTenantSelected(form, id, !isTenantSelected(form, id));

const tenantsLabel = (ids) => {
    if (!ids?.length) return 'Select tenants...';
    if (ids.length === 1) {
        const selectedId = normalizeTenantId(ids[0]);
        return (props.tenants || []).find(
            (tenant) => normalizeTenantId(tenant.id) === selectedId
        )?.name ?? '1 tenant';
    }
    return `${ids.length} tenants selected`;
};

const charCount = (val) => (val || '').length;

const charCountClass = (count) =>
    count <= 160
        ? 'text-green-600 dark:text-green-400'
        : count <= 320
            ? 'text-amber-600 dark:text-amber-400'
            : 'text-red-600 dark:text-red-400';

const charSegments = (count) =>
    count <= 160 ? 1 : count <= 320 ? 2 : Math.ceil(count / 153);

const statusVariant = (status) =>
    status === 'good' ? 'success'
        : status === 'minor_improvement' ? 'warning'
            : status === 'bad' ? 'destructive'
                : 'secondary';

const formatMetric = (key) => {
    const metric = (props.metrics || []).find((item) => item.key === key);
    return metric ? metric.label : key;
};

const formatStatus = (value) => {
    if (!value) return 'General';
    const found = statusOptions.find((option) => option.value === value);
    return found ? found.label : value;
};

const placeholderByValue = computed(() => {
    const map = {};
    (props.placeholders || []).forEach((placeholder) => { map[placeholder.value] = placeholder; });
    return map;
});

const placeholderGroups = computed(() => {
    const query = placeholderQuery.value.trim().toLowerCase();
    const items = (props.placeholders || []).filter((ph) => {
        const value = (ph.value || '').toLowerCase();
        const label = (ph.label || '').toLowerCase();
        return !query || value.includes(query) || label.includes(query);
    });
    const isDriver = (ph) => (ph.value || '').startsWith('{driver_');
    const isCompany = (ph) => (ph.value || '').startsWith('{company_');
    const isThreshold = (ph) => (ph.value || '').startsWith('{threshold_');
    const isDate = (ph) => (ph.value || '').startsWith('{date_');
    const groups = [
        { key: 'driver', label: 'Driver Metrics', items: items.filter(isDriver) },
        { key: 'company', label: 'Company Averages', items: items.filter(isCompany) },
        { key: 'thresholds', label: 'Thresholds', items: items.filter(isThreshold) },
        { key: 'date', label: 'Date Range', items: items.filter(isDate) },
    ];
    const used = new Set(groups.flatMap((group) => group.items.map((item) => item.value)));
    const otherItems = items.filter((item) => !used.has(item.value));
    if (otherItems.length) groups.push({ key: 'other', label: 'Other', items: otherItems });
    return groups.filter((group) => group.items.length > 0);
});

const escapeHtml = (text) =>
    String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

const placeholderLabel = (value) => placeholderByValue.value[value]?.label || value;

const placeholderChipClass = 'placeholder-token inline-flex select-none items-center rounded-md border border-primary/25 bg-primary/10 px-1.5 py-0.5 align-baseline font-mono text-xs font-medium text-primary';

const renderMessageHtml = (text) => {
    const value = String(text || '');
    if (!value) return '';
    const parts = value.split(/(\{[^}]+\})/g);
    return parts.map((part) => {
        if (/^\{[^}]+\}$/.test(part)) {
            const label = placeholderLabel(part);
            return `<span contenteditable="false" dir="ltr" data-placeholder-value="${escapeHtml(part)}" class="${placeholderChipClass}" title="${escapeHtml(part)}">${escapeHtml(label)}</span>`;
        }
        return escapeHtml(part).replace(/\n/g, '<br>');
    }).join('');
};

const serializeEditorNode = (node) => {
    if (!node) return '';
    if (node.nodeType === Node.TEXT_NODE) return node.textContent || '';
    if (node.nodeType !== Node.ELEMENT_NODE) return '';
    const element = node;
    if (element.dataset?.placeholderValue) return element.dataset.placeholderValue;
    if (element.tagName === 'BR') return '\n';
    let text = '';
    element.childNodes.forEach((child) => { text += serializeEditorNode(child); });
    if (element.tagName === 'DIV' || element.tagName === 'P') text += '\n';
    return text;
};

const serializeEditor = (editor) => {
    if (!editor) return '';
    let text = '';
    editor.childNodes.forEach((node) => { text += serializeEditorNode(node); });
    return text.replace(/\n$/, '');
};

const getMessageValue = (field) => {
    if (!field) return '';
    if (field.type === 'global') return globalMessagesForm.messages?.[field.metricKey]?.[field.statusKey] || '';
    if (field.type === 'override') return messageOverrideForm.message || '';
    return '';
};

const setMessageValue = (field, nextValue) => {
    if (!field) return;
    if (field.type === 'global') {
        ensureGlobalMessagePath(field.metricKey, field.statusKey);
        globalMessagesForm.messages[field.metricKey][field.statusKey] = nextValue;
        return;
    }
    if (field.type === 'override') messageOverrideForm.message = nextValue;
};

const fieldKey = (field) => {
    if (!field) return '';
    if (field.type === 'override') return 'override';
    return `${field.type}:${field.metricKey}:${field.statusKey}`;
};

const setEditorRef = (field, el) => {
    if (!field || !el) return;
    const key = fieldKey(field);
    editorRefs.value[key] = el;
    nextTick(() => {
        if (activeEditor.value !== el) el.innerHTML = renderMessageHtml(getMessageValue(field));
    });
};

const setActiveEditor = (field, event) => {
    activeField.value = field;
    activeEditor.value = event?.currentTarget || null;
};

const syncEditorToForm = (field, editor) => setMessageValue(field, serializeEditor(editor));

const handleEditorInput = (field, event) => {
    setActiveEditor(field, event);
    syncEditorToForm(field, event.currentTarget);
};

const insertPlainTextAtSelection = (text) => {
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount) return;
    const range = selection.getRangeAt(0);
    range.deleteContents();
    const lines = text.split(/\r\n|\r|\n/);
    lines.forEach((line, index) => {
        if (index > 0) {
            range.insertNode(document.createElement('br'));
            range.collapse(false);
        }
        if (line) {
            const textNode = document.createTextNode(line);
            range.insertNode(textNode);
            range.setStartAfter(textNode);
            range.setEndAfter(textNode);
        }
    });
    selection.removeAllRanges();
    selection.addRange(range);
};

const handleEditorPaste = (field, event) => {
    event.preventDefault();
    const text = event.clipboardData?.getData('text/plain') || '';
    if (!text) return;
    insertPlainTextAtSelection(text);
    syncEditorToForm(field, event.currentTarget);
};

const activeFieldLabel = computed(() => {
    if (!activeField.value) return 'Click a message field to activate.';
    if (activeField.value.type === 'override') return 'Tenant Override Message';
    if (activeField.value.metricKey === 'general') return 'General Message';
    return `${formatMetric(activeField.value.metricKey)} · ${formatStatus(activeField.value.statusKey)}`;
});

const createPlaceholderChip = (placeholderValue) => {
    const chip = document.createElement('span');
    chip.contentEditable = 'false';
    chip.dir = 'ltr';
    chip.dataset.placeholderValue = placeholderValue;
    chip.title = placeholderValue;
    chip.textContent = placeholderLabel(placeholderValue);
    chip.className = placeholderChipClass;
    return chip;
};

const selectionIsInside = (container) => {
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount || !container) return false;
    const node = selection.anchorNode;
    return node === container || container.contains(node);
};

const moveCaretToEnd = (element) => {
    const range = document.createRange();
    const selection = window.getSelection();
    range.selectNodeContents(element);
    range.collapse(false);
    selection.removeAllRanges();
    selection.addRange(range);
};

const insertPlaceholder = (placeholderValue) => {
    if (!activeField.value || !activeEditor.value) return;
    const editor = activeEditor.value;
    editor.focus();
    if (!selectionIsInside(editor)) moveCaretToEnd(editor);
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount) return;
    const range = selection.getRangeAt(0);
    const chip = createPlaceholderChip(placeholderValue);
    const spacer = document.createTextNode(' ');
    range.deleteContents();
    range.insertNode(spacer);
    range.insertNode(chip);
    range.setStartAfter(spacer);
    range.setEndAfter(spacer);
    selection.removeAllRanges();
    selection.addRange(range);
    syncEditorToForm(activeField.value, editor);
    nextTick(() => { editor.focus(); });
};

const editorEvents = (field) => ({
    focus: (event) => setActiveEditor(field, event),
    click: (event) => setActiveEditor(field, event),
    keyup: (event) => setActiveEditor(field, event),
    mouseup: (event) => setActiveEditor(field, event),
    input: (event) => handleEditorInput(field, event),
    paste: (event) => handleEditorPaste(field, event),
});

const editorClass = 'sms-message-editor min-h-[84px] whitespace-pre-wrap break-words rounded-md border border-input bg-background px-3 py-2 text-start text-sm leading-relaxed text-foreground ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 empty:before:text-muted-foreground empty:before:content-[attr(data-placeholder)]';

const nativeSelectClass = 'h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';

const tenantNameById = (id) => {
    const normalized = normalizeTenantId(id);
    return (props.tenants || []).find((t) => normalizeTenantId(t.id) === normalized)?.name ?? `Tenant ${id}`;
};

const messageOverridesCount = computed(() => (props.messageOverrides || []).length);
const thresholdOverridesCount = computed(() => (props.thresholdOverrides || []).length);

const OVERRIDE_PAGE_SIZE = 8;

const messageOverrideFilters = ref({
    query: '',
    metric: 'all',
    status: 'all',
    visibleCount: OVERRIDE_PAGE_SIZE,
});

const thresholdOverrideFilters = ref({
    query: '',
    metric: 'all',
    visibleCount: OVERRIDE_PAGE_SIZE,
});

const normalizedText = (value) => String(value || '').toLowerCase().trim();

const compactSelectClass = 'h-9 w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';

const messageOverrideMetricOptions = computed(() => {
    const keys = new Set((props.messageOverrides || []).map((override) => override.metric_key));
    return [...keys].filter(Boolean).map((key) => ({ value: key, label: formatMetric(key) }))
        .sort((a, b) => a.label.localeCompare(b.label));
});

const thresholdOverrideMetricOptions = computed(() => {
    const keys = new Set((props.thresholdOverrides || []).map((override) => override.metric_key));
    return [...keys].filter(Boolean).map((key) => ({ value: key, label: formatMetric(key) }))
        .sort((a, b) => a.label.localeCompare(b.label));
});

const filteredMessageOverrides = computed(() => {
    const query = normalizedText(messageOverrideFilters.value.query);
    const metric = messageOverrideFilters.value.metric;
    const status = messageOverrideFilters.value.status;
    return [...(props.messageOverrides || [])]
        .filter((override) => {
            const matchesMetric = metric === 'all' || override.metric_key === metric;
            const matchesStatus = status === 'all' || (override.status || '') === status;
            const searchable = [
                override.tenant_name,
                formatMetric(override.metric_key),
                formatStatus(override.status),
                override.message,
            ].map(normalizedText).join(' ');
            return matchesMetric && matchesStatus && (!query || searchable.includes(query));
        })
        .sort((a, b) => {
            const c = String(a.tenant_name || '').localeCompare(String(b.tenant_name || ''));
            return c !== 0 ? c : String(formatMetric(a.metric_key)).localeCompare(String(formatMetric(b.metric_key)));
        });
});

const visibleMessageOverrides = computed(() =>
    filteredMessageOverrides.value.slice(0, messageOverrideFilters.value.visibleCount)
);
const hasMoreMessageOverrides = computed(() =>
    filteredMessageOverrides.value.length > visibleMessageOverrides.value.length
);

const filteredThresholdOverrides = computed(() => {
    const query = normalizedText(thresholdOverrideFilters.value.query);
    const metric = thresholdOverrideFilters.value.metric;
    return [...(props.thresholdOverrides || [])]
        .filter((override) => {
            const matchesMetric = metric === 'all' || override.metric_key === metric;
            const searchable = [
                override.tenant_name,
                formatMetric(override.metric_key),
                override.good,
                override.minor_improvement,
                override.bad,
            ].map(normalizedText).join(' ');
            return matchesMetric && (!query || searchable.includes(query));
        })
        .sort((a, b) => {
            const c = String(a.tenant_name || '').localeCompare(String(b.tenant_name || ''));
            return c !== 0 ? c : String(formatMetric(a.metric_key)).localeCompare(String(formatMetric(b.metric_key)));
        });
});

const visibleThresholdOverrides = computed(() =>
    filteredThresholdOverrides.value.slice(0, thresholdOverrideFilters.value.visibleCount)
);
const hasMoreThresholdOverrides = computed(() =>
    filteredThresholdOverrides.value.length > visibleThresholdOverrides.value.length
);

watch(
    () => [messageOverrideFilters.value.query, messageOverrideFilters.value.metric, messageOverrideFilters.value.status],
    () => { messageOverrideFilters.value.visibleCount = OVERRIDE_PAGE_SIZE; }
);
watch(
    () => [thresholdOverrideFilters.value.query, thresholdOverrideFilters.value.metric],
    () => { thresholdOverrideFilters.value.visibleCount = OVERRIDE_PAGE_SIZE; }
);

const showMoreMessageOverrides = () => { messageOverrideFilters.value.visibleCount += OVERRIDE_PAGE_SIZE; };
const showMoreThresholdOverrides = () => { thresholdOverrideFilters.value.visibleCount += OVERRIDE_PAGE_SIZE; };

const resetMessageOverrideFilters = () => {
    messageOverrideFilters.value = { query: '', metric: 'all', status: 'all', visibleCount: OVERRIDE_PAGE_SIZE };
};
const resetThresholdOverrideFilters = () => {
    thresholdOverrideFilters.value = { query: '', metric: 'all', visibleCount: OVERRIDE_PAGE_SIZE };
};

const hasActiveMessageOverrideFilters = computed(() =>
    messageOverrideFilters.value.query ||
    messageOverrideFilters.value.metric !== 'all' ||
    messageOverrideFilters.value.status !== 'all'
);
const hasActiveThresholdOverrideFilters = computed(() =>
    thresholdOverrideFilters.value.query ||
    thresholdOverrideFilters.value.metric !== 'all'
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :permissions="permissions">

        <Head title="SMS Coaching" />
        <div
            class="w-full md:max-w-2xl lg:max-w-3xl xl:max-w-6xl lg:mx-auto m-0 p-2 md:p-4 lg:p-6 space-y-2 md:space-y-4 lg:space-y-6">
            <div class="w-full max-w-6xl space-y-6">
                <div class="space-y-1">
                    <h1 class="text-2xl font-bold text-foreground">SMS Coaching</h1>
                    <p class="text-sm text-muted-foreground">
                        Configure global messages and thresholds per metric, then override them per tenant as needed.
                    </p>
                </div>

                <Tabs v-model="activeTab" default-value="configure" class="space-y-6">
                    <TabsList class="grid w-full grid-cols-2">
                        <TabsTrigger value="configure">Messages &amp; Thresholds</TabsTrigger>
                        <TabsTrigger value="overrides" class="gap-2">
                            Overrides
                            <span v-if="messageOverridesCount + thresholdOverridesCount"
                                class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/15 px-1.5 text-[10px] font-semibold text-primary">
                                {{ messageOverridesCount + thresholdOverridesCount }}
                            </span>
                        </TabsTrigger>
                    </TabsList>

                    <!-- ── Configure Tab ── -->
                    <TabsContent value="configure" force-mount :hidden="activeTab !== 'configure'">
                        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_288px]">
                            <div class="space-y-4">

                                <!-- Metric Picker -->
                                <div class="rounded-xl border bg-card p-4">
                                    <p
                                        class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Select metric to configure
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button"
                                            class="rounded-full border px-4 py-1.5 text-sm font-medium transition-all"
                                            :class="selectedGlobalMetricKey === 'general'
                                                ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                                : 'border-border bg-muted/50 text-muted-foreground hover:border-primary/40 hover:bg-primary/5 hover:text-foreground'"
                                            @click="selectedGlobalMetricKey = 'general'">
                                            General
                                        </button>
                                        <button v-for="metric in thresholdMetrics" :key="metric.key" type="button"
                                            class="rounded-full border px-4 py-1.5 text-sm font-medium transition-all"
                                            :class="selectedGlobalMetricKey === metric.key
                                                ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                                : 'border-border bg-muted/50 text-muted-foreground hover:border-primary/40 hover:bg-primary/5 hover:text-foreground'"
                                            @click="selectedGlobalMetricKey = metric.key">
                                            {{ metric.label }}
                                        </button>
                                    </div>
                                </div>

                                <!-- General Message — key forces fresh mount on metric switch -->
                                <Card v-if="selectedGlobalMetricKey === 'general'">
                                    <CardHeader>
                                        <CardTitle class="flex items-center gap-2">
                                            <MessageSquare class="h-4 w-4 text-muted-foreground" />
                                            General Message
                                        </CardTitle>
                                        <p class="text-sm text-muted-foreground">
                                            Sent to all drivers before threshold-based messages. 1 SMS segment = 160
                                            chars.
                                        </p>
                                    </CardHeader>
                                    <CardContent class="space-y-4">
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between">
                                                <Label>Message</Label>
                                                <p class="text-xs"
                                                    :class="charCountClass(charCount(globalMessagesForm.messages.general?.general))">
                                                    {{ charCount(globalMessagesForm.messages.general?.general) }} chars
                                                    ·
                                                    {{
                                                        charSegments(charCount(globalMessagesForm.messages.general?.general))
                                                    }}
                                                    segment(s)
                                                </p>
                                            </div>
                                            <div :ref="(el) => setEditorRef({ type: 'global', metricKey: 'general', statusKey: 'general' }, el)"
                                                :class="editorClass" contenteditable="true" dir="auto" inputmode="text"
                                                role="textbox" aria-multiline="true"
                                                data-placeholder="General message sent before thresholds"
                                                v-on="editorEvents({ type: 'global', metricKey: 'general', statusKey: 'general' })" />
                                        </div>
                                        <Button type="button" @click="submitGlobalMessages"
                                            :disabled="globalMessagesForm.processing">
                                            Save General Message
                                        </Button>
                                    </CardContent>
                                </Card>

                                <!-- Metric-specific — key forces re-mount when metric changes, preventing frozen state -->
                                <div v-if="selectedGlobalMetric" class="space-y-4">
                                    <div class="flex flex-wrap items-center gap-2 px-1">
                                        <h2 class="text-base font-semibold text-foreground">
                                            {{ selectedGlobalMetric.label }}
                                        </h2>
                                        <Badge v-if="selectedGlobalMetric.direction === 'high'" variant="outline"
                                            class="gap-1">
                                            <TrendingUp class="h-3 w-3" />
                                            Higher is better
                                        </Badge>
                                        <Badge v-else-if="selectedGlobalMetric.direction === 'low'" variant="outline"
                                            class="gap-1">
                                            <TrendingDown class="h-3 w-3" />
                                            Lower is better
                                        </Badge>
                                    </div>

                                    <!-- Messages -->
                                    <Card>
                                        <CardHeader>
                                            <CardTitle class="flex items-center gap-2">
                                                <MessageSquare class="h-4 w-4 text-muted-foreground" />
                                                Messages
                                            </CardTitle>
                                            <p class="text-sm text-muted-foreground">
                                                Coaching SMS sent based on the driver's threshold status. 1 segment =
                                                160
                                                chars.
                                            </p>
                                        </CardHeader>
                                        <CardContent class="space-y-6">
                                            <div v-for="status in statusOptions" :key="status.value" class="space-y-2">
                                                <div class="flex items-center gap-2">
                                                    <Badge :variant="statusVariant(status.value)">{{ status.label }}
                                                    </Badge>
                                                    <p class="ml-auto text-xs"
                                                        :class="charCountClass(charCount(globalMessagesForm.messages[selectedGlobalMetric.key]?.[status.value]))">
                                                        {{
                                                            charCount(globalMessagesForm.messages[selectedGlobalMetric.key]?.[status.value])
                                                        }} chars ·
                                                        {{
                                                            charSegments(charCount(globalMessagesForm.messages[selectedGlobalMetric.key]?.[status.value]))
                                                        }} segment(s)
                                                    </p>
                                                </div>
                                                <div :key="`${selectedGlobalMetric.key}-${status.value}`"
                                                    :ref="(el) => setEditorRef({ type: 'global', metricKey: selectedGlobalMetric?.key, statusKey: status.value }, el)"
                                                    :class="editorClass" contenteditable="true" dir="auto"
                                                    inputmode="text" role="textbox" aria-multiline="true"
                                                    :data-placeholder="`${status.label} message for ${selectedGlobalMetric.label}`"
                                                    v-on="editorEvents({ type: 'global', metricKey: selectedGlobalMetric.key, statusKey: status.value })" />
                                            </div>
                                            <Button type="button" @click="submitGlobalMessages"
                                                :disabled="globalMessagesForm.processing">
                                                Save Messages
                                            </Button>
                                        </CardContent>
                                    </Card>

                                    <!-- Thresholds -->
                                    <Card>
                                        <CardHeader>
                                            <CardTitle class="flex items-center gap-2">
                                                <SlidersHorizontal class="h-4 w-4 text-muted-foreground" />
                                                Thresholds
                                            </CardTitle>
                                            <p class="text-sm text-muted-foreground">
                                                Numeric cutoffs that determine which coaching message each driver
                                                receives.
                                            </p>
                                        </CardHeader>
                                        <CardContent class="space-y-4">
                                            <div class="grid gap-4 md:grid-cols-3">
                                                <div class="space-y-2">
                                                    <Label class="flex items-center gap-1.5">
                                                        <Badge variant="success">Good</Badge>
                                                    </Label>
                                                    <Input
                                                        v-model="globalThresholdsForm.thresholds[selectedGlobalMetric.key].good"
                                                        type="number" step="0.01" placeholder="e.g. 90" />
                                                    <p class="text-xs text-muted-foreground">At or above → Good message.
                                                    </p>
                                                </div>
                                                <div class="space-y-2">
                                                    <Label class="flex items-center gap-1.5">
                                                        <Badge variant="warning">Needs Improvement</Badge>
                                                    </Label>
                                                    <Input
                                                        v-model="globalThresholdsForm.thresholds[selectedGlobalMetric.key].minor_improvement"
                                                        type="number" step="0.01" placeholder="e.g. 70" />
                                                    <p class="text-xs text-muted-foreground">Between Good and Bad.</p>
                                                </div>
                                                <div class="space-y-2">
                                                    <Label class="flex items-center gap-1.5">
                                                        <Badge variant="destructive">Bad</Badge>
                                                    </Label>
                                                    <Input
                                                        v-model="globalThresholdsForm.thresholds[selectedGlobalMetric.key].bad"
                                                        type="number" step="0.01" placeholder="e.g. 50" />
                                                    <p class="text-xs text-muted-foreground">Below this → Bad message.
                                                    </p>
                                                </div>
                                            </div>
                                            <Button type="button" @click="submitGlobalThresholds"
                                                :disabled="globalThresholdsForm.processing">
                                                Save Thresholds
                                            </Button>
                                        </CardContent>
                                    </Card>

                                </div>
                            </div>

                            <!-- Sticky placeholder sidebar -->
                            <div class="sticky top-4 self-start">
                                <Card class="flex flex-col overflow-hidden" style="max-height: calc(100vh - 5rem)">
                                    <CardHeader class="shrink-0 space-y-1 pb-3">
                                        <CardTitle class="text-sm">Insert Placeholder</CardTitle>
                                        <p class="text-xs text-muted-foreground">{{ activeFieldLabel }}</p>
                                    </CardHeader>
                                    <CardContent class="flex-1 space-y-4 overflow-y-auto pt-0">
                                        <Input v-model="placeholderQuery" placeholder="Search placeholders..."
                                            class="h-8 text-sm" />
                                        <div class="space-y-4">
                                            <div v-for="group in placeholderGroups" :key="group.key"
                                                class="space-y-1.5">
                                                <p
                                                    class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                                    {{ group.label }}
                                                </p>
                                                <div class="space-y-1">
                                                    <button v-for="item in group.items" :key="item.value" type="button"
                                                        class="group flex w-full items-start gap-2 rounded-md border border-border/50 bg-muted/20 px-2.5 py-2 text-left transition-colors hover:border-primary/40 hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-50"
                                                        :disabled="!activeField" @click="insertPlaceholder(item.value)">
                                                        <div class="min-w-0 flex-1">
                                                            <p class="truncate text-xs font-medium text-foreground">
                                                                {{ item.label }}
                                                            </p>
                                                            <p
                                                                class="mt-0.5 truncate font-mono text-[10px] text-muted-foreground">
                                                                {{ item.value }}
                                                            </p>
                                                        </div>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </TabsContent>

                    <!-- ── Overrides Tab ── -->
                    <TabsContent value="overrides" force-mount :hidden="activeTab !== 'overrides'">
                        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_288px]">
                            <div class="space-y-4">

                                <!-- Override Type Picker (mirrors metric picker style) -->
                                <div class="rounded-xl border bg-card p-4">
                                    <p
                                        class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Select override type
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button"
                                            class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-medium transition-all"
                                            :class="selectedOverrideType === 'message'
                                                ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                                : 'border-border bg-muted/50 text-muted-foreground hover:border-primary/40 hover:bg-primary/5 hover:text-foreground'"
                                            @click="selectedOverrideType = 'message'">
                                            <MessageSquare class="h-3.5 w-3.5" />
                                            Message Overrides
                                            <span v-if="messageOverridesCount"
                                                class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px] font-bold"
                                                :class="selectedOverrideType === 'message' ? 'bg-white/20 text-white' : 'bg-primary/10 text-primary'">
                                                {{ messageOverridesCount }}
                                            </span>
                                        </button>
                                        <button type="button"
                                            class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-medium transition-all"
                                            :class="selectedOverrideType === 'threshold'
                                                ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                                : 'border-border bg-muted/50 text-muted-foreground hover:border-primary/40 hover:bg-primary/5 hover:text-foreground'"
                                            @click="selectedOverrideType = 'threshold'">
                                            <SlidersHorizontal class="h-3.5 w-3.5" />
                                            Threshold Overrides
                                            <span v-if="thresholdOverridesCount"
                                                class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px] font-bold"
                                                :class="selectedOverrideType === 'threshold' ? 'bg-white/20 text-white' : 'bg-primary/10 text-primary'">
                                                {{ thresholdOverridesCount }}
                                            </span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Message Overrides -->
                                <Card v-show="selectedOverrideType === 'message'">
                                    <CardHeader>
                                        <CardTitle class="flex items-center gap-2">
                                            <MessageSquare class="h-4 w-4 text-muted-foreground" />
                                            Message Overrides
                                        </CardTitle>
                                        <p class="text-sm text-muted-foreground">
                                            Replace the global message for a specific tenant and status level.
                                        </p>
                                    </CardHeader>

                                    <CardContent class="space-y-5">
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="space-y-2">
                                                <Label>Tenants</Label>
                                                <Popover v-model:open="messageTenantsOpen">
                                                    <PopoverTrigger as-child>
                                                        <Button type="button" variant="outline"
                                                            class="w-full justify-between font-normal">
                                                            <span class="truncate">{{
                                                                tenantsLabel(messageOverrideForm.tenant_ids) }}</span>
                                                            <ChevronDown class="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent class="w-72 p-2">
                                                        <div class="max-h-56 space-y-0.5 overflow-y-auto">
                                                            <button v-for="tenant in tenants" :key="tenant.id"
                                                                type="button"
                                                                class="flex w-full cursor-pointer select-none items-center gap-2.5 rounded px-2 py-2 text-left text-sm hover:bg-accent"
                                                                @click="toggleTenant(messageOverrideForm, tenant.id)">
                                                                <Checkbox
                                                                    :model-value="isTenantSelected(messageOverrideForm, tenant.id)"
                                                                    class="pointer-events-none" />
                                                                <span class="min-w-0 truncate">{{ tenant.name }}</span>
                                                            </button>
                                                        </div>
                                                    </PopoverContent>
                                                </Popover>
                                                <div v-if="messageOverrideForm.tenant_ids.length"
                                                    class="flex flex-wrap gap-1.5 pt-1">
                                                    <span v-for="id in messageOverrideForm.tenant_ids" :key="id"
                                                        class="inline-flex items-center gap-1 rounded-full border border-primary/25 bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                                                        {{ tenantNameById(id) }}
                                                        <button type="button"
                                                            class="ml-0.5 rounded-full opacity-60 hover:opacity-100"
                                                            @click="toggleTenant(messageOverrideForm, id)">
                                                            <X class="h-3 w-3" />
                                                        </button>
                                                    </span>
                                                </div>
                                            </div>

                                            <div class="space-y-2">
                                                <Label>Metric</Label>
                                                <select v-model="messageOverrideForm.metric_key"
                                                    :class="nativeSelectClass">
                                                    <option disabled value="">Select metric...</option>
                                                    <option v-for="metric in metrics" :key="metric.key"
                                                        :value="metric.key">
                                                        {{ metric.label }}
                                                    </option>
                                                </select>
                                            </div>
                                        </div>

                                        <div v-if="messageOverrideForm.metric_key && messageOverrideForm.metric_key !== 'general'"
                                            class="space-y-2">
                                            <Label>Status</Label>
                                            <div class="grid grid-cols-3 gap-2">
                                                <button type="button"
                                                    class="rounded-md border px-3 py-2 text-xs font-semibold transition-all"
                                                    :class="messageOverrideForm.status === 'good'
                                                        ? 'border-green-500 bg-green-500/15 text-green-700 dark:text-green-400'
                                                        : 'border-border text-muted-foreground hover:border-green-400/60 hover:bg-green-500/5'"
                                                    @click="messageOverrideForm.status = 'good'">
                                                    Good
                                                </button>
                                                <button type="button"
                                                    class="rounded-md border px-3 py-2 text-xs font-semibold transition-all"
                                                    :class="messageOverrideForm.status === 'minor_improvement'
                                                        ? 'border-amber-500 bg-amber-500/15 text-amber-700 dark:text-amber-400'
                                                        : 'border-border text-muted-foreground hover:border-amber-400/60 hover:bg-amber-500/5'"
                                                    @click="messageOverrideForm.status = 'minor_improvement'">
                                                    Needs Improvement
                                                </button>
                                                <button type="button"
                                                    class="rounded-md border px-3 py-2 text-xs font-semibold transition-all"
                                                    :class="messageOverrideForm.status === 'bad'
                                                        ? 'border-red-500 bg-red-500/15 text-red-700 dark:text-red-400'
                                                        : 'border-border text-muted-foreground hover:border-red-400/60 hover:bg-red-500/5'"
                                                    @click="messageOverrideForm.status = 'bad'">
                                                    Bad
                                                </button>
                                            </div>
                                        </div>

                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between">
                                                <Label>Message</Label>
                                                <p class="text-xs"
                                                    :class="charCountClass(charCount(messageOverrideForm.message))">
                                                    {{ charCount(messageOverrideForm.message) }} chars ·
                                                    {{ charSegments(charCount(messageOverrideForm.message)) }}
                                                    segment(s)
                                                </p>
                                            </div>
                                            <div :ref="(el) => setEditorRef({ type: 'override' }, el)"
                                                :class="editorClass" contenteditable="true" dir="auto" inputmode="text"
                                                role="textbox" aria-multiline="true" data-placeholder="Override message"
                                                v-on="editorEvents({ type: 'override' })" />
                                        </div>

                                        <Button type="button" @click="submitMessageOverride"
                                            :disabled="messageOverrideForm.processing">
                                            Save Message Override
                                        </Button>

                                        <Separator />

                                        <!-- Active message overrides list -->
                                        <div v-if="messageOverrides?.length" class="space-y-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p class="text-xs font-medium text-muted-foreground">Active
                                                        overrides</p>
                                                    <p class="text-[11px] text-muted-foreground/70">
                                                        Showing {{ visibleMessageOverrides.length }} of {{
                                                            filteredMessageOverrides.length }}
                                                        <template
                                                            v-if="filteredMessageOverrides.length !== messageOverrides.length">
                                                            filtered
                                                        </template>
                                                        / {{ messageOverrides.length }} total
                                                    </p>
                                                </div>
                                                <Button v-if="hasActiveMessageOverrideFilters" type="button"
                                                    variant="ghost" size="sm" class="h-7 gap-1.5 px-2 text-xs"
                                                    @click="resetMessageOverrideFilters">
                                                    <RotateCcw class="h-3 w-3" />
                                                    Reset
                                                </Button>
                                            </div>

                                            <div class="rounded-lg border bg-muted/20 p-3">
                                                <div class="grid gap-2 md:grid-cols-[minmax(0,1fr)_180px_160px]">
                                                    <div class="relative">
                                                        <Search
                                                            class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                                        <Input v-model="messageOverrideFilters.query"
                                                            placeholder="Search tenant, metric, status..."
                                                            class="h-9 pl-8 text-sm" />
                                                    </div>
                                                    <select v-model="messageOverrideFilters.metric"
                                                        :class="compactSelectClass">
                                                        <option value="all">All metrics</option>
                                                        <option v-for="metric in messageOverrideMetricOptions"
                                                            :key="metric.value" :value="metric.value">
                                                            {{ metric.label }}
                                                        </option>
                                                    </select>
                                                    <select v-model="messageOverrideFilters.status"
                                                        :class="compactSelectClass">
                                                        <option value="all">All statuses</option>
                                                        <option value="">General</option>
                                                        <option v-for="status in statusOptions" :key="status.value"
                                                            :value="status.value">
                                                            {{ status.label }}
                                                        </option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div v-if="visibleMessageOverrides.length" class="space-y-2">
                                                <div v-for="override in visibleMessageOverrides" :key="override.id"
                                                    class="group rounded-lg border border-l-4 bg-card p-3 transition-colors hover:bg-muted/30"
                                                    :class="{
                                                        'border-l-green-500': override.status === 'good',
                                                        'border-l-amber-500': override.status === 'minor_improvement',
                                                        'border-l-red-500': override.status === 'bad',
                                                        'border-l-primary/60': !override.status,
                                                    }">
                                                    <div class="flex items-start justify-between gap-3">
                                                        <div class="min-w-0 flex-1 space-y-1.5">
                                                            <div class="flex flex-wrap items-center gap-1.5">
                                                                <span class="text-sm font-semibold text-foreground">{{
                                                                    override.tenant_name }}</span>
                                                                <span class="text-muted-foreground/40">·</span>
                                                                <Badge variant="outline" class="text-xs">{{
                                                                    formatMetric(override.metric_key) }}</Badge>
                                                                <Badge :variant="statusVariant(override.status)"
                                                                    class="text-xs">{{ formatStatus(override.status)
                                                                    }}</Badge>
                                                            </div>
                                                            <p class="line-clamp-2 text-xs leading-relaxed text-muted-foreground"
                                                                v-html="renderMessageHtml(override.message)" />
                                                            <button type="button"
                                                                class="inline-flex items-center gap-1.5 text-xs text-primary hover:underline"
                                                                @click="viewingMessage = override">
                                                                <Eye class="h-3.5 w-3.5" />
                                                                View full message
                                                            </button>
                                                        </div>
                                                        <Button variant="ghost" size="icon"
                                                            class="h-7 w-7 shrink-0 text-muted-foreground opacity-0 transition-opacity hover:text-destructive group-hover:opacity-100"
                                                            type="button" @click="confirmDelete('message', override)">
                                                            <X class="h-3.5 w-3.5" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div v-else class="rounded-lg border border-dashed py-8 text-center">
                                                <p class="text-sm font-medium text-muted-foreground">No overrides match
                                                    your
                                                    filters</p>
                                                <p class="mt-1 text-xs text-muted-foreground/60">Try adjusting your
                                                    search or
                                                    filters.</p>
                                            </div>

                                            <div v-if="hasMoreMessageOverrides" class="flex justify-center pt-1">
                                                <Button type="button" variant="outline" size="sm"
                                                    @click="showMoreMessageOverrides">
                                                    Show {{ Math.min(OVERRIDE_PAGE_SIZE, filteredMessageOverrides.length
                                                        -
                                                        visibleMessageOverrides.length) }} more
                                                </Button>
                                            </div>
                                        </div>

                                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                                            <MessageSquare class="h-8 w-8 text-muted-foreground/30" />
                                            <p class="text-sm font-medium text-muted-foreground">No message overrides
                                                yet</p>
                                            <p class="text-xs text-muted-foreground/60">Fill in the form above to create
                                                a
                                                tenant-specific message.</p>
                                        </div>
                                    </CardContent>
                                </Card>

                                <!-- Threshold Overrides -->
                                <Card v-show="selectedOverrideType === 'threshold'">
                                    <CardHeader>
                                        <CardTitle class="flex items-center gap-2">
                                            <SlidersHorizontal class="h-4 w-4 text-muted-foreground" />
                                            Threshold Overrides
                                        </CardTitle>
                                        <p class="text-sm text-muted-foreground">
                                            Set different thresholds for specific tenants instead of the global
                                            defaults.
                                        </p>
                                    </CardHeader>

                                    <CardContent class="space-y-5">
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="space-y-2">
                                                <Label>Tenants</Label>
                                                <Popover v-model:open="thresholdTenantsOpen">
                                                    <PopoverTrigger as-child>
                                                        <Button type="button" variant="outline"
                                                            class="w-full justify-between font-normal">
                                                            <span class="truncate">{{
                                                                tenantsLabel(thresholdOverrideForm.tenant_ids) }}</span>
                                                            <ChevronDown class="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent class="w-72 p-2">
                                                        <div class="max-h-56 space-y-0.5 overflow-y-auto">
                                                            <button v-for="tenant in tenants" :key="tenant.id"
                                                                type="button"
                                                                class="flex w-full cursor-pointer select-none items-center gap-2.5 rounded px-2 py-2 text-left text-sm hover:bg-accent"
                                                                @click="toggleTenant(thresholdOverrideForm, tenant.id)">
                                                                <Checkbox
                                                                    :model-value="isTenantSelected(thresholdOverrideForm, tenant.id)"
                                                                    class="pointer-events-none" />
                                                                <span class="min-w-0 truncate">{{ tenant.name }}</span>
                                                            </button>
                                                        </div>
                                                    </PopoverContent>
                                                </Popover>
                                                <div v-if="thresholdOverrideForm.tenant_ids.length"
                                                    class="flex flex-wrap gap-1.5 pt-1">
                                                    <span v-for="id in thresholdOverrideForm.tenant_ids" :key="id"
                                                        class="inline-flex items-center gap-1 rounded-full border border-primary/25 bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                                                        {{ tenantNameById(id) }}
                                                        <button type="button"
                                                            class="ml-0.5 rounded-full opacity-60 hover:opacity-100"
                                                            @click="toggleTenant(thresholdOverrideForm, id)">
                                                            <X class="h-3 w-3" />
                                                        </button>
                                                    </span>
                                                </div>
                                            </div>

                                            <div class="space-y-2">
                                                <Label>Metric</Label>
                                                <select v-model="thresholdOverrideForm.metric_key"
                                                    :class="nativeSelectClass">
                                                    <option disabled value="">Select metric...</option>
                                                    <option v-for="metric in thresholdMetrics" :key="metric.key"
                                                        :value="metric.key">
                                                        {{ metric.label }}
                                                    </option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="grid gap-4 md:grid-cols-3">
                                            <div class="space-y-2">
                                                <Label class="flex items-center gap-1.5">
                                                    <Badge variant="success">Good</Badge>
                                                </Label>
                                                <Input v-model="thresholdOverrideForm.good" type="number" step="0.01"
                                                    placeholder="e.g. 90" />
                                            </div>
                                            <div class="space-y-2">
                                                <Label class="flex items-center gap-1.5">
                                                    <Badge variant="warning">Needs Improvement</Badge>
                                                </Label>
                                                <Input v-model="thresholdOverrideForm.minor_improvement" type="number"
                                                    step="0.01" placeholder="e.g. 70" />
                                            </div>
                                            <div class="space-y-2">
                                                <Label class="flex items-center gap-1.5">
                                                    <Badge variant="destructive">Bad</Badge>
                                                </Label>
                                                <Input v-model="thresholdOverrideForm.bad" type="number" step="0.01"
                                                    placeholder="e.g. 50" />
                                            </div>
                                        </div>

                                        <Button type="button" @click="submitThresholdOverride"
                                            :disabled="thresholdOverrideForm.processing">
                                            Save Threshold Override
                                        </Button>

                                        <Separator />

                                        <!-- Active threshold overrides list -->
                                        <div v-if="thresholdOverrides?.length" class="space-y-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p class="text-xs font-medium text-muted-foreground">Active
                                                        overrides</p>
                                                    <p class="text-[11px] text-muted-foreground/70">
                                                        Showing {{ visibleThresholdOverrides.length }} of {{
                                                            filteredThresholdOverrides.length }}
                                                        <template
                                                            v-if="filteredThresholdOverrides.length !== thresholdOverrides.length">
                                                            filtered
                                                        </template>
                                                        / {{ thresholdOverrides.length }} total
                                                    </p>
                                                </div>
                                                <Button v-if="hasActiveThresholdOverrideFilters" type="button"
                                                    variant="ghost" size="sm" class="h-7 gap-1.5 px-2 text-xs"
                                                    @click="resetThresholdOverrideFilters">
                                                    <RotateCcw class="h-3 w-3" />
                                                    Reset
                                                </Button>
                                            </div>

                                            <div class="rounded-lg border bg-muted/20 p-3">
                                                <div class="grid gap-2 md:grid-cols-[minmax(0,1fr)_220px]">
                                                    <div class="relative">
                                                        <Search
                                                            class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                                        <Input v-model="thresholdOverrideFilters.query"
                                                            placeholder="Search tenant, metric, or value..."
                                                            class="h-9 pl-8 text-sm" />
                                                    </div>
                                                    <select v-model="thresholdOverrideFilters.metric"
                                                        :class="compactSelectClass">
                                                        <option value="all">All metrics</option>
                                                        <option v-for="metric in thresholdOverrideMetricOptions"
                                                            :key="metric.value" :value="metric.value">
                                                            {{ metric.label }}
                                                        </option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div v-if="visibleThresholdOverrides.length" class="space-y-2">
                                                <div v-for="override in visibleThresholdOverrides" :key="override.id"
                                                    class="group rounded-lg border border-l-4 border-l-primary/50 bg-card p-3 transition-colors hover:bg-muted/30">
                                                    <div class="flex items-start justify-between gap-3">
                                                        <div class="min-w-0 space-y-2">
                                                            <div class="flex flex-wrap items-center gap-1.5">
                                                                <span class="text-sm font-semibold text-foreground">{{
                                                                    override.tenant_name }}</span>
                                                                <span class="text-muted-foreground/40">·</span>
                                                                <Badge variant="outline" class="text-xs">{{
                                                                    formatMetric(override.metric_key) }}</Badge>
                                                            </div>
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <span
                                                                    class="inline-flex items-center gap-1.5 rounded-md border border-green-500/25 bg-green-500/10 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-400">
                                                                    Good <span class="font-mono font-bold">{{
                                                                        override.good
                                                                        }}</span>
                                                                </span>
                                                                <span
                                                                    class="inline-flex items-center gap-1.5 rounded-md border border-amber-500/25 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                                                                    Needs Improvement <span
                                                                        class="font-mono font-bold">{{
                                                                            override.minor_improvement }}</span>
                                                                </span>
                                                                <span
                                                                    class="inline-flex items-center gap-1.5 rounded-md border border-red-500/25 bg-red-500/10 px-2 py-0.5 text-xs font-medium text-red-700 dark:text-red-400">
                                                                    Bad <span class="font-mono font-bold">{{
                                                                        override.bad
                                                                        }}</span>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <Button variant="ghost" size="icon"
                                                            class="h-7 w-7 shrink-0 text-muted-foreground opacity-0 transition-opacity hover:text-destructive group-hover:opacity-100"
                                                            type="button" @click="confirmDelete('threshold', override)">
                                                            <X class="h-3.5 w-3.5" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div v-else class="rounded-lg border border-dashed py-8 text-center">
                                                <p class="text-sm font-medium text-muted-foreground">No overrides match
                                                    your
                                                    filters</p>
                                                <p class="mt-1 text-xs text-muted-foreground/60">Try a different tenant,
                                                    metric, or value.</p>
                                            </div>

                                            <div v-if="hasMoreThresholdOverrides" class="flex justify-center pt-1">
                                                <Button type="button" variant="outline" size="sm"
                                                    @click="showMoreThresholdOverrides">
                                                    Show {{ Math.min(OVERRIDE_PAGE_SIZE,
                                                        filteredThresholdOverrides.length -
                                                        visibleThresholdOverrides.length) }} more
                                                </Button>
                                            </div>
                                        </div>

                                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                                            <SlidersHorizontal class="h-8 w-8 text-muted-foreground/30" />
                                            <p class="text-sm font-medium text-muted-foreground">No threshold overrides
                                                yet
                                            </p>
                                            <p class="text-xs text-muted-foreground/60">Fill in the form above to
                                                override
                                                global thresholds for a tenant.</p>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Sticky placeholder sidebar (overrides tab) -->
                            <div class="sticky top-4 self-start">
                                <Card class="flex flex-col overflow-hidden" style="max-height: calc(100vh - 5rem)">
                                    <CardHeader class="shrink-0 space-y-1 pb-3">
                                        <CardTitle class="text-sm">Insert Placeholder</CardTitle>
                                        <p class="text-xs text-muted-foreground">{{ activeFieldLabel }}</p>
                                    </CardHeader>
                                    <CardContent class="flex-1 space-y-4 overflow-y-auto pt-0">
                                        <Input v-model="placeholderQuery" placeholder="Search placeholders..."
                                            class="h-8 text-sm" />
                                        <div class="space-y-4">
                                            <div v-for="group in placeholderGroups" :key="group.key"
                                                class="space-y-1.5">
                                                <p
                                                    class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                                    {{ group.label }}
                                                </p>
                                                <div class="space-y-1">
                                                    <button v-for="item in group.items" :key="item.value" type="button"
                                                        class="group flex w-full items-start gap-2 rounded-md border border-border/50 bg-muted/20 px-2.5 py-2 text-left transition-colors hover:border-primary/40 hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-50"
                                                        :disabled="!activeField" @click="insertPlaceholder(item.value)">
                                                        <div class="min-w-0 flex-1">
                                                            <p class="truncate text-xs font-medium text-foreground">{{
                                                                item.label }}</p>
                                                            <p
                                                                class="mt-0.5 truncate font-mono text-[10px] text-muted-foreground">
                                                                {{ item.value }}</p>
                                                        </div>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>

            <!-- View Full Message Dialog -->
            <Dialog :open="!!viewingMessage" @update:open="(v) => { if (!v) viewingMessage = null }">
                <DialogScrollContent class="max-w-2xl">
                    <DialogHeader class="pr-6">
                        <DialogTitle>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="break-all">{{ viewingMessage?.tenant_name }}</span>
                                <span class="text-muted-foreground/40 font-normal">·</span>
                                <Badge variant="outline" class="shrink-0 text-xs font-normal">{{
                                    formatMetric(viewingMessage?.metric_key) }}</Badge>
                                <Badge :variant="statusVariant(viewingMessage?.status)"
                                    class="shrink-0 text-xs font-normal">{{
                                        formatStatus(viewingMessage?.status) }}</Badge>
                            </div>
                        </DialogTitle>
                        <DialogDescription>Full override message</DialogDescription>
                    </DialogHeader>

                    <!-- Message body: rounded box with proper wrapping -->
                    <div class="mt-3 rounded-lg border bg-muted/30 p-4">
                        <div class="min-w-0 whitespace-pre-wrap text-sm leading-relaxed text-foreground"
                            style="word-break: break-word; overflow-wrap: anywhere;"
                            v-html="renderMessageHtml(viewingMessage?.message)" />
                    </div>

                    <!-- Stats row -->
                    <div class="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                        <span>{{ charCount(viewingMessage?.message) }} characters</span>
                        <span :class="charCountClass(charCount(viewingMessage?.message))">
                            {{ charSegments(charCount(viewingMessage?.message)) }} SMS segment(s)
                        </span>
                    </div>
                </DialogScrollContent>
            </Dialog>

            <!-- Delete Confirmation -->
            <AlertDialog :open="!!pendingDelete" @update:open="(value) => { if (!value) pendingDelete = null }">
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove Override</AlertDialogTitle>
                        <AlertDialogDescription>
                            Remove the
                            <strong>{{ formatMetric(pendingDelete?.override?.metric_key) }}</strong>
                            <template v-if="pendingDelete?.override?.status">
                                · <strong>{{ formatStatus(pendingDelete?.override?.status) }}</strong>
                            </template>
                            override for
                            <strong>{{ pendingDelete?.override?.tenant_name }}</strong>?
                            This cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel @click="pendingDelete = null">Cancel</AlertDialogCancel>
                        <AlertDialogAction class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            @click="executePendingDelete">
                            Remove
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    </AppLayout>
</template>

<style scoped>
.sms-message-editor {
    direction: auto;
    unicode-bidi: plaintext;
}

.sms-message-editor .placeholder-token {
    direction: ltr;
    unicode-bidi: isolate;
    white-space: nowrap;
}
</style>
