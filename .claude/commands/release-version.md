Tworzy annotated tag oraz GitHub Release dla wersji **$ARGUMENTS** (np. `1.6.0`). Wykonuj kroki ściśle po kolei.

> **Wymóg wejściowy:** `develop` ma zmergowane wszystkie PR-y domykające `fixVersion = $ARGUMENTS`. Sekcja `## [$ARGUMENTS]` w `CHANGELOG.md` i bump `CLAUDE.md` "Wydania" **NIE muszą** być wcześniej zmergowane — robimy je w kroku 1.5 tego workflow.
>
> **NIE tworzymy** `release-$ARGUMENTS.md` w root repo — od 1.7.0 porzucone (CHANGELOG wystarczy, niepotrzebna duplikacja).
>
> **⚠️ KOLEJNOŚĆ JEST KRYTYCZNA — tag powstaje PO synchronizacji mastera, nie przed.** Tag wskazuje na konkretny **obiekt commita (SHA)**, a rebase nie przesuwa commitów, tylko tworzy nowe (inny rodzic → inny hash, mimo identycznego drzewa). Jeśli otagujesz `develop` przed syncem, rebase-merge do `master` zrobi z tagowanego commita sierotę: tag przestanie być osiągalny z jakiejkolwiek gałęzi, `git describe` go nie zobaczy, a GitHub nie pokaże go na liście commitów. Tak osierociały tagi `1.24.0`, `1.25.0` i `1.26.0` (naprawione ręcznie 2026-07-21). Dlatego **sync mastera to krok 3, a tag krok 4** — tag zakłada się na końcu, po ostatnim przepisaniu historii.

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

## 1.5. Pre-release docs (chore PR na develop)

Jeśli brakuje sekcji `## [$ARGUMENTS]` w `CHANGELOG.md` lub `CLAUDE.md` "Wydania" wciąż wskazuje na poprzedni tag — wygeneruj zawartość i wypuść jako **chore PR** na brancha `chore-changelog-$ARGUMENTS`.

```bash
git checkout -b chore-changelog-$ARGUMENTS

# 1. Wygeneruj sekcję CHANGELOG (format z `release_notes_schema.md` + poprzednich sekcji)
#    - Intro paragraph (closes epic / partial progress + test counts + PHPStan note)
#    - ### Added / ### Changed / ### Coverage / ### Documentation / ### Migration / ### Closed Jira / ### Carried forward
#    - Sekcja MUSI być pierwsza po nagłówku CHANGELOG.md (przed [PREV])

# 2. Bump CLAUDE.md "Wydania":
#    - "ostatni tag $PREV" → "ostatni tag $ARGUMENTS" z intro tematu
#    - dopisz "$PREV" na początek listy "Poprzednie:"
#    - update counts epików w tabeli jeśli zmienione

# 3. Commit + PR
git add CHANGELOG.md CLAUDE.md
git commit -m "chore - changelog and status notes for $ARGUMENTS"
git push -u origin chore-changelog-$ARGUMENTS
gh pr create --base develop --title "chore - changelog and status notes for $ARGUMENTS" --body "..."
# zielone CI → merge
gh pr merge <NR> --rebase --delete-branch

git checkout develop && git pull --ff-only origin develop
git log --oneline -3   # ostatni commit = chore
```

**Uwaga:** ten commit **nie** jest jeszcze punktem tagowania — tagujemy dopiero jego kopię, która wyląduje na `master` w kroku 3. Ochrona gałęzi została zdjęta (2026-07-21), więc technicznie dałoby się pchnąć wprost na `develop`, ale trzymamy PR-y dla spójnego trailu i przebiegu CI.

Po zmergowaniu wracaj do kroku 2.

---

## 2. Zbierz dane do release notes

**Fetch Jira fixVersion:** `searchJiraIssuesUsingJql` z `fixVersion = $ARGUMENTS` (lub `cf[10010] = $ARGUMENTS` jeśli pierwsze pada). Zapamiętaj listę kluczy, statusy, typy (Epic vs Sub-task).

**Identyfikuj główny epik:** zwykle jeden Epic w fixVersion (np. HMAI-126 dla 1.6.0). Jeśli więcej niż jeden epic — będą osobne sekcje 🎯/🛡/🗄/itd. w body.

**Zlicz testy:** wyciągnij z `CHANGELOG.md [$ARGUMENTS]` aktualną liczbę PHP/Playwright/Newman. Z poprzedniego tagu (`git tag -l --format='%(contents)' PREV`) wyciągnij baseline. Dla release'ów bez zmian liczby testów (np. czysty frontend): w intro tagu napisz "no test count change" zamiast `+K new tests`.

**Identyfikuj otwarte epiki** dla sekcji "Not in this release": `searchJiraIssuesUsingJql` z `labels = ai_code_review AND status != Done AND "Epic Link" in (HMAI-128, HMAI-129, HMAI-131, HMAI-132, ...)` — counts per epik.

---

## 3. Sync master + wyrównanie develop (PRZED tagiem)

Ten krok ustala commit, który stanie się punktem tagowania. Po nim historia nie jest już przepisywana.

```bash
# 3a. PR develop → master
gh pr create --base master --head develop --title "Release $ARGUMENTS — sync master" --body "..."
# zielone CI (5 jobów) → merge REBASE-em.
# NIGDY --delete-branch: head brancha to develop!
gh pr merge <NR> --rebase

# 3b. Wyrównaj develop do mastera — TO JEST OBOWIĄZKOWE
git fetch origin
git checkout develop
git rebase origin/master        # commity znikają jako "previously applied" (patch-id)
git push --force-with-lease origin develop

# 3c. Weryfikacja — obie gałęzie MUSZĄ wskazywać na ten sam commit
git rev-parse origin/master origin/develop   # dwa identyczne SHA
```

**Dlaczego 3b nie jest opcjonalne:** rebase-merge odtwarza commity na masterze pod **nowymi SHA**, a `develop` trzyma oryginały. Bez rebase'u wracającego obie gałęzie niosą tę samą pracę pod różnymi SHA — i każdy kolejny sync się wykłada konfliktami (tak narastało od 1.24.0 do 1.26.0, patrz PR #399/#401 zamknięte konfliktowo).

**Merge commit NIE jest rozwiązaniem** — decyzja ownera 2026-07-21: wyrównania robimy wyłącznie rebasem, master i develop zostają liniowe.

Jeśli `git rebase origin/master` sypie konfliktami — STOP i pokaż userowi. Czysty rebase pomija już zastosowane commity po patch-id; konflikt oznacza realną rozbieżność treści, nie samych SHA.

Zapamiętaj SHA z kroku 3c — to `{commit_sha}` dla tagu.

---

## 4. Annotated tag (na commicie z kroku 3c)

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

`Commit:` w treści tagu MUSI wskazywać SHA z kroku 3c (commit na `master`), nie stary SHA z develop sprzed rebase'u.

Stwórz i pchnij:

```bash
git tag -a $ARGUMENTS {commit_sha} -m "$(cat <<'EOF'
{wklejony schemat powyżej, z podstawionymi wartościami}
EOF
)"
git show $ARGUMENTS --no-patch --format='%h %s'   # sanity
git push origin $ARGUMENTS
```

**Weryfikacja osiągalności — obowiązkowa:**

```bash
git merge-base --is-ancestor $ARGUMENTS origin/master  && echo "tag on master OK"
git merge-base --is-ancestor $ARGUMENTS origin/develop && echo "tag on develop OK"
git describe --tags origin/master    # MUSI zwrócić dokładnie $ARGUMENTS
```

Jeśli któreś padnie — tag wylądował na sierocie; wróć do kroku 3 zamiast publikować Release.

**Naprawa osieroconego tagu (gdyby kolejność kiedyś się rozjechała):** znajdź na `master` commit o identycznym drzewie (`git rev-parse $TAG^{tree}`, potem skan `git rev-list origin/master`), odtwórz tag z oryginalną treścią i datą tagowania (`git tag -l --format='%(contents)' $TAG > msg`, popraw w niej linię `Commit:`, `GIT_COMMITTER_DATE="<oryginalna data>" git tag -f -a $TAG <nowy_sha> -F msg`) i `git push --force origin refs/tags/$TAG`. GitHub Release podąża za tagiem sam — `target_commitish` i `published_at` zostają nietknięte.

---

## 5. GitHub Release body — w schemacie 1.3.0/1.5.0

Zapisz body do **tymczasowego** pliku `release-$ARGUMENTS-github.md` w root repo. **NIE commituj go** — usunięcie w kroku 7.

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

## 6. Publikacja Release

`gh` CLI **jest** dostępne (`gh release create $ARGUMENTS --notes-file release-$ARGUMENTS-github.md --title "Release $ARGUMENTS — {Theme}" --latest`) i to jest najprostsza droga. Poniższy wariant REST zostaje jako fallback, gdyby `gh` nie miał uprawnień — PAT z `.env.local` (klucz `GITHUB_PERSONAL_ACCESS_TOKEN`).

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

## 7. Cleanup

```bash
rm release-$ARGUMENTS-github.md   # NIE commituj go
ls release-*.md                    # MUSI zostać tylko historyczne release-1.6.0.md i wcześniejsze (nowych nie tworzymy)
git status                         # clean
```

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
