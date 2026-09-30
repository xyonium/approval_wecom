<template>
	<div class="section approval-wecom-settings">
		<h2>{{ t('approval_wecom', 'Approval 企业微信集成') }}</h2>

		<NcNoteCard v-if="!approvalEnabled" type="warning">
			{{ t('approval_wecom', 'Approval 应用未启用。请先安装并启用 Approval 应用，本应用的所有功能都依赖它的审批规则。') }}
		</NcNoteCard>

		<h3>{{ t('approval_wecom', '企业微信（自建应用）') }}</h3>
		<p class="settings-hint">
			{{ t('approval_wecom', '审批请求会以交互卡片推送给审批人，审批人可在企业微信内一键批准/拒绝。用户通过邮箱地址映射：Nextcloud 用户的邮箱需与企业微信成员的邮箱一致。') }}
		</p>
		<NcCheckboxRadioSwitch v-model="config.wecom_enabled" type="checkbox">
			{{ t('approval_wecom', '启用企业微信推送') }}
		</NcCheckboxRadioSwitch>
		<div class="grid">
			<NcTextField v-model="config.corpid"
				:label="t('approval_wecom', '企业 ID (CorpID)')"
				:disabled="!config.wecom_enabled" />
			<NcTextField v-model="corpsecret"
				type="password"
				:label="t('approval_wecom', '应用 Secret')"
				:placeholder="config.has_corpsecret ? t('approval_wecom', '已保存，留空保持不变') : ''"
				:disabled="!config.wecom_enabled" />
			<NcTextField v-model="config.agentid"
				:label="t('approval_wecom', '应用 AgentId')"
				:disabled="!config.wecom_enabled" />
			<NcTextField v-model="token"
				type="password"
				:label="t('approval_wecom', '回调 Token')"
				:placeholder="config.has_token ? t('approval_wecom', '已保存，留空保持不变') : ''"
				:disabled="!config.wecom_enabled" />
			<NcTextField v-model="encodingaeskey"
				type="password"
				:label="t('approval_wecom', '回调 EncodingAESKey')"
				:placeholder="config.has_encodingaeskey ? t('approval_wecom', '已保存，留空保持不变') : ''"
				:disabled="!config.wecom_enabled" />
		</div>
		<div class="callback-url">
			<label>{{ t('approval_wecom', '回调 URL（填入企业微信应用「接收消息」配置）') }}</label>
			<div>
				<input type="text" readonly :value="callbackUrl" @click="$event.target.select()">
				<NcButton @click="copyCallbackUrl">
					{{ copied ? t('approval_wecom', '已复制') : t('approval_wecom', '复制') }}
				</NcButton>
			</div>
		</div>

		<h3>{{ t('approval_wecom', '触发标签') }}</h3>
		<p class="settings-hint">
			{{ t('approval_wecom', '只有被选中标签触发的审批才会推送企业微信卡片 / 归档。可从 Approval 规则自动填充。') }}
		</p>
		<div class="grid">
			<div class="field">
				<label>{{ t('approval_wecom', '待审批标签（触发卡片推送）') }}</label>
				<NcSelect v-model="pendingTags" :options="tagOptions" :multiple="true" :close-on-select="false"
					:placeholder="t('approval_wecom', '选择标签')" />
			</div>
			<div class="field">
				<label>{{ t('approval_wecom', '已通过标签（触发归档与结果通知）') }}</label>
				<NcSelect v-model="approvedTags" :options="tagOptions" :multiple="true" :close-on-select="false"
					:placeholder="t('approval_wecom', '选择标签')" />
			</div>
			<div class="field">
				<label>{{ t('approval_wecom', '已拒绝标签（触发结果通知）') }}</label>
				<NcSelect v-model="rejectedTags" :options="tagOptions" :multiple="true" :close-on-select="false"
					:placeholder="t('approval_wecom', '选择标签')" />
			</div>
		</div>
		<NcButton :disabled="!approvalEnabled || loadingTags" @click="autoFillTags">
			{{ t('approval_wecom', '从 Approval 规则自动填充') }}
		</NcButton>

		<h3>{{ t('approval_wecom', '审批人侧共享归档') }}</h3>
		<p class="settings-hint">
			{{ t('approval_wecom', '审批通过后，把审批人收到的共享挂载点移动到其自己的归档文件夹中（申请人的原文件不受影响）。拒绝时不归档。') }}
		</p>
		<NcCheckboxRadioSwitch v-model="config.archive_enabled" type="checkbox">
			{{ t('approval_wecom', '审批通过后归档共享') }}
		</NcCheckboxRadioSwitch>
		<div class="grid">
			<NcTextField v-model="config.archive_folder"
				:label="t('approval_wecom', '归档文件夹名')"
				:disabled="!config.archive_enabled" />
			<div class="field">
				<label>{{ t('approval_wecom', '子文件夹') }}</label>
				<NcSelect v-model="subfolderOption" :options="subfolderOptions" :clearable="false"
					:disabled="!config.archive_enabled" />
			</div>
		</div>

		<div class="actions">
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ saving ? t('approval_wecom', '保存中…') : t('approval_wecom', '保存') }}
			</NcButton>
			<NcButton :disabled="!config.wecom_enabled || testing" @click="sendTest">
				{{ testing ? t('approval_wecom', '发送中…') : t('approval_wecom', '给我发送测试消息') }}
			</NcButton>
			<span v-if="statusMessage" :class="{ 'status-ok': statusOk, 'status-error': !statusOk }">
				{{ statusMessage }}
			</span>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

const SUBFOLDER_OPTIONS = [
	{ id: 'none', label: t('approval_wecom', '不建子文件夹') },
	{ id: 'month', label: t('approval_wecom', '按月（如 2026-09）') },
	{ id: 'year', label: t('approval_wecom', '按年（如 2026）') },
]

export default {
	name: 'AdminSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcTextField },
	data() {
		return {
			config: loadState('approval_wecom', 'config'),
			approvalEnabled: loadState('approval_wecom', 'approval-enabled'),
			callbackUrl: loadState('approval_wecom', 'callback-url'),
			corpsecret: '',
			token: '',
			encodingaeskey: '',
			tagOptions: [],
			rules: [],
			pendingTags: [],
			approvedTags: [],
			rejectedTags: [],
			subfolderOptions: SUBFOLDER_OPTIONS,
			subfolderOption: SUBFOLDER_OPTIONS[1],
			saving: false,
			testing: false,
			loadingTags: false,
			copied: false,
			statusMessage: '',
			statusOk: true,
		}
	},
	async mounted() {
		this.subfolderOption = SUBFOLDER_OPTIONS.find(o => o.id === this.config.archive_subfolder) ?? SUBFOLDER_OPTIONS[1]
		await this.loadTags()
	},
	methods: {
		t,
		async loadTags() {
			this.loadingTags = true
			try {
				const { data } = await axios.get(generateUrl('/apps/approval_wecom/approval-rule-tags'))
				this.tagOptions = data.tags.map(tag => ({ id: tag.id, label: tag.name }))
				this.rules = data.rules
				const byId = id => this.tagOptions.find(o => o.id === id)
				this.pendingTags = (this.config.trigger_pending_tag_ids ?? []).map(byId).filter(Boolean)
				this.approvedTags = (this.config.trigger_approved_tag_ids ?? []).map(byId).filter(Boolean)
				this.rejectedTags = (this.config.trigger_rejected_tag_ids ?? []).map(byId).filter(Boolean)
			} catch (e) {
				this.showStatus(t('approval_wecom', '加载标签失败'), false)
			} finally {
				this.loadingTags = false
			}
		},
		autoFillTags() {
			const pick = key => this.tagOptions.filter(o => this.rules.some(rule => rule[key] === o.id))
			this.pendingTags = pick('tagPending')
			this.approvedTags = pick('tagApproved')
			this.rejectedTags = pick('tagRejected')
		},
		async save() {
			this.saving = true
			try {
				const payload = {
					wecom_enabled: this.config.wecom_enabled,
					corpid: this.config.corpid ?? '',
					agentid: this.config.agentid ?? '',
					archive_enabled: this.config.archive_enabled,
					archive_folder: this.config.archive_folder ?? 'approval',
					archive_subfolder: this.subfolderOption.id,
					trigger_pending_tag_ids: this.pendingTags.map(o => o.id),
					trigger_approved_tag_ids: this.approvedTags.map(o => o.id),
					trigger_rejected_tag_ids: this.rejectedTags.map(o => o.id),
				}
				// secrets: only send when the admin typed a new value
				if (this.corpsecret !== '') payload.corpsecret = this.corpsecret
				if (this.token !== '') payload.token = this.token
				if (this.encodingaeskey !== '') payload.encodingaeskey = this.encodingaeskey

				const { data } = await axios.put(generateUrl('/apps/approval_wecom/config'), payload)
				this.config = data
				this.corpsecret = ''
				this.token = ''
				this.encodingaeskey = ''
				this.showStatus(t('approval_wecom', '已保存'), true)
			} catch (e) {
				this.showStatus(t('approval_wecom', '保存失败'), false)
			} finally {
				this.saving = false
			}
		},
		async sendTest() {
			this.testing = true
			try {
				const { data } = await axios.post(generateUrl('/apps/approval_wecom/test-message'))
				if (data.success) {
					this.showStatus(t('approval_wecom', '测试消息已发送'), true)
				} else {
					const reasons = {
						no_email: t('approval_wecom', '当前用户没有设置邮箱'),
						no_wecom_user: t('approval_wecom', '企业微信中找不到该邮箱对应的成员'),
						send_failed: t('approval_wecom', '发送失败，请检查企业微信配置'),
					}
					this.showStatus(reasons[data.error] ?? t('approval_wecom', '发送失败'), false)
				}
			} catch (e) {
				this.showStatus(t('approval_wecom', '发送失败，请检查企业微信配置'), false)
			} finally {
				this.testing = false
			}
		},
		async copyCallbackUrl() {
			try {
				await navigator.clipboard.writeText(this.callbackUrl)
				this.copied = true
				setTimeout(() => { this.copied = false }, 2000)
			} catch (e) {
				this.showStatus(t('approval_wecom', '复制失败'), false)
			}
		},
		showStatus(message, ok) {
			this.statusMessage = message
			this.statusOk = ok
			setTimeout(() => { this.statusMessage = '' }, 4000)
		},
	},
}
</script>

<style scoped>
.approval-wecom-settings h3 {
	margin-top: 24px;
	font-weight: bold;
}

.grid {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	max-width: 900px;
	margin: 8px 0;
}

.grid > * {
	flex: 1 1 260px;
	min-width: 260px;
}

.field label,
.callback-url label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.callback-url {
	margin: 8px 0;
	max-width: 700px;
}

.callback-url div {
	display: flex;
	gap: 8px;
}

.callback-url input {
	flex: 1;
}

.actions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 24px;
}

.status-ok {
	color: var(--color-success);
}

.status-error {
	color: var(--color-error);
}
</style>
