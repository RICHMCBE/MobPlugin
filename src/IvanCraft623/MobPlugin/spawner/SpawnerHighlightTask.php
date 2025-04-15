<?php
declare(strict_types=1);

namespace IvanCraft623\MobPlugin\spawner;

use pocketmine\scheduler\Task;

class SpawnerHighlightTask extends Task {
    private SpawnerManager $spawnerManager;

    public function __construct(SpawnerManager $spawnerManager) {
        $this->spawnerManager = $spawnerManager;
    }

    public function onRun() : void {
        $this->spawnerManager->showSpawnerLocationsToOps();
    }
}