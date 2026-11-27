<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Resource;

use Base3\MissionBayIliasActivities\Resource\IliasActivitiesAgentTool;
use Base3\MissionBayIliasActivities\Test\Resource\Fixture\ActivityToolContractScenario;
use Base3\MissionBayIliasActivities\Test\Resource\Fixture\ActivityProgramExecutionScenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/ActivityServiceDouble.php';
require_once __DIR__ . '/Fixture/ActivityAccesscontrolDouble.php';
require_once __DIR__ . '/Fixture/ActivityConfigResolverDouble.php';
require_once __DIR__ . '/Fixture/ActivityEventManagerDouble.php';
require_once __DIR__ . '/Fixture/ActivityToolContractScenario.php';
require_once __DIR__ . '/Fixture/ActivityProgramExecutionScenario.php';

#[CoversClass(IliasActivitiesAgentTool::class)]
final class IliasActivitiesAgentToolTest extends TestCase {

	public function testMetadataAndExecutionProgramContractsAndOrdinaryCalls(): void {
		ActivityToolContractScenario::run();
		$this->addToAssertionCount(1);
	}

	public function testProgramCommandApprovalAndResume(): void {
		ActivityProgramExecutionScenario::run();
		$this->addToAssertionCount(1);
	}
}
