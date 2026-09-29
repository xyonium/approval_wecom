# approval_wecom 设计文档

日期：2026-09-30
状态：待评审

## 1. 背景与目标

Nextcloud 的 approval 应用提供文件审批流程（规则 = pending/approved/rejected 三个系统标签 + 审批人/申请人）。本应用 `approval_wecom` 作为**独立应用**为其增加两个站点级能力，不修改 approval 源码：

1. **企业微信审批卡片**：审批发起时，向审批人推送企微「按钮交互型模板卡片」，审批人可在企微内一键批准/拒绝；审批结果也会推送企微通知给申请人。
2. **审批人侧共享归档**：审批通过时，把审批时自动创建给审批人的共享，在每个审批人的文件树里移入按日期组织的归档文件夹，避免审批人主目录被共享文件堆满（当前痛点：审批人打开主文件夹很慢）。

### 为什么做成独立应用

- approval 的状态机完全建立在**系统标签**上，`OCP\SystemTag\TagAssignedEvent` 是公开 OCP 事件，独立应用即可感知状态变化，无需 fork。
- 本地 fork 的 `ApprovalStateChangedEvent`（PR #449）对本应用**不是必需的**；该 PR 可继续推上游，与本应用互不影响。
- 企微凭据、归档策略是站点私有逻辑，不适合上游。
- 代价：解析规则/审批人/申请人需要**只读** approval 的 4 张数据库表（schema 多年来稳定），统一封装在 `ApprovalInfoProvider` 中。

## 2. 非目标（YAGNI）

- 不修改 approval 应用任何代码。
- 不支持 circle 类型共享的归档（circles provider 对 moveShare 支持不确定），跳过并记日志。
- 不做按规则区分企微应用凭据（全局一套自建应用）。
- 不做企微内免登 Nextcloud（卡片跳转链接打开 NC 页面，未登录则走正常登录）。
- 不处理"撤销审批"通知（pending 标签被摘除不推送）。
- 不做审批人手动再分享出去的共享的归档。
- 归档只移动审批人侧挂载点，绝不动申请人的原文件。

## 3. 总体架构

```
文件被打上标签（approval 按钮流程 / 手动打标签 / 工作流引擎）
        │
        ▼  OCP\SystemTag\TagAssignedEvent（公开事件）
TagAssignmentListener
        │ 按设置页配置的触发标签分发
        ├── 标签 ∈ pending 标签集 ──> WeComService.sendApprovalCards()
        ├── 标签 ∈ approved 标签集 ──> ArchiveService.archiveShares()
        │                           └ WeComService.notifyRequesterResult() + 更新所有卡片
        └── 标签 ∈ rejected 标签集 ──> WeComService.notifyRequesterResult() + 更新所有卡片

企微后台按钮回调 ──HTTP──> WeComCallbackController（公开路由，验签+解密）
        └──> 邮箱匹配 NC 用户 ──> 优先调 approval 的 ApprovalService（不可用时复刻其标签动作，§6.3）
        └──> update_template_card 置灰按钮
```

应用 ID：`approval_wecom`；PHP 命名空间 `OCA\ApprovalWeCom`；目标 Nextcloud 33–35（与 approval 3.3.2 一致）。

运行前提：approval 应用处于启用状态。启动时经 `IAppManager::isEnabledForUser('approval')` 检查，未启用则所有监听器静默跳过（本应用是 approval 的伴生增强包，但不修改、不强依赖其 PHP 类）。

## 4. 与 approval 应用的耦合面

只读访问以下表（经 `IDBConnection`，全部封装在 `ApprovalInfoProvider`）：

| 表 | 用途 | 关键列 |
|---|---|---|
| `approval_rules` | 触发标签 → 规则 | `id`, `tag_pending`, `tag_approved`, `tag_rejected`, `description` |
| `approval_rule_approvers` | 规则 → 审批人实体 | `rule_id`, `entity_id`, `entity_type`（0=user, 1=group, 2=circle） |
| `approval_activity` | 文件+规则 → 申请人（取最新 pending 行动的 `user_id`） | `file_id`, `rule_id`, `user_id`, `new_state`, `timestamp` |

查询接口（全部返回 null/array，不抛异常，降级友好）：

- `findRuleByTagId(int $tagId): ?array` — 任一触发列匹配即返回规则（含命中的角色 pending/approved/rejected）
- `getApproverEntities(int $ruleId): array` — `[{entityId, type}]`
- `resolveApproverUserIds(array $entities): array` — user 直取；group 展开成员；circle 经 `CirclesManager`（存在时）展开
- `findPendingRequesterUserId(int $fileId, ?int $ruleId): ?string` — 读 `approval_activity` 中该 file+rule 的 `new_state=1`(pending) 行的 `user_id`。**只在 pending 标签事件时刻调用**（该时刻行必然存在）；approved/rejected 之后该行已被 approval 的 `storeAction` 先删后插替换，不能再作为申请人来源（见 §9 自建表的动机）

## 5. 功能 A：企微审批卡片推送

### 5.1 触发

`TagAssignmentListener` 收到 `TagAssignedEvent`（`getObjectType() === 'files'`），对每个 objectId × 每个标签：标签 ID ∈ 配置的 `trigger_pending_tag_ids` 时：

1. **请求登记**（总是执行，与企微开关无关）：向 `approval_wecom_requests` upsert `(file_id, rule_id)` 行，写入 `requester_user_id`（取自 `ApprovalInfoProvider.findPendingRequesterUserId()`，兜底文件 owner）。归档功能靠它找申请人。
2. `wecom_enabled` 且规则存在 → `WeComService.sendApprovalCards()`。

去重：同一 (fileId, tagId) 在一次事件中只处理一次；approval 正常流程对同一文件同一 pending 标签只 assign 一次，无需额外持久去重。

### 5.2 发送流程（WeComService.sendApprovalCards）

前置：请求登记已由监听器完成（§5.1）。

1. `ApprovalInfoProvider`：tag → rule → 审批人实体 → 展开为 NC uid 列表。
2. 每个审批人 uid 取 `IUser::getEMailAddress()`；无邮箱的跳过并记 info 日志。
3. 邮箱 → 企微 userid：`POST /cgi-bin/user/get_userid_by_email`（`{"email": "...", "email_type": 1}`）。查不到（errcode 非 0）跳过并记 warning。
4. 全部审批人都无企微映射 → 记 warning，结束。
5. 组卡片（`msgtype=template_card`，`card_type=button_interaction`）：
   - `main_title.title` = `审批请求：{文件名}`；`main_title.desc` = `申请人：{显示名}`
   - `horizontal_content_list`：规则描述（如有）、文件名、时间
   - `card_action`：`type=1`，url = `{NC 绝对地址}/f/{fileId}`（NC 内置 fileid 短链）
   - `button_list`：
     - `{text: "批准", style: 1, key: "approve_{fileId}_{ruleId}"}`
     - `{text: "拒绝", style: 3, key: "reject_{fileId}_{ruleId}"}`
   - `task_id` = `approval_{fileId}_{ruleId}_{unix_ts}`（仅数字字母 `_-@`，≤128 字节，每次发送唯一）
6. `POST /cgi-bin/message/send`（`touser` 用 `|` 连接全部 userid，`agentid` 用配置值）。
7. 发送成功（errcode=0）→ 把返回的 `response_code` 和 `task_id` 更新到 `approval_wecom_requests` 对应行，供审批结束后更新所有人卡片。

### 5.3 access_token 缓存

`GET /cgi-bin/gettoken?corpid=..&corpsecret=..` → 存 `ICache`（`ICacheFactory`），TTL = 返回的 `expires_in` − 300 秒。所有业务调用统一走 `WeComService.apiCall()`，遇到 errcode 42001/40014（token 失效）强制刷新重试一次。

### 5.4 HTTP 约束

`IClientService` 新 client：`timeout=5`，`connect_timeout=3`。任何异常/非 0 errcode → 记日志，**绝不向上抛**——企微故障不能影响审批主流程。

## 6. 功能 B：企微回调审批

### 6.1 路由与企微侧配置

- 路由：`/apps/approval_wecom/wecom/callback`（GET + POST），控制器标注 `#[PublicPage] #[NoCSRFRequired]`（企微服务器直连，无会话）。
- 设置页显示完整的回调 URL 供管理员复制到企微自建应用后台（"接收消息"配置），服务器需公网可达 + HTTPS。

### 6.2 WXBizMsgCrypt（lib/Service/WXBizMsgCrypt.php）

标准企微回调加解密，直接用 `openssl` 实现，不引入 SDK：

- AES 密钥：`base64_decode(EncodingAESKey . "=")`（43 字符 → 32 字节密钥）；IV = 密钥前 16 字节；AES-256-CBC + PKCS7。
- 明文结构：`random(16) | msg_len(4, 网络序) | msg | receiveid`；解密后校验 `receiveid == 配置的 CorpID`。
- 签名：`sha1(implode('', sort([token, timestamp, nonce, encrypt_msg])))` 与 `msg_signature` 比对（hash_equals）。
- `verifyUrl($msgSignature, $timestamp, $nonce, $echoStr): string` — GET 验证用，返回解密明文。
- `decryptMsg($msgSignature, $timestamp, $nonce, $postXml): array` — POST 用，返回解析后的字段数组。

### 6.3 POST 处理流程

1. 验签 + 解密失败 → 403，记 warning。
2. 解析 XML：`MsgType=event`、`Event=template_card_event`，取 `EventKey`、`FromUserName`（点击者企微 userid）、`ResponseCode`、`AgentID`（须等于配置的 agentid）。其他事件类型 → 回复 `success` 并忽略。
3. `EventKey` 必须匹配 `/^(approve|reject)_(\d+)_(\d+)$/` → action、fileId、ruleId；不匹配 → 忽略。
4. 用户映射：`GET /cgi-bin/user/get?userid={FromUserName}` → 取 `email` → `IUserManager::getByEmail()` → NC 用户。查不到 → 用 `ResponseCode` 更新该用户卡片按钮文案为「无法识别用户」，回复 success。
5. 执行审批（**软依赖混合策略**）：
   - **首选**：approval 应用可用（`class_exists(\OCA\Approval\Service\ApprovalService::class)`）时，通过 server 容器取其实例调 `approve()/reject()`（etag 用其 `getEtag()` 取当前值）。好处：权限校验、NC 通知、activity 流条目全部复用 approval 原生逻辑。
   - **兜底**（approval 启用但 `ApprovalService` 类不可用，如未来版本改名时）：复刻其最小动作——校验该用户 ∈ 规则审批人 ∧ 文件仍带 pending 标签；写 `approval_activity`（**与 approval 的 `storeAction` 完全一致：先按 `(rule_id, file_id)` DELETE 再 INSERT**，`new_state` 2/3，`message` 固定 `via WeCom`）；`assignTags(approved/rejected)` + `unassignTags(pending)`（`ISystemTagObjectMapper`）。
   - 标签 assign 会自然触发 `TagAssignedEvent` → 本应用的归档 + 结果通知自动接续执行，无需额外调用。
   - `OutdatedEtagException` 或审批返回 false（状态已变）→ 走步骤 7 的「已由他人处理/无法处理」。
6. 用本次回调的 `ResponseCode` 调 `update_template_card`，把点击者卡片按钮置灰为「已批准/已拒绝」。
7. 已被他人处理（步骤 5 的 pending 校验失败）→ 更新该点击者卡片为「已由他人处理」，回复 success。
8. 回复：HTTP 200 明文 `success`（企微认可）；全程同步处理，各企微 API 调用 3–5 秒超时，整体应在企微 5 秒回调超时内完成；实现后需在真实环境验证时延，若超时则改为立即回 success + QueuedJob 异步处理（ResponseCode 72h 内有效）。

### 6.4 审批结束时的卡片与通知（approved/rejected 标签事件驱动）

1. `WeComService.updateAllCards(fileId, ruleId, resultText)`：查 `approval_wecom_requests` 取发送时存的 `response_code`（一次性，72h 有效），调 `update_template_card` 替换所有接收者卡片为纯文本结果卡（`text_notice`：「{文件名} 已批准/已拒绝，操作人：XXX」）。code 失效/消费失败仅记日志。行的删除由监听器统一负责（§9）。
2. `WeComService.notifyRequesterResult(...)`：申请人取 `approval_wecom_requests.requester_user_id`；有企微映射则发 `text_notice` 卡片（结果 + 文件链接 + 操作人）。

## 7. 功能 C：审批人侧共享归档

### 7.1 触发

标签 ID ∈ 配置的 `trigger_approved_tag_ids` 且 `archive_enabled`。**只在审批通过时归档，拒绝不归档。**

### 7.2 归档算法（ArchiveService.archiveShares(fileId, ruleId|null)）

```
rule     = ApprovalInfoProvider.findRuleByTagId(tagId)          // 可能为 null（标签不属于任何规则）
requester= approval_wecom_requests 表中 (fileId, ruleId) 的 requester_user_id
           ?? node owner 的 uid                                  // 兜底：应用安装前的存量 pending 等
node     = root.getUserFolder(requester).getFirstNodeById(fileId) // null → 记日志返回
approverEntities = rule ? getApproverEntities(rule.id) : null    // null 表示不过滤

foreach [TYPE_USER, TYPE_GROUP, TYPE_CIRCLE] as $type:
  foreach shareManager.getSharesBy(requester, $type, node, reshares=false, limit=-1) as $share:
    if approverEntities !== null && share.sharedWith ∉ approverEntities: continue
    USER:  moveForRecipient(share, share.sharedWith)
    GROUP: foreach groupManager.get(share.sharedWith).getUsers() as $member:
             $s = shareManager.getShareById(share.id, $member.uid)  // 取该成员视角的 target
             moveForRecipient($s, $member.uid)
    CIRCLE: 记 info 日志跳过

moveForRecipient($share, $recipientId):
  $userFolder = root.getUserFolder($recipientId)
  $base = trim(archive_folder, '/') 默认 'approval'
  $sub  = archive_subfolder == 'month' ? date('Y-m') : == 'year' ? date('Y') : null
  $dir  = 逐级 newFolder 创建 $userFolder/$base[/$sub]（已存在则复用；$base 位置被同名文件占用 → 记 warning 跳过该接收者）
  $name = $node->getName()；while ($dir->nodeExists($name)) $name = 在扩展名前追加 " (n)" 后缀递增
  $share->setTarget('/' . $userFolder->getRelativePath($dir->getPath()) . '/' . $name)
  shareManager.moveShare($share, $recipientId)
```

要点：

- 移动的是**接收者侧的共享挂载点**（share target），申请人的文件不动。
- 只处理 `sharedBy == 申请人` 的共享（`getSharesBy` 的第一个参数保证）。
- 每个接收者独立 try/catch，单个失败不影响其他人。
- group share 逐成员移动（group share 支持每成员独立 target）；circle 跳过。
- 归档后共享关系保留，审批人仍可在归档文件夹中访问文件。

### 7.3 配置项

| 键 | 默认 | 说明 |
|---|---|---|
| `archive_enabled` | `0` | 开关 |
| `archive_folder` | `approval` | 归档根文件夹名（相对各审批人文件根目录） |
| `archive_subfolder` | `month` | `none` / `month`（`2026-09`）/ `year`（`2026`） |

## 8. 设置页与配置存储

新增独立 admin 设置区块（`Settings\Admin` + `Settings\AdminSection`，section id `approval_wecom`，名称「审批企微集成」），Vue 单页挂在 `templates/adminSettings.php`。

配置全部存 `IAppConfig`（app = `approval_wecom`）：

| 键 | 敏感 | 说明 |
|---|---|---|
| `wecom_enabled` | 否 | 总开关 |
| `wecom_corpid` | 否 | 企业 ID |
| `wecom_corpsecret` | **是** | 应用 Secret（`setValueString(..., sensitive: true)`） |
| `wecom_agentid` | 否 | 应用 AgentId |
| `wecom_token` | **是** | 回调 Token |
| `wecom_encodingaeskey` | **是** | 回调 EncodingAESKey（43 字符） |
| `archive_enabled` / `archive_folder` / `archive_subfolder` | 否 | 见 §7.3 |
| `trigger_pending_tag_ids` | 否 | JSON 数组，标签 ID |
| `trigger_approved_tag_ids` | 否 | 同上 |
| `trigger_rejected_tag_ids` | 否 | 同上 |

设置页组成：

1. **企业微信**：开关 + 5 个凭据字段；只读展示回调 URL；「发送测试消息」按钮（给管理员自己发一条文本卡，验证凭据与网络）。
2. **触发标签**：三个标签多选（数据来自 `ISystemTagManager::getAllTags()`，按名称排序展示，存 ID）；「自动填入 approval 规则的标签」按钮（读 `approval_rules` 三列分别填入三个多选）。
3. **归档**：开关 + 文件夹名输入 + 子文件夹方式三选一（不建 / 按月 / 按年）。

接口：`GET /apps/approval_wecom/config` 返回全部配置（**敏感字段只返回是否已设置的布尔值**，不回传明文）；`PUT` 保存（敏感字段留空表示不修改）。均走 `#[AuthorizedAdminSetting]`。

## 9. 数据库

本应用自建一张表（migration `Version0001Date20260930000000`）：

```
approval_wecom_requests
  id                 int autoincrement PK
  file_id            int
  rule_id            int
  requester_user_id  string(300)
  task_id            string(128)  null   // 企微卡片 task_id，发送成功后回填
  response_code      string(512)  null   // 企微发送返回的 code，发送成功后回填
  created_at         int                 // unix ts
  unique key (file_id, rule_id)
```

**为什么需要这张表**：approval 的 `storeAction` 是"先删后插"——审批通过后，`approval_activity` 里该 (file, rule) 的 pending 行（申请人）已被替换成 approved 行（审批人）。归档监听器在 approved 标签事件里运行时，申请人的唯一可靠来源是我们自己在 pending 时刻登记的行。

生命周期：

- pending 触发标签事件 → upsert（file_id, rule_id, requester_user_id）
- 卡片发送成功 → 回填 task_id / response_code
- approved/rejected 触发 → 监听器读出该行，依次供归档、卡片更新、申请人通知使用，**全部用完后由监听器删除该行**
- 每日 `TimedJob` 兜底清理 30 天前的孤儿行（永不 resolve 的 pending）

## 10. 安全模型

- 回调端点公开但**三重校验**：msg_signature（SHA1）+ AES 解密成功 + 明文尾部 CorpID 匹配。伪造请求无法通过。
- `EventKey` 仅作为路由信息，权限最终由「该 NC 用户 ∈ 规则审批人 ∧ 文件仍处于 pending」双重校验把关（§6.3 步骤 5）。
- 企微凭据 sensitive 存储；日志中绝不输出 secret/token/AES key/response_code；HTTP client 不记录请求体。
- 设置接口仅授权管理员（`#[AuthorizedAdminSetting]`）。
- 归档不删除任何数据；moveShare 失败仅影响单个接收者。

## 11. 错误处理总原则

- 企微侧一切故障（网络、errcode、映射缺失）→ 记日志 + 静默跳过，不影响审批与归档。
- 归档侧一切故障（目录创建失败、moveShare 异常）→ 记 warning，继续下一个接收者。
- 监听器整体 try/catch，绝不因本应用异常阻断标签事件分发。

## 12. 测试计划（tests/unit，PHPUnit，沿用 approval 的测试布局）

| 测试类 | 覆盖点 |
|---|---|
| `WXBizMsgCryptTest` | 构造已知明文→加密→解密往返；签名正确/篡改；CorpID 不匹配拒绝 |
| `WeComServiceTest` | mock `IClientService`：token 缓存命中/过期刷新；邮箱→userid 失败跳过；卡片 JSON 结构断言；发送失败不抛异常 |
| `WeComCallbackControllerTest` | GET verify 成功/签名错误；POST approve/reject 正常流程（ApprovalService 可用路径 + 兜底路径，断言标签变更与 activity 行）；重复点击返回「已由他人处理」；非法 EventKey 忽略；无法映射用户 |
| `ArchiveServiceTest` | user share 移动；group share 逐成员；同名冲突追加 (n)；circle 跳过；requests 表无记录时 owner 兜底；目录已存在复用 |
| `TagAssignmentListenerTest` | 三类触发标签分发矩阵；pending 触发时总是写 requests 表（含企微关闭时）；非触发标签忽略；多 objectId |
| `ApprovalInfoProviderTest` | tag→rule 命中三列；group 展开；pending 时刻读申请人 |

另备一份手工联调清单（企微后台配回调 → 发卡片 → 点按钮 → 看归档），不自动化。

## 13. 项目骨架

```
approval_wecom/
├── appinfo/info.xml          # NC 33–35，settings 注册
├── appinfo/routes.php        # config GET/PUT + wecom/callback GET/POST
├── lib/
│   ├── AppInfo/Application.php          # 注册 TagAssignedEvent 监听、settings
│   ├── Controller/ConfigController.php
│   ├── Controller/WeComCallbackController.php
│   ├── Listener/TagAssignmentListener.php
│   ├── Migration/Version0001Date20260930000000.php
│   ├── BackgroundJob/CleanupRequestsJob.php
│   ├── Service/ApprovalInfoProvider.php
│   ├── Service/RequestRegistry.php          # approval_wecom_requests 表读写
│   ├── Service/WeComService.php
│   ├── Service/WXBizMsgCrypt.php
│   ├── Service/ArchiveService.php
│   └── Settings/Admin.php, AdminSection.php
├── src/adminSettings.js + components/AdminSettings.vue   # Vite 构建，仿 approval 的 vite 配置
├── templates/adminSettings.php
├── tests/unit/...
├── composer.json（php-dev-tools）/ package.json（@nextcloud/vite-config 等）
└── README.md                 # 含企微后台配置步骤截图说明
```

## 14. 已知限制

1. 企微回调审批在 approval 应用可用时走其 `ApprovalService`（NC 通知、activity 流完整）；approval 不可用时走兜底路径，只改标签 + 写 `approval_activity`，**不产生** NC 通知与 activity 流条目，结果通过企微通知申请人。
2. 触发标签不属于任何 approval 规则时：归档以 owner 兜底照常工作；企微卡片无法确定审批人，不发送（设置页保存时提示）。
3. 多人审批规则下任一审批人点击即生效（与 approval 自身语义一致：先到先得）。
4. 企微回调要求公网可达 HTTPS；纯内网部署无法使用回调（卡片跳转链接仍可用）。

## 15. 已确认的决策记录

| 决策点 | 结论 |
|---|---|
| 企微接入方式 | 自建应用（按钮回调，真·企微内审批） |
| 用户映射 | 邮箱双向匹配（`get_userid_by_email` / `user/get` + `IUserManager::getByEmail`） |
| 归档语义 | 移动审批人侧共享挂载点，申请人文件不动，共享保留 |
| 归档触发 | 仅审批通过；触发标签可在设置页多选（如 `sendapproved`） |
| 应用形态 | 独立应用 `approval_wecom`，基于上游最新 approval，不依赖 PR #449 |
| 归档目录 | 名称可配（默认 `approval`），子文件夹 不建/按月/按年 可配（默认按月） |
