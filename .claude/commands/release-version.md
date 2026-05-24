Tworzy annotated tag oraz GitHub Release dla wersji **$ARGUMENTS** (np. `1.6.0`). Wykonuj kroki ściśle po kolei.

> **Wymóg wejściowy:** `develop` ma zmergowane wszystkie PR-y domykające `fixVersion = $ARGUMENTS`. Sekcja `## [$ARGUMENTS]` w `CHANGELOG.md` i bump `CLAUDE.md` "Wydania" **NIE muszą** być wcześniej zmergowane — od 1.8.0 robimy je bezpośrednio na `develop` jako chore commit w kroku 1.5 tego workflow (precedens zmieniony 2026-05-21, bez mini-PR, bez zgody).
>
> **NIE tworzymy** `release-$ARGUMENTS.md` w root repo — od 1.7.0 porzucone (CHANGELOG wystarczy, niepotrzebna duplikacja).

---

## 1. Sanity check

```bash
git fetch origin
git checkout develop && git pull --ff-only origin develop
git status   # MUSI być clean
git log --oneline -3
```

Zweryfikuj:
- Tag `$ARGUMENTS` jeszcze nie istnieje: `git tag -l $ARGUMENTS` zwraca pusto.
- Poprzedni tag jest znany — `git tag --sort=-v:refname | head -3` (potrzebny do schematu tag message + link `compare/PREV...CURR`).
- Wszystkie PR-y dla `fixVersion = $ARGUMENTS` są zmergowane (`searchJiraIssuesUsingJql`, statusy `Gotowe` poza epikiem który może być `Code Review`).
- `CHANGELOG.md` ma sekcję `## [$ARGUMENTS] — YYYY-MM-DD` jako pierwszą po nagłówku **LUB** przejdź do **kroku 1.5** (nie STOP).
- `CLAUDE.md` ma zaktualizowaną sekcję "Wydania" wskazującą `$ARGUMENTS` jako ostatni tag + counts epików **LUB** przejdź do **kroku 1.5** (nie STOP).

Jeśli tag już istnieje lub PR-y `fixVersion` nie zmergowane — STOP, wypisz co brakuje i poproś o decyzję. Brak CHANGELOG/CLAUDE.md NIE jest blokerem.

---

## 1.5. Pre-release docs (chore commit na develop)

Jeśli brakuje sekcji `## [$ARGUMENTS]` w `CHANGELOG.md` lub `CLAUDE.md` "Wydania" wciąż wskazuje na poprzedni tag — wygeneruj zawartość i commituj **bezpośrednio na develop**. NIE twórz brancha. NIE twórz mini-PR. NIE pytaj o zgodę.

```bash
# 1. Wygeneruj sekcję CHANGELOG (format z `release_notes_schema.md` + poprzednich sekcji)
#    - Intro paragraph (closes epic / partial progress + test counts + PHPStan note)
#    - ### Added / ### Changed / ### Coverage / ### Documentation / ### Migration / ### Closed Jira / ### Carried forward
#    - Sekcja MUSI być pierwsza po nagłówku CHANGELOG.md (przed [PREV])

# 2. Bump CLAUDE.md "Wydania":
#    - "ostatni tag $PREV" → "ostatni tag $ARGUMENTS" z intro tematu
#    - dopisz "$PREV" na początek listy "Poprzednie:"
#    - update counts epików w tabeli jeśli zmienione

# 3. Commit + push
git add CHANGELOG.md CLAUDE.md
git commit -m "chore - changelog and status notes for $ARGUMENTS"
git push origin develop
git log --oneline -3   # ostatni commit = chore — to będzie tag point
```

Po tym commicie wracaj do kroku 2.

**Why ten workflow:** mini-PR dla dwóch docfile'i to ceremoniał bez review value — branch protection na develop nie wymaga PR, a sekcja CHANGELOG jest derywowana z commitów już na develop (`git log PREV..HEAD`). Tworzenie brancha + PR + merge to 3 dodatkowe kroki bez gain'u. Patrz pamięć `feedback_release_changelog_direct_on_develop.md` w `~/.claude/projects/...`.

---

## 2. Zbierz dane do release notes

**Fetch Jira fixVersion:** `searchJiraIssuesUsingJql` z `fixVersion = $ARGUMENTS` (lub `cf[10010] = $ARGUMENTS` jeśli pierwsze pada). Zapamiętaj listę kluczy, statusy, typy (Epic vs Sub-task).

**Identyfikuj główny epik:** zwykle jeden Epic w fixVersion (np. HMAI-126 dla 1.6.0). Jeśli więcej niż jeden epic — będą osobne sekcje 🎯/🛡/🗄/itd. w body.

**Zlicz testy:** wyciągnij z `CHANGELOG.md [$ARGUMENTS]` aktualną liczbę PHP/Playwright/Newman. Z poprzedniego tagu (`git tag -l --format='%(contents)' PREV`) wyciągnij baseline. Dla release'ów bez zmian liczby testów (np. czysty frontend): w intro tagu napisz "no test count change" zamiast `+K new tests`.

**Identyfikuj otwarte epiki** dla sekcji "Not in this release": `searchJiraIssuesUsingJql` z `labels = ai_code_review AND status != Done AND "Epic Link" in (HMAI-128, HMAI-129, HMAI-131, HMAI-132, ...)` — counts per epik.

---

## 3. Annotated tag

Schemat tag message (English, plain text, ~120 linii — wzorzec z tagu `1.5.0` i `1.6.0`):

```
Release $ARGUMENTS — {Theme title in English}

Date: YYYY-MM-DD
Commit: {shortsha} ({commit message})

{Wariant A — epic zamknięty:}
Closes epic {HMAI-XXX} ({theme description}). {N} subtasks
covering {1–2 zdania o zakresie}. ...

{Wariant B — partial batch (epic kontynuowany w next):}
Partial progress on epic {HMAI-XXX} ({theme}). {K} of {N} subtasks
closed in this release covering {scope}. Remaining ({list}) deferred
to {NEXT_VERSION}. ...

{NEW_PHP}/{NEW_PHP} PHP tests + {PW}/{PW} Playwright + {NM}/{NM} Newman
requests — all green. PHPStan level 8 clean (zero new baseline entries).
{Optional tail line — "No domain-model changes — pure X gain." lub podobne}.

{Theme title} (epic HMAI-XXX, {N} subtasks)
  HMAI-XX  {2–4 zdania szczegółów. Wcięcie 11 spacji dla kontynuacji.}
  HMAI-YY  ...

Migration
  1. {Step name} — {command lub jedno zdanie}.
  2. ...

{NEW_PHP}/{NEW_PHP} PHP tests green (vs {PREV_PHP}/{PREV_PHP} at
{PREV_TAG}) — +{DELTA} new across {short summary breakdown}.
PHPStan level 8 clean (zero new baseline entries). CS Fixer and
Rector dry-run both green.

Not in this release
  Still open under ai_code_review: HMAI-AAA ({theme}, {count}),
  HMAI-BBB ({theme}, {count}), ...

Full upgrade notes: see CHANGELOG.md section [$ARGUMENTS].
```

Stwórz i pchnij:

```bash
git tag -a $ARGUMENTS {commit_sha} -m "$(cat <<'EOF'
{wklejony schemat powyżej, z podstawionymi wartościami}
EOF
)"
git show $ARGUMENTS --no-patch --format='%h %s'   # sanity
git push origin $ARGUMENTS
```

---

## 4. GitHub Release body — w schemacie 1.3.0/1.5.0

Zapisz body do **tymczasowego** pliku `release-$ARGUMENTS-github.md` w root repo. **NIE commituj go** — usunięcie w kroku 6.

Schemat body (Markdown, EN, z `release_notes_schema.md` w persistent memory). Intro paragraph (scope + test counts) jest **opcjonalny** — jeśli user wprost powie "intro nie jest potrzebne" albo jeśli release jest trywialny w narrative (np. partial-epic batch), pomiń intro paragraph i zacznij od **Date:**/**Commit:** block:

```markdown
{Opcjonalny intro paragraph — patrz wyżej.}

**Date:** YYYY-MM-DD
**Commit:** [`{shortsha}`](https://github.com/zlotylesk/AIHomeManager/commit/{shortsha}) — `{commit message}`

---

## {emoji} {Theme} (epic [HMAI-XXX](link))

| ID | Change |
| --- | --- |
| [HMAI-NN](link) | {Inline `code` for file/symbol names. 1–3 zdania.} |
| ... |

---

## ⚠️ Migration steps (post-deploy)

1. **{Step}** — opis; komendy w fenced code blocks.
2. ...

No `.env.local` keys required by this release. {Lub: lista nowych ENV.} No destructive DB ops. {Lub: TRUNCATE/DROP warning.}

---

## Not in this release

Still open under `ai_code_review` (counts after $ARGUMENTS closure): [HMAI-AAA](link) ({theme}, {count}), [HMAI-BBB](link) ({theme}, {count}), ...

**Full changelog:** https://github.com/zlotylesk/AIHomeManager/compare/{PREV}...$ARGUMENTS
```

**Emoji per epic** (z `release_notes_schema.md`):
🚦 rate limiting · 🛡 security/hardening/resilience · 🔒 encryption/auth · 🧪 tests/E2E · ✅ coverage · 🛠 CI/tooling · 🗄 persistence/DB · 🎨 frontend · 🧹 cleanup/refactor · 🏗 foundation/infrastructure/operability

**Hard rules** (z `release_notes_schema.md`):
- Title format: `Release X.Y.Z — Theme` (zawsze "Release" + em-dash `—`, NIE myślnik `-`)
- Prose w EN, nawet jeśli commits PL
- Każdy ticket w wierszu tabeli, nigdy bullet
- `**Date:**` + `**Commit:**` jako bold two-line block przed pierwszym `---`
- Liczby testów w intro **bold** (zarówno absolutne jak +delta)
- Final line zawsze `**Full changelog:** https://github.com/.../compare/PREV...CURR`
- "Not in this release" — pojedynczy paragraf z counts w nawiasach, nigdy bulleted list

---

## 5. Publikacja Release przez REST API

`gh` CLI nie jest dostępne; GitHub MCP nie ma `create_release`. Użyj PAT z `.env.local` (klucz `GITHUB_PERSONAL_ACCESS_TOKEN`).

**PowerShell (Windows):**

```powershell
$pat = ([IO.File]::ReadAllText(".env.local") -split "`n" | Where-Object { $_ -match "^GITHUB_PERSONAL_ACCESS_TOKEN=" }) -replace "^GITHUB_PERSONAL_ACCESS_TOKEN=", "" -replace "`r", ""

# 1. test PAT
$me = Invoke-RestMethod -Uri "https://api.github.com/user" -Headers @{Authorization="Bearer $pat"; "User-Agent"="claude-code"}
"auth OK as: $($me.login)"

# 2. POST release — UTF-8 bytes + forced string body (kluczowe — bez tego em-dash i emoji się sypią + Get-Content -Raw wraps w PSObject)
$bodyText = [IO.File]::ReadAllText("release-$ARGUMENTS-github.md", [System.Text.Encoding]::UTF8)
$payloadObj = [pscustomobject]@{
  tag_name = "$ARGUMENTS"
  name = "Release $ARGUMENTS — {Theme}"
  body = $bodyText
  draft = $false
  prerelease = $false
  make_latest = "true"
}
$payloadBytes = [System.Text.Encoding]::UTF8.GetBytes(($payloadObj | ConvertTo-Json -Depth 3 -Compress))
$r = Invoke-RestMethod -Uri "https://api.github.com/repos/zlotylesk/AIHomeManager/releases" `
     -Method Post `
     -Headers @{Authorization="Bearer $pat"; "User-Agent"="claude-code"; Accept="application/vnd.github+json"} `
     -ContentType "application/json; charset=utf-8" `
     -Body $payloadBytes
"Created: $($r.html_url)"
```

**Pułapki (sprawdzone na 1.6.0):**
- `Get-Content -Raw` zwraca PSObject — `ConvertTo-Json` opakowuje cały obiekt zamiast użyć tekstu. Użyj `[IO.File]::ReadAllText`.
- Body bez `-Body $bytes` (samym `-Body $string`) leci jako CP1250/Win-encoded → em-dash `—` ląduje jako `â€"` w response. Zawsze koduj do bytes.
- `make_latest` to string `"true"`/`"false"`, nie boolean. GitHub spec wymaga string.

**Weryfikacja przez API (WebFetch ma 15-min cache, omijaj):**

```powershell
$r = Invoke-RestMethod -Uri "https://api.github.com/repos/zlotylesk/AIHomeManager/releases/tags/$ARGUMENTS" -Headers @{Authorization="Bearer $pat"; "User-Agent"="claude-code"}
"name: $($r.name)"
"body chars: $($r.body.Length)"
"🏗 OK: $($r.body.Contains('🏗'))"
"⚠️ OK: $($r.body.Contains('⚠️'))"
"table OK: $($r.body.Contains('| --- | --- |'))"
"em-dash OK: $($r.body.Contains('—'))"
```

---

## 6. Cleanup

```bash
rm release-$ARGUMENTS-github.md   # NIE commituj go
ls release-*.md                    # MUSI zostać tylko historyczne release-1.6.0.md i wcześniejsze (nowych nie tworzymy)
git status                         # clean
```

---

## 7. Master fast-forward

```bash
git checkout master
git merge --ff-only develop
git push origin master
git checkout develop
```

Jeśli `--ff-only` pada — STOP. Rozbieżność master vs develop wymaga decyzji usera, nie auto-merge.

---

## 8. Co zostaje po Tobie do zrobienia ręcznie

Wypisz userowi (lokalny PAT NIE może):
1. **Jira:** Project HMAI → Releases → `$ARGUMENTS` → **Release** (releaseDate = dzisiejsza).
2. **Confluence (opcjonalnie):** jeśli release zamyka epic z dużą zmianą architektoniczną, zaktualizuj odpowiednią stronę (epic review zwykle aktualizuje stronę modułu).

---

## Linki referencyjne

- Schemat tag message: `git tag -l --format='%(contents)' 1.6.0` lub `1.7.0` (1.7.0 ma wariant "Partial progress on epic" dla batch releasów).
- Schemat release body: persistent memory `release_notes_schema.md` (auto-loaded przez MEMORY.md).
- `release-X.Y.Z.md` w root repo: porzucony od 1.7.0 — historyczne pliki (`release-1.6.0.md` i wcześniejsze) zostają, nowych nie tworzymy.
- Repo: `zlotylesk/AIHomeManager`.