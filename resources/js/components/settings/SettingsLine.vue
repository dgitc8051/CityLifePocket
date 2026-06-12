<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';

const loading = ref(true);
const saving = ref(false);
const flash = ref(null);
const status = ref({});

// 「目前已設」狀態。secret 後端不會回傳原文，只回「set/unset」+ public id。
const form = reactive({
    line_channel_id: '',
    line_channel_secret: '',
    line_messaging_access_token: '',
    line_bot_user_id: '',
    line_login_channel_id: '',
    line_login_channel_secret: '',
    line_liff_id: '',
});

function flashMsg(msg, ok = true) {
    flash.value = { msg, ok };
    setTimeout(() => (flash.value = null), 2400);
}

async function fetchStatus() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/shop');
        status.value = data.data.line ?? {};
        // 把 public id 填回表單，secret 留空（不顯示明文）
        form.line_channel_id = status.value.messaging_channel_id ?? '';
        form.line_bot_user_id = status.value.bot_user_id ?? '';
        form.line_login_channel_id = status.value.login_channel_id ?? '';
        form.line_liff_id = status.value.liff_id ?? '';
        form.line_channel_secret = '';
        form.line_messaging_access_token = '';
        form.line_login_channel_secret = '';
    } catch (e) {
        flashMsg('讀取失敗', false);
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        // 空欄位送 null，後端會自動「保留原值」
        const payload = {};
        Object.keys(form).forEach((k) => {
            const v = form[k];
            payload[k] = v === '' ? null : v;
        });
        await axios.put('/api/shop/line', payload);
        flashMsg('已儲存');
        await fetchStatus();
    } catch (e) {
        const errs = e?.response?.data?.errors;
        flashMsg(errs ? Object.values(errs).flat().join('\n') : (e?.response?.data?.error ?? '儲存失敗'), false);
    } finally {
        saving.value = false;
    }
}

onMounted(fetchStatus);
</script>

<template>
    <div v-if="loading" class="h-64 border-y border-ink-200/60" />
    <div v-else class="space-y-10">
        <!-- Header -->
        <section>
            <h3 class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">LINE Integration</h3>
            <p class="mt-1 font-serif text-[16px] font-medium text-ink-900">LINE 整合設定</p>
            <p class="mt-1 text-[12px] tracking-[0.02em] text-ink-500">
                這家店的 LINE 官方帳號 / Login / LIFF 設定。每家分店可設不同的 LINE channels。
            </p>
        </section>

        <div v-if="flash"
            class="rounded-[5px] px-3.5 py-2.5 text-[12px] tracking-[0.02em]"
            :class="flash.ok ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">
            {{ flash.msg }}
        </div>

        <!-- Status summary -->
        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-[5px] border border-ink-200/60 bg-white p-3">
                <p class="text-[10px] tracking-[0.05em] text-ink-400">Messaging API</p>
                <p class="mt-1 text-[12px]" :class="status.has_messaging ? 'text-success-700' : 'text-ink-400'">
                    {{ status.has_messaging ? '✓ 已設定' : '未設定' }}
                </p>
            </div>
            <div class="rounded-[5px] border border-ink-200/60 bg-white p-3">
                <p class="text-[10px] tracking-[0.05em] text-ink-400">LINE Login</p>
                <p class="mt-1 text-[12px]" :class="status.has_login ? 'text-success-700' : 'text-ink-400'">
                    {{ status.has_login ? '✓ 已設定' : '未設定' }}
                </p>
            </div>
            <div class="rounded-[5px] border border-ink-200/60 bg-white p-3">
                <p class="text-[10px] tracking-[0.05em] text-ink-400">LIFF</p>
                <p class="mt-1 text-[12px]" :class="status.has_liff ? 'text-success-700' : 'text-ink-400'">
                    {{ status.has_liff ? '✓ 已設定' : '未設定' }}
                </p>
            </div>
        </div>

        <!-- Messaging API -->
        <section>
            <h4 class="text-[13px] font-medium text-ink-900">Messaging API (Bot 推播)</h4>
            <p class="mt-0.5 text-[11px] text-ink-500">用來發送班表通知、請假審核結果給員工</p>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-[11px] font-medium tracking-[0.05em] text-ink-700">Channel ID</label>
                    <input v-model="form.line_channel_id" type="text" placeholder="例：2010314210"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] tabular-nums outline-none focus:border-ink-400" />
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium tracking-[0.05em] text-ink-700">
                        Channel Secret
                        <span v-if="status.messaging_secret_set" class="ml-1 text-[10px] text-success-700">（已設·留空保留）</span>
                    </label>
                    <input v-model="form.line_channel_secret" type="password" :placeholder="status.messaging_secret_set ? '••••••（已加密儲存）' : '貼上 secret'"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-ink-400" />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[11px] font-medium tracking-[0.05em] text-ink-700">
                        Channel Access Token (long-lived)
                        <span v-if="status.access_token_set" class="ml-1 text-[10px] text-success-700">（已設·留空保留）</span>
                    </label>
                    <input v-model="form.line_messaging_access_token" type="password" :placeholder="status.access_token_set ? '••••••（已加密儲存）' : '貼上 access token'"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-ink-400" />
                    <p class="mt-1 text-[10.5px] text-ink-400">在 LINE Developers Console → Messaging API → Channel access token 點「Issue」取得</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[11px] font-medium tracking-[0.05em] text-ink-700">Bot User ID</label>
                    <input v-model="form.line_bot_user_id" type="text" placeholder="例：U96fdc9fad9db4ca701eb9e86209dbd1a"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] font-mono tabular-nums outline-none focus:border-ink-400" />
                    <p class="mt-1 text-[10.5px] text-ink-400">把官方帳號加為好友後，可從 webhook 收到的 destination 欄位取得</p>
                </div>
            </div>
        </section>

        <div class="hairline"></div>

        <!-- LINE Login -->
        <section>
            <h4 class="text-[13px] font-medium text-ink-900">LINE Login</h4>
            <p class="mt-0.5 text-[11px] text-ink-500">員工用 LINE 帳號登入 ShiftPal 的入口（LIFF / Web Login）</p>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-[11px] font-medium tracking-[0.05em] text-ink-700">Login Channel ID</label>
                    <input v-model="form.line_login_channel_id" type="text" placeholder="例：2010314199"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] tabular-nums outline-none focus:border-ink-400" />
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium tracking-[0.05em] text-ink-700">
                        Login Channel Secret
                        <span v-if="status.login_secret_set" class="ml-1 text-[10px] text-success-700">（已設·留空保留）</span>
                    </label>
                    <input v-model="form.line_login_channel_secret" type="password" :placeholder="status.login_secret_set ? '••••••（已加密儲存）' : '貼上 secret'"
                        class="h-9 w-full rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] outline-none focus:border-ink-400" />
                </div>
            </div>
        </section>

        <div class="hairline"></div>

        <!-- LIFF -->
        <section>
            <h4 class="text-[13px] font-medium text-ink-900">LIFF (LINE Front-end Framework)</h4>
            <p class="mt-0.5 text-[11px] text-ink-500">員工在 LINE 內開啟 ShiftPal 的迷你應用 ID</p>
            <div class="mt-4">
                <label class="mb-1 block text-[11px] font-medium tracking-[0.05em] text-ink-700">LIFF ID</label>
                <input v-model="form.line_liff_id" type="text" placeholder="例：2010314199-Xa3bC4d5"
                    class="h-9 w-full max-w-md rounded-[5px] border border-ink-200/60 bg-white px-3 text-[13px] tabular-nums outline-none focus:border-ink-400" />
                <p class="mt-1 text-[10.5px] text-ink-400">在 Login channel 下開 LIFF App 後取得</p>
            </div>
        </section>

        <div class="flex justify-end pt-2">
            <button type="button" @click="save" :disabled="saving"
                class="rounded-[5px] bg-sumi-600 px-4 py-1.5 text-[11px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500 disabled:opacity-50">
                {{ saving ? '儲存中' : '儲存 LINE 設定' }}
            </button>
        </div>

        <!-- Webhook URL 提示 -->
        <section v-if="status.has_messaging" class="rounded-[5px] border border-ink-200/60 bg-ink-50/40 p-4">
            <p class="text-[11px] font-medium tracking-[0.05em] text-ink-700">未來給 LINE Developers 的 Webhook URL</p>
            <p class="num mt-2 text-[12px] tracking-[0.02em] text-ink-700">
                https://shiftpal.tsaimushi.com/webhooks/line/{{ status.messaging_channel_id }}
            </p>
            <p class="mt-2 text-[10.5px] text-ink-400">
                目前 webhook handler 還沒實作。先把 credentials 留著，未來開發 LINE bot 邏輯時直接讀這裡。
            </p>
        </section>
    </div>
</template>
