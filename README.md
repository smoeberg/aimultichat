# Multi-Chat v2.1 — production baseline

Secure multi-provider chat application in PHP 8.1+ with MySQL/MariaDB.

## Production architecture

Only `public/` should be the web server document root. `config/`, `src/`, `storage/`, `database.sql`, `bootstrap.php` and `setup.php` must not be directly web-accessible.

## Install

1. Point Apache/Nginx DocumentRoot to `public/`.
2. Ensure PHP has `pdo_mysql`, `curl`, `openssl`, `mbstring` and `json`.
3. Make `storage/` writable by the web-server user (750).
4. Open `/../setup.php` through a temporary protected admin location, or run the SQL manually and create the `.env` file. Delete/protect `setup.php` immediately after installation.
5. Generate the encryption key with `openssl rand -hex 32` if configuring manually.
6. Enable HTTPS.

## Security

- API keys are encrypted at rest with AES-256-GCM.
- Provider configuration JSON is encrypted at rest.
- Passwords use Argon2id.
- CSRF protection is required for state-changing requests.
- Sessions use HttpOnly + SameSite cookies and secure cookies in production.
- Database access uses PDO prepared statements.
- AI requests enforce HTTPS, TLS verification, connect and total timeouts.
- Provider errors never return provider response bodies or credentials to the client.
- Rate limiting is stored in the database.
- API routes verify authentication and chat ownership.
- No secrets are committed; use `config/.env` with mode 0600.

## Providers

OpenAI-compatible providers can be added in `setup.php`/the database. Claude uses its native messages format. GPAI can use an encrypted configuration JSON containing provider-specific credentials.

## Operational requirements

Use regular database backups, log rotation, HTTPS, OS updates, PHP security updates, and a process for rotating provider API keys and the `ENCRYPTION_KEY`. Rotating the encryption key requires a deliberate re-encryption migration.

## LibreChat Agent-integration

Multi-Chat kan bruge en LibreChat Agent via LibreChats OpenAI-kompatible Agents API. LibreChat skal have Remote Agents API aktiveret, og der skal oprettes en API-nøgle med adgang til agenten.

Konfigurer en bot i Admin som:

- Provider: `LibreChat Agent (OpenAI-kompatibel)`
- Endpoint: `https://DIN-LIBRECHAT/api/agents/v1/chat/completions`
- Model ID: LibreChat-agentens ID, fx `agent_abc123`
- API Key: API-nøglen fra LibreChat

LibreChat dokumenterer endpointet som `POST /api/agents/v1/chat/completions`; model-feltet svarer til agent-ID'et.
