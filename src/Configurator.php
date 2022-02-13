<?php

namespace ADT;

use Dotenv\Dotenv;

final class Configurator extends \Nette\Configurator
{
	/**
	 * Allowed IP addresses for debugging.
	 */
	protected static array $debugIps = [];

	/**
	 * Developers allowed debugging.
	 */
	protected static array $developers = [];

	private static bool $debugMode = false;

	public string $configDirectory = '';


	public function loadEnv(string $envDirectory, array $additionalFiles = []): self
	{
		$env = Dotenv::createImmutable($envDirectory, ['.env.common', '.env'], false)->load();
		$this->addStaticParameters(['env' => $env]);
		return $this;
	}


	public function setDebugIps(array $ips): self
	{
		self::$debugIps = $ips;
		return $this;
	}


	/**
	 * @param string|array $publicKey
	 * @return $this
	 */
	public function addDeveloper($publicKey): self
	{
		if (self::$debugMode) {
			throw new \Exception('Use ' . __METHOD__ . '() before setDebugMode()');
		}

		$publicKey = (array) $publicKey;
		
		foreach ($publicKey as $_publicKey) {
			list($slug, $hash) = self::explodeKeySlug($_publicKey);
			self::$developers[$slug] = $hash;
		}

		return $this;
	}


	public function setDebugMode($value): self
	{
		if (!is_bool($value)) {
			throw new \Exception('Parameter "value" must be of type boolean.');
		}

		self::$debugMode = $value;

		return parent::setDebugMode(self::detectDebugMode());
	}


	/**
	 * @param string|array $config
	 * @return $this
	 */
	public function addConfig($config): self
	{
		$config = (array) $config;
		
		foreach ($config as $_config) {
			return parent::addConfig($this->configDirectory ? $this->configDirectory . '/' . $_config . '.neon' : $_config);
		}
		
		return $this;
	}


	/**
	 * Dumps public and secret key for the new developer.
	 * @param string Slug which serves as public key comment. It's typically
	 * developer's name. Can contain only these characters: '0-9A-Za-z./'.
	 */
	public static function newDeveloper(string $slug): void
	{
		$password = \Nette\Utils\Random::generate(32, '!-~');	// This is sufficient for strong security.
		$secret = $slug .'@'. $password;
		$public = $slug .'@'. password_hash($password, PASSWORD_DEFAULT);

		echo "<h1>Public key</h1>";
		\Tracy\Debugger::dump($public);
		echo "<h1>Secret key</h1>";
		\Tracy\Debugger::dump($secret);
		echo "<h1>Secret key (URL encoded)</h1>";
		\Tracy\Debugger::dump(urlencode($secret));
		die();
	}


	/**
	 * Detects debug mode by public and private keys stored in cookie.
	 */
	public static function detectDebugMode($list = null): bool
	{
		if ($list) {
			throw new \Exception('Use "setDebugIps" method instead of "list" parameter.');
		}

		if (Configurator::isCli() || !self::$developers) {
			return self::$debugMode;
		}

		if (empty($_COOKIE[self::COOKIE_SECRET])) {
			return false;
		}
		
		$parts = static::explodeKeySlug($_COOKIE[self::COOKIE_SECRET]);
		if (count($parts) !== 2) {
			return false;
		}
		list($slug, $password) = $parts;

		return
			isset(self::$developers[$slug])
			&&
			(!self::$debugIps || parent::detectDebugMode(self::$debugIps))
			&&
			password_verify($password, self::$developers[$slug]);
	}


	/**
	 * @param  string        error log directory
	 * @param  string        administrator email
	 * @return void
	 */
	public function enableDebugger(?string $logDirectory = NULL, ?string $email = NULL): void 
	{
		\Tracy\Debugger::$logSeverity = E_ALL;
		parent::enableDebugger($logDirectory, $email);
	}


	public function enableTracy(string $logDirectory = null, string $email = null): void 
	{
		\Tracy\Debugger::$logSeverity = E_ALL;
		parent::enableTracy($logDirectory, $email);
	}


	public function setConfigDirectory(string $configDirectory): self
	{
		$this->configDirectory = $configDirectory;
		return $this;
	}


	public function getConfigDirectory(): string
	{
		return $this->configDirectory;
	}


	public static function isCli(): bool
	{
		return php_sapi_name() === 'cli';
	}


	/**
	 * @throws \Exception
	 */
	public static function parseConfigFrom(string $value): array
	{
		if (!$urls = json_decode($value)) {
			return self::parseConfigFromValue($value);
		}

		if (! isset($_SERVER['HTTP_HOST'])) {
			throw new \Exception('Variable \'$_SERVER[HTTP_HOST]\' is not set.');
		}

		if (! isset($_SERVER['REQUEST_URI'])) {
			throw new \Exception('Variable \'$_SERVER[REQUEST_URI]\' is not set.');
		}

		// Exclude server port and parameters.
		$requestUrl = explode(':', $_SERVER['HTTP_HOST'])[0] . explode('?', $_SERVER['REQUEST_URI'])[0];

		foreach ($urls as $url => $value) {
			if (strpos($url, '^') === 0) {
				// Key in $this->urls can be regex (starts with '^').
				if (preg_match("\x01$url\x01", $requestUrl)) {
					return self::parseConfigFromValue($value);
				}
			} else {
				// Or just a regular string.
				if (strpos($requestUrl . '/',  $url . '/') === 0) {
					// $requestUrl starts with $url and continues with a slash or ends.
					return self::parseConfigFromValue($value);
				}
			}
		}

		return [];
	}

	private static function parseConfigFromValue(string $value): array
	{
		return explode(',', $value);
	}


	private static function explodeKeySlug(string $key): array
	{
		return explode('@', $key);
	}
}
