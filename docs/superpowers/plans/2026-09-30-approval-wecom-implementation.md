# approval_wecom Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone Nextcloud app `approval_wecom` that (A) pushes approval requests to WeCom (企业微信) as interactive template cards with in-WeCom approve/reject via server callback, and (B) on approval, moves the approver-side share mount point into a configurable, date-organized archive folder.

**Architecture:** Standalone app (never modifies `approval`). Observes approval state through the public `OCP\SystemTag\TagAssignedEvent` (approval's states are system tags). Reads approval's DB tables read-only via `ApprovalInfoProvider`. Keeps its own `approval_wecom_requests` table (registered at pending time) because approval's `approval_activity` pending row is deleted at resolution. WeCom callback executes approve/reject through approval's own `ApprovalService` when available (soft dependency via `class_exists`), else a fallback replicates `RuleService::storeAction` (delete-then-insert) + tag swap. Settings UI is a Vue 3 admin page; secrets are stored with `sensitive: true` and never returned to the client.

**Tech Stack:** PHP 8.3, Nextcloud 33–35 OCP APIs, PHPUnit 9 (standalone, `nextcloud/ocp` stubs — no server checkout), Vue 3 + Vite (`@nextcloud/vite-config`, `@nextcloud/vue` v9).

**Spec:** `/home/eli/approval_wecom/docs/superpowers/specs/2026-09-30-approval-wecom-design.md`

## Global Constraints

- App ID: `approval_wecom`. PHP namespace: `OCA\ApprovalWeCom`. Project root: `/home/eli/approval_wecom` (its own git repo, already initialized; the spec is commit `58586d8`).
- **No local PHP exists.** All PHP/composer commands run through docker, mounting the app:
  - Composer: `docker run --rm -v /home/eli/approval_wecom:/app -w /app composer:2 composer <args>`
  - PHPUnit: `docker run --rm -v /home/eli/approval_wecom:/app -w /app php:8.3-cli vendor/bin/phpunit -c tests/phpunit.xml [--filter X]`
  - Lint: `docker run --rm -v /home/eli/approval_wecom:/app -w /app php:8.3-cli php -l lib/<file>.php`
  - Node/npm run natively (node 24): `npm install`, `npm run build` in `/home/eli/approval_wecom`.
- **Never modify the `approval` app** (`/home/eli/approval`). It is a read-only reference.
- **Do not depend on `ApprovalStateChangedEvent`** (user's upstream PR #449). Only public OCP events.
- Secrets (`corpsecret`, `token`, `encodingaeskey`): stored via `IAppConfig::setValueString(..., sensitive: true)`; never logged; the settings GET endpoint returns them only as `has_*` booleans.
- Target Nextcloud 33–35, PHP 8.3 (`info.xml` `<nextcloud min-version="33" max-version="35"/>`, composer platform php 8.3).
- All services must never throw past their own boundary at event-handling time: catch `\Throwable`, log via `Psr\Log\LoggerInterface` with context `['app' => 'approval_wecom']`, degrade gracefully.
- Approval schema facts (verified against upstream main migrations):
  - `approval_rules`: `id` INT PK, `tag_pending` INT, `tag_approved` INT, `tag_rejected` INT (+ `description`, `unapprove_when_modified`).
  - `approval_rule_approvers`: `id` INT PK, `rule_id` INT, `entity_type` INT (0=user, 1=group, 2=circle), `entity_id` STRING(300).
  - `approval_activity`: `id` INT PK, `file_id` INT, `rule_id` INT, `user_id` STRING(300), `new_state` INT (1=pending, 2=approved, 3=rejected), `timestamp` INT, `message` STRING.
  - `ApprovalService::approve(int $fileId, ?string $userId, string $etag, string $message = ''): bool` and `reject(...)` (same signature) throw `OutdatedEtagException` on etag mismatch; public `getEtag(int $fileId): string`.
- DB test-mock idiom (standalone PHPUnit): tests extend `OCA\ApprovalWeCom\Tests\TestCase` (created in Task 1). It provides `newQueryBuilderMock(): array` returning `[IDBConnection&MockObject, IQueryBuilder&MockObject]` with `expr()`, `createNamedParameter()` and all *chainable* builder methods stubbed `willReturnSelf()`, but **never** stubs the terminal methods `executeQuery()`/`executeStatement()` or the entry points `update()`/`insert()`/`delete()` — each test configures those itself (avoids PHPUnit double-configuration conflicts). `mockResult(array $rows): IResult&MockObject` returns a result mock whose `fetch()` `array_shift`s rows and returns `false` when empty.
- File layout follows PSR-4: `lib/` → `OCA\ApprovalWeCom\`, `tests/unit/` → `OCA\ApprovalWeCom\Tests\`.
- Commit after every task. Commit style: `feat: ...` / `test: ...` conventional commits.

---

### Task 1: App skeleton + test infrastructure

**Files:**
- Create: `appinfo/info.xml`
- Create: `appinfo/routes.php`
- Create: `lib/AppInfo/Application.php`
- Create: `composer.json`
- Create: `tests/phpunit.xml`, `tests/bootstrap.php`, `tests/unit/TestCase.php`
- Create: `package.json`, `vite.config.js`, `templates/adminSettings.php`
- Create: `.gitignore`
- Test: `tests/unit/ApplicationTest.php`

**Interfaces:**
- Produces: `OCA\ApprovalWeCom\AppInfo\Application` with constants `APP_ID = 'approval_wecom'`, `STATE_APPROVED = 2`, `STATE_REJECTED = 3` (mirror approval's internal states — intentionally *not* referencing approval's class, to stay standalone).
- Produces: `OCA\ApprovalWeCom\Tests\TestCase` helpers `newQueryBuilderMock()` / `mockResult(array $rows)` used by every later DB-touching test.
- Produces: routes `Config#getConfig` (GET `/config`), `Config#setConfig` (PUT `/config`), `Config#getApprovalRuleTags` (GET `/approval-rule-tags`), `Config#sendTestMessage` (POST `/test-message`), `WeComCallback#verify` (GET `/wecom/callback`), `WeComCallback#callback` (POST `/wecom/callback`). Controllers arrive in Tasks 8/10; route names are resolved lazily so the app loads fine before then.

- [ ] **Step 1: Write the failing test**

`tests/unit/ApplicationTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;

final class ApplicationTest extends TestCase {
	public function testConstants(): void {
		$this->assertSame('approval_wecom', Application::APP_ID);
		$this->assertSame(2, Application::STATE_APPROVED);
		$this->assertSame(3, Application::STATE_REJECTED);
	}
}
```

- [ ] **Step 2: Create composer.json, tests/phpunit.xml, tests/bootstrap.php, tests/unit/TestCase.php, .gitignore**

`composer.json`:

```json
{
	"name": "nextcloud/approval_wecom",
	"description": "WeCom (企业微信) integration and share archiving for the Nextcloud Approval app",
	"license": "AGPL-3.0-or-later",
	"require": {
		"php": ">=8.3"
	},
	"require-dev": {
		"phpunit/phpunit": "^9.6",
		"nextcloud/ocp": "dev-master"
	},
	"autoload": {
		"psr-4": {
			"OCA\\ApprovalWeCom\\": "lib/"
		}
	},
	"autoload-dev": {
		"psr-4": {
			"OCA\\ApprovalWeCom\\Tests\\": "tests/unit/"
		}
	},
	"config": {
		"platform": {
			"php": "8.3"
		}
	}
}
```

`tests/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="bootstrap.php" colors="true" failOnWarning="true" failOnRisky="true">
	<testsuites>
		<testsuite name="unit">
			<directory>unit</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

`tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
```

`tests/unit/TestCase.php`:

```php
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
```

`.gitignore`:

```
/vendor/
/node_modules/
/js/
```

- [ ] **Step 3: Install dependencies and run test to verify it fails**

Run:

```bash
docker run --rm -v /home/eli/approval_wecom:/app -w /app composer:2 composer install
docker run --rm -v /home/eli/approval_wecom:/app -w /app php:8.3-cli vendor/bin/phpunit -c tests/phpunit.xml --filter ApplicationTest
```

Expected: FAIL — `Class "OCA\ApprovalWeCom\AppInfo\Application" not found`.

- [ ] **Step 4: Create the app skeleton**

`appinfo/info.xml` (settings section and background job are added by Tasks 10 and 12 — the classes don't exist yet and Nextcloud would error loading them):

```xml
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
	<id>approval_wecom</id>
	<name>Approval WeCom integration</name>
	<summary>Send approval requests to WeCom (企业微信) and archive shared files after approval</summary>
	<description><![CDATA[Integrates the Approval app with WeCom (企业微信): approvers receive interactive template cards and can approve or reject directly inside WeCom. When a request is approved, the share mount point in each approver's files can be moved into a configurable date-organized archive folder. The original file of the requester is never touched.]]></description>
	<version>0.1.0</version>
	<licence>agpl</licence>
	<author>Eli</author>
	<namespace>ApprovalWeCom</namespace>
	<category>integration</category>
	<dependencies>
		<nextcloud min-version="33" max-version="35"/>
	</dependencies>
</info>
```

`appinfo/routes.php`:

```php
<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'Config#getConfig', 'url' => '/config', 'verb' => 'GET'],
		['name' => 'Config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'Config#getApprovalRuleTags', 'url' => '/approval-rule-tags', 'verb' => 'GET'],
		['name' => 'Config#sendTestMessage', 'url' => '/test-message', 'verb' => 'POST'],
		['name' => 'WeComCallback#verify', 'url' => '/wecom/callback', 'verb' => 'GET'],
		['name' => 'WeComCallback#callback', 'url' => '/wecom/callback', 'verb' => 'POST'],
	],
];
```

`lib/AppInfo/Application.php` (listener registration is added by Task 9):

```php
<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'approval_wecom';

	// Mirror of OCA\Approval states (kept as local constants so this app
	// never needs to load a class from the approval app).
	public const STATE_APPROVED = 2;
	public const STATE_REJECTED = 3;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
```

`templates/adminSettings.php`:

```php
<?php

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

script('approval_wecom', 'approval_wecom-adminSettings');
?>

<div id="approval_wecom-admin-settings"></div>
```

`package.json`:

```json
{
	"name": "approval_wecom",
	"private": true,
	"scripts": {
		"dev": "vite build --watch",
		"build": "vite build"
	},
	"dependencies": {
		"@nextcloud/axios": "^2.5.1",
		"@nextcloud/auth": "^2.5.0",
		"@nextcloud/initial-state": "^2.3.0",
		"@nextcloud/l10n": "^3.1.0",
		"@nextcloud/router": "^3.0.1",
		"@nextcloud/vue": "^9.0.0",
		"vue": "^3.5.0"
	},
	"devDependencies": {
		"@nextcloud/vite-config": "^2.3.0",
		"sass": "^1.71.0"
	}
}
```

`vite.config.js`:

```js
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	adminSettings: 'src/adminSettings.js',
})
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker run --rm -v /home/eli/approval_wecom:/app -w /app php:8.3-cli vendor/bin/phpunit -c tests/phpunit.xml --filter ApplicationTest`
Expected: PASS (1 test, 3 assertions).

- [ ] **Step 6: Commit**

```bash
cd /home/eli/approval_wecom
git add appinfo lib composer.json tests package.json vite.config.js templates .gitignore composer.lock
git commit -m "feat: app skeleton with standalone PHPUnit setup"
```

---

### Task 2: Migration + RequestRegistry + SettingsService

**Files:**
- Create: `lib/Migration/Version0001Date20260930000000.php`
- Create: `lib/Service/RequestRegistry.php`
- Create: `lib/Service/SettingsService.php`
- Test: `tests/unit/RequestRegistryTest.php`, `tests/unit/SettingsServiceTest.php`

**Interfaces:**
- Produces: `RequestRegistry`:
  - `register(int $fileId, int $ruleId, string $requesterUserId): void` — upsert (update-then-insert) keyed by `(file_id, rule_id)`; preserves existing `task_id`/`response_code`.
  - `attachCardInfo(int $fileId, int $ruleId, ?string $taskId, ?string $responseCode): void`
  - `find(int $fileId, int $ruleId): ?array{id:int, file_id:int, rule_id:int, requester_user_id:string, task_id:?string, response_code:?string, created_at:int}`
  - `delete(int $fileId, int $ruleId): void`
  - `deleteOlderThan(int $timestamp): int` (returns deleted row count)
- Produces: `SettingsService` (config keys below). Consumed by Tasks 3–12.
  - Getters: `getWeComEnabled(): bool`, `getCorpId(): string`, `getCorpSecret(): string`, `getAgentId(): string`, `getToken(): string`, `getEncodingAesKey(): string`, `isWeComConfigured(): bool`, `getArchiveEnabled(): bool`, `getArchiveFolder(): string` (default `'approval'`), `getArchiveSubfolder(): string` (`'none'|'month'|'year'`, default `'month'`), `getPendingTagIds(): int[]`, `getApprovedTagIds(): int[]`, `getRejectedTagIds(): int[]`
  - `saveAdminConfig(array $values): void` — empty-string secrets mean "keep current value"; secrets stored with `sensitive: true`.
  - `getAdminConfig(): array` — secrets masked to `has_corpsecret`/`has_token`/`has_encodingaeskey` booleans.

- [ ] **Step 1: Write the failing tests**

`tests/unit/RequestRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\Service\RequestRegistry;
use PHPUnit\Framework\MockObject\MockObject;

final class RequestRegistryTest extends TestCase {
	public function testRegisterUpdatesExistingRow(): void {
		[$db, $qb] = $this->newQueryBuilderMock();
		/** @var MockObject $qb */
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
		$qb->method('select')->willReturn($qb);
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
		$qb->method('select')->willReturn($qb);
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
```

`tests/unit/SettingsServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Tests;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCA\ApprovalWeCom\Service\SettingsService;
use OCP\IAppConfig;

final class SettingsServiceTest extends TestCase {
	/** @param array<string, string> $strings @param array<string, bool> $bools */
	private function makeService(array $strings = [], array $bools = []): IAppConfig&\PHPUnit\Framework\MockObject\MockObject {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $strings[$key] ?? $default
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $bools[$key] ?? $default
		);
		return $appConfig;
	}

	public function testDefaults(): void {
		$service = new SettingsService($this->makeService());
		$this->assertFalse($service->getWeComEnabled());
		$this->assertFalse($service->getArchiveEnabled());
		$this->assertSame('approval', $service->getArchiveFolder());
		$this->assertSame('month', $service->getArchiveSubfolder());
		$this->assertSame([], $service->getPendingTagIds());
		$this->assertFalse($service->isWeComConfigured());
	}

	public function testTagIdListsParseJsonAndFilter(): void {
		$service = new SettingsService($this->makeService([
			'trigger_pending_tag_ids' => '[1, 5, "9", 0, -2]',
			'trigger_approved_tag_ids' => 'not json',
		]));
		$this->assertSame([1, 5, 9], $service->getPendingTagIds());
		$this->assertSame([], $service->getApprovedTagIds());
	}

	public function testArchiveFolderSanitized(): void {
		$service = new SettingsService($this->makeService(['archive_folder' => ' /归档/ ']));
		$this->assertSame('归档', $service->getArchiveFolder());
	}

	public function testInvalidSubfolderFallsBackToMonth(): void {
		$service = new SettingsService($this->makeService(['archive_subfolder' => 'weekly']));
		$this->assertSame('month', $service->getArchiveSubfolder());
	}

	public function testGetAdminConfigMasksSecrets(): void {
		$service = new SettingsService($this->makeService(
			['corpsecret' => 's3cret', 'token' => '', 'encodingaeskey' => 'abc', 'corpid' => 'corp1'],
			['wecom_enabled' => true],
		));
		$config = $service->getAdminConfig();
		$this->assertTrue($config['wecom_enabled']);
		$this->assertSame('corp1', $config['corpid']);
		$this->assertTrue($config['has_corpsecret']);
		$this->assertFalse($config['has_token']);
		$this->assertTrue($config['has_encodingaeskey']);
		$this->assertArrayNotHasKey('corpsecret', $config);
	}

	public function testSaveKeepsEmptySecretsAndStoresSensitive(): void {
		$appConfig = $this->makeService();
		$saved = [];
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$saved): void {
				$saved[$key] = ['value' => $value, 'sensitive' => $sensitive];
			}
		);

		$service = new SettingsService($appConfig);
		$service->saveAdminConfig([
			'corpid' => 'corp1',
			'corpsecret' => '',            // empty => keep, must NOT be written
			'token' => 'tok-1',
			'trigger_pending_tag_ids' => [1, '2'],
			'archive_subfolder' => 'year',
		]);

		$this->assertSame(['value' => 'corp1', 'sensitive' => false], $saved['corpid']);
		$this->assertArrayNotHasKey('corpsecret', $saved);
		$this->assertSame(['value' => 'tok-1', 'sensitive' => true], $saved['token']);
		$this->assertSame('[1,2]', $saved['trigger_pending_tag_ids']['value']);
		$this->assertSame('year', $saved['archive_subfolder']['value']);
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker run --rm -v /home/eli/approval_wecom:/app -w /app php:8.3-cli vendor/bin/phpunit -c tests/phpunit.xml --filter 'RequestRegistryTest|SettingsServiceTest'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement migration, RequestRegistry, SettingsService**

`lib/Migration/Version0001Date20260930000000.php`:

```php
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
```

`lib/Service/RequestRegistry.php`:

```php
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
		$qb->update('approval_wecom_requests')
			->set('requester_user_id', $qb->createNamedParameter($requesterUserId, IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$updated = $qb->executeStatement();

		if ($updated === 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('approval_wecom_requests')
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
		$qb->update('approval_wecom_requests')
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
			->from('approval_wecom_requests')
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
		$qb->delete('approval_wecom_requests')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteOlderThan(int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('approval_wecom_requests')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
```

`lib/Service/SettingsService.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\ApprovalWeCom\Service;

use OCA\ApprovalWeCom\AppInfo\Application;
use OCP\IAppConfig;

class SettingsService {
	public const DEFAULT_ARCHIVE_FOLDER = 'approval';
	public const SUBFOLDER_MODES = ['none', 'month', 'year'];

	private const SECRET_KEYS = ['corpsecret', 'token', 'encodingaeskey'];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getWeComEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'wecom_enabled', false);
	}

	public function getCorpId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'corpid');
	}

	public function getCorpSecret(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'corpsecret');
	}

	public function getAgentId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'agentid');
	}

	public function getToken(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'token');
	}

	public function getEncodingAesKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'encodingaeskey');
	}

	public function isWeComConfigured(): bool {
		return $this->getCorpId() !== ''
			&& $this->getCorpSecret() !== ''
			&& $this->getAgentId() !== ''
			&& $this->getToken() !== ''
			&& $this->getEncodingAesKey() !== '';
	}

	public function getArchiveEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'archive_enabled', false);
	}

	public function getArchiveFolder(): string {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, 'archive_folder', self::DEFAULT_ARCHIVE_FOLDER));
		$value = trim($value, '/');
		return $value === '' ? self::DEFAULT_ARCHIVE_FOLDER : $value;
	}

	/** @return 'none'|'month'|'year' */
	public function getArchiveSubfolder(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'archive_subfolder', 'month');
		return in_array($value, self::SUBFOLDER_MODES, true) ? $value : 'month';
	}

	/** @return int[] */
	public function getPendingTagIds(): array {
		return $this->getTagIdList('trigger_pending_tag_ids');
	}

	/** @return int[] */
	public function getApprovedTagIds(): array {
		return $this->getTagIdList('trigger_approved_tag_ids');
	}

	/** @return int[] */
	public function getRejectedTagIds(): array {
		return $this->getTagIdList('trigger_rejected_tag_ids');
	}

	/** @return int[] */
	private function getTagIdList(string $key): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $key, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$ids = array_map(static fn ($v): int => (int)$v, $decoded);
		return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
	}

	/**
	 * Save values coming from the admin settings UI. Unknown keys are ignored.
	 * An empty-string secret means "keep the stored value".
	 */
	public function saveAdminConfig(array $values): void {
		if (array_key_exists('wecom_enabled', $values)) {
			$this->appConfig->setValueBool(Application::APP_ID, 'wecom_enabled', (bool)$values['wecom_enabled']);
		}
		foreach (['corpid', 'agentid'] as $key) {
			if (isset($values[$key]) && is_string($values[$key])) {
				$this->appConfig->setValueString(Application::APP_ID, $key, trim($values[$key]));
			}
		}
		foreach (self::SECRET_KEYS as $key) {
			if (isset($values[$key]) && is_string($values[$key]) && $values[$key] !== '') {
				$this->appConfig->setValueString(Application::APP_ID, $key, trim($values[$key]), false, true);
			}
		}
		if (array_key_exists('archive_enabled', $values)) {
			$this->appConfig->setValueBool(Application::APP_ID, 'archive_enabled', (bool)$values['archive_enabled']);
		}
		if (isset($values['archive_folder']) && is_string($values['archive_folder'])) {
			$folder = trim(trim($values['archive_folder']), '/');
			if ($folder !== '') {
				$this->appConfig->setValueString(Application::APP_ID, 'archive_folder', $folder);
			}
		}
		if (isset($values['archive_subfolder']) && in_array($values['archive_subfolder'], self::SUBFOLDER_MODES, true)) {
			$this->appConfig->setValueString(Application::APP_ID, 'archive_subfolder', $values['archive_subfolder']);
		}
		foreach (['trigger_pending_tag_ids', 'trigger_approved_tag_ids', 'trigger_rejected_tag_ids'] as $key) {
			if (isset($values[$key]) && is_array($values[$key])) {
				$ids = array_values(array_filter(
					array_map(static fn ($v): int => (int)$v, $values[$key]),
					static fn (int $id): bool => $id > 0
				));
				$this->appConfig->setValueString(Application::APP_ID, $key, json_encode($ids));
			}
		}
	}

	/** Config as exposed to the admin UI: secrets are reduced to booleans. */
	public function getAdminConfig(): array {
		return [
			'wecom_enabled' => $this->getWeComEnabled(),
			'corpid' => $this->getCorpId(),
			'agentid' => $this->getAgentId(),
			'has_corpsecret' => $this->getCorpSecret() !== '',
			'has_token' => $this->getToken() !== '',
			'has_encodingaeskey' => $this->getEncodingAesKey() !== '',
			'archive_enabled' => $this->getArchiveEnabled(),
			'archive_folder' => $this->getArchiveFolder(),
			'archive_subfolder' => $this->getArchiveSubfolder(),
			'trigger_pending_tag_ids' => $this->getPendingTagIds(),
			'trigger_approved_tag_ids' => $this->getApprovedTagIds(),
			'trigger_rejected_tag_ids' => $this->getRejectedTagIds(),
		];
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker run --rm -v /home/eli/approval_wecom:/app -w /app php:8.3-cli vendor/bin/phpunit -c tests/phpunit.xml --filter 'RequestRegistryTest|SettingsServiceTest'`
Expected: PASS (13 tests).

- [ ] **Step 5: Commit**

```bash
cd /home/eli/approval_wecom
git add lib/Migration lib/Service tests/unit/RequestRegistryTest.php tests/unit/SettingsServiceTest.php
git commit -m "feat: request registry table, registry service and settings service"
```

<!-- CONTINUE-PLAN -->
