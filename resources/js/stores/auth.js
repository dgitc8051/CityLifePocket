import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const loading = ref(false);
    const initialized = ref(false);

    const isAuthenticated = computed(() => !!user.value);

    async function fetchMe() {
        loading.value = true;
        try {
            const { data } = await axios.get('/api/auth/me');
            user.value = data.user;
        } catch (e) {
            user.value = null;
        } finally {
            loading.value = false;
            initialized.value = true;
        }
    }

    async function login(email, password) {
        await axios.get('/sanctum/csrf-cookie');
        const { data } = await axios.post('/api/auth/login', { email, password });
        user.value = data.user;
        return data.user;
    }

    async function logout() {
        try {
            await axios.post('/api/auth/logout');
        } catch (e) {
            // ignore
        }
        user.value = null;
    }

    async function switchShop(shopId) {
        const { data } = await axios.post('/api/auth/switch-shop', { shop_id: shopId });
        user.value = data.user;
        return data.user;
    }

    return {
        user,
        loading,
        initialized,
        isAuthenticated,
        fetchMe,
        login,
        logout,
        switchShop,
    };
});
