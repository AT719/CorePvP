<?php
namespace CorePvP\Game;

use CorePvP\Main;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\player\GameMode;
use pocketmine\math\Vector3;
use pocketmine\world\Position;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\world\particle\HugeExplosionParticle;
use pocketmine\scheduler\ClosureTask;
use pocketmine\entity\projectile\Arrow;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\form\Form;

class GameManager implements Listener {
    private Main $plugin;

    public const STATE_LOBBY = 0;
    public const STATE_WAITING = 1;
    public const STATE_LOADING = 2; 
    public const STATE_GAME = 3;
    public const STATE_ENDING = 4;

    public int $gameState = self::STATE_LOBBY;
    public int $currentPhase = 1;
    public int $gameTime = 0;
    public int $endingTime = 0;
    public int $loadingTime = 0;

    public int $lobbyTime = 60;

    public string $currentMap = "NEWHUB"; 
    public string $copiedMapName = ""; 

    public array $queue = [];
    public array $teams = [];
    public array $teamData = [
        "red" => ["hp" => 100, "core" => null],
        "blue" => ["hp" => 100, "core" => null]
    ];

    public array $savedInventories = [];
    private array $portalCooldown = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);

        $plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->gameLoop();
        }), 20);
    }

    public function getPlayerTeam(string $name): string {
        return $this->teams[$name] ?? "red";
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) return false;

        switch ($command->getName()) {
            case "hub": 
                $this->sendToHub($sender);
                return true;
            case "kit": 
                $sender->sendMessage("§cKitコマンドは無効化されています。");
                return true;
            case "forcestart":
                if (!$sender->hasPermission("corepvp.command.forcestart")) return false;
                if (count($this->queue) < 1) { 
                    $this->queue[$sender->getName()] = true;
                    $sender->sendMessage("§e[ONPU] §f強制参加・開始します！"); 
                }
                $this->startLoading();
                return true;
            case "stat": 
                $data = $this->plugin->getPlayerData($sender->getName());
                $levelData = $this->plugin->getLevelData($data["exp"]);
                $sender->sendMessage("§fMoney: " . $data["money"] . " | Rank: " . $levelData["rankFormat"]); 
                return true;
            case "shop": 
                $this->plugin->formManager->sendShopForm($sender);
                return true;
            case "givemoney":
                if (count($args) < 2) {
                    $sender->sendMessage("§c使い方: /givemoney [名前] [金額]");
                    return true;
                }
                $targetPlayer = $this->plugin->getServer()->getPlayerByPrefix($args[0]);
                $targetName = $targetPlayer ? $targetPlayer->getName() : $args[0];
                $amount = (int)$args[1];

                if ($amount <= 0) {
                    $sender->sendMessage("§c正しい金額を入力してください。");
                    return true;
                }
                $senderData = $this->plugin->getPlayerData($sender->getName());
                if ($senderData["money"] < $amount) {
                    $sender->sendMessage("§cお金が足りません！");
                    return true;
                }
                
                $this->plugin->addStat($sender->getName(), "money", -$amount);
                $this->plugin->addStat($targetName, "money", $amount);
                $sender->sendMessage("§a[ONPU] §f{$targetName} に {$amount} Money 送金しました。");
                
                if ($targetPlayer) {
                    $targetPlayer->sendMessage("§a[ONPU] §f{$sender->getName()} から {$amount} Money 受け取りました！");
                    $this->playSound($targetPlayer, "random.levelup", 1.0, 2.0);
                }
                return true;
            case "coordtool":
                if (!$sender->hasPermission("corepvp.command.coordtool")) {
                    $sender->sendMessage("§c権限がありません。");
                    return true;
                }
                $tool = VanillaItems::WOODEN_HOE()->setCustomName("§d座標確認ツール");
                $sender->getInventory()->addItem($tool);
                $sender->sendMessage("§a[ONPU] §f座標確認ツールを取得しました。調べたいブロックを叩いてください。");
                return true;
            case "setuptp":
                if (!$sender->hasPermission("corepvp.command.setuptp")) {
                    $sender->sendMessage("§c権限がありません。");
                    return true;
                }
                if (count($args) < 1) {
                    $sender->sendMessage("§c使い方: /setuptp [マップ名] (例: /setuptp WoodSky)");
                    return true;
                }
                
                $mapName = $args[0];
                $wm = $this->plugin->getServer()->getWorldManager();
                
                if (!$wm->isWorldLoaded($mapName)) {
                    if (!$wm->loadWorld($mapName)) {
                        $sender->sendMessage("§c[エラー] マップ '{$mapName}' が見つかりません。大文字・小文字を確認してください。");
                        return true;
                    }
                }
                
                $w = $wm->getWorldByName($mapName);
                
                // ★修正：ねらくん様が設定した本来のリス地を素直に取得する
                $spawn = $w->getSpawnLocation();
                $w->loadChunk($spawn->getFloorX() >> 4, $spawn->getFloorZ() >> 4);
                
                // そこへテレポート
                $sender->teleport($spawn);
                $sender->setGamemode(GameMode::CREATIVE());
                
                // 奈落落下防止の飛行付与
                $sender->setAllowFlight(true);
                $sender->setFlying(true); 
                
                $tool = VanillaItems::WOODEN_HOE()->setCustomName("§d座標確認ツール");
                $sender->getInventory()->addItem($tool);
                
                $sender->sendMessage("§a[ONPU] §f{$mapName} の初期リス地にテレポートしました！");
                return true;
        } 
        return false;
    }

    public function onChat(PlayerChatEvent $event): void {
        $p = $event->getPlayer();
        $name = $p->getName();
        
        if ($this->plugin->punishManager->isMuted($name)) {
            $p->sendMessage("§c[警告] あなたはルール違反によりチャットが制限されています。");
            $event->cancel();
            return;
        }

        $data = $this->plugin->getPlayerData($name);
        $levelData = $this->plugin->getLevelData($data["exp"]);
        $rank = $levelData["rankFormat"];

        $warn = $this->plugin->punishManager->hasWarn($name) ? "§c⚠️§r " : "";

        $team = "";
        if (isset($this->teams[$name])) {
            $team = $this->teams[$name] === "red" ? "§c[Red] " : "§9[Blue] ";
        }

        $format = $warn . $rank . " " . $team . "§f" . $name . " §8» §f" . $event->getMessage();
        $event->setFormat($format);
    }

    private function gameLoop(): void {
        $now = time();

        if ($this->gameState === self::STATE_WAITING) {
            $count = count($this->queue);
            if ($count === 0) {
                $this->gameState = self::STATE_LOBBY;
                $this->lobbyTime = 60;
            } else { 
                $this->lobbyTime--;
                $winningMap = $this->plugin->mapManager->decideMap();

                foreach ($this->queue as $name) {
                    $p = $this->plugin->getServer()->getPlayerExact($name);
                    if ($p) {
                        $p->sendTitle("§e開始まで: §a" . $this->lobbyTime . "秒", "§fマップ: §d" . $winningMap, 0, 25, 0);
                        if (in_array($this->lobbyTime, [60, 30, 10, 5, 4, 3, 2, 1])) {
                            $p->sendMessage("§e[CorePvP] §f試合開始まで残り §a{$this->lobbyTime}秒 §fです！");
                            $this->playSound($p, "random.click", 1.0, 1.0);
                        }
                    }
                }
                if ($this->lobbyTime <= 0) $this->startLoading();
            }
        }

        if ($this->gameState === self::STATE_LOADING) {
            $this->loadingTime++;
            foreach ($this->plugin->getServer()->getOnlinePlayers() as $p) {
                if (isset($this->teams[$p->getName()])) {
                    $p->sendPopup("§eマップ転送中... §fあと " . (6 - $this->loadingTime) . "秒");
                }
            }
            if ($this->loadingTime >= 5) {
                $this->startGame();
            }
        }

        if ($this->gameState === self::STATE_GAME) {
            $this->gameTime++;
            if ($this->gameTime === 600 && $this->currentPhase === 1) {
                $this->currentPhase = 2;
                $this->plugin->getServer()->broadcastMessage("§c§l[Phase 2] §r§eコアへの攻撃とダイヤ採掘が可能になりました！");
                $this->broadcastSound("random.anvil_land");
            }
            if ($this->gameTime === 1200 && $this->currentPhase === 2) {
                $this->currentPhase = 3;
                $this->plugin->getServer()->broadcastMessage("§4§l[Phase 3] §r§cコアダメージが2倍になります！");
                $this->broadcastSound("random.anvil_land");
            }
        }

        if ($this->gameState === self::STATE_ENDING) {
            $this->endingTime--;
            foreach ($this->plugin->getServer()->getOnlinePlayers() as $p) {
                if (mt_rand(0, 2) === 0) {
                    $pos = $p->getPosition()->add(mt_rand(-3, 3), mt_rand(1, 4), mt_rand(-3, 3));
                    $p->getWorld()->addParticle($pos, new HugeExplosionParticle());
                    $this->playSound($p, "random.explode", 0.5, 1.5);
                }
            }
            if ($this->endingTime <= 0) {
                $this->finalizeGame();
            }
        }

        foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
            $this->updateScoreboard($player);
            $name = $player->getName();
            if (isset($this->plugin->kitManager->cooldowns[$name])) {
                foreach ($this->plugin->kitManager->cooldowns[$name] as $kit => $endTime) {
                    if ($now >= $endTime) {
                        $player->sendMessage("§a[ONPU] §lスキル再使用可能！");
                        unset($this->plugin->kitManager->cooldowns[$name][$kit]);
                    }
                }
            }
        }
    }

    public function startLoading(): void {
        $this->gameState = self::STATE_LOADING;
        $this->loadingTime = 0;
        $this->teamData["red"]["hp"] = 100;
        $this->teamData["blue"]["hp"] = 100;

        $this->currentMap = $this->plugin->mapManager->decideMap();
        $this->copiedMapName = $this->plugin->mapManager->prepareArena($this->currentMap, 1);

        $players = array_keys($this->queue);
        shuffle($players);
        
        $i = 0;
        foreach ($players as $name) {
            $p = $this->plugin->getServer()->getPlayerExact($name);
            if (!$p) continue;

            $team = ($i % 2 === 0) ? "red" : "blue";
            $this->teams[$name] = $team;
            $i++;
            
            $this->plugin->mapManager->teleportToGame($p, $this->copiedMapName, $team);
            $p->setGamemode(GameMode::SURVIVAL());
            
            $color = ($team === "red") ? "§cRED" : "§9BLUE";
            $p->sendTitle("§l" . $color . " TEAM", "§fMap: " . ucfirst($this->currentMap), 10, 100, 20);
            
            $this->plugin->kitManager->playerKit[$name] = "None";
            $this->updateScoreboard($p);
        }
    }

    private function startGame(): void {
        $this->gameState = self::STATE_GAME;
        $this->currentPhase = 1;
        $this->gameTime = 0;

        $w = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->copiedMapName);
        
        $this->teamData["red"]["core"] = $this->plugin->mapManager->getCorePosition($this->copiedMapName, "red");
        $this->teamData["blue"]["core"] = $this->plugin->mapManager->getCorePosition($this->copiedMapName, "blue");

        try {
            if($this->teamData["red"]["core"]) $w->setBlock($this->teamData["red"]["core"], VanillaBlocks::END_STONE());
        } catch (\Exception $e) {}

        try {
            if($this->teamData["blue"]["core"]) $w->setBlock($this->teamData["blue"]["core"], VanillaBlocks::END_STONE());
        } catch (\Exception $e) {}

        $this->plugin->getServer()->broadcastMessage("§e[ONPU] §lGame Started! Map: " . ucfirst($this->currentMap));
        $this->broadcastSound("random.levelup");

        foreach ($this->teams as $name => $team) {
            $p = $this->plugin->getServer()->getPlayerExact($name);
            if ($p) {
                $p->sendMessage("§l§aBattle Start!");
                $this->plugin->kitManager->applyKit($p, "default"); 
            }
        }
    }

    private function endGame(string $winningTeam): void {
        $this->gameState = self::STATE_ENDING;
        $this->endingTime = 10;
        $colorCode = ($winningTeam === "red") ? "§c" : "§9";
        $teamName = ($winningTeam === "red") ? "赤" : "青";
        
        foreach ($this->plugin->getServer()->getOnlinePlayers() as $p) {
            $p->sendTitle($colorCode . "§l" . $teamName . "チームの勝利！", "§fGG!", 10, 100, 20);
            $this->playSound($p, "random.totem", 1.0, 1.0);
            $p->setGamemode(GameMode::ADVENTURE());
            $p->getEffects()->clear();
        }
    }

    public function finalizeGame(): void {
        $this->gameState = self::STATE_LOBBY;
        $this->teamData["red"]["hp"] = 100;
        $this->teamData["blue"]["hp"] = 100;
        $this->queue = [];
        $this->teams = [];
        $this->savedInventories = []; 
        $this->plugin->kitManager->playerKit = [];
        $this->plugin->kitManager->activeSkills = [];
        $this->plugin->kitManager->cooldowns = [];
        $this->plugin->mapManager->resetVotes();
        
        foreach ($this->plugin->getServer()->getOnlinePlayers() as $p) {
            $this->sendToHub($p);
        }

        if ($this->copiedMapName !== "") {
            $this->plugin->mapManager->destroyArena($this->copiedMapName);
            $this->copiedMapName = "";
        }
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $p = $event->getPlayer();
        $n = $p->getName();
        $this->plugin->getPlayerData($n); 
        unset($this->teams[$n]);
        unset($this->plugin->kitManager->playerKit[$n]);
        $this->sendToHub($p);
    }

    public function joinMidGame(Player $p): void {
        $name = $p->getName();
        
        if (!isset($this->teams[$name])) {
            $redCount = count(array_filter($this->teams, fn($t) => $t === "red"));
            $blueCount = count(array_filter($this->teams, fn($t) => $t === "blue"));
            $team = ($redCount <= $blueCount) ? "red" : "blue";
            $this->teams[$name] = $team;
            $this->plugin->kitManager->playerKit[$name] = "None";
        } else {
            $team = $this->teams[$name];
        }

        $this->plugin->mapManager->teleportToGame($p, $this->copiedMapName, $team);
        $p->setGamemode(GameMode::SURVIVAL());
        
        $color = ($team === "red") ? "§cRED" : "§9BLUE";
        $p->sendTitle("§l" . $color . " TEAM", "§f戦線復帰！", 10, 60, 20);
        $this->playSound($p, "random.levelup", 1.0, 1.0);

        $p->getInventory()->clearAll();
        $p->getArmorInventory()->clearAll();

        if (isset($this->savedInventories[$name])) {
            $p->getInventory()->setContents($this->savedInventories[$name]["inv"]);
            $p->getArmorInventory()->setContents($this->savedInventories[$name]["armor"]);
            unset($this->savedInventories[$name]);
            $p->sendMessage("§a[ONPU] §f保存されていたアイテムと装備を復元しました！");
        } else {
            $this->plugin->kitManager->applyKit($p, "default");
            $p->sendMessage("§a[ONPU] §fデフォルト装備で途中参加しました！");
        }
        $this->updateScoreboard($p);
    }

    private function sendGameSelectForm(Player $player): void {
        $gm = $this;
        $form = new class($gm) implements Form {
            private GameManager $gm;
            public function __construct(GameManager $gm) { $this->gm = $gm; }
            public function jsonSerialize(): array {
                $btn1 = $this->gm->gameState >= GameManager::STATE_LOADING ? "⚔️ 進行中のCorePvPに途中参加/復帰する" : "⚔️ CorePvP に参加する";
                return [
                    "type" => "form",
                    "title" => "§l§1ゲーム参加メニュー",
                    "content" => "プレイするモードを選択してください。\nCorePvPは投票で選ばれたマップで戦います。",
                    "buttons" => [
                        ["text" => $btn1],
                        ["text" => "🏹 FFA に参加する (準備中)"],
                        ["text" => "❌ 参加をキャンセルする"]
                    ]
                ];
            }
            public function handleResponse(Player $p, $data): void {
                if ($data === null) return; 
                if ($data === 0) {
                    if ($this->gm->gameState >= GameManager::STATE_LOADING) {
                        $this->gm->joinMidGame($p);
                        return;
                    }
                    $this->gm->queue[$p->getName()] = true;
                    $p->sendMessage("§a[参加] §fCorePvPの待機列に参加しました！");
                    if ($this->gm->gameState === GameManager::STATE_LOBBY) {
                        $this->gm->gameState = GameManager::STATE_WAITING;
                    }
                } elseif ($data === 1) {
                    $p->sendMessage("§cFFAは現在開発中です！");
                } elseif ($data === 2) {
                    unset($this->gm->queue[$p->getName()]);
                    $p->sendMessage("§e[キャンセル] §f待機列から抜けました。");
                }
            }
        };
        $player->sendForm($form);
    }

    public function onItemUse(PlayerItemUseEvent $event): void {
        $p = $event->getPlayer();
        $item = $event->getItem();
        if ($item->getTypeId() === VanillaItems::COMPASS()->getTypeId()) {
            $this->sendGameSelectForm($p);
        } elseif ($item->getTypeId() === VanillaItems::PAPER()->getTypeId()) {
            if (!isset($this->queue[$p->getName()])) {
                $p->sendMessage("§c[警告] 投票するには、まずコンパスから「CorePvPに参加」してください！");
                return;
            }
            $this->plugin->formManager->sendVoteForm($p);
        }
    }

    public function onInteract(PlayerInteractEvent $event): void { 
        $p = $event->getPlayer();
        $block = $event->getBlock(); 
        $item = $event->getItem();

        // ★ 座標確認ツール (タップで確認)
        if ($item->getTypeId() === VanillaItems::WOODEN_HOE()->getTypeId() && $item->getName() === "§d座標確認ツール") {
            $pos = $block->getPosition();
            $p->sendMessage("§d[座標確認] X: {$pos->getFloorX()} Y: {$pos->getFloorY()} Z: {$pos->getFloorZ()}");
            $event->cancel();
            return;
        }

        if ($item->getTypeId() === VanillaItems::COMPASS()->getTypeId()) {
            $this->sendGameSelectForm($p);
            return;
        } elseif ($item->getTypeId() === VanillaItems::PAPER()->getTypeId()) {
            if (!isset($this->queue[$p->getName()])) {
                $p->sendMessage("§c[警告] 投票するには、まずコンパスから参加してください！");
                return;
            }
            $this->plugin->formManager->sendVoteForm($p);
            return;
        }

        if ($block->getTypeId() === VanillaBlocks::DIAMOND()->getTypeId()) { 
            if ($this->gameState === self::STATE_GAME && isset($this->teams[$p->getName()])) { 
                $team = $this->teams[$p->getName()];
                $this->plugin->mapManager->teleportToGame($p, $this->copiedMapName, $team);
                $p->setGamemode(GameMode::SURVIVAL()); 
                $this->plugin->kitManager->applyKit($p, "default"); 
                $p->sendMessage("§a[ONPU] §f戦場に復帰しました！"); 
                $this->playSound($p, "random.levelup", 1.0, 1.0); 
                return;
            } 
            if ($this->gameState === self::STATE_GAME) { 
                $p->sendMessage("§c[ONPU] 現在試合中です。");
                return; 
            } 
        } 
        $this->plugin->kitManager->tryActivateSkill($p);
    }

    public function onBreak(BlockBreakEvent $event): void {
        $p = $event->getPlayer();
        $block = $event->getBlock(); 
        $name = $p->getName();
        $item = $event->getItem();

        // ★ 座標確認ツール (破壊アクションでも確認)
        if ($item->getTypeId() === VanillaItems::WOODEN_HOE()->getTypeId() && $item->getName() === "§d座標確認ツール") {
            $pos = $block->getPosition();
            $p->sendMessage("§d[座標確認] X: {$pos->getFloorX()} Y: {$pos->getFloorY()} Z: {$pos->getFloorZ()}");
            $event->cancel();
            return;
        }

        // ロビーでの破壊防止
        if ($this->gameState !== self::STATE_GAME && !isset($this->teams[$name])) { 
            if (!$this->plugin->getServer()->isOp($name)) { 
                $event->cancel();
                $p->sendPopup("§cロビーでの破壊は禁止されています"); 
            } 
            return;
        }

        if ($block->getTypeId() === VanillaBlocks::END_STONE()->getTypeId()) { 
            $this->handleCoreBreak($p, $block, $event);
            return; 
        }

        // ★ 鉱石のフェーズ制限と15秒復活システム
        $blockId = $block->getTypeId();
        $isOre = false;
        $expAmount = 0;
        $dropItem = null;

        if ($blockId === VanillaBlocks::EMERALD_ORE()->getTypeId()) {
            $isOre = true; $expAmount = 30; $dropItem = VanillaItems::EMERALD();
        } elseif ($blockId === VanillaBlocks::DIAMOND_ORE()->getTypeId()) {
            if ($this->currentPhase < 2) {
                $p->sendPopup("§cダイヤ鉱石はPhase 2から採掘可能です！");
                $this->playSound($p, "note.bass", 1.0, 0.5);
                $event->cancel();
                return;
            }
            $isOre = true; $expAmount = 25; $dropItem = VanillaItems::DIAMOND();
        } elseif ($blockId === VanillaBlocks::LAPIS_ORE()->getTypeId()) {
            $isOre = true; $expAmount = 20; $dropItem = VanillaItems::LAPIS_LAZULI()->setCount(6); 
            $p->getXpManager()->addXp(15); 
        } elseif ($blockId === VanillaBlocks::GOLD_ORE()->getTypeId()) {
            $isOre = true; $expAmount = 15; $dropItem = VanillaItems::GOLD_INGOT();
        } elseif ($blockId === VanillaBlocks::IRON_ORE()->getTypeId()) {
            $isOre = true; $expAmount = 10; $dropItem = VanillaItems::IRON_INGOT();
        } elseif ($blockId === VanillaBlocks::COAL_ORE()->getTypeId()) {
            $isOre = true; $expAmount = 5; $dropItem = VanillaItems::COAL();
        }

        if ($isOre) {
            $event->cancel(); 

            if ($dropItem !== null) {
                $p->getInventory()->addItem($dropItem);
            }

            $this->plugin->addStat($name, "exp", $expAmount);
            $p->sendPopup("§a+{$expAmount} XP");
            $this->updateScoreboard($p);

            $pos = $block->getPosition();
            $world = $pos->getWorld();
            $world->setBlock($pos, VanillaBlocks::COBBLESTONE());

            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($world, $pos, $block): void {
                if ($world->isLoaded()) {
                    $world->setBlock($pos, $block);
                }
            }), 300);

            return;
        }

        if (!$this->plugin->getServer()->isOp($name)) { 
            $event->cancel(); 
        }
    }

    private function handleCoreBreak(Player $p, $block, BlockBreakEvent $event): void {
        $targetTeam = null;
        $pos = $block->getPosition();
        
        if ($this->teamData["red"]["core"] !== null && $pos->equals($this->teamData["red"]["core"])) $targetTeam = "red";
        if ($this->teamData["blue"]["core"] !== null && $pos->equals($this->teamData["blue"]["core"])) $targetTeam = "blue";

        if ($targetTeam === null) {
            if (!$this->plugin->getServer()->isOp($p->getName())) $event->cancel();
            return;
        }

        $event->cancel(); 
        $event->setDrops([]); 

        $myTeam = $this->teams[$p->getName()] ?? "";
        if ($myTeam === $targetTeam) {
            $p->sendMessage("§c味方のコアは攻撃できません！");
            return;
        }

        if ($this->currentPhase === 1) {
            $p->sendPopup("§cPhase 1: コアはまだ保護されています！");
            $this->playSound($p, "note.bass", 1.0, 0.5);
            return;
        }

        $damage = ($this->currentPhase === 3) ? 2 : 1;
        $this->teamData[$targetTeam]["hp"] -= $damage;
        $currentHP = $this->teamData[$targetTeam]["hp"];

        $this->broadcastSound("random.anvil_land");
        $teamColor = ($targetTeam === "red") ? "§c赤" : "§9青";
        $p->sendPopup("§eCore Hit! " . $teamColor . "HP: " . $currentHP . " (-" . $damage . ")");
        
        $this->plugin->addStat($p->getName(), "money", 50); 
        $this->plugin->addStat($p->getName(), "exp", 20); 
        $p->sendMessage("§e[ONPU] §fコア破壊ボーナス! +50 Money, +20 XP");

        if ($currentHP <= 0) {
            $this->endGame($myTeam);
        }
    }

    public function onPlace(BlockPlaceEvent $event): void {
        $p = $event->getPlayer();
        $name = $p->getName();
        if ($this->gameState !== self::STATE_GAME && !isset($this->teams[$name])) { 
            if (!$this->plugin->getServer()->isOp($name)) { 
                $event->cancel();
                $p->sendPopup("§cロビーでのブロック設置は禁止されています"); 
            } 
            return;
        }
        if (!$this->plugin->getServer()->isOp($name)) { $event->cancel(); }
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) return;
        if ($this->gameState !== self::STATE_GAME) { $event->cancel(); }
    }

    public function onPvP(EntityDamageByEntityEvent $event): void {
        if ($this->gameState !== self::STATE_GAME) { $event->cancel(); return; }
        $victim = $event->getEntity(); $attacker = $event->getDamager();
        if ($victim instanceof Player && $attacker instanceof Player) {
            if ($victim->getHealth() - $event->getFinalDamage() <= 0) {
                $this->plugin->addStat($attacker->getName(), "kills", 1);
                $this->plugin->addStat($attacker->getName(), "money", 100); 
                $this->plugin->addStat($attacker->getName(), "exp", 50);
                $attacker->sendMessage("§e[ONPU] §fKill! +100 Money, +50 XP"); 
                $this->updateScoreboard($attacker);
            }
            $name = $victim->getName();
            $kitName = $this->plugin->kitManager->getKitName($name);
            if (strtolower($kitName) === "mikawa") { 
                if ($event->getCause() === EntityDamageEvent::CAUSE_PROJECTILE) { $event->setBaseDamage(0);
                $event->cancel(); return; } 
            } 
            if (isset($this->plugin->kitManager->activeSkills[$name]["mikawa"]) && $this->plugin->kitManager->activeSkills[$name]["mikawa"] > time()) { 
                if (mt_rand(0, 1) === 0) { $event->cancel();
                $victim->sendPopup("§b回避！"); return; } 
            } 
            if (isset($this->plugin->kitManager->activeSkills[$name]["assault"]) && $this->plugin->kitManager->activeSkills[$name]["assault"] > time()) { 
                $event->setKnockBack(0);
            }
        }
    }

    // ★ 修正：試合中ならアイテム保持、それ以外なら消す
    public function onDeath(PlayerDeathEvent $event): void {
        $event->setDrops([]);
        $event->setXpDropAmount(0);
        if ($this->gameState === self::STATE_GAME) { 
            $event->setKeepInventory(true); 
        } else {
            $event->setKeepInventory(false); 
        }
    }

    // ★ 修正：試合中以外なら強制的にハブ（ロビー）に復活させる
    public function onRespawn(PlayerRespawnEvent $event): void {
        $p = $event->getPlayer();
        $name = $p->getName();
        if ($this->gameState === self::STATE_GAME && isset($this->teams[$name])) {
            $team = $this->teams[$name];
            $w = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->copiedMapName);
            if ($w) {
                $originalMapName = explode("_", $this->copiedMapName)[1];
                $pos = $this->plugin->mapManager->getMapCoord($originalMapName, $team . "_spawn", $w);
                $event->setRespawnPosition($pos);
            }
            $p->sendMessage("§a[ONPU] §f拠点に復活しました。");
        } else {
            $w = $this->plugin->getServer()->getWorldManager()->getWorldByName("NEWHUB-SPRING");
            $pos = $w ? new Position(267, 71, 248, $w) : $this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn();
            $event->setRespawnPosition($pos);
            
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($p): void {
                if ($p->isOnline()) {
                    $this->sendToHub($p);
                }
            }), 10);
        }
    }

    public function onMove(PlayerMoveEvent $event): void { 
        $p = $event->getPlayer();
        $name = $p->getName(); 
        if ($this->gameState !== self::STATE_GAME) return; 
        if (!isset($this->teams[$name])) return; 
        $block = $p->getWorld()->getBlock($p->getPosition());
        if ($block->getTypeId() === VanillaBlocks::NETHER_PORTAL()->getTypeId()) { 
            if (isset($this->portalCooldown[$name]) && time() < $this->portalCooldown[$name]) return;
            $this->portalCooldown[$name] = time() + 3; 
            $team = $this->teams[$name]; 
            $this->plugin->mapManager->teleportToGame($p, $this->copiedMapName, $team); 
            $this->playSound($p, "mob.endermen.portal", 1.0, 1.0); 
            $p->sendMessage("§a[ONPU] §f拠点のポータルを使用しました。"); 
            $this->plugin->formManager->sendKitForm($p);
        } 
    }

    public function onDrop(PlayerDropItemEvent $event): void { 
        $item = $event->getItem();
        $player = $event->getPlayer(); 
        
        if ($this->gameState !== self::STATE_GAME && !isset($this->teams[$player->getName()])) {
            $event->cancel();
            return;
        }

        if ($item->getNamedTag()->getInt("CorePvP_KitItem", 0) === 1) { 
            if ($item->getNamedTag()->getInt("CorePvP_SkillBook", 0) === 1) { 
                $event->cancel();
                $player->sendPopup("§cスキルブックは捨てられません！"); 
            } else { 
                $event->cancel();
                $player->getInventory()->removeItem($item); $player->sendPopup("§7専用アイテムを破棄しました(消滅)"); 
                $this->playSound($player, "random.fizz", 0.5, 1.5); 
            } 
        } 
    }

    public function onQuit(PlayerQuitEvent $event): void { 
        $name = $event->getPlayer()->getName();
        if (isset($this->queue[$name])) unset($this->queue[$name]); 
    }

    public function onShoot(EntityShootBowEvent $event): void { 
        $entity = $event->getEntity();
        if (!$entity instanceof Player) return; 
        $name = $entity->getName(); 
        $kitName = $this->plugin->kitManager->getKitName($name);
        if (strtolower($kitName) === "archer") { 
            if (isset($this->plugin->kitManager->activeSkills[$name]["archer"]) && $this->plugin->kitManager->activeSkills[$name]["archer"] > time()) { 
                $originalArrow = $event->getProjectile();
                if (!$originalArrow instanceof Arrow) return; 
                $location = $entity->getLocation(); $yawOffsets = [-15, 15];
                foreach ($yawOffsets as $offset) { 
                    $newLocation = clone $location;
                    $newLocation->yaw += $offset; 
                    $rad = deg2rad($newLocation->yaw); $x = -sin($rad) * cos(deg2rad($newLocation->pitch)); $z = cos($rad) * cos(deg2rad($newLocation->pitch)); $y = -sin(deg2rad($newLocation->pitch));
                    $nbt = $originalArrow->saveNBT(); $arrow = new Arrow($newLocation, $entity, $originalArrow->isCritical(), $nbt); 
                    $arrow->setMotion($originalArrow->getMotion()); $arrow->spawnToAll(); $force = $event->getForce();
                    $arrow->setMotion((new Vector3($x, $y, $z))->normalize()->multiply($force * 3)); 
                } 
                $entity->sendPopup("§c>>> 五月雨撃ち！ <<<");
                $this->playSound($entity, "random.bow", 1.0, 1.5); 
            } 
        } 
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void { 
        $packet = $event->getPacket();
        if ($packet instanceof LevelSoundEventPacket && $packet->sound === LevelSoundEvent::ATTACK_NODAMAGE) { 
            $player = $event->getOrigin()->getPlayer();
            if ($player !== null) $this->plugin->kitManager->tryActivateSkill($player); 
        } 
    }

    public function sendToHub(Player $p): void { 
        $name = $p->getName();

        if (isset($this->teams[$name]) && $this->gameState >= self::STATE_LOADING) {
            $this->savedInventories[$name] = [
                "inv" => $p->getInventory()->getContents(),
                "armor" => $p->getArmorInventory()->getContents()
            ];
            $p->sendMessage("§a[ONPU] §f試合中のアイテムを一時保存しました。コンパスから再参加すると復元されます！");
        }

        $this->plugin->mapManager->teleportToHub($p);
        $p->getInventory()->clearAll(); 
        $p->getArmorInventory()->clearAll(); 
        $p->getEffects()->clear(); 
        $p->setGamemode(GameMode::ADVENTURE()); 
        
        $p->getInventory()->setItem(0, VanillaItems::COMPASS()->setCustomName("§aゲーム選択 §7(タップで参加)"));
        $p->getInventory()->setItem(4, VanillaItems::PAPER()->setCustomName("§bマップ投票用紙 §7(タップで開く)"));
        $p->getInventory()->setItem(8, VanillaBlocks::MOB_HEAD()->asItem()->setCustomName("§eステータス確認"));

        $p->sendTitle("§e§lONPU Server", "§fへようこそ！", 10, 60, 20); 
        $this->playSound($p, "random.orb", 1.0, 1.0); 
        $this->initScoreboard($p); 
        if (isset($this->queue[$name])) unset($this->queue[$name]); 

        // ★ 修正：ロビーに戻った時は飛行状態を強制解除
        $p->setAllowFlight(false);
        $p->setFlying(false);
    }

    public function updateScoreboard(Player $player): void {
        $name = $player->getName();
        $data = $this->plugin->getPlayerData($name);
        
        $levelData = $this->plugin->getLevelData($data["exp"]);
        $rank = $levelData["rankFormat"];

        $warn = $this->plugin->punishManager->hasWarn($name) ? "§c⚠️§r " : "";
        $player->setNameTag($warn . $rank . " " . $player->getName());

        $kitName = $this->plugin->kitManager->getKitName($name);
        $team = isset($this->teams[$name]) ? ucfirst($this->teams[$name]) : "None";
        $lines = [
            "§r ",
            "§fname: " . $name,
            "§frank: " . $rank,
            "§fmoney: " . $data["money"],
            "§fkill: " . $data["kills"],
            "§fkit: " . $kitName,
            "§fteam: " . $team
        ];

        if ($this->gameState === self::STATE_GAME || $this->gameState === self::STATE_ENDING || $this->gameState === self::STATE_LOADING) {
            $lines[] = "§e----------------";
            $lines[] = "§cRed HP : " . $this->teamData["red"]["hp"];
            $lines[] = "§9Blue HP: " . $this->teamData["blue"]["hp"];
            if ($this->gameState === self::STATE_ENDING) {
                $lines[] = "§6★ VICTORY! ★";
            } elseif ($this->gameState === self::STATE_LOADING) {
                $lines[] = "§eLoading...";
            } else {
                $lines[] = "§ePhase  : " . $this->currentPhase;
            }
        } elseif ($this->gameState === self::STATE_WAITING) {
            $lines[] = "§e----------------";
            $lines[] = "§7Waiting for game...";
        } else {
            $lines[] = "§e----------------";
            $lines[] = "§7Lobby";
        }

        $pk = new SetScorePacket();
        $pk->type = SetScorePacket::TYPE_CHANGE;
        $pk->entries = [];
        foreach ($lines as $score => $line) {
            $entry = new ScorePacketEntry();
            $entry->objectiveName = "onpu_board";
            $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
            $entry->customName = $line;
            $entry->score = $score;
            $entry->scoreboardId = $score;
            $pk->entries[] = $entry;
        }
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function initScoreboard(Player $player): void { 
        $pk = new SetDisplayObjectivePacket();
        $pk->displaySlot = "sidebar"; 
        $pk->objectiveName = "onpu_board"; 
        $pk->displayName = "§e§l ONPU Server "; 
        $pk->criteriaName = "dummy"; 
        $pk->sortOrder = 0; 
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function playSound(Player $player, string $soundName, float $volume = 1.0, float $pitch = 1.0): void { 
        $pk = new PlaySoundPacket();
        $pk->soundName = $soundName; 
        $pk->x = $player->getPosition()->x; 
        $pk->y = $player->getPosition()->y; 
        $pk->z = $player->getPosition()->z; 
        $pk->volume = $volume; 
        $pk->pitch = $pitch; 
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function broadcastSound(string $soundName): void { 
        foreach ($this->plugin->getServer()->getOnlinePlayers() as $p) {
            $this->playSound($p, $soundName);
        }
    }
}