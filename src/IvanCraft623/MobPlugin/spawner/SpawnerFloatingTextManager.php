<?php
declare(strict_types=1);

namespace IvanCraft623\MobPlugin\spawner;

use IvanCraft623\MobPlugin\MobPlugin;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\Server;
use pocketmine\world\Position;

use function array_keys;
use function microtime;

final class SpawnerFloatingTextManager {
    private const DISPLAY_SECONDS = 5;

    /** @var array<int, SpawnerFloatingText> */
    private array $spawnerTexts = [];

    private TaskScheduler $taskScheduler;
    private ?TaskHandler $taskHandler = null;

    public function __construct(private MobPlugin $plugin) {
        $this->taskScheduler = $plugin->getScheduler();
    }

    private function onUpdate() : void {
        foreach (array_keys($this->spawnerTexts) as $playerId) {
            if (!$this->spawnerTexts[$playerId]->onUpdate()) {
                $this->spawnerTexts[$playerId]->remove();
                unset($this->spawnerTexts[$playerId]);
            }
        }

        if (!empty($this->spawnerTexts)) {
            // TPS에 따라 동적으로 지연 시간 조정 (5~20틱)
            $delay = (int) (20 - (15 * (Server::getInstance()->getTicksPerSecond() / 20)));
            $this->taskHandler = $this->taskScheduler->scheduleDelayedTask(
                new ClosureTask($this->onUpdate(...)),
                $delay
            );
        } else {
            $this->taskHandler = null;
        }
    }

    public function add(Player $player, Position $spawnerPos, array $spawnerData) : void {
        $expirationTime = microtime(true) + self::DISPLAY_SECONDS;
        $playerId = $player->getId();

        if (isset($this->spawnerTexts[$playerId])) {
            $this->spawnerTexts[$playerId]->setExpireAt($expirationTime);
            $this->spawnerTexts[$playerId]->setSpawnerPos($spawnerPos, $spawnerData);
        } else {
            $this->spawnerTexts[$playerId] = new SpawnerFloatingText(
                $player,
                $spawnerPos,
                $spawnerData,
                $expirationTime
            );
        }

        if ($this->taskHandler === null || $this->taskHandler->isCancelled()) {
            $this->onUpdate();
        } else {
            $this->spawnerTexts[$playerId]->onUpdate();
        }
    }

    public function remove(Player $player) : void {
        $playerId = $player->getId();
        if (isset($this->spawnerTexts[$playerId])) {
            $this->spawnerTexts[$playerId]->remove();
            unset($this->spawnerTexts[$playerId]);
        }
    }
}