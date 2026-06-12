/**
 * Feature 模組存取器。
 *
 * 使用方式:
 *   import { useFeature, useFeatures } from '@/composables/useFeature';
 *
 *   const stations = useFeature('stations');  // computed<boolean>
 *   <div v-if="stations.value">...</div>
 *
 *   const features = useFeatures();           // computed<Record<string, boolean>>
 *   <div v-if="features.payroll">...</div>
 *
 * 也可以用 <FeatureGate feature="stations">...</FeatureGate> 把整段 UI 包起來。
 *
 * 真理來源:auth.user.current_shop.features(來自 /api/auth/me)
 * 預設 fallback:**關閉(false)**。避免 stale state 下露出不該露的 UI。
 */
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';

export function useFeatures() {
    const auth = useAuthStore();
    return computed(() => auth.user?.current_shop?.features ?? {});
}

export function useFeature(key) {
    const auth = useAuthStore();
    // 嚴格:undefined 視為 false。後端有 default,只要 API 正常一定會有值。
    return computed(() => auth.user?.current_shop?.features?.[key] === true);
}
