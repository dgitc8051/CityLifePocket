<script setup>
/**
 * 模組級守門員。Feature 關閉時整段 slot 不會 render(不是 hidden,是真的不存在於 DOM)。
 *
 * 用法:
 *   <FeatureGate feature="stations">
 *     <SettingsStations />
 *   </FeatureGate>
 *
 *   多個 feature 同時要開才顯示:
 *   <FeatureGate :feature="['stations', 'skill_score']">
 *     <Foo />
 *   </FeatureGate>
 *
 *   有預備內容(rare):
 *   <FeatureGate feature="payroll">
 *     <PayrollPanel />
 *     <template #fallback><p>本店未啟用薪資功能</p></template>
 *   </FeatureGate>
 *
 * 註:預設 fallback slot 是 empty,UI 就是「整段消失」— 這正是模組化的訴求。
 */
import { computed } from 'vue';
import { useFeatures } from '../composables/useFeature';

const props = defineProps({
    feature: { type: [String, Array], required: true },
});

const features = useFeatures();

const enabled = computed(() => {
    const keys = Array.isArray(props.feature) ? props.feature : [props.feature];
    return keys.every((k) => features.value?.[k] === true);
});
</script>

<template>
    <slot v-if="enabled" />
    <slot v-else name="fallback" />
</template>
