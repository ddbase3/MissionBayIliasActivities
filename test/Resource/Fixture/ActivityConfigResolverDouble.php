<?php declare(strict_types=1);

namespace Base3\MissionBayIliasActivities\Test\Resource\Fixture;

use Base3\MissionBay\Api\IAgentConfigValueResolver;

final class ActivityConfigResolverDouble implements IAgentConfigValueResolver {

	public function resolveValue(array|string|int|float|bool|null $config): mixed {
		return $config;
	}
}
