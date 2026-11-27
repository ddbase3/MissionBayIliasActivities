<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Resource\Fixture;

use Base3\CoreFoundation\Accesscontrol\Api\IAccesscontrol;

final class ActivityAccesscontrolDouble implements IAccesscontrol {

	public mixed $userId = '42';
	public int $reads = 0;

	public function getUserId() {
		$this->reads++;
		return $this->userId;
	}

	public function authenticate(): void {}
}
