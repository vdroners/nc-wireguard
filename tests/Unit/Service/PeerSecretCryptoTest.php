<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use Exception;
use OCA\NcWireguard\Service\PeerSecretCrypto;
use OCA\NcWireguard\Service\PeerSecretCryptoException;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of this class is that it never degrades to plaintext: a
 * ciphertext handed to a `.conf` builder would produce a config that looks fine
 * and never handshakes.
 */
final class PeerSecretCryptoTest extends TestCase
{
	public function testRoundTripsThroughThePeerEnvelope(): void
	{
		$crypto = $this->reversibleCrypto();
		$subject = new PeerSecretCrypto($crypto);

		$sealed = $subject->encrypt('cPrivateKeyBase64=');

		self::assertStringStartsWith(PeerSecretCrypto::PREFIX, $sealed);
		self::assertStringNotContainsString('cPrivateKeyBase64=', $sealed);
		self::assertSame('cPrivateKeyBase64=', $subject->decrypt($sealed));
		self::assertTrue($subject->isSealed($sealed));
	}

	public function testDecryptThrowsWhenTheBackendFails(): void
	{
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new Exception('bad HMAC'));
		$subject = new PeerSecretCrypto($crypto);

		$this->expectException(PeerSecretCryptoException::class);
		$subject->decrypt(PeerSecretCrypto::PREFIX . 'garbage');
	}

	public function testDecryptRefusesAnUnwrappedValueInsteadOfReturningIt(): void
	{
		// SecretCrypto returns the raw string here; for key material that would
		// silently ship a broken peer config.
		$subject = new PeerSecretCrypto($this->reversibleCrypto());

		$this->expectException(PeerSecretCryptoException::class);
		$subject->decrypt('cPlaintextLookingKey=');
	}

	public function testDecryptRefusesAnEmptyEnvelope(): void
	{
		$subject = new PeerSecretCrypto($this->reversibleCrypto());

		$this->expectException(PeerSecretCryptoException::class);
		$subject->decrypt(PeerSecretCrypto::PREFIX);
	}

	public function testDecryptRefusesAnEmptyPlaintext(): void
	{
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('');
		$subject = new PeerSecretCrypto($crypto);

		$this->expectException(PeerSecretCryptoException::class);
		$subject->decrypt(PeerSecretCrypto::PREFIX . 'whatever');
	}

	public function testEncryptRefusesEmptyKeyMaterial(): void
	{
		$subject = new PeerSecretCrypto($this->reversibleCrypto());

		$this->expectException(PeerSecretCryptoException::class);
		$subject->encrypt('   ');
	}

	public function testLegacyAppconfigEnvelopeStillReads(): void
	{
		$subject = new PeerSecretCrypto($this->reversibleCrypto());

		self::assertSame('key', $subject->decrypt('enc:v1:' . base64_encode('key')));
	}

	public function testOptionalDecryptPassesNullThroughButNotGarbage(): void
	{
		$subject = new PeerSecretCrypto($this->reversibleCrypto());

		self::assertNull($subject->decryptOptional(null));
		self::assertNull($subject->decryptOptional(''));

		$this->expectException(PeerSecretCryptoException::class);
		$subject->decryptOptional('not-sealed');
	}

	private function reversibleCrypto(): ICrypto
	{
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(
			static fn (string $data): string => base64_encode($data)
		);
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $data): string {
				$plain = base64_decode($data, true);
				if ($plain === false) {
					throw new Exception('not base64');
				}
				return $plain;
			}
		);
		return $crypto;
	}
}
