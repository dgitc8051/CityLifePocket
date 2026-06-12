<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const pendingBinding = computed(() => auth.user?.pending_binding);
const lineUserId = computed(() => auth.user?.line_user_id);
const features = computed(() => auth.user?.current_shop?.features ?? {});

const loading = ref(true);
const error = ref(null);

const todayLabel = ref('');
const greeting = ref('');
const stats = ref([]);
const todayShifts = ref([]);
const pendingLeaves = ref([]);
const unfilledAvailability = ref([]);

async function fetchDashboard() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get('/api/dashboard');
        todayLabel.value = data.today_label;
        greeting.value = data.greeting;
        stats.value = data.stats;
        todayShifts.value = data.today_shifts;
        pendingLeaves.value = data.pending_leaves;
        unfilledAvailability.value = data.unfilled_availability;
    } catch (e) {
        error.value = e?.response?.data?.error ?? '讀取失敗';
    } finally {
        loading.value = false;
    }
}

onMounted(fetchDashboard);
</script>

<template>
    <!-- LINE 未綁定員工提示（最上層、所有狀態都顯示） -->
    <div v-if="pendingBinding" class="mb-8 rounded-[6px] border border-warning-200 bg-warning-50/60 p-5">
        <p class="font-serif text-[16px] font-medium text-ink-900">等待店長綁定您的員工帳號</p>
        <p class="mt-2 text-[12px] leading-relaxed tracking-[0.02em] text-ink-700">
            您已用 LINE 成功登入，但還沒有對應到任何員工資料。請把下方的 LINE User ID 給店長，請店長到「員工資料」頁面把您綁定到對應員工。
        </p>
        <div class="mt-3 inline-block rounded-[4px] border border-ink-200 bg-white px-3 py-1.5">
            <p class="text-[10px] tracking-[0.05em] text-ink-500">您的 LINE User ID</p>
            <p class="num mt-0.5 select-all text-[12px] font-medium tracking-[0.02em] text-ink-900">
                {{ lineUserId }}
            </p>
        </div>
    </div>

    <div v-if="loading" class="space-y-14">
        <div class="space-y-2">
            <div class="h-3 w-32 rounded bg-ink-200/60" />
            <div class="h-8 w-2/3 rounded bg-ink-200/60" />
        </div>
        <div class="grid grid-cols-1 gap-px bg-ink-200/60 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="i in 4" :key="i" class="h-28 bg-white" />
        </div>
    </div>

    <div
        v-else-if="error"
        class="rounded-[6px] bg-danger-50 px-5 py-4 text-[13px] text-danger-700"
    >
        {{ error }}
    </div>

    <div v-else class="space-y-14">
        <!-- Header -->
        <section>
            <p class="text-[11px] tracking-[0.1em] text-ink-400">{{ todayLabel }}</p>
            <h2 class="mt-3 font-serif text-[26px] font-medium leading-snug tracking-tight text-ink-900">
                {{ greeting }}
            </h2>
        </section>

        <!-- Stats — 1px divider grid (日系常見) -->
        <section class="grid grid-cols-1 gap-px bg-ink-200/60 sm:grid-cols-2 lg:grid-cols-4">
            <article
                v-for="stat in stats"
                :key="stat.label"
                class="bg-white px-6 py-7"
            >
                <p class="text-[10px] font-medium uppercase tracking-[0.12em] text-ink-400">
                    {{ stat.label }}
                </p>
                <p class="num mt-3 text-[32px] font-light leading-none text-ink-900">
                    {{ stat.value }}
                </p>
                <p
                    class="mt-3 text-[11px] tracking-[0.02em]"
                    :class="stat.tone === 'warn' ? 'text-warning-700' : 'text-ink-500'"
                >
                    {{ stat.hint }}
                </p>
            </article>
        </section>

        <!-- Today schedule + leaves -->
        <section class="grid grid-cols-1 gap-10 lg:grid-cols-[1.6fr_1fr]">
            <!-- Today schedule -->
            <div>
                <header class="flex items-end justify-between pb-5">
                    <div>
                        <h3 class="font-serif text-[17px] font-medium tracking-tight text-ink-900">
                            今日班表
                        </h3>
                        <p class="mt-1 text-[11px] tracking-[0.05em] text-ink-500">
                            點選班次以查看詳情或調整人員
                        </p>
                    </div>
                    <RouterLink
                        :to="{ name: 'schedule' }"
                        class="text-[11px] tracking-[0.05em] text-ink-600 transition-colors hover:text-ink-900"
                    >
                        前往排班 →
                    </RouterLink>
                </header>

                <ul class="divide-y divide-ink-200/60 border-y border-ink-200/60">
                    <li
                        v-for="shift in todayShifts"
                        :key="shift.name"
                        class="px-1 py-5"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <div class="flex items-baseline gap-3">
                                <p class="text-[15px] font-medium text-ink-900">
                                    {{ shift.name }}
                                </p>
                                <p class="num text-[12px] tracking-[0.02em] text-ink-500">
                                    {{ shift.time }}
                                </p>
                            </div>
                            <div class="flex items-center gap-1.5 text-[11px]">
                                <span
                                    v-if="features.skill_score && shift.score?.required > 0"
                                    class="num rounded-sm px-1.5 py-0.5 tracking-[0.02em]"
                                    :class="
                                        shift.score.current >= shift.score.required
                                            ? 'bg-success-50 text-success-700'
                                            : 'bg-warning-50 text-warning-700'
                                    "
                                >
                                    {{ shift.score.current }} / {{ shift.score.required }}
                                </span>
                                <span
                                    v-if="features.senior_required && shift.senior?.required > 0"
                                    class="num rounded-sm px-1.5 py-0.5 tracking-[0.02em]"
                                    :class="
                                        shift.senior.current >= shift.senior.required
                                            ? 'bg-success-50 text-success-700'
                                            : 'bg-danger-50 text-danger-700'
                                    "
                                >
                                    高 {{ shift.senior.current }} / {{ shift.senior.required }}
                                </span>
                            </div>
                        </div>

                        <div v-if="shift.members.length" class="mt-4 flex flex-wrap gap-1.5">
                            <span
                                v-for="member in shift.members"
                                :key="member.name"
                                class="inline-flex items-baseline gap-1.5 rounded-sm bg-ink-100/60 px-2 py-0.5 text-[11px] text-ink-700"
                            >
                                <span>{{ member.name }}</span>
                                <span v-if="features.skill_score && member.score !== undefined" class="num text-ink-400">{{ member.score }}</span>
                            </span>
                        </div>
                        <p v-else class="mt-3 text-[11px] text-ink-400">尚未排班</p>

                        <ul
                            v-if="shift.warnings.length"
                            class="mt-3 space-y-1"
                        >
                            <li
                                v-for="w in shift.warnings"
                                :key="w"
                                class="flex items-baseline gap-2 text-[11px] text-warning-700"
                            >
                                <span class="inline-block h-1 w-1 rounded-full bg-warning-700" />
                                {{ w }}
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>

            <!-- Pending leaves + availability -->
            <div class="space-y-10">
                <!-- Pending leaves -->
                <div>
                    <header class="flex items-end justify-between pb-5">
                        <h3 class="font-serif text-[17px] font-medium tracking-tight text-ink-900">
                            待審請假
                        </h3>
                        <span
                            v-if="pendingLeaves.length"
                            class="num text-[11px] tracking-[0.02em] text-ink-500"
                        >
                            {{ pendingLeaves.length }} 件
                        </span>
                    </header>
                    <div
                        v-if="pendingLeaves.length === 0"
                        class="border-y border-ink-200/60 py-8 text-center text-[12px] text-ink-400"
                    >
                        無待審請假
                    </div>
                    <ul v-else class="divide-y divide-ink-200/60 border-y border-ink-200/60">
                        <li
                            v-for="leave in pendingLeaves"
                            :key="leave.id"
                            class="px-1 py-4"
                        >
                            <div class="flex items-baseline justify-between gap-2">
                                <div class="flex items-baseline gap-2">
                                    <p class="text-[13px] font-medium text-ink-900">
                                        {{ leave.name }}
                                    </p>
                                    <span class="text-[11px] text-ink-500">{{ leave.type }}</span>
                                </div>
                                <p class="text-[10px] tracking-[0.05em] text-ink-400">
                                    {{ leave.submitted }}
                                </p>
                            </div>
                            <p class="num mt-1 text-[11px] tracking-[0.02em] text-ink-600">
                                {{ leave.range }}
                            </p>
                            <p v-if="leave.reason" class="mt-1.5 text-[11px] text-ink-500">
                                {{ leave.reason }}
                            </p>
                        </li>
                    </ul>
                </div>

                <!-- Unfilled availability -->
                <div>
                    <header class="flex items-end justify-between pb-5">
                        <h3 class="font-serif text-[17px] font-medium tracking-tight text-ink-900">
                            未填可上時段
                        </h3>
                        <span
                            v-if="unfilledAvailability.length"
                            class="num text-[11px] tracking-[0.02em] text-ink-500"
                        >
                            {{ unfilledAvailability.length }} 位
                        </span>
                    </header>
                    <div
                        v-if="unfilledAvailability.length === 0"
                        class="border-y border-ink-200/60 py-8 text-center text-[12px] text-ink-400"
                    >
                        全員已填寫
                    </div>
                    <ul v-else class="divide-y divide-ink-200/60 border-y border-ink-200/60">
                        <li
                            v-for="emp in unfilledAvailability"
                            :key="emp.id"
                            class="flex items-center justify-between px-1 py-2.5"
                        >
                            <p class="text-[13px] text-ink-900">{{ emp.name }}</p>
                            <p class="text-[10px] tracking-[0.05em] text-ink-400">
                                {{ emp.daysLeft }}
                            </p>
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>
</template>
