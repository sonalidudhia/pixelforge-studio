<script setup lang="ts">
import { useTimeAgo } from '@vueuse/core';
import { CircleAlert, ImageOff } from '@lucide/vue';
import { computed } from 'vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import { Spinner } from '@/components/ui/spinner';
import { formatExactUsd, formatRange } from '@/lib/money';
import type { Generation } from '@/types/generations';

const props = defineProps<{
    generation: Generation;
}>();

const createdAgo = useTimeAgo(() => props.generation.created_at);

const aspect = computed(() => {
    const [width, height] = props.generation.size.split('x').map(Number);

    return `${width} / ${height}`;
});

const estimate = computed(() =>
    formatRange(
        props.generation.estimated_min_cost,
        props.generation.estimated_max_cost,
    ),
);

const tokenSummary = computed(() => {
    const tokens = props.generation.tokens;

    if (!tokens) {
        return null;
    }

    return [
        `${tokens.input_text.toLocaleString()} text`,
        `${tokens.input_image.toLocaleString()} image`,
        `${tokens.output.toLocaleString()} output`,
        `${tokens.reasoning.toLocaleString()} reasoning`,
    ].join(' · ');
});
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
    >
        <div
            class="relative flex items-center justify-center overflow-hidden bg-muted"
            :style="{ aspectRatio: aspect }"
        >
            <a
                v-if="generation.image_url"
                :href="generation.image_url"
                target="_blank"
                rel="noopener"
                class="block size-full"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="size-full object-cover"
                />
            </a>

            <div
                v-else-if="generation.status === 'failed'"
                class="flex flex-col items-center gap-2 p-6 text-center text-sm text-red-700 dark:text-red-400"
            >
                <CircleAlert class="size-6" />
                <p>
                    {{
                        generation.error_message ??
                        'This image could not be made.'
                    }}
                </p>
            </div>

            <div
                v-else-if="generation.image_expired"
                class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" />
                <p>
                    Pixelforge only keeps images for 24 hours. This one has
                    expired.
                </p>
            </div>

            <div
                v-else
                class="flex flex-col items-center gap-2 text-sm text-muted-foreground"
            >
                <Spinner class="size-6" />
                <p>Usually ready in under a minute</p>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <GenerationStatusBadge
                    :status="generation.status"
                    :submit-attempts="generation.submit_attempts"
                />
                <time
                    class="text-xs text-muted-foreground"
                    :datetime="generation.created_at"
                    :title="generation.created_at"
                >
                    {{ createdAgo }}
                </time>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ generation.size }} · {{ generation.quality }} quality
                <template v-if="generation.reference_images.length">
                    · {{ generation.reference_images.length }} reference
                    {{
                        generation.reference_images.length === 1
                            ? 'image'
                            : 'images'
                    }}
                </template>
            </p>

            <dl
                class="mt-auto grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 border-t pt-3 text-sm"
            >
                <dt class="text-muted-foreground">Estimate</dt>
                <dd
                    class="text-right whitespace-nowrap tabular-nums"
                    :class="{
                        'text-muted-foreground line-through':
                            generation.status === 'failed',
                    }"
                >
                    {{ estimate }}
                </dd>

                <dt class="text-muted-foreground">Cost</dt>
                <dd class="text-right font-medium whitespace-nowrap tabular-nums">
                    <template v-if="generation.status === 'failed'">
                        Free, not charged
                    </template>
                    <template v-else-if="generation.cost !== null">
                        {{ formatExactUsd(generation.cost) }}
                    </template>
                    <span v-else class="font-normal text-muted-foreground">
                        Known when finished
                    </span>
                </dd>
            </dl>

            <p v-if="tokenSummary" class="text-xs text-muted-foreground">
                Tokens: {{ tokenSummary }}
            </p>
        </div>
    </article>
</template>
