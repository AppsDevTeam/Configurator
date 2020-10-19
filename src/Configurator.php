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

				$this->parameters['environment'] = $argument;
			} else {
				// HTTP request

				if (! isset($_SERVER['HTTP_HOST'])) {
					throw new \Exception('Variable \'$_SERVER[HTTP_HOST]\' is not set.');
				}

				if (! isset($_SERVER['REQUEST_URI'])) {
					throw new \Exception('Variable \'$_SERVER[REQUEST_URI]\' is not set.');
				}

				$httpHost = explode(":", $_SERVER['HTTP_HOST'])[0];
				$requestUri = $_SERVER['REQUEST_URI'];

				foreach ([$httpHost . $requestUri, $httpHost] as $url) {
					if (isset($this->urls[$url])) {
						$this->parameters['environment'] = $this->urls[$url];
						break;
					} else {
						// Key in $this->urls can be regex.

						$regexUrls = array_filter(array_keys($this->urls), function ($s) {
							return substr($s, 0, 1) === '^'; // Regex starts with '^'.
						});

						foreach ($regexUrls as $regexUrl) {
							if (preg_match("\x01$regexUrl\x01", $url)) {
								$this->parameters['environment'] = $this->urls[$regexUrl];
								break 2;
							}
						}
					}
				}
			}
		} else {
			$this->parameters['environment'] = $environment;
		}

		return $this;
	}

	/**
	 * @return string|boolean
	 */
	public function getEnvironment() {

		if (! isset($this->parameters['environment'])) {
			throw new \Exception('Environment is not set.');
		}

		return $this->parameters['environment'];
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


	/**
	 * @param string Path to folder with configuration files.
	 * @return self
	 */
	public function addEnvironmentConfig($configFolder) {

		$configFile = str_replace('%environment%', $this->getEnvironment(), $this->configFile);
		$fullPath = "$configFolder/$configFile";

		if (! file_exists($fullPath)) {
			throw new \Nette\FileNotFoundException("Config file '$fullPath' not found!");
		}

		$this->addConfig($fullPath);

		return $this;
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

}
