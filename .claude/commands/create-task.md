Tworzy zadanie Jira w projekcie **HMAI** z opisu w języku ludzkim: **$ARGUMENTS**. Wykonuj kroki ściśle po kolei.

> **Cel:** przekuć jedno zdanie (`$ARGUMENTS`) w pełnoprawne, gotowe-do-pracy zadanie Jira o spójnej strukturze. Nie zakładaj kluczowych decyzji — gdy czegoś nie wiesz, **dopytaj** (`AskUserQuestion`) zanim utworzysz ticket. Lepiej 1–4 pytania niż źle wypełniona sekcja.

> **Cloud ID:** `0579d404-bf72-42dd-a5af-975a36fbb84d` (honemanager). Projekt: `HMAI`. Typ: `Zadanie`. Wszystkie wywołania Jira przez Atlassian Rovo MCP.

---

## 1. Zrozum intencję

Przeczytaj `$ARGUMENTS` i ustal **co** użytkownik chce osiągnąć i **dlaczego**. Jeśli zdanie jest ogólne/jednozdaniowe, zbierz braki potrzebne do wypełnienia sekcji z Kroku 4 — przez `AskUserQuestion` (zakres, zachowanie brzegowe, gdzie w UI/API/domenie, co jest poza zakresem). Nie zgaduj. Skorzystaj z wiedzy o repo (CLAUDE.md, istniejący kod), żeby pytania były celne, a nie ogólnikowe.

## 2. fixVersion — ZAWSZE dopytaj (domyślnie najnowsza otwarta)

Pobierz wersje projektu HMAI i odfiltruj **otwarte** (`released = false`, `archived = false`). Zaproponuj **najnowszą otwartą fixVersion jako domyślną** (pierwsza opcja, oznacz „(domyślna)") i zapytaj `AskUserQuestion`, dając też pozostałe otwarte wersje do wyboru.

- REST: `GET /rest/api/3/project/HMAI/versions` (lub `getJiraIssue` na świeżym tickecie HMAI po `fields.fixVersions` jako fallback). Filtruj po stronie modelu.

## 3. Epik — ZAWSZE dopytaj, z listą otwartych

Pobierz **otwarte epiki** HMAI i przedstaw je jako listę wyboru (`AskUserQuestion`). Dodaj opcję „brak epiku (samodzielne zadanie)".

- JQL: `project = HMAI AND issuetype = Epik AND statusCategory != Done ORDER BY key DESC`, `fields: ["summary", "status"]`.

## 4. Zbuduj opis — ZAWSZE dokładnie te sekcje (markdown)

Opis zadania **musi** mieć te pięć nagłówków, w tej kolejności, każdy z konkretną treścią:

```
## Cel zadania
{co konkretnie ma powstać — 1–3 zdania, rzeczowo}

## Background biznesowy
{dlaczego to robimy — potrzeba użytkownika/biznesu, kontekst, motywacja}

## Stan początkowy
{jak jest teraz — obecne zachowanie/braki, punkt wyjścia w kodzie/UI/danych}

## Stan końcowy
{jak ma być po wdrożeniu — docelowe zachowanie z perspektywy użytkownika}

## Kryteria akceptacji
- {sprawdzalny, jednoznaczny warunek 1}
- {sprawdzalny warunek 2}
- {…}
```

Wypełniaj konkretami z `$ARGUMENTS` + odpowiedzi z Kroków 1–3. **Żadnej sekcji nie zostawiaj pustej ani ogólnikowej** — jeśli nie masz treści, wróć do Kroku 1 i dopytaj. Kryteria akceptacji mają być testowalne (dają się przełożyć na test/QA).

Gdy zadanie jest techniczne i znasz repo, dołącz po Kryteriach krótką sekcję pomocniczą (np. `## Wskazówki techniczne` z tabelą plik→zmiana) — opcjonalnie, nie zastępuje pięciu sekcji obowiązkowych.

## 5. Utwórz zadanie

`createJiraIssue`:
- `cloudId`: honemanager, `projectKey: HMAI`, `issueTypeName: Zadanie`, `contentFormat: markdown`
- `summary`: zwięzły, rzeczowy tytuł (PL), wyprowadzony z Celu zadania
- `parent`: klucz wybranego epiku (pomiń, gdy „brak epiku")
- `additional_fields`: `{ "fixVersions": [{ "name": "{wybrana wersja}" }] }`
- `description`: opis z Kroku 4

## 6. Potwierdź

`getJiraIssue` (`fields: ["summary","parent","fixVersions","status"]`) — zweryfikuj, że parent i fixVersion faktycznie się zapisały (create response ich nie odbija). Zwróć użytkownikowi klucz + link `https://honemanager.atlassian.net/browse/{KEY}` i zaproponuj `/start-task {KEY}` jako następny krok.
