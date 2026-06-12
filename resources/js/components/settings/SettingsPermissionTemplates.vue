<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import ApplyTemplateModal from './ApplyTemplateModal.vue';
import PermissionMatrix from '../PermissionMatrix.vue';

const loading = ref(true);
const saving = ref(false);
const flash = ref(null);

const templates = ref([]);
const menuKeys = ref([]);
const menuLabels = ref({});

const activeId = ref(null);
const editing = reactive({
    id: null,
    name: '',
    description: '',
    permissions: {},
    is_system: false,
});

const active = computed(() => templates.value.find((t) => t.id === activeId.value) ?? null);
const isEditingSystem = computed(() => active.value?.is_system === true);
const isNew = computed(() => editing.id === null);

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/permission-templates');
        templates.value = data.data ?? [];
        menuKeys.value = data.menu_keys ?? [];
        menuLabels.value = data.menu_labels ?? {};
        if (!activeId.value && templates.value.length) {
            select(templates.value[0].id);
        }
    } catch (e) {
        flashMsg(e?.response?.data?.error ?? '讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

function select(id) {
    const t = templates.value.find((x) => x.id === id);
    if (!t) return;
    activeId.value = id;
    editing.id = t.id;
    editing.name = t.name;
    editing.description = t.description ?? '';
    editing.permissions = { ...(t.permissions ?? {}) };
    editing.is_system = t.is_system;
}

function newTemplate(basedOn = null) {
    activeId.value = null;
    editing.id = null;
    editing.name = basedOn ? `${basedOn.name} (複本)` : '新模板';
    editing.description = '';
    editing.permissions = basedOn ? { ...basedOn.permissions } : Object.fromEntries(menuKeys.value.map((k) => [k, 'none']));
    editing.is_system = false;
}

function flashMsg(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2400);
}

async function save() {
    if (editing.is_system) {
        flashMsg('系統內建模板不可直接修改,請另存為新模板', false);
        return;
    }
    saving.value = true;
    const payload = {
        name: editing.name,
        description: editing.description,
        permissions: editing.permissions,
    };
    try {
        if (isNew.value) {
            const { data } = await axios.post('/api/permission-templates', payload);
            flashMsg('已建立');
            await load();
            activeId.value = data.data.id;
            select(data.data.id);
        } else {
            await axios.put(`/api/permission-templates/${editing.id}`, payload);
            flashMsg('已儲存');
            await load();
        }
    } catch (e) {
        flashMsg(e?.response?.data?.error ?? '儲存失敗', false);
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!active.value || active.value.is_system) return;
    if (!confirm(`確定刪除模板「${active.value.name}」?套用此模板的員工會被解除綁定。`)) return;
    try {
        await axios.delete(`/api/permission-templates/${active.value.id}`);
        flashMsg('已刪除');
        activeId.value = null;
        await load();
        if (templates.value.length) select(templates.value[0].id);
    } catch (e) {
        flashMsg(e?.response?.data?.error ?? '刪除失敗', false);
    }
}

// ---------- 套用到員工 modal ----------
const applyingTo = ref(null);
function openApply() { applyingTo.value = active.value; }
function onApplied(result) {
    const emp = result?.employees_updated ?? 0;
    const usr = result?.users_updated ?? 0;
    flashMsg(`已套用 ${emp} 位員工${usr > 0 ? `(其中 ${usr} 位已綁定 LINE,即時生效)` : ''}`);
}

onMounted(load);
</script>

<template>
    <div v-if="loading" class="h-64" />
    <div v-else class="space-y-8">
        <section>
            <h3 class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Permission Templates</h3>
            <p class="mt-1 font-serif text-[16px] font-medium text-ink-900">權限模板</p>
            <p class="mt-1 text-[12px] leading-relaxed text-ink-500">
                依角色定義可看 / 可改的選單。系統內建 4 種(店家擁有者 / 店長 / 副店長 / 員工),
                可以「另存為」修改成你們店的版本。最高管理員不在這裡管理 — 全功能永遠開。
            </p>
        </section>

        <div v-if="flash"
            class="rounded-[5px] px-3.5 py-2.5 text-[12px]"
            :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <div class="grid grid-cols-[260px_1fr] gap-6">
            <!-- Left: template list -->
            <aside class="space-y-2">
                <button type="button" @click="newTemplate()"
                    class="w-full rounded-[5px] border border-dashed border-ink-300 px-3 py-2 text-[13px] text-ink-700 hover:bg-ink-50">
                    + 新模板
                </button>
                <ul class="space-y-1">
                    <li v-for="t in templates" :key="t.id">
                        <button type="button"
                            @click="select(t.id)"
                            class="block w-full rounded-[5px] px-3 py-2 text-left text-[13px] transition-colors"
                            :class="activeId === t.id ? 'bg-ink-900 text-white' : 'text-ink-800 hover:bg-ink-100'">
                            <div class="flex items-center justify-between gap-2">
                                <span>{{ t.name }}</span>
                                <span v-if="t.is_system" class="rounded-[2px] bg-ink-200/60 px-1 py-0.5 text-[9px] text-ink-600"
                                    :class="activeId === t.id ? 'bg-white/20 text-white' : ''">系統</span>
                            </div>
                            <p v-if="t.description" class="mt-0.5 text-[10.5px] opacity-70 line-clamp-1">{{ t.description }}</p>
                        </button>
                    </li>
                </ul>
            </aside>

            <!-- Right: editor -->
            <div v-if="active || isNew">
                <header class="mb-4 flex items-center gap-3">
                    <input v-model="editing.name" placeholder="模板名稱"
                        :disabled="isEditingSystem"
                        class="flex-1 rounded-[5px] border border-ink-300 px-3 py-2 text-[14px] font-medium" />
                    <button type="button" v-if="!isEditingSystem"
                        @click="save" :disabled="saving"
                        class="rounded-[5px] bg-ink-900 px-4 py-2 text-[13px] font-medium text-white disabled:opacity-50">
                        {{ saving ? '處理中…' : (isNew ? '建立' : '儲存') }}
                    </button>
                    <button type="button" v-if="!isEditingSystem && !isNew"
                        @click="remove"
                        class="rounded-[5px] border border-danger-300 px-3 py-2 text-[13px] text-danger-700 hover:bg-danger-50">
                        刪除
                    </button>
                    <button type="button" v-if="active"
                        @click="newTemplate(active)"
                        class="rounded-[5px] border border-ink-300 px-3 py-2 text-[13px] text-ink-700 hover:bg-ink-100">
                        另存為新模板
                    </button>
                    <button type="button" v-if="active && !isNew"
                        @click="openApply"
                        class="rounded-[5px] border border-success-300 bg-success-50 px-3 py-2 text-[13px] text-success-700 hover:bg-success-100">
                        套用到員工…
                    </button>
                </header>

                <input v-model="editing.description" placeholder="說明(選填)"
                    :disabled="isEditingSystem"
                    class="mb-4 w-full rounded-[5px] border border-ink-200 px-3 py-2 text-[12px]" />

                <p v-if="isEditingSystem" class="mb-3 rounded-[5px] bg-warning-50 px-3 py-2 text-[11px] text-warning-700">
                    系統內建模板僅供檢視。按「另存為新模板」可以複製一份來修改。
                </p>

                <PermissionMatrix v-model="editing.permissions"
                    :menu-keys="menuKeys" :menu-labels="menuLabels"
                    :readonly="isEditingSystem" />
            </div>
            <div v-else class="text-[13px] text-ink-500">請從左側選一個模板</div>
        </div>

        <ApplyTemplateModal
            v-if="applyingTo"
            :template="applyingTo"
            @close="applyingTo = null"
            @applied="onApplied"
        />
    </div>
</template>
