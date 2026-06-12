<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    minuteStep: { type: Number, default: 5 },
    size: { type: String, default: 'md' },
});
const emit = defineEmits(['update:modelValue']);

const hours = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));

function parse(v) {
    if (!v || typeof v !== 'string') return { h: '00', m: '00' };
    const parts = v.split(':');
    const h = (parts[0] ?? '00').padStart(2, '0').slice(-2);
    const m = (parts[1] ?? '00').padStart(2, '0').slice(0, 2);
    return { h, m };
}

const current = computed(() => parse(props.modelValue));

const minutes = computed(() => {
    const step = Math.max(1, props.minuteStep);
    const set = new Set();
    for (let m = 0; m < 60; m += step) set.add(String(m).padStart(2, '0'));
    set.add(current.value.m);
    return [...set].sort();
});

function update(h, m) {
    emit('update:modelValue', `${h}:${m}`);
}

const selectClass = computed(() => {
    const sizeClass = props.size === 'sm' ? 'h-8' : 'h-9';
    return `${sizeClass} rounded-[5px] border border-ink-200/60 bg-white px-2 text-[13px] tabular-nums outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100 disabled:bg-ink-50 disabled:opacity-60`;
});
</script>

<template>
    <div class="inline-flex items-center gap-1">
        <select
            :value="current.h"
            :disabled="disabled"
            @change="update($event.target.value, current.m)"
            :class="selectClass"
        >
            <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
        </select>
        <span class="select-none text-ink-400">:</span>
        <select
            :value="current.m"
            :disabled="disabled"
            @change="update(current.h, $event.target.value)"
            :class="selectClass"
        >
            <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
        </select>
    </div>
</template>
