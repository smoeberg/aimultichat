# Rettelse: chatliste og API

## Primær fejl rettet
`ApiController::list()` kaldte `Bot::toArray()`, men `Models\Bot` har ikke denne metode. Det gav en fatal PHP-fejl, når browseren hentede `?api=list`, og JavaScript viste derfor **"Kunne ikke indlæse chatlisten"**.

Rettelsen bruger bot-modellens eksisterende sikre `toPublicArray()`-metode. Den returnerer kun offentlige botfelter og eksponerer ikke krypterede API-nøgler eller øvrig intern konfiguration.

## Yderligere robusthed
- API-listen bruger typed callback og eksplicit public projection.
- JSON-responser bruger `JSON_INVALID_UTF8_SUBSTITUTE` og `JSON_THROW_ON_ERROR`.
- Browseren kontrollerer HTTP-status på `?api=list` og viser serverens fejl i konsollen i stedet for at behandle en fejlside som en tom chatliste.

## Tidligere routingrettelse
`public/index.php` bruger allerede `switch` + `exit` for API-ruterne, så en succesfuld API-respons ikke efterfølges af en ekstra 404-JSON-respons.
