<?php
declare(strict_types=1);

namespace IvanCraft623\MobPlugin;

use IvanCraft623\MobPlugin\command\SpawnCommand;
use IvanCraft623\MobPlugin\command\SpawnerCommand;
use IvanCraft623\MobPlugin\spawner\SpawnerManager;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class MobPluginListener implements Listener {
    private SpawnerManager $spawnerManager;
    private array $spawnerSetupMode = [];
    private array $continuousSpawnerSetup = [];
    private array $recentlyPlacedSpawners = []; // 최근에 설치된 스포너를 추적

    public function __construct(SpawnerManager $spawnerManager) {
        $this->spawnerManager = $spawnerManager;
    }

    /**
     * 연속 스포너 설정 모드를 시작합니다.
     * 하나의 몹 타입으로 여러 스포너를 계속 생성할 수 있습니다.
     */
    public function startContinuousSpawnerSetup(string $playerName, string $mobType, int $spawnRate, int $maxMobs): void {
        $this->continuousSpawnerSetup[$playerName] = [
            'type' => $mobType,
            'spawnRate' => $spawnRate,
            'maxMobs' => $maxMobs
        ];
    }

    /**
     * 플레이어가 스포너 설정 모드인지 확인합니다.
     */
    public function isInSpawnerSetup(string $playerName): bool {
        // 기존 단일 스포너 설정 또는 연속 스포너 설정 확인
        return isset($this->spawnerSetupMode[$playerName]) || isset($this->continuousSpawnerSetup[$playerName]);
    }

    /**
     * 스포너 설정 모드를 취소합니다.
     */
    public function cancelSpawnerSetup(string $playerName): bool {
        $inSetup = $this->isInSpawnerSetup($playerName);

        // 두 가지 설정 모드 모두 해제
        unset($this->spawnerSetupMode[$playerName]);
        unset($this->continuousSpawnerSetup[$playerName]);

        return $inSetup;
    }

    /**
     * 특정 위치에 최근 스포너가 생성되었는지 확인
     */
    private function isRecentlyPlaced(string $locationKey): bool {
        return isset($this->recentlyPlacedSpawners[$locationKey]) &&
            $this->recentlyPlacedSpawners[$locationKey] > time() - 2; // 2초 내에 설치된 스포너인지 확인
    }

    /**
     * 특정 위치에 스포너가 설치되었음을 기록
     */
    private function markAsRecentlyPlaced(string $locationKey): void {
        $this->recentlyPlacedSpawners[$locationKey] = time();

        // 오래된 기록 정리 (10초 지난 기록은 삭제)
        foreach($this->recentlyPlacedSpawners as $key => $time) {
            if($time <= time() - 10) {
                unset($this->recentlyPlacedSpawners[$key]);
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $playerName = $player->getName();

        // 블록 위치로부터 위치 키 생성
        $locationKey = $block->getPosition()->getFloorX() . ":" .
            $block->getPosition()->getFloorY() . ":" .
            $block->getPosition()->getFloorZ() . ":" .
            $block->getPosition()->getWorld()->getFolderName();

        // 이 위치에 최근에 스포너가 생성되었는지 확인
        if($this->isRecentlyPlaced($locationKey)) {
            // 스포너가 이미 생성된 위치라면 이벤트 취소하고 처리하지 않음
            $event->cancel();
            return;
        }

        // 기존 단일 스포너 설정 모드 확인
        if (isset($this->spawnerSetupMode[$playerName])) {
            $event->cancel(); // 이벤트 취소

            $spawnerData = $this->spawnerSetupMode[$playerName];

            // 스포너 생성
            $spawnerId = $this->spawnerManager->createSpawner(
                $block->getPosition()->getFloorX(),
                $block->getPosition()->getFloorY(),
                $block->getPosition()->getFloorZ(),
                $block->getPosition()->getWorld()->getFolderName(),
                $spawnerData['type'],
                $spawnerData['spawnRate'],
                $spawnerData['maxMobs']
            );

            // 스포너가 생성된 위치 기록
            $this->markAsRecentlyPlaced($locationKey);

            $player->sendMessage(TextFormat::GREEN . "Successfully created a " . $spawnerData['type'] . " spawner (ID: $spawnerId)");

            // 스포너 설정 모드 종료
            unset($this->spawnerSetupMode[$playerName]);
            return;
        }

        // 연속 스포너 설정 모드 확인
        if (isset($this->continuousSpawnerSetup[$playerName])) {
            $event->cancel(); // 이벤트 취소

            $spawnerData = $this->continuousSpawnerSetup[$playerName];

            // 스포너 생성
            $spawnerId = $this->spawnerManager->createSpawner(
                $block->getPosition()->getFloorX(),
                $block->getPosition()->getFloorY(),
                $block->getPosition()->getFloorZ(),
                $block->getPosition()->getWorld()->getFolderName(),
                $spawnerData['type'],
                $spawnerData['spawnRate'],
                $spawnerData['maxMobs']
            );

            // 스포너가 생성된 위치 기록
            $this->markAsRecentlyPlaced($locationKey);

            $player->sendMessage(TextFormat::GREEN . "Successfully created a " . $spawnerData['type'] . " spawner (ID: $spawnerId)");
            $player->sendMessage(TextFormat::YELLOW . "Click another block to create more or use /mobspawner cancel to finish.");

            // 연속 모드에서는 설정 모드를 유지합니다 (종료하지 않음)
            return;
        }
    }

    public function onPlayerChat(PlayerChatEvent $event) {
        $player = $event->getPlayer();
        $message = $event->getMessage();
        $playerName = $player->getName();

        // 스포너 설정 취소
        if (strtolower($message) === "/mobspawner cancel") {
            // 모든 스포너 설정 모드 확인
            if ($this->cancelSpawnerSetup($playerName)) {
                $player->sendMessage(TextFormat::YELLOW . "Spawner setup mode cancelled.");
                $event->cancel();
            }
        }
    }

    public function startSpawnerSetup(string $playerName, string $mobType, int $spawnRate = 30, int $maxMobs = 5) {
        $this->spawnerSetupMode[$playerName] = [
            'type' => $mobType,
            'spawnRate' => $spawnRate,
            'maxMobs' => $maxMobs
        ];
    }

    /**
     * 블록 파괴 방지 (스포너 설정 모드일 때)
     */
    public function onBlockBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        $playerName = $player->getName();

        // 스포너 설정 모드인 경우 블록 파괴 방지
        if($this->isInSpawnerSetup($playerName)) {
            $event->cancel();
        }
    }
}