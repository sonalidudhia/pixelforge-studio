<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ImageIcon } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerateForm from '@/components/generations/GenerateForm.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/generations';
import { isFinished } from '@/types/generations';
import type {
    GenerateOptions,
    Generation,
    Paginated,
} from '@/types/generations';

const props = defineProps<{
    generations: Paginated<Generation>;
    options: GenerateOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Studio', href: index() }],
    },
});

// Live status: while anything on this page is unfinished, reload just the
// generations every two seconds. Stops by itself once all are done.
const hasUnfinished = computed(() =>
    props.generations.data.some((generation) => !isFinished(generation.status)),
);

const { start, stop } = usePoll(
    2000,
    { only: ['generations'] },
    { autoStart: false },
);

watch(hasUnfinished, (unfinished) => (unfinished ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="Studio" />

    <div
        class="grid flex-1 gap-6 p-4 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]"
    >
        <section class="lg:sticky lg:top-4 lg:self-start">
            <div
                class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border"
            >
                <div class="mb-5">
                    <Heading
                        variant="small"
                        title="Generate an image"
                        description="Describe it, pick a size and quality, and see the price before you start."
                    />
                </div>
                <GenerateForm :options="options" />
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-lg font-semibold">Your generations</h2>
                <span class="text-sm text-muted-foreground">
                    {{ generations.meta.total }} in total
                </span>
            </div>

            <div
                v-if="generations.data.length === 0"
                class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center text-muted-foreground"
            >
                <ImageIcon class="size-8" />
                <p>No images yet. Your generations will appear here.</p>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>

            <nav
                v-if="generations.meta.last_page > 1"
                class="flex items-center justify-between"
                aria-label="Pages"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!generations.links.prev"
                    as-child
                >
                    <Link
                        v-if="generations.links.prev"
                        :href="generations.links.prev"
                        preserve-scroll
                        >Newer</Link
                    >
                    <span v-else>Newer</span>
                </Button>
                <span class="text-sm text-muted-foreground">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!generations.links.next"
                    as-child
                >
                    <Link
                        v-if="generations.links.next"
                        :href="generations.links.next"
                        preserve-scroll
                        >Older</Link
                    >
                    <span v-else>Older</span>
                </Button>
            </nav>
        </section>
    </div>
</template>
