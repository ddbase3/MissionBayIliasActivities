<?php declare(strict_types=1);

namespace MissionBayIliasActivities;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use ILIAS\Component\Activities\Repository;
use ILIAS\DI\Container as IliasContainer;
use MissionBayIliasActivities\Api\IIliasActivityService;
use MissionBayIliasActivities\Ilias\ActivitySchemaMapper;
use MissionBayIliasActivities\Ilias\IliasActivityService;
use RuntimeException;

final class MissionBayIliasActivitiesPlugin implements IPlugin {

	public function __construct(
		private readonly IContainer $container
	) {}

	public static function getName(): string {
		return 'missionbayiliasactivitiesplugin';
	}

	public function init(): void {
		if (!interface_exists(Repository::class)) {
			throw new RuntimeException('MissionBayIliasActivities requires ILIAS 11 or newer with the Activities contract.');
		}
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(ActivitySchemaMapper::class, fn() => new ActivitySchemaMapper(), IContainer::SHARED)
			->set(
				IIliasActivityService::class,
				fn($c) => new IliasActivityService(
					$c->get(Repository::class),
					$c->get(IliasContainer::class),
					$c->get(ActivitySchemaMapper::class)
				),
				IContainer::SHARED
			);
	}
}
