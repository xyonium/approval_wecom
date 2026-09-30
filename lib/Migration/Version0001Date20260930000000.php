<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20260930000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('approval_wecom_requests')) {
			$table = $schema->createTable('approval_wecom_requests');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('rule_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('requester_user_id', Types::STRING, [
				'notnull' => true,
				'length' => 300,
			]);
			$table->addColumn('task_id', Types::STRING, [
				'notnull' => false,
				'length' => 128,
			]);
			$table->addColumn('response_code', Types::STRING, [
				'notnull' => false,
				'length' => 512,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['file_id', 'rule_id'], 'awc_req_file_rule_idx');
		}

		return $schema;
	}
}
