<script setup lang="ts">
import { Plus, X as XIcon } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const urls = defineModel<string[]>({ required: true });

defineProps<{
    max: number;
    /** Laravel's errors for this field, keyed "reference_images.0" and so on. */
    errors: Record<string, string | undefined>;
}>();

function add(): void {
    urls.value = [...urls.value, ''];
}

function remove(position: number): void {
    urls.value = urls.value.filter((_, i) => i !== position);
}

function update(position: number, value: string): void {
    urls.value = urls.value.map((url, i) => (i === position ? value : url));
}
</script>

<template>
    <div class="grid gap-2">
        <div v-for="(url, position) in urls" :key="position" class="grid gap-1">
            <div class="flex gap-2">
                <input
                    :value="url"
                    type="url"
                    placeholder="https://example.com/style.png"
                    :aria-label="'Reference image ' + (position + 1)"
                    :aria-invalid="!!errors['reference_images.' + position]"
                    class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                    @input="
                        update(
                            position,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="'Remove reference image ' + (position + 1)"
                    @click="remove(position)"
                >
                    <XIcon />
                </Button>
            </div>
            <InputError :message="errors['reference_images.' + position]" />
        </div>

        <Button
            v-if="urls.length < max"
            type="button"
            variant="outline"
            size="sm"
            class="justify-self-start"
            @click="add"
        >
            <Plus /> Add reference image
        </Button>
        <InputError :message="errors.reference_images" />
    </div>
</template>
