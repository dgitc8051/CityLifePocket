<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const loading = ref(true);
const stations = ref([]);
const flash = ref(null);

const showModal = ref(false);
const editing = ref(null);
const form = reactive({ name: '', color: '#1e3a8a', sort_order: 0, is_active: true });
const saving = ref(false);
const formError = ref(null);

// 顏色預設（讓店家快速挑色，但允許自填）
const colorPresets = [
    '#1e3a8a', '#0f766e', '#7c2d12', '#9f1239',
    '#a16207', '#15803d', '#7e22ce', '#475569',
];

// 例範本：飲料店 / 早午餐 / 中式餐廳。店家也可以完全自己填。
const presetSets = [
    { label: '飲料店', items: ['收銀', '製作', '備料', '外送'] },
    { label: '早午餐 / 簡餐', items: ['內場', '外場', '收銀', '吧台'] },
    { label: '正餐廳', items: ['廚房', '出餐', '外場', '吧台', '收銀'] },
];

function flashMsg(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2400);
}

async function fetchStations() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/stations');
        stations.value = data.data ?? [];
    } catch (e) {
        flashMsg('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    editing.value = null;
    Object.assign(form, { name: '', color: colorPresets[stations.value.length % colorPresets.length], sort_order: (stations.value.length + 1) * 10, is_active: true });
    formError.value = null;
    showModal.value = true;
}

function openEdit(s) {
    editing.value = s;
    Object.assign(form, { name: s.name, color: s.color ?? '#1e3a8a', sort_order: s.sort_order, is_active: s.is_active });
    formError.value = null;
    showModal.value = true;
}

async function save() {
    saving.value = true;
    formError.value = null;
    try {
        if (editing.value) {
            await axios.put(`/api/stations/${editing.value.id}`, form);
        } else {
            await axios.post('/api/stations', form);
        }
        showModal.value = false;
        await fetchStations();
        flashMsg('已儲存');
    } catch (e) {
        const errs = e?.response?.data?.errors;
        formError.value = errs ? Object.values(errs).flat().join('\n') : '儲存失敗';
    } finally {
        saving.value = false;
    }
}

async function remove(s) {
    if (!confirm(`刪除「${s.name}」？已分配此站的員工會自動取消勾選。`)) return;
    try {
        await axios.delete(`/api/stations/${s.id}`);
        await fetchStations();
        flashMsg('已刪除');
    } catch (e) {
        flashMsg('刪除失敗', false);
    }
}

async function applyPreset(items) {
    if (!confirm(`一鍵建立 ${items.length} 個站別？已存在的同名站別會略過。`)) return;
    const existingNames = new Set(stations.value.map((s) => s.name));
    let added = 0;
    for (let i = 0; i < items.length; i++) {
        const name = items[i];
        if (existingNames.has(name)) continue;
        try {
            await axios.post('/api/stations', {
                name,
                color: colorPresets[i % colorPresets.length],
                sort_order: (stations.value.length + added + 1) * 10,
                is_active: true,
            });
            added++;
        } catch (e) { /* skip */ }
    }
    await fetchStations();
    flashMsg(`已新增 ${added} 個站別`);
}

onMounted(fetchStations);
</script>

<template>
    <div v-if="loading" class="h-64 border-y border-ink-200/60" />
    <div v-else class="space-y-8">
        <section>
            <h3 class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Stations</h3>
            <p class="mt-1 font-serif text-[16px] font-medium text-ink-900">站別管理</p>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                自訂這家店有哪些工作崗位（如：收銀、廚房、外場）。員工資料與時段設定都會用到。
            </p>
        </section>

        <!-- Preset shortcuts (只在沒有站別時顯示) -->
        <div v-if="!stations.length" class="rounded-[5px] border border-ink-200/60 bg-ink-50/40 p-4">
            <p class="text-[11px] font-medium tracking-[0.05em] text-ink-700">快速建立常見組合</p>
            <p class="mt-1 text-[10.5px] text-ink-400">建完後可隨時增刪、改名、改顏色。</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button v-for="p in presetSets" :key="p.label" type="button" @click="applyPreset(p.items)"
                    class="rounded-[4px] border border-ink-200/60 bg-white px-3 py-1.5 text-left transition-colors hover:bg-ink-100/60">
                    <span class="block text-[12px] font-medium text-ink-900">{{ p.label }}</span>
                    <span class="block text-[10px] tracking-[0.02em] text-ink-500">{{ p.items.join('・') }}</span>
                </button>
            </div>
        </div>

        <div v-if="flash"
            class="rounded-[5px] px-3.5 py-2.5 text-[12px] tracking-[0.02em]"
            :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <!-- Station list -->
        <div>
            <div class="flex items-center justify-between">
                <p class="text-[12px] text-ink-600">已有 {{ stations.length }} 個站別</p>
                <button type="button" @click="openCreate"
                    class="rounded-[5px] bg-sumi-600 px-3.5 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500">
                    新增站別
                </button>
            </div>

            <ul v-if="stations.length" class="mt-4 divide-y divide-ink-200/60 border-y border-ink-200/60">
                <li v-for="s in stations" :key="s.id"
                    class="flex items-center gap-4 py-3">
                    <span class="inline-block h-3 w-3 rounded-[2px]" :style="{ backgroundColor: s.color || '#94a3b8' }" />
                    <div class="flex-1">
                        <p class="text-[13px] font-medium text-ink-900">{{ s.name }}</p>
                        <p class="text-[10.5px] tracking-[0.02em] text-ink-400">
                            {{ s.employee_count }} 位員工已分配
                            <span v-if="!s.is_active" class="ml-2 text-warning-700">已停用</span>
                        </p>
                    </div>
                    <button type="button" @click="openEdit(s)"
                        class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-ink-500 hover:bg-ink-100 hover:text-ink-900">
                        編輯
                    </button>
                    <button type="button" @click="remove(s)"
                        class="rounded-[3px] px-2 py-0.5 text-[11px] tracking-[0.05em] text-ink-500 hover:bg-danger-50 hover:text-danger-700">
                        刪除
                    </button>
                </li>
            </ul>
            <div v-else class="mt-4 border-y border-ink-200/60 py-12 text-center text-[12px] text-ink-400">
                還沒有站別。上面點預設組合或右上「新增站別」開始建立。
            </div>
        </div>

        <!-- Modal -->
        <div v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 px-4"
            @click.self="showModal = false">
            <div class="w-full max-w-md overflow-hidden rounded-[6px] bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-ink-200/60 px-5 py-4">
                    <h3 class="text-[15px] font-semibold text-ink-900">{{ editing ? '站別編輯' : '站別建立' }}</h3>
                    <button type="button" @click="showModal = false"
                        class="rounded-[5px] px-2 py-1 text-[12px] text-ink-500 hover:bg-ink-100">取消</button>
                </div>
                <form @submit.prevent="save" class="space-y-4 px-5 py-5">
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">站別名稱 *</label>
                        <input v-model="form.name" type="text" required maxlength="32" placeholder="例：收銀、廚房、外場"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[12px] font-medium text-ink-700">顏色</label>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button v-for="c in colorPresets" :key="c" type="button" @click="form.color = c"
                                class="h-7 w-7 rounded-[3px] border-2 transition-transform hover:scale-110"
                                :class="form.color === c ? 'border-ink-900' : 'border-transparent'"
                                :style="{ backgroundColor: c }" />
                            <input v-model="form.color" type="color"
                                class="ml-1 h-7 w-9 cursor-pointer rounded-[3px] border border-ink-200/60 bg-transparent" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-[12px] font-medium text-ink-700">排序</label>
                            <input v-model.number="form.sort_order" type="number" min="0" max="9999"
                                class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100" />
                        </div>
                        <div class="flex items-end">
                            <label class="flex items-center gap-2 text-[12px] text-ink-700">
                                <input v-model="form.is_active" type="checkbox" class="rounded border-ink-300" />
                                啟用中
                            </label>
                        </div>
                    </div>
                    <div v-if="formError" class="rounded-[5px] bg-danger-50 px-3 py-2 text-[12px] text-danger-700 whitespace-pre-line">
                        {{ formError }}
                    </div>
                </form>
                <div class="flex items-center justify-end gap-2 border-t border-ink-200/60 bg-ink-50/60 px-5 py-3">
                    <button type="button" @click="showModal = false" :disabled="saving"
                        class="rounded-[5px] border border-ink-200/60 bg-white px-3 py-1.5 text-[13px] text-ink-700 hover:bg-ink-50 disabled:opacity-50">取消</button>
                    <button type="button" @click="save" :disabled="saving"
                        class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white hover:bg-ink-800 disabled:opacity-50">
                        {{ saving ? '儲存中' : (editing ? '儲存' : '建立站別') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
