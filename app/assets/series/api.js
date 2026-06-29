import { apiCall } from '../util.js';

// Thin HTTP layer for the Series module. One method per backend endpoint —
// every fetch the Series UI makes goes through here.
export const API = {
    series: () => apiCall('/api/series'),
    seriesDetail: (id) => apiCall(`/api/series/${id}`),
    createSeries: (payload) => apiCall('/api/series', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    }),
    addSeason: (seriesId, number) => apiCall(`/api/series/${seriesId}/seasons`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({number}),
    }),
    addEpisode: (seriesId, seasonId, title, number, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title, number, rating: rating || null}),
    }),
    rateEpisode: (seriesId, seasonId, episodeId, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
    setEpisodeWatched: (seriesId, seasonId, episodeId, watched) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}/watched`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({watched}),
    }),
    rateSeries: (seriesId, rating) => apiCall(`/api/series/${seriesId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
    rateSeason: (seriesId, seasonId, rating) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/rating`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({rating}),
    }),
    deleteSeries: (seriesId) => apiCall(`/api/series/${seriesId}`, {method: 'DELETE'}),
    deleteSeason: (seriesId, seasonId) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}`, {method: 'DELETE'}),
    deleteEpisode: (seriesId, seasonId, episodeId) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}`, {method: 'DELETE'}),
    renameSeries: (seriesId, title) => apiCall(`/api/series/${seriesId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title}),
    }),
    updateSeries: (seriesId, payload) => apiCall(`/api/series/${seriesId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    }),
    renumberSeason: (seriesId, seasonId, number) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({number}),
    }),
    renameEpisode: (seriesId, seasonId, episodeId, title) => apiCall(
        `/api/series/${seriesId}/seasons/${seasonId}/episodes/${episodeId}`, {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({title}),
    }),
    importFromTrakt: () => apiCall('/api/series/import/trakt', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
    }),
};
