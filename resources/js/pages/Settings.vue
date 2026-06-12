<script setup>
import { computed, ref, watch } from 'vue';
import SettingsShop from '../components/settings/SettingsShop.vue';
import SettingsBusinessHours from '../components/settings/SettingsBusinessHours.vue';
import SettingsHolidays from '../components/settings/SettingsHolidays.vue';
import SettingsRules from '../components/settings/SettingsRules.vue';
import SettingsStations from '../components/settings/SettingsStations.vue';
import SettingsSalaryMultipliers from '../components/settings/SettingsSalaryMultipliers.vue';
import SettingsFeatures from '../components/settings/SettingsFeatures.vue';
import SettingsLine from '../components/settings/SettingsLine.vue';
import SettingsPermissionTemplates from '../components/settings/SettingsPermissionTemplates.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const features = computed(() => auth.user?.current_shop?.features ?? {});
const perms = computed(() => auth.user?.permissions ?? {});

const allTabs = [
    { id: 'shop', label: '基本資料' },
    { id: 'hours', label: '營業時間' },
    { id: 'holidays', label: '公休日' },
    { id: 'features', label: '功能開關' },
    { id: 'stations', label: '站別管理', requires: 'stations' },
    { id: 'salary', label: '薪資倍率', requires: 'payroll' },
    { id: 'rules', label: '規則' },
    { id: 'line', label: 'LINE 整合' },
    { id: 'permission_templates', label: '權限模板', permPerm: 'permission_templates' },
];

const tabs = computed(() => allTabs.filter((t) => {
    if (t.requires && features.value[t.requires] !== true) return false;
    if (t.permPerm && !['r', 'rw'].includes(perms.value[t.permPerm] ?? 'none')) return false;
    return true;
}));
const active = ref('shop');

// 當某 tab 因功能關閉而消失時，跳回基本資料
watch(features, () => {
    if (!tabs.value.find((t) => t.id === active.value)) {
        active.value = 'shop';
    }
}, { deep: true });
</script>

<template>
    <div class="space-y-10">
        <section>
            <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Shop</p>
            <h2 class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900">店家資料</h2>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                店名、營業時間、公休日。時段樣板已獨立為「各班人力設定」。
            </p>
        </section>

        <nav class="flex items-center gap-1 border-b border-ink-200/60">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                @click="active = tab.id"
                type="button"
                class="relative -mb-px px-3 py-2.5 text-[12px] tracking-[0.02em] transition-colors"
                :class="
                    active === tab.id
                        ? 'border-b-2 border-ink-900 font-medium text-ink-900'
                        : 'border-b-2 border-transparent text-ink-500 hover:text-ink-900'
                "
            >
                {{ tab.label }}
            </button>
        </nav>

        <SettingsShop v-if="active === 'shop'" />
        <SettingsBusinessHours v-else-if="active === 'hours'" />
        <SettingsHolidays v-else-if="active === 'holidays'" />
        <SettingsFeatures v-else-if="active === 'features'" />
        <SettingsStations v-else-if="active === 'stations'" />
        <SettingsSalaryMultipliers v-else-if="active === 'salary'" />
        <SettingsRules v-else-if="active === 'rules'" />
        <SettingsLine v-else-if="active === 'line'" />
        <SettingsPermissionTemplates v-else-if="active === 'permission_templates'" />
    </div>
</template>
