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
	 * Translation from domain to environment.
	 * @var array
	 */
	protected $domains;

	public function setDomains($domains)
	{
		$this->domains = $domains;
		return $this;
	}


	/**
	 * Http host, so you can set your own. Handy when you want to handle subdomains.
	 * @var string
	 */
	protected $httpHost;

	public function setHttpHost($httpHost)
	{
		$this->httpHost = $httpHost;
		return $this;
	}

	public function getHttpHost()
	{
		if ($this->httpHost !== NULL) {
			return $this->httpHost;
		}

		if (isset($_SERVER['HTTP_HOST'])) {
			return $_SERVER['HTTP_HOST'];
		}

		return NULL;
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
	 * Sets the environment variable. If self::AUTO is passed, environment is
	 * computed using domain list or argv. Possibilities:
	 * - Write "env:<environment>" as first parameter of CLI command.
	 * - Write "env:<http_host>" as first parameter of CLI command.
	 * - Do request to domain specified by self::setDomains.
	 * @param string|boolean $environment
	 * @return self
	 */
	public function setEnvironment($environment = self::AUTO) {

		if ($environment === self::AUTO) {

			$httpHost = NULL;

			if (
				php_sapi_name() === 'cli'
				&&
				($argument = static::popServerArgv(1, 'env')) !== NULL
			) {
				// env:<environment>
				$this->parameters['environment'] = $httpHost = $argument;
			}

			if ($this->getHttpHost()) {
				$httpHost = $this->getHttpHost();
			}

			if (isset($this->domains[$httpHost])) {
				// env:<http_host>
				$this->parameters['environment'] = $this->domains[$httpHost];
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

		// Environment is deprecated in Nette 2.4
		if (! isset($this->parameters['environment'])) {
			return $this->parameters['debugMode'] ? 'development' : 'production';
		}

		return $this->parameters['environment'];
	}

	/**
	 *
	 * @param int $index
	 * @param string $name
	 */
	public static function popServerArgv($index, $name) {

		if (!isset($_SERVER['argv'])) return NULL;

		$argv = $_SERVER['argv'];

		if (!isset($argv[$index])) return NULL;

		$argument = $argv[$index];
		$nameLen = strlen($name) + 1;
		$name .= ':';

		if (strncmp($argument, $name, $nameLen) !== 0) return NULL;

		unset($_SERVER['argv'][$index]);
		$_SERVER['argv'] = array_values($_SERVER['argv']);
		$_SERVER['argc']--;

		return substr($argument, $nameLen);
	}


	/**
	 * Adds first existing config file descending on priority:
	 * - config.remote.<environment>.neon
	 * - config.local.neon
	 * @param string Path to folder with configuration files.
	 * @return self
	 */
	public function addEnvironmentConfig($configFolder) {

		$configFiles = [
			'config.remote.'. $this->getEnvironment() .'.neon',
			'config.local.neon',
		];

		foreach ($configFiles as $configFile) {
			$fullPath = "$configFolder/$configFile";

			if (! file_exists($fullPath)) continue;

			$this->addConfig($fullPath);
			break;
		}

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
		$public = $slug .'@'. \Nette\Security\Passwords::hash($password);

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

		return \Nette\Security\Passwords::verify($password, $developer['hash']);
	}

	protected static function explodeKeySlug($key)
	{
		return explode('@', $key, 2);
	}

}
