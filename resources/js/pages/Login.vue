<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const form = reactive({ email: 'demo@shiftpal.local', password: 'demo1234' });
const error = ref(null);
const submitting = ref(false);

const lineStatus = ref({ enabled: false, redirect_url: null });

// 從 query string 顯示 OAuth 錯誤
const lineError = ref(null);
const lineErrorMessages = {
    line_no_shop: '找不到對應店家',
    line_not_configured: '此店家尚未設定 LINE 登入',
    line_canceled: '已取消 LINE 登入',
    line_state_mismatch: 'OAuth state 不符，請重試',
    line_token_failed: '無法取得 LINE token',
    line_profile_failed: '無法取得 LINE 個人資料',
    line_no_user_id: 'LINE 沒回傳使用者 ID',
    line_invalid_response: 'LINE 回應格式不正確',
};

onMounted(async () => {
    try {
        const { data } = await axios.get('/api/auth/line-status');
        lineStatus.value = data;
    } catch (e) { /* skip */ }

    const err = route.query.error;
    if (err && lineErrorMessages[err]) {
        lineError.value = lineErrorMessages[err];
    }
});

async function submit() {
    submitting.value = true;
    error.value = null;
    try {
        await auth.login(form.email, form.password);
        await router.push({ name: 'dashboard' });
    } catch (e) {
        error.value = e?.response?.data?.errors?.email?.[0]
            ?? e?.response?.data?.message
            ?? '登入失敗';
    } finally {
        submitting.value = false;
    }
}

function loginWithLine() {
    if (!lineStatus.value.redirect_url) return;
    // 必須整個瀏覽器導向（不能用 SPA router），OAuth 要走完整 redirect 流程
    window.location.href = lineStatus.value.redirect_url;
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-ink-50 px-4">
        <div class="w-full max-w-[360px]">
            <div class="mb-12 text-center">
                <div class="mb-6 inline-flex items-baseline gap-2">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-sumi-600" />
                    <span class="text-[14px] font-medium tracking-[0.02em] text-ink-900">
                        ShiftPal
                    </span>
                </div>
                <h1 class="font-serif text-[28px] font-medium tracking-tight text-ink-900">
                    歡迎回來
                </h1>
                <p class="mt-2 text-[12px] tracking-[0.05em] text-ink-500">
                    請輸入帳號以繼續
                </p>
            </div>

            <form
                @submit.prevent="submit"
                class="space-y-5"
            >
                <div>
                    <label class="mb-2 block text-[11px] font-medium tracking-[0.05em] text-ink-600">
                        Email
                    </label>
                    <input
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="username"
                        class="h-11 w-full rounded-[6px] border border-ink-200 bg-white px-3.5 text-[14px] text-ink-900 placeholder-ink-400 outline-none transition-colors focus:border-ink-400"
                    />
                </div>
                <div>
                    <label class="mb-2 block text-[11px] font-medium tracking-[0.05em] text-ink-600">
                        密碼
                    </label>
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="h-11 w-full rounded-[6px] border border-ink-200 bg-white px-3.5 text-[14px] text-ink-900 placeholder-ink-400 outline-none transition-colors focus:border-ink-400"
                    />
                </div>

                <div
                    v-if="error || lineError"
                    class="rounded-[6px] bg-danger-50 px-3 py-2.5 text-[12px] text-danger-700"
                >
                    {{ error || lineError }}
                </div>

                <button
                    type="submit"
                    :disabled="submitting"
                    class="h-11 w-full rounded-[6px] bg-sumi-600 text-[13px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500 disabled:opacity-50"
                >
                    {{ submitting ? '登入中' : '登入' }}
                </button>

                <div v-if="lineStatus.enabled" class="relative my-2">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-ink-200"></div>
                    </div>
                    <div class="relative flex justify-center text-[10px] tracking-[0.1em]">
                        <span class="bg-ink-50 px-3 text-ink-400">或</span>
                    </div>
                </div>

                <button
                    v-if="lineStatus.enabled"
                    type="button"
                    @click="loginWithLine"
                    class="flex h-11 w-full items-center justify-center gap-2 rounded-[6px] text-[13px] font-medium tracking-[0.05em] text-white transition-colors"
                    style="background-color: #06C755;"
                >
                    <svg width="20" height="20" viewBox="0 0 32 32" fill="currentColor">
                        <path d="M16 4C8.27 4 2 9.05 2 15.27c0 5.58 4.96 10.25 11.66 11.14.45.1 1.07.3 1.23.69.14.36.09.92.05 1.28l-.2 1.2c-.06.36-.28 1.4 1.23.76 1.51-.63 8.13-4.78 11.09-8.18C29.06 19.95 30 17.7 30 15.27 30 9.05 23.73 4 16 4z" />
                    </svg>
                    使用 LINE 登入
                </button>

                <button
                    v-else
                    type="button"
                    disabled
                    class="h-11 w-full rounded-[6px] border border-ink-200 bg-white text-[13px] tracking-[0.05em] text-ink-400 cursor-not-allowed"
                >
                    使用 LINE 登入（尚未設定）
                </button>
            </form>

            <p class="mt-12 text-center text-[10px] tracking-[0.08em] text-ink-400">
                示範帳戶：demo@shiftpal.local / demo1234
            </p>
        </div>
    </div>
</template>
