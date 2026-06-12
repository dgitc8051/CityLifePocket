<script setup>
import { reactive, ref } from 'vue';
import TimePicker from './TimePicker.vue';

const props = defineProps({
    employees: { type: Array, default: () => [] },
    onSubmit: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const submitting = ref(false);
const error = ref(null);

const today = new Date().toISOString().slice(0, 10);

const form = reactive({
    employee_id: '',
    start_date: today,
    end_date: today,
    full_day: true,
    start_time: '00:00',
    end_time: '23:59',
    type: 'personal',
    reason: '',
});

async function submit() {
    submitting.value = true;
    error.value = null;
    const payload = {
        employee_id: Number(form.employee_id),
        start_datetime: `${form.start_date} ${form.full_day ? '00:00' : form.start_time}:00`,
        end_datetime: `${form.end_date} ${form.full_day ? '23:59' : form.end_time}:00`,
        type: form.type,
        reason: form.reason || null,
        source: 'manager_proxy',
    };
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
        <div class="w-full max-w-md overflow-hidden rounded-[6px] bg-white shadow-xl" @click.stop>
            <div class="flex items-center justify-between border-b border-ink-200/60 px-5 py-4">
                <h3 class="text-[15px] font-semibold text-ink-900">代為提交</h3>
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
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">員工 *</label>
                    <select
                        v-model="form.employee_id"
                        required
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    >
                        <option value="">— 選擇員工 —</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.name }} ({{ e.level_label }})
                        </option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">類型 *</label>
                    <select
                        v-model="form.type"
                        required
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    >
                        <option value="personal">事假</option>
                        <option value="sick">病假</option>
                        <option value="annual">特休</option>
                        <option value="funeral">喪假</option>
                        <option value="marriage">婚假</option>
                        <option value="other">其他</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">開始日期 *</label>
                        <input
                            v-model="form.start_date"
                            type="date"
                            required
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">結束日期 *</label>
                        <input
                            v-model="form.end_date"
                            type="date"
                            required
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                </div>

                <label class="flex items-center gap-2 text-[13px] text-ink-700">
                    <input v-model="form.full_day" type="checkbox" class="rounded border-ink-300" />
                    整天請假
                </label>

                <div v-if="!form.full_day" class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">開始時間</label>
                        <TimePicker v-model="form.start_time" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">結束時間</label>
                        <TimePicker v-model="form.end_time" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">原因</label>
                    <textarea
                        v-model="form.reason"
                        rows="2"
                        placeholder="（選填）"
                        class="w-full rounded-[5px] border border-ink-200/60 px-3 py-2 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
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
                    {{ submitting ? '處理中' : '建立' }}
                </button>
            </div>
        </div>
    </div>
</template>
