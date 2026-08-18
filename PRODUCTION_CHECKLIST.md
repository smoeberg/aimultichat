# Produktionscheckliste

## Før trafik

- [ ] DocumentRoot peger kun på `public/`.
- [ ] HTTPS og sikre session-cookies er verificeret.
- [ ] `config/.env` og `storage/install.lock` har mode `0600` og er ikke i Git.
- [ ] Databasebrugeren er netværksbegrænset og har kun nødvendige runtime-rettigheder.
- [ ] `php bin/migrate.php` er kørt.
- [ ] `php bin/encrypt-existing-chats.php` er kørt efter opgradering fra en ældre version.
- [ ] Administratorpassword er mindst 12 tegn; MFA håndteres foran applikationen, hvis muligt.
- [ ] Hver provider bruger et godkendt HTTPS-host og en godkendt port samt har fungerende timeout/error-test.
- [ ] GitHub-token er read-only, og repository-allowlisten er eksplicit.
- [ ] Backup, restore, logrotation og secret-rotation er testet.
- [ ] Branch protection, krævet CI/review og secret scanning er aktiveret i GitHub.

## Validering

- [ ] `composer validate --strict --no-check-publish`
- [ ] PHP-lint på alle PHP-filer
- [ ] `php tests/run.php`
- [ ] `php tests/integration.php` mod en isoleret testdatabase
- [ ] `node --check public/js/app.js`
- [ ] Login-rate-limit og sessionudløb er smoke-testet.
- [ ] Chatfejl gemmer ikke brugerbeskeden som en skjult besked.
- [ ] En ikke-godkendt GitHub-repository-URL afvises.
- [ ] OpenAI-kompatibel, Anthropic og GPAI payload/response er integrationstestet.
