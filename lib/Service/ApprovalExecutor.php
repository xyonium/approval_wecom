<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Service;

use Closure;
use OCA\ApprovalWeCom\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

/**
 * Executes approve/reject on behalf of a WeCom callback click.
 *
 * Prefers approval's own ApprovalService (full notifications, activity,
 * permission checks) when the approval app is available. Falls back to a
 * minimal replication of the state change: RuleService::storeAction's
 * delete-then-insert into approval_activity plus the pending → final tag
 * swap. The resulting TagAssignedEvent then drives this app's own listeners
 * (archiving, card updates, requester notification) either way.
 */
class ApprovalExecutor {
	/** @var Closure(): ?object */
	private Closure $approvalServiceResolver;

	public function __construct(
		private ApprovalInfoProvider $infoProvider,
		private ISystemTagObjectMapper $tagObjectMapper,
		private IDBConnection $db,
		private LoggerInterface $logger,
		?Closure $approvalServiceResolver = null,
	) {
		$this->approvalServiceResolver = $approvalServiceResolver ?? static function (): ?object {
			if (!class_exists(\OCA\Approval\Service\ApprovalService::class)) {
				return null;
			}
			try {
				return \OC::$server->get(\OCA\Approval\Service\ApprovalService::class);
			} catch (\Throwable) {
				return null;
			}
		};
	}

	/**
	 * @param string $action 'approve' or 'reject'
	 * @return array{success: bool, error?: string}
	 */
	public function execute(string $action, int $fileId, int $ruleId, string $userId): array {
		if (!in_array($action, ['approve', 'reject'], true)) {
			return ['success' => false, 'error' => 'invalid action'];
		}

		$service = ($this->approvalServiceResolver)();
		if ($service !== null) {
			try {
				$etag = $service->getEtag($fileId);
				$ok = $action === 'approve'
					? $service->approve($fileId, $userId, $etag, 'via WeCom')
					: $service->reject($fileId, $userId, $etag, 'via WeCom');
				return $ok
					? ['success' => true]
					: ['success' => false, 'error' => 'approval app refused (not pending or not authorized)'];
			} catch (\Throwable $e) {
				$this->logger->warning('ApprovalService call failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				return ['success' => false, 'error' => $e->getMessage()];
			}
		}

		return $this->executeFallback($action, $fileId, $ruleId, $userId);
	}

	/**
	 * Minimal replication of approval's approve/reject state change, used when
	 * approval is enabled but its ApprovalService class cannot be loaded.
	 *
	 * @return array{success: bool, error?: string}
	 */
	private function executeFallback(string $action, int $fileId, int $ruleId, string $userId): array {
		$rule = $this->infoProvider->findRuleById($ruleId);
		if ($rule === null) {
			return ['success' => false, 'error' => 'rule not found'];
		}
		if (!in_array($userId, $this->infoProvider->resolveApproverUserIds($ruleId), true)) {
			return ['success' => false, 'error' => 'user is not an approver of this rule'];
		}
		try {
			$isPending = $this->tagObjectMapper->haveTag((string)$fileId, 'files', $rule['tagPending']);
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
		if (!$isPending) {
			return ['success' => false, 'error' => 'file is not pending approval'];
		}

		// replicate RuleService::storeAction(): delete-then-insert on
		// approval_activity keyed by (rule_id, file_id)
		$qb = $this->db->getQueryBuilder();
		$qb->delete('approval_activity')
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('approval_activity')
			->values([
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'rule_id' => $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
				'new_state' => $qb->createNamedParameter(
					$action === 'approve' ? Application::STATE_APPROVED : Application::STATE_REJECTED,
					IQueryBuilder::PARAM_INT
				),
				'timestamp' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'message' => $qb->createNamedParameter('via WeCom', IQueryBuilder::PARAM_STR),
			]);
		$qb->executeStatement();

		$finalTag = $action === 'approve' ? $rule['tagApproved'] : $rule['tagRejected'];
		$this->tagObjectMapper->assignTags((string)$fileId, 'files', $finalTag);
		$this->tagObjectMapper->unassignTags((string)$fileId, 'files', $rule['tagPending']);

		return ['success' => true];
	}
}
