// Series-specific banner content. The generic showError/hideError/showInfo moved
// to assets/banners.js when the legacy panels stopped carrying their own copies
// of them — they belong to base.html.twig's global banners, not to this module.
// What stays here is the one message that really is about Series.

export function showTraktConnectPrompt() {
    const banner = document.getElementById('error-banner');
    if (!banner) return;
    banner.innerHTML = 'Najpierw połącz konto Trakt: <a href="/auth/trakt">Połącz z Trakt</a>';
    banner.classList.remove('hidden');
}
