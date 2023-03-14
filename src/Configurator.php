<?php

namespace ADT;

final class Configurator extends \Nette\Bootstrap\Configurator
{
	/**
	 * Allowed IP addresses for debugging.
	 */
	private static array $debugIps = [];

	/**
	 * Developers allowed debugging.
	 */
	private static array $developers = [];

	private static array $parameters = [];

	private static bool $debugMode = false;

	private string $configDirectory = '';


	public function loadEnv(string $envDirectory, array $additionalFiles = []): self
	{
		$envs = [];
		foreach (array_merge($additionalFiles, ['.env']) as $_envFile) {
			 $envs = array_merge(
				 (new \josegonzalez\Dotenv\Loader($envDirectory . '/' . $_envFile))
					->parse()
					->skipExisting()
					->toArray(),
			 	$envs
			);
		}

		$_ENV = array_merge($envs, $_ENV);

		$this->addStaticParameters(['env' => array_intersect_key($_ENV, $envs)]);

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
			throw new \Exception('Use ' . __METHOD__ . '() before setDebugMode().');
		}

		$publicKey = (array) $publicKey;
		
		foreach ($publicKey as $_publicKey) {
			list($slug, $hash) = self::explodeKeySlug($_publicKey);
			self::$developers[$slug] = $hash;
		}

		return $this;
	}


	public function setDebugMode(bool|string|array $value): static
	{
		if (!is_bool($value)) {
			throw new \Exception('Parameter "value" must be of type boolean.');
		}

		self::$debugMode = $value;

		return parent::setDebugMode(self::detectDebugMode());
	}


	public function addConfig(string|array|null $config): static
	{
		$config = (array) $config;

		$config = array_filter($config);
		
		foreach ($config as $_config) {
			$_configPath = $this->configDirectory ? $this->configDirectory . '/' . $_config . '.neon' : $_config;

			if (!file_exists($_configPath)) {
				throw new \Exception('Config file "' . $_configPath . '" does not exist.');
			}

			$parts = explode('/', $_config);
			if (count($parts) === 2) {
				if (isset(self::$parameters[$parts[0]])) {
					throw new \Exception('You are trying to include more "' . $parts[0] . '" configs.');
				}

				self::$parameters[$parts[0]] = $parts[1];
			}
			parent::addConfig($_configPath);
		}
		
		return $this;
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
}
