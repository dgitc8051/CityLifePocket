<script setup>
import { useLiff } from '../../composables/useLiff';
import { useRouter } from 'vue-router';

const router = useRouter();
const { ready, error, employee, shop, needsBinding } = useLiff();
</script>

<template>
    <div class="liff-wrap">
        <div v-if="error" class="liff-error">
            無法載入:{{ error }}
        </div>
        <div v-else-if="!ready" class="liff-loading">
            載入中…
        </div>
        <template v-else>
            <header class="liff-header">
                <div class="shop">{{ shop?.name }}</div>
                <div class="emp" v-if="employee">{{ employee.name }}</div>
                <div class="emp warn" v-else>尚未綁定員工資料</div>
            </header>

            <div v-if="needsBinding" class="liff-bind-warn">
                請聯絡店長綁定你的員工資料,才能使用打卡、查班功能。
            </div>

            <nav class="liff-nav" v-else>
                <button @click="router.push({ name: 'liff-clockin' })">📍 打卡</button>
                <button @click="router.push({ name: 'liff-schedule' })">📅 我的班表</button>
                <button @click="router.push({ name: 'liff-coverage' })">🆘 換班市場</button>
            </nav>
        </template>
    </div>
</template>

<style scoped>
.liff-wrap { padding: 16px; font-family: 'Helvetica Neue', sans-serif; }
.liff-header { padding: 12px 0; border-bottom: 1px solid #eee; margin-bottom: 24px; }
.liff-header .shop { font-size: 14px; color: #666; }
.liff-header .emp { font-size: 20px; font-weight: 600; margin-top: 4px; }
.liff-header .emp.warn { color: #d93025; }
.liff-bind-warn { padding: 12px; background: #fff8e1; border-radius: 6px; color: #b26a00; }
.liff-nav { display: flex; flex-direction: column; gap: 12px; }
.liff-nav button {
    height: 56px; font-size: 18px;
    background: #06c755; color: white; border: 0; border-radius: 8px;
}
.liff-loading, .liff-error { text-align: center; padding: 48px 16px; color: #888; }
.liff-error { color: #d93025; }
</style>
