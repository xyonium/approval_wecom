<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Controller;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Crypto\CryptoException;
use OCA\ApprovalWeCom\Crypto\WXBizMsgCrypt;
use OCA\ApprovalWeCom\Service\ApprovalExecutor;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCA\ApprovalWeCom\Service\WeComService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Receives WeCom (企业微信) callbacks for the self-built application.
 *
 * GET  — URL verification performed by WeCom when the admin saves the
 *        callback URL: decrypt the echostr parameter and echo it back.
 * POST — encrypted event messages. We only act on template_card_event
 *        events whose EventKey matches our approve_/reject_ button keys.
 *
 * Security notes:
 * - every request is signature-verified (sha1 over token/timestamp/nonce/encrypt)
 *   and the decrypted message's receiveid must equal the configured CorpID;
 * - the clicking WeCom user is resolved to a Nextcloud user via their email
 *   address and must be an approver of the rule that the card belongs to.
 */
class WeComCallbackController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settings,
		private WXBizMsgCrypt $crypto,
		private WeComService $weComService,
		private ApprovalInfoProvider $infoProvider,
		private ApprovalExecutor $executor,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * WeCom URL verification: GET ?msg_signature=&timestamp=&nonce=&echostr=
	 * Must respond with the decrypted echostr in plain text.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function verify(string $msg_signature = '', string $timestamp = '', string $nonce = '', string $echostr = ''): TextPlainResponse {
		if (!$this->settings->getWeComEnabled()) {
			return new TextPlainResponse('disabled', Http::STATUS_NOT_FOUND);
		}
		if (!$this->crypto->verifySignature($this->settings->getToken(), $timestamp, $nonce, $echostr, $msg_signature)) {
			return new TextPlainResponse('invalid signature', Http::STATUS_FORBIDDEN);
		}
		try {
			$plain = $this->crypto->decrypt($echostr, $this->settings->getEncodingAesKey(), $this->settings->getCorpId());
		} catch (CryptoException $e) {
			$this->logger->warning('WeCom URL verification decrypt failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new TextPlainResponse('decrypt failed', Http::STATUS_BAD_REQUEST);
		}
		return new TextPlainResponse($plain);
	}

	/**
	 * WeCom event callback: POST encrypted XML body, signature in query params.
	 * Always answers 'success' after a valid decryption so WeCom does not retry.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function callback(string $msg_signature = '', string $timestamp = '', string $nonce = ''): TextPlainResponse {
		if (!$this->settings->getWeComEnabled()) {
			return new TextPlainResponse('disabled', Http::STATUS_NOT_FOUND);
		}

		try {
			$body = $this->getRequestBody();
			$encryptedMsg = $this->extractEncryptElement($body);
			if ($encryptedMsg === null) {
				return new TextPlainResponse('bad request', Http::STATUS_BAD_REQUEST);
			}
			if (!$this->crypto->verifySignature($this->settings->getToken(), $timestamp, $nonce, $encryptedMsg, $msg_signature)) {
				return new TextPlainResponse('invalid signature', Http::STATUS_FORBIDDEN);
			}
			$plain = $this->crypto->decrypt($encryptedMsg, $this->settings->getEncodingAesKey(), $this->settings->getCorpId());
		} catch (CryptoException $e) {
			$this->logger->warning('WeCom callback decrypt failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new TextPlainResponse('decrypt failed', Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->handlePlainMessage($plain);
		} catch (\Throwable $e) {
			$this->logger->error('WeCom callback handling failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		return new TextPlainResponse('success');
	}

	/**
	 * Raw POST body. OCP\IRequest has no getContent(), so read php://input.
	 * Kept in a protected method so tests can override it.
	 */
	protected function getRequestBody(): string {
		$content = file_get_contents('php://input');
		return $content === false ? '' : $content;
	}

	/** Extract the base64 <Encrypt> element from the outer callback XML. */
	private function extractEncryptElement(string $body): ?string {
		if ($body === '' || !preg_match('/<Encrypt>(?:<!\[CDATA\[)?([A-Za-z0-9+\/=]+)(?:\]\]>)?<\/Encrypt>/', $body, $m)) {
			return null;
		}
		return $m[1];
	}

	private function handlePlainMessage(string $plain): void {
		$xml = simplexml_load_string($plain);
		if ($xml === false) {
			return;
		}
		if ((string)$xml->MsgType !== 'event' || (string)$xml->Event !== 'template_card_event') {
			return;
		}
		$eventKey = (string)$xml->EventKey;
		if (!preg_match('/^(approve|reject)_(\d+)_(\d+)$/', $eventKey, $m)) {
			return;
		}
		[, $action, $fileId, $ruleId] = $m;
		$fileId = (int)$fileId;
		$ruleId = (int)$ruleId;
		$fromUser = (string)$xml->FromUserName;
		$responseCode = (string)$xml->ResponseCode;

		if ($fromUser === '') {
			return;
		}

		$ncUserId = $this->resolveNcUserId($fromUser);
		if ($ncUserId === null) {
			$this->updateButtons($responseCode, '无法识别用户');
			return;
		}
		if (!in_array($ncUserId, $this->infoProvider->resolveApproverUserIds($ruleId), true)) {
			$this->updateButtons($responseCode, '无权审批');
			return;
		}

		$result = $this->executor->execute($action, $fileId, $ruleId, $ncUserId);
		if ($result['success']) {
			$this->updateButtons($responseCode, $action === 'approve' ? '已批准' : '已拒绝');
		} else {
			$this->logger->info('WeCom card action failed: ' . ($result['error'] ?? '?'), ['app' => Application::APP_ID]);
			$this->updateButtons($responseCode, '已失效或已处理');
		}
	}

	private function resolveNcUserId(string $weComUserId): ?string {
		$email = $this->weComService->getEmailByWeComUserId($weComUserId);
		if ($email === null) {
			return null;
		}
		$users = $this->userManager->getByEmail($email);
		$user = $users[0] ?? null;
		return $user?->getUID();
	}

	private function updateButtons(string $responseCode, string $replaceName): void {
		if ($responseCode === '') {
			return;
		}
		$this->weComService->updateCardButtons($responseCode, $replaceName);
	}
}
