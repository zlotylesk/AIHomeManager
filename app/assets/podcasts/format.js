// Pure presentation helpers for the Podcasts view. Kept free of the DOM and of
// Stimulus so they can be unit-tested in isolation (assets/tests/podcasts_format.test.js)
// and reused by the controller's HTML builders.

// Durations arrive in milliseconds from the source; the UI speaks hours/minutes.
export function durationLabel(durationMs) {
    const ms = Number(durationMs);
    if (!Number.isFinite(ms) || ms <= 0) {
        return '—';
    }

    const totalMinutes = Math.round(ms / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    return hours > 0 ? `${hours} h ${minutes} min` : `${minutes} min`;
}

// How far into an episode the listener got, as a percentage of its length.
// Clamped: a resume point can exceed a stale stored duration, and a bar past
// 100% would look broken rather than informative.
export function progressPercent(resumePositionMs, durationMs) {
    const position = Number(resumePositionMs);
    const total = Number(durationMs);

    if (!Number.isFinite(position) || !Number.isFinite(total) || total <= 0 || position <= 0) {
        return 0;
    }

    return Math.min(100, Math.round((position / total) * 100));
}

// A finished episode reads as finished regardless of where the resume point
// sits — someone who replayed the intro has still heard the whole thing.
export function episodeProgressLabel(episode) {
    if (!episode?.listened) {
        return 'Nieodsłuchany';
    }
    if (episode.fullyPlayed) {
        return 'Odsłuchany w całości';
    }

    const percent = progressPercent(episode.resumePositionMs, episode.durationMs);

    return percent > 0 ? `W trakcie — ${percent}%` : 'Rozpoczęty';
}

export function counterLabel(listenedEpisodeCount, episodeCount) {
    const listened = Number(listenedEpisodeCount) || 0;
    const total = Number(episodeCount) || 0;

    return `${listened}/${total} odsłuchanych`;
}

// Dates come as ISO-8601. Rendered as a plain local date; see listenedCaveat
// for why the exact time is deliberately not paraded.
export function dayLabel(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('pl-PL');
}

export function timeLabel(iso) {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
}

// Spotify reports no listen timestamp for podcast episodes, so what we store is
// when the poll first observed the listen. The UI says so rather than implying a
// precision the data does not have.
export const LISTENED_CAVEAT = 'Czas odsłuchu to moment odczytu ze źródła — nie później niż wtedy.';

export function lastListenedLabel(iso) {
    return iso ? `Ostatnio: ${dayLabel(iso)}` : 'Jeszcze nieodsłuchany';
}

// Sessions arrive newest-first; grouping by day keeps that order for the groups
// and within them, so the history reads as a reverse-chronological diary.
export function groupSessionsByDay(sessions) {
    const groups = [];
    const byDay = new Map();

    (Array.isArray(sessions) ? sessions : []).forEach((session) => {
        const day = dayLabel(session?.listenedAt);

        if (!byDay.has(day)) {
            const group = { day, sessions: [] };
            byDay.set(day, group);
            groups.push(group);
        }

        byDay.get(day).sessions.push(session);
    });

    return groups;
}
