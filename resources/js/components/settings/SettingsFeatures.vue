<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const loading = ref(true);
const saving = ref(false);
const flash = ref(null);

const features = reactive({
    stations: true,
    senior_required: true,
    skill_score: true,
    payroll: true,
    ot_approval: true,
});

const items = [
    {
        key: 'stations',
        label: '站別系統',
        sub: '收銀 / 廚房 / 外場分工',
        desc: '把員工依站別分類（如收銀、製作、外場），每個班次可指定「至少 1 人會做廚房」等需求。3-5 人小店通常每個人都會做所有事，可關閉。關閉後：站別管理 tab 隱藏、員工資料與時段設定也不再顯示站別欄位、一鍵排班不考慮站別。',
    },
    {
        key: 'senior_required',
        label: '最少高階員工門檻',
        sub: '保證每個班至少有 N 個熟手',
        desc: '為每個班次設定「最少要有 N 位高階員工（熟手 / 店長）」。3 人店通常只有 1 個店長，這條規則永遠成立，沒實質意義。中型店午尖峰要保證有熟手坐鎮才用得到。關閉後：時段設定隱藏該欄、一鍵排班不檢查此條件。',
    },
    {
        key: 'skill_score',
        label: '能力分數系統',
        sub: '1-10 分量化每位員工',
        desc: '給每位員工 1-10 的能力分數，並為班次設定「建議總分」。對「靠直覺排班」的小店多餘。關閉後：員工資料用 3 級顯示（新手 / 熟手 / 店長）、時段設定隱藏「建議總分」、一鍵排班改用簡化排序。',
    },
    {
        key: 'payroll',
        label: '薪資與倍率',
        sub: '時薪 / 月薪 / 加班倍率',
        desc: '記錄時薪、月薪、平日 1.34 / 假日 2 倍率，產出員工時數表。家庭店主導向、不分薪的店可關閉。關閉後：員工資料隱藏時薪 / 月薪、薪資倍率 tab 隱藏、員工時數表頁面隱藏倍率欄位、一鍵排班「省錢」策略改用單純時薪。',
    },
    {
        key: 'ot_approval',
        label: '加班核可流程',
        sub: '系統偵測加班，店家核可後才計薪',
        desc: '員工下班時間超過排班結束時間時，系統會偵測為加班，但需店家手動核可才計入薪資。防止「員工晚走但沒做事」的灰色地帶。關閉後：偵測到的加班直接視為已核可，自動計薪。',
    },
];

async function fetchFeatures() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/shop');
        Object.assign(features, data.data.features ?? {});
    } catch (e) {
        flashMsg('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

function flashMsg(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2400);
}

async function toggle(key) {
    const next = !features[key];
    const prev = features[key];
    features[key] = next; // optimistic
    saving.value = true;
    try {
        await axios.put('/api/shop/features', { features: { [key]: next } });
        flashMsg(next ? `已開啟「${items.find((i) => i.key === key)?.label}」` : `已關閉「${items.find((i) => i.key === key)?.label}」`);
        // 重新拉 me 讓全站 features 同步
        await auth.fetchMe();
    } catch (e) {
        features[key] = prev;
        flashMsg('儲存失敗', false);
    } finally {
        saving.value = false;
    }
}

onMounted(fetchFeatures);
</script>

<template>
    <div v-if="loading" class="h-64 border-y border-ink-200/60" />
    <div v-else class="space-y-8">
        <section>
            <h3 class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">Features</h3>
            <p class="mt-1 font-serif text-[16px] font-medium text-ink-900">功能開關</p>
            <p class="mt-1 text-[12px] leading-relaxed tracking-[0.02em] text-ink-500">
                依店家規模 / 需求啟用對應功能。關閉後對應介面會隱藏，一鍵排班演算法也會跳過相關規則。<br />
                有疑慮先全開試一週，發現某個功能用不上再關掉。
            </p>
        </section>

        <div v-if="flash"
            class="rounded-[5px] px-3.5 py-2.5 text-[12px] tracking-[0.02em]"
            :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <ul class="divide-y divide-ink-200/60 border-y border-ink-200/60">
            <li v-for="item in items" :key="item.key" class="py-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-baseline gap-2">
                            <p class="text-[14px] font-semibold text-ink-900">{{ item.label }}</p>
                            <p class="text-[11px] text-ink-500">· {{ item.sub }}</p>
                            <span v-if="features[item.key]"
                                class="rounded-[3px] bg-success-50 px-1.5 py-0.5 text-[10px] font-medium text-success-700">啟用中</span>
                            <span v-else
                                class="rounded-[3px] bg-ink-100 px-1.5 py-0.5 text-[10px] font-medium text-ink-500">已關閉</span>
                        </div>
                        <p class="mt-1.5 max-w-2xl text-[12px] leading-relaxed text-ink-500">{{ item.desc }}</p>
                    </div>
                    <button type="button" @click="toggle(item.key)" :disabled="saving"
                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors disabled:opacity-50"
                        :class="features[item.key] ? 'bg-ink-900' : 'bg-ink-300'">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                            :class="features[item.key] ? 'translate-x-6' : 'translate-x-1'" />
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
