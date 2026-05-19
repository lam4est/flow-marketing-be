#!/usr/bin/env bash
# Seed demo data and trigger Welcome Activation workflow enrollment.
# Prerequisites: docker compose up, n8n on :5678 with webhooks send-sms / send-email.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
API="${API_BASE:-http://localhost:5679}"
USER_ID="${X_USER_ID:-1}"

cd "$ROOT"

echo "==> Seeding demo data (user id expected: ${USER_ID})..."
docker compose exec -T backend php bin/console app:seed-demo

echo "==> Resolving workflow_user id for Welcome Activation..."
WU_ID="$(curl -sS -H "Accept: application/json" -H "X-User-Id: ${USER_ID}" \
  "${API}/api/workflows" | python3 -c "
import json, sys
data = json.load(sys.stdin)
for w in data:
    if w.get('name') == 'Welcome Activation' and w.get('is_active'):
        print(w['id'])
        sys.exit(0)
for w in data:
    if w.get('is_active'):
        print(w['id'])
        sys.exit(0)
print('', end='')
sys.exit(1)
" 2>/dev/null)" || true

if [[ -z "${WU_ID}" ]]; then
  echo "Could not find an active workflow enrollment. Try: curl -H \"X-User-Id: ${USER_ID}\" ${API}/api/workflows" >&2
  exit 1
fi

echo "==> Triggering workflow_user ${WU_ID} (X-User-Id: ${USER_ID})..."
RESPONSE="$(curl -sS -w "\n%{http_code}" -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-User-Id: ${USER_ID}" \
  "${API}/api/workflow-users/${WU_ID}/trigger" \
  -d '{}')"
HTTP_BODY="$(echo "$RESPONSE" | head -n -1)"
HTTP_CODE="$(echo "$RESPONSE" | tail -n 1)"

echo "$HTTP_BODY" | python3 -m json.tool 2>/dev/null || echo "$HTTP_BODY"
echo "HTTP ${HTTP_CODE}"

if [[ "$HTTP_CODE" != "202" && "$HTTP_CODE" != "200" ]]; then
  echo "Trigger failed. Check backend logs: docker compose logs backend" >&2
  exit 1
fi

echo ""
echo "==> Done. Watch the worker:"
echo "    docker compose logs -f messenger-worker"
echo "==> n8n executions (webhooks send-sms / send-email): http://localhost:5678"
echo "==> UI: http://localhost:5173  (VITE_USER_ID=${USER_ID})"
