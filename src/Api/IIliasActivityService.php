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

	/**
	 * Searches native Activity metadata, not domain records or effective permissions.
	 * Nonempty searches rank exact Activity names first, followed by distinct
	 * matching capability terms and match quality. Not every term must match;
	 * unrelated entries with no matching term are excluded. Common English
	 * connecting words are ignored. Empty searches list metadata by technical name.
	 * Equal relevance is resolved by technical name. The type filter applies
	 * before ranking and the total counts matches before pagination.
	 * A successful result contains the requested page of Activity metadata.
	 * Partial matches do not establish that an Activity implements the requested
	 * operation or that execution is authorized. Malformed contributed metadata can
	 * be incomplete or omitted; no Activity is executed.
	 *
	 * @param string $query Capability search text; empty lists available metadata.
	 * @param string|null $type Optional query or command filter.
	 * @param int $limit Page size, normalized to 1 through 100.
	 * @param int $offset Nonnegative page offset (negative values become zero).
	 * @return array{ok:true,purpose:string,query:string,type:?string,offset:int,limit:int,total:int,items:list<array{name:string,short_name:string,type:string,description:string,metadata_complete:bool,metadata_warnings?:list<string>}>,usage:string,suggestion?:string}
	 * @throws \InvalidArgumentException For an unknown type filter.
	 * @throws \Throwable If catalogue access fails.
	 */
	public function search(
		string $query = '',
		?string $type = null,
		int $limit = 25,
		int $offset = 0
	): array;

	/**
	 * Describes the exact named native Activity without executing it. Input and
	 * output schema values are mapped metadata documents, not invocation results.
	 * Unsupported types are reported separately from metadata retrieval errors.
	 * Unavailable schema metadata is represented by an empty array, with the
	 * corresponding support flag false and a metadata warning.
	 *
	 * @param string $activityName Exact native Activity name (surrounding whitespace is ignored).
	 * @return array{ok:true,name:string,short_name:string,type:string,description:string,input_schema:array<string,mixed>,input_schema_supported:bool,unsupported_input_types:list<string>,output_schema:array<string,mixed>,output_schema_supported:bool,unsupported_output_types:list<string>,metadata_complete:bool,metadata_warnings:list<string>}
	 * @throws \InvalidArgumentException For an empty or unknown Activity name.
	 * @throws \Throwable If catalogue access or Activity identity/type lookup fails.
	 */
	public function describe(string $activityName): array;

	/**
	 * Executes an exact native Query through its native input processing and
	 * authorization as the trusted authenticated actor. A success contains the
	 * canonical primitive representation of the result, verified against that
	 * Activity's native output description and serializable as JSON. A native
	 * failure contains the Activity identity and error, never a success result.
	 * The mapped schema returned by describe() documents the concrete shape
	 * when supported; it does not replace native validation.
	 *
	 * @param string $activityName Exact native Query name.
	 * @param int $actingUserId Trusted positive native ILIAS user ID.
	 * @param array<string,mixed> $parameters Native Activity inputs, not actor credentials.
	 * @return array{ok:true,activity:string,type:'query',result:mixed}|array{ok:false,activity:string,type:'query',error:array{code:'activity_execution_failed',message:string}}
	 * @throws \InvalidArgumentException For an unknown name or wrong Activity type.
	 * @throws \Throwable For unavailable identity, host errors or invalid output.
	 */
	public function executeQuery(string $activityName, int $actingUserId, array $parameters): array;

	/**
	 * Executes an exact native Command through its native input processing and
	 * authorization as the trusted authenticated actor. The calling MissionBay
	 * harness must enforce the existing approval policy before invocation.
	 * The success/failure and native output verification semantics are the same
	 * as for executeQuery(). Execution can change data before a result or output
	 * validation error is reported; such an error does not establish rollback.
	 *
	 * @param string $activityName Exact native Command name.
	 * @param int $actingUserId Trusted positive native ILIAS user ID.
	 * @param array<string,mixed> $parameters Native Activity inputs, not actor credentials.
	 * @return array{ok:true,activity:string,type:'command',result:mixed}|array{ok:false,activity:string,type:'command',error:array{code:'activity_execution_failed',message:string}}
	 * @throws \InvalidArgumentException For an unknown name or wrong Activity type.
	 * @throws \Throwable For unavailable identity, host errors or invalid output.
	 */
	public function executeCommand(string $activityName, int $actingUserId, array $parameters): array;

	/**
	 * Builds server-owned, user-facing review data for one Command Activity.
	 *
	 * @param array<string,mixed> $parameters
	 * @return array{title:string,message:string,summary:array<string,mixed>}
	 */
	public function buildCommandReview(string $activityName, int $actingUserId, array $parameters): array;
}
