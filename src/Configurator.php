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
				($argv = $_SERVER['argv'])
				&&
				isset($argv[1])
				&&
				substr($argv[1], 0, 4) === 'env:'
			) {
				unset($_SERVER['argv'][1]);

				// env:<environment>
				$this->parameters['environment'] = $httpHost = substr($argv[1], 4);
			}

			if (isset($_SERVER['HTTP_HOST'])) {
				$httpHost = $_SERVER['HTTP_HOST'];
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
	 * Adds first existing config file descending on priority:
	 * - config.remote.<environment>.neon
	 * - config.local.neon
	 * @param string Path to folder with configuration files.
	 * @return self
	 */
	public function addEnvironmentConfig($configFolder) {
		
		$configFiles = [
			'config.remote.'. $this->parameters['environment'] .'.neon',
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
