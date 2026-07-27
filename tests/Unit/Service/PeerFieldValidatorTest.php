<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\PeerFieldValidator;
use PHPUnit\Framework\TestCase;

/**
 * Peer form input rules (v2.1 write path). Pure validation — no HTTP.
 */
final class PeerFieldValidatorTest extends TestCase
{
	private PeerFieldValidator $validator;

	protected function setUp(): void
	{
		$this->validator = new PeerFieldValidator();
	}

	public function testCreateRequiresName(): void
	{
		$result = $this->validator->validate([], true);
		self::assertArrayHasKey('name', $result['errors']);
	}

	public function testUpdateAllowsOmittedName(): void
	{
		$result = $this->validator->validate(['mtu' => 1420], false);
		self::assertSame([], $result['errors']);
		self::assertSame(['mtu' => 1420], $result['fields']);
	}

	public function testNameIsTrimmed(): void
	{
		$result = $this->validator->validate(['name' => '  field-tablet-1  '], true);
		self::assertSame([], $result['errors']);
		self::assertSame('field-tablet-1', $result['fields']['name']);
	}

	public function testNameLengthCeiling(): void
	{
		$ok = $this->validator->validate(
			['name' => str_repeat('a', PeerFieldValidator::NAME_MAX_LENGTH)],
			true
		);
		self::assertSame([], $ok['errors']);

		$tooLong = $this->validator->validate(
			['name' => str_repeat('a', PeerFieldValidator::NAME_MAX_LENGTH + 1)],
			true
		);
		self::assertArrayHasKey('name', $tooLong['errors']);
	}

	public function testNameRejectsControlCharacters(): void
	{
		$result = $this->validator->validate(['name' => "peer\nInjected = 1"], true);
		self::assertArrayHasKey('name', $result['errors']);
	}

	/**
	 * The Field and Admin presets shipped by the Vue form must both validate.
	 */
	public function testFieldAndAdminPresetsValidate(): void
	{
		$field = $this->validator->validate([
			'name' => 'field-gcs',
			'allowedIps' => '10.0.0.0/24,10.8.0.0/24',
			'persistentKeepalive' => 25,
		], true);
		self::assertSame([], $field['errors']);
		self::assertSame(['10.0.0.0/24', '10.8.0.0/24'], $field['fields']['allowedIps']);
		self::assertSame(25, $field['fields']['persistentKeepalive']);

		$admin = $this->validator->validate([
			'name' => 'admin-laptop',
			'allowedIps' => '0.0.0.0/0',
			'dns' => '1.1.1.1',
			'persistentKeepalive' => 25,
		], true);
		self::assertSame([], $admin['errors']);
		self::assertSame(['0.0.0.0/0'], $admin['fields']['allowedIps']);
		self::assertSame(['1.1.1.1'], $admin['fields']['dns']);
	}

	public function testAllowedIpsAcceptsArrayAndDeduplicates(): void
	{
		$result = $this->validator->validate([
			'allowedIps' => ['10.0.0.0/24', '10.0.0.0/24', '2001:db8::/32'],
		], false);
		self::assertSame([], $result['errors']);
		self::assertSame(['10.0.0.0/24', '2001:db8::/32'], $result['fields']['allowedIps']);
	}

	public function testAllowedIpsRejectsBadCidr(): void
	{
		$result = $this->validator->validate(['allowedIps' => '10.0.0.0/24,not-a-cidr'], false);
		self::assertArrayHasKey('allowedIps', $result['errors']);
		self::assertStringContainsString('not-a-cidr', $result['errors']['allowedIps']);
	}

	public function testAllowedIpsRejectsOversizedPrefix(): void
	{
		$result = $this->validator->validate(['allowedIps' => '10.0.0.0/33'], false);
		self::assertArrayHasKey('allowedIps', $result['errors']);
	}

	public function testEmptyAllowedIpsBecomesNull(): void
	{
		foreach ([null, '', []] as $empty) {
			$result = $this->validator->validate(['allowedIps' => $empty], false);
			self::assertSame([], $result['errors']);
			self::assertNull($result['fields']['allowedIps']);
		}
	}

	public function testDnsRejectsHostnames(): void
	{
		$result = $this->validator->validate(['dns' => 'dns.example.com'], false);
		self::assertArrayHasKey('dns', $result['errors']);
	}

	public function testMtuRange(): void
	{
		self::assertSame([], $this->validator->validate(['mtu' => 1420], false)['errors']);
		self::assertArrayHasKey('mtu', $this->validator->validate(['mtu' => 1023], false)['errors']);
		self::assertArrayHasKey('mtu', $this->validator->validate(['mtu' => 9001], false)['errors']);
		self::assertArrayHasKey('mtu', $this->validator->validate(['mtu' => 'big'], false)['errors']);
	}

	public function testNumericStringsAreCoerced(): void
	{
		$result = $this->validator->validate(['mtu' => '1280', 'persistentKeepalive' => '25'], false);
		self::assertSame([], $result['errors']);
		self::assertSame(1280, $result['fields']['mtu']);
		self::assertSame(25, $result['fields']['persistentKeepalive']);
	}

	public function testKeepaliveRange(): void
	{
		self::assertSame([], $this->validator->validate(['persistentKeepalive' => 0], false)['errors']);
		self::assertArrayHasKey(
			'persistentKeepalive',
			$this->validator->validate(['persistentKeepalive' => 65536], false)['errors']
		);
		self::assertArrayHasKey(
			'persistentKeepalive',
			$this->validator->validate(['persistentKeepalive' => 12.5], false)['errors']
		);
	}

	public function testExpiresAtNormalizedToUtcIso(): void
	{
		$result = $this->validator->validate(['expiresAt' => '2027-01-01'], false);
		self::assertSame([], $result['errors']);
		self::assertSame('2027-01-01T00:00:00.000Z', $result['fields']['expiresAt']);
	}

	public function testExpiresAtEmptyClearsExpiry(): void
	{
		foreach ([null, ''] as $empty) {
			$result = $this->validator->validate(['expiresAt' => $empty], false);
			self::assertSame([], $result['errors']);
			self::assertNull($result['fields']['expiresAt']);
		}
	}

	public function testExpiresAtRejectsGarbage(): void
	{
		$result = $this->validator->validate(['expiresAt' => 'whenever'], false);
		self::assertArrayHasKey('expiresAt', $result['errors']);
	}

	public function testUnknownKeysAreDropped(): void
	{
		$result = $this->validator->validate([
			'name' => 'peer',
			'privateKey' => 'should-not-pass-through',
			'enabled' => false,
		], true);
		self::assertSame(['name' => 'peer'], $result['fields']);
	}
}
