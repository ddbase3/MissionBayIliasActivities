<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of MissionBayIliasActivities for BASE3 Framework.
 *
 * MissionBayIliasActivities exposes native ILIAS 11 Activities.
 * It maps the ILIAS repository contracts into MissionBay agent tools.
 * Native ILIAS identity and authorization remain authoritative.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0-or-later
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 ***********************************************************************/


namespace Base3\MissionBayIliasActivities\Resource;

use Base3\AssistantFoundation\Api\IAgentContext;
use Base3\CoreFoundation\Accesscontrol\Api\IAccesscontrol;
use Base3\CoreFoundation\Api\IOutputSchemaProvider;
use InvalidArgumentException;
use Base3\MissionBay\Api\IAgentTool;
use Base3\MissionBay\Api\IConfirmableAgentTool;
use Base3\MissionBay\Resource\AbstractAgentResource;
use Base3\MissionBayIliasActivities\Api\IIliasActivityService;
use Throwable;

final class IliasActivitiesAgentTool extends AbstractAgentResource implements IAgentTool, IConfirmableAgentTool, IOutputSchemaProvider {

	private const FN_SEARCH = 'search_ilias_activities';
	private const FN_DESCRIBE = 'describe_ilias_activity';
	private const FN_QUERY = 'execute_ilias_activity_query';
	private const FN_COMMAND = 'execute_ilias_activity_command';

	public function __construct(
		private readonly IIliasActivityService $activityService,
		private readonly IAccesscontrol $accesscontrol,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'iliasactivitiesagenttool';
	}

	public function getDescription(): string {
		return 'Discovers, describes and executes native ILIAS Activities. Complete multi-step requests autonomously instead of stopping after describing a plan. Treat Activities as capabilities, not domain records. Never ask the user for internal usr_id or ref_id values before attempting to resolve them with available Query Activities. Search the Activity catalogue by capability, describe the selected Activity, and compose additional Query Activities until the user request is answered. Preserve entities resolved earlier in the conversation. Treat empty filtered results as inconclusive unless you actually executed an exhaustive domain query. Before claiming that no users, courses, memberships or capabilities exist, verify with a broader or unfiltered applicable Query Activity. Mutations should resolve all inputs first and then use the single best matching Command Activity.';
	}

	public function getToolDefinitions(): array {
		return [
			$this->readDefinition(
				self::FN_SEARCH,
				'Search ILIAS Activities',
				'Search only the native ILIAS Activity capability catalogue by technical name, short class name or description. Results are ranked by matching capability terms; partial matches may be unrelated to the intended operation. This does NOT search ILIAS domain data. Never pass a person name, course title, ref_id or other entity value here. Search by the capability you need, for example "find user", "find repository object", "list course members" or "add course member". If no suitable Activity is found, retry with broader capability terms or list the relevant Activity type before telling the user that no capability exists. Do not ask the user whether you should broaden the search. After choosing an Activity, call describe_ilias_activity and then pass actual domain values only inside Activity execution parameters.',
				[
					'query' => [
						'type' => 'string',
						'description' => 'Optional capability search, for example "find user" or "list course members". This searches Activity metadata only. Do not put user names, course titles or other domain values here. Omit to list Activities.'
					],
					'type' => [
						'type' => 'string',
						'enum' => ['query', 'command'],
						'description' => 'Optional Activity type filter.'
					],
					'offset' => [
						'type' => 'integer',
						'minimum' => 0,
						'default' => 0
					],
					'limit' => [
						'type' => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 25
					]
				],
				[],
				100
			),
			$this->readDefinition(
				self::FN_DESCRIBE,
				'Describe ILIAS Activity',
				'Inspect one exact native ILIAS Activity selected from search_ilias_activities. Returns its type, description, machine-readable input schema and output schema. If the schema requires an internal identifier such as usr_id or ref_id that is not yet known, use other Query Activities to resolve it instead of asking the user for the identifier. Then execute the Activity with the resolved values.',
				$this->activityProperty(),
				['activity'],
				95
			),
			$this->readDefinition(
				self::FN_QUERY,
				'Execute ILIAS Query Activity',
				'Execute one exact native ILIAS Query Activity as the currently authenticated ILIAS user. This is the step that queries actual ILIAS domain data. Put values such as a person name, course title fragment, ref_id or usr_id into parameters exactly as declared by describe_ilias_activity. Reuse identifiers returned by previous Activities. If a required identifier is missing, resolve it with another Query Activity before asking the user. For inverse questions such as which courses a user participates in, resolve the user, obtain the full readable course set with the repository query using type=crs and no title filter when needed, inspect the member list of each candidate course, and answer only after that composition is complete. An empty result from a capability search or a filtered title search does not prove that no courses exist. Retry broader or unfiltered queries yourself before making a negative claim. Queries are treated as read-only in this conference prototype. Never pass a user identity for authorization.',
				$this->executionProperties(),
				['activity', 'parameters'],
				90
			),
			$this->commandDefinition()
		];
	}

	/**
	 * Describes the actual metadata and execution response envelopes, including
	 * native failures and tool exceptions. The generic execution contract describes
	 * only the canonical primitive representation of an Activity result; the exact
	 * business schema depends on the Activity and is enforced by the native output
	 * description in IIliasActivityService. Describe that Activity before using
	 * its result fields. No catalogue enumeration, actor lookup or execution occurs.
	 * All four functions remain available as ordinary calls.
	 *
	 * @return array<string,array<string,mixed>> Schemas keyed by the original function name.
	 */
	public function getOutputSchemas(): array {
		$string = ['type' => 'string'];
		$boolean = ['type' => 'boolean'];
		$nonNegativeInteger = ['type' => 'integer', 'minimum' => 0];
		$strings = ['type' => 'array', 'items' => $string];
		$identity = [
			'name' => $string,
			'short_name' => $string,
			'type' => $string,
			'description' => $string
		];
		$metadata = [
			'metadata_complete' => $boolean,
			'metadata_warnings' => $strings
		];
		// These values are schema documents, not the results of executing an Activity.
		// The mapper can emit extension keywords; missing metadata is represented by [].
		$schemaDocument = ['anyOf' => [
			['type' => 'object', 'additionalProperties' => true],
			['type' => 'array', 'maxItems' => 0]
		]];

		return [
			self::FN_SEARCH => ['oneOf' => [
				$this->objectSchema([
					'ok' => ['const' => true],
					'purpose' => ['const' => 'activity_capability_catalogue'],
					'query' => $string,
					'type' => ['type' => ['string', 'null'], 'enum' => ['query', 'command', null]],
					'offset' => $nonNegativeInteger,
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
					'total' => $nonNegativeInteger,
					'items' => [
						'type' => 'array',
						'maxItems' => 100,
						'items' => $this->objectSchema($identity + $metadata, ['metadata_warnings'])
					],
					'usage' => $string,
					'suggestion' => $string
				], ['suggestion']),
				$this->errorSchema(self::FN_SEARCH)
			]],
			self::FN_DESCRIBE => ['oneOf' => [
				$this->objectSchema(['ok' => ['const' => true]] + $identity + [
					'input_schema' => $schemaDocument,
					'input_schema_supported' => $boolean,
					'unsupported_input_types' => $strings,
					'output_schema' => $schemaDocument,
					'output_schema_supported' => $boolean,
					'unsupported_output_types' => $strings
				] + $metadata),
				$this->errorSchema(self::FN_DESCRIBE)
			]],
			self::FN_QUERY => $this->executionSchema(self::FN_QUERY, 'query'),
			self::FN_COMMAND => $this->executionSchema(self::FN_COMMAND, 'command')
		];
	}

	/**
	 * @param array<string,array<string,mixed>> $properties
	 * @param list<string> $optional
	 * @return array<string,mixed>
	 */
	private function objectSchema(array $properties, array $optional = []): array {
		return [
			'type' => 'object',
			'properties' => $properties,
			'required' => array_values(array_diff(array_keys($properties), $optional)),
			'additionalProperties' => false
		];
	}

	/**
	 * The envelope is fixed; result is the native Description's JSON value.
	 * Its Activity-specific contract is not replaced by this transport schema.
	 *
	 * @return array<string,mixed>
	 */
	private function executionSchema(string $name, string $type): array {
		$identity = [
			'activity' => ['type' => 'string', 'minLength' => 1],
			'type' => ['const' => $type]
		];
		return ['oneOf' => [
			$this->objectSchema(['ok' => ['const' => true]] + $identity + [
				'result' => [
					'type' => ['object', 'array', 'string', 'number', 'boolean', 'null'],
					'description' => 'Canonical JSON value of the selected native Activity after validation against its own ILIAS output description. Check the call status and output.ok before reading result. The exact result shape is the output_schema returned by describe_ilias_activity for that Activity when supported. Do not guess result fields when metadata is incomplete or assume all Activities return objects or arrays. Do not retry failed mutations blindly.'
				]
			]),
			$this->objectSchema(['ok' => ['const' => false]] + $identity + [
				'error' => $this->objectSchema([
					'code' => ['const' => 'activity_execution_failed'],
					'message' => ['type' => 'string']
				])
			]),
			$this->errorSchema($name)
		]];
	}

	/** @return array<string,mixed> */
	private function errorSchema(string $name): array {
		return $this->objectSchema([
			'ok' => ['const' => false],
			'operation' => ['const' => $name],
			'error' => $this->objectSchema([
				'code' => ['type' => 'string', 'enum' => ['invalid_argument', 'ilias_activity_failed']],
				'message' => ['type' => 'string'],
				'exception_type' => ['type' => 'string']
			])
		]);
	}

	public function getConfirmationRequest(string $name, array $arguments, IAgentContext $context): ?array {
		if ($name !== self::FN_COMMAND) {
			return null;
		}

		return $this->activityService->buildCommandReview(
			$this->activity($arguments),
			$this->actingUserId(),
			$this->parameters($arguments)
		);
	}

	public function callTool(string $name, array $arguments, IAgentContext $context): mixed {
		try {
			return match($name) {
				self::FN_SEARCH => $this->activityService->search(
					trim((string)($arguments['query'] ?? '')),
					$this->optionalType($arguments),
					$this->boundedInt($arguments['limit'] ?? 25, 25, 1, 100),
					$this->boundedInt($arguments['offset'] ?? 0, 0, 0, PHP_INT_MAX)
				),
				self::FN_DESCRIBE => $this->activityService->describe($this->activity($arguments)),
				self::FN_QUERY => $this->activityService->executeQuery(
					$this->activity($arguments),
					$this->actingUserId(),
					$this->parameters($arguments)
				),
				self::FN_COMMAND => $this->activityService->executeCommand(
					$this->activity($arguments),
					$this->actingUserId(),
					$this->parameters($arguments)
				),
				default => throw new InvalidArgumentException('Unsupported tool function: ' . $name)
			};
		} catch (Throwable $e) {
			return [
				'ok' => false,
				'operation' => $name,
				'error' => [
					'code' => $this->errorCode($e),
					'message' => $e->getMessage(),
					'exception_type' => get_class($e)
				]
			];
		}
	}

	/** @param array<string,mixed> $properties @param string[] $required */
	private function readDefinition(
		string $name,
		string $label,
		string $description,
		array $properties,
		array $required,
		int $priority
	): array {
		return [
			'type' => 'function',
			'label' => $label,
			'category' => 'ilias_activities',
			'tags' => ['ilias', 'activities', 'activity', 'readonly', 'capability'],
			'priority' => $priority,
			'readOnlyHint' => true,
			'mutation' => false,
			'requiresApproval' => false,
			'function' => [
				'name' => $name,
				'description' => $description,
				'parameters' => [
					'type' => 'object',
					'properties' => $properties,
					'required' => $required
				]
			]
		];
	}

	private function commandDefinition(): array {
		return [
			'type' => 'function',
			'label' => 'Execute ILIAS Command Activity',
			'category' => 'ilias_activities',
			'tags' => ['ilias', 'activities', 'activity', 'command', 'mutation'],
			'priority' => 90,
			'readOnlyHint' => false,
			'mutation' => true,
			'requiresApproval' => true,
			'commitGuardRequired' => false,
			'sideEffectHint' => true,
			'function' => [
				'name' => self::FN_COMMAND,
				'description' => 'Execute one exact native ILIAS Command Activity as the currently authenticated ILIAS user. Before concluding that a requested command is unavailable, search the command catalogue with broader capability terms such as course, member, add, enroll or assign and inspect plausible matches. Resolve required usr_id or ref_id values through Query Activities instead of asking the user when discovery Activities are available. In the MissionBay agent harness this function is marked as a mutation requiring explicit user approval. Generic commit guarding is intentionally not claimed because the ILIAS Activity contract does not yet expose a generic state snapshot/revalidation contract.',
				'parameters' => [
					'type' => 'object',
					'properties' => $this->executionProperties(),
					'required' => ['activity', 'parameters']
				]
			]
		];
	}

	/** @return array<string,mixed> */
	private function activityProperty(): array {
		return [
			'activity' => [
				'type' => 'string',
				'description' => 'Exact fully-qualified Activity name returned by the Activity search function.'
			]
		];
	}

	/** @return array<string,mixed> */
	private function executionProperties(): array {
		return array_merge($this->activityProperty(), [
			'parameters' => [
				'type' => 'object',
				'description' => 'Raw Activity parameters according to describe_ilias_activity.',
				'additionalProperties' => true
			]
		]);
	}

	/** @param array<string,mixed> $arguments */
	private function activity(array $arguments): string {
		$activity = trim((string)($arguments['activity'] ?? ''));
		if ($activity === '') throw new InvalidArgumentException('Missing parameter: activity');
		return $activity;
	}

	/** @param array<string,mixed> $arguments @return array<string,mixed> */
	private function parameters(array $arguments): array {
		$parameters = $arguments['parameters'] ?? null;
		if (!is_array($parameters)) {
			throw new InvalidArgumentException('Parameter parameters must be an object.');
		}
		return $parameters;
	}

	/** @param array<string,mixed> $arguments */
	private function optionalType(array $arguments): ?string {
		$type = strtolower(trim((string)($arguments['type'] ?? '')));
		return $type === '' ? null : $type;
	}

	private function actingUserId(): int {
		$userId = $this->accesscontrol->getUserId();
		if (!is_numeric($userId) || (int)$userId <= 0) {
			throw new InvalidArgumentException('No authenticated ILIAS user is available for Activity execution.');
		}
		return (int)$userId;
	}

	private function boundedInt(mixed $value, int $default, int $min, int $max): int {
		if (!is_numeric($value)) return $default;
		return max($min, min($max, (int)$value));
	}

	private function errorCode(Throwable $e): string {
		return $e instanceof InvalidArgumentException
			? 'invalid_argument'
			: 'ilias_activity_failed';
	}
}
