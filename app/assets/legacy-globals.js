import { TOAST_TIMEOUT_MS, apiCall, escHtml, safeUrl } from './util.js';
import { fetchAllPages, mountPagerAfter, unwrapPage } from './pagination.js';
import { showError, showInfo } from './banners.js';

/**
 * Hands the shared frontend helpers to the legacy track.
 *
 * Three panels (Tasks, Articles, Music) are still plain `<script src>` files in
 * `public/js/`, which cannot import an ES module — so until they move onto
 * Encore they used to carry their own copies of these helpers. Copies drift:
 * `escHtml` existed four times while guarding twenty `innerHTML` writes, one of
 * them over text imported from Pocket, and the legacy `apiCall` restated the
 * offline-queue contract with its own hardcoded Polish strings.
 *
 * Publishing the very same function objects on `window` removes the copies
 * without moving three panels to a different build system. It works because of
 * load order, which is worth stating since it is what the whole approach rests
 * on: `base.html.twig` renders `encore_entry_script_tags('app')` before
 * `{% block javascripts %}`, and Encore emits classic (non-deferred,
 * non-module) script tags, so this bundle has finished executing before the
 * first legacy file is parsed.
 *
 * The target is a parameter so the publishing itself can be tested; nothing
 * passes anything but `window`.
 */
export function publishLegacyGlobals(target = window) {
    if (!target) {
        return;
    }

    target.TOAST_TIMEOUT_MS = TOAST_TIMEOUT_MS;
    target.safeUrl = safeUrl;
    target.escHtml = escHtml;
    target.apiCall = apiCall;

    // The list-envelope helpers the panels use to read `{data, pagination}`.
    // Only the three the legacy track actually calls are published — the rest of
    // pagination.js stays module-private rather than becoming public surface
    // nobody asked for. `itemNoun` in particular is deliberately absent: the old
    // legacy copy needed it because it built the pager label itself, and the
    // shared mountPagerAfter no longer does.
    target.unwrapPage = unwrapPage;
    target.fetchAllPages = fetchAllPages;
    target.mountPagerAfter = mountPagerAfter;

    // The banners are global elements in base.html.twig, so both tracks drive
    // the same two nodes; the legacy panels carried five byte-identical copies
    // of these. `hideError` is not published because no legacy panel calls it.
    target.showError = showError;
    target.showInfo = showInfo;
}
