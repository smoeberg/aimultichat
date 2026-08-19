# EiraMultiChat

Multi-provider AI-chatklient til PHP 8.1+ og MySQL/MariaDB.

## Arkitektur

Kun `public/` må være webserverens DocumentRoot. `config/`, `src/`, `storage/`, `migrations/`,
`bin/`, `database.sql`, `bootstrap.php` og `setup.php` skal ligge uden for den offentlige webrod.

Applikationen bruger:

- sikre PHP-sessioner og CSRF-beskyttelse;
- Argon2id-passwordhashing (med PHP's sikre standard som fallback, hvis Argon2id ikke er tilgængelig);
- versioneret AES-256-GCM til provider-secrets, samtaletitler og beskeder;
- atomisk databasebaseret rate limiting;
- separate payloads til OpenAI-kompatible providers, Anthropic og GPAI;
- repository-allowlist før en fælles GitHub-forbindelse må læse kode.

## Installation

1. Installér PHP 8.1+ med `pdo_mysql`, `curl`, `openssl`, `mbstring` og `json`.
2. Peg webserverens DocumentRoot på `public/` og aktivér HTTPS.
3. Giv PHP-processen skriveadgang til `config/` og `storage/`, men ikke resten af kildekoden.
4. Eksponér `setup.php` gennem en midlertidig, adgangsbeskyttet server-location, eller opret
   `config/.env` manuelt fra `config/.env.example` og importér `database.sql`.
5. Fjern setup-locationen efter installation.

Ved opgradering køres:

```bash
php bin/migrate.php
php bin/encrypt-existing-chats.php
```

## Provider-egress

Kendte offentlige provider-hosts er indbygget. Custom/LibreChat-hosts skal tilføjes eksplicit til
`PROVIDER_ALLOWED_HOSTS`. Kun HTTPS accepteres. Private netværksadresser afvises som standard;
kun porte i `PROVIDER_ALLOWED_PORTS` accepteres, og DNS-resultatet fastlåses til den validerede
offentlige IPv4-adresse. `PROVIDER_ALLOW_PRIVATE_NETWORKS=true` må kun bruges til en bevidst intern deployment.

## GitHub-kontekst

En administrator skal både gemme et read-only GitHub-token og en eksplicit liste over godkendte
`ejer/repository`-værdier. Et token giver ikke automatisk applikationsbrugere adgang til alle de
repositories, tokenet kan læse. Kendte secret-filer udelades, og mistænkelige secret-linjer redigeres,
før konteksten sendes til en AI-provider.

## Drift

- Opbevar `.env`, logs og installationslåsen uden for Git.
- Rotér database-, GitHub- og provider-legitimationsoplysninger regelmæssigt.
- Tag krypterede databasebackups og test restore-processen.
- Sæt `CHAT_RETENTION_DAYS` og kør `php bin/cleanup.php` via cron, hvis automatisk retention kræves.
- Kør logrotation og overvåg gentagne login-/providerfejl.
- Aktivér branch protection, obligatorisk CI/review og GitHubs secret scanning.

Se også `DEPLOYMENT.md`, `PRODUCTION_CHECKLIST.md` og `SECURITY.md`.
