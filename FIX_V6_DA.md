# Rettelser i denne version

1. `RateLimiter::check()` modtager nu altid et heltal, også hvis en ældre session/model leverer bruger-id som streng.
2. `app_settings` oprettes automatisk ved første brug. Dette migrerer eksisterende databaser, hvor tabellen ikke findes, uden at setup skal køres igen. Tabellen er også med i `database.sql` for nye installationer.
3. AI-beskeder normaliseres til tekst før de sendes til en provider. Det forhindrer PHP-fejlen `Array to string conversion` ved provider-format med content-blokke.

Upload hele ZIP'en og overskriv eksisterende filer. Genindlæs siden og prøv igen.
