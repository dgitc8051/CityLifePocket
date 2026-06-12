<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';

const loading = ref(true);
const holidays = ref([]);
const error = ref(null);
const flash = ref(null);

const adding = ref(false);
const newHoliday = ref({ date: '', type: 'closed', note: '' });

function showFlash(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2000);
}

async function fetchHolidays() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/holidays');
        holidays.value = data.data;
    } catch (e) {
        error.value = '讀取失敗';
    } finally {
        loading.value = false;
    }
}

async function createHoliday() {
    if (!newHoliday.value.date) {
        showFlash('請選擇日期', false);
        return;
    }
    try {
        await axios.post('/api/holidays', newHoliday.value);
        newHoliday.value = { date: '', type: 'closed', note: '' };
        adding.value = false;
        showFlash('已新增');
        await fetchHolidays();
    } catch (e) {
        showFlash(e?.response?.data?.errors ? '輸入有誤' : '新增失敗', false);
    }
}

async function deleteHoliday(h) {
    if (!confirm(`刪除 ${h.date_label} 的公休？`)) return;
    try {
        await axios.delete(`/api/holidays/${h.id}`);
        showFlash('已刪除');
        await fetchHolidays();
    } catch (e) {
        showFlash('刪除失敗', false);
    }
}

onMounted(fetchHolidays);
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <p class="text-[13px] text-ink-500">
                設定單一公休日。固定公休（例如每週一）請去「營業時間」。
            </p>
            <button
                v-if="!adding"
                type="button"
                @click="adding = true"
                class="rounded-[5px] bg-ink-900 px-3 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-ink-800"
            >
                公休建立
            </button>
        </div>

        <!-- Add form -->
        <div
            v-if="adding"
            class="rounded-[6px] border border-ink-200/60 bg-white p-4"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4 sm:items-end">
                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">日期</label>
                    <input
                        v-model="newHoliday.date"
                        type="date"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">類型</label>
                    <select
                        v-model="newHoliday.type"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    >
                        <option value="closed">公休</option>
                        <option value="special">特殊營業</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">備註</label>
                    <input
                        v-model="newHoliday.note"
                        type="text"
                        placeholder="（選填）例：除夕"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    />
                </div>
            </div>
            <div class="mt-3 flex justify-end gap-2">
                <button
                    type="button"
                    @click="adding = false"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[12px] text-ink-700 transition-colors hover:bg-ink-50"
                >
                    取消
                </button>
                <button
                    type="button"
                    @click="createHoliday"
                    class="rounded-[5px] bg-ink-900 px-3 py-1.5 text-[12px] font-medium text-white transition-colors hover:bg-ink-800"
                >
                    建立
                </button>
            </div>
        </div>

        <div v-if="flash" class="rounded-[5px] px-3 py-2 text-[12px]" :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <div v-if="loading" class="h-32 rounded-[6px] border border-ink-200/60 bg-white" />
        <div
            v-else-if="holidays.length === 0"
            class="rounded-[6px] border border-dashed border-ink-300 bg-ink-50/50 px-5 py-12 text-center text-[13px] text-ink-500"
        >
            尚無公休日設定
        </div>
        <ul v-else class="overflow-hidden rounded-[6px] border border-ink-200/60 bg-white divide-y divide-ink-200/60">
            <li
                v-for="h in holidays"
                :key="h.id"
                class="flex items-center justify-between px-4 py-3"
            >
                <div class="flex items-baseline gap-3">
                    <p class="text-[14px] font-medium text-ink-900 tabular-nums">{{ h.date_label }}</p>
                    <span
                        class="rounded-[5px] px-1.5 py-0.5 text-[11px] font-medium"
                        :class="h.type === 'closed' ? 'bg-danger-50 text-danger-700' : 'bg-warning-50 text-warning-700'"
                    >
                        {{ h.type_label }}
                    </span>
                    <span v-if="h.note" class="text-[12px] text-ink-500">{{ h.note }}</span>
                </div>
                <button
                    type="button"
                    @click="deleteHoliday(h)"
                    class="rounded-[5px] px-2 py-1 text-[12px] text-danger-700 transition-colors hover:bg-danger-50"
                >
                    刪除
                </button>
            </li>
        </ul>
    </div>
</template>
