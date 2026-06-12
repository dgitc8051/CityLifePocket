<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const features = computed(() => auth.user?.current_shop?.features ?? {});

const loading = ref(true);
const weekStart = ref(null);
const days = ref([]);
const templates = ref([]);
const matrix = ref([]);
const error = ref(null);
const flash = ref(null);

const editingEmployee = ref(null);
const editingCells = ref({});
const saving = ref(false);

function showFlash(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2000);
}

async function fetchMatrix(week = null) {
    loading.value = true;
    try {
        const url = week ? `?week=${week}` : '';
        const { data } = await axios.get(`/api/availability/matrix${url}`);
        weekStart.value = data.week_start;
        days.value = data.days;
        templates.value = data.templates;
        matrix.value = data.matrix;
    } catch (e) {
        error.value = '讀取失敗';
    } finally {
        loading.value = false;
    }
}

function isSelf(emp) {
    return emp.user_id && auth.user?.id === emp.user_id;
}

function startEdit(emp) {
    editingEmployee.value = emp;
    editingCells.value = {};
    emp.cells.forEach((c) => {
        const key = `${c.day_of_week}-${c.shift_template_id}`;
        // 舊資料若是 maybe → 視為 unavailable（更明確），新編輯不再產生 maybe
        editingCells.value[key] = c.availability === 'maybe'
            ? 'unavailable'
            : (c.availability || 'unavailable');
    });
    // 新時段預設「不行」，讓店家明確標可上
    days.value.forEach((d) => {
        templates.value.forEach((tpl) => {
            const key = `${d.day_of_week}-${tpl.id}`;
            if (editingCells.value[key] === undefined) {
                editingCells.value[key] = 'unavailable';
            }
        });
    });
}

async function saveEdit() {
    if (!editingEmployee.value) return;
    saving.value = true;
    try {
        const entries = Object.entries(editingCells.value).map(([key, availability]) => {
            const [day_of_week, shift_template_id] = key.split('-').map(Number);
            return { day_of_week, shift_template_id, availability };
        });
        await axios.post('/api/availability', {
            employee_id: editingEmployee.value.employee_id,
            week_start_date: weekStart.value,
            source: 'manager_proxy',
            entries,
        });
        editingEmployee.value = null;
        showFlash('已儲存');
        await fetchMatrix(weekStart.value);
    } catch (e) {
        showFlash('儲存失敗', false);
    } finally {
        saving.value = false;
    }
}

function shiftWeek(delta) {
    if (!weekStart.value) return;
    const d = new Date(weekStart.value);
    d.setDate(d.getDate() + delta * 7);
    fetchMatrix(d.toISOString().slice(0, 10));
}

function cellClass(availability) {
    if (availability === 'available') return 'bg-success-50 text-success-700 ring-1 ring-success-700/30';
    if (availability === 'unavailable') return 'bg-danger-50 text-danger-700 ring-1 ring-danger-700/30';
    // 舊資料相容：maybe 視覺上仍顯示，但編輯時會被改成 unavailable
    if (availability === 'maybe') return 'bg-warning-50 text-warning-700 ring-1 ring-warning-700/30';
    return 'bg-ink-50 text-ink-400';
}

function cellSymbol(availability) {
    return { available: '✓', unavailable: '✗', maybe: '?' }[availability] || '—';
}

function cycleAvail(key) {
    // 只在「可上」 ↔ 「不行」之間切換
    const current = editingCells.value[key];
    editingCells.value[key] = current === 'available' ? 'unavailable' : 'available';
}

// 一鍵套用整位員工所有時段
function bulkSet(avail) {
    if (!editingEmployee.value) return;
    days.value.forEach((d) => {
        templates.value.forEach((tpl) => {
            editingCells.value[`${d.day_of_week}-${tpl.id}`] = avail;
        });
    });
}

// 一鍵套用某天所有時段
function bulkSetDay(dayOfWeek, avail) {
    if (!editingEmployee.value) return;
    templates.value.forEach((tpl) => {
        editingCells.value[`${dayOfWeek}-${tpl.id}`] = avail;
    });
}

// 一鍵套用某時段所有日
function bulkSetShift(shiftId, avail) {
    if (!editingEmployee.value) return;
    days.value.forEach((d) => {
        editingCells.value[`${d.day_of_week}-${shiftId}`] = avail;
    });
}

const showBulkDayMenu = ref(null); // dayOfWeek 或 null
const showBulkShiftMenu = ref(null); // shiftId 或 null
function toggleDayMenu(dow) {
    showBulkDayMenu.value = showBulkDayMenu.value === dow ? null : dow;
    showBulkShiftMenu.value = null;
}
function toggleShiftMenu(sid) {
    showBulkShiftMenu.value = showBulkShiftMenu.value === sid ? null : sid;
    showBulkDayMenu.value = null;
}

// 預設可上時段：把當前編輯中的格子儲存為員工的預設
async function saveAsDefault() {
    if (!editingEmployee.value) return;
    if (!confirm('把目前編輯中的時段儲存為此員工的「預設」？日後可以一鍵套用到任何一週。')) return;
    saving.value = true;
    try {
        const entries = Object.entries(editingCells.value).map(([key, availability]) => {
            const [day_of_week, shift_template_id] = key.split('-').map(Number);
            return { day_of_week, shift_template_id, availability };
        });
        await axios.post(`/api/availability/defaults/${editingEmployee.value.employee_id}`, { entries });
        showFlash('已儲存為預設');
    } catch (e) {
        showFlash('儲存預設失敗', false);
    } finally {
        saving.value = false;
    }
}

// 把員工預設套用到當前週，並覆蓋編輯中的格子
async function loadDefault() {
    if (!editingEmployee.value) return;
    if (!confirm('用此員工的「預設」覆蓋目前編輯中的格子？')) return;
    saving.value = true;
    try {
        const { data } = await axios.get(`/api/availability/defaults/${editingEmployee.value.employee_id}`);
        if (!data.data?.length) {
            showFlash('此員工尚未設定預設', false);
            return;
        }
        data.data.forEach((r) => {
            editingCells.value[`${r.day_of_week}-${r.shift_template_id}`] = r.availability;
        });
        showFlash(`已載入 ${data.data.length} 筆預設`);
    } catch (e) {
        showFlash('讀取預設失敗', false);
    } finally {
        saving.value = false;
    }
}

onMounted(() => fetchMatrix());
</script>

<template>
    <div class="space-y-6">
        <section class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="font-serif text-[24px] font-medium tracking-tight text-ink-900">可上時段</h2>
                <p class="mt-1 text-[13px] text-ink-500">
                    每位員工每週可標示哪些時段可以上班。店長可代填。
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
                    @click="fetchMatrix()"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1 text-[12px] font-medium text-ink-700 tabular-nums"
                >
                    {{ weekStart || '本週' }}
                </button>
                <button
                    type="button"
                    @click="shiftWeek(1)"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-2.5 py-1 text-[12px] text-ink-600 transition-colors hover:bg-ink-50"
                >
                    下週 →
                </button>
            </div>
        </section>

        <div v-if="flash" class="rounded-[5px] px-3 py-2 text-[12px]" :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <div class="flex flex-wrap items-center gap-3 text-[11px] text-ink-500">
            <span class="inline-flex items-center gap-1">
                <span class="rounded bg-success-50 px-1.5 py-0.5 text-success-700 font-medium">✓</span> 可上
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="rounded bg-danger-50 px-1.5 py-0.5 text-danger-700 font-medium">✗</span> 不行
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="rounded bg-ink-50 px-1.5 py-0.5 text-ink-400">—</span> 未填
            </span>
        </div>

        <div v-if="loading" class="h-64 rounded-[6px] border border-ink-200/60 bg-white" />

        <div v-else class="space-y-3">
            <article
                v-for="emp in matrix"
                :key="emp.employee_id"
                class="overflow-hidden rounded-[6px] border border-ink-200/60 bg-white"
            >
                <header class="flex items-center justify-between border-b border-ink-200/60 px-4 py-2.5">
                    <div class="flex items-baseline gap-2">
                        <p class="text-[14px] font-medium text-ink-900">{{ emp.employee_name }}</p>
                        <p v-if="features.skill_score" class="text-[11px] text-ink-500">分數 {{ emp.skill_score }}</p>
                        <span
                            v-if="emp.submitted"
                            class="rounded-[5px] bg-success-50 px-1.5 py-0.5 text-[10px] font-medium text-success-700"
                        >已填</span>
                        <span
                            v-else
                            class="rounded-[5px] bg-warning-50 px-1.5 py-0.5 text-[10px] font-medium text-warning-700"
                        >未填</span>
                    </div>
                    <button
                        v-if="editingEmployee?.employee_id !== emp.employee_id"
                        type="button"
                        @click="startEdit(emp)"
                        class="rounded-[5px] px-2 py-1 text-[12px] text-accent-600 transition-colors hover:bg-accent-50"
                    >
                        {{ isSelf(emp) ? '填寫' : '代填' }}
                    </button>
                </header>

                <!-- Bulk actions (edit mode only) -->
                <div v-if="editingEmployee?.employee_id === emp.employee_id"
                    class="flex flex-wrap items-center gap-1.5 border-b border-ink-200/60 bg-ink-50/40 px-3 py-2 text-[11px]">
                    <span class="mr-1 text-ink-500">全部套用：</span>
                    <button type="button" @click="bulkSet('available')"
                        class="rounded-[4px] border border-success-700/30 bg-success-50 px-2 py-0.5 text-success-700 transition-colors hover:bg-success-100">
                        ✓ 全部可上
                    </button>
                    <button type="button" @click="bulkSet('unavailable')"
                        class="rounded-[4px] border border-danger-700/30 bg-danger-50 px-2 py-0.5 text-danger-700 transition-colors hover:bg-danger-100">
                        ✗ 全部不行
                    </button>
                    <span class="ml-2 text-[10px] text-ink-400">點欄/列標題可批次設定該天/該時段</span>
                </div>

                <div class="overflow-x-auto">
                    <div class="min-w-[640px]">
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="bg-ink-50/60 text-ink-500">
                                    <th class="w-20 px-3 py-2 text-left font-medium">時段</th>
                                    <th
                                        v-for="d in days"
                                        :key="d.date"
                                        class="relative px-2 py-2 text-center font-medium tabular-nums"
                                    >
                                        <button
                                            v-if="editingEmployee?.employee_id === emp.employee_id"
                                            type="button"
                                            @click.stop="toggleDayMenu(d.day_of_week)"
                                            class="rounded px-1 py-0.5 transition-colors hover:bg-ink-200/50"
                                        >
                                            {{ d.label }}
                                        </button>
                                        <span v-else>{{ d.label }}</span>
                                        <!-- Day bulk menu -->
                                        <div v-if="editingEmployee?.employee_id === emp.employee_id && showBulkDayMenu === d.day_of_week"
                                            class="absolute left-1/2 top-full z-20 mt-1 -translate-x-1/2 rounded-[5px] border border-ink-200/60 bg-white p-1 shadow-lg">
                                            <button type="button" @click.stop="bulkSetDay(d.day_of_week, 'available'); toggleDayMenu(d.day_of_week)"
                                                class="block w-full whitespace-nowrap rounded px-2.5 py-1 text-left text-[11px] text-success-700 hover:bg-success-50">✓ 此日可上</button>
                                            <button type="button" @click.stop="bulkSetDay(d.day_of_week, 'unavailable'); toggleDayMenu(d.day_of_week)"
                                                class="block w-full whitespace-nowrap rounded px-2.5 py-1 text-left text-[11px] text-danger-700 hover:bg-danger-50">✗ 此日不行</button>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tpl in templates" :key="tpl.id" class="border-t border-ink-200/60">
                                    <td class="relative px-3 py-2 font-medium text-ink-700">
                                        <button
                                            v-if="editingEmployee?.employee_id === emp.employee_id"
                                            type="button"
                                            @click.stop="toggleShiftMenu(tpl.id)"
                                            class="rounded px-1 py-0.5 text-left transition-colors hover:bg-ink-200/50"
                                        >
                                            {{ tpl.name }}
                                            <span class="block text-[10px] text-ink-400 tabular-nums">
                                                {{ tpl.start_time }}–{{ tpl.end_time }}
                                            </span>
                                        </button>
                                        <template v-else>
                                            {{ tpl.name }}
                                            <span class="block text-[10px] text-ink-400 tabular-nums">
                                                {{ tpl.start_time }}–{{ tpl.end_time }}
                                            </span>
                                        </template>
                                        <!-- Shift bulk menu -->
                                        <div v-if="editingEmployee?.employee_id === emp.employee_id && showBulkShiftMenu === tpl.id"
                                            class="absolute left-full top-0 z-20 ml-1 rounded-[5px] border border-ink-200/60 bg-white p-1 shadow-lg">
                                            <button type="button" @click.stop="bulkSetShift(tpl.id, 'available'); toggleShiftMenu(tpl.id)"
                                                class="block w-full whitespace-nowrap rounded px-2.5 py-1 text-left text-[11px] text-success-700 hover:bg-success-50">✓ 此時段全可上</button>
                                            <button type="button" @click.stop="bulkSetShift(tpl.id, 'unavailable'); toggleShiftMenu(tpl.id)"
                                                class="block w-full whitespace-nowrap rounded px-2.5 py-1 text-left text-[11px] text-danger-700 hover:bg-danger-50">✗ 此時段全不行</button>
                                        </div>
                                    </td>
                                    <td
                                        v-for="d in days"
                                        :key="d.date"
                                        class="p-1.5 text-center"
                                    >
                                        <template v-if="editingEmployee?.employee_id === emp.employee_id">
                                            <button
                                                type="button"
                                                @click="cycleAvail(`${d.day_of_week}-${tpl.id}`)"
                                                class="inline-flex h-6 w-8 items-center justify-center rounded text-[11px] font-medium transition-colors"
                                                :class="cellClass(editingCells[`${d.day_of_week}-${tpl.id}`])"
                                            >
                                                {{ cellSymbol(editingCells[`${d.day_of_week}-${tpl.id}`]) }}
                                            </button>
                                        </template>
                                        <template v-else>
                                            <span
                                                class="inline-flex h-6 w-8 items-center justify-center rounded text-[11px] font-medium"
                                                :class="cellClass(emp.cells.find(c => c.day_of_week === d.day_of_week && c.shift_template_id === tpl.id)?.availability)"
                                            >
                                                {{ cellSymbol(emp.cells.find(c => c.day_of_week === d.day_of_week && c.shift_template_id === tpl.id)?.availability) }}
                                            </span>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-if="editingEmployee?.employee_id === emp.employee_id"
                    class="flex flex-wrap items-center justify-end gap-2 border-t border-ink-200/60 bg-ink-50/60 px-4 py-2.5"
                >
                    <p class="mr-auto text-[11px] text-ink-500">點格子可循環切換</p>
                    <button
                        type="button"
                        @click="loadDefault"
                        :disabled="saving"
                        class="rounded-[5px] border border-ink-200/60 bg-white px-2.5 py-1 text-[11px] text-ink-600 transition-colors hover:bg-ink-50"
                    >
                        載入預設
                    </button>
                    <button
                        type="button"
                        @click="saveAsDefault"
                        :disabled="saving"
                        class="rounded-[5px] border border-ink-200/60 bg-white px-2.5 py-1 text-[11px] text-ink-600 transition-colors hover:bg-ink-50"
                    >
                        儲存為預設
                    </button>
                    <span class="mx-1 h-4 w-px bg-ink-200/60"></span>
                    <button
                        type="button"
                        @click="editingEmployee = null"
                        :disabled="saving"
                        class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1 text-[12px] text-ink-700 transition-colors hover:bg-ink-50"
                    >
                        取消
                    </button>
                    <button
                        type="button"
                        @click="saveEdit"
                        :disabled="saving"
                        class="rounded-[5px] bg-ink-900 px-3 py-1 text-[12px] font-medium text-white transition-colors hover:bg-ink-800 disabled:opacity-50"
                    >
                        {{ saving ? '儲存中' : '儲存' }}
                    </button>
                </div>
            </article>
        </div>
    </div>
</template>
