/**
 * LIFF 載入與初始化 composable。
 *
 * - 動態載入 LIFF SDK from CDN（避免額外 npm 依賴；若你之後跑 `npm i @line/liff`
 *   也能改成 import，行為一致）
 * - liff.init() 後若未登入,呼叫 liff.login() — LIFF 會自動處理 redirect
 * - 拿到 id_token 後,呼叫 POST /api/liff/session 建立 sanctum session
 *
 * 使用:
 *   const { ready, profile, employee, error } = useLiff();
 *   <div v-if="ready">...</div>
 */
import { onMounted, ref } from 'vue';
import axios from 'axios';

const LIFF_SDK_URL = 'https://static.line-scdn.net/liff/edge/2/sdk.js';

function loadLiffSdk() {
    if (window.liff) return Promise.resolve(window.liff);

    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = LIFF_SDK_URL;
        s.async = true;
        s.onload = () => resolve(window.liff);
        s.onerror = () => reject(new Error('Failed to load LIFF SDK'));
        document.head.appendChild(s);
    });
}

export function useLiff(options = {}) {
    const ready = ref(false);
    const error = ref(null);
    const profile = ref(null);
    const employee = ref(null);
    const shop = ref(null);
    const needsBinding = ref(false);

    // liff_id 來源優先序:options > URL query > meta tag
    function resolveLiffId() {
        if (options.liffId) return options.liffId;
        const url = new URL(window.location.href);
        const fromQuery = url.searchParams.get('liff_id');
        if (fromQuery) return fromQuery;
        const meta = document.querySelector('meta[name="liff-id"]');
        return meta?.content || null;
    }

    async function init() {
        try {
            const liffId = resolveLiffId();
            if (!liffId) throw new Error('missing_liff_id');

            const liff = await loadLiffSdk();
            await liff.init({ liffId });

            if (!liff.isLoggedIn()) {
                liff.login();   // 此呼叫會 redirect 不會 return
                return;
            }

            const idToken = liff.getIDToken();
            if (!idToken) throw new Error('no_id_token');

            // 在 LINE 之外建立 sanctum session
            const { data } = await axios.post('/api/liff/session', {
                liff_id: liffId,
                id_token: idToken,
            });

            profile.value = data.user;
            employee.value = data.employee;
            shop.value = data.shop;
            needsBinding.value = data.needs_binding;
            ready.value = true;
        } catch (e) {
            error.value = e?.response?.data?.error || e.message || 'liff_init_failed';
        }
    }

    onMounted(init);

    return { ready, error, profile, employee, shop, needsBinding };
}
