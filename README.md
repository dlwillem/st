# DKG SelectieTool

Webapplicatie voor gestructureerde softwareselectie: requirements, scoring door
meerdere deelnemers, weging, leveranciers-invulportaal en rapportage.

## Systeemeisen

- **PHP 8.2 of hoger**
- **MySQL 8** (of MariaDB 10.6+)
- Apache met `mod_rewrite`, `mod_headers` en `.htaccess` actief
- PHP-extensies: `pdo_mysql`, `mbstring`, `openssl`, `gd` of `imagick`, `zip`,
  `fileinfo`, `intl`
- Composer (alleen nodig als `vendor/` niet is meegeleverd)

## Installatie

1. **Upload de codebase** naar de webroot van je hoster (inclusief `vendor/`).
2. **Composer** — alleen nodig als `vendor/` **niet** is meegeleverd (bv. bij
   `git clone` zonder vendor). Release-zip bevat `vendor/` al, sla dan over:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. **Open de install-wizard** in je browser:
   ```
   https://jouw-domein.tld/install.php
   ```
   De wizard maakt zelf `.env` aan als die nog ontbreekt en doorloopt zes
   stappen: DB-verbinding → schema → admin-account → branding → mail → klaar.
4. **Na succes: verwijder `install.php` én de map `sql/` van je host.** Ook
   als je het vergeet gaat de wizard automatisch op slot (via
   `settings.installed_at` + aanwezigheid van admin), maar verwijderen is de
   veilige default.

De wizard genereert automatisch een `APP_KEY` (32 bytes) in `.env`. Deze sleutel
versleutelt het SMTP-wachtwoord in de `settings`-tabel. **Bewaar `.env` in een
backup** — verlies van `APP_KEY` betekent dat mail-credentials opnieuw moeten
worden ingevoerd.

> **Veiligheidsnotitie:** tussen de upload en de eerste installatie is er een
> klein window waarin iedereen die `install.php` raadt de app zou kunnen
> configureren. Voer de installatie daarom meteen na de upload uit en
> verwijder `install.php` direct na stap 6.

## Na installatie

- Log in op `/pages/login.php` met het admin-account.
- **Structuur laden**: Instellingen → Structuur → upload een ingevulde template,
  of download het lege template en vul zelf categorieën, subcategorieën,
  applicatiesoorten en DEMO-vragen aan.
- **Branding**: Instellingen → Branding (app-naam, bedrijfsnaam, logo).
- **Mail**: Instellingen → Mail-configuratie (driver `log` of `smtp` + testmail).

## Health-endpoint

`GET /public/health.php` retourneert JSON met app-status en DB-latency.
Geschikt voor uptime-monitors; geeft 503 bij DB-fouten.

## Backups

Back up regelmatig:

- **Database** (`mysqldump` of hoster-paneel)
- **`.env`** — bevat `APP_KEY`; zonder deze sleutel is het mail-wachtwoord
  onleesbaar
- **`uploads/`** — geüpload logo + leveranciersbijlagen

## Logs

- `logs/php_errors.log` — PHP-fouten in productie
- `logs/mail.log` — gegenereerde e-mails bij `mail_driver=log`
- `audit_log`-tabel — applicatie-acties (login, CRUD op trajecten, etc.)

## Beveiliging

Out-of-the-box ingebouwd:

- HTTPS-redirect in `.htaccess` (localhost uitgezonderd)
- Security-headers: HSTS, CSP, X-Frame-Options, Referrer-Policy, nosniff
- Sessies met `__Host-` prefix + `SameSite=Strict` in productie
- CSRF-tokens op alle POST-formulieren
- PDO-prepared statements (strict mode, geen emulation)
- Bcrypt-wachtwoorden; scoring-tokens opgeslagen als SHA-256 hash
- Gevoelige mappen geblokkeerd via `.htaccess` (`config`, `includes`, `logs`,
  `sql`, `vendor`, `docs`)
- Mail-wachtwoord AES-256-GCM encrypted in DB (sleutel in `.env`)
- Login rate-limiting (5 pogingen per 15 minuten per IP)

## Rollen

| Rol          | Rechten                                                       |
|--------------|---------------------------------------------------------------|
| `architect`  | Alles: gebruikers, trajecten, requirements, scoring, audit    |
| `key_user`   | Trajecten/requirements/scoring beheren, geen gebruikersbeheer |
| `management` | Leesrechten op rapportage, geen mutaties                      |

## Scoringsformule

```
req_score    = gemiddelde over deelnemers
subcat_score = gemiddelde over requirements binnen subcat
cat_score    = Σ (subcat_weight/100 × subcat_score)
total_score  = Σ (cat_weight/100 × cat_score)
```

Een leverancier wordt **KO** gemarkeerd zodra minstens één deelnemer een
`type=ko`-requirement met score 1 beoordeelt.

## Updates

1. Zet `.env` en `uploads/` even veilig.
2. Overschrijf de codebase met de nieuwe versie.
3. Restore `.env` en `uploads/`.
4. Voer `composer install --no-dev` opnieuw uit bij vendor-updates.
5. Eventuele schema-migraties worden als `pages/migrate_*.php` meegeleverd en
   vereisen een architect-login.

## Structuur van de codebase

```
config/        env-loader, DB, mail-config (secrets komen uit .env)
includes/      auth, authz, helpers, excel-import/export, crypto
pages/         publieke routes (flat, geen router)
templates/     layout, sidebar, auth-layout
public/        health.php, statische assets
sql/           schema-dump (alleen gebruikt door install.php)
uploads/       logo + leveranciersbijlagen (schrijfbaar)
logs/          PHP-fouten + mail-log (schrijfbaar)
vendor/        Composer-dependencies
```

## Support

Problemen, bugs of suggesties? Meld ze via de projectbeheerder — dit is een
maatwerkapplicatie zonder publieke issue tracker.
