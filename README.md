# Approval WeCom integration（审批 × 企业微信集成）

Nextcloud [Approval](https://github.com/nextcloud/approval) 应用的配套应用，提供两个功能：

1. **企业微信审批卡片**：审批请求实时推送到审批人的企业微信（自建应用交互卡片），审批人可在企业微信内一键 **批准 / 拒绝**；审批结束后卡片自动替换为结果通知，申请人也会收到结果私信。
2. **审批人侧共享归档**：审批**通过**后，自动把审批人收到的共享挂载点移动到其自己的归档文件夹（默认 `approval/2026-09` 按月归档，可配置为按年或不建子文件夹）。**申请人的原文件不受影响**；拒绝时不归档。

## 工作原理

- 监听 Approval 应用的系统标签分配事件（`TagAssignedEvent`）：待审批标签触发卡片推送与请求登记；通过/拒绝标签触发归档、卡片更新与结果通知。
- 企业微信回调（卡片按钮点击）→ 验签解密 → 点击者按邮箱映射回 Nextcloud 用户 → 校验其为该规则审批人 → 调用 Approval 的 `ApprovalService` 执行审批（类不可用时降级为等价的最小实现）。
- 本应用**不修改 Approval 应用本身**，通过其公开的数据表（`approval_rules` / `approval_rule_approvers` / `approval_activity`）与公开事件集成；申请人在审批结束时从自建表 `aprv_wc_reqs` 读取（Approval 的 activity 行在结束时被删后重建，pending 行不复存在）。

## 安装

1. 安装并启用 Approval 应用（本应用依赖它的审批规则）。
2. 将本应用放入 `apps/` 或 `custom_apps/`，在应用管理页启用 **Approval WeCom integration**。
3. 构建前端资源（发布包已内置可跳过）：`npm ci && npm run build`。

要求：Nextcloud 32–35，PHP 8.3+。管理员与审批人的 Nextcloud 账号需设置邮箱，且与企业微信成员邮箱一致。

## 企业微信侧配置（自建应用）

1. [企业微信管理后台](https://work.weixin.qq.com/) → **应用管理** → 创建**自建应用**，记录：
   - **企业 ID**（我的企业 → 企业信息）
   - 应用的 **Secret** 与 **AgentId**
2. 应用详情 → **接收消息** → 设置 API 接收：
   - **URL**：Nextcloud 管理设置页「Approval 企业微信」中显示的回调 URL（形如 `https://你的域名/apps/approval_wecom/wecom/callback`，需公网可达 + HTTPS）
   - **Token / EncodingAESKey**：随机生成，填入 Nextcloud 设置页
   - 先在 Nextcloud 设置页保存 Token 和 EncodingAESKey，再在企业微信后台保存 URL（企业微信会立刻发起 GET 验证）。
3. 如企业微信启用了 **企业可信 IP**，把 Nextcloud 服务器出口 IP 加入白名单（否则 `message/send` 报 60020）。
4. 审批人在企业微信通讯录中的**邮箱**（或企业邮箱 biz_mail）需与其 Nextcloud 邮箱一致。

## Nextcloud 侧配置

管理设置 → **Approval 企业微信**：

| 配置项 | 说明 |
|---|---|
| 启用企业微信推送 | 总开关 |
| 企业 ID / 应用 Secret / AgentId | 自建应用凭据（Secret 加密存储，界面不回显） |
| 回调 Token / EncodingAESKey | 「接收消息」配置用（加密存储） |
| 触发标签 | 选择哪些 待审批/已通过/已拒绝 标签触发本应用；可一键「从 Approval 规则自动填充」 |
| 归档开关 / 文件夹名 / 子文件夹 | 归档目标：`<文件夹名>/[2026-09|2026]`，默认 `approval` + 按月 |

设置页底部可给当前管理员**发送测试消息**验证凭据与邮箱映射。

## 使用

1. 用户按 Approval 应用正常流程请求审批（文件打上待审批标签）。
2. 审批人收到企业微信卡片「审批请求：文件名」，点 **批准/拒绝** 即可；也可点卡片跳转 Nextcloud 文件页。
3. 审批结束后：所有审批人的卡片变为结果通知；申请人收到结果私信；通过时该文件在审批人处的共享被移入归档文件夹。

审批人在企业微信点击按钮的卡片会立即更新按钮文案（已批准/已拒绝/无权审批/无法识别用户/已失效或已处理）。结果通知依赖发送时保存的 `response_code`（企业微信限制 72 小时内有效，过期仅记日志）。

## 维护

- `aprv_wc_reqs` 表登记在途请求；审批结束时删除对应行；**每日定时任务**清理 30 天以上的残留行（如文件被直接删除导致的孤儿行）。
- 日志：应用内所有异常只记日志（`app=approval_wecom`），绝不阻断 Approval 主流程或企业微信回调响应。
- 界面文案为中文（源码内中文文案，未走翻译流程）。

## 开发

```bash
# 单元测试（需要 docker）
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit -c tests/phpunit.xml

# 前端
npm ci && npm run build
```

许可：AGPL-3.0-or-later。
