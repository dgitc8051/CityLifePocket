<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { useLiff } from '../../composables/useLiff';

const { ready, error: liffError, employee, shop } = useLiff();

const state = ref(null);
const stateLoading = ref(false);
const punching = ref(false);
const punchResult = ref(null);
const error = ref(null);

async function loadState() {
    stateLoading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get('/api/liff/attendance/state');
        state.value = data;
    } catch (e) {
        error.value = e?.response?.data?.error || '無法取得狀態';
    } finally {
        stateLoading.value = false;
    }
}

function getPosition() {
    return new Promise((resolve) => {
        if (!navigator.geolocation) { resolve(null); return; }
        navigator.geolocation.getCurrentPosition(
            (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 },
        );
    });
}

async function punch() {
    if (punching.value) return;
    punching.value = true;
    error.value = null;
    try {
        const pos = await getPosition();
        const { data } = await axios.post('/api/liff/attendance/punch', pos || {});
        punchResult.value = data;
        await loadState();
    } catch (e) {
        error.value = e?.response?.data?.error || '打卡失敗';
    } finally {
        punching.value = false;
    }
}

onMounted(() => {
    // useLiff 完成後再 load state
    const stop = setInterval(() => {
        if (ready.value && !state.value) {
            loadState();
        }
        if (state.value) clearInterval(stop);
    }, 200);
});
</script>

<template>
    <div class="clockin-wrap">
        <div v-if="liffError" class="error">{{ liffError }}</div>
        <template v-else-if="ready">
            <header>
                <div class="shop">{{ shop?.name }}</div>
                <div class="emp">{{ employee?.name }}</div>
            </header>

            <div v-if="state" class="card">
                <div v-if="state.today_entry" class="shift">
                    今日:{{ state.today_entry.shift_name }}
                    ({{ state.today_entry.start_time?.slice(0,5) }}–{{ state.today_entry.end_time?.slice(0,5) }})
                </div>
                <div v-else class="shift dim">今天沒有排定班次(允許自由打卡)</div>

                <div v-if="state.active_record" class="active">
                    🟢 已上班於 {{ new Date(state.active_record.clocked_in_at).toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' }) }}
                </div>

                <button
                    class="punch-btn"
                    :class="state.action"
                    :disabled="punching"
                    @click="punch"
                >
                    {{ punching ? '處理中…' : state.action === 'clock_out' ? '🕔 下班打卡' : '🕘 上班打卡' }}
                </button>

                <div v-if="punchResult" class="result">
                    ✅ {{ punchResult.action === 'clock_in' ? '上班成功' : '下班成功' }}
                    <span v-if="punchResult.late_minutes > 0"> · 遲到 {{ punchResult.late_minutes }} 分</span>
                </div>

                <div v-if="error" class="error">{{ error }}</div>
            </div>
            <div v-else-if="stateLoading">載入中…</div>
        </template>
        <div v-else class="loading">載入中…</div>
    </div>
</template>

<style scoped>
.clockin-wrap { padding: 16px; font-family: 'Helvetica Neue', sans-serif; }
header { padding: 8px 0 16px; border-bottom: 1px solid #eee; }
header .shop { font-size: 14px; color: #666; }
header .emp { font-size: 22px; font-weight: 600; }
.card { padding: 20px 0; text-align: center; }
.shift { font-size: 16px; margin-bottom: 12px; }
.shift.dim { color: #999; }
.active {
    display: inline-block; padding: 8px 16px; background: #e8f5e9;
    border-radius: 16px; margin-bottom: 24px; color: #2e7d32;
}
.punch-btn {
    width: 100%; height: 96px; font-size: 24px; border: 0;
    border-radius: 12px; color: white; font-weight: 600;
}
.punch-btn.clock_in { background: #06c755; }
.punch-btn.clock_out { background: #d97706; }
.punch-btn:disabled { opacity: 0.6; }
.result { margin-top: 16px; color: #2e7d32; font-size: 18px; }
.error { color: #d93025; margin-top: 12px; }
.loading { text-align: center; padding: 48px; color: #888; }
</style>
