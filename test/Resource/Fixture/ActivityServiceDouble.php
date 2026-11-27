<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Resource\Fixture;

use Base3\MissionBayIliasActivities\Api\IIliasActivityService;
use Throwable;

final class ActivityServiceDouble implements IIliasActivityService {

	public array $calls = [];
	public array|Throwable $searchResult = [];
	/** @var array<string,array|Throwable> */
	public array $descriptions = [];
	public array|Throwable $queryResult = [];
	public array|Throwable $commandResult = [];
	public array $review = ['title' => 'Review', 'message' => 'Review command', 'summary' => []];

	public function search(string $query = '', ?string $type = null, int $limit = 25, int $offset = 0): array {
		$this->calls[] = ['search', $query, $type, $limit, $offset];
		return $this->result($this->searchResult);
	}

	public function describe(string $activityName): array {
		$this->calls[] = ['describe', $activityName];
		return $this->result($this->descriptions[$activityName] ?? new \InvalidArgumentException('Unknown ILIAS Activity: ' . $activityName));
	}

	public function executeQuery(string $activityName, int $actingUserId, array $parameters): array {
		$this->calls[] = ['query', $activityName, $actingUserId, $parameters];
		return $this->result($this->queryResult);
	}

	public function executeCommand(string $activityName, int $actingUserId, array $parameters): array {
		$this->calls[] = ['command', $activityName, $actingUserId, $parameters];
		return $this->result($this->commandResult);
	}

	public function buildCommandReview(string $activityName, int $actingUserId, array $parameters): array {
		$this->calls[] = ['review', $activityName, $actingUserId, $parameters];
		return $this->review;
	}

	private function result(array|Throwable $result): array {
		if ($result instanceof Throwable) throw $result;
		return $result;
	}
}
