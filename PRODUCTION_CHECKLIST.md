# Production readiness

Implemented: prepared SQL, Argon2id passwords, AES-256-GCM provider secrets/config, HTTPS provider endpoints with TLS verification, hardened session cookies, CSRF on state-changing requests, per-user chat authorization, database rate limiting, security headers, installer lock, restrictive secret files, and `public/` as the intended web root.

Deployment: PHP 8.3+ with PDO MySQL/OpenSSL/cURL; MySQL 8+ or MariaDB 10.4+; DocumentRoot=`public/`; keep config/src/storage/setup outside web root; run setup once and then deny/remove setup.php; HTTPS; backups/log rotation; end-to-end test with real infrastructure and provider credentials before public traffic.

Validation: all PHP files syntax-checked and ZIP integrity tested locally. A real DB/provider integration test requires deployment infrastructure.

- LibreChat: hvis LibreChat-bots bruges, verificér Remote Agents API, API-nøgle, agent-ID og HTTPS endpoint før produktion.
