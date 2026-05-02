# AGENTS.md

## Cursor Cloud specific instructions

### Architecture Overview

TourneyHub is a tournament management web app with two services:

| Service | Tech | Directory | Dev URL |
|---------|------|-----------|---------|
| Backend (REST API) | PHP 8.3 / Slim 4 | `backend/` | `http://localhost:8080` |
| Frontend (SPA) | Vue 3 / Vite 8 | `frontend/` | `http://localhost:5173` |

**Database:** MariaDB (MySQL-compatible), database name `tourneyhub`, user `tourney`, password `tupassword` (from `backend/.env`).

### Starting Services

1. **MariaDB:** `mysqld_safe &` (wait ~3s for startup)
2. **Backend:** `cd backend && php -S localhost:8080 -t public/`
3. **Frontend:** `cd frontend && npm run dev`

### Important Gotchas

- **No SQL schema file exists in the repo.** The database schema must be reverse-engineered from PHP source code. The update script handles creating the database and all 14 tables on each run (idempotent via `CREATE TABLE IF NOT EXISTS`).
- **2FA is in shadow mode** (`AUTH_2FA_MODE=shadow` in `backend/.env`), so login returns a JWT directly without requiring OTP verification.
- **`AUTH_DEV_BYPASS_2FA_ON_MAIL_FAILURE=true`** is set, so email/SMTP failures won't block login.
- The backend vendor directory is committed; `composer install` is only needed if dependencies change.
- Frontend uses `package-lock.json` (npm).
- The frontend `.env` must have `VITE_API_BASE_URL=http://localhost:8080/api`.
- Vite 8 requires Node.js 20+ (22 recommended).
- There are no lint, test, or CI configurations in this repository.
- Test accounts in backend `.env` comments: `test@test.com` / `123456`, `admin@admin.com` / `admin123`.

### API Quick Reference

- `GET /` — health check ("API TourneyHub funcionando")
- `POST /api/register` — `{ username, email, password, full_name }`
- `POST /api/login` — `{ email, password }` → `{ token }`
- `GET /api/tournaments` — list public tournaments
- `POST /api/tournaments` — create tournament (requires Bearer token)
- All authenticated routes require `Authorization: Bearer <JWT>` header.
