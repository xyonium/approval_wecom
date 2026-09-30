<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

abstract class TestCase extends PhpUnitTestCase {
	/**
	 * IDBConnection + IQueryBuilder mocks. Chainable methods return the builder
	 * itself; terminal methods (executeQuery/executeStatement) and the write
	 * entry points (update/insert/delete) are left for each test to configure.
	 *
	 * @return array{0: IDBConnection&MockObject, 1: IQueryBuilder&MockObject}
	 */
	protected function newQueryBuilderMock(): array {
		$expr = $this->createMock(IExpressionBuilder::class);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => ':' . (is_scalar($value) ? (string)$value : 'param')
		);
		foreach ([
			'select', 'selectAlias', 'from', 'where', 'andWhere', 'orWhere',
			'orderBy', 'groupBy', 'setMaxResults', 'set', 'values', 'resetQueryParts',
		] as $method) {
			$qb->method($method)->willReturn($qb);
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return [$db, $qb];
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return IResult&MockObject
	 */
	protected function mockResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			$row = array_shift($rows);
			return $row === null ? false : $row;
		});
		$result->method('fetchOne')->willReturnCallback(static function () use (&$rows) {
			$row = array_shift($rows);
			return $row === null ? false : reset($row);
		});
		return $result;
	}
}
