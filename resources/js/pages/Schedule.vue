<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const features = computed(() => auth.user?.current_shop?.features ?? {});

// 一鍵排班策略：依目前開啟的功能動態組
const strategyOptions = computed(() => {
    const opts = [{ val: 'balanced', label: '平衡', desc: '每人天數差距最小（推薦）' }];
    if (features.value.payroll) opts.push({ val: 'cheap', label: '省錢', desc: '低時薪員工優先（含倍率）' });
    if (features.value.skill_score) opts.push({ val: 'senior', label: '重資深', desc: '高分數員工優先' });
    return opts;
});

const loading = ref(true);
const successFlash = ref(null);

const weekStart = ref(null);
const daysCount = ref(14);
const days = ref([]);
const templates = ref([]);
const employees = ref([]);
const entries = ref([]);
const schedule = ref(null);
const availabilities = ref({});  // "empId|date|shiftId" → availability
const leaveDates = ref({});       // empId → [date,...]
const submittedWeeks = ref({});   // empId → { weekStart: true }

const popoverFor = ref(null); // {empId, date}
const isBusy = ref(false);

// 依員工 + 日期 + 時段 三維索引（每格依時段 start_time 由早到晚排）
const entriesByCell = computed(() => {
    const map = new Map();
    entries.value.forEach((e) => {
        const k = `${e.employee_id}|${e.date}`;
        if (!map.has(k)) map.set(k, []);
        map.get(k).push(e);
    });
    const tplMap = templateMap.value;
    for (const arr of map.values()) {
        arr.sort((a, b) => {
            const ta = tplMap.get(a.shift_template_id);
            const tb = tplMap.get(b.shift_template_id);
            return (ta?.start_time ?? '').localeCompare(tb?.start_time ?? '');
        });
    }
    return map;
});

const employeeMap = computed(() => {
    const m = new Map();
    employees.value.forEach((e) => m.set(e.id, e));
    return m;
});

const templateMap = computed(() => {
    const m = new Map();
    templates.value.forEach((t) => m.set(t.id, t));
    return m;
});

function flash(msg, ok = true) {
    successFlash.value = { msg, ok };
    setTimeout(() => (successFlash.value = null), 2200);
}

async function fetchSchedule(week = null, daysParam = null) {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (week) params.set('week', week);
        params.set('days', daysParam ?? daysCount.value);
        const { data } = await axios.get(`/api/schedule?${params.toString()}`);
        weekStart.value = data.week_start;
        daysCount.value = data.days?.length || 14;
        days.value = data.days || [];
        templates.value = data.templates || [];
        employees.value = data.employees || [];
        entries.value = data.entries || [];
        schedule.value = data.schedule;
        availabilities.value = data.availabilities || {};
        leaveDates.value = data.leave_dates || {};
        submittedWeeks.value = data.submitted_weeks || {};
    } catch (e) {
        flash('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

function getCellEntries(empId, date) {
    return entriesByCell.value.get(`${empId}|${date}`) ?? [];
}

function templateAppliesToDay(template, dayOfWeek) {
    return (template.days_of_week_bitmask & (1 << dayOfWeek)) !== 0;
}

async function addEntry(empId, tplId, date) {
    if (isBusy.value) return null;
    isBusy.value = true;
    try {
        const { data } = await axios.post('/api/schedule-entries', {
            employee_id: empId,
            shift_template_id: tplId,
            date,
        });
        entries.value.push(data.data);
        popoverFor.value = null;
        return data.data;
    } catch (e) {
        const msg = e?.response?.data?.error ?? '新增失敗';
        flash(msg, false);
        return null;
    } finally {
        isBusy.value = false;
    }
}

async function removeEntry(entryId) {
    if (isBusy.value) return;
    isBusy.value = true;
    try {
        await axios.delete(`/api/schedule-entries/${entryId}`);
        entries.value = entries.value.filter((e) => e.id !== entryId);
    } catch (e) {
        flash('移除失敗', false);
    } finally {
        isBusy.value = false;
    }
}

function openPopover(empId, date) {
    popoverFor.value = { empId, date };
}
function closePopover() {
    popoverFor.value = null;
}

async function publish() {
    try {
        await axios.post('/api/schedule/publish', { week: weekStart.value });
        flash('班表已發布');
        await fetchSchedule(weekStart.value);
    } catch (e) {
        flash('發布失敗', false);
    }
}

const showCopyModal = ref(false);
const copyReplace = ref(false);
const copyBusy = ref(false);

// === 一鍵排班 ===
const showAutoModal = ref(false);
const autoStrategy = ref('balanced');
const autoReplace = ref(false);
const autoBusy = ref(false);
const autoPreview = ref(null); // { proposed, warnings, summary }

// 當前選的策略若因 toggle 關閉而消失 → 回退到「平衡」
watch(strategyOptions, (opts) => {
    if (!opts.find((o) => o.val === autoStrategy.value)) {
        autoStrategy.value = 'balanced';
    }
});

async function previewAuto() {
    if (autoBusy.value) return;
    autoBusy.value = true;
    autoPreview.value = null;
    try {
        const { data } = await axios.post('/api/schedule/auto-generate/preview', {
            week: weekStart.value,
            days: daysCount.value,
            strategy: autoStrategy.value,
            replace_existing: autoReplace.value,
        });
        autoPreview.value = data;
    } catch (e) {
        flash(e?.response?.data?.error ?? '預覽失敗', false);
    } finally {
        autoBusy.value = false;
    }
}

async function applyAuto() {
    if (autoBusy.value) return;

    // 沒預覽過先預覽，讓 confirm 拿到真實數字
    if (!autoPreview.value) {
        await previewAuto();
        if (!autoPreview.value) return; // 預覽失敗就停
    }

    const fullSlots = autoPreview.value?.summary?.slots_full ?? 0;
    const partialSlots = autoPreview.value?.summary?.slots_partial ?? 0;
    const total = autoPreview.value?.proposed?.length ?? 0;
    const action = autoReplace.value ? '會先清空現有排班，' : '';

    if (!confirm(`${action}預計建立 ${total} 個排班（${fullSlots} 個完整時段、${partialSlots} 個不完整）。繼續嗎？`)) return;

    autoBusy.value = true;
    try {
        const { data } = await axios.post('/api/schedule/auto-generate/apply', {
            week: weekStart.value,
            days: daysCount.value,
            strategy: autoStrategy.value,
            replace_existing: autoReplace.value,
        });
        flash(data.message ?? '套用完成');
        showAutoModal.value = false;
        autoPreview.value = null;
        await fetchSchedule(weekStart.value);
    } catch (e) {
        flash(e?.response?.data?.error ?? '套用失敗', false);
    } finally {
        autoBusy.value = false;
    }
}

function openAutoModal() {
    autoPreview.value = null;
    autoStrategy.value = 'balanced';
    autoReplace.value = false;
    showAutoModal.value = true;
}

// === 一鍵刪除 ===
const clearBusy = ref(false);
async function clearSchedule() {
    if (clearBusy.value || !weekStart.value) return;
    const count = entries.value.length;
    if (count === 0) {
        flash('本期沒有排班可刪除', false);
        return;
    }
    if (!confirm(`確定要刪除本期（${daysCount.value} 天）共 ${count} 個排班？無法復原。`)) return;
    clearBusy.value = true;
    try {
        const { data } = await axios.post('/api/schedule/clear', {
            week: weekStart.value,
            days: daysCount.value,
        });
        flash(data.message ?? '刪除完成');
        await fetchSchedule(weekStart.value);
    } catch (e) {
        flash(e?.response?.data?.error ?? '刪除失敗', false);
    } finally {
        clearBusy.value = false;
    }
}

function priorWeekStr() {
    if (!weekStart.value) return '';
    const d = new Date(weekStart.value);
    d.setDate(d.getDate() - 7);
    return d.toISOString().slice(0, 10);
}

async function copyPriorWeek() {
    if (!weekStart.value || copyBusy.value) return;
    copyBusy.value = true;
    try {
        const { data } = await axios.post('/api/schedule/copy', {
            source_week: priorWeekStr(),
            target_week: weekStart.value,
            replace_existing: copyReplace.value,
        });
        flash(data.message ?? '複製成功');
        showCopyModal.value = false;
        await fetchSchedule(weekStart.value);
    } catch (e) {
        flash(e?.response?.data?.error ?? '複製失敗', false);
    } finally {
        copyBusy.value = false;
    }
}

function printSchedule() {
    window.print();
}

function shiftWeek(deltaWeeks) {
    if (!weekStart.value) return;
    const d = new Date(weekStart.value);
    d.setDate(d.getDate() + deltaWeeks * 7);
    fetchSchedule(d.toISOString().slice(0, 10));
}

// 收集所有違規。若 headcount===0 就只顯示一個「未排」（因為所有後續違規都是它的副作用）
const violations = computed(() => {
    const list = [];
    days.value.forEach((d) => {
        templates.value.forEach((t) => {
            if (!templateAppliesToDay(t, d.day_of_week)) return;
            const s = shiftDayStats(t.id, d.date);
            const label = d.day_label + ' ' + d.weekday_label;

            // 整個時段沒人 → 一個警示就好
            if (s.headcount === 0) {
                list.push({ date: d.date, label, shift: t.name, kind: 'empty', msg: '未排' });
                return;
            }

            if (s.headcount < (t.min_headcount ?? 0)) {
                list.push({ date: d.date, label, shift: t.name, kind: 'headcount', msg: `人數 ${s.headcount}/${t.min_headcount}` });
            }
            if (features.value.skill_score && s.totalScore < (t.required_score ?? 0)) {
                list.push({ date: d.date, label, shift: t.name, kind: 'score', msg: `建議總分 ${s.totalScore}/${t.required_score}（參考）` });
            }
            if (features.value.senior_required && (t.min_senior_count ?? 0) > 0 && s.seniorCount < t.min_senior_count) {
                list.push({ date: d.date, label, shift: t.name, kind: 'senior', msg: `高階 ${s.seniorCount}/${t.min_senior_count}` });
            }
            if (features.value.stations) {
                s.stationCoverage.filter((c) => !c.ok).forEach((c) => {
                    list.push({ date: d.date, label, shift: t.name, kind: 'station', msg: `站別「${c.name}」${c.covered}/${c.min}` });
                });
            }
        });
    });
    return list;
});

const violationsByDate = computed(() => {
    const map = new Map();
    violations.value.forEach((v) => {
        if (!map.has(v.date)) map.set(v.date, { label: v.label, items: [] });
        map.get(v.date).items.push(v);
    });
    return Array.from(map.values());
});

const showViolations = ref(false);

// 員工本期已排總天數
function empDayCount(empId) {
    const dates = new Set();
    entries.value.forEach((e) => {
        if (e.employee_id === empId) dates.add(e.date);
    });
    return dates.size;
}

// 單一 (時段, 日) 的統計
function shiftDayStats(tplId, date) {
    const tpl = templateMap.value.get(tplId);
    const matching = entries.value.filter((e) => e.shift_template_id === tplId && e.date === date);
    const members = matching.map((e) => employeeMap.value.get(e.employee_id)).filter(Boolean);
    const totalScore = members.reduce((s, m) => s + (m.skill_score || 0), 0);
    const seniorCount = members.filter((m) => m.is_senior).length;

    // 站別覆蓋：對每個站別需求，看有幾位「會這站」的員工在這班
    const stationCoverage = (tpl?.station_requirements ?? []).map((req) => {
        const covered = members.filter((m) => (m.station_ids ?? []).includes(req.station_id)).length;
        return {
            station_id: req.station_id,
            name: req.name,
            color: req.color,
            min: req.min_count,
            covered,
            ok: covered >= req.min_count,
        };
    });
    const stationsOk = stationCoverage.every((c) => c.ok);

    return {
        tpl, members, totalScore, seniorCount,
        scoreOk: totalScore >= (tpl?.required_score || 0),
        seniorOk: seniorCount >= (tpl?.min_senior_count || 0),
        headcount: members.length,
        stationCoverage,
        stationsOk,
    };
}

function levelDot(level) {
    return {
        lead: 'bg-sumi-600',
        senior: 'bg-accent-500',
        junior: 'bg-ink-400',
        trainee: 'bg-ink-300',
    }[level] || 'bg-ink-300';
}

function shiftChipClass(tplName) {
    // 各時段固定不同色，店員快速辨識
    const colors = {};
    templates.value.forEach((t, i) => {
        colors[t.name] = ['bg-accent-50 text-accent-700', 'bg-warning-50 text-warning-700', 'bg-success-50 text-success-700', 'bg-ink-100 text-ink-700'][i % 4];
    });
    return colors[tplName] ?? 'bg-ink-100 text-ink-700';
}

// === 複製時段（一鍵貼）===
const copiedTemplate = ref(null);
const pastedEntryIds = ref(new Set());  // 本次複製 session 內貼上的 entry id
const isCopying = computed(() => !!copiedTemplate.value);
const copyHint = computed(() => {
    if (!copiedTemplate.value) return null;
    const n = pastedEntryIds.value.size;
    const prefix = `已複製：${copiedTemplate.value.name}`;
    const counter = n > 0 ? `，已貼 ${n} 個（粗體框 = 本次貼上，再點可取消）` : '';
    return `${prefix}${counter}，點要貼入的格子（紅框 = 不可貼，綠框 = 可貼）`;
});

function toggleCopyTemplate(t) {
    if (copiedTemplate.value?.id === t.id) {
        copiedTemplate.value = null;
    } else {
        copiedTemplate.value = t;
    }
    pastedEntryIds.value = new Set();
}
function cancelCopy() {
    copiedTemplate.value = null;
    pastedEntryIds.value = new Set();
}

function canPasteTo(empId, dayOfWeek, date) {
    if (!copiedTemplate.value) return false;
    const t = copiedTemplate.value;
    // 1. 時段必須適用該日
    if (!templateAppliesToDay(t, dayOfWeek)) return false;
    // 2. 員工該日已有此時段 → 不可重複貼
    const cell = getCellEntries(empId, date);
    if (cell.some((e) => e.shift_template_id === t.id)) return false;
    // 3. 員工該日請假
    if ((leaveDates.value[empId] ?? []).includes(date)) return false;
    // 4. 員工標示此時段不可上班（unavailable）
    const availKey = `${empId}|${date}|${t.id}`;
    const avail = availabilities.value[availKey];
    if (avail === 'unavailable') return false;
    // 5. 員工該週有提交 availability、但這時段沒填 → 視為不願意
    if (avail === undefined) {
        // 算 weekStart（週一）
        const d = new Date(date);
        const dayOfW = d.getDay(); // 0=日
        const offset = dayOfW === 0 ? 6 : dayOfW - 1;
        d.setDate(d.getDate() - offset);
        const weekStart = d.toISOString().slice(0, 10);
        if (submittedWeeks.value[empId]?.[weekStart]) return false;
    }
    return true;
}

async function pasteAt(empId, date) {
    if (!copiedTemplate.value) return;
    const t = copiedTemplate.value;
    const newEntry = await addEntry(empId, t.id, date);
    if (newEntry) {
        // 加入本次貼過的 set（chip 會變粗體）
        pastedEntryIds.value = new Set([...pastedEntryIds.value, newEntry.id]);
    }
    // 維持複製狀態（讓使用者可以連續貼多個）
}

// chip 點擊：
// - 一般模式 → 移除
// - 複製模式 + 是本次貼上 → 取消貼上
// - 複製模式 + 不是本次貼上 → 不動作（避免誤刪舊資料）
async function chipClick(entry) {
    if (!isCopying.value) {
        return removeEntry(entry.id);
    }
    if (pastedEntryIds.value.has(entry.id)) {
        await removeEntry(entry.id);
        const next = new Set(pastedEntryIds.value);
        next.delete(entry.id);
        pastedEntryIds.value = next;
    }
}

function onKeydown(e) {
    if (e.key === 'Escape' && copiedTemplate.value) {
        cancelCopy();
    }
}

onMounted(() => {
    fetchSchedule();
    window.addEventListener('keydown', onKeydown);
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div class="space-y-8">
        <!-- Header -->
        <section class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Schedule</p>
                <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">人員排班</h2>
                <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                    每行一位員工，每格點選後加入時段。本期 {{ daysCount }} 天。
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center rounded-[5px] border border-ink-200/60 bg-white">
                    <button type="button" @click="shiftWeek(-1)"
                        class="px-3 py-1.5 text-[11px] text-ink-500 transition-colors hover:text-ink-900">←</button>
                    <button type="button" @click="fetchSchedule()"
                        class="num border-x border-ink-200/60 px-3.5 py-1.5 text-[11px] font-medium tracking-[0.02em] text-ink-800 transition-colors hover:bg-ink-50">
                        {{ weekStart || '本週' }}
                    </button>
                    <button type="button" @click="shiftWeek(1)"
                        class="px-3 py-1.5 text-[11px] text-ink-500 transition-colors hover:text-ink-900">→</button>
                </div>
                <div class="flex items-center gap-px rounded-[5px] border border-ink-200/60 bg-white p-0.5 text-[11px]">
                    <button v-for="opt in [
                        { val: 7, label: '7 天' },
                        { val: 14, label: '半月' },
                        { val: 28, label: '月' },
                    ]" :key="opt.val" @click="fetchSchedule(weekStart, opt.val)" type="button"
                        class="rounded-[3px] px-2.5 py-1 tracking-[0.05em] transition-colors"
                        :class="daysCount === opt.val ? 'bg-ink-100 text-ink-900 font-medium' : 'text-ink-500 hover:text-ink-900'">
                        {{ opt.label }}
                    </button>
                </div>
                <button type="button" @click="openAutoModal"
                    class="no-print rounded-[5px] border border-accent-500 bg-accent-50 px-3 py-1.5 text-[11px] font-medium tracking-[0.05em] text-accent-700 transition-colors hover:bg-accent-100">
                    一鍵排班
                </button>
                <button type="button" @click="clearSchedule" :disabled="clearBusy"
                    class="no-print rounded-[5px] border border-danger-200 bg-white px-3 py-1.5 text-[11px] tracking-[0.05em] text-danger-700 transition-colors hover:bg-danger-50 disabled:opacity-50">
                    {{ clearBusy ? '刪除中' : '一鍵刪除' }}
                </button>
                <button type="button" @click="showCopyModal = true"
                    class="no-print rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[11px] tracking-[0.05em] text-ink-700 transition-colors hover:bg-ink-50">
                    複製上週
                </button>
                <button type="button" @click="printSchedule"
                    class="no-print rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[11px] tracking-[0.05em] text-ink-700 transition-colors hover:bg-ink-50">
                    列印
                </button>
                <button type="button" @click="publish"
                    class="no-print rounded-[5px] bg-sumi-600 px-4 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500">
                    發布班表
                </button>
            </div>
        </section>

        <!-- Violation summary banner -->
        <div v-if="!loading && violations.length"
            class="no-print rounded-[5px] border border-warning-200 bg-warning-50/60 px-4 py-3">
            <button type="button" @click="showViolations = !showViolations"
                class="flex w-full items-center justify-between text-left">
                <span class="text-[12px] font-medium tracking-[0.02em] text-warning-700">
                    本期有 {{ violations.length }} 項警示（涵蓋 {{ violationsByDate.length }} 天）
                </span>
                <span class="text-[10px] tracking-[0.05em] text-warning-700/70">
                    {{ showViolations ? '收合 ↑' : '展開 ↓' }}
                </span>
            </button>
            <ul v-if="showViolations" class="mt-3 space-y-2 border-t border-warning-200/60 pt-3">
                <li v-for="(g, gi) in violationsByDate" :key="gi"
                    class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[11px]">
                    <span class="num min-w-[60px] font-medium text-ink-900">{{ g.label }}</span>
                    <span v-for="(v, vi) in g.items" :key="vi"
                        class="rounded-[3px] bg-white px-2 py-0.5 tracking-[0.02em]"
                        :class="{
                            'text-warning-700': v.kind === 'score',
                            'text-ink-500': v.kind === 'empty',
                            'text-danger-700': v.kind !== 'score' && v.kind !== 'empty',
                        }">
                        {{ v.shift }}・{{ v.msg }}
                    </span>
                </li>
            </ul>
        </div>

        <!-- Legend / 時段複製器 -->
        <div class="flex flex-wrap items-center gap-2 text-[10px] tracking-[0.05em] text-ink-500">
            <span class="inline-flex items-center gap-1">
                時段（點擊複製）：
            </span>
            <button v-for="t in templates" :key="t.id"
                type="button"
                @click="toggleCopyTemplate(t)"
                :title="copiedTemplate?.id === t.id ? '再點一次取消複製' : `複製 ${t.name} 後可貼到任意格`"
                class="inline-flex items-center gap-1.5 rounded-[3px] px-1.5 py-0.5 text-[10px] transition-all"
                :class="[
                    shiftChipClass(t.name),
                    copiedTemplate?.id === t.id
                        ? 'ring-2 ring-ink-900 ring-offset-1'
                        : 'hover:scale-105',
                ]">
                {{ t.name }}
                <span class="num text-ink-400">{{ t.start_time }}–{{ t.end_time }}</span>
            </button>
            <span class="ml-2 inline-flex items-center gap-1 text-[10px] text-ink-400">
                <span class="h-1.5 w-1.5 rounded-full bg-accent-600" /> 換班過
            </span>
        </div>

        <!-- Copy mode banner -->
        <div v-if="isCopying"
            class="no-print flex items-center justify-between gap-3 rounded-[5px] border-2 border-ink-900 bg-ink-900/5 px-4 py-2 text-[12px]">
            <span class="font-medium tracking-[0.02em] text-ink-900">{{ copyHint }}</span>
            <button type="button" @click="cancelCopy"
                class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-ink-700 transition-colors hover:bg-ink-200/60">
                取消複製（ESC）
            </button>
        </div>

        <div v-if="successFlash"
            class="rounded-[5px] px-3.5 py-2.5 text-[12px] tracking-[0.02em]"
            :class="successFlash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ successFlash.msg }}
        </div>

        <div v-if="loading" class="h-96 border-y border-ink-200/60" />

        <!-- Schedule grid -->
        <div v-else class="overflow-x-auto">
            <table class="min-w-full border-collapse text-[12px]">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-ink-50 px-3 py-2 text-left text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">
                            員工
                        </th>
                        <th class="bg-ink-50 px-3 py-2 text-center text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">
                            本期天數
                        </th>
                        <th v-for="d in days" :key="d.date"
                            class="min-w-[44px] border-l border-ink-200/40 px-1 py-2 text-center"
                            :class="{ 'bg-ink-100/40': d.is_weekend, 'bg-accent-50/30': d.is_today }">
                            <p class="num text-[13px] font-medium tracking-[0.02em]"
                                :class="d.is_today ? 'text-accent-700' : d.is_weekend ? 'text-danger-700' : 'text-ink-700'">
                                {{ d.day_label }}
                            </p>
                            <p class="text-[10px] tracking-[0.05em]"
                                :class="d.is_weekend ? 'text-danger-700/60' : 'text-ink-400'">
                                {{ d.weekday_label }}
                            </p>
                        </th>
                    </tr>
                </thead>

                <!-- Employee rows -->
                <tbody>
                    <tr v-for="emp in employees" :key="emp.id" class="border-t border-ink-200/40">
                        <th class="sticky left-0 z-10 bg-white px-3 py-2 text-left align-top">
                            <div class="flex items-center gap-2">
                                <span class="h-1 w-1 rounded-full" :class="levelDot(emp.level)" />
                                <span class="text-[12px] font-medium text-ink-900">{{ emp.name }}</span>
                            </div>
                            <p class="ml-3 mt-0.5 text-[10px] tracking-[0.05em] text-ink-400">
                                {{ emp.level_label }}<template v-if="features.skill_score">・{{ emp.skill_score }} 分</template>
                            </p>
                        </th>
                        <td class="bg-white px-3 py-2 text-center align-top">
                            <span class="num text-[12px] font-medium text-ink-700">{{ empDayCount(emp.id) }}</span>
                            <span class="text-[10px] text-ink-400"> 天</span>
                        </td>
                        <td v-for="d in days" :key="d.date"
                            class="relative border-l border-ink-200/40 px-1 py-1 text-center align-middle"
                            :class="[
                                d.is_weekend ? 'bg-ink-50/30' : '',
                                isCopying && canPasteTo(emp.id, d.day_of_week, d.date) ? 'paste-ok cursor-copy hover:bg-success-50/40' : '',
                                isCopying && !canPasteTo(emp.id, d.day_of_week, d.date) ? 'paste-no opacity-50' : '',
                            ]"
                            @click="isCopying && canPasteTo(emp.id, d.day_of_week, d.date) ? pasteAt(emp.id, d.date) : null">
                            <!-- 已排的 chips -->
                            <div v-if="getCellEntries(emp.id, d.date).length" class="flex flex-col gap-0.5">
                                <button v-for="e in getCellEntries(emp.id, d.date)" :key="e.id"
                                    type="button" @click.stop="chipClick(e)"
                                    :title="pastedEntryIds.has(e.id)
                                        ? '本次貼上 — 再點一次取消'
                                        : (e.status === 'swapped' ? '此班次曾經換班' : '')"
                                    class="relative rounded-[2px] px-1 py-0.5 text-[10px] tracking-[0.05em] transition-all"
                                    :class="[
                                        shiftChipClass(templateMap.get(e.shift_template_id)?.name || ''),
                                        pastedEntryIds.has(e.id)
                                            ? 'font-bold ring-2 ring-ink-900 shadow-sm scale-105'
                                            : 'font-medium hover:opacity-50',
                                    ]">
                                    {{ templateMap.get(e.shift_template_id)?.name?.replace(/班$/, '') || '?' }}
                                    <span v-if="e.status === 'swapped'"
                                        class="absolute -right-0.5 -top-0.5 h-1.5 w-1.5 rounded-full bg-accent-600 ring-1 ring-white" />
                                </button>
                            </div>
                            <!-- 加入按鈕（非複製模式才顯示 +；複製模式由綠/紅框表示狀態） -->
                            <button v-else-if="!isCopying" type="button"
                                @click.stop="openPopover(emp.id, d.date)"
                                class="h-5 w-full rounded-[2px] text-[10px] text-ink-300 transition-colors hover:bg-ink-100 hover:text-ink-700">
                                +
                            </button>
                            <!-- 複製模式下的空格：完全留白，靠 td 的綠/紅框表示 -->
                            <div v-else class="h-5 w-full"></div>

                            <!-- Popover -->
                            <div v-if="popoverFor && popoverFor.empId === emp.id && popoverFor.date === d.date"
                                class="absolute left-1/2 top-full z-20 mt-1 -translate-x-1/2 rounded-[5px] border border-ink-200/60 bg-white shadow-lg"
                                @click.stop>
                                <div class="px-3 py-2 text-[10px] tracking-[0.05em] text-ink-500 border-b border-ink-200/60">
                                    加入時段
                                </div>
                                <ul class="py-1">
                                    <li v-for="t in templates" :key="t.id">
                                        <button type="button" @click="addEntry(emp.id, t.id, d.date)"
                                            :disabled="!templateAppliesToDay(t, d.day_of_week)"
                                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[12px] transition-colors hover:bg-ink-100 disabled:cursor-not-allowed disabled:opacity-40">
                                            <span class="rounded-[2px] px-1.5 py-0.5 text-[10px] font-medium" :class="shiftChipClass(t.name)">
                                                {{ t.name }}
                                            </span>
                                            <span class="num text-[10px] text-ink-400">
                                                {{ t.start_time }}–{{ t.end_time }}
                                            </span>
                                        </button>
                                    </li>
                                </ul>
                                <button type="button" @click="closePopover"
                                    class="block w-full border-t border-ink-200/60 px-3 py-1.5 text-center text-[10px] tracking-[0.05em] text-ink-400 transition-colors hover:bg-ink-50">
                                    取消
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>

                <!-- Bottom stats rows: per shift per day -->
                <tfoot>
                    <tr v-for="t in templates" :key="t.id" class="border-t border-ink-200/60">
                        <th class="sticky left-0 z-10 bg-ink-50 px-3 py-2 text-left align-top">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px]">
                                <span class="rounded-[2px] px-1.5 py-0.5 text-[10px]" :class="shiftChipClass(t.name)">{{ t.name }}</span>
                                <span v-if="t.min_headcount" class="text-[10px] text-ink-500">人 ≥ {{ t.min_headcount }}</span>
                                <span v-if="features.senior_required && t.min_senior_count" class="text-[10px] text-ink-500">/ 高 ≥ {{ t.min_senior_count }}</span>
                                <span v-if="features.skill_score && t.required_score" class="text-[10px] text-ink-400">/ 建議 {{ t.required_score }}</span>
                                <template v-if="features.stations">
                                    <span v-for="req in (t.station_requirements ?? [])" :key="req.station_id"
                                        class="text-[10px] text-ink-500">
                                        / {{ req.name }} ≥ {{ req.min_count }}
                                    </span>
                                </template>
                            </div>
                        </th>
                        <td class="bg-ink-50 px-3 py-2 text-center text-[10px] text-ink-400">—</td>
                        <td v-for="d in days" :key="d.date"
                            class="border-l border-ink-200/40 px-0.5 py-1 text-center align-middle"
                            :class="{ 'bg-ink-100/30': d.is_weekend }">
                            <template v-if="templateAppliesToDay(t, d.day_of_week)">
                                <div class="flex flex-col items-center gap-0.5">
                                    <!-- 人數 (硬) -->
                                    <span class="num rounded-[2px] px-1 py-0.5 text-[10px] tracking-[0.02em]"
                                        :class="shiftDayStats(t.id, d.date).headcount >= (t.min_headcount ?? 0)
                                            ? 'bg-success-50 text-success-700'
                                            : (shiftDayStats(t.id, d.date).headcount > 0 ? 'bg-danger-50 text-danger-700' : 'bg-ink-100 text-ink-400')">
                                        人 {{ shiftDayStats(t.id, d.date).headcount }}{{ t.min_headcount ? '/' + t.min_headcount : '' }}
                                    </span>
                                    <!-- 高階 (硬) -->
                                    <span v-if="features.senior_required && t.min_senior_count > 0" class="num text-[9px]"
                                        :class="shiftDayStats(t.id, d.date).seniorOk ? 'text-success-700' : 'text-danger-700'">
                                        高 {{ shiftDayStats(t.id, d.date).seniorCount }}/{{ t.min_senior_count }}
                                    </span>
                                    <!-- 站別 (硬) -->
                                    <template v-if="features.stations">
                                        <span v-for="cov in shiftDayStats(t.id, d.date).stationCoverage" :key="cov.station_id"
                                            class="num text-[9px]"
                                            :class="cov.ok ? 'text-success-700' : 'text-danger-700'">
                                            {{ cov.name }} {{ cov.covered }}/{{ cov.min }}
                                        </span>
                                    </template>
                                    <!-- 分數 (軟) -->
                                    <span v-if="features.skill_score && t.required_score > 0" class="num text-[9px]"
                                        :class="shiftDayStats(t.id, d.date).scoreOk ? 'text-ink-400' : 'text-warning-700'">
                                        {{ shiftDayStats(t.id, d.date).totalScore }}/{{ t.required_score }}
                                    </span>
                                </div>
                            </template>
                            <span v-else class="text-[10px] text-ink-300">—</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Status -->
        <div v-if="schedule" class="text-[10px] tracking-[0.05em] text-ink-500">
            狀態：
            <span v-if="schedule.status === 'published'" class="text-success-700">已發布</span>
            <span v-else class="text-warning-700">草稿</span>
        </div>
    </div>

    <!-- 點外部關閉 popover -->
    <div v-if="popoverFor" class="fixed inset-0 z-10" @click="closePopover" />

    <!-- 一鍵排班 modal -->
    <div v-if="showAutoModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 px-4"
        @click.self="showAutoModal = false">
        <div class="w-full max-w-2xl overflow-hidden rounded-[6px] bg-white shadow-xl">
            <div class="border-b border-ink-200/60 px-5 py-4">
                <h3 class="text-[15px] font-semibold text-ink-900">一鍵排班</h3>
                <p class="num mt-1 text-[11px] tracking-[0.02em] text-ink-500">
                    依現有員工<template v-if="features.stations"> / 站別需求</template>自動湊出可行班表。{{ weekStart }} 起的一週
                </p>
            </div>

            <div class="space-y-4 px-5 py-5">
                <!-- 策略選擇 -->
                <div>
                    <label class="mb-2 block text-[12px] font-medium text-ink-700">排班策略</label>
                    <div class="grid gap-2" :class="strategyOptions.length === 1 ? 'grid-cols-1' : (strategyOptions.length === 2 ? 'grid-cols-2' : 'grid-cols-3')">
                        <button v-for="opt in strategyOptions" :key="opt.val" type="button" @click="autoStrategy = opt.val"
                            class="rounded-[5px] border px-3 py-2 text-left transition-colors"
                            :class="autoStrategy === opt.val
                                ? 'border-ink-900 bg-ink-900 text-white'
                                : 'border-ink-200/60 bg-white text-ink-700 hover:bg-ink-50'">
                            <p class="text-[12px] font-medium">{{ opt.label }}</p>
                            <p class="mt-0.5 text-[10px] tracking-[0.02em] opacity-70">{{ opt.desc }}</p>
                        </button>
                    </div>
                </div>

                <label class="flex items-start gap-2 text-[12px] text-ink-700">
                    <input v-model="autoReplace" type="checkbox" class="mt-0.5 rounded border-ink-300" />
                    <span>
                        先清空本週現有排班再排
                        <span class="block text-[10px] text-ink-400">不勾選則保留現有排班、只補空缺</span>
                    </span>
                </label>

                <!-- 預覽結果 -->
                <div v-if="autoPreview" class="rounded-[5px] border border-ink-200/60 bg-ink-50/40 p-4">
                    <div class="flex items-center gap-3 text-[12px] tracking-[0.02em]">
                        <span class="num font-medium text-ink-900">{{ autoPreview.proposed.length }} 項排班</span>
                        <span class="num text-success-700">完整 {{ autoPreview.summary.slots_full }}</span>
                        <span v-if="autoPreview.summary.slots_partial > 0" class="num text-danger-700">
                            不完整 {{ autoPreview.summary.slots_partial }}
                        </span>
                        <span v-if="autoPreview.kept_existing > 0" class="num text-ink-500">
                            保留現有 {{ autoPreview.kept_existing }}
                        </span>
                    </div>

                    <ul v-if="autoPreview.warnings.length" class="mt-3 space-y-0.5 border-t border-ink-200/60 pt-2 text-[11px] tracking-[0.02em] text-danger-700">
                        <li v-for="(w, i) in autoPreview.warnings" :key="i" class="num">• {{ w }}</li>
                    </ul>

                    <details class="mt-3">
                        <summary class="cursor-pointer text-[11px] tracking-[0.05em] text-ink-500 hover:text-ink-900">
                            展開所有 {{ autoPreview.proposed.length }} 項
                        </summary>
                        <ul class="num mt-2 max-h-60 space-y-0.5 overflow-y-auto text-[11px] text-ink-700">
                            <li v-for="(p, i) in autoPreview.proposed" :key="i"
                                :class="{ 'text-ink-400': p.existing }">
                                {{ p.date }} {{ p.shift_name }} — {{ p.employee_name }}
                                <span v-if="p.existing" class="text-[10px] text-ink-300">(已存在)</span>
                            </li>
                        </ul>
                    </details>
                </div>
            </div>

            <div class="flex items-center justify-between gap-2 border-t border-ink-200/60 bg-ink-50/60 px-5 py-3">
                <button type="button" @click="showAutoModal = false" :disabled="autoBusy"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[13px] text-ink-700 transition-colors hover:bg-ink-50 disabled:opacity-50">
                    取消
                </button>
                <div class="flex gap-2">
                    <button type="button" @click="previewAuto" :disabled="autoBusy"
                        class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[13px] text-ink-700 transition-colors hover:bg-ink-50 disabled:opacity-50">
                        {{ autoBusy ? '計算中' : (autoPreview ? '重新預覽' : '預覽') }}
                    </button>
                    <button type="button" @click="applyAuto" :disabled="autoBusy"
                        class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-ink-800 disabled:opacity-50">
                        {{ autoBusy ? '套用中' : (autoPreview ? '套用' : '直接套用') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 複製上週 modal -->
    <div v-if="showCopyModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 px-4"
        @click.self="showCopyModal = false">
        <div class="w-full max-w-md overflow-hidden rounded-[6px] bg-white shadow-xl">
            <div class="border-b border-ink-200/60 px-5 py-4">
                <h3 class="text-[15px] font-semibold text-ink-900">複製上週班表</h3>
                <p class="num mt-1 text-[11px] tracking-[0.02em] text-ink-500">
                    {{ priorWeekStr() }} → {{ weekStart }}
                </p>
            </div>
            <div class="space-y-3 px-5 py-5">
                <p class="text-[12px] tracking-[0.02em] text-ink-600">
                    把上週同一天的時段套到本期相對應的日期。
                </p>
                <label class="flex items-start gap-2 text-[12px] text-ink-700">
                    <input v-model="copyReplace" type="checkbox" class="mt-0.5 rounded border-ink-300" />
                    <span>
                        先清空本週現有排班再複製
                        <span class="block text-[10px] text-ink-400">不勾選則只補上不重複的項目</span>
                    </span>
                </label>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-ink-200/60 bg-ink-50/60 px-5 py-3">
                <button type="button" @click="showCopyModal = false" :disabled="copyBusy"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[13px] text-ink-700 transition-colors hover:bg-ink-50 disabled:opacity-50">
                    取消
                </button>
                <button type="button" @click="copyPriorWeek" :disabled="copyBusy"
                    class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-ink-800 disabled:opacity-50">
                    {{ copyBusy ? '複製中' : '確認複製' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
    table { font-size: 11px !important; }
    @page { size: A4 landscape; margin: 10mm; }
}

/* 複製模式：用 outline 而非 border/box-shadow，
   outline 不會被表格 cell border-collapse 吃掉，比 ring 更穩 */
td.paste-ok {
    outline: 2px solid #15803d; /* success-700 */
    outline-offset: -2px;
    position: relative;
    z-index: 1;
}
td.paste-no {
    outline: 2px solid #dc2626; /* danger-600 */
    outline-offset: -2px;
    position: relative;
    z-index: 1;
}
</style>
