let counter = 0;

/**
 * Stable-enough unique ids so every field can wire label/hint/error together
 * with aria-describedby without the caller having to invent one.
 */
export function useId(prefix = 'field') {
    counter += 1;

    return `${prefix}-${counter}`;
}
