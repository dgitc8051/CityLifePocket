<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import LeaveFormModal from '../components/LeaveFormModal.vue';

const loading = ref(true);
const leaves = ref([]);
const meta = ref({ total: 0, pending: 0, approved: 0, rejected: 0 });
const flash = ref(null);
const statusFilter = ref('pending');
const search = ref('');
const showCreateModal = ref(false);
const employees = ref([]);
const reviewing = ref(null);
const rejectNote = ref('');

const filtered = computed(() => {
    let list = leaves.value;
    if (statusFilter.value !== 'all') list = list.filter((l) => l.status === statusFilter.value);
    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        list = list.filter((l) =>
            (l.employee_name || '').toLowerCase().includes(q) ||
            (l.reason || '').toLowerCase().includes(q),
        );
    }
    return list;
});

function showFlash(msg, ok = true) { flash.value = { msg, ok }; setTimeout(() => (flash.value = null), 2500); }

async function fetchLeaves() {
    loading.value = true;
    try { const { data } = await axios.get('/api/leaves'); leaves.value = data.data; meta.value = data.meta; }
    catch (e) { } finally { loading.value = false; }
}
async function fetchEmployees() {
    try { const { data } = await axios.get('/api/employees?status=active'); employees.value = data.data; } catch (e) { }
}
async function approve(l) {
    try { await axios.post(`/api/leaves/${l.id}/approve`); showFlash('已核准'); await fetchLeaves(); }
    catch (e) { showFlash(e?.response?.data?.error ?? '處理失敗', false); }
}
function startReject(l) { reviewing.value = l; rejectNote.value = ''; }
async function confirmReject() {
    if (!rejectNote.value.trim()) { showFlash('請輸入拒絕原因', false); return; }
    try { await axios.post(`/api/leaves/${reviewing.value.id}/reject`, { review_note: rejectNote.value });
        reviewing.value = null; showFlash('已拒絕'); await fetchLeaves();
    } catch (e) { showFlash('處理失敗', false); }
}
async function cancelLeave(l) {
    if (!confirm(`取消「${l.employee_name}」的請假？`)) return;
    try { await axios.delete(`/api/leaves/${l.id}`); showFlash('已取消'); await fetchLeaves(); }
    catch (e) { showFlash('處理失敗', false); }
}
async function handleCreate(payload) {
    try { await axios.post('/api/leaves', payload); showCreateModal.value = false; showFlash('已建立'); await fetchLeaves(); return null; }
    catch (e) { const errs = e?.response?.data?.errors; return errs ? Object.values(errs).flat().join('\n') : '處理失敗'; }
}
function statusClass(s) { return {
    'text-warning-700': s === 'pending',
    'text-success-700': s === 'approved',
    'text-danger-700': s === 'rejected',
    'text-ink-400': s === 'cancelled',
}; }
function pad2(n) {
    return String(n).padStart(2, '0');
}
function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return `${d.getMonth() + 1}/${d.getDate()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}
function formatRange(l) {
    const s = new Date(l.start_datetime), e = new Date(l.end_datetime);
    const sameDay = s.toDateString() === e.toDateString();
    const fullDay = sameDay && s.getHours() === 0 && s.getMinutes() === 0 && e.getHours() === 23 && e.getMinutes() === 59;
    if (fullDay) return `${s.getMonth() + 1}/${s.getDate()} 全天`;
    if (sameDay) {
        const fmt = (d) => `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
        return `${s.getMonth() + 1}/${s.getDate()} ${fmt(s)}–${fmt(e)}`;
    }
    return `${formatDateTime(l.start_datetime)} → ${formatDateTime(l.end_datetime)}`;
}

onMounted(async () => { await Promise.all([fetchLeaves(), fetchEmployees()]); });
</script>

<template>
    <div class="space-y-10">
        <section class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Leaves</p>
                <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">請假</h2>
                <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                    員工申請的審核與店長代為提交。
                </p>
            </div>
            <button type="button" @click="showCreateModal = true"
                class="rounded-[5px] bg-sumi-600 px-4 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500">
                代為提交
            </button>
        </section>

        <!-- Stats — grid with hairlines -->
        <section class="grid grid-cols-2 gap-px bg-ink-200/60 sm:grid-cols-4">
            <article v-for="s in [
                { label: '待審核', value: meta.pending, tone: 'warn' },
                { label: '已核准', value: meta.approved, tone: 'ok' },
                { label: '已拒絕', value: meta.rejected, tone: 'neutral' },
                { label: '總計', value: meta.total, tone: 'neutral' },
            ]" :key="s.label" class="bg-white px-5 py-6">
                <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">{{ s.label }}</p>
                <p class="num mt-3 text-[28px] font-light leading-none"
                    :class="s.tone === 'warn' ? 'text-warning-700' : s.tone === 'ok' ? 'text-success-700' : 'text-ink-900'">
                    {{ s.value }}
                </p>
            </article>
        </section>

        <!-- Filters -->
        <section class="flex flex-wrap items-center gap-4">
            <input v-model="search" type="search" placeholder="搜尋姓名或原因"
                class="h-9 w-full max-w-xs rounded-[5px] border border-ink-200/60 bg-white px-3.5 text-[12px] outline-none transition-colors focus:border-ink-400" />
            <nav class="flex items-center gap-px rounded-[5px] border border-ink-200/60 bg-white p-0.5 text-[11px]">
                <button v-for="opt in [
                    { val: 'pending', label: '待審' },
                    { val: 'approved', label: '已核准' },
                    { val: 'rejected', label: '已拒絕' },
                    { val: 'all', label: '全部' },
                ]" :key="opt.val" @click="statusFilter = opt.val" type="button"
                    class="rounded-[3px] px-3 py-1 tracking-[0.05em] transition-colors"
                    :class="statusFilter === opt.val
                        ? 'bg-ink-100 text-ink-900 font-medium'
                        : 'text-ink-500 hover:text-ink-900'">
                    {{ opt.label }}
                </button>
            </nav>
        </section>

        <div v-if="flash" class="rounded-[5px] px-3.5 py-2.5 text-[12px] tracking-[0.02em]"
            :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <div v-if="loading" class="h-64 border-y border-ink-200/60" />
        <div v-else-if="filtered.length === 0" class="border-y border-ink-200/60 py-20 text-center text-[12px] text-ink-400">
            無符合條件的紀錄
        </div>
        <ul v-else class="divide-y divide-ink-200/60 border-y border-ink-200/60">
            <li v-for="l in filtered" :key="l.id" class="px-1 py-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <p class="text-[14px] font-medium text-ink-900">{{ l.employee_name }}</p>
                            <span class="text-[11px] text-ink-500">{{ l.type_label }}</span>
                            <span class="text-[11px] tracking-[0.05em]" :class="statusClass(l.status)">
                                · {{ l.status_label }}
                            </span>
                            <span v-if="l.source === 'manager_proxy'"
                                class="text-[10px] tracking-[0.05em] text-ink-400">店長代填</span>
                        </div>
                        <p class="num mt-1.5 text-[12px] tracking-[0.02em] text-ink-700">{{ formatRange(l) }}</p>
                        <p v-if="l.reason" class="mt-1 text-[11px] text-ink-500">原因 — {{ l.reason }}</p>
                        <p v-if="l.review_note" class="mt-0.5 text-[11px] text-ink-500">審核 — {{ l.review_note }}</p>
                        <p class="mt-2 text-[10px] tracking-[0.05em] text-ink-400">
                            {{ formatDateTime(l.submitted_at) }} 申請
                            <span v-if="l.reviewed_at"> · {{ formatDateTime(l.reviewed_at) }} 處理</span>
                        </p>
                    </div>
                    <div v-if="l.status === 'pending'" class="flex items-center gap-1">
                        <button type="button" @click="approve(l)"
                            class="rounded-[3px] bg-sumi-600 px-3 py-1 text-[11px] tracking-[0.05em] text-white transition-colors hover:bg-sumi-500">
                            核准
                        </button>
                        <button type="button" @click="startReject(l)"
                            class="rounded-[3px] border border-ink-200/60 bg-white px-3 py-1 text-[11px] tracking-[0.05em] text-ink-700 transition-colors hover:bg-ink-50">
                            拒絕
                        </button>
                        <button type="button" @click="cancelLeave(l)"
                            class="rounded-[3px] px-2 py-1 text-[11px] tracking-[0.05em] text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700">
                            取消
                        </button>
                    </div>
                </div>
            </li>
        </ul>

        <!-- Reject modal -->
        <div v-if="reviewing"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 px-4"
            @click.self="reviewing = null">
            <div class="w-full max-w-md overflow-hidden rounded-[6px] bg-white shadow-xl">
                <div class="border-b border-ink-200/60 px-5 py-4">
                    <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Reject</p>
                    <h3 class="mt-1 font-serif text-[16px] font-medium text-ink-900">
                        拒絕 {{ reviewing.employee_name }} 的請假
                    </h3>
                </div>
                <div class="px-5 py-5">
                    <label class="mb-2 block text-[11px] font-medium tracking-[0.05em] text-ink-600">
                        拒絕原因
                    </label>
                    <textarea v-model="rejectNote" rows="3" placeholder="必填，將通知員工"
                        class="w-full rounded-[5px] border border-ink-200/60 px-3 py-2 text-[13px] outline-none transition-colors focus:border-ink-400" />
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-ink-200/60 bg-ink-50 px-5 py-3">
                    <button type="button" @click="reviewing = null"
                        class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[11px] tracking-[0.05em] text-ink-700 transition-colors hover:bg-ink-50">
                        取消
                    </button>
                    <button type="button" @click="confirmReject"
                        class="rounded-[5px] bg-danger-700 px-4 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-danger-700/90">
                        確認拒絕
                    </button>
                </div>
            </div>
        </div>

        <LeaveFormModal v-if="showCreateModal" :employees="employees"
            :on-submit="handleCreate" @close="showCreateModal = false" />
    </div>
</template>
