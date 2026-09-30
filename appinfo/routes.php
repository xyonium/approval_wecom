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
