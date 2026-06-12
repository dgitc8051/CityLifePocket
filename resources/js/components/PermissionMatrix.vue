<script setup>
/**
 * 權限矩陣編輯器。
 *
 * v-model: { [menu_key]: 'rw' | 'r' | 'none' }
 *
 * UI 規則:
 *  - 「可存取」未勾 → 沒勾「可編輯」(且 disable)
 *  - 「可存取」勾 + 「可編輯」未勾 → 唯讀(value = 'r')
 *  - 「可存取」勾 + 「可編輯」勾 → 完整 (value = 'rw')
 *
 * 工具列:全部可存取 / 全部可編輯 / 清除
 */
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    menuKeys: { type: Array, required: true },
    menuLabels: { type: Object, required: true },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

function get(key) {
    return props.modelValue[key] ?? 'none';
}

function setPermission(key, level) {
    if (props.readonly) return;
    emit('update:modelValue', { ...props.modelValue, [key]: level });
}

function toggleRead(key) {
    const cur = get(key);
    setPermission(key, cur === 'none' ? 'r' : 'none');
}

function toggleWrite(key) {
    const cur = get(key);
    if (cur === 'none') return;     // 沒可存取 → 不可勾可編輯
    setPermission(key, cur === 'rw' ? 'r' : 'rw');
}

function allRead() {
    const out = {};
    props.menuKeys.forEach((k) => { out[k] = get(k) === 'rw' ? 'rw' : 'r'; });
    emit('update:modelValue', out);
}

function allWrite() {
    const out = {};
    props.menuKeys.forEach((k) => { out[k] = 'rw'; });
    emit('update:modelValue', out);
}

function clear() {
    const out = {};
    props.menuKeys.forEach((k) => { out[k] = 'none'; });
    emit('update:modelValue', out);
}

const stats = computed(() => {
    let rw = 0, r = 0, none = 0;
    props.menuKeys.forEach((k) => {
        const v = get(k);
        if (v === 'rw') rw++;
        else if (v === 'r') r++;
        else none++;
    });
    return { rw, r, none };
});
</script>

<template>
    <div>
        <!-- Toolbar -->
        <div v-if="!readonly" class="mb-3 flex flex-wrap items-center gap-2 text-[12px]">
            <button type="button" @click="allRead"
                class="rounded-[3px] border border-ink-300 px-2.5 py-1 text-ink-700 hover:bg-ink-100">全部可存取</button>
            <button type="button" @click="allWrite"
                class="rounded-[3px] border border-ink-300 px-2.5 py-1 text-ink-700 hover:bg-ink-100">全部可編輯</button>
            <button type="button" @click="clear"
                class="rounded-[3px] border border-ink-300 px-2.5 py-1 text-ink-700 hover:bg-ink-100">清除</button>
            <span class="ml-auto text-[11px] text-ink-500">
                可編輯 {{ stats.rw }} · 唯讀 {{ stats.r }} · 不可存取 {{ stats.none }}
            </span>
        </div>

        <!-- Matrix -->
        <table class="w-full border border-ink-200 text-[13px]">
            <thead class="bg-ink-50">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-ink-700">選單項目</th>
                    <th class="w-24 px-3 py-2 text-center font-medium text-ink-700">可存取</th>
                    <th class="w-24 px-3 py-2 text-center font-medium text-ink-700">可編輯</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="key in menuKeys" :key="key" class="border-t border-ink-100">
                    <td class="px-3 py-2 text-ink-800">{{ menuLabels[key] ?? key }}</td>
                    <td class="px-3 py-2 text-center">
                        <input type="checkbox"
                            :checked="get(key) !== 'none'"
                            :disabled="readonly"
                            @change="toggleRead(key)" />
                    </td>
                    <td class="px-3 py-2 text-center">
                        <input type="checkbox"
                            :checked="get(key) === 'rw'"
                            :disabled="readonly || get(key) === 'none'"
                            @change="toggleWrite(key)" />
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="mt-2 text-[11px] text-ink-500">
            「可編輯」僅在勾選「可存取」後可用。
        </p>
    </div>
</template>
