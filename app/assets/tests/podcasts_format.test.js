import { describe, expect, it } from 'vitest';
import {
    LISTENED_CAVEAT,
    counterLabel,
    dayLabel,
    durationLabel,
    episodeProgressLabel,
    groupSessionsByDay,
    lastListenedLabel,
    progressPercent,
    timeLabel,
} from '../podcasts/format.js';

describe('durationLabel', () => {
    it('renders hours and minutes past the hour', () => {
        expect(durationLabel(5_400_000)).toBe('1 h 30 min');
    });

    it('drops the hour part for a short episode', () => {
        expect(durationLabel(1_800_000)).toBe('30 min');
    });

    it('falls back for a missing or nonsensical duration', () => {
        expect(durationLabel(null)).toBe('—');
        expect(durationLabel(0)).toBe('—');
        expect(durationLabel(-5)).toBe('—');
        expect(durationLabel('abc')).toBe('—');
    });
});

describe('progressPercent', () => {
    it('reports how far in the listener got', () => {
        expect(progressPercent(900_000, 1_800_000)).toBe(50);
    });

    // A resume point can outrun a stale stored duration; a bar past 100% would
    // look broken rather than informative.
    it('clamps a resume point that outruns the stored duration', () => {
        expect(progressPercent(2_000_000, 1_800_000)).toBe(100);
    });

    it('is zero when either side is missing or the episode was never opened', () => {
        expect(progressPercent(0, 1_800_000)).toBe(0);
        expect(progressPercent(900_000, 0)).toBe(0);
        expect(progressPercent(null, null)).toBe(0);
    });
});

describe('episodeProgressLabel', () => {
    it('names an untouched episode', () => {
        expect(episodeProgressLabel({ listened: false })).toBe('Nieodsłuchany');
    });

    // Finished wins over the position: replaying the intro of an episode you
    // heard in full must not demote it to "in progress".
    it('keeps a finished episode finished even after a rewind', () => {
        expect(episodeProgressLabel({ listened: true, fullyPlayed: true, resumePositionMs: 0, durationMs: 1_800_000 }))
            .toBe('Odsłuchany w całości');
    });

    it('reports the percentage mid-episode', () => {
        expect(episodeProgressLabel({ listened: true, fullyPlayed: false, resumePositionMs: 450_000, durationMs: 1_800_000 }))
            .toBe('W trakcie — 25%');
    });

    it('falls back when the duration is unknown', () => {
        expect(episodeProgressLabel({ listened: true, fullyPlayed: false, resumePositionMs: 450_000, durationMs: null }))
            .toBe('Rozpoczęty');
    });
});

describe('counterLabel', () => {
    it('reads as listened-of-total', () => {
        expect(counterLabel(2, 5)).toBe('2/5 odsłuchanych');
    });

    it('treats missing counters as zero', () => {
        expect(counterLabel(null, undefined)).toBe('0/0 odsłuchanych');
    });
});

describe('dayLabel / timeLabel', () => {
    it('renders a local date', () => {
        expect(dayLabel('2026-07-20T19:00:00+00:00')).not.toBe('—');
    });

    it('falls back for missing or unparsable input', () => {
        expect(dayLabel(null)).toBe('—');
        expect(dayLabel('not-a-date')).toBe('—');
        expect(timeLabel(null)).toBe('');
        expect(timeLabel('not-a-date')).toBe('');
    });
});

describe('lastListenedLabel', () => {
    it('distinguishes a never-listened show from a dated one', () => {
        expect(lastListenedLabel(null)).toBe('Jeszcze nieodsłuchany');
        expect(lastListenedLabel('2026-07-20T19:00:00+00:00')).toContain('Ostatnio:');
    });
});

describe('groupSessionsByDay', () => {
    it('groups consecutive sessions of one day together', () => {
        const groups = groupSessionsByDay([
            { id: 's1', listenedAt: '2026-07-20T19:00:00+00:00' },
            { id: 's2', listenedAt: '2026-07-20T08:00:00+00:00' },
            { id: 's3', listenedAt: '2026-07-18T12:00:00+00:00' },
        ]);

        expect(groups).toHaveLength(2);
        expect(groups[0].sessions.map((s) => s.id)).toEqual(['s1', 's2']);
        expect(groups[1].sessions.map((s) => s.id)).toEqual(['s3']);
    });

    // The API returns sessions newest-first; grouping must not reshuffle them,
    // or the history stops reading as a reverse-chronological diary.
    it('preserves the incoming order of both groups and members', () => {
        const groups = groupSessionsByDay([
            { id: 'a', listenedAt: '2026-07-18T12:00:00+00:00' },
            { id: 'b', listenedAt: '2026-07-20T19:00:00+00:00' },
        ]);

        expect(groups.map((g) => g.sessions[0].id)).toEqual(['a', 'b']);
    });

    it('survives an empty or missing history', () => {
        expect(groupSessionsByDay([])).toEqual([]);
        expect(groupSessionsByDay(undefined)).toEqual([]);
    });
});

describe('LISTENED_CAVEAT', () => {
    // The module's central honesty constraint: the stored moment is when the
    // poll observed the listen, not when it happened.
    it('tells the user the timestamp is an upper bound', () => {
        expect(LISTENED_CAVEAT).toContain('nie później');
    });
});
