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

// Uncomment line below to create hash for new developer.
// ADT\Configurator::newDeveloper('JohnDoe');
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

## Tracy bar with concurrent AJAX requests

In debug mode `enableTracy()` stores the deferred Tracy bar content in `ADT\Tracy\LockFreeFileSession`
instead of Tracy's `FileSession`. `FileSession` keeps the file of the `tracy-session` cookie
locked for the whole request, so concurrent AJAX requests of one browser (a page firing several
signals at once) run one after another. `LockFreeFileSession` locks the file only while reading
and writing and merges the changes of concurrent requests. A storage set by the project before
`enableTracy()` (`Debugger::setSessionStorage()`) is kept.
