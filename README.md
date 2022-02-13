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
	->addConfig('stage/' . $_ENV['STAGE'])
	->addConfig(Configurator::parseConfigFrom($_ENV['NETTE_ENV']))
	->addConfig(
		$_ENV['NETTE_TEST'] || !Configurator::isCli() && $_SERVER['SERVER_PORT'] === '1420' // A = 1, D = 4, T = 20
			? 'test/' . $_ENV['STAGE'] . '.neon'
			: null
	);
```
