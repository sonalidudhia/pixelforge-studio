export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

/** Money is an exact decimal string in US dollars, e.g. "0.020465625". */
export type Decimal = string;

export type Generation = {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_min_cost: Decimal;
    estimated_max_cost: Decimal;
    /** What the user paid. Set once the generation has finished. */
    cost: Decimal | null;
    tokens: {
        input_text: number;
        input_image: number;
        output: number;
        reasoning: number;
    } | null;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    submit_attempts: number;
    created_at: string;
    completed_at: string | null;
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
};

export type GenerateOptions = {
    sizes: string[];
    qualities: string[];
    max_prompt_characters: number;
    max_reference_images: number;
    markup_percent: number;
};

export type CostEstimate = {
    min: Decimal;
    max: Decimal;
};

export function isFinished(status: GenerationStatus): boolean {
    return status === 'succeeded' || status === 'failed';
}
