<?php

declare(strict_types=1);

namespace ADT\Configurator\Tests;

use ADT\DebugToken;
use PHPUnit\Framework\TestCase;

final class DebugTokenTest extends TestCase
{
	private const Now = 1_800_000_000;
	private const Expiry = self::Now + 3600;

	private string $developer;
	private string $secretKey;

	protected function setUp(): void
	{
		[$this->developer, $this->secretKey] = self::sshKey('JohnDoe');
	}

	public function testTokenForHostIsValidOnlyThere(): void
	{
		$token = $this->token(self::Expiry, 'www.example.cz');

		$this->assertTrue(DebugToken::verify($token, [$this->developer], 'www.example.cz', self::Now));
		// port and case of the request host do not matter
		$this->assertTrue(DebugToken::verify($token, [$this->developer], 'WWW.Example.cz:4434', self::Now));
		$this->assertFalse(DebugToken::verify($token, [$this->developer], 'www.other.cz', self::Now));
	}

	public function testTokenForAnyHostIsValidEverywhere(): void
	{
		$token = $this->token(self::Expiry, DebugToken::AnyHost);

		$this->assertTrue(DebugToken::verify($token, [$this->developer], 'www.example.cz', self::Now));
		$this->assertTrue(DebugToken::verify($token, [$this->developer], 'admin.other.cz', self::Now));
	}

	public function testExpiredTokenIsRejected(): void
	{
		$token = $this->token(self::Expiry, DebugToken::AnyHost);

		$this->assertFalse(DebugToken::verify($token, [$this->developer], 'www.example.cz', self::Expiry));
	}

	public function testTokenLongerThanMaxLifetimeIsRejected(): void
	{
		$token = $this->token(self::Now + DebugToken::MaxLifetime + 60, DebugToken::AnyHost);

		$this->assertFalse(DebugToken::verify($token, [$this->developer], 'www.example.cz', self::Now));
	}

	public function testTokenOfRemovedOrOtherDeveloperIsRejected(): void
	{
		[$other] = self::sshKey('JohnDoe');
		$token = $this->token(self::Expiry, DebugToken::AnyHost);

		$this->assertFalse(DebugToken::verify($token, [], 'www.example.cz', self::Now));
		$this->assertFalse(DebugToken::verify($token, [$other], 'www.example.cz', self::Now));
		// the right one among others
		$this->assertTrue(DebugToken::verify($token, [$other, $this->developer], 'www.example.cz', self::Now));
	}

	public function testTamperedTokenIsRejected(): void
	{
		$token = $this->token(self::Expiry, 'www.example.cz');
		$signature = substr($token, strrpos($token, '.') + 1);

		$longer = (self::Expiry + 60) . '.www.example.cz.' . $signature;
		$everywhere = self::Expiry . '.*.' . $signature;
		$this->assertFalse(DebugToken::verify($longer, [$this->developer], 'www.example.cz', self::Now));
		$this->assertFalse(DebugToken::verify($everywhere, [$this->developer], 'www.other.cz', self::Now));

		// other encodings of the same signature (unused bits of the last character) are rejected too
		$last = substr($signature, -1);
		foreach (str_split('AQgw') as $char) {
			$variant = self::Expiry . '.www.example.cz.' . substr($signature, 0, -1) . $char;
			$this->assertSame($char === $last, DebugToken::verify($variant, [$this->developer], 'www.example.cz', self::Now));
		}
	}

	public function testMalformedCookiesAndOtherKeysAreIgnored(): void
	{
		$keys = [$this->developer, 'JohnDoe@' . password_hash('x', PASSWORD_DEFAULT), 'JohnDoe@ssh-rsa AAAAB3', 'x'];
		foreach ([null, '', ['x'], 'JohnDoe@password', '1.*.x', self::Expiry . '.*.' . str_repeat('A', 86)] as $cookie) {
			$this->assertFalse(DebugToken::verify($cookie, $keys, 'www.example.cz', self::Now));
		}
	}

	public function testCreateFromSignatureOfSshKeygen(): void
	{
		exec('command -v ssh-keygen', $output, $code);
		if ($code !== 0) {
			$this->markTestSkipped('ssh-keygen is not installed');
		}
		$dir = sys_get_temp_dir() . '/adt-debug-token-' . uniqid();
		mkdir($dir);
		try {
			exec('ssh-keygen -q -t ed25519 -N "" -C test -f ' . escapeshellarg($dir . '/key'));
			$expiry = time() + 3600;
			file_put_contents($dir . '/message', DebugToken::message($expiry, '*'));
			exec('ssh-keygen -Y sign -n nette-debug -f ' . escapeshellarg($dir . '/key') . ' ' . escapeshellarg($dir . '/message') . ' 2>/dev/null');

			$token = DebugToken::create($expiry, '*', (string) file_get_contents($dir . '/message.sig'));
			$developer = 'Test@' . trim((string) file_get_contents($dir . '/key.pub'));
			$this->assertTrue(DebugToken::verify($token, [$developer], 'www.example.cz'));
		} finally {
			exec('rm -rf ' . escapeshellarg($dir));
		}
	}

	public function testCreateRejectsSignatureOfOtherNamespace(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		DebugToken::create(self::Expiry, '*', base64_encode('SSHSIG' . pack('N', 1) . self::str('k') . self::str('file') . self::str('') . self::str('sha512') . self::str('s')));
	}

	private function token(int $expiry, string $host): string
	{
		$signature = sodium_crypto_sign_detached(DebugToken::signedData(DebugToken::message($expiry, $host)), $this->secretKey);
		$sshsig = 'SSHSIG' . pack('N', 1) . self::str('key') . self::str('nette-debug') . self::str('') . self::str('sha512')
			. self::str(self::str('ssh-ed25519') . self::str($signature));
		return DebugToken::create($expiry, $host, "-----BEGIN SSH SIGNATURE-----\n" . chunk_split(base64_encode($sshsig), 70) . "-----END SSH SIGNATURE-----\n");
	}

	/** @return array{string, string} line of APP_DEVELOPERS and the secret key */
	private static function sshKey(string $slug): array
	{
		$keyPair = sodium_crypto_sign_keypair();
		$blob = self::str('ssh-ed25519') . self::str(sodium_crypto_sign_publickey($keyPair));
		return [$slug . '@ssh-ed25519 ' . base64_encode($blob) . ' comment', sodium_crypto_sign_secretkey($keyPair)];
	}

	private static function str(string $value): string
	{
		return pack('N', strlen($value)) . $value;
	}
}
