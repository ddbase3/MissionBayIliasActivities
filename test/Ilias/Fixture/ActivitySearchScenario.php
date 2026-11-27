<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Ilias\Fixture;

use Base3\MissionBayIliasActivities\Api\IIliasActivityService;
use InvalidArgumentException;
use RuntimeException;

/** Regression catalogue and queries from the native Activity live test. */
final class ActivitySearchScenario {

	private const ROWS = [
		['ILIAS\\ActivityShowcase\\Activities\\AddCourseMember', 'command', 'Adds one active ILIAS user to a course as a normal course member.'],
		['ILIAS\\ActivityShowcase\\Activities\\FindRepositoryObjects', 'query', 'Find repository objects such as courses, groups or SCORM modules by a loose title fragment and optional ILIAS object type. Returns ref_id values for subsequent Activities.'],
		['ILIAS\\ActivityShowcase\\Activities\\FindUsers', 'query', 'Find active ILIAS users by login, first name, last name or full name.'],
		['ILIAS\\ActivityShowcase\\Activities\\ListCourseMembers', 'query', 'Lists the participants of one ILIAS course, including administrators, tutors and normal members.'],
		['ILIAS\\ActivityShowcase\\Activities\\ListUserCourseMemberships', 'query', 'Lists all ILIAS course memberships of one user, including the course role.'],
		['ILIAS\\ActivityShowcase\\Activities\\RemoveCourseMember', 'command', 'Removes one ILIAS user from a course membership.'],
		['ILIAS\\ScormAicc\\Activities\\ViewScormUserCertificateStatusActivity', 'query', 'Shows whether a certificate is available for a user in a SCORM learning module.'],
		['ILIAS\\ScormAicc\\Activities\\ViewScormUserStatusActivity', 'query', 'Shows the SCORM learning progress status of a user for a SCORM learning module.'],
		['ILIAS\\Setup\\Activities\\GetStatus', 'query', null],
	];

	/** @param callable(array):IIliasActivityService $createService */
	public static function run(callable $createService): void {
		$service = $createService(self::ROWS);
		$reversed = $createService(array_reverse(self::ROWS));
		$all = $service->search('', null, 100);
		self::check($all['total'] === 9 && count($all['items']) === 9, 'Unfiltered catalogue changed.');
		$names = array_column($all['items'], 'name');
		$sortedNames = $names;
		sort($sortedNames, SORT_STRING);
		self::check($names === $sortedNames && $all === $reversed->search('', null, 100), 'Unfiltered order is not deterministic.');
		self::check($service->search('', 'query')['total'] === 7 && $service->search('', 'command')['total'] === 2, 'Activity type inventory changed.');

		foreach ([
			['find repository objects courses list course', 'query', 'FindRepositoryObjects'],
			['list course members participants roles tutors administrators', 'query', 'ListCourseMembers'],
			['repository search type crs title', 'query', 'FindRepositoryObjects'],
			['course', 'query', 'ListCourseMembers'],
			['repository', 'query', 'FindRepositoryObjects'],
			['find user', 'query', 'FindUsers'],
			['list course members', 'query', 'ListCourseMembers'],
			['enroll member', 'command', 'AddCourseMember'],
			['withdraw member', 'command', 'RemoveCourseMember'],
			['add course member', 'command', 'AddCourseMember'],
			['remove course member', 'command', 'RemoveCourseMember'],
			['FindRepositoryObjects', null, 'FindRepositoryObjects'],
			['ILIAS\\ActivityShowcase\\Activities\\ListUserCourseMemberships', null, 'ListUserCourseMemberships'],
			['GetStatus', 'query', 'GetStatus']
		] as [$query, $type, $expected]) {
			$result = $service->search($query, $type, 100);
			self::check(($result['items'][0]['short_name'] ?? null) === $expected, 'Wrong first result for ' . $query . ': ' . json_encode($result['items']));
			self::check($result['total'] === count($result['items']) && !isset($result['suggestion']), 'Successful search count or suggestion is incorrect.');
			self::check($result === $reversed->search($query, $type, 100), 'Search order depends on repository enumeration.');
			foreach ($result['items'] as $item) {
				self::check($type === null || $item['type'] === $type, 'Activity type filter was bypassed.');
				self::check(!isset($item['_search_score']), 'Internal rank leaked into output.');
			}
			$page = $service->search($query, $type, 1, 1);
			self::check($page['total'] === $result['total'] && $page['items'] === array_slice($result['items'], 1, 1), 'Pagination was applied before ranking.');
		}

		self::check($service->search('course', 'query')['total'] === 3, 'Single-term course search changed.');
		self::check($service->search('repository', 'query')['total'] === 1, 'Single-term repository search changed.');
		self::check($service->search('member', 'query')['total'] === 2, 'Single-term member search changed.');
		$found = $service->search('find repository objects courses list course', 'query');
		$repeated = $service->search('find repository objects courses list course course course', 'query');
		self::check($found['items'] === $repeated['items'], 'Repeated terms inflated relevance.');
		$incomplete = $service->search('GetStatus', 'query')['items'][0];
		self::check(!$incomplete['metadata_complete'] && $incomplete['description'] === '' && count($incomplete['metadata_warnings']) === 1, 'Incomplete metadata is no longer discoverable by name.');

		foreach (['xylophonozz', 'for the and', '!!!', '12345'] as $query) {
			$missing = $service->search($query);
			self::check($missing['ok'] === true && $missing['total'] === 0 && $missing['items'] === [] && isset($missing['suggestion']), 'No-match query was padded: ' . $query);
		}
		$page = $service->search('', null, 500, -1);
		self::check($page['offset'] === 0 && $page['limit'] === 100 && $page['items'] === $all['items'], 'Existing page normalization changed.');
		$page = $service->search('course', 'query', 1, 99);
		self::check($page['items'] === [] && $page['total'] === 3 && !isset($page['suggestion']), 'Out-of-range page lost total or added a no-match suggestion.');
		$rejected = false;
		try {
			$service->search('course', 'invalid');
		} catch (InvalidArgumentException) {
			$rejected = true;
		}
		self::check($rejected, 'Invalid type filter accepted.');
	}

	private static function check(bool $condition, string $message): void {
		if (!$condition) throw new RuntimeException($message);
	}
}
