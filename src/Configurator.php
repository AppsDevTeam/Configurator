<?php

namespace ADT;

use Exception;
use josegonzalez\Dotenv\Loader;
use Nette\Utils\Random;
use Tracy\Debugger;

final class Configurator extends \Nette\Bootstrap\Configurator
{
	private static array $parameters = [];

	private string $configDirectory = '';


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
			$_value = $this->convertString($_value);
		}

		// arguments of array_intersect_key has to be in this order
		$this->addStaticParameters(['env' => array_intersect_key($_ENV, $envs)]);

		return $this;
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

	private function convertString($str): float|bool|int|string
	{
		if (!is_string($str)) {
			return $str;
		}

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
