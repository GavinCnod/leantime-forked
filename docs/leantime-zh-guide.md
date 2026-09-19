# Leantime 中文操作指南(本团队 fork)

> 面向**团队成员**(新入职成员可直接照做)。fork 策略:沿 `dev-self-hosted` 分支走,不追上游。
> 配套:`docs/cost-tracking-rules.md`(记账规矩)、`docs/leantime-zh-glossary.md`(术语对照)。
> 界面已中文化,本文截图位置标了 `【截图:xxx】`,补齐后删掉占位。

## 1. 部署(管理员/维护者)

团队成员一般不用管这节 —— 你们用的系统已经部署好了。这节是给**维护系统的人**(你)看的。

- 生产部署:`deploy/docker-compose.prod.yml` + `deploy/Dockerfile.prod` + `deploy/entrypoint.sh`
- 反向代理 / TLS:`deploy/Caddyfile`
- 配置:`config/.env`(基准见 `config/.env.sample` 和 `deploy/.env.prod.example`)
  - 数据库连接、`LEAN_*` 系列变量
- 部署手册:`deploy/railway.md`(Railway)、`deploy/tencent-lighthouse.md`(腾讯云轻量)
- CostTracking 插件:已通过 `deploy/plugin_ensure.php` 随镜像内置,**不需要团队成员 `git apply` 打补丁**。若界面上没看到"成本"tab,先清 `storage/framework/views/*` 缓存(见第 8 节 FAQ)。

## 2. 首次登录与安装

- 浏览器打开 `【你们部署的域名,待填】`
- 首次进 `/install`,按向导建库、建第一个管理员账号
- 之后用邮箱/密码登录

【截图:登录页 + 安装向导】

## 3. 中文化设置

- 右上角头像 → 语言(Language)→ 选**中文**
- 界面术语和团队用词对照,见 `docs/leantime-zh-glossary.md`

## 4. 建项目与拆任务

1. **建项目**:顶部菜单"所有项目" → 新建项目,填名称、负责人、可选归属客户
2. **设预算**:进项目 → "项目详情"tab → 填**项目预算**(金额)。没设预算时,成本 tab 会提示"尚未设置金额预算"
3. **拆任务**:项目里建任务(To-Do / 待办),可拆子任务,给任务分配协作人、截止日期
4. **分配协作人**:任务上的"协作人"字段,可多人

【截图:新建项目 → 设预算 → 拆任务】

## 5. CostTracking 插件使用(面向团队成员,无需安装)

CostTracking 已随 fork 部署内置,**团队成员不需要安装、不需要打 patch**(那是维护者的活)。

你要做的只有:

1. **记成本**:在任务上填两个字段
   - **预算成本(planned cost)**:这件事预计要花多少(可选)
   - **实际成本(actual cost)**:实际花了多少 ← **记账就填这里**,备注格式见 `docs/cost-tracking-rules.md`
2. **看预算 vs 实际**:进项目页 → 点与"项目详情/成员"等并列的 **"成本"tab**,看预算使用率、预算剩余、超支金额、含成本任务数;可切"顶层任务/子任务"两个统计范围
3. **看板/列表上的成本**:任务卡片、任务表右侧有成本列(需要已打核心补丁,fork 已内置);简单列表里任务标题旁有成本角标

【截图:任务上两个成本字段 + 项目成本 tab】

## 6. 费用记录操作(对照规矩)

产生一笔费用后:

1. 确认你是这笔的**经手人**(下单/付款的人),不确定就群里 @ 相关人
2. 找到对应任务(客户沟通费→客户项目;样品费→下单项目;分摊/内部开销见规矩文档)
3. 打开任务 → 填"实际成本" → 备注写 `{月-日} {费用类型} 垫付 {发票号}`
4. 分摊:每个相关项目各记一笔,备注写"分摊自 {费用} {份额}"
5. 内部开销:进"内部开销"项目的固定"费用登记"任务上记

对照 `docs/cost-tracking-rules.md` 的 worked example。

## 7. 负责人每周核对

每周核对日(规矩文档里定死那天)下午:

1. 每个项目 → "成本"tab
2. 看三数字:预算使用率 / 预算剩余 / 超支金额
3. 切"子任务"范围看分摊细节
4. 异常(超支 / 归属存疑 / 大量补记)当场群里 @ 经手人改
5. 各经手人口头报本周发票号,听有没有记重(当前插件无法跨任务看备注,去重靠这一步)

## 8. 常见问题

- **界面上没"成本"tab / 成本列不显示**
  管理员清缓存:`rm -rf storage/framework/views/* && rm -f storage/framework/viewPaths.php`,再刷新。团队成员遇到直接报给维护者。
- **PostgreSQL 下成本数字不对**
  本 fork 已修 Postgres 兼容性(commit `358679109` / `0c4e8835b` 等)。若你用的不是 Postgres 可忽略;是 Postgres 且异常,查 `app/Core/Db/DatabaseHelper` 相关。
- **插件卸载会删表**
  `plugin:remove mindrose/costtracking` 会 drop `zp_ticket_costs`,**先备份数据**。禁用(`plugin:disable`)不删表。
- **成本字段填了但汇总没变**
  确认填的是"实际成本"(不是预算成本);统计范围选的是不是"顶层任务";子任务上的成本要在"子任务"范围才计入。
- **改 composer.json 元数据不生效**
  Leantime 只在安装时读一次 composer.json。部署新元数据后跑 `php bin/leantime plugin:costtracking:sync-metadata`。

---

## 待你补齐的内容(交付前)

- 【第 1 节】你们部署的域名、`.env` 关键项实际值(脱敏后)
- 【各节截图】按 `【截图:xxx】` 占位补真实界面截图
- 【第 7 节】核对日写死(和规矩文档保持一致)
