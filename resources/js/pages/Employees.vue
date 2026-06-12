<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import EmployeeFormModal from '../components/EmployeeFormModal.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const features = computed(() => auth.user?.current_shop?.features ?? {});

const loading = ref(true);
const employees = ref([]);
const meta = ref({ total: 0, active: 0 });
const error = ref(null);
const search = ref('');
const statusFilter = ref('all');

const showModal = ref(false);
const editing = ref(null);

const filteredEmployees = computed(() => {
    let list = employees.value;
    if (statusFilter.value !== 'all') {
        list = list.filter((e) => e.status === statusFilter.value);
    }
    if (search.value.trim()) {
        const q = search.value.trim().toLowerCase();
        list = list.filter((e) =>
            (e.name || '').toLowerCase().includes(q) ||
            (e.phone || '').includes(q),
        );
    }
    return list;
});

async function fetchEmployees() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get('/api/employees');
        employees.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        error.value = e?.response?.data?.error ?? '讀取失敗';
    } finally {
        loading.value = false;
    }
}

function openCreate() { editing.value = null; showModal.value = true; }
function openEdit(emp) { editing.value = emp; showModal.value = true; }

async function handleSubmit(payload) {
    try {
        if (editing.value) {
            await axios.put(`/api/employees/${editing.value.id}`, payload);
        } else {
            await axios.post('/api/employees', payload);
        }
        showModal.value = false;
        await fetchEmployees();
        return null;
    } catch (e) {
        const errs = e?.response?.data?.errors;
        if (errs) return Object.values(errs).flat().join('\n');
        return '處理失敗';
    }
}

async function handleTerminate(emp) {
    if (!confirm(`確定要將「${emp.name}」標記為離職？`)) return;
    try {
        await axios.delete(`/api/employees/${emp.id}`);
        await fetchEmployees();
    } catch (e) {
        alert('處理失敗');
    }
}

function statusLabel(s) {
    return { active: '在職', leave: '長假', terminated: '離職' }[s] || s;
}

function statusClass(s) {
    return {
        'text-success-700': s === 'active',
        'text-warning-700': s === 'leave',
        'text-ink-400': s === 'terminated',
    };
}

function bindingClass(level) {
    return {
        'text-ink-400': level === 'L0',
        'text-accent-600': level === 'L1',
        'text-sumi-600': level === 'L2',
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

onMounted(fetchEmployees);
</script>

<template>
    <div class="space-y-10">
        <!-- Header -->
        <section class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Members</p>
                <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">員工</h2>
                <p class="num mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                    共 {{ meta.total }} 位，在職 {{ meta.active }} 位
                </p>
            </div>
            <button type="button" @click="openCreate"
                class="rounded-[5px] bg-sumi-600 px-4 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500">
                員工建立
            </button>
        </section>

        <!-- Filters -->
        <section class="flex flex-wrap items-center gap-4">
            <input v-model="search" type="search" placeholder="搜尋姓名或電話"
                class="h-9 w-full max-w-xs rounded-[5px] border border-ink-200/60 bg-white px-3.5 text-[12px] tracking-[0.02em] outline-none transition-colors focus:border-ink-400" />
            <nav class="flex items-center gap-px rounded-[5px] border border-ink-200/60 bg-white p-0.5 text-[11px]">
                <button v-for="opt in [
                    { val: 'all', label: '全部' },
                    { val: 'active', label: '在職' },
                    { val: 'leave', label: '長假' },
                    { val: 'terminated', label: '離職' },
                ]" :key="opt.val" @click="statusFilter = opt.val" type="button"
                    class="rounded-[3px] px-3 py-1 tracking-[0.05em] transition-colors"
                    :class="statusFilter === opt.val
                        ? 'bg-ink-100 text-ink-900 font-medium'
                        : 'text-ink-500 hover:text-ink-900'">
                    {{ opt.label }}
                </button>
            </nav>
        </section>

        <div v-if="loading" class="h-64 border-y border-ink-200/60" />
        <div v-else-if="error" class="rounded-[5px] bg-danger-50 px-5 py-4 text-[12px] text-danger-700">{{ error }}</div>
        <div v-else-if="filteredEmployees.length === 0"
            class="border-y border-ink-200/60 py-20 text-center text-[12px] text-ink-400">
            無符合條件的員工
        </div>

        <!-- Table -->
        <div v-else class="overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead>
                    <tr class="border-y border-ink-200/60 text-left text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">
                        <th class="py-3 pr-4 font-normal">姓名</th>
                        <th class="py-3 pr-4 font-normal">{{ features.skill_score ? '等級・分數' : '等級' }}</th>
                        <th class="py-3 pr-4 font-normal">類型</th>
                        <th class="py-3 pr-4 font-normal">綁定</th>
                        <th class="py-3 pr-4 font-normal">入職</th>
                        <th class="py-3 pr-4 font-normal">電話</th>
                        <th class="py-3 pr-4 font-normal">狀態</th>
                        <th class="py-3 text-right font-normal">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-200/60">
                    <tr v-for="emp in filteredEmployees" :key="emp.id"
                        class="transition-colors hover:bg-ink-100/30">
                        <td class="py-4 pr-4">
                            <p class="font-medium text-ink-900">{{ emp.name }}</p>
                            <p v-if="emp.notes" class="mt-0.5 truncate text-[10px] tracking-[0.02em] text-ink-400">
                                {{ emp.notes }}
                            </p>
                        </td>
                        <td class="py-4 pr-4">
                            <div class="flex items-center gap-2 text-ink-700">
                                <span class="h-1 w-1 rounded-full" :class="levelDot(emp.level)" />
                                <span>{{ emp.level_label }}</span>
                                <template v-if="features.skill_score">
                                    <span class="text-ink-400">·</span>
                                    <span class="num">{{ emp.skill_score }}</span>
                                </template>
                            </div>
                        </td>
                        <td class="py-4 pr-4 text-ink-700">{{ emp.employment_type_label }}</td>
                        <td class="py-4 pr-4">
                            <span class="num text-[10px] font-medium tracking-[0.05em]" :class="bindingClass(emp.binding_level)">
                                {{ emp.binding_level }}
                            </span>
                        </td>
                        <td class="num py-4 pr-4 text-ink-500">{{ emp.hire_date ?? '—' }}</td>
                        <td class="num py-4 pr-4 text-ink-500">{{ emp.phone ?? '—' }}</td>
                        <td class="py-4 pr-4">
                            <span class="text-[11px] tracking-[0.05em]" :class="statusClass(emp.status)">
                                · {{ statusLabel(emp.status) }}
                            </span>
                        </td>
                        <td class="py-4 text-right">
                            <button type="button" @click="openEdit(emp)"
                                class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-900">
                                編輯
                            </button>
                            <button v-if="emp.status !== 'terminated'" type="button" @click="handleTerminate(emp)"
                                class="ml-1 rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-ink-500 transition-colors hover:bg-danger-50 hover:text-danger-700">
                                離職
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmployeeFormModal v-if="showModal" :employee="editing" :on-submit="handleSubmit"
            @close="showModal = false" />
    </div>
</template>
