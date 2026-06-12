<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

const loading = ref(true);
const logs = ref([]);
const error = ref(null);
const entityFilter = ref('all');
const expanded = ref(new Set());

const entityOptions = [
    { val: 'all', label: '全部' },
    { val: 'Employee', label: '員工' },
    { val: 'ShiftTemplate', label: '時段樣板' },
    { val: 'ScheduleEntry', label: '排班' },
    { val: 'Schedule', label: '班表' },
    { val: 'LeaveRequest', label: '請假' },
    { val: 'ShiftSwapRequest', label: '換班' },
    { val: 'Station', label: '站別' },
    { val: 'Employee::Availability', label: '可上時段' },
    { val: 'Holiday', label: '公休' },
    { val: 'Shop', label: '店家' },
];

const filtered = computed(() => {
    if (entityFilter.value === 'all') return logs.value;
    if (entityFilter.value === 'Employee::Availability') {
        return logs.value.filter((l) => l.action === 'availability.update');
    }
    if (entityFilter.value === 'ShiftSwapRequest') {
        return logs.value.filter((l) => l.action.startsWith('shift_swap'));
    }
    if (entityFilter.value === 'Station') {
        return logs.value.filter((l) => l.action.startsWith('station.'));
    }
    return logs.value.filter((l) => l.action.startsWith(entityFilter.value.toLowerCase()) || l.action.startsWith(entityFilter.value));
});

async function fetchLogs() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get('/api/audit-logs?limit=200');
        logs.value = data.data;
    } catch (e) {
        error.value = e?.response?.data?.error ?? '讀取失敗';
    } finally {
        loading.value = false;
    }
}

function toggleExpand(id) {
    if (expanded.value.has(id)) expanded.value.delete(id);
    else expanded.value.add(id);
    expanded.value = new Set(expanded.value);
}

function toneClass(tone) {
    return {
        'text-success-700': tone === 'success',
        'text-danger-700': tone === 'danger',
        'text-ink-600': tone === 'neutral',
    };
}

onMounted(fetchLogs);
</script>

<template>
    <div class="space-y-10">
        <section>
            <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Audit</p>
            <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">操作紀錄</h2>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                每一筆變更都記下：誰、何時、做了什麼。
            </p>
        </section>

        <!-- Filter -->
        <nav class="flex flex-wrap items-center gap-px rounded-[5px] border border-ink-200/60 bg-white p-0.5 text-[11px]">
            <button
                v-for="opt in entityOptions"
                :key="opt.val"
                @click="entityFilter = opt.val"
                type="button"
                class="rounded-[3px] px-3 py-1 tracking-[0.05em] transition-colors"
                :class="entityFilter === opt.val
                    ? 'bg-ink-100 text-ink-900 font-medium'
                    : 'text-ink-500 hover:text-ink-900'"
            >
                {{ opt.label }}
            </button>
        </nav>

        <div v-if="loading" class="h-64 border-y border-ink-200/60" />
        <div v-else-if="error" class="rounded-[5px] bg-danger-50 px-5 py-4 text-[12px] text-danger-700">
            {{ error }}
        </div>
        <div v-else-if="filtered.length === 0" class="border-y border-ink-200/60 py-20 text-center text-[12px] text-ink-400">
            無紀錄
        </div>

        <ul v-else class="divide-y divide-ink-200/60 border-y border-ink-200/60">
            <li v-for="log in filtered" :key="log.id" class="py-4">
                <!-- Primary line: time / user / action / summary -->
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="num shrink-0 text-[11px] tracking-[0.02em] text-ink-400">
                        {{ log.time_label }}
                    </span>
                    <span class="shrink-0 text-[12px] font-medium text-ink-900">
                        {{ log.user_name }}
                    </span>
                    <span
                        class="shrink-0 rounded-[3px] px-1.5 py-0.5 text-[10px] tracking-[0.05em]"
                        :class="{
                            'bg-success-50 text-success-700': log.tone === 'success',
                            'bg-danger-50 text-danger-700': log.tone === 'danger',
                            'bg-ink-100 text-ink-700': log.tone === 'neutral',
                        }"
                    >
                        {{ log.action_label }}
                    </span>
                    <span class="text-[12px] text-ink-700 break-all">
                        {{ log.summary }}
                    </span>
                    <button
                        v-if="log.diff && log.diff.length"
                        type="button"
                        @click="toggleExpand(log.id)"
                        class="ml-auto shrink-0 rounded-[3px] px-2 py-0.5 text-[10px] tracking-[0.05em] text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
                    >
                        {{ expanded.has(log.id) ? '收合' : `${log.diff.length} 項變更 ↓` }}
                    </button>
                </div>

                <!-- Diff list -->
                <ul
                    v-if="expanded.has(log.id) && log.diff && log.diff.length"
                    class="mt-3 ml-[88px] space-y-1.5 border-l-2 border-ink-200/60 pl-4"
                >
                    <li
                        v-for="(d, i) in log.diff"
                        :key="i"
                        class="flex flex-wrap items-baseline gap-2 text-[11px] tracking-[0.02em]"
                    >
                        <span class="min-w-[80px] text-ink-500">{{ d.field }}</span>
                        <span class="text-ink-400 line-through">{{ d.before }}</span>
                        <span class="text-ink-300">→</span>
                        <span class="font-medium text-ink-900">{{ d.after }}</span>
                    </li>
                    <li
                        v-if="log.ip_address"
                        class="pt-1 text-[10px] tracking-[0.05em] text-ink-400"
                    >
                        IP {{ log.ip_address }}
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</template>
