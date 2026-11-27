
## Namespace migration on ILIAS 11

This module is installed at `components/Dev/MissionBayIliasActivities/` under the ILIAS project root. Its PHP namespace is `Base3\MissionBayIliasActivities`, and the `base3.json` dependencies name `CoreFoundation`, `Base3Ilias`, `AssistantFoundation`, and `MissionBay`. Native `ILIAS\...` namespaces remain owned by ILIAS. See [privacy and data processing](PRIVACY.md).

## Tool contracts and programmable exploration

The existing tool resource implements `Base3\CoreFoundation\Api\IOutputSchemaProvider`. It declares the real return contracts of all four existing functions, including their `ok: false` responses. All four functions can be used in the MissionBay Program Tool Loop when selected, and remain available as ordinary native Function Calls. Their original names, arguments, response values and configured name prefixes are unchanged.

Search results contain paginated Activity metadata. Nonempty searches rank exact technical or short names first, then the number of distinct matching capability terms, then match quality (exact token matches, short-name matches and phrase matches are preferred). The existing normalization of plurals, word prefixes and capability synonyms is retained. Common English connecting words are ignored. A longer query may match only part of an Activity description; an unmatched term no longer discards an otherwise relevant Activity. A result with no matching term is not included, and a nonempty query containing only ignored words or punctuation returns no results. Empty searches retain technical-name order. Type filtering precedes ranking, ties use technical-name order, and pagination is applied after ranking. No ranking scores are exposed in the response. A partial match is a discovery hint, not an executable contract or permission decision: select by actual capability, then describe the exact Activity. Describe results include the native mapped input/output schema documents, their support flags, unsupported types and metadata warnings. The schema documents may contain native extension keywords or be empty when metadata is unavailable. A program must check both the MissionBay call status and `output.ok` before accessing success-only fields, and inspect the support flags before relying on an Activity schema. Metadata discovery is not proof of domain authorization.

### Programmable Activity execution

`execute_ilias_activity_query` and `execute_ilias_activity_command` use two distinct contract levels:

* The existing execution envelope has a fixed, closed schema. Success requires `ok: true`, the exact Activity identity, its type (`query` or `command`), and `result`. Native failures require `ok: false`, the Activity identity, type and `error.code: activity_execution_failed` with a message. Exceptions caught by the tool retain the existing `ok: false`, `operation`, `error` envelope. These three variants are mutually exclusive.
* The business result is the canonical primitive representation of the selected native Activity. Its JSON value can be an object, array, string, number, boolean or null. The native output description remains authoritative: the service checks `matches()`, converts through `getPrimitiveRepresentation()` and rejects noncanonical or non-JSON-serializable results. The generic envelope does not claim to describe the fields of every Activity. Use `describe_ilias_activity` for the actual mapped business schema before consuming fields. The native `maybePerformAs()` call continues to handle inputs, permissions and execution. No separate catalogue-wide schema, cache or execution path is introduced.

The envelope is static and does not enumerate native Activities during contract discovery. This keeps the selected tool contract small even when the native catalogue grows. The current MissionBay contract mechanism is function-wide; it does not perform argument-dependent schema selection. Native validation is therefore performed by the existing service, not by a second schema resolver in the orchestrator. Incomplete metadata remains discoverable, but does not establish a usable business contract. A program must not guess result fields; ordinary calls remain available when appropriate. A native Activity with missing or invalid output description cannot be declared successful merely because the envelope is supported.

Commands retain their original mutation annotations, server-owned confirmation review and existing approval pipeline. Approval is not native authorization. A failed result does not imply that a mutation was rolled back. Do not retry blindly. A program can resume after an approved or denied command using the existing MissionBay suspension; no new state or permission system is required.

Example using the existing configured prefix `ilias_activities`:

```javascript
let found = tools.ilias_activities__search_ilias_activities({type: "command", limit: 25});
if (found.status !== "success") { return {error: found.error_message}; }
if (!found.output.ok) { return {error: found.output.error.message}; }
let rows = [];
for (let item of found.output.items) {
	let detail = tools.ilias_activities__describe_ilias_activity({activity: item.name});
	if (detail.status === "success" && detail.output.ok) {
		rows.push({name: item.name, input: detail.output.input_schema, output: detail.output.output_schema, supported: detail.output.output_schema_supported});
	}
}
return {total: found.output.total, described: rows};
```

This example explores one page without executing domain Queries or Commands. For larger catalogues, use the returned total and offset to request additional pages within the existing program limits.

Example using the native Showcase Query after its description has been discovered:

```javascript
let activity = "ILIAS\\ActivityShowcase\\Activities\\FindRepositoryObjects";
let detail = tools.ilias_activities__describe_ilias_activity({activity: activity});
if (detail.status !== "success" || !detail.output.ok) { return {error: "Description failed"}; }
if (!detail.output.input_schema_supported || !detail.output.output_schema_supported) { return {error: "Unsupported Activity metadata"}; }
let courses = tools.ilias_activities__execute_ilias_activity_query({activity: activity, parameters: {type: "crs", limit: 20}});
if (courses.status !== "success" || !courses.output.ok) { return {error: "Query failed"}; }
let rows = [];
for (let course of courses.output.result) {
	rows.push({ref_id: course.ref_id, title: course.title});
}
return {count: rows.length, courses: rows};
```

Use the same call pattern for a native Command, after resolving its required parameters from authorized Queries. Its selected function is still `ilias_activities__execute_ilias_activity_command`; MissionBay must process the existing approval before execution.

## Tests

`test/Resource/IliasActivitiesAgentToolTest.php` exercises the actual tool resource, configured wrapper, contract validation and program interpreter using an isolated Activity service double. It covers successful and empty searches, incomplete metadata, exception responses, schema rejection, all execution result types, program composition and unchanged ordinary Query/Command dispatch. The same test also runs `ActivityProgramExecutionScenario` through the actual MissionBay policy, execution, observation and resume stages using the existing MissionBay test fixtures (available in the MissionBay source checkout). It verifies approval, denial and a simulated native permission withdrawal after approval without replaying the earlier Query. `test/Ilias/IliasActivityServiceTest.php` exercises ranked metadata search using the nine-Activity live regression catalogue and the original multi-term queries, as well as native output conversion with the installed ILIAS Data API and mocked Activity, repository and UI factory. It requires the ILIAS 11 host classes on the test classpath. Real native authorization, persistent suspension and HITL in a live ILIAS installation require separate host integration tests.
