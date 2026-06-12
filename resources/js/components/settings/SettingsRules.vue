<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import TimePicker from '../TimePicker.vue';

const loading = ref(true);
const saving = ref(false);
const error = ref(null);
const success = ref(false);

const settings = reactive({
    leave_min_advance_days: 3,
    availability_cutoff_day: 25,
    availability_cutoff_time: '12:00',
    max_consecutive_work_days: 6,
    min_hours_between_shifts: 11,
    full_time_min_days_per_month: 22,
    full_time_max_days_per_month: 26,
    part_time_min_days_per_month: 8,
    part_time_max_days_per_month: 15,
    intern_min_days_per_month: 4,
    intern_max_days_per_month: 12,
});

async function fetchSettings() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/shop');
        const s = data.data.settings_json ?? {};
        Object.keys(settings).forEach((k) => {
            if (s[k] !== undefined && s[k] !== null) settings[k] = s[k];
        });
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
        // 把 cutoff_time 補成 HH:mm:00 給後端
        await axios.put('/api/shop', { settings_json: { ...settings } });
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

onMounted(fetchSettings);
</script>

<template>
    <div v-if="loading" class="h-64 border-y border-ink-200/60" />
    <div v-else class="space-y-8">
        <!-- 排休 / 排班規則 -->
        <section>
            <h3 class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Submission Rules</h3>
            <p class="mt-1 font-serif text-[16px] font-medium text-ink-900">提交時限</p>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                員工可以何時提交排班意願與請假。超過時間需店長代填。
            </p>

            <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-[11px] font-medium tracking-[0.05em] text-ink-700">
                        每月排班意願截止日
                    </label>
                    <div class="flex items-center gap-2">
                        <span class="text-[12px] text-ink-500">每月</span>
                        <input
                            v-model.number="settings.availability_cutoff_day"
                            type="number"
                            min="1"
                            max="31"
                            class="h-9 w-16 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                        />
                        <span class="text-[12px] text-ink-500">號</span>
                        <TimePicker v-model="settings.availability_cutoff_time" />
                        <span class="text-[12px] text-ink-500">前</span>
                    </div>
                    <p class="mt-1.5 text-[10.5px] text-ink-400">超過此時間員工無法自行修改下個月可上時段</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-[11px] font-medium tracking-[0.05em] text-ink-700">
                        請假需提前申請天數
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model.number="settings.leave_min_advance_days"
                            type="number"
                            min="0"
                            max="30"
                            class="h-9 w-16 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                        />
                        <span class="text-[12px] text-ink-500">天</span>
                    </div>
                    <p class="mt-1.5 text-[10.5px] text-ink-400">病假、急事除外（店長可代填）</p>
                </div>
            </div>
        </section>

        <div class="hairline"></div>

        <!-- 勞基法 -->
        <section>
            <h3 class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Labor Rules</h3>
            <p class="mt-1 font-serif text-[16px] font-medium text-ink-900">勞工規範</p>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                排班時自動檢查；違反不會擋下，會在班表上跳警示。
            </p>

            <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-[11px] font-medium tracking-[0.05em] text-ink-700">
                        連續上班天數上限
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model.number="settings.max_consecutive_work_days"
                            type="number"
                            min="1"
                            max="14"
                            class="h-9 w-16 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                        />
                        <span class="text-[12px] text-ink-500">天（勞基法 6 天）</span>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-[11px] font-medium tracking-[0.05em] text-ink-700">
                        班與班最短間隔
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model.number="settings.min_hours_between_shifts"
                            type="number"
                            min="0"
                            max="24"
                            class="h-9 w-16 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                        />
                        <span class="text-[12px] text-ink-500">小時（勞基法 11 小時）</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="hairline"></div>

        <!-- 月度上下限 -->
        <section>
            <h3 class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Monthly Limits</h3>
            <p class="mt-1 font-serif text-[16px] font-medium text-ink-900">每月排班上下限</p>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                每位員工每月應上班的天數範圍。低於下限或超過上限時，排班看板會跳警示。
            </p>

            <table class="mt-5 w-full text-[13px]">
                <thead>
                    <tr class="border-y border-ink-200/60 text-left text-[10px] font-normal uppercase tracking-[0.12em] text-ink-400">
                        <th class="py-2 pr-4">雇用類型</th>
                        <th class="py-2 pr-4">下限（最少）</th>
                        <th class="py-2 pr-4">上限（最多）</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-200/60">
                    <tr>
                        <td class="py-3 pr-4 font-medium text-ink-900">全職</td>
                        <td class="py-3 pr-4">
                            <input
                                v-model.number="settings.full_time_min_days_per_month"
                                type="number" min="0" max="31"
                                class="h-8 w-20 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                            />
                            <span class="ml-2 text-[11px] text-ink-500">天 / 月</span>
                        </td>
                        <td class="py-3 pr-4">
                            <input
                                v-model.number="settings.full_time_max_days_per_month"
                                type="number" min="0" max="31"
                                class="h-8 w-20 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                            />
                            <span class="ml-2 text-[11px] text-ink-500">天 / 月</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 pr-4 font-medium text-ink-900">兼職</td>
                        <td class="py-3 pr-4">
                            <input
                                v-model.number="settings.part_time_min_days_per_month"
                                type="number" min="0" max="31"
                                class="h-8 w-20 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                            />
                            <span class="ml-2 text-[11px] text-ink-500">天 / 月</span>
                        </td>
                        <td class="py-3 pr-4">
                            <input
                                v-model.number="settings.part_time_max_days_per_month"
                                type="number" min="0" max="31"
                                class="h-8 w-20 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                            />
                            <span class="ml-2 text-[11px] text-ink-500">天 / 月</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 pr-4 font-medium text-ink-900">實習</td>
                        <td class="py-3 pr-4">
                            <input
                                v-model.number="settings.intern_min_days_per_month"
                                type="number" min="0" max="31"
                                class="h-8 w-20 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                            />
                            <span class="ml-2 text-[11px] text-ink-500">天 / 月</span>
                        </td>
                        <td class="py-3 pr-4">
                            <input
                                v-model.number="settings.intern_max_days_per_month"
                                type="number" min="0" max="31"
                                class="h-8 w-20 rounded-[5px] border border-ink-200/60 bg-white px-2.5 text-center text-[13px] tabular-nums outline-none focus:border-ink-400"
                            />
                            <span class="ml-2 text-[11px] text-ink-500">天 / 月</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <div v-if="error" class="rounded-[5px] bg-danger-50 px-3 py-2 text-[12px] text-danger-700">{{ error }}</div>
        <div v-if="success" class="rounded-[5px] bg-success-50 px-3 py-2 text-[12px] text-success-700">已儲存</div>

        <div class="flex justify-end pt-2">
            <button
                type="button"
                @click="save"
                :disabled="saving"
                class="rounded-[5px] bg-sumi-600 px-4 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500 disabled:opacity-50"
            >
                {{ saving ? '儲存中' : '儲存規則' }}
            </button>
        </div>
    </div>
</template>
