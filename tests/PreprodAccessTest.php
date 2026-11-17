<?php

declare(strict_types=1);

namespace ADT\Configurator\Tests;

use ADT\Configurator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreprodAccessTest extends TestCase
{
	private const ALLOWED = '78.80.93.27, 203.0.113.0/24, 2001:db8::/32';

	/** @return array<string, array{array<string, string>, string, bool}> */
	public static function requests(): array
	{
		$edge = '172.18.0.5';
		return [
			'not preprod' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '1.2.3.4'], self::ALLOWED, true],
			'production port' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_PORT' => '443'], self::ALLOWED, true],
			'empty list = open' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_PORT' => '4434'], ' ', true],
			'foreign IP via edge' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, false],
			'listed IP via edge' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '78.80.93.27', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, true],
			'CIDR via edge' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '203.0.113.200', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, true],
			'outside CIDR' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '203.0.114.1', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, false],
			'IPv6 CIDR' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '2001:db8:1::1', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, true],
			'real_ip of nginx on' => [['REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, false],
			'public client cannot claim X-Real-IP' => [['REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '78.80.93.27', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, false],
			'smoke check from the bridge' => [['REMOTE_ADDR' => '172.17.0.1', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, true],
			'loopback' => [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, true],
			'VPN' => [['REMOTE_ADDR' => '10.8.0.6', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, true],
			'invalid X-Real-IP' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => 'garbage', 'HTTP_X_FORWARDED_PORT' => '4434'], self::ALLOWED, true],
			'invalid entry matches nothing' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_PORT' => '4434'], '1.2.3.4/x, 1.2.3.0/40, nonsense', false],
			'IPv4 client, IPv6 range' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '32.1.13.184', 'HTTP_X_FORWARDED_PORT' => '4434'], '2001:db8::/32', false],
			'/0 opens everything' => [['REMOTE_ADDR' => $edge, 'HTTP_X_REAL_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_PORT' => '4434'], '0.0.0.0/0', true],
		];
	}

	/** @param array<string, string> $server */
	#[DataProvider('requests')]
	public function testIsPreprodAllowed(array $server, string $allowed, bool $expected): void
	{
		$this->assertSame($expected, Configurator::isPreprodAllowed($server, $allowed));
	}
}
