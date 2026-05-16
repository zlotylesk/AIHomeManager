# Postman / Newman collection

Pelnoekosystemowy test API uruchamiany przez Newman CLI. Pokrywa Series, Tasks (time-report), Books, Music, Articles oraz OAuth startery dla Google i Discogs. Implementuje akceptacje HMAI-33.

## Wymagania

- Stack uruchomiony: `make up`
- `API_KEY=e2e-test-key` w `app/.env.local`
- Graylog GELF UDP input skonfigurowany (patrz CLAUDE.md → sekcja Testy E2E pre-req)
- Discogs/Last.fm — placeholders w `.env.local` wystarcza (testy toleruja 503), realne klucze tylko gdy chcesz pelny happy-path

## Uruchomienie

```
make test-newman-install   # jednorazowo, instaluje newman z package.json
make test-newman           # truncate + newman run
```

Lub bezposrednio:

```
npx newman run tests-e2e/postman/AIHomeManager.postman_collection.json --ignore-redirects
```

`--ignore-redirects` jest niezbedne dla testow OAuth — bez niego newman podaza za 302 do `accounts.google.com` i widzi 200 zamiast 302.

## Stan na 2026-05-16

28 requestow, 42 asercje, 100% zielone (BN API + Discogs OAuth zwracaja 503/502 — toleruja to asercje).

Pominiete vs. spec Confluence (oryginalnie 37 req):

- **Tasks CRUD (7 req: POST/GET/PUT/PATCH/DELETE single)** — endpointy nie istnieja, sledzone w [HMAI-43](https://honemanager.atlassian.net/browse/HMAI-43)
- **2 req Books dependent** — automatycznie skipowane przez prerequest gdy `book_id` puste (POST ISBN zwrocil 503 bo National Library API jest nieosiagalne z kontenera)

## Asercje tolerujace brak srodowiska

| Endpoint                     | Akceptowane                                                       |
| ---------------------------- | ----------------------------------------------------------------- |
| `POST /books` (ISBN)         | `201` (BN OK) lub `503` (BN unreachable)                          |
| `POST /books` (zly ISBN)     | `422` (walidacja) lub `503` (BN unreachable przed walidacja)      |
| `GET /music/*`               | `200` (realne klucze) lub `503` (placeholder klucze w .env.local) |
| `GET /auth/discogs`          | `302` (realne consumer key/secret) lub `502` (Discogs unreachable)|

## Struktura

Auth na poziomie kolekcji to API key w naglowku `X-API-Key`, wartosc ze zmiennej `api_key` (default `e2e-test-key`). Folder Auth nadpisuje na `noauth` poniewaz `/auth/*` jest poza firewall'em `^/api/*`.

Skrypty test-script ustawiaja `series_id`, `season_id`, `episode_id`, `book_id`, `article_id`, `today_article_id` jako collection variables w trakcie biegu. Kolejnosc requestow ma znaczenie — nie restartuj iteracji.
