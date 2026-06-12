<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const loading = ref(true);
const multipliers = ref([]);
const flash = ref(null);

const showModal = ref(false);
const editing = ref(null);
const form = reactive({
    label: '',
    multiplier: 1.0,
    condition_type: 'weekday_ot',
    hours_from: 0,
    hours_to: null,
    sort_order: 0,
    is_active: true,
});
const saving = ref(false);
const formError = ref(null);

const typeLabels = {
    weekday_ot: '平日加班',
    rest_day_ot: '休息日加班',
    holiday: '國定假日',
    night: '夜間時段',
    custom: '自訂',
};

function flashMsg(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2400);
}

async function fetchAll() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/salary-multipliers');
        multipliers.value = data.data ?? [];
    } catch (e) {
        flashMsg('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    editing.value = null;
    Object.assign(form, {
        label: '',
        multiplier: 1.34,
        condition_type: 'weekday_ot',
        hours_from: 0,
        hours_to: 2,
        sort_order: (multipliers.value.length + 1) * 10,
        is_active: true,
    });
    formError.value = null;
    showModal.value = true;
}

function openEdit(m) {
    editing.value = m;
    Object.assign(form, {
        label: m.label,
        multiplier: m.multiplier,
        condition_type: m.condition_type,
        hours_from: m.condition_json?.hours_from ?? 0,
        hours_to: m.condition_json?.hours_to ?? null,
        sort_order: m.sort_order,
        is_active: m.is_active,
    });
    formError.value = null;
    showModal.value = true;
}

function needsHoursRange(type) {
    return ['weekday_ot', 'rest_day_ot'].includes(type);
}

async function save() {
    saving.value = true;
    formError.value = null;
    try {
        const payload = {
            label: form.label,
            multiplier: Number(form.multiplier),
            condition_type: form.condition_type,
            sort_order: Number(form.sort_order),
            is_active: form.is_active,
            condition_json: needsHoursRange(form.condition_type)
                ? {
                      hours_from: Number(form.hours_from) || 0,
                      hours_to: form.hours_to === null || form.hours_to === '' ? null : Number(form.hours_to),
                  }
                : null,
        };
        if (editing.value) {
            await axios.put(`/api/salary-multipliers/${editing.value.id}`, payload);
        } else {
            await axios.post('/api/salary-multipliers', payload);
        }
        showModal.value = false;
        await fetchAll();
        flashMsg('已儲存');
    } catch (e) {
        const errs = e?.response?.data?.errors;
        const single = e?.response?.data?.error;
        formError.value = errs ? Object.values(errs).flat().join('\n') : single || '儲存失敗';
    } finally {
        saving.value = false;
    }
}

async function remove(m) {
    if (!confirm(`刪除「${m.label}」？已產生的薪資紀錄不受影響。`)) return;
    try {
        await axios.delete(`/api/salary-multipliers/${m.id}`);
        await fetchAll();
        flashMsg('已刪除');
    } catch (e) {
        flashMsg('刪除失敗', false);
    }
}

onMounted(fetchAll);
</script>

<template>
    <div v-if="loading" class="h-64 border-y border-ink-200/60" />
    <div v-else class="space-y-8">
        <section>
            <h3 class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Salary Multipliers</h3>
            <p class="mt-1 font-serif text-[16px] font-medium text-ink-900">薪資倍率設定</p>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                設定加班 / 假日 / 夜間等各種倍率。預設為台灣勞基法，店家可自行調整、刪除、新增。<br />
                這些倍率會影響員工時數表計算與「省錢」排班策略。
            </p>
        </section>

        <div v-if="flash"
            class="rounded-[5px] px-3.5 py-2.5 text-[12px] tracking-[0.02em]"
            :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <div>
            <div class="flex items-center justify-between">
                <p class="text-[12px] text-ink-600">共 {{ multipliers.length }} 個倍率</p>
                <button type="button" @click="openCreate"
                    class="rounded-[5px] bg-sumi-600 px-3.5 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500">
                    新增倍率
                </button>
            </div>

            <ul v-if="multipliers.length" class="mt-4 divide-y divide-ink-200/60 border-y border-ink-200/60">
                <li v-for="m in multipliers" :key="m.id" class="flex items-center gap-4 py-3">
                    <div class="w-20 text-right text-[15px] font-semibold tabular-nums text-ink-900">
                        ×{{ Number(m.multiplier).toFixed(2) }}
                    </div>
                    <div class="flex-1">
                        <p class="text-[13px] font-medium text-ink-900">
                            {{ m.label }}
                            <span v-if="!m.is_active"
                                class="ml-2 rounded-[3px] bg-ink-100 px-1.5 py-0.5 text-[10px] text-ink-500">
                                已停用
                            </span>
                        </p>
                        <p class="text-[10.5px] tracking-[0.02em] text-ink-400">
                            {{ m.condition_type_label }}
                            <span v-if="m.condition_json?.hours_from !== undefined && m.condition_json?.hours_from !== null">
                                · 第 {{ m.condition_json.hours_from }}<span v-if="m.condition_json.hours_to !== null">–{{ m.condition_json.hours_to }}</span> 小時
                            </span>
                        </p>
                    </div>
                    <button type="button" @click="openEdit(m)"
                        class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-ink-500 hover:bg-ink-100 hover:text-ink-900">
                        編輯
                    </button>
                    <button type="button" @click="remove(m)"
                        class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-ink-500 hover:bg-danger-50 hover:text-danger-700">
                        刪除
                    </button>
                </li>
            </ul>
            <div v-else class="mt-4 border-y border-ink-200/60 py-12 text-center text-[12px] text-ink-400">
                還沒有倍率。點右上「新增倍率」開始建立。
            </div>
        </div>

        <!-- Modal -->
        <div v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 px-4"
            @click.self="showModal = false">
            <div class="w-full max-w-md overflow-hidden rounded-[6px] bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-ink-200/60 px-5 py-4">
                    <h3 class="text-[15px] font-semibold text-ink-900">{{ editing ? '編輯倍率' : '新增倍率' }}</h3>
                    <button type="button" @click="showModal = false"
                        class="rounded-[5px] px-2 py-1 text-[12px] text-ink-500 hover:bg-ink-100">取消</button>
                </div>
                <form @submit.prevent="save" class="space-y-4 px-5 py-5">
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">顯示名稱 *</label>
                        <input v-model="form.label" type="text" required maxlength="100" placeholder="例：平日1.34倍、假日2倍"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-[12px] font-medium text-ink-700">倍率 *</label>
                            <input v-model.number="form.multiplier" type="number" step="0.01" min="0.01" max="10" required
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[12px] font-medium text-ink-700">套用條件 *</label>
                            <select v-model="form.condition_type"
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 px-2 text-[13px] outline-none focus:border-accent-500">
                                <option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="needsHoursRange(form.condition_type)" class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-[12px] font-medium text-ink-700">起算（加班第 N 小時）</label>
                            <input v-model.number="form.hours_from" type="number" step="0.5" min="0" max="24"
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[12px] font-medium text-ink-700">截止（含、可空白＝無上限）</label>
                            <input v-model.number="form.hours_to" type="number" step="0.5" min="0" max="24"
                                placeholder="例：2 → 加班前2小時"
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-[12px] font-medium text-ink-700">排序</label>
                            <input v-model.number="form.sort_order" type="number" min="0" max="9999"
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
                        </div>
                        <div class="flex items-end">
                            <label class="flex items-center gap-2 text-[12px] text-ink-700">
                                <input v-model="form.is_active" type="checkbox" class="rounded border-ink-300" />
                                啟用中
                            </label>
                        </div>
                    </div>
                    <div v-if="formError" class="rounded-[5px] bg-danger-50 px-3 py-2 text-[12px] text-danger-700 whitespace-pre-line">
                        {{ formError }}
                    </div>
                </form>
                <div class="flex items-center justify-end gap-2 border-t border-ink-200/60 bg-ink-50/60 px-5 py-3">
                    <button type="button" @click="showModal = false" :disabled="saving"
                        class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[13px] text-ink-700 hover:bg-ink-50 disabled:opacity-50">取消</button>
                    <button type="button" @click="save" :disabled="saving"
                        class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white hover:bg-ink-800 disabled:opacity-50">
                        {{ saving ? '儲存中' : (editing ? '儲存' : '建立') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
