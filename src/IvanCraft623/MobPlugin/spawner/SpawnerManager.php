<?php

/*
 *   __  __       _     _____  _             _
 *  |  \/  |     | |   |  __ \| |           (_)
 *  | \  / | ___ | |__ | |__) | |_   _  __ _ _ _ __
 *  | |\/| |/ _ \| '_ \|  ___/| | | | |/ _` | | '_ \
 *  | |  | | (_) | |_) | |    | | |_| | (_| | | | | |
 *  |_|  |_|\___/|_.__/|_|    |_|\__,_|\__, |_|_| |_|
 *                                      __/ |
 *                                     |___/
 *
 * A PocketMine-MP plugin that implements mobs AI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 *
 * @author IvanCraft623
 */

declare(strict_types=1);

namespace IvanCraft623\MobPlugin\spawner;

use IvanCraft623\MobPlugin\MobPlugin;
use IvanCraft623\MobPlugin\entity\animal\Cow;
use IvanCraft623\MobPlugin\entity\animal\Chicken;
use IvanCraft623\MobPlugin\entity\animal\Pig;
use IvanCraft623\MobPlugin\entity\animal\Sheep;
use IvanCraft623\MobPlugin\entity\animal\MooshroomCow;
use IvanCraft623\MobPlugin\entity\ambient\Bat;
use IvanCraft623\MobPlugin\entity\monster\Spider;
use IvanCraft623\MobPlugin\entity\monster\CaveSpider;
use IvanCraft623\MobPlugin\entity\monster\Creeper;
use IvanCraft623\MobPlugin\entity\monster\Enderman;
use IvanCraft623\MobPlugin\entity\monster\Endermite;
use IvanCraft623\MobPlugin\entity\monster\Slime;
use IvanCraft623\MobPlugin\entity\golem\IronGolem;
use IvanCraft623\MobPlugin\entity\golem\SnowGolem;

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\player\Player;
use pocketmine\scheduler\TaskHandler;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\world\World;

class SpawnerManager {

    private MobPlugin $plugin;
    private Config $config;
    private TaskScheduler $scheduler;
    private array $spawners = [];
    private array $activeTasks = [];

    public function __construct(MobPlugin $plugin) {
        $this->plugin = $plugin;
        $this->scheduler = $plugin->getScheduler();

        // 스포너 데이터 파일 생성
        $dataFolder = $plugin->getDataFolder();
        if (!file_exists($dataFolder . "spawners")) {
            mkdir($dataFolder . "spawners", 0777, true);
        }

        $this->config = new Config($dataFolder . "spawners/spawners.json", Config::JSON);
        $this->loadSpawners();
    }

    /**
     * OP 관리자에게 스포너 위치 표시
     */
    public function showSpawnerLocationsToOps() : void {
        $server = $this->plugin->getServer();

        // 모든 월드의 OP 관리자들을 확인
        foreach ($server->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getPlayers() as $player) {
                // OP 권한 체크
                if ($player->hasPermission("mobplugin.admin")) {
                    $this->highlightNearbySpawners($player);
                }
            }
        }
    }

    private function highlightNearbySpawners(Player $player) : void {
        // OP 권한 체크
        if (!$this->plugin->getServer()->isOp($player->getName())) {
            return;
        }

        $playerPos = $player->getPosition();
        $world = $player->getWorld();

        // 현재 플레이어의 월드 이름과 일치하는 스포너만 확인
        foreach ($this->spawners as $spawnerId => $spawner) {
            // 현재 월드의 스포너만 확인
            if ($spawner['world'] === $world->getFolderName()) {
                $spawnerPos = new Position($spawner['x'], $spawner['y'], $spawner['z'], $world);
                $distance = $playerPos->distance($spawnerPos);

                // 20블록 반경 이내 스포너만 표시
                if ($distance <= 20) {
                    // 새로운 FloatingText 관리자를 사용하여 홀로그램 추가
                    $floatingTextManager = new SpawnerFloatingTextManager($this->plugin);
                    $floatingTextManager->add($player, $spawnerPos, $spawner);
                }
            }
        }
    }

    /**
     * 모든 스포너 데이터를 불러옵니다.
     */
    private function loadSpawners() : void {
        $this->spawners = $this->config->getAll();

        // 저장된 모든 스포너에 대해 스폰 태스크 시작
        foreach ($this->spawners as $id => $spawner) {
            $this->startSpawnTask((int) $id);
        }
    }

    /**
     * 새로운 스포너를 생성합니다.
     */
    public function createSpawner(int $x, int $y, int $z, string $worldName, string $type, int $spawnRate = 30, int $maxMobs = 5) : int {
        // 새 스포너 ID 생성
        $id = $this->getNextId();

        // 스포너 데이터 저장
        $this->spawners[$id] = [
            'id' => $id,
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'world' => $worldName,
            'type' => $type,
            'spawn_rate' => $spawnRate,
            'max_mobs' => $maxMobs,
            'spawned_entities' => []
        ];

        $this->saveSpawners();

        // 스폰 태스크 시작
        $this->startSpawnTask($id);

        return $id;
    }

    /**
     * 스포너를 제거합니다.
     */
    public function removeSpawner(int $id) : bool {
        if (!isset($this->spawners[$id])) {
            return false;
        }

        // 활성 태스크 중지
        if (isset($this->activeTasks[$id])) {
            $this->stopTask($id);
        }

        // 스포너가 스폰한 모든 엔티티 제거
        $this->despawnAllEntities($id);

        // 스포너 데이터 제거
        unset($this->spawners[$id]);
        $this->saveSpawners();

        return true;
    }

    /**
     * 스포너가 존재하는지 확인합니다.
     */
    public function spawnerExists(int $id) : bool {
        return isset($this->spawners[$id]);
    }

    /**
     * 스포너 정보를 가져옵니다.
     */
    public function getSpawner(int $id) : ?array {
        return $this->spawners[$id] ?? null;
    }

    /**
     * 모든 스포너 목록을 가져옵니다.
     */
    public function getAllSpawners() : array {
        return $this->spawners;
    }

    /**
     * 다음 사용 가능한 ID를 가져옵니다.
     */
    private function getNextId() : int {
        if (empty($this->spawners)) {
            return 1;
        }

        return max(array_keys($this->spawners)) + 1;
    }

    /**
     * 스포너 데이터를 저장합니다.
     */
    private function saveSpawners() : void {
        $this->config->setAll($this->spawners);
        $this->config->save();
    }

    /**
     * 스포너의 스폰 태스크를 시작합니다.
     */
    private function startSpawnTask(int $id) : void {
        if (!isset($this->spawners[$id])) {
            return;
        }

        // 이미 활성화된 태스크가 있으면 중지
        if (isset($this->activeTasks[$id])) {
            $this->stopTask($id);
        }

        $spawner = $this->spawners[$id];
        $spawnRate = $spawner['spawn_rate'] ?? 30; // 초 단위

        // 새 스폰 태스크 등록
        $task = new SpawnerTask($this, $id);
        $taskHandler = $this->scheduler->scheduleRepeatingTask($task, $spawnRate * 20); // ticks로 변환 (1초 = 20틱)
        $this->activeTasks[$id] = $taskHandler;
    }

    /**
     * 특정 스포너의 태스크를 중지합니다.
     */
    private function stopTask(int $id) : void {
        if (isset($this->activeTasks[$id])) {
            $taskHandler = $this->activeTasks[$id];
            if ($taskHandler instanceof TaskHandler) {
                $taskHandler->cancel();
            }
            unset($this->activeTasks[$id]);
        }
    }

    /**
     * 몬스터를 스폰합니다.
     */
    public function spawnEntity(int $spawnerId) : bool {
        if (!isset($this->spawners[$spawnerId])) {
            return false;
        }

        $spawner = $this->spawners[$spawnerId];
        $worldName = $spawner['world'];
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($worldName);

        if ($world === null) {
            return false;
        }

        // 현재 스폰된 엔티티 수 확인
        $currentCount = count($spawner['spawned_entities'] ?? []);
        if ($currentCount >= $spawner['max_mobs']) {
            // 이미 최대 수만큼 스폰되었음
            return false;
        }

        // 청크가 로드되었는지 확인
        if (!$world->isChunkLoaded($spawner['x'] >> 4, $spawner['z'] >> 4)) {
            return false;
        }

        // 플레이어가 근처에 있는지 확인 (24블록 이내)
        $nearbyPlayers = false;
        foreach ($world->getPlayers() as $player) {
            $distance = $player->getPosition()->distance(new Position($spawner['x'], $spawner['y'], $spawner['z'], $world));
            if ($distance <= 24) {
                $nearbyPlayers = true;
                break;
            }
        }

        if (!$nearbyPlayers) {
            return false;
        }

        // 엔티티 맵
        $entityMap = [
            "cow" => Cow::class,
            "chicken" => Chicken::class,
            "pig" => Pig::class,
            "sheep" => Sheep::class,
            "mooshroom" => MooshroomCow::class,
            "bat" => Bat::class,
            "spider" => Spider::class,
            "cavespider" => CaveSpider::class,
            "creeper" => Creeper::class,
            "enderman" => Enderman::class,
            "endermite" => Endermite::class,
            "slime" => Slime::class,
            "irongolem" => IronGolem::class,
            "snowgolem" => SnowGolem::class,
        ];

        if (!isset($entityMap[$spawner['type']])) {
            return false;
        }

        // 약간의 무작위 오프셋을 추가하여 겹치지 않게 스폰
        $offsetX = mt_rand(-30, 30) / 10;
        $offsetZ = mt_rand(-30, 30) / 10;

        $className = $entityMap[$spawner['type']];
        $position = new Position($spawner['x'] + $offsetX, $spawner['y'], $spawner['z'] + $offsetZ, $world);

        // 안전한 스폰 위치 찾기
        $safePosition = $this->findSafeSpawnLocation($position);

        // 엔티티 생성 및 스폰
        $location = new Location(
            $safePosition->x,
            $safePosition->y,
            $safePosition->z,
            $safePosition->getWorld(),
            0, // yaw (회전 각도)
            0  // pitch (기울기 각도)
        );
        $entity = new $className($location);
        $entity->spawnToAll();

        // 스폰된 엔티티 추적
        $entityId = $entity->getId();
        $this->spawners[$spawnerId]['spawned_entities'][] = $entityId;
        $this->saveSpawners();

        return true;
    }

    /**
     * 안전한 스폰 위치를 찾습니다.
     */
    private function findSafeSpawnLocation(Position $position): Position {
        $world = $position->getWorld();
        $x = $position->x;
        $z = $position->z;

        // 수직으로 최대 5칸 확인
        for ($y = $position->y; $y < $position->y + 5; $y++) {
            $blockBelow = $world->getBlockAt((int)$x, (int)($y - 1), (int)$z);
            $currentBlock = $world->getBlockAt((int)$x, (int)$y, (int)$z);
            $blockAbove = $world->getBlockAt((int)$x, (int)($y + 1), (int)$z);

            // 아래 블록이 솔리드하고, 현재 블록과 위 블록이 투과 가능한지 확인
            if ($blockBelow->isSolid() &&
                !$currentBlock->isSolid() &&
                !$blockAbove->isSolid()) {
                return new Position($x, $y, $z, $world);
            }
        }

        // 안전한 위치를 찾지 못하면 원래 위치 반환
        return $position;
    }

    /**
     * 엔티티가 사망하거나 제거될 때 스포너 목록에서도 제거합니다.
     */
    public function removeEntity(Entity $entity) : void {
        $entityId = $entity->getId();

        foreach ($this->spawners as $spawnerId => $spawner) {
            if (isset($spawner['spawned_entities']) && in_array($entityId, $spawner['spawned_entities'])) {
                $key = array_search($entityId, $this->spawners[$spawnerId]['spawned_entities']);
                if ($key !== false) {
                    unset($this->spawners[$spawnerId]['spawned_entities'][$key]);
                    // 배열 재인덱싱
                    $this->spawners[$spawnerId]['spawned_entities'] = array_values($this->spawners[$spawnerId]['spawned_entities']);
                    $this->saveSpawners();
                }
            }
        }
    }

    /**
     * 특정 스포너에 의해 스폰된 모든 엔티티를 제거합니다.
     */
    private function despawnAllEntities(int $spawnerId) : void {
        if (!isset($this->spawners[$spawnerId]) || !isset($this->spawners[$spawnerId]['spawned_entities'])) {
            return;
        }

        foreach ($this->plugin->getServer()->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getEntities() as $entity) {
                if (in_array($entity->getId(), $this->spawners[$spawnerId]['spawned_entities'])) {
                    $entity->close();
                }
            }
        }

        $this->spawners[$spawnerId]['spawned_entities'] = [];
        $this->saveSpawners();
    }
}