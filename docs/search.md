# Global search

Search runs on one of two interchangeable backends behind the same domain port,
selected by `SEARCH_ENGINE_BACKEND`:

| Value | Backend | What you get |
|---|---|---|
| `opensearch` (current) | OpenSearch 2.x via `app.search_client` | Polish stemming (`analysis-stempel`), diacritic-free matching, typo tolerance, per-type facets |
| `fulltext` | MySQL `search_documents` + `MATCH … AGAINST` | No extra service. Title boosting and per-type facets, no typo tolerance. The rollback target, and the automatic fallback |

The flag is read by a **factory**, not a container alias — an alias is resolved
at compile time and cannot read an environment variable — so switching backends
is a restart, not a deploy. An unknown value is rejected at boot rather than
silently defaulted: an operator who mistypes `opensarch` would otherwise never
learn the switch did nothing.

The OpenSearch image is **built** from `docker/opensearch`, not pulled. The stock
image ships no Polish analysis, and OpenSearch has no built-in Polish stemmer,
so `analysis-stempel` is installed on top. Without it the index could only
lowercase and fold.

## Provisioning and filling the index

```bash
make search-index       # create the index + alias if missing (idempotent)
make search-reindex     # rebuild into a new index and move the alias atomically
make search-populate    # fill the active backend's index from the source modules
```

Everything addresses the index through the **alias** (`SEARCH_INDEX_ALIAS`,
default `search_documents`), never a physical index name — mappings are largely
immutable once a field exists, so a schema change means building a new index and
moving the alias. `make search-index` is the search-side counterpart of
`doctrine:migrations:migrate`: run it on deploy, it is safe every time.

Beyond that the index maintains itself. The Scheduler rebuilds it every 15
minutes, and entity changes that emit domain events are indexed incrementally
within seconds. The 15-minute rebuild is what keeps the modules that emit no
domain events at all current.

## Cutover and rollback

The order matters. Flipping the flag before the index is filled leaves search
answering from an empty engine — and thanks to the fallback, doing so *quietly*.

```bash
# 1. Build the index the new backend will read (idempotent).
make search-index

# 2. Fill it from the source modules; check the count against what you expect.
make search-populate

# 3. Validate relevance while still serving the old backend — the engine is
#    readable before anything reads from it in anger.
curl -s -H "X-API-Key: $API_KEY" 'http://localhost:8080/api/v1/search?q=<a phrase you know the answer to>'

# 4. Flip the flag and restart: SEARCH_ENGINE_BACKEND=opensearch in .env.local
docker compose restart php messenger_worker scheduler_worker

# 5. Observe. Any degrade is logged as a warning.
docker compose logs php | grep 'Search degraded to the fallback engine'
```

**Rollback is one line and needs no data work:** set
`SEARCH_ENGINE_BACKEND=fulltext` and restart. The MySQL index was never allowed
to go stale — the dual write keeps it current for exactly this moment — so
FULLTEXT answers immediately with the same response shape, only ranked less
well. Nothing has to be rebuilt, re-migrated or re-synced, which is the reason
the flag is worth keeping rather than deleting the old backend on cutover day.

Worth knowing while observing step 5: a degrade does **not** wait for a
rollback. If the engine becomes unreachable the fallback already serves FULLTEXT
automatically. The rollback is for the other case — the engine is *up* but
wrong: bad relevance, a broken mapping, a half-finished reindex — which no
automatic mechanism can detect for you.

## What an engine outage costs

OpenSearch is treated as **optional infrastructure**, and three mechanisms keep
it that way:

- **Reads degrade, they do not fail.** The engine is wrapped in a fallback to
  MySQL FULLTEXT: an unreachable engine costs relevance (no typo tolerance,
  weaker ranking) but still answers, logging a `warning` per degraded query.
  Only a failure of *both* backends surfaces as an error — reporting "no
  results" while search is broken would be a lie about the library's contents.
- **The standby index stays current.** Selecting `opensearch` also turns on a
  dual write, so the MySQL `search_documents` table keeps being maintained.
  Without it the table would freeze the day the flag was flipped, and the
  fallback would serve a months-old library — plausible, wrong, and far harder
  to notice than an error.
- **Health reports `degraded`, not `down`.** `GET /api/health` returns HTTP 200
  with `search: degraded` when the engine is unreachable, so an orchestrator
  does not pull a working instance out of rotation over a search outage.

## Recovery

**The index is not backed up — it is rebuilt.** Every document in it is derived
from the source module tables, so the MySQL backup already contains everything
needed; an engine snapshot would only add a second thing to keep in sync.

```bash
make search-index       # recreate the index + alias
make search-populate    # refill from the source modules
```

Search stays available throughout: the rebuild is mark-and-sweep — documents are
upserted, then anything not touched by the run is deleted — so the index answers
queries the whole time rather than going empty for the duration. If the engine
is gone entirely, set `SEARCH_ENGINE_BACKEND=fulltext` and restart; search keeps
working on MySQL while the engine is rebuilt.

An index literally *named* like the alias blocks provisioning. `make
search-index` refuses it with the fix in the message rather than passing on
OpenSearch's uninformative "invalid alias name".

## Growth and retention

The index holds one document per searchable entity, not a history — its size
tracks the size of the library, not the passage of time, and the 15-minute
rebuild sweeps documents whose source rows are gone. There is nothing to prune
on a schedule. The one thing worth watching is a **superseded index** left
behind by an interrupted `make search-reindex`; a completed reindex removes it
itself.
