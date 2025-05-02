<?php
declare(strict_types=1);

namespace IvanCraft623\MobPlugin\spawner;

use IvanCraft623\MobPlugin\MobPlugin;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;

class SpawnerHologram {
    private MobPlugin $plugin;
    private ?int $textEntityId = null;
    private array $holograms = [];
    private array $spawnerInfoCache = [];
    private array $activeHologramPlayers = [];

    public function __construct(MobPlugin $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * 모든 스포너 홀로그램을 업데이트합니다.
     * 이 메서드는 이제 활성화된 홀로그램이 있는 경우에만 정보를 업데이트합니다.
     */
    public function updateAllHolograms(): void {
        $spawnerManager = $this->plugin->getSpawnerManager();
        if ($spawnerManager === null) {
            return;
        }

        // 모든 플레이어의 홀로그램 업데이트 - 활성화된 홀로그램만
        foreach ($this->holograms as $playerName => $holograms) {
            $player = $this->plugin->getServer()->getPlayerExact($playerName);
            if ($player === null) {
                unset($this->holograms[$playerName]);
                continue;
            }

            // 각 홀로그램의 정보만 업데이트
            foreach ($holograms as $entityId => $hologramData) {
                $spawnerId = $hologramData['spawnerId'];
                $info = $this->updateSpawnerInfo($spawnerId);

                if ($info !== null) {
                    // 홀로그램 텍스트 업데이트
                    $metadata = new EntityMetadataCollection();
                    $metadata->setString(EntityMetadataProperties::NAMETAG, $info);

                    $pk = SetActorDataPacket::create(
                        $entityId,
                        $metadata->getAll(),
                        new PropertySyncData([], []),
                        0
                    );
                    $player->getNetworkSession()->sendDataPacket($pk);

                    // 캐시 업데이트
                    $this->holograms[$playerName][$entityId]['text'] = $info;
                }
            }
        }
    }

    /**
     * 특정 플레이어의 홀로그램을 업데이트합니다.
     */
    private function updateHologramsForPlayer(Player $player): void {
        $playerName = $player->getName();
        $playerPos = $player->getPosition();
        $world = $player->getWorld();

        // 이전 홀로그램 제거
        if (isset($this->holograms[$playerName])) {
            foreach ($this->holograms[$playerName] as $entityId => $data) {
                $this->removeHologram($player, $entityId);
            }
        }

        // 새 홀로그램 배열 초기화
        $this->holograms[$playerName] = [];

        // 스포너 매니저 가져오기
        $spawnerManager = $this->plugin->getSpawnerManager();
        if ($spawnerManager === null) {
            return;
        }

        // 근처 스포너 검색
        foreach ($spawnerManager->getAllSpawners() as $spawnerId => $spawner) {
            if ($spawner['world'] !== $world->getFolderName()) {
                continue; // 다른 월드의 스포너 건너뛰기
            }

            $spawnerPos = new Position((float)$spawner['x'], (float)$spawner['y'], (float)$spawner['z'], $world);
            $distance = $playerPos->distance($spawnerPos);

            // 20블록 이내의 스포너만 표시
            if ($distance <= 20) {
                $info = $this->spawnerInfoCache[$spawnerId] ?? $this->updateSpawnerInfo($spawnerId);
                if ($info !== null) {
                    $this->showHologram($player, $spawnerPos->add(0.5, 1.5, 0.5), $info, $spawnerId);
                }
            }
        }
    }

    /**
     * 스포너 정보를 업데이트합니다.
     */
    private function updateSpawnerInfo(int $spawnerId): ?string {
        $spawnerManager = $this->plugin->getSpawnerManager();
        if ($spawnerManager === null) {
            return null;
        }

        $spawner = $spawnerManager->getSpawner($spawnerId);
        if ($spawner === null) {
            return null;
        }

        // 스폰 주기 시간을 그대로 사용
        $timeLeft = $spawnerManager->getTimeUntilNextSpawn($spawnerId) ?? $spawner['spawn_rate'];

        $mobType = $spawner['type'];
        $currentCount = count($spawner['spawned_entities'] ?? []);
        $maxMobs = $spawner['max_mobs'];
        $spawnRate = $spawner['spawn_rate'];

        // 한국어 몹 이름 변환
        $koreanMobNames = [
            "cow" => "소",
            "chicken" => "닭",
            "pig" => "돼지",
            "sheep" => "양",
            "mooshroom" => "무시룸",
            "bat" => "박쥐",
            "spider" => "거미",
            "cavespider" => "동굴거미",
            "creeper" => "크리퍼",
            "enderman" => "엔더맨",
            "endermite" => "엔더진",
            "slime" => "슬라임",
            "irongolem" => "철골렘",
            "snowgolem" => "눈골렘"
        ];

        $koreanMobName = $koreanMobNames[$mobType] ?? $mobType;

        // 홀로그램에 표시할 텍스트
        $info = "§f종류: §a" . $koreanMobName . "\n" .
            "§f남은 시간: §a" . $timeLeft . "초";

        // 캐시에 저장
        $this->spawnerInfoCache[$spawnerId] = $info;

        return $info;
    }

    /**
     * 플레이어에게 홀로그램을 보여줍니다.
     * @return int 생성된 엔티티 ID
     */
    private function showHologram(Player $player, Vector3 $position, string $text, int $spawnerId): int {
        $playerName = $player->getName();

        // 고유한 엔티티 ID 생성
        $entityId = Entity::nextRuntimeId();

        // 홀로그램 데이터 저장
        if (!isset($this->holograms[$playerName])) {
            $this->holograms[$playerName] = [];
        }

        $this->holograms[$playerName][$entityId] = [
            'position' => $position,
            'text' => $text,
            'spawnerId' => $spawnerId
        ];

        // 아머 스탠드 엔티티 생성 (나머지 코드 동일)
        $pk = AddActorPacket::create(
            $entityId,               // actorUniqueId
            $entityId,               // actorRuntimeId
            EntityIds::XP_ORB,       // type
            $position, // position
            null,                    // motion
            0.0,                    // pitch
            0.0,                    // yaw
            0.0,                    // headYaw
            0.0,                    // bodyYaw
            [],                      // attributes
            [
                EntityMetadataProperties::FLAGS => new LongMetadataProperty(1 << EntityMetadataFlags::NO_AI),
                EntityMetadataProperties::SCALE => new FloatMetadataProperty(0.01),
                EntityMetadataProperties::NAMETAG => new StringMetadataProperty($text),
                EntityMetadataProperties::ALWAYS_SHOW_NAMETAG => new ByteMetadataProperty(1),
            ],
            new PropertySyncData([], []),
            [] // links
        );
        $player->getNetworkSession()->sendDataPacket($pk);

        return $entityId;
    }

    /**
     * 홀로그램을 제거합니다.
     */
    private function removeHologram(Player $player, int $entityId): void {
        $pk = RemoveActorPacket::create($entityId);
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    /**
     * 플레이어가 스포너 블록을 우클릭했을 때 호출됩니다.
     * 월드 보호 설정에 관계없이 클릭한 스포너의 정보만 표시합니다.
     */
    public function onSpawnerInteract(Player $player, Position $position): bool {
        // 스포너 매니저 가져오기
        $spawnerManager = $this->plugin->getSpawnerManager();
        if ($spawnerManager === null) {
            return false;
        }

        // 스포너 ID 찾기
        $spawnerId = $this->findSpawnerIdByPosition(
            $position->getFloorX(),
            $position->getFloorY(),
            $position->getFloorZ(),
            $position->getWorld()->getFolderName()
        );

        if ($spawnerId === null) {
            return false;
        }

        $playerName = $player->getName();

        // 고유한 식별자 생성 - 스포너의 위치와 월드를 결합
        $spawnerKey = $position->getFloorX() . ":" .
            $position->getFloorY() . ":" .
            $position->getFloorZ() . ":" .
            $position->getWorld()->getFolderName();

        // 이전 홀로그램 찾기 및 제거
        if (isset($this->holograms[$playerName])) {
            foreach ($this->holograms[$playerName] as $entityId => $data) {
                if (isset($data['spawnerKey']) && $data['spawnerKey'] === $spawnerKey) {
                    // 이 스포너의 기존 홀로그램 제거
                    $this->removeHologram($player, $entityId);
                    unset($this->holograms[$playerName][$entityId]);
                }
            }
        }

        // 새 홀로그램 배열 초기화 (없을 경우)
        if (!isset($this->holograms[$playerName])) {
            $this->holograms[$playerName] = [];
        }

        // 클릭한 스포너의 정보 표시
        $info = $this->updateSpawnerInfo($spawnerId);
        if ($info !== null) {
            $entityId = $this->showHologram($player, $position->add(0.5, 1.5, 0.5), $info, $spawnerId);

            // 스포너 키 저장
            $this->holograms[$playerName][$entityId]['spawnerKey'] = $spawnerKey;

            // 디버그 메시지
            $this->plugin->getLogger()->debug("Created hologram ID: $entityId for player $playerName at $spawnerKey");

            // 5초 후 홀로그램 자동 숨김
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
                function() use ($playerName, $entityId, $spawnerKey): void {
                    $player = $this->plugin->getServer()->getPlayerExact($playerName);
                    if ($player !== null && isset($this->holograms[$playerName][$entityId])) {
                        $this->plugin->getLogger()->debug("Removing hologram ID: $entityId for player $playerName at $spawnerKey");
                        $this->removeHologram($player, $entityId);
                        unset($this->holograms[$playerName][$entityId]);

                        // 플레이어의 홀로그램이 모두 제거되면 배열도 제거
                        if (empty($this->holograms[$playerName])) {
                            unset($this->holograms[$playerName]);
                        }
                    }
                }
            ), 5 * 20); // 5초 후 (20틱 = 1초)
        }

        return true;
    }

    /**
     * 위치로 스포너 ID를 찾습니다.
     */
    private function findSpawnerIdByPosition(int $x, int $y, int $z, string $worldName): ?int {
        $spawnerManager = $this->plugin->getSpawnerManager();
        if ($spawnerManager === null) {
            return null;
        }

        $spawners = $spawnerManager->getAllSpawners();

        foreach ($spawners as $id => $spawner) {
            if ($spawner['x'] === $x &&
                $spawner['y'] === $y &&
                $spawner['z'] === $z &&
                $spawner['world'] === $worldName) {
                return (int) $id;
            }
        }

        return null;
    }
}