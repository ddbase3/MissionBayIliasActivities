<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Resource\Fixture;

use Base3\AssistantFoundation\Dto\AgentCapabilitySelectionConfig;
use Base3\AssistantFoundation\Dto\AgentInteractionResponse;
use Base3\AssistantFoundation\Dto\AgentResume;
use Base3\AssistantFoundation\Dto\AiChatResult;
use Base3\AssistantFoundation\Dto\AiResultMetadata;
use Base3\AssistantFoundation\Dto\AiToolCall;
use Base3\MissionBay\Capability\AgentCapabilityCatalogBuilder;
use Base3\MissionBay\Context\AgentContext;
use Base3\MissionBay\Dto\Orchestrator\AgentModelDecisionConfig;
use Base3\MissionBay\Orchestrator\AgentActionFingerprint;
use Base3\MissionBay\Orchestrator\AgentToolOrchestrator;
use Base3\MissionBay\Orchestrator\Policy\StaticAgentActionPolicyResolver;
use Base3\MissionBay\Orchestrator\Program\ToolProgramDefinitionService;
use Base3\MissionBay\Orchestrator\Service\AgentActionReviewService;
use Base3\MissionBay\Orchestrator\Service\AgentActionResumeService;
use Base3\MissionBay\Orchestrator\Service\AgentCapabilitySelectionGuardService;
use Base3\MissionBay\Orchestrator\Service\AgentToolContractValidationService;
use Base3\MissionBay\Orchestrator\Stage\AgentActionPolicyStage;
use Base3\MissionBay\Orchestrator\Stage\AgentModelDecisionStage;
use Base3\MissionBay\Orchestrator\Stage\AgentToolExecutionStage;
use Base3\MissionBay\Orchestrator\Stage\AgentToolObservationStage;
use Base3\MissionBay\Policy\AllowAllAgentActionPolicy;
use Base3\MissionBay\Policy\MutationApprovalAgentActionPolicy;
use Base3\MissionBay\Resource\AgentTool\Configured\ConfiguredAgentToolResource;
use Base3\MissionBay\Test\Orchestrator\AgentSelectedNativeQueueModel;
use Base3\MissionBay\Test\Orchestrator\ApprovalMemoryStateStore;
use Base3\MissionBay\Test\Policy\Fixture\PolicyTestAgentConfigurations;
use Base3\MissionBay\Test\Service\ComponentPresetTestSuspensionRepository;
use Base3\MissionBayIliasActivities\Resource\IliasActivitiesAgentTool;
use RuntimeException;

/** Exercises the existing MissionBay stages, not a separate program runner. */
final class ActivityProgramExecutionScenario {

	private const QUERY = 'ILIAS\\ActivityShowcase\\Activities\\FindUsers';
	private const COMMAND = 'ILIAS\\ActivityShowcase\\Activities\\AddCourseMember';
	private const SOURCE = 'let q = tools.ilias_activities__execute_ilias_activity_query({activity:"ILIAS\\\\ActivityShowcase\\\\Activities\\\\FindUsers",parameters:{query:"fixture-user"}}); if (q.status !== "success" || !q.output.ok) { return "query_failed"; } let id = q.output.result[0].usr_id; let c = tools.ilias_activities__execute_ilias_activity_command({activity:"ILIAS\\\\ActivityShowcase\\\\Activities\\\\AddCourseMember",parameters:{ref_id:12,usr_id:id}}); if (c.status !== "success") { return c.status; } if (!c.output.ok) { return c.output.error.code; } return c.output.result.status;';

	public static function run(): void {
		foreach ([
			[AgentInteractionResponse::DECISION_APPROVE, false, 'added'],
			[AgentInteractionResponse::DECISION_DENY, false, 'failure'],
			[AgentInteractionResponse::DECISION_APPROVE, true, 'activity_execution_failed']
		] as [$decision, $withdraw, $expected]) {
			$service = new ActivityServiceDouble();
			$service->queryResult = ['ok' => true, 'activity' => self::QUERY, 'type' => 'query', 'result' => [['usr_id' => 7]]];
			$service->commandResult = ['ok' => true, 'activity' => self::COMMAND, 'type' => 'command', 'result' => ['status' => 'added']];
			$access = new ActivityAccesscontrolDouble();
			$events = new ActivityEventManagerDouble();
			$context = new AgentContext();
			$wrapper = new ConfiguredAgentToolResource(new ActivityConfigResolverDouble(), $events, 'activity-program-test');
			$wrapper->setConfig(['namespace' => 'ilias_activities']);
			$wrapper->init(['tool' => [new IliasActivitiesAgentTool($service, $access)]], $context);
			$fingerprint = new AgentActionFingerprint();
			$repository = new ComponentPresetTestSuspensionRepository();
			$review = new AgentActionReviewService($fingerprint, $repository, 900, eventManager: $events);
			$resume = new AgentActionResumeService($fingerprint, $repository, $events);
			$policies = [new MutationApprovalAgentActionPolicy(new PolicyTestAgentConfigurations(), $fingerprint, new ApprovalMemoryStateStore()), new AllowAllAgentActionPolicy()];
			$orchestrator = new AgentToolOrchestrator(null, null, [
				new AgentModelDecisionStage(),
				new AgentActionPolicyStage(new StaticAgentActionPolicyResolver($policies), $fingerprint, $review, new AgentToolContractValidationService(), new AgentCapabilitySelectionGuardService(), policyIds: ['mutation-approval-actions', 'allow-all-actions']),
				new AgentToolExecutionStage($events, $review),
				new AgentToolObservationStage()
			], $resume);
			$definitions = $wrapper->getToolDefinitions();
			$names = array_column(array_column($definitions, 'function'), 'name');
			$runTurn = static function(AgentSelectedNativeQueueModel $model, $prepared = null) use ($orchestrator, $definitions, $wrapper, $names) {
				return $orchestrator->run($model, [['role' => 'user', 'content' => 'Resolve the fixture user and add the user to the fixture course.']], $definitions, [$wrapper], new AgentContext(), maxLoops: 8, resume: $prepared, capabilityCatalog: (new AgentCapabilityCatalogBuilder())->build([$wrapper], $definitions), capabilitySelectionConfig: new AgentCapabilitySelectionConfig(maxTools: 16), requiredToolNames: $names, modelDecisionConfig: AgentModelDecisionConfig::nativeCapability(true));
			};
			$model = new AgentSelectedNativeQueueModel([new AiChatResult('', [new AiToolCall('activity-program', ToolProgramDefinitionService::TOOL_NAME, ['program' => self::SOURCE])], new AiResultMetadata('model_decision', 'test', 'activity-program'))]);
			$run = $runTurn($model);
			self::check($run->isSuspended() && count($run->getInteractionRequests()) === 1, 'Activity command did not suspend: ' . $run->getFailureCode() . ' ' . $run->getFailureMessage());
			self::check(self::calls($service, 'query') === [['query', self::QUERY, 42, ['query' => 'fixture-user']]], 'Query did not use the authenticated actor.');
			self::check(self::calls($service, 'command') === [], 'Command executed before approval.');
			self::check(self::calls($service, 'review') === [['review', self::COMMAND, 42, ['ref_id' => 12, 'usr_id' => 7]]], 'Existing native command review was bypassed.');
			if ($withdraw) {
				$service->commandResult = ['ok' => false, 'activity' => self::COMMAND, 'type' => 'command', 'error' => ['code' => 'activity_execution_failed', 'message' => 'Native permission withdrawn']];
			}
			$prepared = $resume->prepare(new AgentResume($run->getResumeHandle(), [new AgentInteractionResponse($run->getInteractionRequests()[0]->getId(), $decision)]));
			$model = new AgentSelectedNativeQueueModel([new AiChatResult('Completed.', [], new AiResultMetadata('model_decision', 'test', 'terminal'))]);
			$run = $runTurn($model, $prepared);
			self::check($run->isCompleted(), 'Activity program did not resume: ' . $run->getFailureCode() . ' ' . $run->getFailureMessage());
			self::check(count(self::calls($service, 'query')) === 1, 'Earlier native Query was replayed.');
			$commands = $decision === AgentInteractionResponse::DECISION_APPROVE ? [['command', self::COMMAND, 42, ['ref_id' => 12, 'usr_id' => 7]]] : [];
			self::check(self::calls($service, 'command') === $commands, 'Approval or native actor delegation changed.');
			$results = array_values(array_filter($run->getToolResults(), static fn($result): bool => $result->getCallId() === 'activity-program'));
			self::check(count($results) === 1 && $results[0]->getOutput()['result'] === $expected, 'Unexpected Activity program result after resume.');
		}
	}

	private static function calls(ActivityServiceDouble $service, string $method): array {
		return array_values(array_filter($service->calls, static fn(array $call): bool => $call[0] === $method));
	}

	private static function check(bool $condition, string $message): void {
		if (!$condition) throw new RuntimeException($message);
	}
}
