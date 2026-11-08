<?php

namespace ADT;

use Exception;
use josegonzalez\Dotenv\Loader;
use Nette\DI\ContainerLoader;
use Nette\Utils\Random;
use Tracy\Debugger;

final class Configurator extends \Nette\Bootstrap\Configurator
{
	private static array $parameters = [];

	private string $configDirectory = '';

	private ?bool $autoRebuild = null;

	private bool $debugModeByCookie = false;


	public function loadEnv(string $envDirectory, array $additionalFiles = []): self
	{
		$envs = [];
		foreach (array_merge($additionalFiles, ['.env']) as $_envFile) {
			$envs = array_merge(
				(new Loader($envDirectory . '/' . $_envFile))
					->parse()
					->skipExisting()
					->toArray(),
				$envs
			);
		}

		// arguments of array_merge has to be in this order
		$_ENV = array_merge($envs, $_ENV);

		foreach ($_ENV as &$_value) {
			if (!is_string($_value)) {
				continue;
			}

			$_value = $this->convertString($_value);
		}

		// arguments of array_intersect_key has to be in this order
		$this->addStaticParameters(['env' => array_intersect_key($_ENV, $envs)]);

		return $this;
	}


	/**
	 * Maintenance window of a deploy (recipe of adt/docker-deployer, README "Maintenance during
	 * migrations"): while the deploy runs migrations, the file MAINTENANCE_FILE exists on all
	 * servers, so that the application holds no metadata locks of the tables being changed.
	 * Commands of the deploy have MAINTENANCE_BYPASS=1 - read by getenv(), $_ENV holds the empty
	 * value from .env. Call after loadEnv().
	 *
	 * @phpstan-impure
	 */
	public static function isMaintenance(): bool
	{
		$file = $_ENV['MAINTENANCE_FILE'] ?? getenv('MAINTENANCE_FILE');
		if (!$file || getenv('MAINTENANCE_BYPASS')) {
			return false;
		}

		clearstatcache(true, $file);
		return file_exists($file);
	}


	/**
	 * Handles the maintenance window before the DI container is created (no database connection):
	 * - web: 503 with Retry-After ($template = own page, otherwise a default one)
	 * - console: ends with exit code 0 (cron jobs skip a run), except $waitingCommands, which wait
	 *   until the maintenance ends and then continue - supervisor would restart a consumer that
	 *   exits in a loop
	 *
	 * @param list<string> $waitingCommands
	 */
	public function handleMaintenance(?string $template = null, array $waitingCommands = ['background-queue:consume']): void
	{
		if (!self::isMaintenance()) {
			return;
		}

		if (self::isCli()) {
			// Anywhere in argv, not just argv[1]: `bin/console -v background-queue:consume` is the consumer too
			if (array_intersect($waitingCommands, array_slice($_SERVER['argv'] ?? [], 1)) !== []) {
				while (self::isMaintenance()) {
					sleep(5);
				}
				return;
			}
			echo "Maintenance (database migrations) - the command is skipped.\n";
			exit(0);
		}

		http_response_code(503);
		header('Retry-After: 60');
		header('Cache-Control: no-store');
		require $template ?? __DIR__ . '/maintenance.phtml';
		exit;
	}


	/**
	 * @throws Exception
	 */
	public function addConfig(string|array|null $config): static
	{
		$config = (array) $config;

		$config = array_filter($config);

		foreach ($config as $_config) {
			$_configPath = $this->configDirectory ? $this->configDirectory . '/' . $_config . '.neon' : $_config;

			if (!file_exists($_configPath)) {
				throw new Exception('Config file "' . $_configPath . '" does not exist.');
			}

			$parts = explode('/', $_config);
			if (count($parts) === 2) {
				if (isset(self::$parameters[$parts[0]])) {
					throw new Exception('You are trying to include more "' . $parts[0] . '" configs.');
				}

				self::$parameters[$parts[0]] = $parts[1];
			}

			parent::addConfig($_configPath);
		}

		return $this;
	}


	public function enableTracy(?string $logDirectory = null, ?string $email = null): void
	{
		Debugger::$logSeverity = E_ALL;
		parent::enableTracy($logDirectory, $email);
	}


	/**
	 * Debug mode enabled by a developer cookie (list of developer keys) means a deployed server,
	 * whose code does not change between deploys - the DI container is then never rebuilt, the same
	 * as in production mode. With auto-rebuild nette/di invalidates the container in opcache on every
	 * request (ContainerLoader::includeFreshFile), so each debug request recompiles it and wastes
	 * opcache memory until opcache restarts.
	 */
	public function setDebugMode(bool|string|array $value): static
	{
		$this->debugModeByCookie = !is_bool($value);
		return parent::setDebugMode($value);
	}


	/**
	 * Overrides whether the DI container is rebuilt when its sources change (default: debug mode,
	 * except debug mode enabled by a developer cookie).
	 */
	public function setAutoRebuild(bool $autoRebuild): self
	{
		$this->autoRebuild = $autoRebuild;
		return $this;
	}


	public function loadContainer(): string
	{
		$loader = new ContainerLoader(
			$this->getCacheDirectory() . '/nette.configurator',
			$this->autoRebuild ?? ($this->staticParameters['debugMode'] && !$this->debugModeByCookie),
		);
		return $loader->load(
			$this->generateContainer(...),
			$this->generateContainerKey(),
		);
	}


	public function setConfigDirectory(string $configDirectory): self
	{
		$this->configDirectory = $configDirectory;
		return $this;
	}


	/**
	 * Dumps public and secret key for the new developer.
	 * @param string $slug which serves as public key comment. It's typically
	 * developer's name. Can contain only these characters: '0-9A-Za-z./'.
	 */
	public static function newDeveloper(string $slug): void
	{
		$password = Random::generate(32, '!-~');	// This is sufficient for strong security.
		$secret = $slug .'@'. $password;
		$public = $slug .'@'. password_hash($password, PASSWORD_DEFAULT);

		echo "<h1>Public key</h1>";
		Debugger::dump($public);
		echo "<h1>Secret key</h1>";
		Debugger::dump($secret);
		echo "<h1>Secret key (URL encoded)</h1>";
		Debugger::dump(urlencode($secret));
		die();
	}


	/**
	 * @throws Exception
	 */
	public static function detectDebugMode(string|array|null $list = null): bool
	{
		if (is_null($list)) {
			return false;
		}

		if (is_string($list)) {
			$list = [$list];
		}

		if (php_sapi_name() === 'cli') {
			throw new Exception('Not allowed for CLI.');
		}

		$developers = [];
		foreach ($list as $_developer) {
			list($slug, $hash) = self::explodeKeySlug($_developer);
			$developers[$slug] = $hash;
		}
		if (empty($_COOKIE[self::CookieSecret])) {
			return false;
		}
		$parts = self::explodeKeySlug($_COOKIE[self::CookieSecret]);
		if (count($parts) !== 2) {
			return false;
		}
		list($slug, $password) = $parts;

		return
			isset($developers[$slug])
			&&
			password_verify($password, $developers[$slug]);
	}


	public static function isCli(): bool
	{
		return php_sapi_name() === 'cli';
	}


	public static function get($name): string
	{
		return self::$parameters[$name] ?? '';
	}

	private static function explodeKeySlug(string $key): array
	{
		// we have to set $limit because the password part can contain @
		return explode('@', $key, 2);
	}

	private function convertString(string $str): float|bool|int|string|null
	{
		// Pokud řetězec obsahuje pouze číslice
		if (ctype_digit($str)) {
			// když začíná 0 a má víc jak 1 znak, vratíme string
			if (str_starts_with($str, '0') && strlen($str) > 1) {
				return $str;
			}
			return (int)$str; // jinak vratíme integer
		}

		// Pokud řetězec obsahuje číslice a případně jednu desetinnou tečku, vratíme float
		if (preg_match('/^\d+\.\d+$/', $str)) {
			return (float)$str;
		}

		// Pro "true" a "false" vrátíme boolean
		if (strtolower($str) === 'true') {
			return true;
		}

		if (strtolower($str) === 'false') {
			return false;
		}

		// V opačném případě vratíme původní řetězec
		return $str;
	}

	/**
	 * @throws Exception
	 */
	public static function getConfigByUrl($urls): string
	{
		if (! isset($_SERVER['HTTP_HOST'])) {
			throw new Exception('Variable \'$_SERVER[HTTP_HOST]\' is not set.');
		}

		if (! isset($_SERVER['REQUEST_URI'])) {
			throw new Exception('Variable \'$_SERVER[REQUEST_URI]\' is not set.');
		}

		// Exclude server port and parameters.
		$requestUrl = explode(':', $_SERVER['HTTP_HOST'])[0] . explode('?', $_SERVER['REQUEST_URI'])[0];

		foreach ($urls as $url => $env) {
			if (str_starts_with($url, '^')) {
				// Key in $this->urls can be regex (starts with '^').
				if (preg_match("\x01$url\x01", $requestUrl)) {
					return $env;
				}
			} else {
				// Or just a regular string.
				if (str_starts_with($requestUrl . '/', $url . '/')) {
					// $requestUrl starts with $url and continues with a slash or ends.
					return $env;
				}
			}
		}

		throw new Exception('No env was found.');
	}
}
