## ADT\Configurator

Extended nette/configurator.

## Installation

```
composer require adt/configurator
```

## Usage

```
$configurator = new Configurator();

$configurator->loadEnv(__DIR__ . '/..', ['.env.common']);

$configurator
	->addDeveloper(json_decode($_ENV['NETTE_DEVELOPERS'], true))
	->setDebugMode((bool) $_ENV['NETTE_DEBUG']);

$configurator
	->setConfigDirectory(__DIR__ . '/config')
	->addConfig('common')
	->addConfig('interface/' . php_sapi_name())
	->addConfig('stage/' . $_ENV['STAGE'])
	->addConfig(explode(',', $_ENV['NETTE_ENV']))
	->addConfig(Configurator::isCli() && Configurator::get('country') ? 'cli-country' : null)
	->addConfig(Configurator::get('country') ? 'env/' . $_ENV['STAGE'] . '-' . Configurator::get('country') : null)
	->addConfig($_ENV['NETTE_TEST'] ? 'test' : null);
```

## Bootstrap of a project

`ADT\Bootstrap` is the bootstrap of the AppsDevTeam web projects (sandbox layout: `.env`, `app/`,
`config/`, `log/`, `temp/` in one root). The project's class only adds what is its own:

```php
namespace App;

class Bootstrap extends \ADT\Bootstrap
{
	// deprecations of the vendor on the current PHP: out of strict mode of Tracy
	protected bool $strictDeprecations = false;

	protected function init(): void
	{
		// before the container (static registrations of the project)
		CzechValidationMessages::register();
	}

	protected function addConfigs(string $interface): void
	{
		// after common, interface/<ui|console>, stage/<STAGE>, APP_ENV and tenant configs, before test
		$this->configurator->addConfig($_ENV['APP_SANDBOX'] ? 'sandbox' : null);
	}
}
```

`new \App\Bootstrap()` (root = parent of the directory of the class) then `bootUI()` in
`www/index.php`, `bootConsole()` in `bin/console` and the test bootstrap. It does:

- `loadEnv()`, `handlePreprodAccess()` and `handleMaintenance()` (see below) in the constructor
- `src/defaults.neon` of the package before `config/common.neon` (with nette/http only): no `X-Powered-By`,
  CSP `object-src 'none'`, `base-uri 'none'`, `frame-ancestors 'self'` - the project overrides them in
  `common.neon`, `script-src` stays in the project (it depends on its templates)
- debug mode: console `APP_DEBUG`, web `APP_DEBUG` or a developer token (see *Debug mode on servers*);
  error page of production
  `src/error.phtml` of this package (500, AJAX gets just the text), `app/error.phtml` of the project
  replaces it
- Tracy (`log/`), `ADT\ErrorLogger` when adt/error-logger is installed (`$errorEmail`, null = off,
  e-mails only in production; `ERROR_EMAIL` of `.env` overrides the recipient per stage, empty = no e-mails, e.g. CI), `temp/`, parameters `rootDir`, `appDir`, `wwwDir`
- secrets hidden in the Tracy log (HTML and `.md`, the attachments of ErrorLogger e-mails) and on the
  BlueScreen: a scrubber hides every key matching `$sensitiveKeys` (`*PASS*`, `*SECRET*`, `*TOKEN*`,
  `*_KEY*`, `*SALT*`, `*DSN*`, `*AUTH*`, `APP_DEVELOPERS`, cookies and the session ID, `app-debug`,
  `MAINTENANCE_BYPASS` ...) - `$_ENV` and `$_SERVER` carry the whole `.env` of the container - and the
  value of every cookie, whatever its name (remember-me and similar logins). A project extends the
  pattern by overriding the property; a scrubber set by the project before is kept. Not covered:
  the `.md` report of plain Tracy prints request headers and unnamed call arguments raw (scrubbed only
  by ErrorLogger ≥ 3.4, which writes its own), and the URL (report title, `REQUEST_URI`,
  `QUERY_STRING`, the line of `exception.log`) keeps secrets of the query string
  (`tests/BootstrapWebTest.php`)
- configs `common`, `interface/<ui|console>`, `stage/<STAGE>`, `APP_ENV` (comma-separated),
  tenant configs, `addConfigs()`, `test` (`APP_TEST` in `.env` or env)
- tenants (several projects in one code base): `APP_ENV=project/<name>` (`config/project/<name>.neon`)
  adds `config/project.neon` (shared by the tenants) and `config/env/<STAGE>-<name>.neon`
  (`$tenantGroup`, null = off)
- in test mode the parameters of [adt/make](https://github.com/AppsDevTeam/make) `make test`:
  `testDbName` from env `TEST_DB_NAME` (a suite with its own database) and `testShardSuffix`
  (`''` or `_<part>` from env `TEST_SHARD` or cookie `test_shard`) for a suite split into parts

## Maintenance window

The recipe of [adt/docker-deployer](https://github.com/AppsDevTeam/docker-deployer) runs pending
migrations in a maintenance window: it creates `~/project/maintenance` on all servers, so that
the application holds no metadata locks of the tables being changed (DDL on a Galera cluster
conflicts with them). Handle it right after `loadEnv()`, before the DI container is created:

```php
$configurator->loadEnv(__DIR__ . '/..');
$configurator->handleMaintenance();
```

- web: `503` with `Retry-After`, a default page or your own (`handleMaintenance($template)`)
- console: ends with exit code 0 (cron jobs skip a run), except the waiting commands
  (default `['background-queue:consume']`, second argument), which wait until the maintenance
  ends - supervisor would restart a consumer that exits in a loop

`.env` of the project:

```
# inside the containers; the deploy root ~/project is mounted at /var/www/html
MAINTENANCE_FILE=/var/www/html/maintenance
# commands of the deploy get 1 and skip the check; it must be listed (empty),
# otherwise `de` does not pass it into the container
MAINTENANCE_BYPASS=
```

`Configurator::isMaintenance()` tells whether the maintenance is on.

## Preprod access

Preprod of a deploy (port 4434 of the edge proxy, nginx serves `release/www` by the header
`X-Forwarded-Port: 4434`) is open only to the IPs of `PREPROD_IPS` in `.env` - comma-separated
IPs or CIDRs, empty = open to everybody:

```
PREPROD_IPS=78.80.93.27, 203.0.113.0/24
```

```php
$configurator->loadEnv(__DIR__ . '/..');
$configurator->handlePreprodAccess();
```

- others get `403` before the application starts (the console is not affected); static files are
  served by nginx and stay public
- private and loopback addresses always pass: the smoke check of the deploy (curl on the server,
  through the docker bridge) and the VPN
- the client is `X-Real-IP` when the request comes from a private address (the edge proxy,
  which always overwrites the header), otherwise `REMOTE_ADDR` - with or without `real_ip` of nginx
- an invalid entry matches nothing, a typo closes preprod instead of opening it

## Debug mode on servers

The developer's own SSH key (ssh-ed25519) turns the debug mode on - nothing else is generated:

- `APP_DEVELOPERS` (`config/project/.env.common`) is a JSON list of `"<Slug>@ssh-ed25519 <base64>"`, the
  public SSH keys of the developers - nothing secret.
- The cookie `app-debug` carries a token: an expiry (at most 366 days) and a host (`*` = every host)
  signed by the SSH key through ssh-agent (`ssh-keygen -Y sign -n app-debug`, `ADT\DebugToken`). The
  private key never leaves the developer's machine and never goes into a cookie.
- The server turns the debug mode on only with the header `X-App-Debug-Allowed: 1`, which the edge
  proxy (nginx-proxy of server-management-scripts) sends only to the IPs in `NGINX_PROXY_DEBUG_IPS` and
  always overwrites. Without the proxy (locally, directly) the cookie does nothing; locally `APP_DEBUG=1`.

With [adt/make](https://github.com/AppsDevTeam/make) in any project:

```bash
make debug-token [DOMAIN=www.example.cz] [DAYS=365]   # prints the cookie value
```

- New developer: add `"<Slug>@" + ~/.ssh/id_ed25519.pub` (without the comment) to `APP_DEVELOPERS`, deploy.
- Leaving developer: remove the line, deploy. Tokens of the key stop working at once.
- A leaked cookie works only from the allowed IPs and until it expires.

## Tracy bar with concurrent AJAX requests

In debug mode `enableTracy()` stores the deferred Tracy bar content in `ADT\Tracy\LockFreeFileSession`
instead of Tracy's `FileSession`. `FileSession` keeps the file of the `tracy-session` cookie
locked for the whole request, so concurrent AJAX requests of one browser (a page firing several
signals at once) run one after another. `LockFreeFileSession` locks the file only while reading
and writing and merges the changes of concurrent requests. A storage set by the project before
`enableTracy()` (`Debugger::setSessionStorage()`) is kept.

## Tests

```
composer install
composer test
```
