<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Resource\Fixture;

use Base3\CoreFoundation\Event\Api\IEventManager;

final class ActivityEventManagerDouble implements IEventManager {

	public array $events = [];

	public function on(string $event, callable $listener, int $priority = 0): void {}
	public function once(string $event, callable $listener, int $priority = 0): void {}
	public function off(string $event, callable $listener): void {}

	public function fire(object|string $event, ...$args): array {
		$this->events[] = $event;
		return [];
	}
}
