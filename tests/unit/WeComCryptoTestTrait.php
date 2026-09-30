<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

/**
 * Test-side implementation of the WeCom (WXBizMsgCrypt) message encryption,
 * used to generate fixtures for roundtrip and tamper tests.
 */
trait WeComCryptoTestTrait {
	private const TEST_AES_KEY = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG'; // 43 chars
	private const TEST_CORP_ID = 'corp123';
	private const TEST_TOKEN = 'tok-xyz';

	private function wecomEncrypt(string $msg, ?string $aesKey = null, ?string $corpId = null): string {
		$aesKey = $aesKey ?? self::TEST_AES_KEY;
		$corpId = $corpId ?? self::TEST_CORP_ID;
		$key = base64_decode($aesKey . '=');
		$text = random_bytes(16) . pack('N', strlen($msg)) . $msg . $corpId;
		$pad = 32 - (strlen($text) % 32);
		$text .= str_repeat(chr($pad), $pad);
		$iv = substr($key, 0, 16);
		$raw = openssl_encrypt($text, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
		return base64_encode($raw);
	}

	private function wecomSign(string $token, string $timestamp, string $nonce, string $encryptMsg): string {
		$arr = [$token, $timestamp, $nonce, $encryptMsg];
		sort($arr, SORT_STRING);
		return sha1(implode('', $arr));
	}
}
