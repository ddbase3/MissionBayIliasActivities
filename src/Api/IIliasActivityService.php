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


namespace Base3\MissionBayIliasActivities\Api;

interface IIliasActivityService {

	/** @return array<string,mixed> */
	public function search(
		string $query = '',
		?string $type = null,
		int $limit = 25,
		int $offset = 0
	): array;

	/** @return array<string,mixed> */
	public function describe(string $activityName): array;

	/** @param array<string,mixed> $parameters @return array<string,mixed> */
	public function executeQuery(string $activityName, int $actingUserId, array $parameters): array;

	/** @param array<string,mixed> $parameters @return array<string,mixed> */
	public function executeCommand(string $activityName, int $actingUserId, array $parameters): array;

	/**
	 * Builds server-owned, user-facing review data for one Command Activity.
	 *
	 * @param array<string,mixed> $parameters
	 * @return array{title:string,message:string,summary:array<string,mixed>}
	 */
	public function buildCommandReview(string $activityName, int $actingUserId, array $parameters): array;
}
