<?php
namespace CorePvP\Map;

use CorePvP\Main;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\scheduler\ClosureTask;

class MapManager {
    private Main $plugin;
    public array $votes = [];

    public const MATCH_MAPS = [
        "DesertTemple", "Nature", "oasis", "Canyon", "Cherokee", "Coastal",
        "Eldor", "Fall", "JokerCity", "Lush", "SkyCastle", "Snow home",
        "SnowIce", "WoodField", "Woodfieldv2", "WoodSky"
    ];

    // ★ 諸悪の根源だった架空のゴミ配列($coords)は完全に削除しました

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function loadAllWorlds(): void {
        $wm = $this->plugin->getServer()->getWorldManager();
        if (!$wm->isWorldLoaded("NEWHUB-SPRING")) {
            $wm->loadWorld("NEWHUB-SPRING", true);
        }
        foreach (self::MATCH_MAPS as $map) {
            if (!$wm->isWorldLoaded($map)) {
                $wm->loadWorld($map, true); 
            }
            $world = $wm->getWorldByName($map);
            if ($world !== null) {
                $world->save(true);
                $wm->unloadWorld($world);
            }
        }
    }

    public function getMapCoord(string $mapName, string $key, \pocketmine\world\World $w): Position {
        // ★ マップ制作者（ねらくん様）が設定した本来のリス地をそのまま取得
        $spawn = $w->getSpawnLocation();
        
        // ★ 以前完璧に動いていた通り、コアの位置だけリス地から±10ずらす
        if (strpos($key, "red_core") !== false) {
            return new Position($spawn->getFloorX() + 10, $spawn->getFloorY(), $spawn->getFloorZ(), $w);
        }
        if (strpos($key, "blue_core") !== false) {
            return new Position($spawn->getFloorX() - 10, $spawn->getFloorY(), $spawn->getFloorZ(), $w);
        }
        
        // ★ スポーン地点はねらくん様の設定そのまま
        return clone $spawn;
    }

    public function prepareArena(string $mapName, int $arenaId): string {
        $server = $this->plugin->getServer();
        $worldManager = $server->getWorldManager();
        
        // Windowsのファイル破損対策（ユニーク名生成）は残しています
        $uniqueId = substr(str_shuffle("abcdefghijklmnopqrstuvwxyz0123456789"), 0, 5);
        $copyName = "copy_{$mapName}_{$arenaId}_{$uniqueId}";

        $this->copyDirectory(
            $server->getDataPath() . "worlds/" . $mapName,
            $server->getDataPath() . "worlds/" . $copyName
        );

        $worldManager->loadWorld($copyName);
        return $copyName;
    }

    public function destroyArena(string $copyName): void {
        $server = $this->plugin->getServer();
        $wm = $server->getWorldManager();
        if ($wm->isWorldLoaded($copyName)) {
            $world = $wm->getWorldByName($copyName);
            if ($world !== null) {
                $wm->unloadWorld($world);
            }
        }
        
        $dir = $server->getDataPath() . "worlds/" . $copyName;
        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($dir): void {
            $this->deleteDirectory($dir);
        }), 100); 
    }

    public function teleportToHub(Player $player): void {
        $w = $this->plugin->getServer()->getWorldManager()->getWorldByName("NEWHUB-SPRING");
        if ($w) {
            $player->teleport(new Position(267, 71, 248, $w));
        } else {
            $player->teleport($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
        }
    }

    public function teleportToGame(Player $player, string $copyMapName, string $team): void {
        $w = $this->plugin->getServer()->getWorldManager()->getWorldByName($copyMapName);
        if ($w) {
            $originalMapName = explode("_", $copyMapName)[1]; 
            $pos = $this->getMapCoord($originalMapName, $team . "_spawn", $w);
            $w->loadChunk($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4);
            $player->teleport($pos);
        } else {
            $player->sendMessage("§c[エラー] マップが読み込めませんでした。");
        }
    }

    public function getCorePosition(string $copyMapName, string $team): ?Position {
        $w = $this->plugin->getServer()->getWorldManager()->getWorldByName($copyMapName);
        if ($w === null) return null;
        
        $originalMapName = explode("_", $copyMapName)[1]; 
        return $this->getMapCoord($originalMapName, $team . "_core", $w);
    }

    public function addVote(string $playerName, string $mapName): void {
        $this->votes[$playerName] = $mapName;
    }

    public function decideMap(): string {
        if (empty($this->votes)) {
            return self::MATCH_MAPS[array_rand(self::MATCH_MAPS)];
        }
        $counts = array_count_values($this->votes);
        arsort($counts);
        return array_key_first($counts);
    }

    public function resetVotes(): void {
        $this->votes = [];
    }

    private function copyDirectory(string $src, string $dst): void {
        if (!is_dir($src)) return;
        @mkdir($dst, 0777, true);
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->copyDirectory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    private function deleteDirectory(string $dir): void {
        if (!file_exists($dir)) return;
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dir);
    }
}