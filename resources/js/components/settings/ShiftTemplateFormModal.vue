<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import TimePicker from '../TimePicker.vue';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const features = computed(() => auth.user?.current_shop?.features ?? {});

const props = defineProps({
    template: { type: Object, default: null },
    onSubmit: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const isEdit = !!props.template;
const submitting = ref(false);
const error = ref(null);

// 把 bitmask 解開成 7 個 bool
const days = reactive([false, false, false, false, false, false, false]);
const bitmask = props.template?.days_of_week_bitmask ?? 0b1111111;
for (let i = 0; i < 7; i++) {
    days[i] = (bitmask & (1 << i)) !== 0;
}

const form = reactive({
    name: props.template?.name ?? '',
    start_time: props.template?.start_time ?? '10:00',
    end_time: props.template?.end_time ?? '15:00',
    required_score: props.template?.required_score ?? 0,
    min_senior_count: props.template?.min_senior_count ?? 0,
    min_headcount: props.template?.min_headcount ?? 1,
    max_headcount: props.template?.max_headcount ?? '',
    sort_order: props.template?.sort_order ?? 0,
    notes: props.template?.notes ?? '',
});

const dayLabels = ['日', '一', '二', '三', '四', '五', '六'];

// 站別需求：{ station_id, min_count } 結構
const stations = ref([]);
const stationReqs = reactive(
    (props.template?.station_requirements ?? []).reduce((acc, r) => {
        acc[r.station_id] = r.min_count;
        return acc;
    }, {})
);

async function fetchStations() {
    try {
        const { data } = await axios.get('/api/stations');
        stations.value = (data.data ?? []).filter((s) => s.is_active);
    } catch (e) { /* skip */ }
}

function toggleStationReq(sid) {
    if (stationReqs[sid] !== undefined) {
        delete stationReqs[sid];
    } else {
        stationReqs[sid] = 1;
    }
}

const computedBitmask = computed(() => {
    let m = 0;
    days.forEach((on, i) => {
        if (on) m |= 1 << i;
    });
    return m;
});

onMounted(fetchStations);

async function submit() {
    submitting.value = true;
    error.value = null;
    const payload = { ...form, days_of_week_bitmask: computedBitmask.value };
    Object.keys(payload).forEach((k) => {
        if (payload[k] === '' || payload[k] === null) delete payload[k];
    });
    payload.station_requirements = Object.entries(stationReqs).map(([sid, min]) => ({
        station_id: Number(sid),
        min_count: Math.max(1, Number(min) || 1),
    }));

    const err = await props.onSubmit(payload);
    submitting.value = false;
    if (err) error.value = err;
}

function close() {
    if (!submitting.value) emit('close');
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 px-4"
        @click.self="close"
    >
        <div class="w-full max-w-lg overflow-hidden rounded-[6px] bg-white shadow-xl" @click.stop>
            <div class="flex items-center justify-between border-b border-ink-200/60 px-5 py-4">
                <h3 class="text-[15px] font-semibold text-ink-900">
                    {{ isEdit ? '時段編輯' : '時段建立' }}
                </h3>
                <button
                    type="button"
                    @click="close"
                    class="rounded-[5px] px-2 py-1 text-[12px] text-ink-500 transition-colors hover:bg-ink-100"
                >
                    取消
                </button>
            </div>

            <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">時段名稱 *</label>
                    <input
                        v-model="form.name"
                        type="text"
                        placeholder="例：早班、尖峰、晚班"
                        required
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">開始 *</label>
                        <TimePicker v-model="form.start_time" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">結束 *</label>
                        <TimePicker v-model="form.end_time" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">適用日</label>
                    <div class="flex items-center gap-1">
                        <button
                            v-for="(label, i) in dayLabels"
                            :key="i"
                            type="button"
                            @click="days[i] = !days[i]"
                            class="h-8 w-8 rounded-[5px] border text-[12px] transition-colors"
                            :class="
                                days[i]
                                    ? 'border-accent-600 bg-sumi-600 text-white'
                                    : 'border-ink-200/60 bg-white text-ink-500 hover:bg-ink-50'
                            "
                        >
                            {{ label }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">最少人數</label>
                        <input
                            v-model.number="form.min_headcount"
                            type="number"
                            min="0"
                            max="50"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            最多人數
                            <span class="ml-1 font-normal text-ink-400">選填</span>
                        </label>
                        <input
                            v-model.number="form.max_headcount"
                            type="number"
                            min="0"
                            max="50"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div v-if="features.skill_score">
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            建議總分
                            <span class="ml-1 font-normal text-ink-400">參考用·不擋發佈</span>
                        </label>
                        <input
                            v-model.number="form.required_score"
                            type="number"
                            min="0"
                            max="1000"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div v-if="features.senior_required">
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            最少高階員工
                            <span class="ml-1 font-normal text-ink-400">熟手 + 店長</span>
                        </label>
                        <input
                            v-model.number="form.min_senior_count"
                            type="number"
                            min="0"
                            max="50"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                </div>

                <!-- 站別需求 -->
                <div v-if="features.stations">
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">
                        本班次需要的站別
                        <span class="ml-1 font-normal text-ink-400">勾選後可設定各站至少幾人</span>
                    </label>
                    <div v-if="stations.length" class="space-y-1.5">
                        <div v-for="s in stations" :key="s.id" class="flex items-center gap-2">
                            <button type="button" @click="toggleStationReq(s.id)"
                                class="inline-flex flex-1 items-center gap-2 rounded-[4px] border px-2.5 py-1.5 text-left text-[11px] tracking-[0.02em] transition-colors"
                                :class="stationReqs[s.id] !== undefined
                                    ? 'border-ink-900 bg-ink-900 text-white'
                                    : 'border-ink-200/60 bg-white text-ink-700 hover:bg-ink-50'">
                                <span class="inline-block h-2 w-2 rounded-[1px]" :style="{ backgroundColor: s.color || '#94a3b8' }" />
                                {{ s.name }}
                            </button>
                            <input v-if="stationReqs[s.id] !== undefined"
                                v-model.number="stationReqs[s.id]"
                                type="number" min="1" max="50"
                                class="h-8 w-16 rounded-[5px] border border-ink-200/60 px-2 text-center text-[12px] tabular-nums outline-none focus:border-accent-500" />
                            <span v-if="stationReqs[s.id] !== undefined" class="text-[11px] text-ink-500">人</span>
                        </div>
                    </div>
                    <p v-else class="text-[10.5px] text-ink-400">
                        尚未設定站別。請先到「店家資料 → 站別管理」建立。
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">備註</label>
                    <input
                        v-model="form.notes"
                        type="text"
                        placeholder="（選填，如：尖峰、需要備料）"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    />
                </div>

                <div v-if="error" class="rounded-[5px] bg-danger-50 px-3 py-2 text-[12px] text-danger-700 whitespace-pre-line">
                    {{ error }}
                </div>
            </form>

            <div class="flex items-center justify-end gap-2 border-t border-ink-200/60 bg-ink-50/60 px-5 py-3">
                <button
                    type="button"
                    @click="close"
                    :disabled="submitting"
                    class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[13px] text-ink-700 transition-colors hover:bg-ink-50 disabled:opacity-50"
                >
                    取消
                </button>
                <button
                    type="button"
                    @click="submit"
                    :disabled="submitting"
                    class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-ink-800 disabled:opacity-50"
                >
                    {{ submitting ? '儲存中' : isEdit ? '儲存' : '建立時段' }}
                </button>
            </div>
        </div>
    </div>
</template>
