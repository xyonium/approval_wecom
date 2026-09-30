<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Service;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Read-only window into the approval app's database tables.
 * Nothing here ever writes to approval's tables.
 */
class ApprovalInfoProvider {
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}

	public function isApprovalAppEnabled(): bool {
		return $this->appManager->isEnabledForUser('approval');
	}

	/**
	 * Find the approval rule that uses the given tag in any of its three slots.
	 *
	 * @return ?array{id: int, tagPending: string, tagApproved: string, tagRejected: string, matched: 'pending'|'approved'|'rejected'}
	 */
	public function findRuleByTagId(int $tagId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'tag_pending', 'tag_approved', 'tag_rejected')
			->from('approval_rules')
			->where($qb->expr()->eq('tag_pending', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->orWhere($qb->expr()->eq('tag_approved', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->orWhere($qb->expr()->eq('tag_rejected', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}
		$matched = match (true) {
			(int)$row['tag_pending'] === $tagId => 'pending',
			(int)$row['tag_approved'] === $tagId => 'approved',
			default => 'rejected',
		};
		return [
			'id' => (int)$row['id'],
			'tagPending' => (string)$row['tag_pending'],
			'tagApproved' => (string)$row['tag_approved'],
			'tagRejected' => (string)$row['tag_rejected'],
			'matched' => $matched,
		];
	}

	/**
	 * @return ?array{id: int, tagPending: string, tagApproved: string, tagRejected: string}
	 */
	public function findRuleById(int $ruleId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'tag_pending', 'tag_approved', 'tag_rejected')
			->from('approval_rules')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}
		return [
			'id' => (int)$row['id'],
			'tagPending' => (string)$row['tag_pending'],
			'tagApproved' => (string)$row['tag_approved'],
			'tagRejected' => (string)$row['tag_rejected'],
		];
	}

	/**
	 * Raw approver entities of a rule (users, groups, circles).
	 *
	 * @return list<array{type: int, entityId: string}> type is one of Application::TYPE_*
	 */
	public function getApproverEntities(int $ruleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('entity_type', 'entity_id')
			->from('approval_rule_approvers')
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();

		$entities = [];
		while ($row = $result->fetch()) {
			$entities[] = [
				'type' => (int)$row['entity_type'],
				'entityId' => (string)$row['entity_id'],
			];
		}
		$result->closeCursor();
		return $entities;
	}

	/**
	 * Approver entities resolved to concrete user IDs. Groups are expanded via
	 * the group manager; circles are skipped (circle shares are not handled).
	 *
	 * @return string[]
	 */
	public function resolveApproverUserIds(int $ruleId): array {
		$userIds = [];
		foreach ($this->getApproverEntities($ruleId) as $entity) {
			if ($entity['type'] === Application::TYPE_USER) {
				$userIds[] = $entity['entityId'];
			} elseif ($entity['type'] === Application::TYPE_GROUP) {
				$group = $this->groupManager->get($entity['entityId']);
				if ($group === null) {
					$this->logger->debug('Approval rule ' . $ruleId . ' references unknown group ' . $entity['entityId'], ['app' => Application::APP_ID]);
					continue;
				}
				foreach ($group->getUsers() as $user) {
					$userIds[] = $user->getUID();
				}
			} else {
				$this->logger->debug('Approval rule ' . $ruleId . ': circle approvers are not supported by approval_wecom', ['app' => Application::APP_ID]);
			}
		}
		return array_values(array_unique($userIds));
	}

	/**
	 * User who requested approval, from approval's activity table.
	 * Only valid while the request is pending: approval deletes this row at
	 * resolution time (storeAction is delete-then-insert).
	 */
	public function findPendingRequesterUserId(int $fileId, int $ruleId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('approval_activity')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('new_state', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('timestamp', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : (string)$row['user_id'];
	}

	/**
	 * All tags used by approval rules, for the admin settings auto-fill.
	 *
	 * @return list<array{ruleId: int, tagPending: int, tagApproved: int, tagRejected: int}>
	 */
	public function getAllRuleTags(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'tag_pending', 'tag_approved', 'tag_rejected')
			->from('approval_rules')
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();

		$rules = [];
		while ($row = $result->fetch()) {
			$rules[] = [
				'ruleId' => (int)$row['id'],
				'tagPending' => (int)$row['tag_pending'],
				'tagApproved' => (int)$row['tag_approved'],
				'tagRejected' => (int)$row['tag_rejected'],
			];
		}
		$result->closeCursor();
		return $rules;
	}
}
