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


namespace Base3\MissionBayIliasActivities;

use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;
use ILIAS\Component\Activities\Repository;
use ILIAS\DI\Container as IliasContainer;
use Base3\MissionBayIliasActivities\Api\IIliasActivityService;
use Base3\MissionBayIliasActivities\Ilias\ActivitySchemaMapper;
use Base3\MissionBayIliasActivities\Ilias\IliasActivityService;
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
