# Security notes

## Environment secrets

The repository must never contain a real `.env` file. Configure production values outside Git and generate a unique Laravel `APP_KEY` for every installation.

The previous repository history contained an `.env` with an application key and database credentials. Treat those values as compromised and rotate them on any server that used them.

## Ubuntu 24.04

The supported PHP runtime for Ubuntu 24.04 is PHP 8.3. The installer and services in this branch are being migrated away from PHP 8.1/Python 2-era assumptions.

## Privileged operations

XPanel performs privileged Linux account and SSH operations. The remaining sudo policy should be kept least-privilege: do not grant `www-data` unrestricted access to general-purpose commands such as `sed`, `rm`, `curl`, `crontab`, or `mysqldump`. Prefer dedicated root-owned helper commands with strict argument validation.
