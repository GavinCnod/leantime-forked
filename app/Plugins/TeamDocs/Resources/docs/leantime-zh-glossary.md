# Leantime 中文术语对照表

> 本团队 fork 使用中文界面(`app/Language/zh-CN.ini`),但部分界面元素、CostTracking 插件字段、以及官方英文文档仍用英文。本表统一团队沟通和文档里的用词。

> 配套:`docs/leantime-zh-guide.md`、`docs/cost-tracking-rules.md`。

> 最后更新: 2026年9月19日 15:00 版本号（v1.0）

## 导航与项目

| 英文(界面/文档) | 中文(团队统一用词) | 说明 |
|---|---|---|
| Project | 项目 | |
| All Projects | 所有项目 | 顶部菜单 |
| Project Dashboard | 项目概览 | |
| To-Dos | 任务 / 待办 | Leantime 核心单元,即一张任务卡 |
| Sub-task | 子任务 | 任务下的拆分 |
| Milestone | 里程碑 | |
| Collaborator / Team member | 协作人 / 成员 | 分配到任务上的人 |
| Client | 客户 | 项目可归属客户 |
| Timesheet | 时间表 / 工时 | |
| Idea | 想法 | |
| Research | 研究 | |
| Reports | 报表 | |
| My Portfolio | 我的组合 | |

## 任务(Ticket)字段

| 英文 | 中文 | 说明 |
|---|---|---|
| Task / Ticket | 任务 | |
| Title | 标题 | |
| Description | 描述 | |
| Status | 状态 | 如 待办 / 进行中 / 已完成 |
| Priority | 优先级 | |
| Collaborators | 协作人 | |
| Deadline | 截止日期 | |
| Sub-tasks | 子任务 | |
| Effort / Estimate | 预估工时 | |
| Attachments | 附件 | |
| Tags | 标签 | |
| Reaction / Emoji | 表情回应 | |

## CostTracking 插件(成本相关,重点)

| 英文键(`costtracking.*`) | 中文(界面实际显示) | 说明 |
|---|---|---|
| `cost_tab` | 成本 | 项目页上与"项目详情/成员"等并列的 tab(锚点 `#costtracking`) |
| `planned_cost` / `planned_cost_short` | 预算成本 / 预算 | 任务上"预计要花多少" |
| `actual_cost` / `actual_cost_short` | 实际成本 / 实际 | 任务上"实际花了多少",**记账就填这里** |
| `total` | 合计 | |
| `project_cost_summary` | 项目成本汇总 | |
| `budget` | 项目预算 | 在项目"项目详情"tab 里设置 |
| `remaining` | 预算剩余 | |
| `over_budget` | 超支金额 | |
| `usage` | 预算使用率(按实际成本) | |
| `ticket_count` | 含成本任务数 | 注意:分摊一笔费用会记到多个任务,此数会偏大,主指标以"预算 vs 实际"为准 |
| `scope_toplevel` | 顶层任务 | 成本 tab 的统计范围选项(默认) |
| `scope_subtasks` | 子任务 | 成本 tab 的统计范围选项 |
| `no_budget_hint` | (未设预算提示) | 项目详情未设预算时成本 tab 的提示语 |

> 记账时,经手人填的是任务上的 **"实际成本"(`actual_cost`)** 字段,并按 `docs/cost-tracking-rules.md` 的备注格式写说明。

## 角色与权限

| 英文 | 中文 | 说明 |
|---|---|---|
| Owner / Admin | 所有者 / 管理员 | 跨项目权限 |
| Manager | 经理 | |
| Team member | 成员 | |
| Project access | 项目访问权限 | 决定某人能否看某个项目 |

---

**用法**:写文档、群里沟通时,统一用"中文(团队统一用词)"列的词。遇到界面还是英文的(未翻译的插件字段、上游新功能),先查本表;查不到就按上面 CostTracking 表的"英文键 → 中文"对应关系理解。
