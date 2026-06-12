<script setup>
import { onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useLiff } from '../../composables/useLiff';

const { ready, error: liffError, employee, shop } = useLiff();

const feed = ref([]);
const loading = ref(false);
const error = ref(null);
const submitting = ref(null);   // 出價中的 request id

const dowLabel = ['日', '一', '二', '三', '四', '五', '六'];

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get('/api/liff/coverage/feed');
        feed.value = data.data || [];
    } catch (e) {
        error.value = e?.response?.data?.error || '無法取得代班 feed';
    } finally {
        loading.value = false;
    }
}

async function offer(req) {
    if (submitting.value) return;
    submitting.value = req.id;
    try {
        await axios.post(`/api/liff/coverage/${req.id}/offer`, { message: '我可以接' });
        await load();
    } catch (e) {
        error.value = e?.response?.data?.error || '出價失敗';
    } finally {
        submitting.value = null;
    }
}

async function withdraw(offerId) {
    if (submitting.value) return;
    submitting.value = `offer-${offerId}`;
    try {
        await axios.post(`/api/liff/coverage/offer/${offerId}/withdraw`);
        await load();
    } catch (e) {
        error.value = e?.response?.data?.error || '撤回失敗';
    } finally {
        submitting.value = null;
    }
}

function formatDate(s) {
    if (!s) return '';
    const d = new Date(s);
    return `${d.getMonth() + 1}/${d.getDate()} (${dowLabel[d.getDay()]})`;
}

function formatExpires(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    const mins = Math.floor((d - now) / 60000);
    if (mins < 0) return '已過期';
    if (mins < 60) return `剩 ${mins} 分`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `剩 ${hours} 小時`;
    return `剩 ${Math.floor(hours / 24)} 天`;
}

watch(ready, (v) => { if (v) load(); }, { immediate: true });
</script>

<template>
    <div class="cov-wrap">
        <div v-if="liffError" class="error">{{ liffError }}</div>
        <template v-else-if="ready">
            <header>
                <div class="shop">{{ shop?.name }} · 換班市場</div>
                <div class="emp">{{ employee?.name }}</div>
            </header>

            <div v-if="loading">載入中…</div>
            <div v-else-if="feed.length === 0" class="empty">
                目前沒有開放的代班請求 🎉
            </div>
            <div v-else class="cards">
                <div v-for="r in feed" :key="r.id" class="card" :class="{ conflict: r.has_conflict }">
                    <div class="card-head">
                        <span class="requester">{{ r.requester.name }}</span>
                        <span class="expires">{{ formatExpires(r.expires_at) }}</span>
                    </div>
                    <div class="card-shift">
                        {{ formatDate(r.date) }} · {{ r.shift_name }}
                        <span class="time">{{ r.start_time }}–{{ r.end_time }}</span>
                    </div>
                    <div v-if="r.reason" class="card-reason">原因:{{ r.reason }}</div>
                    <div v-if="r.has_conflict" class="conflict-warn">
                        ⚠ 你那天已有班次,無法代班
                    </div>

                    <div class="card-action">
                        <template v-if="r.my_offer">
                            <span class="offer-status" :class="r.my_offer.status">
                                {{ r.my_offer.status === 'pending' ? '✓ 已表態' :
                                   r.my_offer.status === 'accepted' ? '🎉 已被接受' :
                                   r.my_offer.status === 'rejected' ? '已拒絕' : '已撤回' }}
                            </span>
                            <button
                                v-if="r.my_offer.status === 'pending'"
                                class="btn-withdraw"
                                :disabled="submitting === `offer-${r.my_offer.id}`"
                                @click="withdraw(r.my_offer.id)">
                                撤回
                            </button>
                        </template>
                        <button
                            v-else-if="!r.has_conflict"
                            class="btn-offer"
                            :disabled="submitting === r.id"
                            @click="offer(r)">
                            {{ submitting === r.id ? '送出中…' : '🙋 我可以接' }}
                        </button>
                    </div>
                </div>
            </div>
            <div v-if="error" class="error">{{ error }}</div>
        </template>
        <div v-else class="loading">載入中…</div>
    </div>
</template>

<style scoped>
.cov-wrap { padding: 16px; font-family: 'Helvetica Neue', sans-serif; }
header { padding: 8px 0 16px; border-bottom: 1px solid #eee; margin-bottom: 16px; }
header .shop { font-size: 14px; color: #666; }
header .emp { font-size: 18px; font-weight: 600; }
.empty { padding: 48px 16px; text-align: center; color: #888; }
.cards { display: flex; flex-direction: column; gap: 12px; }
.card {
    padding: 14px; border: 1px solid #e5e7eb; border-radius: 10px;
    background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}
.card.conflict { opacity: 0.6; }
.card-head { display: flex; justify-content: space-between; align-items: baseline; }
.card-head .requester { font-weight: 600; }
.card-head .expires { font-size: 13px; color: #d97706; }
.card-shift { margin: 8px 0; font-size: 16px; }
.card-shift .time { color: #555; margin-left: 6px; }
.card-reason { font-size: 14px; color: #555; margin-bottom: 8px; }
.conflict-warn { color: #b91c1c; font-size: 13px; margin-bottom: 8px; }
.card-action { margin-top: 8px; display: flex; gap: 8px; align-items: center; }
.btn-offer {
    flex: 1; height: 44px; border: 0; border-radius: 8px;
    background: #06c755; color: white; font-weight: 600; font-size: 15px;
}
.btn-offer:disabled { opacity: 0.5; }
.btn-withdraw {
    height: 36px; padding: 0 14px; border: 1px solid #ddd; border-radius: 8px;
    background: white; color: #555;
}
.offer-status { padding: 6px 10px; border-radius: 6px; font-size: 13px; }
.offer-status.pending { background: #fef3c7; color: #92400e; }
.offer-status.accepted { background: #dcfce7; color: #166534; }
.offer-status.rejected { background: #fee2e2; color: #991b1b; }
.offer-status.withdrawn { background: #e5e7eb; color: #4b5563; }
.error { color: #d93025; margin-top: 12px; }
.loading { text-align: center; padding: 48px; color: #888; }
</style>
