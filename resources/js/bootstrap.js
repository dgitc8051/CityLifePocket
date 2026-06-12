import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Feature module 守門:後端因 feature 關閉而回 403 時,
 * 我們把這個錯誤吃掉(let console silent),避免 UI 顯示一堆 "請聯絡管理員" 紅錯。
 *
 * 這是 SaaS 模組化的最後一道保險:即使前端某個地方忘了 v-if,後端 403,
 * axios 也不會把它變成終端用戶可見的崩潰。
 */
axios.interceptors.response.use(
    (res) => res,
    (err) => {
        if (err.response?.status === 403 && err.response.data?.error === 'feature_disabled') {
            // eslint-disable-next-line no-console
            console.warn(`[FeatureModule] ${err.response.data.feature} 已關閉,呼叫已被擋下`);
            // 不 reject,回一個假的「無資料」response 避免上游 .catch 觸發 error UI
            return Promise.resolve({ data: { data: [], feature_disabled: err.response.data.feature } });
        }
        return Promise.reject(err);
    },
);
