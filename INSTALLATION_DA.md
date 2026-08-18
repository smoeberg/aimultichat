# Installation

1. Upload projektet uden for webserverens DocumentRoot.
2. Peg DocumentRoot på projektets `public/`-mappe.
3. Installér PHP 8.1+ med PDO MySQL, cURL, OpenSSL, mbstring og JSON.
4. Aktivér HTTPS og giv PHP-processen skriveadgang til `config/` og `storage/`.
5. Kør `setup.php` gennem en midlertidig adgangsbeskyttet server-location. Fjern locationen bagefter.
6. Alternativt kopieres `config/.env.example` til `config/.env`, secrets udfyldes, `database.sql`
   importeres, og `php bin/migrate.php` køres.
7. Opret providers og GitHub repository-allowlist i Admin.

Commit aldrig `.env`, backupkopier, logs eller `storage/install.lock`.
