<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Service;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * WeCom (企业微信) API client for a self-built application.
 *
 * Every public method degrades to null/false instead of throwing, so callers
 * in event listeners and controllers never have to handle exceptions.
 */
class WeComService {
	private const API_BASE = 'https://qyapi.weixin.qq.com';
	private const TOKEN_CACHE_KEY = 'access_token';
	// errcodes meaning "access token invalid/expired"
	private const TOKEN_ERRCODES = [40014, 42001, 41001];

	public function __construct(
		private IClientService $clientService,
		private ICacheFactory $cacheFactory,
		private SettingsService $settings,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	private function getCache(): ICache {
		return $this->cacheFactory->createDistributed(Application::APP_ID);
	}

	public function getAccessToken(): ?string {
		$cache = $this->getCache();
		$cached = $cache->get(self::TOKEN_CACHE_KEY);
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		$corpId = $this->settings->getCorpId();
		$corpSecret = $this->settings->getCorpSecret();
		if ($corpId === '' || $corpSecret === '') {
			return null;
		}

		try {
			$response = $this->clientService->newClient()->get(self::API_BASE . '/cgi-bin/gettoken', [
				'timeout' => 5,
				'connect_timeout' => 3,
				'query' => ['corpid' => $corpId, 'corpsecret' => $corpSecret],
			]);
			$data = json_decode($response->getBody(), true);
			if (!is_array($data) || ($data['errcode'] ?? -1) !== 0 || empty($data['access_token'])) {
				$this->logger->warning('WeCom gettoken failed, errcode ' . ($data['errcode'] ?? 'n/a'), ['app' => Application::APP_ID]);
				return null;
			}
			// refresh 5 minutes early to stay clear of the expiry edge
			$ttl = max(60, (int)($data['expires_in'] ?? 7200) - 300);
			$cache->set(self::TOKEN_CACHE_KEY, (string)$data['access_token'], $ttl);
			return (string)$data['access_token'];
		} catch (\Throwable $e) {
			$this->logger->warning('WeCom gettoken exception: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return null;
		}
	}

	/**
	 * Call a WeCom API endpoint with the access token attached.
	 * On an expired/invalid token the cache is cleared and the call retried once.
	 *
	 * @return ?array<string, mixed> decoded JSON response, or null on transport failure
	 */
	private function apiCall(string $method, string $path, ?array $jsonBody = null, array $query = [], bool $retryOnTokenExpiry = true): ?array {
		$token = $this->getAccessToken();
		if ($token === null) {
			return null;
		}
		$query['access_token'] = $token;

		try {
			$options = [
				'timeout' => 5,
				'connect_timeout' => 3,
				'query' => $query,
			];
			if ($jsonBody !== null) {
				$options['json'] = $jsonBody;
			}
			$client = $this->clientService->newClient();
			$url = self::API_BASE . $path;
			$response = strtoupper($method) === 'GET'
				? $client->get($url, $options)
				: $client->post($url, $options);
			$data = json_decode($response->getBody(), true);
			if (!is_array($data)) {
				return null;
			}
			$errcode = (int)($data['errcode'] ?? -1);
			if (in_array($errcode, self::TOKEN_ERRCODES, true) && $retryOnTokenExpiry) {
				$this->getCache()->remove(self::TOKEN_CACHE_KEY);
				return $this->apiCall($method, $path, $jsonBody, $query, false);
			}
			return $data;
		} catch (\Throwable $e) {
			$this->logger->warning('WeCom API call ' . $path . ' failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return null;
		}
	}

	/** Map a Nextcloud user email to a WeCom userid (tries enterprise mailbox first, then personal). */
	public function getWeComUserIdByEmail(string $email): ?string {
		// email_type: 1 = enterprise mailbox (biz_mail), 2 = personal email
		foreach ([1, 2] as $type) {
			$data = $this->apiCall('POST', '/cgi-bin/user/get_userid_by_email', ['email' => $email, 'email_type' => $type]);
			if ($data !== null && ($data['errcode'] ?? -1) === 0 && !empty($data['userid'])) {
				return (string)$data['userid'];
			}
		}
		return null;
	}

	/** Map a WeCom userid back to an email (email field, falling back to biz_mail). */
	public function getEmailByWeComUserId(string $weComUserId): ?string {
		$data = $this->apiCall('GET', '/cgi-bin/user/get', null, ['userid' => $weComUserId]);
		if ($data === null || ($data['errcode'] ?? -1) !== 0) {
			return null;
		}
		$email = $data['email'] ?? $data['biz_mail'] ?? null;
		return is_string($email) && $email !== '' ? $email : null;
	}

	/**
	 * Send an interactive approval card (button_interaction template card) to
	 * the given WeCom users as one multi-recipient message.
	 *
	 * @param string[] $weComUserIds
	 * @return ?array{taskId: string, responseCode: ?string} null when the send failed
	 */
	public function sendApprovalCards(array $weComUserIds, string $requesterName, string $fileName, int $fileId, int $ruleId, string $fileUrl): ?array {
		if ($weComUserIds === []) {
			return null;
		}
		$taskId = sprintf('approval_%d_%d_%d', $fileId, $ruleId, time());
		$body = [
			'touser' => implode('|', $weComUserIds),
			'msgtype' => 'template_card',
			'agentid' => (int)$this->settings->getAgentId(),
			'template_card' => [
				'card_type' => 'button_interaction',
				'main_title' => ['title' => '审批请求：' . $fileName, 'desc' => '申请人：' . $requesterName],
				'sub_title_text' => $requesterName . ' 请求审批文件「' . $fileName . '」',
				'task_id' => $taskId,
				// button styles are cosmetic (WeCom renders them); keys carry the action
				'button_list' => [
					['text' => '批准', 'style' => 1, 'key' => 'approve_' . $fileId . '_' . $ruleId],
					['text' => '拒绝', 'style' => 3, 'key' => 'reject_' . $fileId . '_' . $ruleId],
				],
				'card_action' => ['type' => 1, 'url' => $fileUrl],
			],
		];
		$data = $this->apiCall('POST', '/cgi-bin/message/send', $body);
		if ($data === null || ($data['errcode'] ?? -1) !== 0) {
			$this->logger->warning('WeCom sendApprovalCards failed, errcode ' . ($data['errcode'] ?? 'n/a'), ['app' => Application::APP_ID]);
			return null;
		}
		return [
			'taskId' => $taskId,
			'responseCode' => isset($data['response_code']) ? (string)$data['response_code'] : null,
		];
	}

	/**
	 * Replace the buttons of the clicked card (per-user ResponseCode from the
	 * callback event, valid once within 72h).
	 */
	public function updateCardButtons(string $responseCode, string $replaceName): bool {
		$data = $this->apiCall('POST', '/cgi-bin/message/update_template_card', [
			'response_code' => $responseCode,
			'button' => ['replace_name' => $replaceName],
		]);
		return $data !== null && ($data['errcode'] ?? -1) === 0;
	}

	/**
	 * Replace the whole card for all recipients (response_code captured when
	 * the card message was sent).
	 */
	public function replaceCardWithNotice(string $responseCode, string $title, string $text): bool {
		$data = $this->apiCall('POST', '/cgi-bin/message/update_template_card', [
			'response_code' => $responseCode,
			'template_card' => [
				'card_type' => 'text_notice',
				'main_title' => ['title' => $title],
				'sub_title_text' => $text,
			],
		]);
		return $data !== null && ($data['errcode'] ?? -1) === 0;
	}

	/** Send a plain text notice to a Nextcloud user via their WeCom mapping. No-ops when unmapped. */
	public function sendNoticeToNcUser(string $ncUserId, string $text): void {
		$email = $this->userManager->get($ncUserId)?->getEMailAddress();
		if (!$email) {
			return;
		}
		$weComUserId = $this->getWeComUserIdByEmail($email);
		if ($weComUserId === null) {
			return;
		}
		$this->sendTextMessage([$weComUserId], $text);
	}

	/** @return array{success: bool, error?: string} */
	public function sendTestMessage(string $ncUserId): array {
		$email = $this->userManager->get($ncUserId)?->getEMailAddress();
		if (!$email) {
			return ['success' => false, 'error' => 'no_email'];
		}
		$weComUserId = $this->getWeComUserIdByEmail($email);
		if ($weComUserId === null) {
			return ['success' => false, 'error' => 'no_wecom_user'];
		}
		return $this->sendTextMessage([$weComUserId], 'Nextcloud approval_wecom 测试消息')
			? ['success' => true]
			: ['success' => false, 'error' => 'send_failed'];
	}

	/** @param string[] $weComUserIds */
	private function sendTextMessage(array $weComUserIds, string $text): bool {
		if ($weComUserIds === []) {
			return false;
		}
		$data = $this->apiCall('POST', '/cgi-bin/message/send', [
			'touser' => implode('|', $weComUserIds),
			'msgtype' => 'text',
			'agentid' => (int)$this->settings->getAgentId(),
			'text' => ['content' => $text],
		]);
		return $data !== null && ($data['errcode'] ?? -1) === 0;
	}
}
