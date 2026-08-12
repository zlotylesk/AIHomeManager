<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener(event: 'kernel.response', priority: -128)]
final readonly class SecurityHeadersListener
{
    /**
     * Kept identical to the value in docker/nginx/snippets/hsts-map.conf, which
     * ProductionRuntimeConfigTest enforces: the two layers announce one policy
     * or the build fails. A max-age that disagreed between them would be
     * decided by whichever response the browser happened to see last.
     */
    public const string STRICT_TRANSPORT_SECURITY = 'max-age=31536000; includeSubDomains';

    /**
     * Every page-served asset is same-origin (Encore's public path is /build,
     * the three legacy panels load their own /js/*.js, Chart.js arrives as a
     * lazy same-origin chunk), so 'self' alone covers script-src and
     * connect-src. style-src keeps 'unsafe-inline' because ~130 call sites
     * build markup through innerHTML and interpolate a `style="width:${x}%"`
     * attribute directly (progress bars, status badges) — escHtml protects
     * the surrounding HTML, not that attribute value, and a nonce would need
     * to be threaded through every one of those template strings for no
     * attacker capability removed (style injection here cannot exfiltrate the
     * API key, only vandalise layout). frame-ancestors 'none' is the
     * header-only sibling of X-Frame-Options: DENY — a <meta> CSP tag cannot
     * carry it at all, which is one of the reasons this moved to a header.
     *
     * Kept identical to docker/nginx/snippets/csp-map.conf's default branch;
     * ProductionRuntimeConfigTest enforces it the same way it enforces HSTS.
     */
    public const string CONTENT_SECURITY_POLICY =
        "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: https:; font-src 'self'; connect-src 'self'; "
        ."base-uri 'self'; object-src 'none'; frame-ancestors 'none'";

    /**
     * The FrankenPHP hot-reload script (base.html.twig) only ever renders
     * when APP_ENV=dev, and only then does it need `idiomorph`/`hot-reload`
     * from jsdelivr — appending the exception here rather than widening the
     * constant above keeps every other environment, including test, on the
     * policy that ships.
     */
    private const string DEV_SCRIPT_SRC_EXTRA = ' https://cdn.jsdelivr.net';

    /**
     * Swagger UI and Redoc (vendor/nelmio/api-doc-bundle templates) are not
     * this application's code: both render an inline `<script>` that calls
     * `loadSwaggerUI()`/`loadRedocly()`, and Redoc additionally injects an
     * inline `<style>`, pulls Montserrat/Roboto from Google Fonts, spawns a
     * `blob:` Worker for its search index, and loads its mini-logo from
     * `cdn.redoc.ly` — verified against a live render, not guessed. None of
     * that touches the API key meta tag — the strict policy above exists to
     * contain a script XSS in *this application's* markup, and these two
     * pages carry none of it. Confined to `/api/doc*` so the relaxation never
     * reaches a page that does.
     *
     * Kept identical to docker/nginx/snippets/csp-map.conf's ~^/api/doc
     * branch; ProductionRuntimeConfigTest enforces it the same way.
     */
    public const string CONTENT_SECURITY_POLICY_API_DOC =
        "default-src 'self'; script-src 'self' 'unsafe-inline'; "
        ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        ."img-src 'self' data: https://cdn.redoc.ly; font-src 'self' https://fonts.gstatic.com; "
        ."connect-src 'self'; worker-src 'self' blob:; "
        ."base-uri 'self'; object-src 'none'; frame-ancestors 'none'";

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($event->getRequest()->getPathInfo()));

        // Only over TLS. RFC 6797 requires a user agent to ignore the header on
        // a plain-HTTP connection, so sending it there is not a weaker
        // protection — it is none, dressed as one, on exactly the stacks where
        // it would matter that it is missing.
        //
        // isSecure() is accurate behind nginx because framework.yaml trusts
        // x-forwarded-proto and nginx passes the scheme it terminated. In
        // development this is plainly false and the header stays off, which is
        // correct: localhost is a secure context to a browser, but pinning HSTS
        // on it would outlive the container in every developer's browser.
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        }
    }

    private function contentSecurityPolicy(string $pathInfo): string
    {
        if (str_starts_with($pathInfo, '/api/doc')) {
            return self::CONTENT_SECURITY_POLICY_API_DOC;
        }

        if ('dev' === $this->environment) {
            return str_replace(
                "script-src 'self';",
                "script-src 'self'".self::DEV_SCRIPT_SRC_EXTRA.';',
                self::CONTENT_SECURITY_POLICY,
            );
        }

        return self::CONTENT_SECURITY_POLICY;
    }
}
