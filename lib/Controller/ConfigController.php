<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Controller;

use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCA\ApprovalWeCom\Service\WeComService;
use OCA\ApprovalWeCom\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\SystemTag\ISystemTagManager;

/**
 * Admin settings API. Every method is guarded by AuthorizedAdminSetting so
 * only admins (or users with delegated access to this app's settings
 * section) can read or change the configuration.
 */
class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settings,
		private ApprovalInfoProvider $infoProvider,
		private WeComService $weComService,
		private ISystemTagManager $tagManager,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function getConfig(): JSONResponse {
		return new JSONResponse($this->settings->getAdminConfig());
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function setConfig(): JSONResponse {
		$this->settings->saveAdminConfig($this->request->getParams());
		return new JSONResponse($this->settings->getAdminConfig());
	}

	/**
	 * Tags used by approval rules plus the rules themselves — the admin UI
	 * builds its trigger-tag selectors from this.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function getApprovalRuleTags(): JSONResponse {
		$rules = $this->infoProvider->getAllRuleTags();

		$tagIds = [];
		foreach ($rules as $rule) {
			foreach (['tagPending', 'tagApproved', 'tagRejected'] as $key) {
				if (!in_array($rule[$key], $tagIds, true)) {
					$tagIds[] = $rule[$key];
				}
			}
		}

		$tags = [];
		foreach ($this->tagManager->getTagsByIds($tagIds) as $tag) {
			$tags[] = ['id' => (int)$tag->getId(), 'name' => $tag->getName()];
		}

		return new JSONResponse([
			'enabled' => $this->infoProvider->isApprovalAppEnabled(),
			'tags' => $tags,
			'rules' => $rules,
		]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function sendTestMessage(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['success' => false, 'error' => 'no_user'], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->weComService->sendTestMessage($this->userId));
	}
}
