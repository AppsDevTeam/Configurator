<?php

declare(strict_types=1);

namespace ADT;

use Nette\DI\Container;
use ReflectionClass;
use Tracy\Debugger;

/**
 * Bootstrap of an AppsDevTeam web project (sandbox layout): the project's App\Bootstrap extends it
 * and only adds what is its own (form extensions in init(), extra configs in addConfigs()).
 *
 * Layout: <root>/.env, <root>/config/{common,interface/<ui|console>,stage/<STAGE>,test}.neon,
 * <root>/log, <root>/temp, <root>/app/error.phtml (optional error page of production).
 *
 * Env: STAGE, APP_DEBUG (debug mode of the console and, when set, of the web),
 * APP_DEVELOPERS (JSON list of developer keys - debug mode of the web by a cookie),
 * APP_ENV (comma-separated extra configs), APP_TEST (test mode, adds test.neon).
 */
abstract class Bootstrap
{
	protected Configurator $configurator;

	/** Root of the project (the directory with .env, app/, config/) */
	protected string $rootDir;

	/** Recipient of the errors of production (adt/error-logger, when installed); null = no ErrorLogger */
	protected ?string $errorEmail = 'error@mail.appsdevteam.com';

	/**
	 * Deprecations in strict mode of Tracy: true = a deprecation in a web request ends with an error
	 * page. A project whose vendor still triggers deprecations of the current PHP sets false - they
	 * are then left out of strict mode and of error_reporting (Tracy would echo them into AJAX
	 * responses and break snippets).
	 */
	protected bool $strictDeprecations = true;


	/**
	 * @param string|null $rootDir root of the project, default = parent directory of the directory
	 *                             of the project's Bootstrap class (app/Bootstrap.php)
	 */
	public function __construct(?string $rootDir = null)
	{
		$this->rootDir = $rootDir ?? dirname((string) (new ReflectionClass(static::class))->getFileName(), 2);
		$this->configurator = new Configurator();
		$this->configurator->loadEnv($this->rootDir);

		// Maintenance window of a deploy (adt/docker-deployer): web 503, console commands skipped,
		// consumers wait - before the DI container and any database connection exist
		$this->configurator->handleMaintenance();

		$this->init();
	}


	/** Project-wide registrations that must precede the container (e.g. extension methods of forms) */
	protected function init(): void
	{
	}


	/** Extra configs after common, interface, stage and APP_ENV, before test (e.g. per tenant) */
	protected function addConfigs(string $interface): void
	{
	}


	public function bootConsole(): Container
	{
		$this->configurator->setDebugMode((bool) $_ENV['APP_DEBUG']);
		return $this->setupContainer('console');
	}


	public function bootUI(): Container
	{
		$errorTemplate = $this->rootDir . '/app/error.phtml';
		if (is_file($errorTemplate)) {
			Debugger::$errorTemplate = $errorTemplate;
			if (Debugger::$productionMode) {
				Debugger::$onFatalError[] = function () use ($errorTemplate) {
					require $errorTemplate;
				};
			}
		}

		// Locally APP_DEBUG is enough, on servers a cookie with a developer key is verified.
		// New developer: Configurator::newDeveloper('JohnDoe')
		$this->configurator->setDebugMode($_ENV['APP_DEBUG'] ? true : json_decode($_ENV['APP_DEVELOPERS'] ?? '[]', true));
		return $this->setupContainer('ui');
	}


	protected function setupContainer(string $interface): Container
	{
		$this->configurator->enableTracy($this->rootDir . '/log');
		if (!$this->strictDeprecations) {
			Debugger::$strictMode = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED;
			error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
		}

		if ($this->errorEmail !== null && class_exists(ErrorLogger::class)) {
			$logger = ErrorLogger::install($this->errorEmail);
			if (!Debugger::$productionMode) {
				// no e-mails outside production
				$logger->mailer = null;
			}
		}

		$this->configurator->setTempDirectory($this->rootDir . '/temp');
		$this->configurator->addStaticParameters([
			'rootDir' => $this->rootDir,
			'appDir' => $this->rootDir . '/app',
			'wwwDir' => $this->rootDir . '/www',
		]);

		// APP_TEST comes from $_ENV (.env, CLI) or getenv() (www/index.php sets it by putenv as well)
		$isTest = $_ENV['APP_TEST'] || getenv('APP_TEST');

		$this->configurator
			->setConfigDirectory($this->rootDir . '/config')
			->addConfig('common')
			->addConfig('interface/' . $interface)
			->addConfig('stage/' . $_ENV['STAGE']);

		// One by one - the inherited phpdoc of Nette addConfig() does not allow a list
		foreach ($_ENV['APP_ENV'] ? explode(',', $_ENV['APP_ENV']) : [] as $envConfig) {
			$this->configurator->addConfig($envConfig);
		}

		$this->addConfigs($interface);

		if ($isTest) {
			$this->configurator->addConfig('test');

			// Own test database of a suite running next to another one (make test of adt/make,
			// TEST_ENV_<Suite>) - an explicit static parameter beats the value from test.neon
			if (getenv('TEST_DB_NAME')) {
				$this->configurator->addStaticParameters(['testDbName' => getenv('TEST_DB_NAME')]);
			}

			// Part of a suite split into concurrent processes (TEST_SHARDS_<Suite> of adt/make): the test
			// process has it in env TEST_SHARD, the browser and HTTP clients of the tests send it in
			// the cookie test_shard. testShardSuffix ('' or '_<part>') separates the data of the part,
			// typically `dbname: <test database>%testShardSuffix%` in test.neon.
			$shard = getenv('TEST_SHARD') ?: ($_COOKIE['test_shard'] ?? null);
			$valid = is_string($shard) && preg_match('/^[1-9][0-9]?$/', $shard);
			$this->configurator->addStaticParameters(['testShardSuffix' => $valid ? '_' . $shard : '']);
		}

		return $this->configurator->createContainer();
	}
}
