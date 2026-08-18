# Multi-Chat – debug review

## Status
The original archive had several runtime blockers. They are fixed in the debugged copy, but a real MySQL database and live provider credentials are still required for an end-to-end production test.

## Checks performed
- PHP lint: all supplied PHP files pass syntax checking.
- File structure compared with the README's declared architecture.
- Cross-file class references inspected.
- Request routing and credential handling inspected.

## Findings fixed in the debugged copy

### 0. Chat list fatal error
`ApiController::list()` called the non-existent `Bot::toArray()` method. The endpoint now uses `Bot::toPublicArray()`, preventing the fatal error and keeping encrypted provider credentials out of API responses.

### 0b. Chat list HTTP error handling
The browser now checks the HTTP status of `?api=list` before rendering the result, so server-side API failures are not silently interpreted as an empty chat list.

### 1. Chat UI bootstrap was missing
`public/js/app.js` defined `MultiChatApp` but never instantiated it. The debugged copy starts the app after DOM load.

### 2. Session token generation was missing
`User` called `Security::generateSessionToken()`, but the method did not exist. The method now generates a cryptographically random 64-character token.

### 3. Non-default database ports were ignored
The installer accepted a port, while `Database` always connected to MySQL's default port. The configured port is now used.

### 4. Installer configuration values were not safely round-tripped
The installer now protects setup with CSRF, generates the encryption key, writes quoted environment values safely, and the loader decodes those quoted values.

### 5. Provider system prompts were incomplete
OpenAI-compatible and Claude requests now include the configured bot system prompt and stored system messages without sending unsupported `system` roles in Claude's message array.

### 6. Failed sends left stale optimistic messages
The browser now removes the optimistic user message and restores the input when the server rejects a send.

## Positive observations
- `declare(strict_types=1)` is used consistently in the supplied PHP files.
- PDO prepared statements are used for user/database values.
- Password hashing uses Argon2id.
- CSRF tokens use cryptographically strong random bytes.
- Session cookies set `HttpOnly`, `SameSite=Lax`, and conditionally `Secure`.
- Provider-specific payload handling is separated into `BotService`.
- The intended directory structure is substantially cleaner than a monolithic implementation.

## Validation
- All supplied PHP files pass `php -l`.
- The browser JavaScript passes `node --check`.
- The archive remains a valid ZIP.
- Live DB, login, and AI provider calls still need to be exercised on the target server.
