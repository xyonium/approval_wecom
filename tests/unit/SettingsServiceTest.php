<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

final class SettingsServiceTest extends TestCase {
	/**
	 * @param array<string, string> $strings
	 * @param array<string, bool> $bools
	 * @return IAppConfig&MockObject
	 */
	private function makeAppConfig(array $strings = [], array $bools = []): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $strings[$key] ?? $default
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $bools[$key] ?? $default
		);
		return $appConfig;
	}

	public function testDefaults(): void {
		$service = new SettingsService($this->makeAppConfig());
		$this->assertFalse($service->getWeComEnabled());
		$this->assertFalse($service->getArchiveEnabled());
		$this->assertSame('approval', $service->getArchiveFolder());
		$this->assertSame('month', $service->getArchiveSubfolder());
		$this->assertSame([], $service->getPendingTagIds());
		$this->assertFalse($service->isWeComConfigured());
	}

	public function testTagIdListsParseJsonAndFilter(): void {
		$service = new SettingsService($this->makeAppConfig([
			'trigger_pending_tag_ids' => '[1, 5, "9", 0, -2]',
			'trigger_approved_tag_ids' => 'not json',
		]));
		$this->assertSame([1, 5, 9], $service->getPendingTagIds());
		$this->assertSame([], $service->getApprovedTagIds());
	}

	public function testArchiveFolderSanitized(): void {
		$service = new SettingsService($this->makeAppConfig(['archive_folder' => ' /归档/ ']));
		$this->assertSame('归档', $service->getArchiveFolder());
	}

	public function testInvalidSubfolderFallsBackToMonth(): void {
		$service = new SettingsService($this->makeAppConfig(['archive_subfolder' => 'weekly']));
		$this->assertSame('month', $service->getArchiveSubfolder());
	}

	public function testGetAdminConfigMasksSecrets(): void {
		$service = new SettingsService($this->makeAppConfig(
			['corpsecret' => 's3cret', 'token' => '', 'encodingaeskey' => 'abc', 'corpid' => 'corp1'],
			['wecom_enabled' => true],
		));
		$config = $service->getAdminConfig();
		$this->assertTrue($config['wecom_enabled']);
		$this->assertSame('corp1', $config['corpid']);
		$this->assertTrue($config['has_corpsecret']);
		$this->assertFalse($config['has_token']);
		$this->assertTrue($config['has_encodingaeskey']);
		$this->assertArrayNotHasKey('corpsecret', $config);
	}

	public function testSaveKeepsEmptySecretsAndStoresSensitive(): void {
		$appConfig = $this->makeAppConfig();
		$saved = [];
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$saved): bool {
				$saved[$key] = ['value' => $value, 'sensitive' => $sensitive];
				return true;
			}
		);

		$service = new SettingsService($appConfig);
		$service->saveAdminConfig([
			'corpid' => 'corp1',
			'corpsecret' => '',            // empty => keep, must NOT be written
			'token' => 'tok-1',
			'trigger_pending_tag_ids' => [1, '2'],
			'archive_subfolder' => 'year',
		]);

		$this->assertSame(['value' => 'corp1', 'sensitive' => false], $saved['corpid']);
		$this->assertArrayNotHasKey('corpsecret', $saved);
		$this->assertSame(['value' => 'tok-1', 'sensitive' => true], $saved['token']);
		$this->assertSame('[1,2]', $saved['trigger_pending_tag_ids']['value']);
		$this->assertSame('year', $saved['archive_subfolder']['value']);
	}
}
