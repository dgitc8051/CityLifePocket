<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const isManager = computed(() => ['admin', 'owner', 'manager', 'sub_manager'].includes(auth.user?.role));

const loading = ref(true);
const employees = ref([]);
const selectedEmpId = ref(null);
const fromDate = ref('');
const toDate = ref('');

const data = ref(null);
const expanded = ref(new Set());

function defaultRange() {
    const now = new Date();
    const half = new Date(now);
    half.setMonth(half.getMonth() - 5);
    half.setDate(1);
    fromDate.value = half.toISOString().slice(0, 10);
    toDate.value = now.toISOString().slice(0, 10);
}

async function fetchEmployees() {
    if (!isManager.value) return;
    try {
        const { data: r } = await axios.get('/api/employees?status=active');
        employees.value = r.data ?? [];
        if (employees.value.length && !selectedEmpId.value) {
            // 預設選擇登入者自己（如果有對應 employee）
            const me = employees.value.find((e) => e.user_id === auth.user?.id);
            selectedEmpId.value = (me ?? employees.value[0]).id;
        }
    } catch (e) { /* skip */ }
}

async function fetchHours() {
    loading.value = true;
    try {
        const params = { from: fromDate.value, to: toDate.value };
        if (selectedEmpId.value) params.employee_id = selectedEmpId.value;
        const { data: r } = await axios.get('/api/attendance/personal-hours', { params });
        data.value = r;
        // 預設展開最近三個月
        expanded.value = new Set((r.months ?? []).slice(0, 3).map((m) => m.month));
    } catch (e) {
        data.value = null;
    } finally {
        loading.value = false;
    }
}

function toggleMonth(m) {
    if (expanded.value.has(m)) expanded.value.delete(m);
    else expanded.value.add(m);
    expanded.value = new Set(expanded.value);
}

function minutesToHM(min) {
    if (!min) return '00:00';
    const h = Math.floor(min / 60);
    const m = min % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function bucketMinutesForMonth(monthData, multiplierId) {
    let total = 0;
    for (const r of monthData.records) {
        total += r.buckets?.[multiplierId] ?? 0;
    }
    return total;
}

function fmtTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

async function approveOT(recordId) {
    if (!confirm('核可此筆加班時數？')) return;
    try {
        await axios.post(`/api/attendance/${recordId}/approve-overtime`);
        await fetchHours();
    } catch (e) { alert(e?.response?.data?.error ?? '核可失敗'); }
}

async function rejectOT(recordId) {
    if (!confirm('拒絕此筆加班時數？此筆不計加班費。')) return;
    try {
        await axios.post(`/api/attendance/${recordId}/reject-overtime`);
        await fetchHours();
    } catch (e) { alert(e?.response?.data?.error ?? '操作失敗'); }
}

const buckets = computed(() => data.value?.buckets ?? []);

watch(selectedEmpId, () => { if (selectedEmpId.value) fetchHours(); });

onMounted(async () => {
    defaultRange();
    await fetchEmployees();
    await fetchHours();
});
</script>

<template>
    <div class="space-y-8">
        <section>
            <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Personal Hours</p>
            <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">員工時數表</h2>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                依店家設定的薪資倍率拆桶。加班時數須經店家核可才計入薪資。
            </p>
        </section>

        <!-- Controls -->
        <div class="flex flex-wrap items-end gap-3">
            <div v-if="isManager">
                <label class="mb-1 block text-[11px] tracking-[0.02em] text-ink-500">員工</label>
                <select v-model="selectedEmpId"
                    class="h-9 rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500">
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[11px] tracking-[0.02em] text-ink-500">起</label>
                <input v-model="fromDate" type="date"
                    class="h-9 rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500" />
            </div>
            <div>
                <label class="mb-1 block text-[11px] tracking-[0.02em] text-ink-500">迄</label>
                <input v-model="toDate" type="date"
                    class="h-9 rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500" />
            </div>
            <button type="button" @click="fetchHours"
                class="h-9 rounded-[5px] bg-ink-900 px-4 text-[13px] font-medium text-white transition-colors hover:bg-ink-800">
                查詢
            </button>
        </div>

        <div v-if="loading" class="h-64 border-y border-ink-200/60" />

        <template v-else-if="data">
            <!-- 總覽：員工資訊 + 總時數 -->
            <section class="rounded-[8px] border border-ink-200/60 bg-white p-5">
                <p class="text-[18px] font-semibold text-ink-900">{{ data.employee.name }}</p>
                <p class="text-[11px] tracking-[0.02em] text-ink-500">
                    {{ data.range.from }} ~ {{ data.range.to }}
                    <span v-if="data.employee.hourly_wage">· 時薪 ${{ data.employee.hourly_wage }}</span>
                </p>

                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.1em] text-ink-400">總工時</p>
                        <p class="num mt-1 text-[20px] font-semibold tabular-nums text-ink-900">{{ minutesToHM(data.summary.work_minutes) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.1em] text-ink-400">遲到</p>
                        <p class="num mt-1 text-[20px] font-semibold tabular-nums text-ink-900">{{ data.summary.late_minutes }} 分</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.1em] text-ink-400">已核可加班</p>
                        <p class="num mt-1 text-[20px] font-semibold tabular-nums text-success-700">{{ minutesToHM(data.summary.ot_approved_minutes) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.1em] text-ink-400">待核可加班</p>
                        <p class="num mt-1 text-[20px] font-semibold tabular-nums" :class="data.summary.ot_detected_minutes - data.summary.ot_approved_minutes > 0 ? 'text-warning-700' : 'text-ink-400'">
                            {{ minutesToHM(data.summary.ot_detected_minutes - data.summary.ot_approved_minutes) }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- 主表 -->
            <section class="overflow-x-auto">
                <table class="w-full min-w-[800px] border-collapse text-[12px]">
                    <thead>
                        <tr class="border-b border-ink-200/60 bg-ink-50/60 text-ink-600">
                            <th class="px-3 py-2.5 text-left font-medium">月份 / 日期</th>
                            <th class="px-3 py-2.5 text-right font-medium">工時</th>
                            <th class="px-3 py-2.5 text-right font-medium">遲到</th>
                            <th class="px-3 py-2.5 text-right font-medium">待核可</th>
                            <th class="px-3 py-2.5 text-right font-medium">已核可</th>
                            <th v-for="b in buckets" :key="b.multiplier_id" class="px-3 py-2.5 text-right font-medium">
                                {{ b.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="m in data.months" :key="m.month">
                            <!-- 月份 row -->
                            <tr class="cursor-pointer border-b border-ink-200/60 bg-ink-50/30 hover:bg-ink-50" @click="toggleMonth(m.month)">
                                <td class="px-3 py-2.5 font-medium text-ink-900">
                                    <span class="inline-block w-3 text-[10px] text-ink-500">{{ expanded.has(m.month) ? '▾' : '▸' }}</span>
                                    {{ m.month }} ({{ m.records_count }})
                                </td>
                                <td class="num px-3 py-2.5 text-right tabular-nums">{{ minutesToHM(m.work_minutes) }}</td>
                                <td class="num px-3 py-2.5 text-right tabular-nums text-ink-400">—</td>
                                <td class="num px-3 py-2.5 text-right tabular-nums" :class="m.ot_detected_minutes - m.ot_approved_minutes > 0 ? 'text-warning-700' : 'text-ink-400'">
                                    {{ minutesToHM(m.ot_detected_minutes - m.ot_approved_minutes) }}
                                </td>
                                <td class="num px-3 py-2.5 text-right tabular-nums text-success-700">{{ minutesToHM(m.ot_approved_minutes) }}</td>
                                <td v-for="b in buckets" :key="b.multiplier_id" class="num px-3 py-2.5 text-right tabular-nums">
                                    {{ minutesToHM(bucketMinutesForMonth(m, b.multiplier_id)) }}
                                </td>
                            </tr>
                            <!-- 日明細 -->
                            <template v-if="expanded.has(m.month)">
                                <tr v-for="r in m.records" :key="r.id" class="border-b border-ink-200/40 bg-white hover:bg-ink-50/30">
                                    <td class="px-3 py-2 pl-8 text-[11px] text-ink-700">
                                        {{ r.date }}
                                        <span class="text-ink-400">{{ fmtTime(r.clocked_in_at) }} → {{ fmtTime(r.clocked_out_at) }}</span>
                                        <span v-if="r.day_type === 'rest_day'" class="ml-1 rounded-[3px] bg-info-50 px-1 text-[10px] text-info-700">休</span>
                                        <span v-if="r.day_type === 'holiday'" class="ml-1 rounded-[3px] bg-danger-50 px-1 text-[10px] text-danger-700">假</span>
                                        <span v-if="r.pending_approval" class="ml-1 rounded-[3px] bg-warning-50 px-1 text-[10px] text-warning-700">待核可</span>
                                    </td>
                                    <td class="num px-3 py-2 text-right tabular-nums text-ink-700">{{ minutesToHM(r.work_minutes) }}</td>
                                    <td class="num px-3 py-2 text-right tabular-nums" :class="r.late_minutes > 0 ? 'text-warning-700' : 'text-ink-400'">
                                        {{ r.late_minutes > 0 ? r.late_minutes + ' 分' : '—' }}
                                    </td>
                                    <td class="num px-3 py-2 text-right tabular-nums">
                                        <span v-if="r.pending_approval" class="inline-flex items-center gap-1">
                                            <span class="text-warning-700">{{ minutesToHM(r.ot_detected_minutes) }}</span>
                                            <template v-if="isManager">
                                                <button @click="approveOT(r.id)" class="rounded-[3px] bg-success-600 px-1.5 py-0.5 text-[9px] text-white hover:bg-success-700">核可</button>
                                                <button @click="rejectOT(r.id)" class="rounded-[3px] border border-ink-200/60 bg-white px-1.5 py-0.5 text-[9px] text-ink-700 hover:bg-danger-50">拒</button>
                                            </template>
                                        </span>
                                        <span v-else class="text-ink-400">—</span>
                                    </td>
                                    <td class="num px-3 py-2 text-right tabular-nums" :class="r.ot_approved_minutes > 0 ? 'text-success-700' : 'text-ink-400'">
                                        {{ r.ot_approved_minutes > 0 ? minutesToHM(r.ot_approved_minutes) : '—' }}
                                    </td>
                                    <td v-for="b in buckets" :key="b.multiplier_id" class="num px-3 py-2 text-right tabular-nums" :class="(r.buckets?.[b.multiplier_id] ?? 0) > 0 ? 'text-ink-700' : 'text-ink-400'">
                                        {{ (r.buckets?.[b.multiplier_id] ?? 0) > 0 ? minutesToHM(r.buckets[b.multiplier_id]) : '—' }}
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </section>

            <div v-if="data.months.length === 0"
                class="border-y border-ink-200/60 py-16 text-center text-[12px] text-ink-400">
                這段時間沒有打卡紀錄
            </div>
        </template>

        <div v-else class="border-y border-ink-200/60 py-12 text-center text-[12px] text-ink-400">
            無資料
        </div>
    </div>
</template>
