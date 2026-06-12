<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import TimePicker from '../TimePicker.vue';

const loading = ref(true);
const saving = ref(false);
const error = ref(null);
const success = ref(false);
const hours = ref([]);

async function fetchHours() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get('/api/business-hours');
        hours.value = data.data;
    } catch (e) {
        error.value = '讀取失敗';
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    error.value = null;
    success.value = false;
    try {
        await axios.put('/api/business-hours', {
            hours: hours.value.map((h) => ({
                day_of_week: h.day_of_week,
                is_closed: h.is_closed,
                open_time: h.is_closed ? null : h.open_time,
                close_time: h.is_closed ? null : h.close_time,
            })),
        });
        success.value = true;
        setTimeout(() => (success.value = false), 2000);
    } catch (e) {
        error.value = e?.response?.data?.errors
            ? Object.values(e.response.data.errors).flat().join('\n')
            : '儲存失敗';
    } finally {
        saving.value = false;
    }
}

function copyMondayToAll() {
    const monday = hours.value.find((h) => h.day_of_week === 1);
    if (!monday) return;
    hours.value.forEach((h) => {
        h.is_closed = monday.is_closed;
        h.open_time = monday.open_time;
        h.close_time = monday.close_time;
    });
}

onMounted(fetchHours);
</script>

<template>
    <div v-if="loading" class="h-64 rounded-[6px] border border-ink-200/60 bg-white" />
    <div v-else class="space-y-4">
        <div class="rounded-[6px] border border-ink-200/60 bg-white">
            <div class="flex items-center justify-between border-b border-ink-200/60 px-5 py-3">
                <p class="text-[13px] text-ink-600">設定每天的營業時段</p>
                <button
                    type="button"
                    @click="copyMondayToAll"
                    class="text-[12px] text-accent-600 hover:text-accent-700"
                >
                    套用週一到所有天
                </button>
            </div>
            <ul class="divide-y divide-ink-200/60">
                <li
                    v-for="h in hours"
                    :key="h.day_of_week"
                    class="grid grid-cols-[80px_120px_1fr] items-center gap-3 px-5 py-3"
                >
                    <span class="text-[13px] font-medium text-ink-900">{{ h.day_label }}</span>
                    <label class="flex items-center gap-2 text-[12px] text-ink-600">
                        <input
                            v-model="h.is_closed"
                            type="checkbox"
                            class="rounded border-ink-300"
                        />
                        公休
                    </label>
                    <div class="flex items-center gap-2 text-[13px]" :class="{ 'opacity-40': h.is_closed }">
                        <TimePicker v-model="h.open_time" :disabled="h.is_closed" size="sm" />
                        <span class="text-ink-400">—</span>
                        <TimePicker v-model="h.close_time" :disabled="h.is_closed" size="sm" />
                    </div>
                </li>
            </ul>
        </div>

        <div v-if="error" class="rounded-[5px] bg-danger-50 px-3 py-2 text-[12px] text-danger-700">
            {{ error }}
        </div>
        <div v-if="success" class="rounded-[5px] bg-success-50 px-3 py-2 text-[12px] text-success-700">
            ✓ 已儲存
        </div>

        <div class="flex justify-end">
            <button
                type="button"
                @click="save"
                :disabled="saving"
                class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-ink-800 disabled:opacity-50"
            >
                {{ saving ? '儲存中' : '儲存' }}
            </button>
        </div>
    </div>
</template>
