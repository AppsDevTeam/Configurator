## ADT\Configurator

Initial system DI container generator for ADT.

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

 