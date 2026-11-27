<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Ilias;

use Base3\MissionBayIliasActivities\Ilias\ActivitySchemaMapper;
use Base3\MissionBayIliasActivities\Ilias\IliasActivityService;
use Base3\MissionBayIliasActivities\Test\Ilias\Fixture\ActivitySearchScenario;
use ILIAS\Component\Activities\Activity;
use ILIAS\Component\Activities\ActivityType;
use ILIAS\Component\Activities\Repository;
use ILIAS\Component\Dependencies\Name;
use ILIAS\Data\Description\Description;
use ILIAS\Data\Factory as DataFactory;
use ILIAS\Data\Result;
use ILIAS\DI\Container as IliasContainer;
use ILIAS\DI\UIServices;
use ILIAS\UI\Component\Input\Factory as InputFactory;
use ILIAS\UI\Factory as UiFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/Fixture/ActivitySearchScenario.php';

#[CoversClass(IliasActivityService::class)]
final class IliasActivityServiceTest extends TestCase {

	public function testRankedActivitySearch(): void {
		ActivitySearchScenario::run(function(array $rows): IliasActivityService {
			$data = new DataFactory();
			$activities = [];
			foreach ($rows as [$name, $type, $description]) {
				$activity = $this->createMock(Activity::class);
				$activity->method('getName')->willReturn(new Name($name));
				$activity->method('getType')->willReturn(ActivityType::from($type));
				if ($description === null) {
					$activity->method('getDescription')->willThrowException(new RuntimeException('Missing description'));
				} else {
					$activity->method('getDescription')->willReturn($data->text()->markdown()->simpleDocument($description));
				}
				$activity->expects($this->never())->method('maybePerformAs');
				$activity->expects($this->never())->method('getInputDescription');
				$activity->expects($this->never())->method('getOutputDescription');
				$activities[] = $activity;
			}
			$repository = $this->createMock(Repository::class);
			$repository->method('getActivitiesByName')->willReturnCallback(static function() use ($activities): \Generator {
				yield from $activities;
			});
			return new IliasActivityService($repository, $this->createMock(IliasContainer::class), new ActivitySchemaMapper());
		});
		$this->addToAssertionCount(1);
	}

	public function testNativeResultAndFailureEnvelopes(): void {
		$data = new DataFactory();
		$description = $data->description()->int($data->text()->markdown()->simpleDocument('Fixture count.'));
		$result = $this->execute($description, $data->ok(7), ActivityType::Query);
		self::assertSame(['ok' => true, 'activity' => self::class, 'type' => 'query', 'result' => 7], $result);
		$result = $this->execute($description, $data->ok(9), ActivityType::Command);
		self::assertSame(['ok' => true, 'activity' => self::class, 'type' => 'command', 'result' => 9], $result);
		$result = $this->execute($description, $data->error('Native permission denied'), ActivityType::Command);
		self::assertSame(['ok' => false, 'activity' => self::class, 'type' => 'command', 'error' => ['code' => 'activity_execution_failed', 'message' => 'Native permission denied']], $result);
	}

	public function testInvalidNativeResultIsNotReportedAsSuccess(): void {
		$data = new DataFactory();
		$description = $data->description()->int($data->text()->markdown()->simpleDocument('Fixture count.'));
		$this->expectException(RuntimeException::class);
		$this->execute($description, $data->ok('not an integer'), ActivityType::Query);
	}

	public function testNoncanonicalNativeProjectionIsRejectedEvenIfMatchSucceeds(): void {
		$data = new DataFactory();
		$description = $this->createMock(Description::class);
		$description->method('matches')->willReturn(true);
		$description->method('getPrimitiveRepresentation')->willReturn(static fn() => yield 'Invalid output');
		$this->expectException(RuntimeException::class);
		$this->execute($description, $data->ok('invalid'), ActivityType::Query);
	}

	public function testNonJsonNativeProjectionIsRejected(): void {
		$data = new DataFactory();
		$description = $this->createMock(Description::class);
		$description->method('matches')->willReturn(true);
		$description->method('getPrimitiveRepresentation')->willReturn(INF);
		$this->expectException(\JsonException::class);
		$this->execute($description, $data->ok('invalid'), ActivityType::Command);
	}

	private function execute(Description $description, Result $result, ActivityType $type): array {
		$input = $this->createMock(InputFactory::class);
		$factory = $this->createMock(UiFactory::class);
		$factory->method('input')->willReturn($input);
		$ui = $this->createMock(UIServices::class);
		$ui->method('factory')->willReturn($factory);
		$ilias = $this->createMock(IliasContainer::class);
		$ilias->method('ui')->willReturn($ui);
		$activity = $this->createMock(Activity::class);
		$activity->method('getName')->willReturn(new Name(self::class));
		$activity->method('getType')->willReturn($type);
		$activity->expects($result->isOK() ? $this->once() : $this->never())->method('getOutputDescription')->willReturn($description);
		$activity->expects($this->once())->method('maybePerformAs')->with($input, 42, ['ref_id' => 12])->willReturn($result);
		$repository = $this->createMock(Repository::class);
		$repository->method('getActivitiesByName')->willReturnCallback(static function() use ($activity): \Generator {
			yield $activity;
		});
		$service = new IliasActivityService($repository, $ilias, new ActivitySchemaMapper());
		return $type === ActivityType::Query
			? $service->executeQuery(self::class, 42, ['ref_id' => 12])
			: $service->executeCommand(self::class, 42, ['ref_id' => 12]);
	}
}
