#!/usr/bin/env bash
set -euo pipefail

GRAYLOG_URL="${GRAYLOG_URL:-http://localhost:9000}"
GRAYLOG_USER="${GRAYLOG_USER:-admin}"
GRAYLOG_PASS="${GRAYLOG_PASS:-admin}"
AUTH="$GRAYLOG_USER:$GRAYLOG_PASS"

api() {
    curl -sf -u "$AUTH" -H 'Content-Type: application/json' -H 'X-Requested-By: graylog-bootstrap' "$@"
}

echo "==> Waiting for Graylog API..."
until api "$GRAYLOG_URL/api/system/cluster" > /dev/null 2>&1; do
    sleep 3
done
echo "    Graylog API is ready."

# --- GELF UDP Input ---
INPUT_TITLE="GELF UDP"
EXISTING_INPUT=$(api "$GRAYLOG_URL/api/system/inputs" | grep -c "\"title\":\"$INPUT_TITLE\"" || true)
if [ "$EXISTING_INPUT" -eq 0 ]; then
    echo "==> Creating GELF UDP input..."
    api "$GRAYLOG_URL/api/system/inputs" -X POST -d '{
        "title": "'"$INPUT_TITLE"'",
        "type": "org.graylog2.inputs.gelf.udp.GELFUDPInput",
        "configuration": {
            "bind_address": "0.0.0.0",
            "port": 12201,
            "recv_buffer_size": 1048576
        },
        "global": true
    }' > /dev/null
    echo "    Created."
else
    echo "    GELF UDP input already exists, skipping."
fi

# --- Index Sets ---
create_index_set() {
    local title="$1" prefix="$2" rotation_strategy="$3" rotation_config="$4" retention_count="$5"

    EXISTING=$(api "$GRAYLOG_URL/api/system/indices/index_sets" | grep -c "\"title\":\"$title\"" || true)
    if [ "$EXISTING" -eq 0 ]; then
        echo "==> Creating index set: $title..."
        api "$GRAYLOG_URL/api/system/indices/index_sets" -X POST -d '{
            "title": "'"$title"'",
            "description": "Created by graylog-bootstrap.sh (HMAI-142)",
            "index_prefix": "'"$prefix"'",
            "shards": 1,
            "replicas": 0,
            "rotation_strategy_class": "'"$rotation_strategy"'",
            "rotation_strategy": '"$rotation_config"',
            "retention_strategy_class": "org.graylog2.indexer.retention.strategies.DeletionRetentionStrategy",
            "retention_strategy": { "type": "org.graylog2.indexer.retention.strategies.DeletionRetentionStrategyConfig", "max_number_of_indices": '"$retention_count"' },
            "index_analyzer": "standard",
            "index_optimization_max_num_segments": 1,
            "index_optimization_disabled": false,
            "field_type_refresh_interval": 5000,
            "writable": true,
            "default": false
        }' > /dev/null
        echo "    Created."
    else
        echo "    Index set '$title' already exists, skipping."
    fi
}

create_index_set "auth-events" "auth_events" \
    "org.graylog2.indexer.rotation.strategies.TimeBasedRotationStrategy" \
    '{ "type": "org.graylog2.indexer.rotation.strategies.TimeBasedRotationStrategyConfig", "rotation_period": "P1D", "max_rotation_period": null, "rotate_empty_index_set": false }' \
    90

create_index_set "series-events" "series_events_log" \
    "org.graylog2.indexer.rotation.strategies.TimeBasedRotationStrategy" \
    '{ "type": "org.graylog2.indexer.rotation.strategies.TimeBasedRotationStrategyConfig", "rotation_period": "P1D", "max_rotation_period": null, "rotate_empty_index_set": false }' \
    30

# --- Streams ---
create_stream() {
    local title="$1" index_set_title="$2" field="$3" value="$4"

    EXISTING=$(api "$GRAYLOG_URL/api/streams" | grep -c "\"title\":\"$title\"" || true)
    if [ "$EXISTING" -eq 0 ]; then
        INDEX_SET_ID=$(api "$GRAYLOG_URL/api/system/indices/index_sets" | \
            grep -o '"id":"[^"]*","title":"'"$index_set_title"'"' | \
            head -1 | grep -o '"id":"[^"]*"' | cut -d'"' -f4)

        if [ -z "$INDEX_SET_ID" ]; then
            echo "    ERROR: Index set '$index_set_title' not found, cannot create stream '$title'."
            return 1
        fi

        echo "==> Creating stream: $title (index set: $INDEX_SET_ID)..."
        STREAM_ID=$(api "$GRAYLOG_URL/api/streams" -X POST -d '{
            "title": "'"$title"'",
            "description": "Created by graylog-bootstrap.sh (HMAI-142)",
            "index_set_id": "'"$INDEX_SET_ID"'",
            "rules": [{
                "field": "'"$field"'",
                "value": "'"$value"'",
                "type": 1,
                "inverted": false
            }],
            "remove_matches_from_default_stream": true
        }' | grep -o '"stream_id":"[^"]*"' | cut -d'"' -f4)

        if [ -n "$STREAM_ID" ]; then
            api "$GRAYLOG_URL/api/streams/$STREAM_ID/resume" -X POST > /dev/null
            echo "    Created and started (id: $STREAM_ID)."
        fi
    else
        echo "    Stream '$title' already exists, skipping."
    fi
}

create_stream "auth" "auth-events" "channel" "auth"
create_stream "series" "series-events" "channel" "series"

echo ""
echo "=== Graylog bootstrap complete ==="
echo "Retention policy:"
echo "  auth-events:   90 daily indices (time-based rotation)"
echo "  series-events: 30 daily indices (time-based rotation)"
echo "  default:       managed by Graylog defaults"
