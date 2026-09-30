<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Own bookkeeping of pending approval requests.
 *
 * Necessary because approval's RuleService::storeAction() deletes the pending
 * approval_activity row when the request is resolved, so the requester can
 * no longer be recovered from approval's tables at resolution time.
 */
class RequestRegistry {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function register(int $fileId, int $ruleId, string $requesterUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('aprv_wc_reqs')
			->set('requester_user_id', $qb->createNamedParameter($requesterUserId, IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$updated = $qb->executeStatement();

		if ($updated === 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('aprv_wc_reqs')
				->values([
					'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'rule_id' => $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT),
					'requester_user_id' => $qb->createNamedParameter($requesterUserId, IQueryBuilder::PARAM_STR),
					'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				]);
			try {
				$qb->executeStatement();
			} catch (\Throwable) {
				// Unique key (file_id, rule_id) lost a concurrent-insert race:
				// the row exists now, which is all we need.
			}
		}
	}

	public function attachCardInfo(int $fileId, int $ruleId, ?string $taskId, ?string $responseCode): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('aprv_wc_reqs')
			->set('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_STR))
			->set('response_code', $qb->createNamedParameter($responseCode, IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @return ?array{id: int, file_id: int, rule_id: int, requester_user_id: string, task_id: ?string, response_code: ?string, created_at: int}
	 */
	public function find(int $fileId, int $ruleId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'rule_id', 'requester_user_id', 'task_id', 'response_code', 'created_at')
			->from('aprv_wc_reqs')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}
		return [
			'id' => (int)$row['id'],
			'file_id' => (int)$row['file_id'],
			'rule_id' => (int)$row['rule_id'],
			'requester_user_id' => (string)$row['requester_user_id'],
			'task_id' => $row['task_id'] === null ? null : (string)$row['task_id'],
			'response_code' => $row['response_code'] === null ? null : (string)$row['response_code'],
			'created_at' => (int)$row['created_at'],
		];
	}

	public function delete(int $fileId, int $ruleId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('aprv_wc_reqs')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteOlderThan(int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('aprv_wc_reqs')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
