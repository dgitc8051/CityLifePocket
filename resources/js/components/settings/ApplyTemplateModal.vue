<script setup>
/**
 * 「套用模板到員工」批次選擇 modal。
 *
 * Props:
 *   template: { id, name }
 * Emits:
 *   close, applied(employees_updated, users_updated)
 */
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    template: { type: Object, required: true },
});
const emit = defineEmits(['close', 'applied']);

const loading = ref(true);
const submitting = ref(false);
const error = ref(null);

const employees = ref([]);
const selectedIds = ref(new Set());
const resetOverrides = ref(false);

const search = ref('');
const statusFilter = ref('active');   // active / all / leave / terminated

const filtered = computed(() => {
    let list = employees.value;
    if (statusFilter.value !== 'all') {
        list = list.filter((e) => e.status === statusFilter.value);
    }
    const q = search.value.trim().toLowerCase();
    if (q) list = list.filter((e) => (e.name + ' ' + (e.phone ?? '')).toLowerCase().includes(q));
    return list;
});

const allFilteredSelected = computed(() =>
    filtered.value.length > 0 && filtered.value.every((e) => selectedIds.value.has(e.id))
);

const adminCount = computed(() =>
    filtered.value.filter((e) => e.is_admin_promoted).length
);

function toggle(id) {
    if (selectedIds.value.has(id)) selectedIds.value.delete(id);
    else selectedIds.value.add(id);
    selectedIds.value = new Set(selectedIds.value);
}

function selectAllFiltered() {
    const next = new Set(selectedIds.value);
    if (allFilteredSelected.value) {
        // 取消全選
        filtered.value.forEach((e) => next.delete(e.id));
    } else {
        // 選擇全部(略過 admin)
        filtered.value
            .filter((e) => !e.is_admin_promoted)
            .forEach((e) => next.add(e.id));
    }
    selectedIds.value = next;
}

async function fetchEmployees() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/employees');
        employees.value = data.data ?? [];
    } catch (e) {
        error.value = '無法載入員工列表';
    } finally {
        loading.value = false;
    }
}

async function submit() {
    if (selectedIds.value.size === 0) return;
    submitting.value = true;
    error.value = null;
    try {
        const { data } = await axios.post(`/api/permission-templates/${props.template.id}/apply`, {
            employee_ids: Array.from(selectedIds.value),
            reset_overrides: resetOverrides.value,
        });
        emit('applied', data);
        emit('close');
    } catch (e) {
        error.value = e?.response?.data?.error ?? '套用失敗';
    } finally {
        submitting.value = false;
    }
}

onMounted(fetchEmployees);
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 px-4"
        @click.self="$emit('close')"
    >
        <div class="w-full max-w-2xl overflow-hidden rounded-[6px] bg-white shadow-xl" @click.stop>
            <div class="border-b border-ink-200/60 px-5 py-4">
                <h3 class="text-[15px] font-semibold text-ink-900">套用「{{ template.name }}」到員工</h3>
                <p class="mt-1 text-[11px] text-ink-500">
                    勾選的員工將套用此模板的權限設定。最高管理員不會被覆蓋。
                </p>
            </div>

            <div class="border-b border-ink-200/60 px-5 py-3 flex items-center gap-2">
                <input v-model="search" placeholder="搜尋姓名 / 電話…"
                    class="h-8 flex-1 rounded-[5px] border border-ink-200/60 px-3 text-[12px] outline-none focus:border-accent-500" />
                <select v-model="statusFilter"
                    class="h-8 rounded-[5px] border border-ink-200/60 bg-white px-2 text-[12px]">
                    <option value="active">在職</option>
                    <option value="leave">留職停薪</option>
                    <option value="terminated">離職</option>
                    <option value="all">全部</option>
                </select>
                <button type="button" @click="selectAllFiltered"
                    class="h-8 rounded-[5px] border border-ink-200/60 px-2 text-[11px] text-ink-700 hover:bg-ink-50">
                    {{ allFilteredSelected ? '取消全選' : '全選' }}
                </button>
            </div>

            <div class="max-h-[420px] overflow-y-auto px-5 py-3">
                <div v-if="loading" class="py-12 text-center text-[12px] text-ink-400">載入中…</div>
                <div v-else-if="filtered.length === 0" class="py-12 text-center text-[12px] text-ink-400">沒有符合的員工</div>
                <ul v-else class="divide-y divide-ink-200/60">
                    <li v-for="emp in filtered" :key="emp.id"
                        class="flex items-center justify-between gap-3 py-2.5">
                        <label class="flex flex-1 items-center gap-3 cursor-pointer"
                            :class="emp.is_admin_promoted ? 'opacity-50' : ''">
                            <input type="checkbox"
                                :checked="selectedIds.has(emp.id)"
                                :disabled="emp.is_admin_promoted"
                                @change="toggle(emp.id)"
                                class="h-4 w-4 rounded border-ink-300" />
                            <div class="flex-1">
                                <div class="flex items-baseline gap-2">
                                    <p class="text-[13px] font-medium text-ink-900">{{ emp.name }}</p>
                                    <span class="text-[11px] text-ink-500">{{ emp.level_label }} · {{ emp.system_role_label }}</span>
                                    <span v-if="emp.is_admin_promoted"
                                        class="rounded-[2px] bg-warning-100 px-1.5 py-0.5 text-[10px] text-warning-700">最高管理員</span>
                                </div>
                                <p class="text-[11px] text-ink-400">
                                    {{ emp.phone || '—' }}
                                    <span v-if="!emp.user_id" class="text-warning-600">· 未綁定 LINE,套用後等待登入時生效</span>
                                </p>
                            </div>
                        </label>
                    </li>
                </ul>
            </div>

            <div class="border-t border-ink-200/60 px-5 py-3">
                <label class="flex items-center gap-2 text-[12px] text-ink-700">
                    <input v-model="resetOverrides" type="checkbox" class="h-4 w-4 rounded border-ink-300" />
                    同時清除這些員工的「個人覆寫」(讓他們完全跟著模板走)
                </label>
            </div>

            <div v-if="error" class="border-t border-danger-200 bg-danger-50 px-5 py-2.5 text-[12px] text-danger-700">
                {{ error }}
            </div>

            <div class="flex items-center justify-between border-t border-ink-200/60 px-5 py-3">
                <p class="text-[12px] text-ink-500">
                    已選 <span class="num font-medium text-ink-900">{{ selectedIds.size }}</span> 位
                    <span v-if="adminCount > 0" class="ml-1 text-warning-600">(略過 {{ adminCount }} 位 admin)</span>
                </p>
                <div class="flex items-center gap-2">
                    <button type="button" @click="$emit('close')"
                        class="rounded-[5px] border border-ink-200 px-3 py-1.5 text-[13px] text-ink-700 hover:bg-ink-50">
                        取消
                    </button>
                    <button type="button" @click="submit"
                        :disabled="submitting || selectedIds.size === 0"
                        class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white disabled:opacity-50">
                        {{ submitting ? '處理中…' : `套用到 ${selectedIds.size} 位員工` }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
