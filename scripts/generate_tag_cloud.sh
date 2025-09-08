#!/usr/bin/env bash
# Generate a diverse set of tags with skewed frequency distribution for TagCache.
# Usage: ./scripts/generate_tag_cloud.sh [BASE_URL] [TOTAL_KEYS]
# Relies on credential.txt or TC_USER / TC_PASS.
set -euo pipefail
BASE_URL=${1:-http://127.0.0.1:8080}
TOTAL=${2:-4000}
USER=${TC_USER:-}
PASS=${TC_PASS:-}
if [[ -z "$USER" || -z "$PASS" ]]; then
  if [[ -f credential.txt ]]; then
    USER=$(grep '^username=' credential.txt | cut -d= -f2)
    PASS=$(grep '^password=' credential.txt | cut -d= -f2)
  fi
fi
if [[ -z "$USER" || -z "$PASS" ]]; then
  echo "Need credentials (credential.txt or TC_USER/TC_PASS env vars)" >&2; exit 1;
fi
B64=$(printf '%s:%s' "$USER" "$PASS" | base64)
TOKEN=$(curl -s -X POST "$BASE_URL/auth/login" -H "Authorization: Basic $B64" -H 'Content-Type: application/json' -d '{"username":"'"$USER"'","password":"'"$PASS"'"}' | jq -r '.token')
[[ -z "$TOKEN" || "$TOKEN" == null ]] && { echo "Failed to login" >&2; exit 1; }

# Weighted tag pools (Zipf‑like distribution): common, medium, rare
COMMON=(user session cache hot feed main core auth api web mobile search)
MEDIUM=(feature rollout blue green purple orange red yellow gold silver bronze queue task job worker billing invoice payment geo region shard east west north south)
RARE=(twilight nebula quark lambda photon graviton aurora magma frost dune lotus comet pulse prism forge drift nova ember tide echo flux chrono apex zenith cipher vertex loom orbit glyph macro nano delta sigma omega tau kappa zeta)

echo "Generating $TOTAL keys with skewed tag distribution at $BASE_URL" >&2
for ((i=1;i<=TOTAL;i++)); do
  # decide frequency tier counts
  tags=()
  # Always at least one common
  tags+=("${COMMON[$RANDOM % ${#COMMON[@]}]}")
  # 70% chance add a medium tag
  if (( RANDOM % 100 < 70 )); then tags+=("${MEDIUM[$RANDOM % ${#MEDIUM[@]}]}"); fi
  # 25% chance add another medium
  if (( RANDOM % 100 < 25 )); then tags+=("${MEDIUM[$RANDOM % ${#MEDIUM[@]}]}"); fi
  # 15% chance add rare
  if (( RANDOM % 100 < 15 )); then tags+=("${RARE[$RANDOM % ${#RARE[@]}]}"); fi
  # Deduplicate
  uniq_tags=$(printf '%s\n' "${tags[@]}" | awk '!seen[$0]++')
  ttl=$(( (RANDOM % 900000) + 60000 ))
  value="val_$i"
  # Build JSON array for tags
  tag_json=$(printf '%s\n' "$uniq_tags" | jq -R . | jq -s .)
  curl -s -o /dev/null -X PUT "$BASE_URL/keys/cloud_$i" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d '{"value":"'$value'","ttl_ms":'$ttl',"tags":'$tag_json'}' &
  if (( i % 60 == 0 )); then wait; echo "Inserted $i" >&2; fi
done
wait
echo "Done generating tag cloud dataset." >&2
