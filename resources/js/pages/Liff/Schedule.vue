<script setup>
import { onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useLiff } from '../../composables/useLiff';

const { ready, error: liffError, employee, shop } = useLiff();

const data = ref(null);
const loading = ref(false);
const error = ref(null);

const dowLabel = ['日', '一', '二', '三', '四', '五', '六'];

async function load() {
    loading.value = true;
    try {
        const res = await axios.get('/api/liff/schedule', { params: { weeks: 2 } });
        data.value = res.data;
    } catch (e) {
        error.value = e?.response?.data?.error || '無法取得班表';
    } finally {
        loading.value = false;
    }
}

watch(ready, (v) => { if (v && !data.value) load(); }, { immediate: true });
</script>

<template>
    <div class="sched-wrap">
        <div v-if="liffError" class="error">{{ liffError }}</div>
        <template v-else-if="ready">
            <header>
                <div class="shop">{{ shop?.name }}</div>
                <div class="emp">{{ employee?.name }} 的班表</div>
            </header>

            <div v-if="loading">載入中…</div>
            <div v-else-if="data && data.days.length === 0" class="empty">
                接下來兩週都沒有班次
            </div>
            <div v-else-if="data" class="days">
                <div v-for="d in data.days" :key="d.date" class="day">
                    <div class="date">
                        <span class="dow">星期{{ dowLabel[d.dow] }}</span>
                        <span class="ymd">{{ d.date }}</span>
                    </div>
                    <div class="entries">
                        <div v-for="e in d.entries" :key="e.id" class="entry">
                            <span class="name">{{ e.shift_name }}</span>
                            <span class="time">{{ e.start_time }} – {{ e.end_time }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div v-if="error" class="error">{{ error }}</div>
        </template>
        <div v-else class="loading">載入中…</div>
    </div>
</template>

<style scoped>
.sched-wrap { padding: 16px; font-family: 'Helvetica Neue', sans-serif; }
header { padding: 8px 0 16px; border-bottom: 1px solid #eee; margin-bottom: 16px; }
header .shop { font-size: 14px; color: #666; }
header .emp { font-size: 20px; font-weight: 600; }
.days { display: flex; flex-direction: column; gap: 12px; }
.day { padding: 12px; border: 1px solid #eee; border-radius: 8px; }
.date { display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px; }
.date .dow { font-weight: 600; }
.date .ymd { color: #888; font-size: 14px; }
.entries { display: flex; flex-direction: column; gap: 6px; }
.entry {
    display: flex; justify-content: space-between;
    padding: 8px 12px; background: #f5f7fa; border-radius: 6px;
}
.entry .name { font-weight: 500; }
.entry .time { color: #555; }
.empty { padding: 48px 16px; text-align: center; color: #888; }
.error { color: #d93025; margin-top: 12px; }
.loading { text-align: center; padding: 48px; color: #888; }
</style>
