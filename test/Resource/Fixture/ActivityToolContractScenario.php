<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Resource\Fixture;

use Base3\AssistantFoundation\Dto\AgentToolResult;
use Base3\AssistantFoundation\Dto\AiToolCall;
use Base3\MissionBay\Context\AgentContext;
use Base3\MissionBay\Orchestrator\Program\ToolProgramDefinitionService;
use Base3\MissionBay\Orchestrator\Program\ToolProgramInterpreter;
use Base3\MissionBay\Orchestrator\Program\ToolProgramParser;
use Base3\MissionBay\Orchestrator\Program\ToolProgramState;
use Base3\MissionBay\Orchestrator\Service\AgentToolContractValidationService;
use Base3\MissionBay\Resource\AgentTool\Configured\ConfiguredAgentToolResource;
use Base3\MissionBayIliasActivities\Resource\IliasActivitiesAgentTool;
use RuntimeException;

final class ActivityToolContractScenario {

	private const SEARCH = 'search_ilias_activities';
	private const DESCRIBE = 'describe_ilias_activity';
	private const QUERY = 'execute_ilias_activity_query';
	private const COMMAND = 'execute_ilias_activity_command';
	private const PREFIX = 'ilias_activities__';
	private const ADD = 'ILIAS\\ActivityShowcase\\Activities\\AddCourseMember';
	private const REMOVE = 'ILIAS\\ActivityShowcase\\Activities\\RemoveCourseMember';

	public static function run(): void {
		$service = new ActivityServiceDouble();
		$access = new ActivityAccesscontrolDouble();
		$tool = new IliasActivitiesAgentTool($service, $access);
		$context = new AgentContext();
		$validator = new AgentToolContractValidationService();
		$programs = new ToolProgramDefinitionService();
		$definitions = $tool->getToolDefinitions();
		$names = [self::SEARCH, self::DESCRIBE, self::QUERY, self::COMMAND];
		self::check(array_column(array_column($definitions, 'function'), 'name') === $names, 'Existing function names changed.');
		self::check(array_keys($tool->getOutputSchemas()) === $names, 'Missing execution envelope contracts.');
		self::check(array_keys($programs->getContracts($definitions, [$tool])) === $names, 'One or more functions are not program-callable.');
		self::check($service->calls === [] && $access->reads === 0, 'Contract discovery accessed the host.');

		$wrapper = new ConfiguredAgentToolResource(new ActivityConfigResolverDouble(), new ActivityEventManagerDouble(), 'activity-contract-test');
		$wrapper->setConfig(['namespace' => 'ilias_activities']);
		$wrapper->init(['tool' => [$tool]], $context);
		$active = $programs->getContracts($wrapper->getToolDefinitions(), [$wrapper]);
		self::check(array_keys($active) === array_map(static fn(string $name): string => self::PREFIX . $name, $names), 'Configured wrapper did not preserve program contracts.');
		foreach ($names as $name) {
			self::check($wrapper->getOutputSchemas()[self::PREFIX . $name] === $tool->getOutputSchemas()[$name], 'Configured schema changed: ' . $name);
		}

		// The fixture follows the current service response, including incomplete metadata and pagination.
		$add = ['name' => self::ADD, 'short_name' => 'AddCourseMember', 'type' => 'command', 'description' => 'Adds a course member.', 'metadata_complete' => true];
		$remove = ['name' => self::REMOVE, 'short_name' => 'RemoveCourseMember', 'type' => 'command', 'description' => '', 'metadata_complete' => false, 'metadata_warnings' => ['Missing description']];
		$page = ['ok' => true, 'purpose' => 'activity_capability_catalogue', 'query' => '', 'type' => 'command', 'offset' => 0, 'limit' => 25, 'total' => 2, 'items' => [$add, $remove], 'usage' => 'Describe an Activity before execution.'];
		$description = ['ok' => true] + $add + [
			'input_schema' => ['type' => 'object', 'properties' => ['ref_id' => ['type' => 'number'], 'usr_id' => ['type' => 'number']], 'required' => ['ref_id', 'usr_id'], 'additionalProperties' => false],
			'input_schema_supported' => true,
			'unsupported_input_types' => [],
			'output_schema' => ['type' => 'object', 'properties' => ['added' => ['type' => 'boolean']], 'required' => ['added']],
			'output_schema_supported' => true,
			'unsupported_output_types' => [],
			'metadata_warnings' => []
		];
		$incomplete = array_replace($description, $remove, ['input_schema' => [], 'input_schema_supported' => false, 'output_schema' => ['x-ilias-description-class' => 'ExampleUnsupportedDescription'], 'output_schema_supported' => false, 'unsupported_output_types' => ['ExampleUnsupportedDescription']]);
		$service->searchResult = $page;
		$service->descriptions = [self::ADD => $description, self::REMOVE => $incomplete];

		$call = new AiToolCall('search', self::PREFIX . self::SEARCH, ['type' => 'command']);
		$result = $wrapper->callTool($call->getName(), $call->getArguments(), $context);
		self::check($result === $page && $validator->validateOutput($call, $result, [$wrapper])->passes(), 'Search output was changed or rejected.');
		self::check($service->calls === [['search', '', 'command', 25, 0]], 'Search delegation changed.');
		foreach ([
			array_replace($page, ['type' => null, 'total' => 0, 'items' => []]),
			array_replace($page, ['query' => 'unknown', 'total' => 0, 'items' => [], 'suggestion' => 'Broaden search.']),
			array_replace($page, ['offset' => 2, 'items' => []])
		] as $response) {
			$service->searchResult = $response;
			$result = $wrapper->callTool($call->getName(), $call->getArguments(), $context);
			self::check($result === $response && $validator->validateOutput($call, $result, [$wrapper])->passes(), 'Valid empty or paginated search rejected.');
		}
		foreach ([$description, $incomplete] as $response) {
			$describe = new AiToolCall('describe', self::PREFIX . self::DESCRIBE, ['activity' => $response['name']]);
			$result = $wrapper->callTool($describe->getName(), $describe->getArguments(), $context);
			self::check($result === $response && $validator->validateOutput($describe, $result, [$wrapper])->passes(), 'Complete or incomplete description rejected.');
		}
		$describe = new AiToolCall('describe-error', self::PREFIX . self::DESCRIBE, ['activity' => 'unknown']);
		$result = $wrapper->callTool($describe->getName(), $describe->getArguments(), $context);
		self::check($result['ok'] === false && $result['error']['code'] === 'invalid_argument' && $validator->validateOutput($describe, $result, [$wrapper])->passes(), 'Existing invalid argument envelope rejected.');
		$service->searchResult = new RuntimeException('Catalogue unavailable');
		$result = $wrapper->callTool($call->getName(), $call->getArguments(), $context);
		self::check($result['ok'] === false && $result['error']['code'] === 'ilias_activity_failed' && $validator->validateOutput($call, $result, [$wrapper])->passes(), 'Existing runtime error envelope rejected.');

		foreach ([
			array_replace($page, ['total' => '2']),
			array_replace($page, ['limit' => 101]),
			array_replace($page, ['items' => [['name' => self::ADD]]]),
			array_replace($page, ['items' => [array_replace($add, ['metadata_complete' => 'yes'])]]),
			array_replace($page, ['ok' => false]),
			array_replace($result, ['operation' => self::DESCRIBE])
		] as $invalid) {
			self::check(!$validator->validateOutput($call, $invalid, [$wrapper])->passes(), 'Malformed search output passed validation.');
		}
		foreach ([
			array_replace($description, ['output_schema' => 'not a schema document']),
			array_replace($description, ['input_schema' => [1, 2]]),
			array_replace($description, ['unsupported_output_types' => [123]]),
			array_diff_key($description, ['input_schema_supported' => true])
		] as $invalid) {
			self::check(!$validator->validateOutput($describe, $invalid, [$wrapper])->passes(), 'Malformed describe output passed validation.');
		}

		$service->searchResult = $page;
		$service->calls = [];
		$source = 'let found = tools.ilias_activities__search_ilias_activities({type:"command"}); if (found.status !== "success") { return -1; } if (!found.output.ok) { return -2; } let names = []; for (let item of found.output.items) { let detail = tools.ilias_activities__describe_ilias_activity({activity:item.name}); if (detail.status === "success" && detail.output.ok) { names.push(detail.output.name); } } return names;';
		self::check(self::executeProgram($source, $wrapper, $context) === [self::ADD, self::REMOVE], 'Program could not compose Activity search and descriptions.');
		self::check($service->calls === [['search', '', 'command', 25, 0], ['describe', self::ADD], ['describe', self::REMOVE]] && $access->reads === 0, 'Metadata program invoked domain execution or identity lookup.');
		$service->searchResult = new RuntimeException('Catalogue unavailable');
		self::check(self::executeProgram($source, $wrapper, $context) === -2, 'Program failed to handle the existing domain-level error envelope.');

		// Ordinary calls, actor delegation and review semantics are unchanged. This
		// verifies the plugin boundary, not authorization inside native ILIAS.
		$service->queryResult = ['ok' => true, 'activity' => 'ExampleQuery', 'type' => 'query', 'result' => [['usr_id' => 7]]];
		$service->commandResult = ['ok' => true, 'activity' => self::ADD, 'type' => 'command', 'result' => ['added' => true]];
		$service->calls = [];
		$parameters = ['ref_id' => 12, 'usr_id' => 7];
		$query = new AiToolCall('query', self::PREFIX . self::QUERY, ['activity' => 'ExampleQuery', 'parameters' => $parameters]);
		$command = new AiToolCall('command', self::PREFIX . self::COMMAND, ['activity' => self::ADD, 'parameters' => $parameters]);
		self::check($definitions[3]['mutation'] === true && $definitions[3]['requiresApproval'] === true && $definitions[3]['commitGuardRequired'] === false, 'Existing command policy annotations changed.');
		self::check($wrapper->getConfirmationRequest($query->getName(), $query->getArguments(), $context) === null, 'Query unexpectedly requests confirmation.');
		self::check($wrapper->getConfirmationRequest($command->getName(), $command->getArguments(), $context) === $service->review, 'Command review delegation changed.');
		self::check($service->calls === [['review', self::ADD, 42, $parameters]], 'Review executed a domain operation.');
		foreach ([[$query, $service->queryResult], [$command, $service->commandResult]] as [$request, $expected]) {
			self::check($validator->getProgramContract($request->getName(), [$wrapper]) !== null, 'Execution envelope is not program-callable.');
			$result = $wrapper->callTool($request->getName(), $request->getArguments(), $context);
			$validation = $validator->validateOutput($request, $result, [$wrapper]);
			self::check($result === $expected && $validation->passes() && $validation->isDeclared(), 'Ordinary call or execution envelope changed.');
		}
		self::check($service->calls === [['review', self::ADD, 42, $parameters], ['query', 'ExampleQuery', 42, $parameters], ['command', self::ADD, 42, $parameters]], 'Native actor or arguments changed.');
		$access->userId = null;
		$result = $wrapper->callTool($command->getName(), $command->getArguments(), $context);
		self::check($result['ok'] === false && count($service->calls) === 3 && $validator->validateOutput($command, $result, [$wrapper])->passes(), 'Unauthenticated command reached the Activity service or produced an invalid error.');

		$access->userId = '42';
		foreach ([[$query, 'query', 'ExampleQuery', 'queryResult'], [$command, 'command', self::ADD, 'commandResult']] as [$request, $type, $activity, $property]) {
			$success = ['ok' => true, 'activity' => $activity, 'type' => $type];
			$failure = ['ok' => false, 'activity' => $activity, 'type' => $type, 'error' => ['code' => 'activity_execution_failed', 'message' => 'Native permission denied']];
			foreach ([[], [1, 2], (object)[], (object)['id' => 7], ['usr_id' => 7], 'text', 0, 1.25, false, null, [['nested' => ['field' => true]]]] as $value) {
				$expected = $success + ['result' => $value];
				$service->{$property} = $expected;
				$actual = $wrapper->callTool($request->getName(), $request->getArguments(), $context);
				self::check($actual === $expected && $validator->validateOutput($request, $actual, [$wrapper])->passes(), 'A canonical result type was rejected or changed.');
			}
			$service->{$property} = $failure;
			$actual = $wrapper->callTool($request->getName(), $request->getArguments(), $context);
			self::check($actual === $failure && $validator->validateOutput($request, $actual, [$wrapper])->passes(), 'Native failure envelope was rejected.');
			$service->{$property} = new RuntimeException('Native Activity failed');
			$actual = $wrapper->callTool($request->getName(), $request->getArguments(), $context);
			self::check($actual['ok'] === false && $actual['error']['code'] === 'ilias_activity_failed' && $validator->validateOutput($request, $actual, [$wrapper])->passes(), 'Existing tool exception envelope was rejected.');
			foreach ([
				$success,
				$success + ['result' => INF],
				array_replace($success + ['result' => null], ['type' => $type === 'query' ? 'command' : 'query']),
				array_replace($success + ['result' => null], ['activity' => '']),
				$success + ['result' => [], 'error' => []],
				$failure + ['result' => null],
				array_replace($failure, ['error' => ['code' => 'other', 'message' => 'failure']]),
				array_replace($actual, ['operation' => self::DESCRIBE])
			] as $invalid) {
				self::check(!$validator->validateOutput($request, $invalid, [$wrapper])->passes(), 'Malformed execution envelope passed validation.');
			}
		}

		// A real program can consume the domain result of the same generic query.
		// Commands are exercised through the actual policy pipeline separately.
		$service->queryResult = ['ok' => true, 'activity' => 'ExampleQuery', 'type' => 'query', 'result' => [['usr_id' => 7], ['usr_id' => 8]]];
		$source = 'let q = tools.ilias_activities__execute_ilias_activity_query({activity:"ExampleQuery",parameters:{ref_id:12}}); if (q.status !== "success" || !q.output.ok) { return -1; } let ids = []; for (let row of q.output.result) { ids.push(row.usr_id); } return ids;';
		self::check(self::executeProgram($source, $wrapper, $context) === [7, 8], 'Program could not process a native Query result.');
		$service->queryResult = ['ok' => false, 'activity' => 'ExampleQuery', 'type' => 'query', 'error' => ['code' => 'activity_execution_failed', 'message' => 'Native permission denied']];
		self::check(self::executeProgram($source, $wrapper, $context) === -1, 'Program could not handle a native Query failure.');
	}

	private static function executeProgram(string $source, ConfiguredAgentToolResource $wrapper, AgentContext $context): mixed {
		$program = (new ToolProgramParser())->parse($source);
		$vm = new ToolProgramInterpreter();
		$state = new ToolProgramState();
		$validator = new AgentToolContractValidationService();
		foreach ($program->toolNames as $name) {
			self::check($validator->getProgramContract($name, [$wrapper]) !== null, 'Program contract missing.');
		}
		while (true) {
			$next = $vm->advance($program, $state, 'activity-contract-test', static fn(): bool => false);
			if (array_key_exists('result', $next)) return $next['result'];
			$call = $next['call'];
			self::check($validator->validateInput($call, [$wrapper])->passes(), 'Program arguments rejected.');
			$output = $wrapper->callTool($call->getName(), $call->getArguments(), $context);
			self::check($validator->validateOutput($call, $output, [$wrapper])->passes(), 'Program output rejected.');
			$vm->acceptResult($state, AgentToolResult::success($call->getId(), $call->getName(), $call->getArguments(), $output));
		}
	}

	private static function check(bool $condition, string $message): void {
		if (!$condition) throw new RuntimeException($message);
	}
}
