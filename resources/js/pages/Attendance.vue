<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const isManager = computed(() => ['admin', 'owner', 'manager', 'sub_manager'].includes(auth.user?.role));

const tab = ref('grid'); // grid | history | overtime
const loading = ref(true);
const cards = ref([]);
const departments = ref([]);
const shopGeo = ref({ has_geofence: false });
const today = ref('');
const flash = ref(null);
const filterDept = ref('all');
const searchText = ref('');

// PIN modal
const pinModal = ref({ open: false, card: null, pin: '', busy: false, error: '' });

// History + overtime
const historyRecords = ref([]);
const overtimeRecords = ref([]);

function flashMsg(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2400);
}

async function fetchGrid() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/attendance/card-grid');
        cards.value = data.cards ?? [];
        departments.value = data.departments ?? [];
        shopGeo.value = data.shop ?? { has_geofence: false };
        today.value = new Date().toISOString().slice(0, 10);
    } catch (e) {
        flashMsg('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

async function fetchHistory() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/attendance');
        historyRecords.value = data.data ?? [];
    } catch (e) {
        flashMsg('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

async function fetchOvertime() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/attendance/pending-overtime');
        overtimeRecords.value = data.data ?? [];
    } catch (e) {
        flashMsg('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

function onTabChange(t) {
    tab.value = t;
    if (t === 'grid') fetchGrid();
    else if (t === 'history') fetchHistory();
    else if (t === 'overtime') fetchOvertime();
}

function openPin(card) {
    pinModal.value = { open: true, card, pin: '', busy: false, error: '' };
}

function appendPin(d) {
    if (pinModal.value.pin.length >= 4) return;
    pinModal.value.pin += d;
}

function backspacePin() {
    pinModal.value.pin = pinModal.value.pin.slice(0, -1);
}

// 取得 GPS（瀏覽器原生）
function getLocation() {
    return new Promise((resolve) => {
        if (!navigator.geolocation) return resolve({ lat: null, lng: null });
        navigator.geolocation.getCurrentPosition(
            (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
            () => resolve({ lat: null, lng: null }),
            { enableHighAccuracy: true, timeout: 5000 },
        );
    });
}

async function submitPin() {
    if (pinModal.value.pin.length !== 4) {
        pinModal.value.error = '請輸入 4 位密碼';
        return;
    }
    pinModal.value.busy = true;
    pinModal.value.error = '';
    try {
        const loc = await getLocation();
        const { data } = await axios.post('/api/attendance/clock-with-pin', {
            employee_id: pinModal.value.card.id,
            pin: pinModal.value.pin,
            lat: loc.lat,
            lng: loc.lng,
        });
        flashMsg(data.action === 'clock_in' ? '打卡上班成功' : '打卡下班成功');
        pinModal.value.open = false;
        await fetchGrid();
    } catch (e) {
        pinModal.value.error = e?.response?.data?.error ?? '打卡失敗';
    } finally {
        pinModal.value.busy = false;
    }
}

async function approveOT(record) {
    if (!confirm(`核可 ${record.employee_name} 的 ${record.ot_detected_minutes} 分鐘加班？`)) return;
    try {
        await axios.post(`/api/attendance/${record.id}/approve-overtime`);
        flashMsg('已核可');
        await fetchOvertime();
    } catch (e) {
        flashMsg(e?.response?.data?.error ?? '核可失敗', false);
    }
}

async function rejectOT(record) {
    if (!confirm(`拒絕 ${record.employee_name} 的加班？此筆不計加班費。`)) return;
    try {
        await axios.post(`/api/attendance/${record.id}/reject-overtime`);
        flashMsg('已拒絕');
        await fetchOvertime();
    } catch (e) {
        flashMsg(e?.response?.data?.error ?? '操作失敗', false);
    }
}

const filteredCards = computed(() => {
    return cards.value.filter((c) => {
        if (filterDept.value !== 'all' && (c.role ?? '未分類') !== filterDept.value) return false;
        if (searchText.value && !c.name.toLowerCase().includes(searchText.value.toLowerCase()) && !c.code.toLowerCase().includes(searchText.value.toLowerCase())) return false;
        return true;
    });
});

const statusColor = (s) => ({
    on_duty: 'text-success-700',
    clocked_out: 'text-ink-400',
    not_clocked_in: 'text-danger-600',
}[s] ?? 'text-ink-500');

// 為頭像產生底色（依姓名 hash）
function avatarColor(name) {
    const palette = ['#10b981', '#0891b2', '#8b5cf6', '#f59e0b', '#ef4444', '#3b82f6', '#84cc16'];
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
    return palette[Math.abs(h) % palette.length];
}

function fmtTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function statusClass(s) {
    return {
        'text-success-700': s === 'on_time',
        'text-warning-700': s === 'late' || s === 'early',
        'text-danger-700': s === 'no_show',
        'text-ink-500': s === 'present_unscheduled',
    };
}

onMounted(fetchGrid);
</script>

<template>
    <div class="space-y-8">
        <section class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Attendance</p>
                <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">出勤打卡</h2>
                <p class="num mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                    {{ today }} ·
                    <span v-if="shopGeo.has_geofence">已啟用定位驗證</span>
                    <span v-else>未啟用定位驗證</span>
                </p>
            </div>
        </section>

        <!-- Tabs -->
        <nav class="flex items-center gap-1 border-b border-ink-200/60">
            <button type="button" @click="onTabChange('grid')"
                class="relative -mb-px px-3 py-2.5 text-[12px] tracking-[0.02em] transition-colors"
                :class="tab === 'grid' ? 'border-b-2 border-ink-900 font-medium text-ink-900' : 'border-b-2 border-transparent text-ink-500 hover:text-ink-900'">
                員工打卡
            </button>
            <button type="button" @click="onTabChange('history')"
                class="relative -mb-px px-3 py-2.5 text-[12px] tracking-[0.02em] transition-colors"
                :class="tab === 'history' ? 'border-b-2 border-ink-900 font-medium text-ink-900' : 'border-b-2 border-transparent text-ink-500 hover:text-ink-900'">
                打卡紀錄
            </button>
            <button v-if="isManager" type="button" @click="onTabChange('overtime')"
                class="relative -mb-px px-3 py-2.5 text-[12px] tracking-[0.02em] transition-colors"
                :class="tab === 'overtime' ? 'border-b-2 border-ink-900 font-medium text-ink-900' : 'border-b-2 border-transparent text-ink-500 hover:text-ink-900'">
                加班核可
                <span v-if="overtimeRecords.length > 0" class="ml-1 rounded-full bg-warning-100 px-1.5 py-0.5 text-[10px] font-medium text-warning-700">{{ overtimeRecords.length }}</span>
            </button>
        </nav>

        <div v-if="flash"
            class="rounded-[5px] px-3.5 py-2.5 text-[12px] tracking-[0.02em]"
            :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <div v-if="loading" class="h-64 border-y border-ink-200/60" />

        <!-- TAB: 員工打卡卡片網格 -->
        <template v-else-if="tab === 'grid'">
            <!-- Search bar -->
            <div class="flex items-center gap-3">
                <input v-model="searchText" type="text" placeholder="搜尋姓名 / 編號..."
                    class="h-10 flex-1 rounded-[8px] border border-ink-200/60 bg-white px-4 text-[14px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
            </div>

            <div class="grid grid-cols-[200px_1fr] gap-6">
                <!-- 左側：部門篩選 -->
                <aside class="space-y-1">
                    <button type="button" @click="filterDept = 'all'"
                        class="flex w-full items-center justify-between rounded-[6px] px-4 py-2.5 text-left text-[13px] transition-colors"
                        :class="filterDept === 'all' ? 'bg-ink-900 text-white' : 'text-ink-700 hover:bg-ink-100'">
                        <span>所有</span>
                        <span class="text-[11px] opacity-60">{{ cards.length }}</span>
                    </button>
                    <button v-for="d in departments" :key="d.key" type="button" @click="filterDept = d.key"
                        class="flex w-full items-center justify-between rounded-[6px] px-4 py-2.5 text-left text-[13px] transition-colors"
                        :class="filterDept === d.key ? 'bg-ink-900 text-white' : 'text-ink-700 hover:bg-ink-100'">
                        <span>{{ d.label || '未分類' }}</span>
                        <span class="text-[11px] opacity-60">{{ d.count }}</span>
                    </button>
                </aside>

                <!-- 右側：卡片網格 -->
                <div>
                    <div v-if="filteredCards.length === 0"
                        class="border-y border-ink-200/60 py-16 text-center text-[12px] text-ink-400">
                        沒有符合條件的員工
                    </div>
                    <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        <button v-for="c in filteredCards" :key="c.id"
                            type="button" @click="openPin(c)"
                            class="relative flex flex-col items-center rounded-[10px] border border-ink-200/60 bg-white p-5 text-center transition-shadow hover:shadow-md">
                            <!-- 狀態徽章 -->
                            <span class="absolute right-3 top-3 text-[11px] font-medium" :class="statusColor(c.status)">
                                {{ c.status_label }}
                            </span>
                            <!-- 頭像 -->
                            <span class="flex h-16 w-16 items-center justify-center rounded-full text-[22px] font-medium text-white"
                                :style="{ backgroundColor: avatarColor(c.name) }">
                                {{ c.name.slice(0, 1) }}
                            </span>
                            <p class="mt-3 text-[10px] tabular-nums tracking-[0.1em] text-ink-400">{{ c.code }}</p>
                            <p class="mt-1 text-[15px] font-semibold text-ink-900">{{ c.name }}</p>
                            <p class="mt-0.5 text-[11px] text-ink-500">{{ c.role || '未分配職務' }}</p>
                            <p v-if="!c.has_pin" class="mt-2 text-[10px] text-warning-700">⚠ 未設定生日，無法打卡</p>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- TAB: 打卡紀錄 -->
        <template v-else-if="tab === 'history'">
            <div v-if="historyRecords.length === 0"
                class="border-y border-ink-200/60 py-16 text-center text-[12px] text-ink-400">
                這段時間沒有打卡紀錄
            </div>
            <ul v-else class="divide-y divide-ink-200/60 border-y border-ink-200/60">
                <li v-for="r in historyRecords" :key="r.id" class="flex flex-wrap items-center gap-3 py-3 text-[12px]">
                    <span class="num min-w-[80px] text-ink-500">{{ r.date }}</span>
                    <span class="num min-w-[52px] text-ink-500">{{ fmtTime(r.clocked_in_at) }}</span>
                    <span class="text-ink-400">→</span>
                    <span class="num min-w-[52px] text-ink-500">{{ fmtTime(r.clocked_out_at) }}</span>
                    <span class="num text-[11px] text-ink-400" v-if="r.hours_worked">· {{ r.hours_worked }}h</span>
                    <span class="font-medium text-ink-900">{{ r.employee_name }}</span>
                    <span v-if="r.shift_name" class="text-ink-500">{{ r.shift_name }} {{ r.shift_time }}</span>
                    <span class="rounded-[3px] px-1.5 py-0.5 text-[10px]" :class="statusClass(r.status)">{{ r.status_label }}</span>
                    <span v-if="r.late_minutes > 0" class="rounded-[3px] bg-warning-50 px-1.5 py-0.5 text-[10px] text-warning-700">遲到 {{ r.late_minutes }} 分</span>
                    <span v-if="r.ot_pending" class="rounded-[3px] bg-warning-50 px-1.5 py-0.5 text-[10px] text-warning-700">加班待核可 {{ r.ot_detected_minutes }} 分</span>
                    <span v-if="r.ot_approved_minutes > 0" class="rounded-[3px] bg-success-50 px-1.5 py-0.5 text-[10px] text-success-700">已核可 {{ r.ot_approved_minutes }} 分</span>
                </li>
            </ul>
        </template>

        <!-- TAB: 加班核可（manager only） -->
        <template v-else-if="tab === 'overtime' && isManager">
            <div v-if="overtimeRecords.length === 0"
                class="border-y border-ink-200/60 py-16 text-center text-[12px] text-ink-400">
                沒有待核可的加班
            </div>
            <ul v-else class="divide-y divide-ink-200/60 border-y border-ink-200/60">
                <li v-for="r in overtimeRecords" :key="r.id" class="flex flex-wrap items-center gap-3 py-3 text-[12px]">
                    <span class="num min-w-[80px] text-ink-500">{{ r.date }}</span>
                    <span class="font-medium text-ink-900">{{ r.employee_name }}</span>
                    <span class="text-ink-500">{{ r.shift_name }} {{ r.shift_time }}</span>
                    <span class="num text-ink-500">下班 {{ fmtTime(r.clocked_out_at) }}</span>
                    <span class="rounded-[3px] bg-warning-50 px-1.5 py-0.5 text-[11px] font-medium text-warning-700">系統偵測加班 {{ r.ot_detected_minutes }} 分鐘</span>
                    <div class="ml-auto flex gap-1">
                        <button type="button" @click="approveOT(r)"
                            class="rounded-[5px] bg-success-600 px-3 py-1 text-[11px] font-medium text-white transition-colors hover:bg-success-700">
                            核可加班
                        </button>
                        <button type="button" @click="rejectOT(r)"
                            class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1 text-[11px] text-ink-700 transition-colors hover:bg-danger-50 hover:text-danger-700">
                            拒絕
                        </button>
                    </div>
                </li>
            </ul>
        </template>

        <!-- PIN 打卡 Modal -->
        <div v-if="pinModal.open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/40 px-4"
            @click.self="pinModal.open = false">
            <div class="w-full max-w-sm overflow-hidden rounded-[12px] bg-white shadow-xl">
                <div class="border-b border-ink-200/60 px-5 py-4 text-center">
                    <span class="flex mx-auto h-14 w-14 items-center justify-center rounded-full text-[18px] font-medium text-white"
                        :style="{ backgroundColor: avatarColor(pinModal.card.name) }">
                        {{ pinModal.card.name.slice(0, 1) }}
                    </span>
                    <p class="mt-2 text-[15px] font-semibold text-ink-900">{{ pinModal.card.name }}</p>
                    <p class="text-[11px] text-ink-500">
                        <span v-if="pinModal.card.status === 'on_duty'">下班打卡</span>
                        <span v-else>上班打卡</span>
                        · 輸入 4 位生日密碼（MMDD）
                    </p>
                </div>

                <div class="px-5 py-5">
                    <!-- PIN 顯示 -->
                    <div class="mb-5 flex justify-center gap-2">
                        <span v-for="i in 4" :key="i"
                            class="flex h-12 w-10 items-center justify-center rounded-[6px] border-2 text-[20px] font-semibold tabular-nums"
                            :class="pinModal.pin.length >= i ? 'border-ink-900 bg-ink-900 text-white' : 'border-ink-200/60 bg-ink-50/30 text-ink-300'">
                            {{ pinModal.pin.length >= i ? '●' : '' }}
                        </span>
                    </div>

                    <!-- 數字鍵盤 -->
                    <div class="grid grid-cols-3 gap-2">
                        <button v-for="n in [1, 2, 3, 4, 5, 6, 7, 8, 9]" :key="n"
                            type="button" @click="appendPin(String(n))" :disabled="pinModal.busy"
                            class="h-12 rounded-[8px] border border-ink-200/60 bg-white text-[18px] font-medium text-ink-900 transition-colors hover:bg-ink-100 disabled:opacity-50">
                            {{ n }}
                        </button>
                        <button type="button" @click="backspacePin" :disabled="pinModal.busy"
                            class="h-12 rounded-[8px] border border-ink-200/60 bg-white text-[12px] text-ink-500 transition-colors hover:bg-ink-100 disabled:opacity-50">
                            ←
                        </button>
                        <button type="button" @click="appendPin('0')" :disabled="pinModal.busy"
                            class="h-12 rounded-[8px] border border-ink-200/60 bg-white text-[18px] font-medium text-ink-900 transition-colors hover:bg-ink-100 disabled:opacity-50">
                            0
                        </button>
                        <button type="button" @click="submitPin" :disabled="pinModal.busy || pinModal.pin.length !== 4"
                            class="h-12 rounded-[8px] bg-ink-900 text-[14px] font-medium text-white transition-colors hover:bg-ink-800 disabled:opacity-50">
                            {{ pinModal.busy ? '...' : 'OK' }}
                        </button>
                    </div>

                    <p v-if="pinModal.error" class="mt-3 text-center text-[12px] text-danger-700">{{ pinModal.error }}</p>
                </div>

                <div class="flex items-center justify-center border-t border-ink-200/60 bg-ink-50/60 px-5 py-3">
                    <button type="button" @click="pinModal.open = false" :disabled="pinModal.busy"
                        class="text-[12px] tracking-[0.02em] text-ink-500 transition-colors hover:text-ink-900 disabled:opacity-50">
                        取消
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
