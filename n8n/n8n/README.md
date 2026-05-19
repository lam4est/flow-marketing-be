# n8n workflows

Exports for [n8n](https://n8n.io) automation. Import via **Workflows → Import from file**.

| File | Purpose |
|------|---------|
| `workflows/multi-channel-send.json` | Poll campaign workflows and trigger `POST /api/workflow-users/{id}/trigger` on the Symfony backend |

Backend must be reachable from n8n (`http://backend:8000` inside Docker, or host URL). Set `X-User-Id` to the owning user id.

Webhooks expected by the backend (configure in `docker-compose.yml` / `backend/.env`):

- `WORKFLOW_EMAIL_WEBHOOK_URL` → e.g. `http://localhost:5678/webhook/send-email`
- `WORKFLOW_SMS_WEBHOOK_URL` → e.g. `http://localhost:5678/webhook/send-sms`

Direct outbound sends (Google Sheet, CRM, etc.) can call `POST /api/multi_channel/send` with `sender` and `message` — build a separate workflow in n8n as needed.
