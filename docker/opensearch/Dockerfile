# App-data search engine image (HMAI-362, epic HMAI-359).
#
# The stock opensearchproject/opensearch image ships no language-analysis
# plugins, and OpenSearch has no built-in Polish stemmer — the `stemmer` filter's
# language list covers ~30 languages, Polish is not among them. Polish
# morphology lives in `analysis-stempel` (Apache-2.0, Lucene's Stempel
# algorithmic stemmer), which also brings the `polish_stop` stopword set.
#
# Without it the index could only lowercase and fold, so "książkami" would never
# match "książka" — the search would be measurably worse than what the epic set
# out to replace. Hence a one-line derived image rather than a compromise on the
# analyzer.
#
# The base tag stays `:2` to match the monitoring OpenSearch and the CI service
# (Graylog 6.3 supports OpenSearch 1.1-2.19.5 only); the plugin installer
# resolves the plugin build that matches the running distribution, so the two
# cannot drift apart.
FROM opensearchproject/opensearch:2

RUN /usr/share/opensearch/bin/opensearch-plugin install --batch analysis-stempel
