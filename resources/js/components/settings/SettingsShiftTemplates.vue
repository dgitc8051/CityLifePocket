<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import ShiftTemplateFormModal from './ShiftTemplateFormModal.vue';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const features = computed(() => auth.user?.current_shop?.features ?? {});

const sectionDesc = computed(() => {
    const parts = ['最低人數'];
    if (features.value.senior_required) parts.push('高階員工');
    if (features.value.stations) parts.push('站別需求');
    return `定義每日時段（早 / 中 / 晚），含${parts.join('、')}。`;
});

const loading = ref(true);
const templates = ref([]);
const error = ref(null);

const showModal = ref(false);
const editing = ref(null);

async function fetchTemplates() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get('/api/shift-templates');
        templates.value = data.data;
    } catch (e) {
        error.value = '讀取失敗';
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    editing.value = null;
    showModal.value = true;
}

function openEdit(t) {
    editing.value = t;
    showModal.value = true;
}

async function handleSubmit(payload) {
    try {
        if (editing.value) {
            await axios.put(`/api/shift-templates/${editing.value.id}`, payload);
        } else {
            await axios.post('/api/shift-templates', payload);
        }
        showModal.value = false;
        await fetchTemplates();
        return null;
    } catch (e) {
        const errs = e?.response?.data?.errors;
        const singleErr = e?.response?.data?.error;
        if (errs) return Object.values(errs).flat().join('\n');
        if (singleErr) return singleErr;
        return '儲存失敗';
    }
}

async function handleDeactivate(t) {
    if (!confirm(`停用「${t.name}」？已使用此時段的排班不受影響。`)) return;
    try {
        await axios.delete(`/api/shift-templates/${t.id}`);
        await fetchTemplates();
    } catch (e) {
        alert('處理失敗');
    }
}

async function handleActivate(t) {
    try {
        await axios.put(`/api/shift-templates/${t.id}`, { is_active: true });
        await fetchTemplates();
    } catch (e) {
        alert('啟用失敗');
    }
}

onMounted(fetchTemplates);
</script>

<template>
    <div v-if="loading" class="h-64 rounded-[6px] border border-ink-200/60 bg-white" />
    <div v-else class="space-y-4">
        <div class="flex items-center justify-between">
            <p class="text-[13px] text-ink-500">{{ sectionDesc }}</p>
            <button
                type="button"
                @click="openCreate"
                class="rounded-[5px] bg-ink-900 px-3 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-ink-800"
            >
                時段建立
            </button>
        </div>

        <div
            v-if="templates.length === 0"
            class="rounded-[6px] border border-dashed border-ink-300 bg-ink-50/50 px-5 py-12 text-center text-[13px] text-ink-500"
        >
            尚未設定任何時段
        </div>

        <div v-else class="space-y-2">
            <article
                v-for="t in templates"
                :key="t.id"
                class="rounded-[6px] border border-ink-200/60 bg-white p-4"
                :class="{ 'opacity-60': !t.is_active }"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-baseline gap-2">
                            <p class="text-[15px] font-semibold text-ink-900">{{ t.name }}</p>
                            <p class="text-[13px] text-ink-500 tabular-nums">
                                {{ t.start_time }}–{{ t.end_time }}
                            </p>
                            <span class="text-[12px] text-ink-400">· {{ t.days_label }}</span>
                            <span
                                v-if="!t.is_active"
                                class="rounded-[5px] bg-ink-100 px-1.5 py-0.5 text-[11px] text-ink-500"
                            >
                                已停用
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5 text-[12px]">
                            <span class="rounded-[5px] bg-success-50 px-2 py-0.5 text-success-700 tabular-nums">
                                人數 {{ t.min_headcount }}<span v-if="t.max_headcount">–{{ t.max_headcount }}</span>
                            </span>
                            <span v-if="features.senior_required && t.min_senior_count > 0" class="rounded-[5px] bg-ink-100 px-2 py-0.5 text-ink-700 tabular-nums">
                                最少高階 {{ t.min_senior_count }}
                            </span>
                            <template v-if="features.stations">
                                <span v-for="req in (t.station_requirements ?? [])" :key="req.station_id"
                                    class="inline-flex items-center gap-1 rounded-[5px] bg-white px-2 py-0.5 text-ink-700 tabular-nums"
                                    style="border:1px solid #e2e8f0">
                                    <span class="inline-block h-2 w-2 rounded-[1px]" :style="{ backgroundColor: req.color || '#94a3b8' }" />
                                    {{ req.name }} ≥ {{ req.min_count }}
                                </span>
                            </template>
                            <span v-if="features.skill_score && t.required_score > 0" class="rounded-[5px] bg-warning-50/60 px-2 py-0.5 text-warning-700/80 tabular-nums">
                                建議總分 {{ t.required_score }}
                            </span>
                        </div>
                        <p v-if="t.notes" class="mt-2 text-[12px] text-ink-500">{{ t.notes }}</p>
                    </div>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            @click="openEdit(t)"
                            class="rounded-[5px] px-2 py-1 text-[12px] text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900"
                        >
                            編輯
                        </button>
                        <button
                            v-if="t.is_active"
                            type="button"
                            @click="handleDeactivate(t)"
                            class="rounded-[5px] px-2 py-1 text-[12px] text-danger-700 transition-colors hover:bg-danger-50"
                        >
                            停用
                        </button>
                        <button
                            v-else
                            type="button"
                            @click="handleActivate(t)"
                            class="rounded-[5px] px-2 py-1 text-[12px] text-success-700 transition-colors hover:bg-success-50"
                        >
                            啟用
                        </button>
                    </div>
                </div>
            </article>
        </div>

        <ShiftTemplateFormModal
            v-if="showModal"
            :template="editing"
            :on-submit="handleSubmit"
            @close="showModal = false"
        />
    </div>
</template>
