<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

const loading = ref(true);
const swaps = ref([]);
const meta = ref({ pending_count: 0 });
const statusFilter = ref('all');
const showModal = ref(false);
const flash = ref(null);
const busy = ref(false);

const filtered = computed(() => {
    if (statusFilter.value === 'all') return swaps.value;
    return swaps.value.filter((s) => s.status === statusFilter.value);
});

const tabs = [
    { val: 'all', label: '全部' },
    { val: 'pending', label: '待審核' },
    { val: 'accepted', label: '已通過' },
    { val: 'rejected', label: '已拒絕' },
    { val: 'cancelled', label: '已取消' },
];

function flashMsg(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2400);
}

async function fetchSwaps() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/shift-swap-requests');
        swaps.value = data.data ?? [];
        meta.value = data.meta ?? { pending_count: 0 };
    } catch (e) {
        flashMsg('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

async function approve(id) {
    if (busy.value) return;
    busy.value = true;
    try {
        await axios.post(`/api/shift-swap-requests/${id}/approve`);
        flashMsg('已核准');
        await fetchSwaps();
    } catch (e) {
        flashMsg(e?.response?.data?.error ?? '核准失敗', false);
    } finally {
        busy.value = false;
    }
}

async function reject(id) {
    if (busy.value) return;
    if (!confirm('確定拒絕？')) return;
    busy.value = true;
    try {
        await axios.post(`/api/shift-swap-requests/${id}/reject`);
        flashMsg('已拒絕');
        await fetchSwaps();
    } catch (e) {
        flashMsg(e?.response?.data?.error ?? '拒絕失敗', false);
    } finally {
        busy.value = false;
    }
}

async function cancel(id) {
    if (busy.value) return;
    if (!confirm('確定取消？')) return;
    busy.value = true;
    try {
        await axios.delete(`/api/shift-swap-requests/${id}`);
        flashMsg('已取消');
        await fetchSwaps();
    } catch (e) {
        flashMsg(e?.response?.data?.error ?? '取消失敗', false);
    } finally {
        busy.value = false;
    }
}

// --- Create modal ---
const employees = ref([]);
const entries = ref([]);
const form = ref({ from_entry_id: '', to_employee_id: '', to_entry_id: '', reason: '' });

async function openCreate() {
    showModal.value = true;
    form.value = { from_entry_id: '', to_employee_id: '', to_entry_id: '', reason: '' };
    try {
        const today = new Date();
        const monday = new Date(today);
        monday.setDate(today.getDate() - ((today.getDay() + 6) % 7));
        const { data } = await axios.get(`/api/schedule?week=${monday.toISOString().slice(0, 10)}&days=28`);
        employees.value = data.employees ?? [];
        entries.value = (data.entries ?? []).map((e) => {
            const emp = (data.employees ?? []).find((x) => x.id === e.employee_id);
            const tpl = (data.templates ?? []).find((t) => t.id === e.shift_template_id);
            return {
                ...e,
                emp_name: emp?.name ?? '?',
                shift_name: tpl?.name ?? '?',
                label: `${e.date} ${tpl?.name ?? '?'}（${emp?.name ?? '?'}）`,
            };
        });
    } catch (e) {
        flashMsg('讀取排班失敗', false);
    }
}

const fromEntries = computed(() => entries.value);
const toEntriesForSelected = computed(() => {
    if (!form.value.to_employee_id) return [];
    return entries.value.filter((e) => e.employee_id === Number(form.value.to_employee_id));
});

async function submitCreate() {
    if (busy.value) return;
    busy.value = true;
    try {
        const payload = {
            from_schedule_entry_id: Number(form.value.from_entry_id),
            to_employee_id: Number(form.value.to_employee_id),
            to_schedule_entry_id: form.value.to_entry_id ? Number(form.value.to_entry_id) : null,
            reason: form.value.reason || null,
        };
        await axios.post('/api/shift-swap-requests', payload);
        flashMsg('已送出申請');
        showModal.value = false;
        await fetchSwaps();
    } catch (e) {
        const errs = e?.response?.data?.errors;
        const msg = errs ? Object.values(errs).flat().join('\n') : (e?.response?.data?.error ?? '送出失敗');
        flashMsg(msg, false);
    } finally {
        busy.value = false;
    }
}

function statusClass(s) {
    return {
        'bg-warning-50 text-warning-700': s === 'pending',
        'bg-success-50 text-success-700': s === 'accepted',
        'bg-danger-50 text-danger-700': s === 'rejected',
        'bg-ink-100 text-ink-500': s === 'cancelled',
    };
}

function fmtDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getMonth() + 1}/${d.getDate()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

onMounted(fetchSwaps);
</script>

<template>
    <div class="space-y-10">
        <section class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Shift Swap</p>
                <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">換班申請</h2>
                <p class="num mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                    待審核 {{ meta.pending_count }} 件
                </p>
            </div>
            <button type="button" @click="openCreate"
                class="rounded-[5px] bg-sumi-600 px-4 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500">
                新增申請
            </button>
        </section>

        <nav class="flex flex-wrap items-center gap-px rounded-[5px] border border-ink-200/60 bg-white p-0.5 text-[11px]">
            <button v-for="t in tabs" :key="t.val" @click="statusFilter = t.val" type="button"
                class="rounded-[3px] px-3 py-1 tracking-[0.05em] transition-colors"
                :class="statusFilter === t.val
                    ? 'bg-ink-100 text-ink-900 font-medium'
                    : 'text-ink-500 hover:text-ink-900'">
                {{ t.label }}
            </button>
        </nav>

        <div v-if="flash"
            class="rounded-[5px] px-3.5 py-2.5 text-[12px] tracking-[0.02em]"
            :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <div v-if="loading" class="h-64 border-y border-ink-200/60" />
        <div v-else-if="!filtered.length"
            class="border-y border-ink-200/60 py-20 text-center text-[12px] text-ink-400">
            無紀錄
        </div>

        <ul v-else class="divide-y divide-ink-200/60 border-y border-ink-200/60">
            <li v-for="s in filtered" :key="s.id" class="py-4">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1.5">
                    <span class="num shrink-0 text-[11px] tracking-[0.02em] text-ink-400">
                        {{ fmtDate(s.requested_at) }}
                    </span>
                    <span class="shrink-0 rounded-[3px] px-1.5 py-0.5 text-[10px] tracking-[0.05em]"
                        :class="statusClass(s.status)">
                        {{ s.status_label }}
                    </span>
                    <span class="text-[12px] text-ink-900">
                        <span class="font-medium">{{ s.from_employee?.name }}</span>
                        <span class="num mx-2 text-ink-400">
                            {{ s.from_entry?.date }} {{ s.from_entry?.shift_name }}
                            <span class="text-[10px]">{{ s.from_entry?.start_time }}–{{ s.from_entry?.end_time }}</span>
                        </span>
                        <span class="text-ink-400">→</span>
                        <span class="ml-2 font-medium">{{ s.to_employee?.name }}</span>
                        <span v-if="s.to_entry" class="num ml-2 text-ink-400">
                            {{ s.to_entry?.date }} {{ s.to_entry?.shift_name }}
                            <span class="text-[10px]">{{ s.to_entry?.start_time }}–{{ s.to_entry?.end_time }}</span>
                            <span class="ml-1 text-[10px] text-ink-300">(雙向)</span>
                        </span>
                        <span v-else class="ml-2 text-[10px] text-ink-400">(單向代班)</span>
                    </span>
                    <div v-if="s.status === 'pending'" class="ml-auto flex shrink-0 gap-1">
                        <button type="button" @click="approve(s.id)" :disabled="busy"
                            class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-success-700 transition-colors hover:bg-success-50 disabled:opacity-50">
                            核准
                        </button>
                        <button type="button" @click="reject(s.id)" :disabled="busy"
                            class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-danger-700 transition-colors hover:bg-danger-50 disabled:opacity-50">
                            拒絕
                        </button>
                        <button type="button" @click="cancel(s.id)" :disabled="busy"
                            class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-ink-500 transition-colors hover:bg-ink-100 disabled:opacity-50">
                            取消
                        </button>
                    </div>
                </div>
                <p v-if="s.reason" class="mt-1.5 pl-[88px] text-[11px] tracking-[0.02em] text-ink-500">
                    原因：{{ s.reason }}
                </p>
            </li>
        </ul>

        <!-- Create modal -->
        <div v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 px-4"
            @click.self="showModal = false">
            <div class="w-full max-w-md overflow-hidden rounded-[6px] bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-ink-200/60 px-5 py-4">
                    <h3 class="text-[15px] font-semibold text-ink-900">新增換班申請</h3>
                    <button type="button" @click="showModal = false"
                        class="rounded-[5px] px-2 py-1 text-[12px] text-ink-500 transition-colors hover:bg-ink-100">
                        取消
                    </button>
                </div>
                <form @submit.prevent="submitCreate" class="space-y-4 px-5 py-5">
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">我的班次 *</label>
                        <select v-model="form.from_entry_id" required
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100">
                            <option value="">— 選擇要轉出的班次 —</option>
                            <option v-for="e in fromEntries" :key="e.id" :value="e.id">{{ e.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">對方員工 *</label>
                        <select v-model="form.to_employee_id" required
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100">
                            <option value="">— 選擇對方 —</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                {{ emp.name }}（{{ emp.level_label }}）
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            對方班次 <span class="ml-1 font-normal text-ink-400">選填（雙向交換）</span>
                        </label>
                        <select v-model="form.to_entry_id"
                            :disabled="!form.to_employee_id"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100 disabled:bg-ink-50">
                            <option value="">— 不填表示單向代班 —</option>
                            <option v-for="e in toEntriesForSelected" :key="e.id" :value="e.id">{{ e.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">原因</label>
                        <textarea v-model="form.reason" rows="2" placeholder="（選填，如：臨時有事、家庭因素）"
                            class="w-full rounded-[5px] border border-ink-200/60 px-3 py-2 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
                    </div>
                </form>
                <div class="flex items-center justify-end gap-2 border-t border-ink-200/60 bg-ink-50/60 px-5 py-3">
                    <button type="button" @click="showModal = false" :disabled="busy"
                        class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[13px] text-ink-700 transition-colors hover:bg-ink-50 disabled:opacity-50">
                        取消
                    </button>
                    <button type="button" @click="submitCreate" :disabled="busy || !form.from_entry_id || !form.to_employee_id"
                        class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-ink-800 disabled:opacity-50">
                        {{ busy ? '送出中' : '送出申請' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
