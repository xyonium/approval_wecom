<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\Service\RequestRegistry;

final class RequestRegistryTest extends TestCase {
	public function testRegisterUpdatesExistingRow(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->expects($this->once())->method('update')->willReturn($qb);
		$qb->expects($this->never())->method('insert');
		$qb->method('executeStatement')->willReturn(1);

		$registry = new RequestRegistry($db);
		$registry->register(12, 3, 'alice');
	}

	public function testRegisterInsertsWhenNoRowExists(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->expects($this->once())->method('update')->willReturn($qb);
		$qb->expects($this->once())->method('insert')->willReturn($qb);
		// 0 rows updated by UPDATE, then INSERT affects 1 row
		$qb->method('executeStatement')->willReturnOnConsecutiveCalls(0, 1);

		$registry = new RequestRegistry($db);
		$registry->register(12, 3, 'alice');
	}

	public function testFindReturnsTypedRow(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([[
			'id' => '5', 'file_id' => '12', 'rule_id' => '3',
			'requester_user_id' => 'alice', 'task_id' => 'awc_12_3_1',
			'response_code' => 'rc-abc', 'created_at' => '1700000000',
		]]));

		$registry = new RequestRegistry($db);
		$row = $registry->find(12, 3);

		$this->assertSame(5, $row['id']);
		$this->assertSame(12, $row['file_id']);
		$this->assertSame(3, $row['rule_id']);
		$this->assertSame('alice', $row['requester_user_id']);
		$this->assertSame('awc_12_3_1', $row['task_id']);
		$this->assertSame('rc-abc', $row['response_code']);
		$this->assertSame(1700000000, $row['created_at']);
	}

	public function testFindReturnsNullWhenMissing(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->method('executeQuery')->willReturn($this->mockResult([]));

		$registry = new RequestRegistry($db);
		$this->assertNull($registry->find(12, 3));
	}

	public function testAttachCardInfoUpdatesRow(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->expects($this->once())->method('update')->willReturn($qb);
		$qb->method('executeStatement')->willReturn(1);

		$registry = new RequestRegistry($db);
		$registry->attachCardInfo(12, 3, 'awc_12_3_1', 'rc-abc');
	}

	public function testDelete(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->expects($this->once())->method('delete')->willReturn($qb);
		$qb->method('executeStatement')->willReturn(1);

		$registry = new RequestRegistry($db);
		$registry->delete(12, 3);
	}

	public function testDeleteOlderThanReturnsCount(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		$qb->expects($this->once())->method('delete')->willReturn($qb);
		$qb->method('executeStatement')->willReturn(4);

		$registry = new RequestRegistry($db);
		$this->assertSame(4, $registry->deleteOlderThan(1700000000));
	}
}
