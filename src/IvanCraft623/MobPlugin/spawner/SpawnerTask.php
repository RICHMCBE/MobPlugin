<?php
declare(strict_types=1);

namespace IvanCraft623\MobPlugin\spawner;

use pocketmine\scheduler\Task;

class SpawnerTask extends Task {

    private SpawnerManager $manager;
    private int $spawnerId;

    public function __construct(SpawnerManager $manager, int $spawnerId) {
        $this->manager = $manager;
        $this->spawnerId = $spawnerId;
    }

    public function onRun() : void {
        // 몬스터 스폰 시도
        $this->manager->spawnEntity($this->spawnerId);

        // 다음 스폰 시간 업데이트
        $spawner = $this->manager->getSpawner($this->spawnerId);
        if ($spawner !== null) {
            $spawnRate = $spawner['spawn_rate'] ?? 30;
            $this->manager->updateNextSpawnTime($this->spawnerId, time() + $spawnRate);
        }
    }
}