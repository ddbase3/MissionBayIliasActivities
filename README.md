# MissionBayIliasActivities

MissionBayIliasActivities exposes the native ILIAS 11 Activities catalogue to the MissionBay agent system.

The plugin is intentionally ILIAS 11+ only. It has no ILIAS 10 compatibility mode because the `ILIAS\Component\Activities` contract does not exist there.

## Architecture

The plugin consumes the exact native `ILIAS\Component\Activities\Repository` instance. Base3Ilias publishes that component-local repository into the BASE3 container at the ILIAS/BASE3 integration boundary.

No REST call, MCP call, duplicate Activity repository or second discovery registry is used.

The MissionBay resource `IliasActivitiesAgentTool` exposes four generic functions:

- `search_ilias_activities`
- `describe_ilias_activity`
- `execute_ilias_activity_query`
- `execute_ilias_activity_command`

The first three are read-only for the conference prototype. Commands are marked as mutations with `requiresApproval=true` for the MissionBay agent harness. Generic mutation commit guarding is deliberately not claimed because the current ILIAS Activity contract does not expose a generic snapshot/revalidation contract.

## Tool profiles and MCP

The tool is a normal discoverable MissionBay `IAgentTool`. Add it to a MissionBay component preset and tool profile like any other tool. MissionBay can then use the same profile internally in an agent harness and expose that profile through its existing MCP support.

Internal agent execution uses MissionBay action policies, so `execute_ilias_activity_command` enters the existing HITL approval flow. MCP exposure maps the tool safety hints into MCP annotations; a direct MCP `tools/call` is transport execution and does not run the internal MissionBay agent approval policy. For the conference MCP demonstration, prefer read-only Activity calls unless the MCP client provides the desired approval behavior.

This plugin does not create a second MCP endpoint or a second tool-profile mechanism.

## Acting user

Activity execution uses the current BASE3 `IAccesscontrol` user id and passes it to `Activity::maybePerformAs()`. The model cannot supply or override this user id.

An external MCP request therefore also needs a runtime in which Base3Ilias has established an ILIAS user identity. The plugin does not invent a technical-user fallback.

## Requirements

- ILIAS 11+
- Base3Ilias with the included Activities Repository bridge patch
- MissionBay
- AssistantFoundation
