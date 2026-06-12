<script setup>
import { computed } from 'vue';
import SettingsShiftTemplates from '../components/settings/SettingsShiftTemplates.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const features = computed(() => auth.user?.current_shop?.features ?? {});

const headerDesc = computed(() => {
    const parts = ['最低人數'];
    if (features.value.senior_required) parts.push('高階員工');
    if (features.value.stations) parts.push('站別需求');
    return `定義每個時段的${parts.join('、')}。設定後排班看板會即時檢查。`;
});
</script>

<template>
    <div class="space-y-10">
        <section>
            <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Shift Rules</p>
            <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">各班人力設定</h2>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">{{ headerDesc }}</p>
        </section>

        <SettingsShiftTemplates />
    </div>
</template>
