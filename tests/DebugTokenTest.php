<?php

declare(strict_types=1);

namespace ADT\Configurator\Tests;

use ADT\DebugToken;
use PHPUnit\Framework\TestCase;

final class DebugTokenTest extends TestCase
{
	private const Now = 1_800_000_000;

	private string $public;
	private string $secret;

	protected function setUp(): void
	{
		[$this->public, $this->secret] = DebugToken::newKeyPair('JohnDoe');
	}

	public function testTokenOfDeveloperIsValidOnItsHost(): void
	{
		$token = DebugToken::create($this->secret, 'www.example.cz', 3600, self::Now);

		$this->assertTrue(DebugToken::verify($token, [$this->public], 'www.example.cz', self::Now));
		// port and case of the request host do not matter
		$this->assertTrue(DebugToken::verify($token, [$this->public], 'WWW.Example.cz:4434', self::Now));
	}

	public function testTokenIsBoundToHost(): void
	{
		$token = DebugToken::create($this->secret, 'www.example.cz', 3600, self::Now);

		$this->assertFalse(DebugToken::verify($token, [$this->public], 'www.other.cz', self::Now));
	}

	public function testExpiredTokenIsRejected(): void
	{
		$token = DebugToken::create($this->secret, 'www.example.cz', 3600, self::Now);

		$this->assertFalse(DebugToken::verify($token, [$this->public], 'www.example.cz', self::Now + 3600));
	}

	public function testTokenLongerThanMaxLifetimeIsRejected(): void
	{
		// signed for the future by a developer who skipped the limit of create()
		$token = DebugToken::create($this->secret, 'www.example.cz', DebugToken::MaxLifetime, self::Now + 3600);

		$this->assertFalse(DebugToken::verify($token, [$this->public], 'www.example.cz', self::Now));
	}

	public function testTokenOfRemovedOrOtherDeveloperIsRejected(): void
	{
		[$otherPublic] = DebugToken::newKeyPair('JohnDoe');
		$token = DebugToken::create($this->secret, 'www.example.cz', 3600, self::Now);

		$this->assertFalse(DebugToken::verify($token, [], 'www.example.cz', self::Now));
		$this->assertFalse(DebugToken::verify($token, [$otherPublic], 'www.example.cz', self::Now));
	}

	public function testTamperedTokenIsRejected(): void
	{
		$token = DebugToken::create($this->secret, 'www.example.cz', 3600, self::Now);
		[$slug, $expiry, $signature] = explode('.', $token);

		$longer = $slug . '.' . ($expiry + 60) . '.' . $signature;
		$this->assertFalse(DebugToken::verify($longer, [$this->public], 'www.example.cz', self::Now));
	}

	public function testMalformedCookiesAndFormerKeysAreRejected(): void
	{
		$bcrypt = 'JohnDoe@' . password_hash('x', PASSWORD_DEFAULT);
		foreach ([null, '', ['x'], 'JohnDoe@password', 'JohnDoe.1.x'] as $cookie) {
			$this->assertFalse(DebugToken::verify($cookie, [$this->public, $bcrypt], 'www.example.cz', self::Now));
		}

		$token = DebugToken::create($this->secret, 'www.example.cz', 3600, self::Now);
		$this->assertFalse(DebugToken::verify($token, [$bcrypt], 'www.example.cz', self::Now));
	}

	public function testPublicKeyLineFromKeyFile(): void
	{
		$this->assertSame($this->public, DebugToken::publicKeyLine($this->secret . "\n"));
	}
}
