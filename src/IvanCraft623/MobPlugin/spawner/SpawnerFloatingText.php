<?php
declare(strict_types=1);

namespace IvanCraft623\MobPlugin\spawner;

use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

use function microtime;

final class SpawnerFloatingText {
    private ?int $textEntityId = null;

    public function __construct(
        private readonly Player $player,
        private Position $spawnerPos,
        private array $spawnerData,
        private float $expireAt
    ) {}

    /** @return bool true if the text should be updated again, false if the text should be removed */
    public function onUpdate() : bool {
        // 플레이어 연결 상태와 월드 확인
        if (!$this->player->isConnected() || $this->player->getWorld() !== $this->spawnerPos->getWorld()) {
            return false;
        }

        // 만료 시간 체크
        if ($this->expireAt <= microtime(true)) {
            return false;
        }

        // 스포너 정보 텍스트 생성
        $nameTag = TextFormat::BOLD . TextFormat::BLUE . "[스포너 #{$this->spawnerData['id']}] " .
            TextFormat::WHITE . $this->spawnerData['type'];
        $nameTag .= "\n" . TextFormat::YELLOW . "스폰율: " . TextFormat::WHITE . "{$this->spawnerData['spawn_rate']}초";
        $nameTag .= "\n" . TextFormat::YELLOW . "최대 몹: " . TextFormat::WHITE . "{$this->spawnerData['max_mobs']}마리";

        // 텍스트 엔티티 생성 또는 업데이트
        if ($this->textEntityId === null) {
            $this->textEntityId = Entity::nextRuntimeId();
            $this->player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
                $this->textEntityId,
                $this->textEntityId,
                EntityIds::XP_ORB,
                $this->spawnerPos->add(0.5, 1.5, 0.5), // 블록 위 1.5 높이
                null,
                0,
                0,
                0,
                0,
                [],
                [
                    EntityMetadataProperties::FLAGS => new LongMetadataProperty(1 << EntityMetadataFlags::NO_AI),
                    EntityMetadataProperties::SCALE => new FloatMetadataProperty(0.01),
                    EntityMetadataProperties::NAMETAG => new StringMetadataProperty($nameTag),
                    EntityMetadataProperties::ALWAYS_SHOW_NAMETAG => new ByteMetadataProperty(1),
                ],
                new PropertySyncData([], []),
                []
            ));
        } else {
            $this->player->getNetworkSession()->sendDataPacket(SetActorDataPacket::create(
                $this->textEntityId,
                [
                    EntityMetadataProperties::NAMETAG => new StringMetadataProperty($nameTag)
                ],
                new PropertySyncData([], []),
                0
            ));
        }
        return true;
    }

    public function remove() : void {
        if ($this->textEntityId !== null && $this->player->isConnected()) {
            $this->player->getNetworkSession()->sendDataPacket(RemoveActorPacket::create($this->textEntityId));
            $this->textEntityId = null;
        }
    }

    public function getSpawnerPos() : Position {
        return $this->spawnerPos;
    }

    public function setSpawnerPos(Position $spawnerPos, array $spawnerData) : void {
        $this->spawnerPos = $spawnerPos;
        $this->spawnerData = $spawnerData;

        if ($this->textEntityId !== null) {
            $this->remove();
            $this->onUpdate();
        }
    }

    public function getExpireAt() : float {
        return $this->expireAt;
    }

    public function setExpireAt(float $expireAt) : void {
        $this->expireAt = $expireAt;
    }
}