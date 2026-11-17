<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ReferenceImagesInput from '@/components/generations/ReferenceImagesInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatRange } from '@/lib/money';
import { estimate as estimateRoute, store } from '@/routes/generations';
import type { CostEstimate, GenerateOptions } from '@/types/generations';

const props = defineProps<{
    options: GenerateOptions;
}>();

const draft = useForm({
    prompt: '',
    size: props.options.sizes.includes('1024x1024')
        ? '1024x1024'
        : props.options.sizes[0],
    quality: props.options.qualities.includes('medium')
        ? 'medium'
        : props.options.qualities[0],
    reference_images: [] as string[],
});

// Pixelforge counts characters (code points), not UTF-16 units or bytes.
const characters = computed(() => Array.from(draft.prompt).length);
const filledReferenceImages = computed(() =>
    draft.reference_images.map((url) => url.trim()).filter((url) => url !== ''),
);

const sizeLabels: Record<string, string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

// Live estimate. Only the prompt's length is sent, never its text.
const estimate = ref<CostEstimate | null>(null);
const estimating = ref(false);
const estimateFailed = ref(false);
let inFlight: AbortController | null = null;

const refreshEstimate = useDebounceFn(async () => {
    inFlight?.abort();
    inFlight = new AbortController();
    estimating.value = true;
    estimateFailed.value = false;

    try {
        const response = await fetch(
            estimateRoute.url({
                query: {
                    characters: Math.min(
                        characters.value,
                        props.options.max_prompt_characters,
                    ),
                    size: draft.size,
                    quality: draft.quality,
                    reference_images: Math.min(
                        filledReferenceImages.value.length,
                        props.options.max_reference_images,
                    ),
                },
            }),
            {
                headers: { Accept: 'application/json' },
                signal: inFlight.signal,
            },
        );

        if (!response.ok) {
            throw new Error(`Estimate failed with ${response.status}`);
        }

        estimate.value = (await response.json()) as CostEstimate;
        estimating.value = false;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            estimateFailed.value = true;
            estimating.value = false;
        }
    }
}, 250);

watch(
    [
        characters,
        () => draft.size,
        () => draft.quality,
        () => filledReferenceImages.value.length,
    ],
    () => void refreshEstimate(),
);
onMounted(() => void refreshEstimate());

function submit(): void {
    draft
        .transform((data) => ({
            ...data,
            reference_images: filledReferenceImages.value,
        }))
        .post(store.url(), {
            preserveScroll: true,
        });
}
</script>

<template>
    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span
                    class="text-xs tabular-nums"
                    :class="
                        characters > options.max_prompt_characters
                            ? 'text-red-600'
                            : 'text-muted-foreground'
                    "
                >
                    {{ characters.toLocaleString() }} /
                    {{ options.max_prompt_characters.toLocaleString() }}
                </span>
            </div>
            <textarea
                id="prompt"
                v-model="draft.prompt"
                rows="5"
                required
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                class="w-full min-w-0 resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                :aria-invalid="!!draft.errors.prompt"
            />
            <InputError :message="draft.errors.prompt" />
        </div>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Size</legend>
            <div class="grid grid-cols-3 gap-2">
                <label
                    v-for="size in options.sizes"
                    :key="size"
                    class="flex cursor-pointer flex-col items-center gap-1 rounded-md border p-2 text-xs has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                >
                    <input
                        v-model="draft.size"
                        type="radio"
                        name="size"
                        :value="size"
                        class="sr-only"
                    />
                    <span class="font-medium">{{
                        sizeLabels[size] ?? size
                    }}</span>
                    <span class="text-muted-foreground">{{ size }}</span>
                </label>
            </div>
            <InputError :message="draft.errors.size" />
        </fieldset>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">Quality</legend>
            <div class="grid grid-cols-3 gap-2">
                <label
                    v-for="quality in options.qualities"
                    :key="quality"
                    class="cursor-pointer rounded-md border p-2 text-center text-xs font-medium capitalize has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                >
                    <input
                        v-model="draft.quality"
                        type="radio"
                        name="quality"
                        :value="quality"
                        class="sr-only"
                    />
                    {{ quality }}
                </label>
            </div>
            <InputError :message="draft.errors.quality" />
        </fieldset>

        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label
                    >Reference images
                    <span class="font-normal text-muted-foreground"
                        >(optional)</span
                    ></Label
                >
                <span class="text-xs text-muted-foreground">
                    {{ draft.reference_images.length }} /
                    {{ options.max_reference_images }}
                </span>
            </div>
            <p class="text-xs text-muted-foreground">
                Image URLs that guide the style. Each one adds a little to the
                price.
            </p>
            <ReferenceImagesInput
                v-model="draft.reference_images"
                :max="options.max_reference_images"
                :errors="draft.errors"
            />
        </div>

        <div class="rounded-lg border bg-muted/40 p-4" aria-live="polite">
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-sm font-medium">Estimated cost</span>
                <span class="text-lg font-semibold tabular-nums">
                    <Spinner v-if="estimating && !estimate" />
                    <template v-else-if="estimate">{{
                        formatRange(estimate.min, estimate.max)
                    }}</template>
                    <span
                        v-else-if="estimateFailed"
                        class="text-sm font-normal text-muted-foreground"
                        >Unavailable</span
                    >
                </span>
            </div>
            <p class="mt-2 text-xs text-muted-foreground">
                You pay what Pixelforge charges plus
                {{ options.markup_percent }}%. Part of that is only known once
                the image is made, so you see a range now and the exact cost
                when it finishes. Failed images are free.
            </p>
        </div>

        <Button type="submit" :disabled="draft.processing || characters === 0">
            <Spinner v-if="draft.processing" />
            Generate image
        </Button>
    </form>
</template>
