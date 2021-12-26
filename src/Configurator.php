<?php

namespace ADT;

class Configurator extends \Nette\Configurator
{

	/**
	 * Allowed IP addresses for debugging.
	 * @var array
	 */
	protected $ips;

	public function setIps($ips)
	{
		$this->ips = $ips;
		return $this;
	}


	/**
	 * Translation from URL to environment.
	 * @var string[]
	 */
	protected $urls = [];

	/**
	 * @param string[] $urls
	 * @return $this
	 */
	public function setUrls($urls)
	{
		$this->urls = $urls;
		return $this;
	}

	/**
	 * @deprecated Use method setUrls() instead.
	 * @param string[] $domains
	 */
	public function setDomains($domains)
	{
		$this->setUrls($domains);
	}


	/**
	 * Developers allowed debugging.
	 * @var array
	 */
	protected $developers;

	/**
	 * Adds new developer.
	 * @param string Developer's public key obtained by self::newDeveloper.
	 * @param boolean Can the developer debug from any IP?
	 */
	public function addDeveloper($publicKey, $ipIndependent = FALSE)
	{
		list($slug, $hash) = static::explodeKeySlug($publicKey);
		$this->developers[$slug] = [
			'hash' => $hash,
			'ipIndependent' => $ipIndependent,
		];
		return $this;
	}

	/**
	 * Config file name mask.
	 * @var string
	 */
	protected $configFile = 'config.%environment%.neon';

	/**
	 * Sets config file name mask.
	 * @param string $configFile
	 * @return $this
	 */
	public function setConfigFile($configFile)
	{
		$this->configFile = $configFile;
		return $this;
	}


	/**
	 * Set parameter %debugMode%.
	 * @param  bool|string|array
	 * @return self
	 */
	public function setDebugMode($value = NULL)
	{
		if ($value === NULL) {
			$value = $this->detectDebugModeByKey();
		}

		return parent::setDebugMode($value);
	}


	/**
	 * Sets the environment variable. If NULL is passed, environment is
	 * computed using URL list and argv. Possibilities:
	 * - Write "--env <environment>" as parameter of CLI command.
	 * - Do request to URL specified by self::setUrls.
	 * @param string|null $environment
	 * @return self
	 */
	public function setEnvironment($environment = null) {

		if ($environment === null) {

			if (php_sapi_name() === 'cli') {
				// CLI

				// --env <environment>
				if (($argument = static::getServerArgv('env')) === null) {
					throw new \Exception("Parameter '--env' is required.");
				}

				$this->staticParameters['environment'] = $argument;
			} else {
				// HTTP request

				if (! isset($_SERVER['HTTP_HOST'])) {
					throw new \Exception('Variable \'$_SERVER[HTTP_HOST]\' is not set.');
				}

				if (! isset($_SERVER['REQUEST_URI'])) {
					throw new \Exception('Variable \'$_SERVER[REQUEST_URI]\' is not set.');
				}

				// Exclude server port and parameters.
				$requestUrl = explode(':', $_SERVER['HTTP_HOST'])[0] . explode('?', $_SERVER['REQUEST_URI'])[0];

				foreach ($this->urls as $url => $env) {
					if (strpos($url, '^') === 0) {
						// Key in $this->urls can be regex (starts with '^').
						if (preg_match("\x01$url\x01", $requestUrl)) {
							$this->staticParameters['environment'] = $env;
							break;
						}
					} else {
						// Or just a regular string.
						if (strpos($requestUrl . '/',  $url . '/') === 0) {
							// $requestUrl starts with $url and continues with a slash or ends.
							$this->staticParameters['environment'] = $env;
							break;
						}
					}
				}
			}
		} else {
			$this->staticParameters['environment'] = $environment;
		}

		return $this;
	}

	/**
	 * @return string|boolean
	 */
	public function getEnvironment() {

		if (! isset($this->staticParameters['environment'])) {
			throw new \Exception('Environment is not set.');
		}

		return $this->staticParameters['environment'];
	}


	/**
	 * @param string $name
	 * @return string|NULL
	 */
	public static function getServerArgv($name) {

		$prevArgv = NULL;
		$result = NULL;

		foreach ($_SERVER['argv'] as $argv) {
			if ($prevArgv === "--$name") { // --env <value>
				$result = $argv;
				$prevArgv = null;
			} elseif (strpos($argv, "--$name=") === 0) { // --env=<value>
				list(, $result) = explode('=', $argv);
			} else {
				$prevArgv = $argv;
			}
		}

		return $result;
	}


	public function addEnvironmentConfig(): self
	{
		$fullPath = ($this->configPath ? $this->configPath . '/' : '') . str_replace('%environment%', $this->getEnvironment(), $this->configFile);

		if (! file_exists($fullPath)) {
			throw new \Nette\FileNotFoundException("Config file '$fullPath' not found!");
		}

		$this->addConfig($fullPath);

		return $this;
	}
	
	
	public function addConfig($config)
	{
		return parent::addConfig($this->configPath . '/' . $config);
	}


	/**
	 * Dumps public and secret key for the new developer.
	 * @param string Slug which serves as public key comment. It's typically
	 * developer's name. Can contain only these characters: '0-9A-Za-z./'.
	 */
	public static function newDeveloper($slug)
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
	 * @return bool
	 */
	protected function detectDebugModeByKey() {

		if (getenv('NETTE_DEBUG')) {
			// You can overwrite this in your bootstrap.php by calling `->setDebugMode(php_sapi_name() == 'cli' ? FALSE : NULL)`.
			return TRUE;
		}

		if (!isset($_COOKIE[self::COOKIE_SECRET]) || !is_string($_COOKIE[self::COOKIE_SECRET])) {
			return FALSE;
		}

		$secret = $_COOKIE[self::COOKIE_SECRET];
		list($slug, $password) = static::explodeKeySlug($secret);

		if (!isset($this->developers[$slug])) {
			return FALSE;
		}

		$developer = $this->developers[$slug];

		if (!$developer['ipIndependent'] && !parent::detectDebugMode($this->ips)) {
			return FALSE;
		}

		return password_verify($password, $developer['hash']);
	}

	protected static function explodeKeySlug($key)
	{
		return explode('@', $key, 2);
	}

	/**
	 * @param  string        error log directory
	 * @param  string        administrator email
	 * @return void
	 */
	public function enableDebugger(?string $logDirectory = NULL, ?string $email = NULL): void {
		\Tracy\Debugger::$logSeverity = E_ALL;
		parent::enableDebugger($logDirectory, $email);
	}

	public function enableTracy(string $logDirectory = null, string $email = null): void {
		\Tracy\Debugger::$logSeverity = E_ALL;
		parent::enableTracy($logDirectory, $email);
	}
	

	public string $configPath = '';
	
	public function setConfigPath(string $configPath): self
	{
		$this->configPath = $configPath;
		
		return $this;
	}
	
	public function getConfigPath(): string
	{
		return $this->configPath;
	}

}
