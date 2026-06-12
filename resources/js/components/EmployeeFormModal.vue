<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';
import PermissionMatrix from './PermissionMatrix.vue';

const props = defineProps({
    employee: { type: Object, default: null },
    onSubmit: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const auth = useAuthStore();
const isAdmin = computed(() => auth.user?.role === 'admin');
const features = computed(() => auth.user?.current_shop?.features ?? {});

const isEdit = !!props.employee;
const isLineBound = computed(() => isEdit && !!props.employee?.line_user_id);
// 已綁定 + 非 admin → readonly
const lineFieldReadonly = computed(() => isLineBound.value && !isAdmin.value);

const submitting = ref(false);
const error = ref(null);

const stations = ref([]);
const selectedStationIds = ref(new Set(props.employee?.station_ids ?? []));

// ---------- 權限模板系統 ----------
const templates = ref([]);
const menuKeys = ref([]);
const menuLabels = ref({});

async function fetchTemplates() {
    try {
        const { data } = await axios.get('/api/permission-templates');
        templates.value = data.data ?? [];
        menuKeys.value = data.menu_keys ?? [];
        menuLabels.value = data.menu_labels ?? {};
    } catch (e) { /* 沒權限看模板就略過 */ }
}

// 三模式:admin / template / custom
const initialMode = (() => {
    if (props.employee?.is_admin_promoted) return 'admin';
    if (props.employee?.permission_overrides_json) return 'custom';
    return 'template';
})();
const permMode = ref(initialMode);
const selectedTemplateId = ref(props.employee?.permission_template_id ?? null);
const customMatrix = ref(props.employee?.permission_overrides_json ?? {});

// 「自訂」模式下,當切換 base template 時把模板矩陣複製進來當起點
function loadTemplateIntoCustom(tplId) {
    const t = templates.value.find((x) => x.id === tplId);
    if (t) customMatrix.value = { ...(t.permissions ?? {}) };
}

watch(templates, (v) => {
    // 第一次拿到模板時,如果沒有預設,挑一個合理的(staff 模板)
    if (!selectedTemplateId.value && v.length) {
        const fallback = v.find((t) => t.is_system && t.name === '員工') ?? v[0];
        selectedTemplateId.value = fallback?.id ?? null;
    }
});

async function fetchStations() {
    try {
        const { data } = await axios.get('/api/stations');
        stations.value = (data.data ?? []).filter((s) => s.is_active);
    } catch (e) { /* skip */ }
}

function toggleStation(id) {
    if (selectedStationIds.value.has(id)) selectedStationIds.value.delete(id);
    else selectedStationIds.value.add(id);
    selectedStationIds.value = new Set(selectedStationIds.value);
}

const form = reactive({
    name: props.employee?.name ?? '',
    phone: props.employee?.phone ?? '',
    birthday: props.employee?.birthday ?? '',
    line_user_id: props.employee?.line_user_id ?? '',
    skill_score: props.employee?.skill_score ?? 3,
    level: props.employee?.level ?? 'trainee',
    system_role: props.employee?.system_role ?? 'staff',
    employment_type: props.employee?.employment_type ?? 'part',
    hire_date: props.employee?.hire_date ?? '',
    status: props.employee?.status ?? 'active',
    weekly_max_hours: props.employee?.weekly_max_hours ?? '',
    weekly_min_hours: props.employee?.weekly_min_hours ?? '',
    daily_max_hours: props.employee?.daily_max_hours ?? '',
    hourly_wage: props.employee?.hourly_wage ?? '',
    monthly_salary: props.employee?.monthly_salary ?? '',
    notes: props.employee?.notes ?? '',
});

onMounted(() => {
    fetchStations();
    fetchTemplates();
});

watch(() => form.level, (newLevel) => {
    const defaults = { trainee: 2, junior: 4, senior: 6, lead: 9 };
    if (!isEdit && defaults[newLevel] !== undefined) {
        form.skill_score = defaults[newLevel];
    }
});

async function submit() {
    submitting.value = true;
    error.value = null;
    const payload = { ...form };
    Object.keys(payload).forEach((k) => {
        if (payload[k] === '' || payload[k] === null) delete payload[k];
    });
    if (payload.skill_score) payload.skill_score = Number(payload.skill_score);
    if (payload.weekly_max_hours) payload.weekly_max_hours = Number(payload.weekly_max_hours);
    if (payload.weekly_min_hours !== undefined && payload.weekly_min_hours !== '') payload.weekly_min_hours = Number(payload.weekly_min_hours);
    if (payload.daily_max_hours) payload.daily_max_hours = Number(payload.daily_max_hours);
    if (payload.hourly_wage) payload.hourly_wage = Number(payload.hourly_wage);
    if (payload.monthly_salary) payload.monthly_salary = Number(payload.monthly_salary);
    payload.station_ids = Array.from(selectedStationIds.value);

    // 權限模式
    if (permMode.value === 'admin') {
        payload.make_admin = true;
        payload.permission_template_id = null;
        payload.permissions_json = null;
    } else if (permMode.value === 'template') {
        payload.make_admin = false;
        payload.permission_template_id = selectedTemplateId.value;
        payload.permissions_json = null;
    } else {
        // custom — 模板當基底,矩陣為 override(也存 base template_id 方便顯示)
        payload.make_admin = false;
        payload.permission_template_id = selectedTemplateId.value;
        payload.permissions_json = customMatrix.value;
    }

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
        <div
            class="w-full max-w-lg overflow-hidden rounded-[6px] bg-white shadow-xl"
            @click.stop
        >
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-ink-200/60 px-5 py-4">
                <h3 class="font-serif text-[16px] font-medium tracking-tight text-ink-900">
                    {{ isEdit ? '員工編輯' : '員工建立' }}
                </h3>
                <button
                    type="button"
                    @click="close"
                    class="rounded-[5px] px-2 py-1 text-[12px] text-ink-500 transition-colors hover:bg-ink-100"
                >
                    取消
                </button>
            </div>

            <!-- Body -->
            <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">姓名 *</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            職階 *
                            <span class="ml-1 font-normal text-ink-400">工作等級</span>
                        </label>
                        <select
                            v-model="form.level"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        >
                            <option value="trainee">新手</option>
                            <option value="junior">初階</option>
                            <option value="senior">熟手</option>
                            <option value="lead">領班</option>
                        </select>
                    </div>
                    <div v-if="features.skill_score">
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            能力分數 *
                            <span class="ml-1 font-normal text-ink-400">1-10</span>
                        </label>
                        <input
                            v-model.number="form.skill_score"
                            type="number"
                            min="1"
                            max="10"
                            required
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">雇用類型 *</label>
                        <select
                            v-model="form.employment_type"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        >
                            <option value="full">全職</option>
                            <option value="part">兼職</option>
                            <option value="intern">實習</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            角色權限
                            <span class="ml-1 font-normal text-ink-400">登入系統時的權限(不影響排班)</span>
                        </label>

                        <!-- 三模式分段控制 -->
                        <div class="mb-2 inline-flex rounded-[5px] border border-ink-200/60 bg-ink-50 p-0.5 text-[12px]">
                            <button
                                v-if="isAdmin"
                                type="button"
                                @click="permMode = 'admin'"
                                :class="permMode === 'admin' ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-500 hover:text-ink-900'"
                                class="rounded-[4px] px-3 py-1.5 transition-colors"
                            >最高管理員</button>
                            <button
                                type="button"
                                @click="permMode = 'template'"
                                :class="permMode === 'template' ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-500 hover:text-ink-900'"
                                class="rounded-[4px] px-3 py-1.5 transition-colors"
                            >套用模板</button>
                            <button
                                type="button"
                                @click="(function(){ permMode = 'custom'; if (Object.keys(customMatrix).length === 0 && selectedTemplateId) loadTemplateIntoCustom(selectedTemplateId); })()"
                                :class="permMode === 'custom' ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-500 hover:text-ink-900'"
                                class="rounded-[4px] px-3 py-1.5 transition-colors"
                            >自訂</button>
                        </div>

                        <!-- 模式內容 -->
                        <div v-if="permMode === 'admin'" class="rounded-[5px] border border-warning-200 bg-warning-50/60 px-3 py-2.5 text-[12px] text-warning-700">
                            ⚠ 最高管理員擁有所有權限,包含跨組織、模板管理、員工帳號管理。請審慎授予。
                        </div>

                        <div v-else-if="permMode === 'template'">
                            <select v-model.number="selectedTemplateId"
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100">
                                <option v-for="t in templates" :key="t.id" :value="t.id">
                                    {{ t.name }}<template v-if="t.is_system"> · 系統內建</template>
                                </option>
                            </select>
                            <p v-if="templates.find((t) => t.id === selectedTemplateId)?.description" class="mt-1.5 text-[11px] text-ink-500">
                                {{ templates.find((t) => t.id === selectedTemplateId)?.description }}
                            </p>
                            <p class="mt-1.5 text-[11px] text-ink-400">
                                需新增 / 修改模板?到「店家資料 → 權限模板」頁面管理。
                            </p>
                        </div>

                        <div v-else-if="permMode === 'custom'" class="space-y-2">
                            <div class="flex items-center gap-2 text-[12px]">
                                <span class="text-ink-500">以模板為起點:</span>
                                <select v-model.number="selectedTemplateId" @change="loadTemplateIntoCustom(selectedTemplateId)"
                                    class="h-7 rounded-[4px] border border-ink-200/60 bg-white px-2 text-[12px]">
                                    <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                                <button type="button" @click="loadTemplateIntoCustom(selectedTemplateId)"
                                    class="rounded-[4px] border border-ink-200/60 px-2 py-1 text-[11px] text-ink-600 hover:bg-ink-50">
                                    重新載入模板
                                </button>
                            </div>
                            <PermissionMatrix
                                v-if="menuKeys.length"
                                v-model="customMatrix"
                                :menu-keys="menuKeys"
                                :menu-labels="menuLabels"
                            />
                            <p class="text-[11px] text-ink-400">
                                此員工專屬覆寫;不會儲存成模板。常用就到「權限模板」頁面建立模板更方便。
                            </p>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">電話</label>
                        <input
                            v-model="form.phone"
                            type="tel"
                            placeholder="0912345678"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            生日 <span class="font-normal text-ink-400">（打卡密碼 = MMDD）</span>
                        </label>
                        <input
                            v-model="form.birthday"
                            type="date"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 flex items-baseline justify-between text-[12px] font-medium text-ink-700">
                            <span>
                                LINE User ID
                                <span v-if="isLineBound" class="ml-1 font-normal text-success-700">✓ 已綁定</span>
                                <span v-else class="ml-1 font-normal text-ink-400">由員工 LINE 登入後自助綁定</span>
                            </span>
                            <span v-if="lineFieldReadonly" class="text-[10px] tracking-[0.05em] text-ink-400">
                                已鎖定·只有 admin 可變更
                            </span>
                        </label>
                        <input
                            v-model="form.line_user_id"
                            type="text"
                            :readonly="lineFieldReadonly"
                            placeholder="U96fdc9fad9db4ca701eb9e86209dbd1a"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] font-mono tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                            :class="lineFieldReadonly ? 'bg-ink-50 text-ink-500 cursor-not-allowed' : ''"
                        />
                        <p v-if="isAdmin && isLineBound" class="mt-1 text-[10.5px] text-ink-400">
                            清空此欄位後儲存 → 自動清除 user 連結，員工下次 LINE 登入可重新綁定。
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">入職日期</label>
                        <input
                            v-model="form.hire_date"
                            type="date"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            每天上限工時
                            <span class="ml-1 font-normal text-ink-400">演算法軟限制</span>
                        </label>
                        <input
                            v-model="form.daily_max_hours"
                            type="number"
                            min="1"
                            max="24"
                            placeholder="8"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            每週上限工時
                            <span class="ml-1 font-normal text-ink-400">演算法軟限制</span>
                        </label>
                        <input
                            v-model="form.weekly_max_hours"
                            type="number"
                            min="1"
                            max="168"
                            placeholder="40"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            每週最低工時
                            <span class="ml-1 font-normal text-ink-400">正職適用·軟限制</span>
                        </label>
                        <input
                            v-model="form.weekly_min_hours"
                            type="number"
                            min="0"
                            max="168"
                            placeholder="40"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                    <div v-if="features.payroll" class="col-span-2 grid grid-cols-2 gap-3 rounded-[5px] bg-ink-50/60 p-3">
                        <p class="col-span-2 text-[11px] tracking-[0.02em] text-ink-500">
                            薪資（兼職填時薪、正職填月薪。兩個都填會優先用月薪）
                        </p>
                        <div>
                            <label class="mb-1 block text-[12px] font-medium text-ink-700">
                                時薪
                                <span class="ml-1 font-normal text-ink-400">NT$ / 小時</span>
                            </label>
                            <input
                                v-model="form.hourly_wage"
                                type="number"
                                min="0"
                                max="10000"
                                placeholder="190"
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-[12px] font-medium text-ink-700">
                                月薪
                                <span class="ml-1 font-normal text-ink-400">NT$ / 月</span>
                            </label>
                            <input
                                v-model="form.monthly_salary"
                                type="number"
                                min="0"
                                max="9999999"
                                placeholder="35000"
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                            />
                        </div>
                    </div>
                    <div v-if="isEdit" class="col-span-2">
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">狀態</label>
                        <select
                            v-model="form.status"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        >
                            <option value="active">在職</option>
                            <option value="leave">請長假</option>
                            <option value="terminated">離職</option>
                        </select>
                    </div>
                    <div v-if="features.stations" class="col-span-2">
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">
                            可上站別
                            <span class="ml-1 font-normal text-ink-400">勾選此員工會做的站</span>
                        </label>
                        <div v-if="stations.length" class="flex flex-wrap gap-1.5">
                            <button v-for="s in stations" :key="s.id" type="button" @click="toggleStation(s.id)"
                                class="inline-flex items-center gap-1.5 rounded-[4px] border px-2.5 py-1 text-[11px] tracking-[0.02em] transition-colors"
                                :class="selectedStationIds.has(s.id)
                                    ? 'border-ink-900 bg-ink-900 text-white'
                                    : 'border-ink-200/60 bg-white text-ink-700 hover:bg-ink-50'">
                                <span class="inline-block h-2 w-2 rounded-[1px]" :style="{ backgroundColor: s.color || '#94a3b8' }" />
                                {{ s.name }}
                            </button>
                        </div>
                        <p v-else class="text-[10.5px] text-ink-400">
                            尚未設定站別。請先到「店家資料 → 站別管理」建立。
                        </p>
                    </div>
                    <div class="col-span-2">
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">備註</label>
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            placeholder="（選填）"
                            class="w-full rounded-[5px] border border-ink-200/60 px-3 py-2 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                        />
                    </div>
                </div>

                <div
                    v-if="error"
                    class="rounded-[5px] bg-danger-50 px-3 py-2 text-[12px] text-danger-700 whitespace-pre-line"
                >
                    {{ error }}
                </div>
            </form>

            <!-- Footer -->
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
                    {{ submitting ? '儲存中' : isEdit ? '儲存' : '建立員工' }}
                </button>
            </div>
        </div>
    </div>
</template>
