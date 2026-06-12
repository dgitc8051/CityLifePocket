<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const loading = ref(true);
const saving = ref(false);
const error = ref(null);
const success = ref(false);

const form = reactive({
    name: '',
    timezone: 'Asia/Taipei',
    line_channel_id: '',
    clock_in_lat: null,
    clock_in_lng: null,
    clock_in_radius_m: null,
});

const owner = ref(null);

async function fetchShop() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get('/api/shop');
        form.name = data.data.name;
        form.timezone = data.data.timezone;
        form.line_channel_id = data.data.line_channel_id ?? '';
        form.clock_in_lat = data.data.clock_in_lat;
        form.clock_in_lng = data.data.clock_in_lng;
        form.clock_in_radius_m = data.data.clock_in_radius_m;
        owner.value = data.data.owner;
    } catch (e) {
        error.value = '讀取失敗';
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    error.value = null;
    success.value = false;
    try {
        await axios.put('/api/shop', form);
        success.value = true;
        setTimeout(() => (success.value = false), 2000);
    } catch (e) {
        error.value = e?.response?.data?.errors
            ? Object.values(e.response.data.errors).flat().join('\n')
            : '儲存失敗';
    } finally {
        saving.value = false;
    }
}

const locating = ref(false);
function useCurrentLocation() {
    if (!navigator.geolocation) {
        alert('此瀏覽器不支援定位');
        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (p) => {
            form.clock_in_lat = Number(p.coords.latitude.toFixed(7));
            form.clock_in_lng = Number(p.coords.longitude.toFixed(7));
            if (!form.clock_in_radius_m) form.clock_in_radius_m = 50;
            locating.value = false;
        },
        (err) => {
            locating.value = false;
            const reason = {
                1: '已拒絕位置權限，請至瀏覽器設定允許',
                2: '無法取得位置（GPS 訊號弱？）',
                3: '取得位置逾時',
            }[err?.code] ?? '無法取得目前位置';
            alert(reason);
        },
        { enableHighAccuracy: true, timeout: 8000 },
    );
}

onMounted(fetchShop);
</script>

<template>
    <div v-if="loading" class="h-64 rounded-[6px] border border-ink-200/60 bg-white" />
    <div v-else class="rounded-[6px] border border-ink-200/60 bg-white p-6">
        <div class="space-y-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="col-span-2">
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">店家名稱</label>
                    <input
                        v-model="form.name"
                        type="text"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">時區</label>
                    <select
                        v-model="form.timezone"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    >
                        <option value="Asia/Taipei">Asia/Taipei (UTC+8)</option>
                        <option value="Asia/Tokyo">Asia/Tokyo (UTC+9)</option>
                        <option value="Asia/Hong_Kong">Asia/Hong_Kong (UTC+8)</option>
                        <option value="Asia/Singapore">Asia/Singapore (UTC+8)</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[12px] font-medium text-ink-700">
                        LINE 官方帳號 ID
                        <span class="ml-1 font-normal text-ink-400">（選填）</span>
                    </label>
                    <input
                        v-model="form.line_channel_id"
                        type="text"
                        placeholder="@xxxxx"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-100"
                    />
                </div>
            </div>

            <!-- 打卡定位 -->
            <div class="border-t border-ink-200/60 pt-5">
                <p class="text-[12px] font-medium text-ink-900">打卡定位驗證</p>
                <p class="mt-1 text-[11px] text-ink-500">設定後員工打卡需在店面範圍內。半徑空白＝不檢查位置。</p>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-[11px] text-ink-600">緯度</label>
                        <input v-model.number="form.clock_in_lat" type="number" step="0.0000001" placeholder="25.0330"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-ink-600">經度</label>
                        <input v-model.number="form.clock_in_lng" type="number" step="0.0000001" placeholder="121.5654"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-ink-600">允許半徑（公尺）</label>
                        <input v-model.number="form.clock_in_radius_m" type="number" min="0" max="10000" placeholder="例：50"
                            class="h-9 w-full rounded-[5px] border border-ink-200/60 px-3 text-[13px] tabular-nums outline-none focus:border-accent-500" />
                    </div>
                </div>
                <button type="button" @click="useCurrentLocation" :disabled="locating"
                    class="mt-3 text-[11px] tracking-[0.02em] text-accent-600 underline transition-colors hover:text-accent-700 disabled:cursor-wait disabled:opacity-60">
                    {{ locating ? '取得位置中…' : '↪ 使用目前位置帶入（半徑空白會自動填 50m）' }}
                </button>
            </div>

            <div v-if="owner" class="rounded-[5px] bg-ink-50/60 px-4 py-3 text-[12px]">
                <p class="text-ink-500">店家擁有者</p>
                <p class="mt-0.5 font-medium text-ink-900">{{ owner.name }}</p>
            </div>

            <div v-if="error" class="rounded-[5px] bg-danger-50 px-3 py-2 text-[12px] text-danger-700">
                {{ error }}
            </div>
            <div v-if="success" class="rounded-[5px] bg-success-50 px-3 py-2 text-[12px] text-success-700">
                ✓ 已儲存
            </div>

            <div class="flex justify-end">
                <button
                    type="button"
                    @click="save"
                    :disabled="saving"
                    class="rounded-[5px] bg-ink-900 px-4 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-ink-800 disabled:opacity-50"
                >
                    {{ saving ? '儲存中' : '儲存' }}
                </button>
            </div>
        </div>
    </div>
</template>
