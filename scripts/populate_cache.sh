#!/usr/bin/env bash
# Populate TagCache with massive random data via HTTP API.
# Usage: ./scripts/populate_cache.sh http://localhost:8080 USER PASS 5000
set -euo pipefail
BASE_URL=${1:-http://localhost:8080}
USER=${2:-${TC_USER:-}}
PASS=${3:-${TC_PASS:-}}
COUNT=${4:-5000}
if [[ -z "$USER" || -z "$PASS" ]]; then
  if [[ -f credential.txt ]]; then
    USER=$(grep '^username=' credential.txt | cut -d'=' -f2)
    PASS=$(grep '^password=' credential.txt | cut -d'=' -f2)
  fi
fi
if [[ -z "$USER" || -z "$PASS" ]]; then
  echo "Provide USER PASS or have credential.txt present" >&2; exit 1
fi
TOKEN=$(curl -s -X POST -H "Authorization: Basic $(printf '%s:%s' "$USER" "$PASS" | base64)" -H 'Content-Type: application/json' \
  -d "{\"username\":\"$USER\",\"password\":\"$PASS\"}" "$BASE_URL/auth/login" | jq -r '.token')
if [[ "$TOKEN" == "null" || -z "$TOKEN" ]]; then echo "Failed to obtain token" >&2; exit 1; fi

echo "Inserting $COUNT keys into $BASE_URL" >&2
TAGS=(alpha beta gamma delta epsilon finance user session feature hot cold cache api web mobile rust js long short dev prod blue green purple orange)
for ((i=1;i<=COUNT;i++)); do
  key="k_$i"
  tag_count=$(( (RANDOM % 4) + 1 ))
  selected=()
  for ((t=0;t<tag_count;t++)); do
    selected+=("${TAGS[$RANDOM % ${#TAGS[@]}]}")
  done
  # dedupe
  uniq_tags=$(printf '%s\n' "${selected[@]}" | awk '!seen[$0]++' | paste -sd, -)
  ttl=$(( (RANDOM % 60000) + 5000 ))
  val_size=$(( (RANDOM % 180) + 20 ))
  value=$(head -c $val_size /dev/urandom | base64 | tr -d '\n' | cut -c1-$val_size)
  json=$(jq -n --arg v "$value" --argjson n $i '{i:$n,v:$v,rand:(now|tonumber)}')
  curl -s -o /dev/null -X PUT "$BASE_URL/keys/$key" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "{\"value\":$json,\"ttl_ms\":$ttl,\"tags\":[\"${uniq_tags//,/\",\"}\"]}" &
  if (( i % 50 == 0 )); then wait; echo "Inserted $i" >&2; fi
done
wait
echo "Done." >&2
