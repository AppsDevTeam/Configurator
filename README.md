## ADT\Configurator

Initial system DI container generator for ADT.

Every developer has his own public and private key.
Debug access is granted by providing correct private key in cookie and IP address.

Environment is based on these possibilities:
- Write `--env <environment>`, or `--env=<environment>` as parameter of CLI command.
- Do request to URL specified by `self::setUrls`.

## Installation

The best way to install is using [Composer](http://getcomposer.org/):

```sh
$ composer require adt/configurator
```

## Usage

In `bootstrap.php` replace

```
$configurator = new Nette\Configurator;
```

by

```
$configurator = new ADT\Configurator;

// Uncomment line below to create hash for new developer.
// ADT\Configurator::newDeveloper('JohnDoe');

$configurator
	->setIps([
		'127.0.0.1',
		'1.2.3.256', // Place one
	])
	->addDeveloper('JohnDoe@$2y$10$jmP/xNJk8ThxytW.vlZo1O/4nbDZdZh7eYeRZH1sE3pYeQGLD2982')
	->addDeveloper('TotalAdmin@$2y$10$qMptWXe6UkKjxXnDHiw6zOltc074IeI6QQBobuKJVPNwh7LO0d/cO', TRUE)
	->setDebugMode();
```

And after

```
$configurator->addConfig(__DIR__ . '/config/config.neon');
```

add

```
$configurator
	->setUrls([
		'my.local.domain.loc' => 'local',
		'my.dev.domain.com' => 'dev',
		'my.production.domain.com' => 'prod',
		'^.*\.all\.other\.subdomains\.com$' => 'other', // Regex must start with '^'
	])
	->setEnvironment()
	->addEnvironmentConfig(__DIR__ . '/config');
```

Create these config files:
- `/config/config.dev.neon`
- `/config/config.prod.neon`
- `/config/config.local.neon`

Then you can go to URL `my.dev.domain.com` or run `php www/index.php --env dev ...`.

You can enable debug mode in cli using `NETTE_DEBUG` variable. Set it for example in your `~/.bash_profile`:

```bash
export NETTE_DEBUG=1
```
