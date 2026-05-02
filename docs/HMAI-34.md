# HMAI-34 — API Authorization with API Key

**Jira:** https://honemanager.atlassian.net/browse/HMAI-34
**Branch:** `HMAI-34-api-authorization-secure-endpoints`
**Commit:** `eeebfd8`
**PR:** https://github.com/zlotylesk/AIHomeManager/compare/develop...HMAI-34-api-authorization-secure-endpoints?expand=1
**Confluence:** https://honemanager.atlassian.net/wiki/spaces/H/pages/46891009/Dokumentacja+API (v3)
**Date:** 2026-05-01

## Cel

Zabezpieczenie wszystkich endpointów `/api/*` przed nieuwierzytelnionym dostępem. Jeden z P0 blockerów z code review HMAI-44 (przed wdrożeniem na serwer publiczny).

## Strategiczne decyzje

### Dlaczego API Key, nie JWT

Task description sugerował dwie opcje (API Key lub JWT) z dev notes wskazującymi API Key jako rekomendowane dla systemu jednoosobowego.

| czynnik | API Key | JWT |
|---|---|---|
| Kod | ~50 linii authenticatora | bundle + konfiguracja kluczy + endpointy login/refresh |
| Liczba użytkowników | 1 (single-user) | obojętna |
| Rotacja | edycja `.env.local` + restart | endpoint refresh + revoke list |
| Stateful endpoints | nie | nie |
| Złożoność | minimalna | spora |

API Key wystarcza, bo:
- nie ma wielu użytkowników → claim-y i role są zbędne;
- token nigdy nie wygasa → refresh flow nie ma zastosowania;
- klucz statyczny w `.env.local` jest porównywalnie bezpieczny do JWT secret w tym samym pliku.

JWT zostawiam jako ścieżkę migracji jeśli w przyszłości pojawią się: dodatkowi użytkownicy, role, tokeny krótkoterminowe.

### Dlaczego Symfony Security Component, a nie sam EventListener

Czysty `RequestEvent` listener byłby krótszy, ale:
- task explicitly wymagał konfiguracji firewall'a;
- SecurityBundle daje gotowy mechanizm `stateless: true`, separację per-firewall, hooki na success/failure;
- standardowy Symfony pattern → łatwiejszy dla każdego, kto zna framework;
- migracja do JWT w przyszłości to swap authenticatora, a nie rewrite warstwy auth.

### Dlaczego dwa firewalle, nie access_control

Pierwsza wersja używała jednego firewall'a `^/api` i `access_control` z `^/api → ROLE_API`. Problem: pozostałe trasy (frontend, `/auth/*`) i tak musiały być w jakimś firewall'u, więc i tak potrzebowałem drugiego.

Finalnie:
- `dev` — `_profiler`/assets, `security: false`
- `api` — `^/api`, stateless, custom authenticator
- `main` — wszystko inne, `security: false`

Bez `access_control` w ogóle. Firewall sam wymusza auth dla `/api`.

## Implementacja

### Struktura

```
app/
├── config/
│   ├── packages/security.yaml         # firewall config
│   ├── bundles.php                    # +SecurityBundle
│   └── services.yaml                  # ApiKeyAuthenticator binding
├── src/Security/
│   ├── ApiKeyAuthenticator.php        # custom authenticator
│   ├── ApiUser.php                    # minimal UserInterface
│   └── ApiUserProvider.php            # in-memory single user
├── tests/
│   ├── Integration/Security/
│   │   └── ApiKeyAuthTest.php         # 6 cases (+6 to baseline)
│   └── Support/
│       └── AuthenticatedApiTrait.php  # injects header in tests
├── .env                               # API_KEY= (placeholder)
├── .env.local                         # API_KEY=<real> (gitignored)
└── .env.test                          # API_KEY=test-api-key
```

### Najistotniejsze fragmenty

**Authenticator** (`hash_equals` chroni przed timing attack):
```php
if (!hash_equals($this->expectedApiKey, $providedKey)) {
    throw new CustomUserMessageAuthenticationException('Invalid API key.');
}
```

**Test trait** — `setServerParameter` na KernelBrowser dokleja header do każdego requestu:
```php
$client->setServerParameter('HTTP_X_API_KEY', self::TEST_API_KEY);
```

**security.yaml** — kluczowa idea: `main` z `security: false` łapie wszystko poza `^/api`:
```yaml
firewalls:
    api:    { pattern: ^/api, stateless: true, ... }
    main:   { security: false }
```

## Testy

| plik | scenariusze |
|---|---|
| `ApiKeyAuthTest::testApiRequestWithoutKeyReturns401` | brak headera → 401 |
| `ApiKeyAuthTest::testApiRequestWithInvalidKeyReturns401` | błędna wartość → 401 |
| `ApiKeyAuthTest::testApiRequestWithValidKeyIsAuthorized` | poprawna → nie 401 |
| `ApiKeyAuthTest::testFrontendRouteIsPublic` | `/` → publiczne |
| `ApiKeyAuthTest::testGoogleAuthRouteIsPublic` | `/auth/google` → publiczne |
| `ApiKeyAuthTest::testAuthenticatorHeaderConstantMatchesConvention` | `X-API-Key` literal |

Suite: **225/225 passing** (was 219, +6).
`doctrine:schema:validate` — clean.

## Właściwości bezpieczeństwa

- **Timing-safe comparison:** `hash_equals()` — odporne na timing oracle.
- **Stateless:** brak sesji, brak ciasteczek do wykradnięcia.
- **No-fallback:** brak `expectedApiKey` (puste env) → 401 (nie passthrough).
- **Header transport:** klucz nigdy nie idzie przez query string → nie trafia do logów dostępowych.
- **Gitignored secret:** real key w `.env.local` (`.gitignore` zawiera `.env.local`).
- **No verbose errors:** komunikaty 401 są generyczne ("Missing API key", "Invalid API key") bez ujawniania konfiguracji.

## Świadomie pominięte

| element | dlaczego |
|---|---|
| `POST /auth/token` (login endpoint) | API Key jest statyczny — nie ma flow logowania |
| `POST /auth/refresh` | jw., brak ekspiracji |
| `lexik/jwt-authentication-bundle` | API Key wybrane zamiast JWT |
| Aktualizacja kolekcji Postman | brak pliku w repo — udokumentowane w Confluence Postman page (50823180) jako TODO |
| Rate limiting | poza zakresem HMAI-34 |

## Migracja do JWT (jeśli kiedyś)

1. `composer require lexik/jwt-authentication-bundle`.
2. Wygenerować klucze RSA do `config/jwt/`.
3. Dopisać do `security.yaml`:
   - provider entity z User entity (nowy moduł Auth);
   - firewall `api` swap `custom_authenticators: [ApiKeyAuthenticator]` → `jwt: ~`;
   - nowy firewall `login` z `json_login`.
4. `ApiKeyAuthenticator` można pozostawić jako fallback przez listę authenticatorów albo usunąć.
5. Test trait podmienić: zamiast headera `X-API-Key` generować JWT przez `JWTTokenManager` w setUp.

## Follow-up

- Po merge HMAI-34 — agent zdalny `trig_01Q8MebG257xDW81jccrFgb3` (zaplanowany na 2026-05-02 09:00 Warsaw) wskaże następny P0 blocker.
- Pozostałe P0 z HMAI-44: plaintext OAuth tokens, HTTP w Last.fm, `unserialize()` z Redis, dual-write w `LogReadingSessionHandler`.
- Postman collection: dopisać X-API-Key Pre-request Script przy najbliższej okazji (HMAI-33 follow-up).
