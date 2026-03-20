<?php
namespace CorePvP\Map;

use CorePvP\Main;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\Server;

class MapManager {
    private Main $plugin;
    public array $votes = [];

    // ★ あなたが厳選した16個の試合用マップリスト
    public const MATCH_MAPS = [
        "desertTemple", "Nature", "oasis", "canyon", "cherokee", "coastal",
        "Eldor", "Fall", "JokerCity", "Lush", "SkyCastle", "Snow home",
        "SnowIce", "WoodSky", "WoodField", "Woodfieldv2"
    ];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    // ロビー(NEWHUB-SPRING)の読み込み
    public function loadAllWorlds(): void {
        $wm = $this->plugin->getServer()->getWorldManager();
        if (!$wm->isWorldLoaded("NEWHUB-SPRING")) {
            $wm->loadWorld("NEWHUB-SPRING");
        }
    }

    // --- ワールド複製システム（仮想アリーナ生成） ---
    // 試合開始時に呼ばれ、元のマップを汚さないようにコピー（例: copy_desertTemple_1）を作ります
    public function prepareArena(string $mapName, int $arenaId): string {
        $server = $this->plugin->getServer();
        $worldManager = $server->getWorldManager();
        $copyName = "copy_{$mapName}_{$arenaId}";

        // 古いコピーが残っていれば削除
        if ($worldManager->isWorldLoaded($copyName)) {
            $worldManager->unloadWorld($worldManager->getWorldByName($copyName));
        }
        $this->deleteDirectory($server->getDataPath() . "worlds/" . $copyName);

        // オリジナルからコピーを作成して読み込み
        $this->copyDirectory(
            $server->getDataPath() . "worlds/" . $mapName,
            $server->getDataPath() . "worlds/" . $copyName
        );

        $worldManager->loadWorld($copyName);
        return $copyName; // コピーしたマップの名前を返す
    }

    // 試合終了時にコピーしたマップを完全に消去する
    public function destroyArena(string $copyName): void {
        $server = $this->plugin->getServer();
        $wm = $server->getWorldManager();
        if ($wm->isWorldLoaded($copyName)) {
            $wm->unloadWorld($wm->getWorldByName($copyName));
        }
        $this->deleteDirectory($server->getDataPath() . "worlds/" . $copyName);
    }

    // --- テレポートシステム ---
    public function teleportToHub(Player $player): void {
        $w = $this->plugin->getServer()->getWorldManager()->getWorldByName("NEWHUB-SPRING");
        if ($w) {
            // 先ほど特定したロビーの固定座標！
            $player->teleport(new Position(267, 71, 248, $w));
        } else {
            $player->teleport($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn());
        }
    }

    public function teleportToWaiting(Player $player): void {
        // CorePvPはロビー内でカウントダウンを行うため、Hubと同じ場所に転送します
        $this->teleportToHub($player);
    }

    public function teleportToGame(Player $player, string $copyMapName, string $team): void {
        $w = $this->plugin->getServer()->getWorldManager()->getWorldByName($copyMapName);
        if ($w) {
            // 各マップの正確なチーム座標が決まるまでは、全員ミッド(SafeSpawn)に飛ばします
            $spawn = $w->getSafeSpawn();
            // 奈落(Y=0以下)の場合は上空へ補正
            $y = $spawn->getY() > 0 ? $spawn->getY() : 100;
            $player->teleport(new Position($spawn->getX(), $y, $spawn->getZ(), $w));
        }
    }

    public function getCorePosition(string $copyMapName, string $team): ?Position {
        $w = $this->plugin->getServer()->getWorldManager()->getWorldByName($copyMapName);
        if ($w === null) return null;
        
        // とりあえずミッド付近にコアを仮置きします（後で正確な座標が分かったら書き換えます）
        $spawn = $w->getSafeSpawn();
        $offsetX = ($team === "red") ? 5 : -5;
        $y = $spawn->getY() > 0 ? $spawn->getY() : 100;
        return new Position($spawn->getX() + $offsetX, $y, $spawn->getZ(), $w);
    }

    // --- 投票システム ---
    public function addVote(string $playerName, string $mapName): void {
        $this->votes[$playerName] = $mapName;
    }

    public function decideMap(): string {
        // 誰も投票しなかった場合は16マップの中からランダム
        if (empty($this->votes)) {
            return self::MATCH_MAPS[array_rand(self::MATCH_MAPS)];
        }
        // 票を集計して1番多いものを返す
        $counts = array_count_values($this->votes);
        arsort($counts);
        return array_key_first($counts);
    }

    public function resetVotes(): void {
        $this->votes = [];
    }

    // --- ユーティリティ（ファイル操作用・触らなくてOKです） ---
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