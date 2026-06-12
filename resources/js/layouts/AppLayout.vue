<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const allSections = [
    {
        title: '主畫面',
        items: [
            { to: { name: 'dashboard' }, label: '今日概覽', desc: '今天班表、待審請假、未填可上時段', perm: 'dashboard' },
        ],
    },
    {
        title: '排班作業',
        items: [
            { to: { name: 'schedule' }, label: '人員排班', desc: '把員工排入每日時段', perm: 'schedule' },
            { to: { name: 'shift-templates' }, label: '各班人力設定', desc: '設定每個時段需要的人數與規則', perm: 'shift_templates' },
            { to: { name: 'availability' }, label: '排班意願', desc: '員工每週可上時段（員工填或店長代填）', perm: 'availability' },
            { to: { name: 'attendance' }, label: '出勤打卡', desc: '今日打卡上下班、出勤紀錄', perm: 'attendance' },
        ],
    },
    {
        title: '人員管理',
        items: [
            { to: { name: 'employees' }, label: '員工資料', desc: '管理員工、雇用類型', perm: 'employees' },
            { to: { name: 'leaves' }, label: '請假審核', desc: '員工請假申請的核准與拒絕', perm: 'leaves' },
            { to: { name: 'shift-swaps' }, label: '換班申請', desc: '員工換班、代班的審核與紀錄', perm: 'leaves' },
        ],
    },
    {
        title: '其他',
        items: [
            { to: { name: 'reports' }, label: '工時報表', desc: '工時統計、達標率、CSV 匯出', perm: 'reports' },
            { to: { name: 'personal-hours' }, label: '員工時數表', desc: '依倍率拆桶、加班核可', perm: 'attendance', feature: 'payroll' },
            { to: { name: 'settings' }, label: '店家資料', desc: '店名、營業時間、公休日、規則', perm: 'settings' },
            { to: { name: 'audit-logs' }, label: '操作紀錄', desc: '誰、何時、改了什麼（含 IP）', perm: 'audit_logs' },
        ],
    },
];

const navSections = computed(() => {
    const perms = auth.user?.permissions ?? {};
    const features = auth.user?.current_shop?.features ?? {};
    return allSections
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => {
                if ((perms[item.perm] ?? 'none') === 'none') return false;
                if (item.feature && features[item.feature] === false) return false;
                return true;
            }),
        }))
        .filter((s) => s.items.length > 0);
});

const pageTitle = computed(() => route.meta?.title ?? '');
const userInitial = computed(() => auth.user?.name?.charAt(0) ?? '?');
const shopName = computed(() => auth.user?.current_shop?.name ?? '');
const accessibleShops = computed(() => auth.user?.accessible_shops ?? []);
const hasMultipleShops = computed(() => accessibleShops.value.length > 1);
const roleLabel = computed(() => auth.user?.role_label ?? '');

const switchingShop = ref(false);
async function handleSwitchShop(shopId) {
    if (switchingShop.value || shopId === auth.user?.current_shop?.id) return;
    switchingShop.value = true;
    try {
        await auth.switchShop(shopId);
        // 重新整理當前頁面資料
        window.location.reload();
    } catch (e) {
        // silent
    } finally {
        switchingShop.value = false;
    }
}

const mobileNavOpen = ref(false);
watch(() => route.fullPath, () => { mobileNavOpen.value = false; });

async function handleLogout() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <div class="flex min-h-screen bg-ink-50">
        <!-- Desktop sidebar -->
        <aside class="no-print hidden w-64 shrink-0 border-r border-ink-200/60 bg-white lg:flex lg:flex-col">
            <div class="flex h-14 items-center px-6">
                <RouterLink :to="{ name: 'dashboard' }" class="flex items-baseline gap-2">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-sumi-600" />
                    <span class="text-[14px] font-medium tracking-[0.02em] text-ink-900">ShiftPal</span>
                </RouterLink>
            </div>

            <div class="hairline mx-6"></div>

            <nav class="flex-1 overflow-y-auto px-3 py-5">
                <div v-for="section in navSections" :key="section.title" class="mb-6 last:mb-0">
                    <p class="px-3 pb-2 text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">
                        {{ section.title }}
                    </p>
                    <ul class="space-y-px">
                        <li v-for="item in section.items" :key="item.label">
                            <RouterLink
                                :to="item.to"
                                class="group block rounded-[5px] px-3 py-2 text-ink-700 transition-colors hover:bg-ink-100/60 hover:text-ink-900"
                                active-class="bg-ink-100 text-ink-900"
                            >
                                <p class="text-[13px] font-medium">{{ item.label }}</p>
                                <p class="mt-0.5 text-[10.5px] leading-relaxed text-ink-400">{{ item.desc }}</p>
                            </RouterLink>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="hairline mx-6"></div>

            <div class="px-3 py-4">
                <div class="flex items-center gap-3 px-3 py-1.5">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-ink-100 text-[11px] font-medium text-ink-700">
                        {{ userInitial }}
                    </div>
                    <div class="flex-1 leading-tight">
                        <p class="text-[12px] font-medium text-ink-900">{{ auth.user?.name }}</p>
                        <p class="text-[10px] text-ink-500">
                            {{ roleLabel }}<span v-if="shopName"> · {{ shopName }}</span>
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    @click="handleLogout"
                    class="mt-1 w-full rounded-[5px] px-3 py-1.5 text-left text-[11px] text-ink-500 transition-colors hover:bg-ink-100/60 hover:text-ink-900"
                >
                    登出
                </button>
            </div>
        </aside>

        <!-- Mobile drawer -->
        <Transition name="drawer">
            <div v-if="mobileNavOpen" class="fixed inset-0 z-40 lg:hidden" @click.self="mobileNavOpen = false">
                <div class="absolute inset-0 bg-ink-900/30 backdrop-blur-sm" />
                <aside class="drawer-panel relative flex h-full w-72 max-w-[85vw] flex-col bg-white shadow-xl">
                    <div class="flex h-14 items-center justify-between px-5">
                        <RouterLink :to="{ name: 'dashboard' }" class="flex items-baseline gap-2">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-sumi-600" />
                            <span class="text-[14px] font-medium tracking-[0.02em] text-ink-900">ShiftPal</span>
                        </RouterLink>
                        <button type="button" @click="mobileNavOpen = false"
                            class="rounded-[5px] p-1.5 text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-900"
                            aria-label="關閉選單">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M3 3 L13 13 M13 3 L3 13" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>

                    <div class="hairline mx-5"></div>

                    <nav class="flex-1 overflow-y-auto px-3 py-5">
                        <div v-for="section in navSections" :key="section.title" class="mb-6 last:mb-0">
                            <p class="px-3 pb-2 text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">
                                {{ section.title }}
                            </p>
                            <ul class="space-y-px">
                                <li v-for="item in section.items" :key="item.label">
                                    <RouterLink :to="item.to"
                                        class="group block rounded-[5px] px-3 py-2.5 text-ink-700 transition-colors hover:bg-ink-100/60"
                                        active-class="bg-ink-100 text-ink-900">
                                        <p class="text-[14px] font-medium">{{ item.label }}</p>
                                        <p class="mt-0.5 text-[11px] leading-relaxed text-ink-400">{{ item.desc }}</p>
                                    </RouterLink>
                                </li>
                            </ul>
                        </div>
                    </nav>

                    <div class="hairline mx-5"></div>

                    <div class="px-3 py-4">
                        <div class="flex items-center gap-3 px-3 py-1.5">
                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-ink-100 text-[11px] font-medium text-ink-700">
                                {{ userInitial }}
                            </div>
                            <div class="flex-1 leading-tight">
                                <p class="text-[12px] font-medium text-ink-900">{{ auth.user?.name }}</p>
                                <p class="text-[10px] text-ink-500">
                                    {{ roleLabel }}<span v-if="shopName"> · {{ shopName }}</span>
                                </p>
                            </div>
                        </div>
                        <button type="button" @click="handleLogout"
                            class="mt-1 w-full rounded-[5px] px-3 py-1.5 text-left text-[11px] text-ink-500 transition-colors hover:bg-ink-100/60 hover:text-ink-900">
                            登出
                        </button>
                    </div>
                </aside>
            </div>
        </Transition>

        <!-- Main -->
        <div class="flex min-h-screen flex-1 flex-col">
            <header class="no-print sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-ink-200/60 bg-ink-50/80 px-5 backdrop-blur-md lg:px-12">
                <button type="button" @click="mobileNavOpen = true"
                    class="-ml-1.5 rounded-[5px] p-1.5 text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 lg:hidden"
                    aria-label="開啟選單">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M3 5 H15 M3 9 H15 M3 13 H15" stroke-linecap="round" />
                    </svg>
                </button>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0">
                    <h1 class="text-[14px] font-medium tracking-[0.02em] text-ink-900">{{ pageTitle }}</h1>
                    <!-- 單店：純文字；多店：下拉切換 -->
                    <p v-if="!hasMultipleShops" class="hidden text-[11px] text-ink-400 sm:block">— {{ shopName }}</p>
                    <select v-else
                        :value="auth.user?.current_shop?.id"
                        @change="handleSwitchShop(Number($event.target.value))"
                        :disabled="switchingShop"
                        class="hidden h-7 rounded-[4px] border border-ink-200/60 bg-white px-2 text-[11px] text-ink-700 outline-none focus:border-ink-400 disabled:opacity-50 sm:block"
                    >
                        <option v-for="s in accessibleShops" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
            </header>

            <main class="flex-1 px-5 py-8 sm:px-8 sm:py-10 lg:px-12 lg:py-14">
                <div class="mx-auto max-w-[1080px]">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped>
.drawer-enter-active,
.drawer-leave-active {
    transition: opacity 200ms ease;
}
.drawer-enter-active .drawer-panel,
.drawer-leave-active .drawer-panel {
    transition: transform 220ms ease;
}
.drawer-enter-from,
.drawer-leave-to { opacity: 0; }
.drawer-enter-from .drawer-panel,
.drawer-leave-to .drawer-panel { transform: translateX(-100%); }
</style>

<style>
@media print {
    .no-print { display: none !important; }
    main { padding: 0 !important; }
}
</style>
