# MissionBayIliasActivities Privacy and Data Processing

## Scope and purpose

MissionBayIliasActivities exposes the native ILIAS 11 Activities catalogue and selected Activity executions to the existing MissionBay agent-tool execution system. It does not define an independent user directory, Activity repository, identity service, agent runtime, or permission store.

## Personal data and sources

The plugin obtains the currently authenticated ILIAS user identity through the existing BASE3 access-control contract and passes that user's identifier to native ILIAS Activity execution. Query or Command parameters and results may contain personal data or confidential course and repository information, depending on the Activity selected. Activity metadata itself can reveal capabilities and schema details.

The plugin does not introduce persistent storage for users, results, prompts, or Activity definitions. Native ILIAS Activities and the configured MissionBay execution system may process, store, or log data under their own policies. This repository alone does not establish their actual retention periods.

## Recipients and transfers

Execution involves the installed ILIAS application and the configured MissionBay agent tool/runtime. Data returned by an Activity can subsequently be presented to an agent and potentially a model provider according to the separate MissionBay configuration. No claim of EU-only processing or absence of third-country transfers is made here; the deployment's chosen services must be checked individually.

## Access and security boundaries

The acting user is derived from the established BASE3/ILIAS identity, not supplied as model input. The native Activity contract and native ILIAS authorization govern execution. MissionBay declares commands as mutations requiring approval in its agent harness. Direct MCP calls do not automatically inherit the internal agent approval workflow; read-only tools are preferable unless the calling client enforces the required approval.

## Retention, access and deletion

This plugin defines no independent database tables, persistent cache, or background retention job. Deletion, access requests, retention and audit of Activity-related personal data belong to the existing ILIAS and MissionBay owners, together with any configured model or telemetry providers. An end-to-end deletion claim requires assessment of those systems, including logs and backups.

## Deployment verification gaps

The repository alone does not prove compliance with GDPR, ePrivacy, or national privacy requirements. Before deployment, review the actual available ILIAS Activities, their authorization and data exposure, approval behavior for mutations, external model configuration, processor arrangements, logs, retention, deletion and cross-border transfers. Test permission withdrawal and direct tool invocation in the actual host environment.
