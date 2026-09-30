<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\Crypto\CryptoException;
use OCA\ApprovalWeCom\Crypto\WXBizMsgCrypt;

final class WXBizMsgCryptTest extends TestCase {
	use WeComCryptoTestTrait;

	private WXBizMsgCrypt $crypt;

	protected function setUp(): void {
		parent::setUp();
		$this->crypt = new WXBizMsgCrypt();
	}

	public function testDecryptRoundtrip(): void {
		$msg = '<xml><Event>template_card_event</Event></xml>';
		$encrypted = $this->wecomEncrypt($msg);
		$this->assertSame($msg, $this->crypt->decrypt($encrypted, self::TEST_AES_KEY, self::TEST_CORP_ID));
	}

	public function testDecryptRejectsCorpIdMismatch(): void {
		$encrypted = $this->wecomEncrypt('<xml/>', null, 'other-corp');
		$this->expectException(CryptoException::class);
		$this->expectExceptionMessageMatches('/receiveid/i');
		$this->crypt->decrypt($encrypted, self::TEST_AES_KEY, self::TEST_CORP_ID);
	}

	public function testDecryptRejectsWrongKey(): void {
		$encrypted = $this->wecomEncrypt('<xml>hello</xml>');
		$wrongKey = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefg'; // 43 chars, different key
		$this->expectException(CryptoException::class);
		$this->crypt->decrypt($encrypted, $wrongKey, self::TEST_CORP_ID);
	}

	public function testDecryptRejectsTamperedCiphertext(): void {
		$encrypted = $this->wecomEncrypt('<xml><EventKey>approve_12_3</EventKey></xml>');
		$raw = base64_decode($encrypted);
		$raw[20] = $raw[20] === "\x00" ? "\x01" : "\x00"; // flip one byte in the middle
		$tampered = base64_encode($raw);
		$this->expectException(CryptoException::class);
		$this->crypt->decrypt($tampered, self::TEST_AES_KEY, self::TEST_CORP_ID);
	}

	public function testVerifySignature(): void {
		$encrypted = $this->wecomEncrypt('<xml/>');
		$signature = $this->wecomSign(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypted);
		$this->assertTrue($this->crypt->verifySignature(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypted, $signature));
		$this->assertFalse($this->crypt->verifySignature(self::TEST_TOKEN, '1700000000', 'nonce1', $encrypted, sha1('wrong')));
		$this->assertFalse($this->crypt->verifySignature('other-token', '1700000000', 'nonce1', $encrypted, $signature));
	}
}
