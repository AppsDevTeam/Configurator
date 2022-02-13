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
