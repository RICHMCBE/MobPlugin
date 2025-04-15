<?php
declare(strict_types=1);

namespace IvanCraft623\MobPlugin\command;

use IvanCraft623\MobPlugin\MobPlugin;
use IvanCraft623\MobPlugin\spawner\SpawnerManager;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

// CommandCore 클래스 가져오기
use RoMo\CommandCore\CommandCore;
use RoMo\CommandCore\command\parameter\EnumParameter;
use RoMo\CommandCore\command\parameter\IntParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;

class SpawnerCommand extends Command {
    private MobPlugin $plugin;
    private SpawnerManager $spawnerManager;

    public function __construct(MobPlugin $plugin, SpawnerManager $spawnerManager) {
        parent::__construct(
            "몹스포너",
            "블록을 클릭하여 몹 스포너를 생성합니다",
            "/몹스포너 <몹종류> [스폰주기] [최대몹수]"
        );
        $this->setPermission("mobplugin.command.spawner");
        $this->plugin = $plugin;
        $this->spawnerManager = $spawnerManager;

        // 명령어 자동완성 등록
        $this->registerCommandOverloads();
    }

    private function registerCommandOverloads(): void {
        // 유효한 몹 종류 정의
        $validMobTypes = [
            "소", "닭", "돼지", "양", "무시룸",
            "박쥐",
            "거미", "동굴거미", "크리퍼", "엔더맨", "엔더진", "슬라임",
            "철골렘", "눈골렘"
        ];

        // CommandEnum 생성
        $mobTypeEnum = new CommandEnum("몹종류", $validMobTypes);

        // 주요 스포너 명령어 오버로드 생성
        $mainOverload = CommandCore::createOverload(
            new EnumParameter("mobType", $mobTypeEnum, false),
            new IntParameter("spawnRate", true),
            new IntParameter("maxMobs", true)
        );

        // 명령어 오버로드 등록
        CommandCore::getInstance()->registerCommandOverload(
            $this,
            $mainOverload
        );
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "이 명령어는 게임 내에서만 사용할 수 있습니다");
            return false;
        }

        if (count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "사용법: " . $this->getUsage());
            return false;
        }

        // 몹 종류를 한국어로 변환
        $mobTypeMap = [
            "소" => "cow",
            "닭" => "chicken",
            "돼지" => "pig",
            "양" => "sheep",
            "무시룸" => "mooshroom",
            "박쥐" => "bat",
            "거미" => "spider",
            "동굴거미" => "cavespider",
            "크리퍼" => "creeper",
            "엔더맨" => "enderman",
            "엔더진" => "endermite",
            "슬라임" => "slime",
            "철골렘" => "irongolem",
            "눈골렘" => "snowgolem"
        ];

        $mobType = strtolower($args[0]);
        // 한국어 입력일 경우 영어로 변환
        $mobType = $mobTypeMap[$mobType] ?? $mobType;

        $spawnRate = isset($args[1]) ? (int) $args[1] : 30;
        $maxMobs = isset($args[2]) ? (int) $args[2] : 5;

        // 유효한 몹 타입 목록
        $validTypes = [
            "cow", "chicken", "pig", "sheep", "mooshroom",
            "bat",
            "spider", "cavespider", "creeper", "enderman", "endermite", "slime",
            "irongolem", "snowgolem"
        ];

        // 유효하지 않은 몹 타입 체크
        if (!in_array($mobType, $validTypes)) {
            $sender->sendMessage(TextFormat::RED . "알 수 없는 몹 종류: $mobType");
            $sender->sendMessage(TextFormat::YELLOW . "사용 가능한 몹 종류: " . implode(", ", [
                    "소", "닭", "돼지", "양", "무시룸",
                    "박쥐",
                    "거미", "동굴거미", "크리퍼", "엔더맨", "엔더진", "슬라임",
                    "철골렘", "눈골렘"
                ]));
            return false;
        }

        // 스포너 설정 모드 시작 (연속 생성 모드)
        $this->plugin->getSpawnerListener()->startContinuousSpawnerSetup($sender->getName(), $mobType, $spawnRate, $maxMobs);

        $sender->sendMessage(TextFormat::GREEN . "연속 몹 스포너 설정 모드가 활성화되었습니다. 몹 스포너를 생성하려면 블록을 클릭하세요.");
        $sender->sendMessage(TextFormat::YELLOW . "선택된 몹: $mobType");
        $sender->sendMessage(TextFormat::YELLOW . "/몹스포너취소 명령어로 설정 모드를 종료할 수 있습니다.");

        return true;
    }
}