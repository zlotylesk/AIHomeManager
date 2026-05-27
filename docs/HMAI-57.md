> **Status:** This decision has been promoted to [ADR-005 in Confluence](https://honemanager.atlassian.net/wiki/spaces/H/pages/64225282). This file retains the original drafting context.

# HMAI-57 — CSRF on mutating API endpoints

**Jira:** https://honemanager.atlassian.net/browse/HMAI-57
**Source:** HMAI-44 code review (M1, Major)
**Decision date:** 2026-05-20

## Cel

Code review HMAI-44 zgłosił: "Brak CSRF na endpointach POST/PUT/DELETE — każdy endpoint mutujący jest podatny na ataki cross-site, jeśli sesja użytkownika żyje". DoD epica HMAI-129 dopuszcza explicit decyzję `stateless+API key` zamiast dodawania tokenów CSRF.

## Decyzja

**Nie dodajemy `#[IsCsrfTokenValid]` na `^/api/*`.** Mutujące endpointy są chronione przez wymóg nagłówka `X-API-Key`, który nie podlega CSRF z założenia.

## Uzasadnienie

CSRF wykorzystuje fakt, że przeglądarka **automatycznie wysyła ciasteczka** przy każdym żądaniu cross-origin. Atakujący wystawia formularz na zewnętrznej stronie; ofiara klikając w niego trafia żądanie do naszego serwera ze swoją sesją.

Nasz firewall `api` (`config/packages/security.yaml`):

- **`stateless: true`** — brak sesji, brak ciasteczek sesyjnych.
- **Autoryzacja przez header `X-API-Key`** porównywany w `App\Security\ApiKeyAuthenticator` przez `hash_equals` z `%env(API_KEY)%`.

Przeglądarka **nie ustawia automatycznie custom headers** na żądaniach cross-origin (CORS preflight blokuje to). Atakujący nie może zatem podszyć żądania mutującego — żądanie bez `X-API-Key` zwraca 401 niezależnie od ciasteczek czy origin.

System jest **single-user**: API key trzymany w `.env.local` (klient terminal, własna integracja, Postman); brak scenariusza "ofiara loguje się przez przeglądarkę a atakujący wykonuje akcje w jej imieniu". Nawet gdyby pojawił się drugi użytkownik — póki przesyłają klucz w headerze, nie w cookies, CSRF nie ma jak zadziałać.

## OAuth init/callback (`/auth/google*`, `/auth/discogs*`)

Nie objęte firewall'em `api` (publiczne), ale **rozwiązane osobno przez HMAI-52/53**: parameter `state` w protokole OAuth pełni rolę CSRF tokenu (random nonce w session, weryfikowany po callbacku). To standard OAuth2 — nie wymaga dodatkowego `#[IsCsrfTokenValid]`.

## Co byłoby do zrobienia, gdyby decyzja się zmieniła

Jeśli kiedyś dorzucimy stateful UI flow (np. forms POST z `<form action="/api/...">` przez session) lub klucz API zacznie być serwowany jako cookie:

1. Dodać `framework.csrf_protection: enabled: true` (już domyślne).
2. `#[IsCsrfTokenValid('series')]` itp. na każdej akcji POST/PUT/DELETE w kontrolerach.
3. W Twigach renderować `{{ csrf_token('series') }}` do `<meta name="csrf-token">`.
4. JS odczytuje meta i wysyła w `X-CSRF-Token` przez `apiCall()`.
5. Wyłączyć CSRF dla endpointów ze stateless headerem (`#[IsCsrfTokenValid(false)]`) jeśli zmieszane przepływy.

## Test regresji

`App\Tests\Integration\Security\ApiKeyAuthCsrfTest` (HMAI-57) — POST do `/api/series` z ciasteczkiem `PHPSESSID` ale bez `X-API-Key` zwraca 401, potwierdzając że nie ma drogi do mutacji bez headera.

## Powiązane

- HMAI-34 — wprowadzenie API key auth (`docs/HMAI-34.md`)
- HMAI-52/53 — OAuth state validation (osobny vector CSRF dla OAuth init)
