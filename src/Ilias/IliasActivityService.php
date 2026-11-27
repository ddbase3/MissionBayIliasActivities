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


namespace Base3\MissionBayIliasActivities\Ilias;

use ILIAS\Component\Activities\Activity;
use ILIAS\Component\Activities\ActivityType;
use ILIAS\Component\Activities\Repository;
use ILIAS\Data\Factory as DataFactory;
use ILIAS\Data\Result;
use ILIAS\DI\Container as IliasContainer;
use InvalidArgumentException;
use Base3\MissionBayIliasActivities\Api\IIliasActivityService;
use RuntimeException;
use Throwable;

final class IliasActivityService implements IIliasActivityService {

	private readonly DataFactory $dataFactory;

	public function __construct(
		private readonly Repository $repository,
		private readonly IliasContainer $ilias,
		private readonly ActivitySchemaMapper $schemaMapper
	) {
		$this->dataFactory = new DataFactory();
	}

	public function search(
		string $query = '',
		?string $type = null,
		int $limit = 25,
		int $offset = 0
	): array {
		$query = trim($query);
		$type = $this->normalizeType($type);
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$normalizedQuery = $this->normalizeSearchText($query);
		$queryTokens = $this->searchTokens($normalizedQuery);

		$items = [];
		foreach ($this->allActivities() as $activity) {
			try {
				$activityType = $activity->getType()->value;
				if ($type !== null && $activityType !== $type) continue;

				$name = (string)$activity->getName();
				$shortName = $this->shortName($name);
				$description = $this->activityDescription($activity);
				$score = [0, 0, 0];

				if ($query !== '') {
					$score = $this->searchMatch($normalizedQuery, $queryTokens, $name, $shortName, $description['value']);
					if ($score[0] === 0 && $score[1] === 0) continue;
				}
				$item = [
					'name' => $name,
					'short_name' => $shortName,
					'type' => $activityType,
					'description' => $description['value'],
					'metadata_complete' => $description['available'],
					'_search_score' => $score
				];
				if (!$description['available']) {
					$item['metadata_warnings'] = [$description['error']];
				}
				$items[] = $item;
			} catch (Throwable) {
				// One malformed contributed Activity must not make the whole catalogue unusable.
				continue;
			}
		}

		if ($query !== '') {
			usort($items, static function(array $a, array $b): int {
				$scoreCompare = $b['_search_score'] <=> $a['_search_score'];
				return $scoreCompare !== 0 ? $scoreCompare : strcmp($a['name'], $b['name']);
			});
		} else {
			usort($items, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
		}

		$total = count($items);
		$items = array_slice($items, $offset, $limit);
		foreach ($items as &$item) unset($item['_search_score']);
		unset($item);

		$result = [
			'ok' => true,
			'purpose' => 'activity_capability_catalogue',
			'query' => $query,
			'type' => $type,
			'offset' => $offset,
			'limit' => $limit,
			'total' => $total,
			'items' => $items,
			'usage' => 'This searches Activity capabilities only. To search actual ILIAS users, courses or repository objects, choose a matching Activity, describe it, and execute it with the real domain value in its parameters. Resolve required usr_id and ref_id values through available Query Activities instead of asking the user when discovery capabilities exist. Compose multiple Activities when the requested answer is not provided by one Activity.'
		];

		if ($query !== '' && $total === 0) {
			$result['suggestion'] = 'Broaden the capability search yourself before concluding that no Activity exists. Try terms such as user, course, member, find, add, enroll or assign, or list the relevant Activity type. Do not search here for a person name, course title or identifier.';
		}

		return $result;
	}

	public function describe(string $activityName): array {
		$activity = $this->activity($activityName);
		$description = $this->activityDescription($activity);
		$input = $this->activityInputSchema($activity);
		$output = $this->activityOutputSchema($activity);
		$warnings = array_values(array_filter([
			$description['error'],
			$input['error'],
			$output['error']
		]));

		return [
			'ok' => true,
			'name' => (string)$activity->getName(),
			'short_name' => $this->shortName((string)$activity->getName()),
			'type' => $activity->getType()->value,
			'description' => $description['value'],
			'input_schema' => $input['schema'],
			'input_schema_supported' => $input['supported'],
			'unsupported_input_types' => $input['unsupported'],
			'output_schema' => $output['schema'],
			'output_schema_supported' => $output['supported'],
			'unsupported_output_types' => $output['unsupported'],
			'metadata_complete' => $warnings === [],
			'metadata_warnings' => $warnings
		];
	}

	public function executeQuery(string $activityName, int $actingUserId, array $parameters): array {
		return $this->execute($activityName, ActivityType::Query, $actingUserId, $parameters);
	}

	public function executeCommand(string $activityName, int $actingUserId, array $parameters): array {
		return $this->execute($activityName, ActivityType::Command, $actingUserId, $parameters);
	}

	public function buildCommandReview(string $activityName, int $actingUserId, array $parameters): array {
		if ($actingUserId <= 0) {
			throw new RuntimeException('No authenticated ILIAS user is available for Activity review.');
		}

		$activity = $this->activity($activityName);
		if ($activity->getType() !== ActivityType::Command) {
			throw new InvalidArgumentException('Only Command Activities can require mutation approval.');
		}

		$description = $this->activityDescription($activity);
		$shortName = $this->shortName((string)$activity->getName());
		$title = $this->humanizeActivityName($shortName);
		$summary = [];

		if ($description['value'] !== '') {
			$summary['Activity'] = $description['value'];
		}

		foreach ($parameters as $key => $value) {
			[$label, $display] = $this->reviewParameter((string)$key, $value);
			$summary[$label] = $display;
		}

		$message = $description['value'] !== ''
			? $description['value']
			: 'This ILIAS Activity will change data.';

		if ($this->looksLikeAddCourseMember($shortName, $parameters)) {
			$user = $this->reviewUser((int)($parameters['usr_id'] ?? 0), false);
			$refId = (int)($parameters['ref_id'] ?? $parameters['course_ref_id'] ?? 0);
			$course = $this->reviewRepositoryObject($refId, false);
			if ($user !== '' && $course !== '') {
				$title = 'Add course member';
				$message = 'Add ' . $user . ' to the course ' . $course . ' as a normal course member.';
			}
		}

		if ($this->looksLikeRemoveCourseMember($shortName, $parameters)) {
			$user = $this->reviewUser((int)($parameters['usr_id'] ?? 0), false);
			$refId = (int)($parameters['ref_id'] ?? $parameters['course_ref_id'] ?? 0);
			$course = $this->reviewRepositoryObject($refId, false);
			if ($user !== '' && $course !== '') {
				$title = 'Remove course member';
				$message = 'Remove ' . $user . ' from the course ' . $course . '.';
			}
		}

		return [
			'title' => $title,
			'message' => $message,
			'summary' => $summary
		];
	}

	/** @param array<string,mixed> $parameters @return array<string,mixed> */
	private function execute(
		string $activityName,
		ActivityType $expectedType,
		int $actingUserId,
		array $parameters
	): array {
		if ($actingUserId <= 0) {
			throw new RuntimeException('No authenticated ILIAS user is available for Activity execution.');
		}

		$activity = $this->activity($activityName);
		if ($activity->getType() !== $expectedType) {
			throw new InvalidArgumentException(sprintf(
				'Activity %s is of type %s, not %s.',
				(string)$activity->getName(),
				$activity->getType()->value,
				$expectedType->value
			));
		}

		$result = $activity->maybePerformAs(
			$this->ilias->ui()->factory()->input(),
			$actingUserId,
			$parameters
		);

		return $this->result($activity, $result);
	}

	/** @return array<string,mixed> */
	private function result(Activity $activity, Result $result): array {
		if ($result->isOK()) {
			$value = $result->value();
			$output = $activity->getOutputDescription($this->dataFactory->description());
			if (!$output->matches($value)) {
				throw new RuntimeException('Output description does not match result.');
			}

			$primitive = $output->getPrimitiveRepresentation($value);
			// The native conversion must produce canonical data, not its diagnostic
			// closure/generator or another non-JSON value. Keep the native match
			// check above and do not substitute a guessed business schema.
			if ($primitive !== null && !is_scalar($primitive) && !is_array($primitive) && !$primitive instanceof \stdClass) {
				throw new RuntimeException('Output description does not match result.');
			}
			json_encode($primitive, JSON_THROW_ON_ERROR);

			return [
				'ok' => true,
				'activity' => (string)$activity->getName(),
				'type' => $activity->getType()->value,
				'result' => $primitive
			];
		}

		$error = $result->error();
		return [
			'ok' => false,
			'activity' => (string)$activity->getName(),
			'type' => $activity->getType()->value,
			'error' => [
				'code' => 'activity_execution_failed',
				'message' => $error instanceof Throwable ? $error->getMessage() : (string)$error
			]
		];
	}

	private function activity(string $activityName): Activity {
		$activityName = trim($activityName);
		if ($activityName === '') {
			throw new InvalidArgumentException('Activity name must not be empty.');
		}

		foreach ($this->allActivities() as $activity) {
			if ((string)$activity->getName() === $activityName) {
				return $activity;
			}
		}
		throw new InvalidArgumentException('Unknown ILIAS Activity: ' . $activityName);
	}

	/** @return list<Activity> */
	private function allActivities(): array {
		$activities = [];
		foreach ($this->repository->getActivitiesByName('/.*/') as $activity) {
			if ($activity instanceof Activity) $activities[] = $activity;
		}
		return $activities;
	}

	/** @return array{value:string,available:bool,error:?string} */
	private function activityDescription(Activity $activity): array {
		try {
			return [
				'value' => $activity->getDescription()->getRawRepresentation(),
				'available' => true,
				'error' => null
			];
		} catch (Throwable $e) {
			return [
				'value' => '',
				'available' => false,
				'error' => $this->metadataError($activity, 'description', $e)
			];
		}
	}

	/** @return array{schema:array<string,mixed>,supported:bool,unsupported:list<string>,error:?string} */
	private function activityInputSchema(Activity $activity): array {
		try {
			$mapped = $this->schemaMapper->mapInput(
				$activity->getInputDescription($this->ilias->ui()->factory()->input()->field())
			);
			return $mapped + ['error' => null];
		} catch (Throwable $e) {
			return [
				'schema' => [],
				'supported' => false,
				'unsupported' => [],
				'error' => $this->metadataError($activity, 'input description', $e)
			];
		}
	}

	/** @return array{schema:array<string,mixed>,supported:bool,unsupported:list<string>,error:?string} */
	private function activityOutputSchema(Activity $activity): array {
		try {
			$mapped = $this->schemaMapper->mapOutput(
				$activity->getOutputDescription($this->dataFactory->description())
			);
			return $mapped + ['error' => null];
		} catch (Throwable $e) {
			return [
				'schema' => [],
				'supported' => false,
				'unsupported' => [],
				'error' => $this->metadataError($activity, 'output description', $e)
			];
		}
	}

	private function metadataError(Activity $activity, string $part, Throwable $e): string {
		return sprintf(
			'Activity %s has no usable %s metadata: %s',
			(string)$activity->getName(),
			$part,
			$e->getMessage()
		);
	}

	/**
	 * Ranks exact names first, then distinct matched terms, then match quality.
	 * Unmatched terms do not invalidate otherwise relevant metadata.
	 *
	 * @param list<string> $queryTokens
	 * @return array{0:int,1:int,2:int}
	 */
	private function searchMatch(string $query, array $queryTokens, string $name, string $shortName, string $description): array {
		$normalizedName = $this->normalizeSearchText($name);
		$normalizedShortName = $this->normalizeSearchText($shortName);
		$normalizedDescription = $this->normalizeSearchText($description);
		$exact = $query !== '' && ($query === $normalizedName || $query === $normalizedShortName) ? 1 : 0;
		$nameTokens = $this->searchTokens($normalizedName);
		$shortNameTokens = $this->searchTokens($normalizedShortName);
		$descriptionTokens = $this->searchTokens($normalizedDescription);
		$matched = 0;
		$score = 0;
		foreach ($queryTokens as $token) {
			// Count each term only once, with its strongest evidence across the metadata.
			$termScore = max(
				3 * $this->searchTermScore($token, $shortNameTokens),
				2 * $this->searchTermScore($token, $descriptionTokens),
				$this->searchTermScore($token, $nameTokens)
			);
			if ($termScore === 0) continue;
			$matched++;
			$score += $termScore;
		}
		if ($matched > 0) {
			if (str_contains(' ' . $normalizedShortName . ' ', ' ' . $query . ' ')) {
				$score += 8;
			} elseif (str_contains(' ' . $normalizedDescription . ' ', ' ' . $query . ' ')) {
				$score += 4;
			}
		}
		return [$exact, $matched, $score];
	}

	/** @param list<string> $candidateTokens */
	private function searchTermScore(string $token, array $candidateTokens): int {
		if (in_array($token, $candidateTokens, true)) return 2;
		if (strlen($token) < 3) return 0;
		foreach ($candidateTokens as $candidate) {
			if (strlen($candidate) >= 3 && (str_starts_with($candidate, $token) || str_starts_with($token, $candidate))) {
				return 1;
			}
		}
		return 0;
	}

	/** @return list<string> */
	private function searchTokens(string $normalized): array {
		if ($normalized === '') return [];

		$tokens = [];
		foreach (explode(' ', $normalized) as $token) {
			if (in_array($token, ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'is', 'of', 'on', 'or', 'the', 'to', 'with'], true)) continue;
			$token = $this->normalizeSearchToken($token);
			if ($token !== '') $tokens[$token] = true;
		}
		return array_map('strval', array_keys($tokens));
	}

	private function normalizeSearchText(string $value): string {
		$value = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $value) ?? $value;
		$value = str_replace(['\\', '_', '-', '/', '.'], ' ', $value);
		$value = strtolower($value);
		$value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
		return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
	}

	private function normalizeSearchToken(string $token): string {
		$token = strtolower(trim($token));
		$length = strlen($token);
		if ($length > 4 && str_ends_with($token, 'ies')) {
			$token = substr($token, 0, -3) . 'y';
		} elseif ($length > 4 && str_ends_with($token, 'ing')) {
			$token = substr($token, 0, -3);
		} elseif ($length > 3 && str_ends_with($token, 's') && !str_ends_with($token, 'ss')) {
			$token = substr($token, 0, -1);
		}

		return match($token) {
			'search', 'lookup', 'locate' => 'find',
			'participant', 'participation', 'participat', 'membership' => 'member',
			'enroll', 'enrol', 'assign', 'register' => 'add',
			'unenroll', 'unenrol', 'withdraw', 'dismiss', 'deassign', 'remov' => 'remove',
			default => $token
		};
	}

	private function normalizeType(?string $type): ?string {
		$type = strtolower(trim((string)$type));
		if ($type === '') return null;
		if (!in_array($type, [ActivityType::Query->value, ActivityType::Command->value], true)) {
			throw new InvalidArgumentException('Activity type must be query or command.');
		}
		return $type;
	}


	private function humanizeActivityName(string $name): string {
		$name = preg_replace('/Activity$/', '', $name) ?? $name;
		$name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name) ?? $name;
		$name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
		return $name !== '' ? ucfirst($name) : 'ILIAS activity';
	}

	/** @return array{0:string,1:mixed} */
	private function reviewParameter(string $key, mixed $value): array {
		return match($key) {
			'usr_id', 'user_id' => ['User', $this->reviewUser((int)$value, true)],
			'ref_id' => ['Repository object', $this->reviewRepositoryObject((int)$value, true)],
			'course_ref_id' => ['Course', $this->reviewRepositoryObject((int)$value, true)],
			default => [$this->humanizeParameterName($key), $this->formatReviewValue($value)]
		};
	}

	private function reviewUser(int $usrId, bool $includeId): string {
		if ($usrId <= 0 || !class_exists('ilObjUser')) {
			return $includeId && $usrId > 0 ? 'usr_id ' . $usrId : '';
		}

		$name = \ilObjUser::_lookupName($usrId);
		$login = trim((string)\ilObjUser::_lookupLogin($usrId));
		$fullName = trim(implode(' ', array_filter([
			trim((string)($name['firstname'] ?? '')),
			trim((string)($name['lastname'] ?? ''))
		])));

		$display = $fullName !== '' ? $fullName : ($login !== '' ? $login : 'User');
		$details = [];
		if ($login !== '' && strcasecmp($login, $display) !== 0) {
			$details[] = $login;
		}
		if ($includeId) {
			$details[] = 'usr_id ' . $usrId;
		}

		return $details !== [] ? $display . ' (' . implode(', ', $details) . ')' : $display;
	}

	private function reviewRepositoryObject(int $refId, bool $includeId): string {
		if ($refId <= 0 || !class_exists('ilObject')) {
			return $includeId && $refId > 0 ? 'ref_id ' . $refId : '';
		}

		$objId = (int)\ilObject::_lookupObjId($refId);
		$title = $objId > 0 ? trim((string)\ilObject::_lookupTitle($objId)) : '';
		$display = $title !== '' ? $title : 'Repository object';
		return $includeId ? $display . ' (ref_id ' . $refId . ')' : $display;
	}

	private function humanizeParameterName(string $name): string {
		$name = str_replace(['_', '-', '.'], ' ', trim($name));
		$name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
		return $name !== '' ? ucfirst($name) : 'Value';
	}

	private function formatReviewValue(mixed $value): mixed {
		if ($value === null) return 'Not specified';
		if (is_bool($value)) return $value ? 'Yes' : 'No';
		if (is_scalar($value)) return (string)$value;
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : get_debug_type($value);
	}

	/** @param array<string,mixed> $parameters */
	private function looksLikeAddCourseMember(string $shortName, array $parameters): bool {
		$normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $shortName) ?? $shortName);
		return str_contains($normalized, 'addcoursemember')
			&& array_key_exists('usr_id', $parameters)
			&& (array_key_exists('ref_id', $parameters) || array_key_exists('course_ref_id', $parameters));
	}

	/** @param array<string,mixed> $parameters */
	private function looksLikeRemoveCourseMember(string $shortName, array $parameters): bool {
		$normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $shortName) ?? $shortName);
		return str_contains($normalized, 'removecoursemember')
			&& array_key_exists('usr_id', $parameters)
			&& (array_key_exists('ref_id', $parameters) || array_key_exists('course_ref_id', $parameters));
	}

	private function shortName(string $name): string {
		$parts = explode('\\', $name);
		return (string)end($parts);
	}
}
