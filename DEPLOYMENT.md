# Deployment

## Webserver

- DocumentRoot skal være `<projekt>/public`.
- `setup.php` må aldrig ligge i eller være direkte tilgængelig fra den offentlige webrod.
- HTTPS er obligatorisk i produktion.
- Hvis TLS termineres i en reverse proxy, sættes `TRUST_PROXY_HEADERS=true` kun når direkte adgang
  til applikationsserveren er blokeret.

## Filer og rettigheder

- `config/.env`: ejerlæsbar, mode `0600`.
- `storage/install.lock`: ejerlæsbar, mode `0600`.
- `storage/` og `storage/logs/`: mode `0750`, skrivbar for PHP-processen.
- Kildekode og migrationsfiler: read-only for PHP-processen.

## Opgradering

1. Tag databasebackup.
2. Deploy kildekoden.
3. Kør `php bin/migrate.php` med samme miljøvariabler som webapplikationen.
4. Kør `php bin/encrypt-existing-chats.php` for at kryptere eksisterende klartekst og opgradere
   ældre ciphertext til det versionerede format. Kommandoen kan køres igen sikkert.
5. Kør smoke tests for login, chat, hver aktiv provider og godkendt GitHub-repository.
6. Rul tilbage til backup og forrige release, hvis migration eller smoke tests fejler.

Hvis `CHAT_RETENTION_DAYS` er større end nul, planlægges `php bin/cleanup.php` dagligt via cron.

Migrationer kræver midlertidigt DDL-rettigheder. Fjern `CREATE`, `ALTER` og `DROP` fra den normale
runtime-databasebruger efter installation/migration, eller kør migrationen med en separat deploy-bruger.

## Custom providers

Tilføj eksakte DNS-hosts til `PROVIDER_ALLOWED_HOSTS` og eventuelle HTTPS-porte til
`PROVIDER_ALLOWED_PORTS`, separeret med komma. Private/internal hosts
kræver desuden `PROVIDER_ALLOW_PRIVATE_NETWORKS=true`; brug netværks-firewall og en dedikeret
egress-policy.

## Drift

Rotér logs, overvåg 401/403/429/5xx, sæt databasebackup og restore-test på fast plan, og rotér
secrets gennem en dokumenteret procedure. Providerfejl vises ikke med responsbody til brugere;
brug request-id i applikationsloggen ved fejlsøgning.
