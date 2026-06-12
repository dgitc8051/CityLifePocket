<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const features = computed(() => auth.user?.current_shop?.features ?? {});
const sortOptions = computed(() => {
    const base = [
        { val: 'hours', label: '工時' },
        { val: 'shifts', label: '班次' },
    ];
    if (features.value.skill_score) base.push({ val: 'score', label: '分數' });
    base.push({ val: 'name', label: '姓名' });
    return base;
});

const loading = ref(true);
const weekStart = ref(null);
const weekEnd = ref(null);
const rows = ref([]);
const totals = ref({ employees: 0, shifts: 0, hours: 0 });
const coverage = ref([]);

const sortBy = ref('hours');

const sortedRows = computed(() => {
    const arr = [...rows.value];
    arr.sort((a, b) => {
        if (sortBy.value === 'hours') return b.total_hours - a.total_hours;
        if (sortBy.value === 'shifts') return b.shifts_count - a.shifts_count;
        if (sortBy.value === 'score') return b.skill_score - a.skill_score;
        return a.name.localeCompare(b.name);
    });
    return arr;
});

async function fetchData(week = null) {
    loading.value = true;
    try {
        const url = week ? `?week=${week}` : '';
        const [hoursRes, covRes] = await Promise.all([
            axios.get(`/api/reports/weekly-hours${url}`),
            axios.get(`/api/reports/shift-coverage${url}`),
        ]);
        weekStart.value = hoursRes.data.week_start;
        weekEnd.value = hoursRes.data.week_end;
        rows.value = hoursRes.data.rows;
        totals.value = hoursRes.data.totals;
        coverage.value = covRes.data.rows;
    } finally {
        loading.value = false;
    }
}

function shiftWeek(delta) {
    if (!weekStart.value) return;
    const d = new Date(weekStart.value);
    d.setDate(d.getDate() + delta * 7);
    fetchData(d.toISOString().slice(0, 10));
}

function downloadCsv() {
    if (!weekStart.value) return;
    window.location.href = `/api/reports/schedule.csv?week=${weekStart.value}`;
}

function levelDot(level) {
    return {
        lead: 'bg-sumi-600',
        senior: 'bg-success-700',
        junior: 'bg-warning-700',
        trainee: 'bg-ink-400',
    }[level] || 'bg-ink-400';
}

onMounted(() => fetchData());
</script>

<template>
    <div class="space-y-8">
        <section class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="font-serif text-[24px] font-medium tracking-tight text-ink-900">報表</h2>
                <p class="mt-1 text-[13px] text-ink-500">
                    員工工時、班次數、時段達標率與 CSV 匯出。
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="shiftWeek(-1)"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-2.5 py-1 text-[12px] text-ink-600 transition-colors hover:bg-ink-50"
                >
                    ← 上週
                </button>
                <button
                    type="button"
                    @click="fetchData()"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1 text-[12px] font-medium text-ink-700 tabular-nums"
                >
                    {{ weekStart || '本週' }} ~ {{ weekEnd?.slice(5) || '' }}
                </button>
                <button
                    type="button"
                    @click="shiftWeek(1)"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-2.5 py-1 text-[12px] text-ink-600 transition-colors hover:bg-ink-50"
                >
                    下週 →
                </button>
                <button
                    type="button"
                    @click="downloadCsv"
                    class="ml-2 rounded-[5px] bg-ink-900 px-3 py-1.5 text-[12px] font-medium text-white transition-colors hover:bg-ink-800"
                >
                    匯出 CSV
                </button>
            </div>
        </section>

        <!-- Totals -->
        <section class="grid grid-cols-3 gap-3">
            <article class="rounded-[6px] border border-ink-200/60 bg-white p-4">
                <p class="text-[11px] text-ink-500">參與員工數</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-ink-900">{{ totals.employees }}</p>
            </article>
            <article class="rounded-[6px] border border-ink-200/60 bg-white p-4">
                <p class="text-[11px] text-ink-500">總班次數</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-ink-900">{{ totals.shifts }}</p>
            </article>
            <article class="rounded-[6px] border border-ink-200/60 bg-white p-4">
                <p class="text-[11px] text-ink-500">總工時</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-ink-900">{{ totals.hours }} <span class="text-[14px] text-ink-500">小時</span></p>
            </article>
        </section>

        <!-- Coverage -->
        <section>
            <h3 class="mb-3 font-serif text-[16px] font-medium tracking-tight text-ink-900">時段達標率</h3>
            <div v-if="coverage.length === 0" class="rounded-[6px] border border-dashed border-ink-300 bg-ink-50/50 px-5 py-8 text-center text-[13px] text-ink-500">
                尚無資料
            </div>
            <div v-else class="overflow-hidden rounded-[6px] border border-ink-200/60 bg-white">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="border-b border-ink-200/60 bg-ink-50/60 text-left text-[12px] text-ink-500">
                            <th class="px-4 py-2.5 font-medium">時段</th>
                            <th class="px-4 py-2.5 font-medium">時間</th>
                            <th class="px-4 py-2.5 font-medium">設定</th>
                            <th v-if="features.senior_required" class="px-4 py-2.5 font-medium">高階達標率</th>
                            <th v-if="features.stations" class="px-4 py-2.5 font-medium">站別覆蓋率</th>
                            <th v-if="features.skill_score" class="px-4 py-2.5 font-medium">建議分數達標率</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-200/60">
                        <tr v-for="r in coverage" :key="r.shift_id">
                            <td class="px-4 py-2.5 font-medium text-ink-900">{{ r.shift_name }}</td>
                            <td class="px-4 py-2.5 tabular-nums text-ink-600">{{ r.time }}</td>
                            <td class="px-4 py-2.5 text-ink-600">
                                <span v-if="features.senior_required">高 {{ r.min_senior_count }}</span>
                                <span v-if="features.senior_required && features.skill_score"> / </span>
                                <span v-if="features.skill_score">建議 {{ r.required_score }}</span>
                                <span v-if="!features.senior_required && !features.skill_score">人 ≥ {{ r.min_headcount ?? '?' }}</span>
                            </td>
                            <td v-if="features.senior_required" class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-ink-100">
                                        <div class="h-full"
                                            :class="r.coverage_senior >= 100 ? 'bg-success-700' : 'bg-danger-700'"
                                            :style="{ width: `${r.coverage_senior}%` }" />
                                    </div>
                                    <span class="num text-[12px] text-ink-700">{{ r.coverage_senior }}%</span>
                                </div>
                            </td>
                            <td v-if="features.stations" class="px-4 py-2.5">
                                <div v-if="r.has_station_req" class="flex items-center gap-2">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-ink-100">
                                        <div class="h-full"
                                            :class="r.coverage_stations >= 100 ? 'bg-success-700' : 'bg-danger-700'"
                                            :style="{ width: `${r.coverage_stations}%` }" />
                                    </div>
                                    <span class="num text-[12px] text-ink-700">{{ r.coverage_stations }}%</span>
                                </div>
                                <span v-else class="text-[11px] text-ink-400">未設站別需求</span>
                            </td>
                            <td v-if="features.skill_score" class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-ink-100">
                                        <div class="h-full"
                                            :class="r.coverage_score >= 100 ? 'bg-success-700' : 'bg-warning-700'"
                                            :style="{ width: `${r.coverage_score}%` }" />
                                    </div>
                                    <span class="num text-[12px] text-ink-400">{{ r.coverage_score }}%</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Employee hours -->
        <section>
            <div class="mb-3 flex items-end justify-between">
                <h3 class="font-serif text-[16px] font-medium tracking-tight text-ink-900">員工工時</h3>
                <div class="flex items-center gap-1 rounded-[5px] bg-ink-100 p-0.5 text-[12px]">
                    <button
                        v-for="opt in sortOptions"
                        :key="opt.val"
                        @click="sortBy = opt.val"
                        type="button"
                        class="rounded px-2.5 py-1 transition-colors"
                        :class="sortBy === opt.val ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-600 hover:text-ink-900'"
                    >
                        排序：{{ opt.label }}
                    </button>
                </div>
            </div>

            <div v-if="loading" class="h-64 rounded-[6px] border border-ink-200/60 bg-white" />
            <div v-else class="overflow-hidden rounded-[6px] border border-ink-200/60 bg-white">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="border-b border-ink-200/60 bg-ink-50/60 text-left text-[12px] text-ink-500">
                            <th class="px-4 py-2.5 font-medium">姓名</th>
                            <th class="px-4 py-2.5 font-medium">等級</th>
                            <th v-if="features.skill_score" class="px-4 py-2.5 font-medium">分數</th>
                            <th class="px-4 py-2.5 font-medium">本週班次</th>
                            <th class="px-4 py-2.5 font-medium">本週工時</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-200/60">
                        <tr v-for="r in sortedRows" :key="r.employee_id">
                            <td class="px-4 py-2.5 font-medium text-ink-900">{{ r.name }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2 text-ink-700">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="levelDot(r.level)" />
                                    <span>{{ {lead: '領班', senior: '熟手', junior: '初階', trainee: '新手'}[r.level] }}</span>
                                </div>
                            </td>
                            <td v-if="features.skill_score" class="px-4 py-2.5 tabular-nums text-ink-700">{{ r.skill_score }}</td>
                            <td class="px-4 py-2.5 tabular-nums text-ink-700">{{ r.shifts_count }}</td>
                            <td class="px-4 py-2.5 tabular-nums text-ink-900 font-medium">{{ r.total_hours }} 小時</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
