/**
 * Pure presentation helpers for the notifications settings panel.
 *
 * Kept free of DOM and fetch so they stay unit-testable; the Stimulus controller
 * owns everything with side effects.
 */

const TYPE_LABELS = {
    task_due: 'Termin zadania',
    article_daily: 'Artykuł dnia',
    goal_streak_at_risk: 'Zagrożona seria',
    daily_digest: 'Podsumowanie dnia',
};

const CHANNEL_LABELS = {
    email: 'E-mail',
    push: 'Push',
};

const STATUS_LABELS = {
    pending: 'Oczekuje',
    sent: 'Wysłane',
    failed: 'Błąd',
};

export function typeLabel(type) {
    return TYPE_LABELS[type] || type;
}

export function channelLabel(channel) {
    return CHANNEL_LABELS[channel] || channel;
}

export function statusLabel(status) {
    return STATUS_LABELS[status] || status;
}

export function isChannelEnabled(preference, channel) {
    return Array.isArray(preference?.channels) && preference.channels.includes(channel);
}

/**
 * How the quiet window reads in the panel. A window that wraps past midnight is
 * spelled out, because "22:00–07:00" alone invites reading it as a same-day range.
 */
export function quietHoursLabel(preference) {
    const from = preference?.quietFrom;
    const to = preference?.quietTo;

    if (!from || !to) {
        return 'Brak ciszy';
    }

    return from > to ? `${from}–${to} (przez noc)` : `${from}–${to}`;
}

/**
 * The two ends are set together or not at all — one alone would persist as
 * "no quiet hours" and silently lose the user's edit.
 */
export function isQuietRangeComplete(from, to) {
    const hasFrom = Boolean(from);
    const hasTo = Boolean(to);

    return hasFrom === hasTo;
}

export function formatSentAt(notification) {
    const stamp = notification?.sentAt || notification?.createdAt;

    if (!stamp) {
        return '';
    }

    const parsed = new Date(stamp);

    return Number.isNaN(parsed.getTime()) ? '' : parsed.toLocaleString('pl-PL');
}
