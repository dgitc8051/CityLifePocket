<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();

const phone = ref('');
const submitting = ref(false);
const error = ref(null);

async function submit() {
    if (!phone.value) return;
    submitting.value = true;
    error.value = null;
    try {
        await axios.post('/api/auth/bind-phone', { phone: phone.value });
        // 重新拉 user 狀態
        await auth.fetchMe();
        await router.push({ name: 'dashboard' });
    } catch (e) {
        error.value = e?.response?.data?.error ?? '綁定失敗';
    } finally {
        submitting.value = false;
    }
}

async function logout() {
    await auth.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-ink-50 px-4">
        <div class="w-full max-w-[400px]">
            <div class="mb-10 text-center">
                <div class="mb-6 inline-flex items-baseline gap-2">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-sumi-600" />
                    <span class="text-[14px] font-medium tracking-[0.02em] text-ink-900">
                        ShiftPal
                    </span>
                </div>
                <h1 class="font-serif text-[24px] font-medium tracking-tight text-ink-900">
                    完成綁定
                </h1>
                <p class="mt-3 text-[13px] leading-relaxed tracking-[0.02em] text-ink-600">
                    您已用 LINE 登入。<br/>
                    請輸入店長登錄您員工資料時用的手機號碼，<br/>
                    系統會把這個 LINE 帳號綁定到您的員工身分。
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div>
                    <label class="mb-2 block text-[11px] font-medium tracking-[0.05em] text-ink-600">
                        員工手機號碼
                    </label>
                    <input
                        v-model="phone"
                        type="tel"
                        required
                        autocomplete="tel"
                        placeholder="0912345678"
                        class="h-11 w-full rounded-[6px] border border-ink-200 bg-white px-3.5 text-[15px] text-ink-900 placeholder-ink-400 tabular-nums outline-none transition-colors focus:border-ink-400"
                    />
                </div>

                <div v-if="error" class="rounded-[6px] bg-danger-50 px-3 py-2.5 text-[12px] leading-relaxed text-danger-700">
                    {{ error }}
                </div>

                <button
                    type="submit"
                    :disabled="submitting || !phone"
                    class="h-11 w-full rounded-[6px] bg-sumi-600 text-[13px] font-medium tracking-[0.05em] text-white transition-colors hover:bg-sumi-500 disabled:opacity-50"
                >
                    {{ submitting ? '綁定中' : '完成綁定' }}
                </button>

                <button
                    type="button"
                    @click="logout"
                    class="block w-full text-center text-[11px] tracking-[0.05em] text-ink-500 transition-colors hover:text-ink-900"
                >
                    用其他帳號登入
                </button>
            </form>

            <div class="mt-8 rounded-[6px] border border-ink-200/60 bg-white p-4">
                <p class="text-[10px] tracking-[0.05em] text-ink-400">您的 LINE User ID</p>
                <p class="num mt-1 select-all break-all text-[11px] text-ink-700">
                    {{ auth.user?.line_user_id }}
                </p>
                <p class="mt-3 text-[10.5px] leading-relaxed tracking-[0.02em] text-ink-400">
                    如果無法綁定（例如手機號碼錯誤），請把上方 ID 給管理員協助處理。
                </p>
            </div>
        </div>
    </div>
</template>
