/**
 * Which badge colour a status wears.
 *
 * Colour is never the only signal — every badge carries its label — so this is
 * decoration on top of the words, not a substitute for them.
 */
const ORDER = {
    pending_payment: 'pending',
    paid: 'active',
    partially_fulfilled: 'pending',
    completed: 'active',
    cancelled: 'neutral',
    refunded: 'danger',
};

const SUB_ORDER = {
    pending: 'pending',
    accepted: 'pending',
    shipped: 'pending',
    delivered: 'active',
    settled: 'active',
    rejected: 'danger',
    disputed: 'danger',
    refunded: 'danger',
    cancelled: 'neutral',
};

export function orderTone(status) {
    return ORDER[status] ?? 'neutral';
}

export function subOrderTone(status) {
    return SUB_ORDER[status] ?? 'neutral';
}
