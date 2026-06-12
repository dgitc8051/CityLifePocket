import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/Login.vue'),
        meta: { public: true, layout: 'blank' },
    },
    {
        path: '/bind-phone',
        name: 'bind-phone',
        component: () => import('../pages/BindPhone.vue'),
        meta: { layout: 'blank' },
    },
    {
        path: '/',
        name: 'dashboard',
        component: () => import('../pages/Dashboard.vue'),
        meta: { title: '今日概覽' },
    },
    {
        path: '/schedule',
        name: 'schedule',
        component: () => import('../pages/Schedule.vue'),
        meta: { title: '人員排班' },
    },
    {
        path: '/shift-templates',
        name: 'shift-templates',
        component: () => import('../pages/ShiftTemplates.vue'),
        meta: { title: '各班人力設定' },
    },
    {
        path: '/availability',
        name: 'availability',
        component: () => import('../pages/Availability.vue'),
        meta: { title: '排班意願' },
    },
    {
        path: '/employees',
        name: 'employees',
        component: () => import('../pages/Employees.vue'),
        meta: { title: '員工資料' },
    },
    {
        path: '/leaves',
        name: 'leaves',
        component: () => import('../pages/Leaves.vue'),
        meta: { title: '請假審核' },
    },
    {
        path: '/shift-swaps',
        name: 'shift-swaps',
        component: () => import('../pages/ShiftSwaps.vue'),
        meta: { title: '換班申請' },
    },
    {
        path: '/attendance',
        name: 'attendance',
        component: () => import('../pages/Attendance.vue'),
        meta: { title: '出勤打卡' },
    },
    {
        path: '/reports',
        name: 'reports',
        component: () => import('../pages/Reports.vue'),
        meta: { title: '工時報表' },
    },
    {
        path: '/personal-hours',
        name: 'personal-hours',
        component: () => import('../pages/PersonalHours.vue'),
        meta: { title: '員工時數表', requiresFeature: 'payroll' },
    },
    {
        path: '/settings',
        name: 'settings',
        component: () => import('../pages/Settings.vue'),
        meta: { title: '店家資料' },
    },
    {
        path: '/audit-logs',
        name: 'audit-logs',
        component: () => import('../pages/AuditLogs.vue'),
        meta: { title: '操作紀錄' },
    },
    // LIFF (員工端,跑在 LINE WebView 內,不走主 SPA 認證流程)
    {
        path: '/liff',
        name: 'liff-index',
        component: () => import('../pages/Liff/Index.vue'),
        meta: { public: true, layout: 'blank', title: 'LIFF' },
    },
    {
        path: '/liff/clockin',
        name: 'liff-clockin',
        component: () => import('../pages/Liff/Clockin.vue'),
        meta: { public: true, layout: 'blank', title: '打卡' },
    },
    {
        path: '/liff/schedule',
        name: 'liff-schedule',
        component: () => import('../pages/Liff/Schedule.vue'),
        meta: { public: true, layout: 'blank', title: '我的班表' },
    },
    {
        path: '/liff/coverage',
        name: 'liff-coverage',
        component: () => import('../pages/Liff/Coverage.vue'),
        meta: { public: true, layout: 'blank', title: '換班市場' },
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.initialized) {
        await auth.fetchMe();
    }

    if (to.meta.public) {
        if (auth.isAuthenticated && to.name === 'login') {
            return { name: 'dashboard' };
        }
        return true;
    }

    if (!auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    // 已登入但 pending_binding（LINE 登入後尚未綁手機）→ 強制跳綁定頁
    if (auth.user?.pending_binding && to.name !== 'bind-phone') {
        return { name: 'bind-phone' };
    }
    // 反過來：已綁好的人不該再進綁定頁
    if (!auth.user?.pending_binding && to.name === 'bind-phone') {
        return { name: 'dashboard' };
    }

    // Feature 模組守門:整個頁面對應某個 feature 時,關閉就 redirect dashboard
    // 是 SaaS UI 模組化的關鍵:店家關掉的功能 → 對應整個頁面消失,不只是頁內某塊
    if (to.meta.requiresFeature) {
        const features = auth.user?.current_shop?.features ?? {};
        if (features[to.meta.requiresFeature] !== true) {
            return { name: 'dashboard' };
        }
    }

    return true;
});

router.afterEach((to) => {
    const title = to.meta?.title;
    document.title = title ? `${title} · ShiftPal` : 'ShiftPal';
});

export default router;
