<?php
declare(strict_types=1);

namespace IvanCraft623\MobPlugin\command;

use IvanCraft623\MobPlugin\MobPlugin;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

class SpawnerCancelCommand extends Command {
    private MobPlugin $plugin;

    public function __construct(MobPlugin $plugin) {
        parent::__construct(
            "몹스포너취소",
            "현재 진행 중인 몹 스포너 설정 모드를 취소합니다",
            "/몹스포너취소"
        );
        $this->setPermission("mobplugin.command.spawner.cancel");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "이 명령어는 게임 내에서만 사용할 수 있습니다");
            return false;
        }

        $spawnerListener = $this->plugin->getSpawnerListener();

        if ($spawnerListener->cancelSpawnerSetup($sender->getName())) {
            $sender->sendMessage(TextFormat::GREEN . "몹 스포너 설정 모드가 성공적으로 취소되었습니다.");
        } else {
            $sender->sendMessage(TextFormat::RED . "현재 몹 스포너 설정 모드가 아닙니다.");
        }

        return true;
    }
}