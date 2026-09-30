<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Service;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCP\IAppConfig;

class SettingsService {
	public const DEFAULT_ARCHIVE_FOLDER = 'approval';
	public const SUBFOLDER_MODES = ['none', 'month', 'year'];

	private const SECRET_KEYS = ['corpsecret', 'token', 'encodingaeskey'];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getWeComEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'wecom_enabled', false);
	}

	public function getCorpId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'corpid');
	}

	public function getCorpSecret(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'corpsecret');
	}

	public function getAgentId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'agentid');
	}

	public function getToken(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'token');
	}

	public function getEncodingAesKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'encodingaeskey');
	}

	public function isWeComConfigured(): bool {
		return $this->getCorpId() !== ''
			&& $this->getCorpSecret() !== ''
			&& $this->getAgentId() !== ''
			&& $this->getToken() !== ''
			&& $this->getEncodingAesKey() !== '';
	}

	public function getArchiveEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'archive_enabled', false);
	}

	public function getArchiveFolder(): string {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, 'archive_folder', self::DEFAULT_ARCHIVE_FOLDER));
		$value = trim($value, '/');
		return $value === '' ? self::DEFAULT_ARCHIVE_FOLDER : $value;
	}

	/** @return 'none'|'month'|'year' */
	public function getArchiveSubfolder(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'archive_subfolder', 'month');
		return in_array($value, self::SUBFOLDER_MODES, true) ? $value : 'month';
	}

	/** @return int[] */
	public function getPendingTagIds(): array {
		return $this->getTagIdList('trigger_pending_tag_ids');
	}

	/** @return int[] */
	public function getApprovedTagIds(): array {
		return $this->getTagIdList('trigger_approved_tag_ids');
	}

	/** @return int[] */
	public function getRejectedTagIds(): array {
		return $this->getTagIdList('trigger_rejected_tag_ids');
	}

	/** @return int[] */
	private function getTagIdList(string $key): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $key, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$ids = array_map(static fn ($v): int => (int)$v, $decoded);
		return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
	}

	/**
	 * Save values coming from the admin settings UI. Unknown keys are ignored.
	 * An empty-string secret means "keep the stored value".
	 */
	public function saveAdminConfig(array $values): void {
		if (array_key_exists('wecom_enabled', $values)) {
			$this->appConfig->setValueBool(Application::APP_ID, 'wecom_enabled', (bool)$values['wecom_enabled']);
		}
		foreach (['corpid', 'agentid'] as $key) {
			if (isset($values[$key]) && is_string($values[$key])) {
				$this->appConfig->setValueString(Application::APP_ID, $key, trim($values[$key]));
			}
		}
		foreach (self::SECRET_KEYS as $key) {
			if (isset($values[$key]) && is_string($values[$key]) && $values[$key] !== '') {
				$this->appConfig->setValueString(Application::APP_ID, $key, trim($values[$key]), false, true);
			}
		}
		if (array_key_exists('archive_enabled', $values)) {
			$this->appConfig->setValueBool(Application::APP_ID, 'archive_enabled', (bool)$values['archive_enabled']);
		}
		if (isset($values['archive_folder']) && is_string($values['archive_folder'])) {
			$folder = trim(trim($values['archive_folder']), '/');
			if ($folder !== '') {
				$this->appConfig->setValueString(Application::APP_ID, 'archive_folder', $folder);
			}
		}
		if (isset($values['archive_subfolder']) && in_array($values['archive_subfolder'], self::SUBFOLDER_MODES, true)) {
			$this->appConfig->setValueString(Application::APP_ID, 'archive_subfolder', $values['archive_subfolder']);
		}
		foreach (['trigger_pending_tag_ids', 'trigger_approved_tag_ids', 'trigger_rejected_tag_ids'] as $key) {
			if (isset($values[$key]) && is_array($values[$key])) {
				$ids = array_values(array_filter(
					array_map(static fn ($v): int => (int)$v, $values[$key]),
					static fn (int $id): bool => $id > 0
				));
				$this->appConfig->setValueString(Application::APP_ID, $key, json_encode($ids));
			}
		}
	}

	/** Config as exposed to the admin UI: secrets are reduced to booleans. */
	public function getAdminConfig(): array {
		return [
			'wecom_enabled' => $this->getWeComEnabled(),
			'corpid' => $this->getCorpId(),
			'agentid' => $this->getAgentId(),
			'has_corpsecret' => $this->getCorpSecret() !== '',
			'has_token' => $this->getToken() !== '',
			'has_encodingaeskey' => $this->getEncodingAesKey() !== '',
			'archive_enabled' => $this->getArchiveEnabled(),
			'archive_folder' => $this->getArchiveFolder(),
			'archive_subfolder' => $this->getArchiveSubfolder(),
			'trigger_pending_tag_ids' => $this->getPendingTagIds(),
			'trigger_approved_tag_ids' => $this->getApprovedTagIds(),
			'trigger_rejected_tag_ids' => $this->getRejectedTagIds(),
		];
	}
}
