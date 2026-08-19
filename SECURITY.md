# Security policy

Rapportér sikkerhedsproblemer privat til repositoryejeren. Opret ikke et offentligt issue med
legitimationsoplysninger, persondata eller en reproducerbar udnyttelse.

## Secrets

- Commit aldrig `config/.env`, backupkopier af miljøfiler, logs eller `storage/install.lock`.
- Brug en separat databasebruger med mindst mulige rettigheder og netværksbegrænsning.
- Brug fine-grained GitHub-token eller GitHub App med adgang til de færrest mulige repositories.
- Rotér `ENCRYPTION_KEY` gennem en kontrolleret re-encryption; fjern først den gamle nøgle efter migration.

## Supported version

Kun seneste version af `main` modtager sikkerhedsrettelser.
