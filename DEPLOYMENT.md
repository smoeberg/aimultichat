# Deployment – Multi-Chat v2.3

## DocumentRoot
The web server **must point to `public/`**, not the project root.

Example:
- `/var/www/multichat/public` = DocumentRoot
- `/var/www/multichat/config` = private
- `/var/www/multichat/storage` = private
- `/var/www/multichat/src` = private

## Required PHP extensions
- pdo_mysql
- openssl
- curl
- mbstring

## First installation
Open `/setup.php` only if the server configuration deliberately exposes it. After installation, remove or block access to `setup.php`.

## If a 500 occurs
Check:
- `storage/logs/php-error.log`
- web server error log

The application now writes a useful exception message there instead of exposing it to visitors.
