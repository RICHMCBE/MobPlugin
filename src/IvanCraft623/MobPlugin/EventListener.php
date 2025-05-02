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

namespace IvanCraft623\MobPlugin;

use IvanCraft623\MobPlugin\entity\golem\IronGolem;
use IvanCraft623\MobPlugin\entity\golem\SnowGolem;
use IvanCraft623\MobPlugin\entity\Mob;
use IvanCraft623\MobPlugin\pattern\BlockPattern;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityDespawnEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\math\Vector3;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\Position;

class EventListener implements Listener {

    private MobPlugin $plugin;

    public function __construct(MobPlugin $plugin) {
        $this->plugin = $plugin;
    }

	/**
	 * @priority HIGH
	 * @ignoreCancelled
	 */
	public function onBlockPlace(BlockPlaceEvent $event) : void {
		foreach($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]){
			if (($id = $block->getTypeId()) === BlockTypeIds::CARVED_PUMPKIN ||
				$id === BlockTypeIds::LIT_PUMPKIN ||
				$id === BlockTypeIds::PUMPKIN
			) {
				$player = $event->getPlayer();
				MobPlugin::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($block, $player) : void{
					$pos = $block->getPosition();

					($this->tryToSpawnFromPattern(
						$pos, IronGolem::getSpawnPattern(), fn(Location $l) => new IronGolem($l), new Vector3(1, 2, 0)
					) ?? $this->tryToSpawnFromPattern(
						$pos, SnowGolem::getSpawnPattern(), fn(Location $l) => new SnowGolem($l), new Vector3(0, 2, 0)
					))?->setOwningEntity($player->isClosed() ? null : $player); //Player may have been disconnected
				}), 1);
			}
		}
	}

	private function tryToSpawnFromPattern(Position $pos, BlockPattern $pattern, \Closure $entityConstructor, Vector3 $spawnOffset) : ?Entity{
		$patternMatch = $pattern->find($pos);
		if ($patternMatch === null) {
			return null;
		}

		$world = $pos->getWorld();

		//clear pattern
		$totalWidth = $patternMatch->getWidth();
		for ($currentWidth = 0; $currentWidth < $totalWidth; $currentWidth++) {
			$totalHeight = $patternMatch->getHeight();
			for ($currentHeight = 0; $currentHeight < $totalHeight; $currentHeight++) {
				$b = $patternMatch->getBlock($currentWidth, $currentHeight, 0, $world);
				if ($b->getTypeId() === BlockTypeIds::AIR) {
					continue;
				}

				$p = $b->getPosition();

				$world->addParticle($p->add(0.5, 0.5, 0.5), new BlockBreakParticle($b));
				$world->setBlock($p, VanillaBlocks::AIR());
			}
		}

		//spawn
		$e = $entityConstructor(Location::fromObject(
			$patternMatch->getBlock((int) $spawnOffset->x, (int) $spawnOffset->y, (int) $spawnOffset->z, $world)
				->getPosition()
				->add(0.5, 0, 0.5),
			$world
		));
		$e->spawnToAll();

		return $e;
	}

    /**
     * 플레이어 상호작용 이벤트 처리
     * @priority HIGHEST
     */
    public function onPlayerInteract(PlayerInteractEvent $event): void {
        // 우클릭(USE) 액션만 처리
        if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            return;
        }

        $block = $event->getBlock();
        $player = $event->getPlayer();
        $position = $block->getPosition();

        // 스포너 홀로그램 매니저 가져오기
        $spawnerHologram = $this->plugin->getSpawnerHologram();
        if ($spawnerHologram !== null) {
            // 스포너 ID 확인
            $spawnerManager = $this->plugin->getSpawnerManager();
            if ($spawnerManager !== null) {
                $spawnerId = $spawnerManager->findSpawnerByPosition(
                    $position->getFloorX(),
                    $position->getFloorY(),
                    $position->getFloorZ(),
                    $position->getWorld()->getFolderName()
                );

                // 스포너인 경우 홀로그램 표시 및 이벤트 취소
                if ($spawnerId !== null) {
                    $spawnerHologram->onSpawnerInteract($player, $position);
                    $event->cancel(); // 원래 이벤트를 취소하여 보호 플러그인의 상호작용 제한을 우회
                }
            }
        }
    }

    /**
     * 블록 파괴 이벤트 처리
     * @priority NORMAL
     * @ignoreCancelled
     */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $block = $event->getBlock();
        $player = $event->getPlayer();
        $position = $block->getPosition();

        // 스포너 매니저 가져오기
        $spawnerManager = $this->plugin->getSpawnerManager();
        if ($spawnerManager === null) {
            return;
        }

        // 스포너 블록인지 확인 및 처리
        if ($spawnerManager->onSpawnerBreak($position, $player)) {
            // 스포너가 제거됐으면 블록 파괴 이벤트 취소
            $event->cancel();
        }
    }

    /**
     * 엔티티 디스폰 이벤트 처리
     */
    public function onEntityDespawn(EntityDespawnEvent $event): void {
        $entity = $event->getEntity();
        if ($entity instanceof Mob) {
            $spawnerManager = $this->plugin->getSpawnerManager();
            if ($spawnerManager !== null) {
                $spawnerManager->removeEntity($entity);
            }
        }
    }

    /**
     * 엔티티 사망 이벤트 처리
     */
    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();
        if ($entity instanceof Mob) {
            $spawnerManager = $this->plugin->getSpawnerManager();
            if ($spawnerManager !== null) {
                $spawnerManager->removeEntity($entity);
            }
        }
    }
}
