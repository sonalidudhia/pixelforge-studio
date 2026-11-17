<script setup lang="ts">
import { CircleAlert, CircleCheck, Clock } from '@lucide/vue';
import { computed } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types/generations';

const props = defineProps<{
    status: GenerationStatus;
    submitAttempts: number;
}>();

const label = computed(() => {
    switch (props.status) {
        case 'pending':
            // A retry means Pixelforge said "busy" and we are waiting our turn.
            return props.submitAttempts > 1
                ? `Pixelforge busy, retrying (try ${props.submitAttempts})`
                : 'Submitting';
        case 'queued':
            return 'Queued';
        case 'processing':
            return 'Processing';
        case 'succeeded':
            return 'Succeeded';
        case 'failed':
            return 'Failed';
    }

    return props.status;
});

const tone = computed(() => {
    switch (props.status) {
        case 'succeeded':
            return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
        case 'failed':
            return 'bg-red-500/10 text-red-700 dark:text-red-400';
        default:
            return 'bg-amber-500/10 text-amber-700 dark:text-amber-400';
    }
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="tone"
        role="status"
    >
        <CircleCheck v-if="status === 'succeeded'" class="size-3.5" />
        <CircleAlert v-else-if="status === 'failed'" class="size-3.5" />
        <Clock v-else-if="status === 'queued'" class="size-3.5" />
        <Spinner v-else class="size-3.5" />
        {{ label }}
    </span>
</template>
