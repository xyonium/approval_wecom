<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Crypto;

/**
 * WeCom (企业微信) callback message crypto, per the WXBizMsgCrypt spec:
 *  - AES-256-CBC, key = base64_decode(EncodingAESKey . '='), IV = first 16 bytes of key
 *  - PKCS7 padding with a block size of 32
 *  - plaintext = random(16) | msg_len(4, network byte order) | msg | receiveid
 *  - signature = sha1(concat(sort(token, timestamp, nonce, encrypt_msg)))
 */
class WXBizMsgCrypt {
	public function verifySignature(string $token, string $timestamp, string $nonce, string $encryptMsg, string $signature): bool {
		$arr = [$token, $timestamp, $nonce, $encryptMsg];
		sort($arr, SORT_STRING);
		return hash_equals(sha1(implode('', $arr)), $signature);
	}

	/**
	 * Decrypt an encrypted message and verify it was addressed to our corp.
	 *
	 * @throws CryptoException when decryption fails or the receiveid does not match the corp ID
	 */
	public function decrypt(string $encrypted, string $encodingAesKey, string $corpId): string {
		$key = base64_decode($encodingAesKey . '=');
		if ($key === false || strlen($key) !== 32) {
			throw new CryptoException('Invalid EncodingAESKey');
		}
		$iv = substr($key, 0, 16);

		// $encrypted is base64 (as transported in the callback XML); without
		// OPENSSL_RAW_DATA openssl base64-decodes the input itself.
		$plain = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $iv);
		if ($plain === false || strlen($plain) < 21) {
			throw new CryptoException('Decryption failed');
		}

		// strip PKCS7 padding (block size 32, pad byte value = pad length)
		$pad = ord($plain[strlen($plain) - 1]);
		if ($pad < 1 || $pad > 32) {
			throw new CryptoException('Invalid padding');
		}
		$plain = substr($plain, 0, strlen($plain) - $pad);

		if (strlen($plain) < 20) {
			throw new CryptoException('Truncated plaintext');
		}
		$msgLen = unpack('N', substr($plain, 16, 4))[1];
		$msg = substr($plain, 20, $msgLen);
		$receiveId = substr($plain, 20 + $msgLen);

		if ($receiveId !== $corpId) {
			throw new CryptoException('receiveid mismatch');
		}
		return $msg;
	}
}
