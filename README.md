# Flow Marketing — Backend (Symfony API)

Symfony API for marketing workflows, multi-channel send, and n8n integration.

**Frontend (Vue):** separate repository — [flow-marketing](https://github.com/lam4est/flow-marketing). Clone it next to this repo and run `npm run dev` on the host.

## Layout

```
.
├── docker-compose.yml   # PostgreSQL + API + messenger worker
├── n8n/workflows/       # n8n exports (import in n8n UI)
├── scripts/             # local smoke test helpers
├── src/                 # application code
└── .env.example         # copy to .env.local for local Symfony
```

## Quick start (API in Docker)

From this repository root:

```bash
cp .env.example .env   # required for Symfony / compose (not committed)
docker compose up -d
docker compose exec backend php bin/console app:seed-demo
```

| Service | URL |
|---------|-----|
| API | http://localhost:5679 |
| PostgreSQL | `localhost:5433` (user `postgres`, db `app_db`) |

Optional: copy `.env.example` → `.env.local` for Symfony CLI outside Docker. For compose variable substitution, you may add a `.env` file here with `APP_SECRET=...` (see comments in `.env.example`).

## Frontend (separate repo)

```bash
git clone https://github.com/lam4est/flow-marketing.git ../frontend
cd ../frontend && npm install && npm run dev
```

| Service | URL |
|---------|-----|
| UI | http://localhost:5173 — configure `VITE_API_BASE_URL=http://localhost:5679` in `.env.development` |

## Smoke test (workflow + worker + n8n)

Prerequisites: stack running, n8n on port **5678** with webhooks `send-sms` and `send-email`.

```bash
./scripts/e2e-smoke.sh
# or manually:
docker compose exec backend php bin/console app:seed-demo
curl -s -H "X-User-Id: 1" http://localhost:5679/api/workflows
curl -X POST -H "Content-Type: application/json" -H "X-User-Id: 1" \
  http://localhost:5679/api/workflow-users/1/trigger -d '{}'
docker compose logs -f messenger-worker
```

## n8n

Import `n8n/workflows/multi-channel-send.json` (see `n8n/README.md`). Compose points webhooks at `host.docker.internal:5678` when n8n runs on the host.

## Environment

| What | Where |
|------|--------|
| Symfony / DB | `.env.example` → `.env.local` (gitignored) |
| Docker Compose `APP_SECRET` | optional `.env` in this directory |
| Frontend API URL | `flow-marketing` repo — `.env.development` |

`.env`, `.env.dev`, `.env.test` are gitignored.

## Quality checks

```bash
composer phpstan
```

CI: `.github/workflows/ci.yml`.
