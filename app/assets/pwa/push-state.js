/*
 * Pure decision logic for the contextual push-notification opt-in (HMAI-349).
 *
 * The soft-ask is deliberately NOT shown on the first visit — a permission
 * prompt sprung on someone who just arrived is the anti-pattern browsers now
 * penalise. It is offered only once the visitor has come back, the browser can
 * actually do push, permission is still undecided, and they have not already
 * waved it away. Kept side-effect-free so it is Vitest-covered in isolation, the
 * install-state.js precedent.
 */

export const MIN_VISITS_BEFORE_PROMPT = 2;

export function shouldOfferPushPrompt({ supported, permission, dismissed, visits, minVisits = MIN_VISITS_BEFORE_PROMPT }) {
    return !!supported
        && 'default' === permission
        && !dismissed
        && Number(visits) >= minVisits;
}

// A resettable, overflow-safe visit counter: parses the stored value, bumps it,
// and starts a fresh count from a missing/garbage/zero value.
export function nextVisitCount(current) {
    const parsed = Number.parseInt(current, 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed + 1 : 1;
}
