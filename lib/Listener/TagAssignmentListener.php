<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Listener;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Service\ApprovalInfoProvider;
use OCA\ApprovalWeCom\Service\ArchiveService;
use OCA\ApprovalWeCom\Service\RequestRegistry;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCA\ApprovalWeCom\Service\WeComService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\SystemTag\TagAssignedEvent;
use Psr\Log\LoggerInterface;

/**
 * Drives all three features off approval's tag assignments:
 *
 * - pending tag (admin-selected): register the request in
 *   approval_wecom_requests (so the requester survives approval's
 *   delete-then-insert activity rows) and push WeCom approval cards;
 * - approved tag: archive the approver-side share mount points, replace the
 *   WeCom cards with a result notice, notify the requester, drop the row;
 * - rejected tag: same as approved, minus the archiving.
 *
 * The listener never throws: approval's own flow must not be affected.
 *
 * @template-implements IEventListener<TagAssignedEvent>
 */
class TagAssignmentListener implements IEventListener {
	public function __construct(
		private ApprovalInfoProvider $infoProvider,
		private RequestRegistry $registry,
		private SettingsService $settings,
		private WeComService $weComService,
		private ArchiveService $archiveService,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof TagAssignedEvent || $event->getObjectType() !== 'files') {
			return;
		}
		if (!$this->infoProvider->isApprovalAppEnabled()) {
			return;
		}

		$pendingTags = $this->settings->getPendingTagIds();
		$approvedTags = $this->settings->getApprovedTagIds();
		$rejectedTags = $this->settings->getRejectedTagIds();

		foreach (array_unique($event->getObjectIds()) as $objectId) {
			foreach (array_unique($event->getTags()) as $tagId) {
				try {
					$this->handleTag((int)$objectId, (int)$tagId, $pendingTags, $approvedTags, $rejectedTags);
				} catch (\Throwable $e) {
					$this->logger->error('TagAssignmentListener failed for file ' . $objectId . ' tag ' . $tagId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
				}
			}
		}
	}

	/**
	 * @param list<int> $pendingTags
	 * @param list<int> $approvedTags
	 * @param list<int> $rejectedTags
	 */
	private function handleTag(int $fileId, int $tagId, array $pendingTags, array $approvedTags, array $rejectedTags): void {
		if (in_array($tagId, $pendingTags, true)) {
			$rule = $this->infoProvider->findRuleByTagId($tagId);
			// only a genuine pending tag of a rule marks a new request
			if ($rule !== null && $rule['matched'] === 'pending') {
				$this->handlePending($fileId, $rule);
			}
		} elseif (in_array($tagId, $approvedTags, true)) {
			$this->handleResolved($fileId, $this->infoProvider->findRuleByTagId($tagId), true);
		} elseif (in_array($tagId, $rejectedTags, true)) {
			$this->handleResolved($fileId, $this->infoProvider->findRuleByTagId($tagId), false);
		}
	}

	/** @param array{id: int, tagPending: int, tagApproved: int, tagRejected: int, matched: string} $rule */
	private function handlePending(int $fileId, array $rule): void {
		$ruleId = $rule['id'];
		$requester = $this->infoProvider->findPendingRequesterUserId($fileId, $ruleId)
			?? $this->findNodeOwnerId($fileId);
		if ($requester === null) {
			$this->logger->warning('Pending approval for file ' . $fileId . ': requester unknown, not registering', ['app' => Application::APP_ID]);
			return;
		}

		// always register — archiving needs the requester at resolution time,
		// independent of the WeCom switch
		$this->registry->register($fileId, $ruleId, $requester);

		if (!$this->settings->getWeComEnabled() || !$this->settings->isWeComConfigured()) {
			return;
		}

		$weComUserIds = [];
		foreach ($this->infoProvider->resolveApproverUserIds($ruleId) as $uid) {
			$email = $this->userManager->get($uid)?->getEMailAddress();
			if ($email === null || $email === '') {
				$this->logger->info('Approver ' . $uid . ' has no email, skipping WeCom mapping', ['app' => Application::APP_ID]);
				continue;
			}
			$weComUserId = $this->weComService->getWeComUserIdByEmail($email);
			if ($weComUserId === null) {
				$this->logger->warning('No WeCom user for email of approver ' . $uid, ['app' => Application::APP_ID]);
				continue;
			}
			$weComUserIds[] = $weComUserId;
		}
		if ($weComUserIds === []) {
			$this->logger->warning('No approver of rule ' . $ruleId . ' has a WeCom mapping, no card sent', ['app' => Application::APP_ID]);
			return;
		}

		$requesterName = $this->userManager->get($requester)?->getDisplayName() ?? $requester;
		$sent = $this->weComService->sendApprovalCards(
			array_values(array_unique($weComUserIds)),
			$requesterName,
			$this->findNodeName($fileId) ?? (string)$fileId,
			$fileId,
			$ruleId,
			$this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]),
		);
		if ($sent !== null) {
			$this->registry->attachCardInfo($fileId, $ruleId, $sent['taskId'], $sent['responseCode']);
		}
	}

	/** @param ?array{id: int, tagPending: int, tagApproved: int, tagRejected: int, matched: string} $rule */
	private function handleResolved(int $fileId, ?array $rule, bool $approved): void {
		$ruleId = $rule['id'] ?? null;
		$request = $ruleId === null ? null : $this->registry->find($fileId, $ruleId);
		$requester = $request['requester_user_id'] ?? $this->findNodeOwnerId($fileId);

		if ($approved && $this->settings->getArchiveEnabled()) {
			if ($requester === null) {
				$this->logger->warning('Archive: requester unknown for file ' . $fileId . ', skipping', ['app' => Application::APP_ID]);
			} else {
				$this->archiveService->archiveShares(
					$fileId,
					$requester,
					$ruleId === null ? null : $this->infoProvider->getApproverEntities($ruleId),
				);
			}
		}

		if ($this->settings->getWeComEnabled() && $this->settings->isWeComConfigured()) {
			$resultText = $this->buildResultText($fileId, $ruleId, $approved);
			$responseCode = $request['response_code'] ?? null;
			if (is_string($responseCode) && $responseCode !== '') {
				$this->weComService->replaceCardWithNotice($responseCode, $approved ? '审批已通过' : '审批已拒绝', $resultText);
			}
			if ($requester !== null) {
				$this->weComService->sendNoticeToNcUser($requester, $resultText);
			}
		}

		if ($ruleId !== null) {
			$this->registry->delete($fileId, $ruleId);
		}
	}

	/** 「{文件名} 已批准/已拒绝，操作人：XXX」(operator part omitted when unknown) */
	private function buildResultText(int $fileId, ?int $ruleId, bool $approved): string {
		$fileName = $this->findNodeName($fileId) ?? (string)$fileId;
		$text = $fileName . ' ' . ($approved ? '已批准' : '已拒绝');
		$operator = $ruleId === null ? null : $this->infoProvider->findResolverUserId($fileId, $ruleId);
		if ($operator !== null) {
			$operatorName = $this->userManager->get($operator)?->getDisplayName() ?? $operator;
			$text .= '，操作人：' . $operatorName;
		}
		return $text;
	}

	private function findNode(int $fileId): ?Node {
		$nodes = $this->rootFolder->getById($fileId);
		return $nodes[0] ?? null;
	}

	private function findNodeName(int $fileId): ?string {
		try {
			return $this->findNode($fileId)?->getName();
		} catch (\Throwable) {
			return null;
		}
	}

	private function findNodeOwnerId(int $fileId): ?string {
		try {
			return $this->findNode($fileId)?->getOwner()?->getUID();
		} catch (\Throwable) {
			return null;
		}
	}
}
