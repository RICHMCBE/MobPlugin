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

use IvanCraft623\MobPlugin\command\SpawnerCancelCommand;
use IvanCraft623\MobPlugin\command\SpawnerCommand;
use IvanCraft623\MobPlugin\entity\ambient\Bat;
use IvanCraft623\MobPlugin\entity\animal\Chicken;
use IvanCraft623\MobPlugin\entity\animal\Cow;
use IvanCraft623\MobPlugin\entity\animal\MooshroomCow;
use IvanCraft623\MobPlugin\entity\animal\Pig;
use IvanCraft623\MobPlugin\entity\animal\Sheep;
use IvanCraft623\MobPlugin\entity\CustomAttributes;
use IvanCraft623\MobPlugin\entity\golem\IronGolem;
use IvanCraft623\MobPlugin\entity\golem\SnowGolem;
use IvanCraft623\MobPlugin\entity\monster\CaveSpider;
use IvanCraft623\MobPlugin\entity\monster\Creeper;
use IvanCraft623\MobPlugin\entity\monster\Enderman;
use IvanCraft623\MobPlugin\entity\monster\Endermite;
use IvanCraft623\MobPlugin\entity\monster\Slime;
use IvanCraft623\MobPlugin\entity\monster\Spider;
use IvanCraft623\MobPlugin\item\ExtraItemRegisterHelper;
use IvanCraft623\MobPlugin\spawner\SpawnerHighlightTask;
use IvanCraft623\MobPlugin\spawner\SpawnerManager;
use IvanCraft623\MobPlugin\utils\Utils;

use pocketmine\entity\AttributeFactory;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityDataHelper as Helper;
use pocketmine\entity\EntityFactory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Random;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use function mt_rand;

class MobPlugin extends PluginBase {
    use SingletonTrait;

    private MobPluginListener $spawnerListener;

    public const ALL_ENTITIES = [
        Bat::class,
        Chicken::class,
        Cow::class,
        MooshroomCow::class,
        Pig::class,
        Sheep::class,
        IronGolem::class,
        SnowGolem::class,
        CaveSpider::class,
        Creeper::class,
        Enderman::class,
        Endermite::class,
        Slime::class,
        Spider::class
    ];

    private ?Random $random = null;
    private SpawnerManager $spawnerManager;

    public function onLoad() : void {
        self::setInstance($this);
    }

    public function onEnable() : void {
        CustomTimings::init();

        $this->registerAttributes();
        $this->registerEntities();

        ExtraItemRegisterHelper::init();

        // 스포너 매니저 초기화
        $this->spawnerManager = new SpawnerManager($this);

        // 명령어 등록
        $this->getServer()->getCommandMap()->register("mobspawner", new SpawnerCommand($this, $this->spawnerManager));
        $this->getServer()->getCommandMap()->register("mobspawncancel", new SpawnerCancelCommand($this));

        $this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);

        // 스포너 관련 이벤트 리스너 등록
        $this->spawnerListener = new MobPluginListener($this->spawnerManager);
        $this->getServer()->getPluginManager()->registerEvents($this->spawnerListener, $this);

        // 스포너 하이라이트 태스크 추가 (10초마다)
        $this->getScheduler()->scheduleRepeatingTask(
            new SpawnerHighlightTask($this->spawnerManager),
            10 * 20 // 10초마다
        );

        $this->getLogger()->info("MobPlugin has been enabled!");
    }

    public function getSpawnerListener(): ?MobPluginListener {
        return $this->spawnerListener;
    }

    public function getRandom() : Random {
        if ($this->random === null) {
            $this->random = new Random(mt_rand());
        }
        return $this->random;
    }

    private function registerAttributes() : void{
        $factory = AttributeFactory::getInstance();

        $factory->register(CustomAttributes::ATTACK_KNOCKBACK, 0.00, 340282346638528859811704183484516925440.00, 0.4, false);
    }

    private function registerEntities() : void{
        $factory = EntityFactory::getInstance();

        foreach (self::ALL_ENTITIES as $entityClass) {
            $this->registerEntity($factory, $entityClass);
        }
    }

    /**
     * @phpstan-param class-string<Entity> $entityClass
     */
    private function registerEntity(EntityFactory $factory, string $entityClass) : void{
        //Did you know that bedrock entity's save ids are the same as network ids?
        $entityId = $entityClass::getNetworkTypeId();

        $factory->register($entityClass, function(World $world, CompoundTag $nbt) use ($entityClass) : Entity{
            return new $entityClass(Helper::parseLocation($nbt, $world), $nbt);
        }, [$entityId, Utils::getEntityNameFromId($entityId)]);
    }
}