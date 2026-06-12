<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    comingFeatures: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="space-y-10">
        <section>
            <p class="text-[12px] uppercase tracking-wider text-ink-400">
                規劃中
            </p>
            <h2
                class="mt-2 font-serif text-[24px] font-medium tracking-tight text-ink-900"
            >
                {{ title }}
            </h2>
            <p v-if="description" class="mt-2 max-w-2xl text-[14px] text-ink-500">
                {{ description }}
            </p>
        </section>

        <section
            v-if="comingFeatures.length"
            class="rounded-[6px] border border-ink-200/60 bg-white p-6"
        >
            <p class="text-[12px] font-medium uppercase tracking-wider text-ink-400">
                即將推出
            </p>
            <ul class="mt-4 space-y-2.5">
                <li
                    v-for="feature in comingFeatures"
                    :key="feature"
                    class="flex items-start gap-2.5 text-[14px] text-ink-700"
                >
                    <span
                        class="mt-2 inline-block h-1 w-1 shrink-0 rounded-full bg-ink-400"
                    />
                    {{ feature }}
                </li>
            </ul>
        </section>
    </div>
</template>
