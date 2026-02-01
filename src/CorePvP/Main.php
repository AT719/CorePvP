<?php
namespace CorePvP;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerDeathEvent; 
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\item\VanillaItems;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\block\VanillaBlocks;
use pocketmine\utils\Config;
use pocketmine\form\Form;
use pocketmine\scheduler\ClosureTask;
use pocketmine\entity\projectile\Arrow;
use pocketmine\math\Vector3;
use pocketmine\color\Color;
use pocketmine\player\GameMode;
use pocketmine\world\Position;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\world\particle\HugeExplosionParticle;
use pocketmine\world\particle\DustParticle;

class Main extends PluginBase implements Listener {
    private Config $db;
    private array $cooldowns = [];
    private array $activeSkills = [];
    private array $playerKit = [];
    private array $scoreboards = [];
    private array $portalCooldown = [];

    // ゲーム進行ステータス
    public const STATE_LOBBY = 0;
    public const STATE_WAITING = 1;
    public const STATE_GAME = 2;
    public const STATE_ENDING = 3; // ★追加：試合終了後の余韻タイム
    private int $gameState = self::STATE_LOBBY;
    
    private int $currentPhase = 1;
    private int $gameTime = 0;
    private int $endingTime = 0; // 余韻のカウントダウン

    private array $queue = [];
    private array $teams = [];
    private array $votes = [];
    private int $countdown = 30;

    private array $teamData = [
        "red" => ["hp" => 100, "core" => null],
        "blue" => ["hp" => 100, "core" => null]
    ];

    // ★座標を離して明確化 (200ブロック差)
    private array $coords = [
        "hub" => [0, 100, 0],
        "waiting" => [1000, 100, 0],
        
        // 赤チームエリア (X = 2000周辺)
        "red_spawn" => [2000, 100, 0],
        "red_core_pos" => [2005, 101, 0], 

        // 青チームエリア (X = 2200周辺)
        "blue_spawn" => [2200, 100, 0],
        "blue_core_pos" => [2205, 101, 0] 
    ];

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->db = new Config($this->getDataFolder() . "players.json", Config::JSON);
        $this->getLogger()->info("§aCorePvP v10.9 (Victory Firework) Loaded!");

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->gameLoop();
        }), 20);
    }

    // --- ゲームループ (1秒ごとに実行) ---
    private function gameLoop(): void {
        $now = time();

        // 1. 待機中
        if ($this->gameState === self::STATE_WAITING) {
            $count = count($this->queue);
            if ($count === 0) {
                $this->gameState = self::STATE_LOBBY;
                $this->countdown = 30;
            } elseif ($count >= 2) { 
                $this->countdown--;
                foreach ($this->queue as $name) {
                    $p = $this->getServer()->getPlayerExact($name);
                    if ($p) $p->sendPopup("§eGame starting in §c" . $this->countdown);
                }
                if ($this->countdown <= 0) $this->startGame();
            } else {
                $this->countdown = 30;
                foreach ($this->queue as $name) {
                    $p = $this->getServer()->getPlayerExact($name);
                    if ($p) $p->sendPopup("§cWaiting for players... (" . $count . "/2)");
                }
            }
        }

        // 2. 試合中
        if ($this->gameState === self::STATE_GAME) {
            $this->gameTime++;
            if ($this->gameTime === 600 && $this->currentPhase === 1) {
                $this->currentPhase = 2;
                $this->getServer()->broadcastMessage("§c§l[Phase 2] §r§eコアへの攻撃が可能になりました！");
                $this->broadcastSound("random.anvil_land");
            }
            if ($this->gameTime === 1200 && $this->currentPhase === 2) {
                $this->currentPhase = 3;
                $this->getServer()->broadcastMessage("§4§l[Phase 3] §r§cコアダメージが2倍になります！");
                $this->broadcastSound("random.anvil_land");
            }
        }

        // 3. ★試合終了後の余韻 (Victory Time)
        if ($this->gameState === self::STATE_ENDING) {
            $this->endingTime--;
            
            // 花火演出
            foreach ($this->getServer()->getOnlinePlayers() as $p) {
                if (mt_rand(0, 2) === 0) { // 30%の確率で発生
                    $pos = $p->getPosition()->add(mt_rand(-3, 3), mt_rand(1, 4), mt_rand(-3, 3));
                    $p->getWorld()->addParticle($pos, new HugeExplosionParticle());
                    $this->playSound($p, "random.explode", 0.5, 1.5);
                    $this->playSound($p, "firework.launch", 1.0, 1.0);
                }
            }

            if ($this->endingTime <= 0) {
                $this->finalizeGame(); // ロビーへ戻す
            }
        }

        // スコアボード & スキルCT更新
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $this->updateScoreboard($player);
            $name = $player->getName();
            if (isset($this->cooldowns[$name])) {
                foreach ($this->cooldowns[$name] as $kit => $endTime) {
                    if ($now >= $endTime) {
                        $player->sendMessage("§a[ONPU] §lスキル再使用可能！");
                        unset($this->cooldowns[$name][$kit]);
                    }
                }
            }
        }
    }

    // --- ゲーム終了処理 (フェーズ1: 余韻開始) ---
    private function endGame(string $winningTeam): void {
        $this->gameState = self::STATE_ENDING;
        $this->endingTime = 10; // 10秒間

        $colorCode = ($winningTeam === "red") ? "§c" : "§9";
        $teamName = ($winningTeam === "red") ? "赤" : "青";
        
        $title = $colorCode . "§l" . $teamName . "チームの勝利！";
        $subtitle = "§fGG! ロビーへ戻ります...";

        foreach ($this->getServer()->getOnlinePlayers() as $p) {
            $p->sendTitle($title, $subtitle, 10, 100, 20);
            $this->playSound($p, "random.totem", 1.0, 1.0); // 勝利音
            $p->setGamemode(GameMode::ADVENTURE()); // 戦闘停止
            $p->getEffects()->clear(); // エフェクト消去
        }
    }

    // --- ゲーム終了処理 (フェーズ2: 完全リセット) ---
    private function finalizeGame(): void {
        $this->gameState = self::STATE_LOBBY;
        
        // データ初期化
        $this->teamData["red"]["hp"] = 100;
        $this->teamData["blue"]["hp"] = 100;
        $this->queue = [];
        $this->teams = [];     // チーム解散
        $this->playerKit = []; // Kit解除
        $this->activeSkills = [];
        $this->cooldowns = [];
        $this->currentPhase = 1;
        $this->gameTime = 0;

        foreach ($this->getServer()->getOnlinePlayers() as $p) {
            $this->sendToHub($p); // ロビーへ
        }
    }

    // --- コア破壊判定 (修正版) ---
    private function handleCoreBreak(Player $p, $block, BlockBreakEvent $event): void {
        $targetTeam = null;
        $pos = $block->getPosition();

        // 座標チェック (厳密に)
        if ($this->teamData["red"]["core"] !== null && $pos->equals($this->teamData["red"]["core"])) $targetTeam = "red";
        if ($this->teamData["blue"]["core"] !== null && $pos->equals($this->teamData["blue"]["core"])) $targetTeam = "blue";

        if ($targetTeam === null) {
            // コアじゃないエンドストーン
            if (!$this->getServer()->isOp($p->getName())) $event->cancel();
            return;
        }

        $event->cancel(); // コア自体は壊さない

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

        // ダメージ処理
        $damage = ($this->currentPhase === 3) ? 2 : 1;
        $this->teamData[$targetTeam]["hp"] -= $damage;
        $currentHP = $this->teamData[$targetTeam]["hp"];

        $this->broadcastSound("random.anvil_land"); // 破壊音
        
        $teamColor = ($targetTeam === "red") ? "§c赤" : "§9青";
        $p->sendPopup("§eCore Hit! " . $teamColor . "HP: " . $currentHP . " (-" . $damage . ")");

        if ($currentHP <= 0) {
            $this->endGame($myTeam); // 自分の勝ち
        }
    }

    // --- スコアボード (ステータス対応) ---
    private function updateScoreboard(Player $player): void {
        $name = $player->getName();
        $data = $this->getPlayerData($player);
        
        // 変数がセットされていない場合は None
        $kitName = isset($this->playerKit[$name]) ? ucfirst($this->playerKit[$name]) : "None";
        $team = isset($this->teams[$name]) ? ucfirst($this->teams[$name]) : "None";
        
        $lines = [
            "§r ",
            "§fname: " . $name,
            "§fmoney: " . $data["money"],
            "§fkill: " . $data["kills"],
            "§fkit: " . $kitName,
            "§fteam: " . $team
        ];

        // 試合中とエンディング中はHP表示
        if ($this->gameState === self::STATE_GAME || $this->gameState === self::STATE_ENDING) {
            $lines[] = "§e----------------";
            $lines[] = "§cRed HP : " . $this->teamData["red"]["hp"];
            $lines[] = "§9Blue HP: " . $this->teamData["blue"]["hp"];
            
            if ($this->gameState === self::STATE_ENDING) {
                $lines[] = "§6★ VICTORY! ★";
            } else {
                $lines[] = "§ePhase  : " . $this->currentPhase;
            }
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

    // --- Kit適用 (本の名前にKit名を入れる) ---
    private function applyKit(Player $p, string $kitName): void {
        $p->getInventory()->clearAll();
        $p->getArmorInventory()->clearAll();
        $p->getEffects()->clear();
        
        $name = $p->getName();
        $this->playerKit[$name] = strtolower($kitName);
        $this->updateScoreboard($p); // すぐに更新

        $team = $this->teams[$name] ?? "red";
        $color = ($team === "blue") ? Color::fromRGB(0, 0, 255) : Color::fromRGB(255, 0, 0);
        
        // 防具
        $helmet = $this->setKitItem(VanillaItems::LEATHER_CAP()->setCustomColor($color));
        $chest = $this->setKitItem(VanillaItems::LEATHER_TUNIC()->setCustomColor($color));
        $leggings = $this->setKitItem(VanillaItems::LEATHER_PANTS()->setCustomColor($color));
        $boots = $this->setKitItem(VanillaItems::LEATHER_BOOTS()->setCustomColor($color));
        $p->getArmorInventory()->setHelmet($helmet);
        $p->getArmorInventory()->setChestplate($chest);
        $p->getArmorInventory()->setLeggings($leggings);
        $p->getArmorInventory()->setBoots($boots);

        // ★修正: 本の名前にスキル名を入れる
        $skillBook = $this->setKitItem(VanillaItems::ENCHANTED_BOOK(), true);
        $displayName = ucfirst($kitName) . " Skill"; // 例: Warrior Skill
        $skillBook->setCustomName("§r§b" . $displayName . " (Tap/Hit)");
        $skillBook->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1));

        switch (strtolower($kitName)) {
            case "default": $p->getInventory()->addItem($this->setKitItem(VanillaItems::STONE_SWORD()), $this->setKitItem(VanillaItems::STONE_PICKAXE()), $this->setKitItem(VanillaItems::STONE_AXE()), $this->setKitItem(VanillaBlocks::CRAFTING_TABLE()->asItem())); $p->sendMessage("§a[ONPU] §fデフォルトKitを装備しました。"); break;
            case "warrior": $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::WOODEN_PICKAXE()), $this->setKitItem(VanillaItems::WOODEN_AXE()), $skillBook); $p->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 999999, 0, true)); $p->sendMessage("§a[ONPU] §f剣士になりました。"); break;
            case "miner": $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::STONE_PICKAXE()), $this->setKitItem(VanillaItems::WOODEN_AXE()), $skillBook); $p->getEffects()->add(new EffectInstance(VanillaEffects::HASTE(), 999999, 1, true)); $p->sendMessage("§a[ONPU] §f採掘士になりました。"); break;
            case "assault": $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::WOODEN_PICKAXE()), $this->setKitItem(VanillaItems::WOODEN_AXE()), $skillBook); $p->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 999999, 1, true)); $p->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 999999, 0, true)); $p->sendMessage("§a[ONPU] §f突撃兵になりました。"); break;
            case "archer": $bow = $this->setKitItem(VanillaItems::BOW()); $bow->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 3)); $potion = $this->setKitItem(VanillaItems::SPLASH_POTION()->setType(PotionType::LEAPING())); $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_AXE()), $bow, $potion, $skillBook, $this->setKitItem(VanillaItems::ARROW()->setCount(128))); $p->sendMessage("§a[ONPU] §f狩人になりました。"); break;
            case "healer": $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_AXE()), $this->setKitItem(VanillaItems::WOODEN_PICKAXE()), $skillBook); $p->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), 999999, 0, true)); $p->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 999999, 0, true)); $p->sendMessage("§a[ONPU] §f僧侶になりました。"); break;
            case "mikawa": $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::STONE_AXE()), $skillBook); $p->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 999999, 0, true)); $p->sendMessage("§a[ONPU] §f身躱神になりました。"); break;
            case "cancel": $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::WOODEN_PICKAXE()), $this->setKitItem(VanillaItems::WOODEN_AXE()), $skillBook); $p->sendMessage("§a[ONPU] §fキャンセラーになりました。"); break;
            case "builder": $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::STONE_PICKAXE()), $this->setKitItem(VanillaItems::STONE_AXE()), $this->setKitItem(VanillaItems::STONE_SHOVEL()), $skillBook); $p->getInventory()->addItem(VanillaBlocks::OAK_PLANKS()->asItem()->setCount(64)); $p->getInventory()->addItem(VanillaBlocks::BRICKS()->asItem()->setCount(64)); $p->sendMessage("§a[ONPU] §f建築士になりました。"); break;
        }
    }

    // --- その他イベント (省略なし) ---
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
                $this->addStat($attacker, "kills", 1); $this->addStat($attacker, "money", 100); $this->addStat($attacker, "exp", 50);
                $attacker->sendMessage("§e[ONPU] §fKill! +100 Money"); $this->updateScoreboard($attacker);
            }
            $name = $victim->getName();
            if (isset($this->playerKit[$name]) && $this->playerKit[$name] === "mikawa") { if ($event->getCause() === EntityDamageEvent::CAUSE_PROJECTILE) { $event->setBaseDamage(0); $event->cancel(); return; } } 
            if (isset($this->activeSkills[$name]["mikawa"]) && $this->activeSkills[$name]["mikawa"] > time()) { if (mt_rand(0, 1) === 0) { $event->cancel(); $victim->sendPopup("§b回避！"); return; } } 
            if (isset($this->activeSkills[$name]["assault"]) && $this->activeSkills[$name]["assault"] > time()) { $event->setKnockBack(0); }
        }
    }
    public function onDeath(PlayerDeathEvent $event): void {
        $event->setDrops([]); $event->setXpDropAmount(0);
        if ($this->gameState === self::STATE_GAME) { $event->setKeepInventory(true); }
    }
    public function onRespawn(PlayerRespawnEvent $event): void {
        $p = $event->getPlayer(); $name = $p->getName(); $w = $this->getServer()->getWorldManager()->getDefaultWorld();
        if ($this->gameState === self::STATE_GAME && isset($this->teams[$name])) {
            $team = $this->teams[$name];
            $pos = ($team === "red") ? $this->coords["red_spawn"] : $this->coords["blue_spawn"];
            $event->setRespawnPosition(new Position($pos[0], $pos[1], $pos[2], $w));
            $p->sendMessage("§a[ONPU] §f拠点に復活しました。");
        } else {
            $pos = $this->coords["hub"];
            $event->setRespawnPosition(new Position($pos[0], $pos[1], $pos[2], $w));
        }
    }
    public function onBreak(BlockBreakEvent $event): void {
        $p = $event->getPlayer(); $block = $event->getBlock(); $name = $p->getName();
        if ($this->gameState !== self::STATE_GAME) { if (!$this->getServer()->isOp($name)) { $event->cancel(); $p->sendPopup("§c権限がありません"); } return; }
        if ($block->getTypeId() === VanillaBlocks::END_STONE()->getTypeId()) { $this->handleCoreBreak($p, $block, $event); return; }
        if (!$this->getServer()->isOp($name)) { $event->cancel(); }
    }
    public function onPlace(BlockPlaceEvent $event): void {
        $p = $event->getPlayer(); $name = $p->getName();
        if ($this->gameState !== self::STATE_GAME) { if (!$this->getServer()->isOp($name)) { $event->cancel(); $p->sendPopup("§c権限がありません"); } return; }
        if (!$this->getServer()->isOp($name)) { $event->cancel(); }
    }
    private function startGame(): void {
        $this->gameState = self::STATE_GAME; $this->currentPhase = 1; $this->gameTime = 0;
        $this->teamData["red"]["hp"] = 100; $this->teamData["blue"]["hp"] = 100;
        $w = $this->getServer()->getWorldManager()->getDefaultWorld();
        $this->teamData["red"]["core"] = new Position($this->coords["red_core_pos"][0], $this->coords["red_core_pos"][1], $this->coords["red_core_pos"][2], $w);
        $this->teamData["blue"]["core"] = new Position($this->coords["blue_core_pos"][0], $this->coords["blue_core_pos"][1], $this->coords["blue_core_pos"][2], $w);
        $w->setBlock($this->teamData["red"]["core"], VanillaBlocks::END_STONE());
        $w->setBlock($this->teamData["blue"]["core"], VanillaBlocks::END_STONE());
        $players = array_keys($this->queue); shuffle($players);
        $redCount = 0; $half = ceil(count($players) / 2);
        foreach ($players as $name) {
            $p = $this->getServer()->getPlayerExact($name); if (!$p) continue;
            if ($redCount < $half) { $team = "red"; $redCount++; $pos = $this->coords["red_spawn"]; } else { $team = "blue"; $pos = $this->coords["blue_spawn"]; }
            $this->teams[$name] = $team;
            $p->teleport(new Vector3($pos[0], $pos[1], $pos[2]));
            $p->setGamemode(GameMode::SURVIVAL());
            $p->sendMessage("§l§aGame Start! You are in §" . ($team === "red" ? "cRed" : "9Blue") . " §aTeam!");
            $this->playSound($p, "random.levelup", 1.0, 1.0);
            $this->applyKit($p, "default");
        }
        $this->getServer()->broadcastMessage("§e[ONPU] §lGame Started! Map: " . $this->decideMap());
    }
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) return false;
        switch ($command->getName()) {
            case "hub": $this->sendToHub($sender); return true;
            case "kit": $sender->sendMessage("§cKitコマンドは無効化されています。"); return true;
            case "forcestart": if (!$sender->hasPermission("corepvp.command.forcestart")) return false; if (count($this->queue) < 1) { $this->queue[$sender->getName()] = true; $sender->sendMessage("§e[ONPU] §f強制参加・開始します！"); } $this->startGame(); return true;
            case "stat": $data = $this->getPlayerData($sender); $sender->sendMessage("§fMoney: " . $data["money"]); return true;
            case "shop": $this->sendShopForm($sender); return true;
        } return false;
    }
    private function broadcastSound(string $soundName): void { foreach ($this->getServer()->getOnlinePlayers() as $p) $this->playSound($p, $soundName); }
    private function sendToHub(Player $p): void { $pos = $this->coords["hub"]; $p->teleport(new Vector3($pos[0], $pos[1], $pos[2])); $p->getInventory()->clearAll(); $p->getArmorInventory()->clearAll(); $p->getEffects()->clear(); $p->setGamemode(GameMode::ADVENTURE()); $p->sendTitle("§e§lONPU Server", "§fへようこそ！", 10, 60, 20); $this->playSound($p, "random.orb", 1.0, 1.0); $this->initScoreboard($p); $name = $p->getName(); if (isset($this->queue[$name])) unset($this->queue[$name]); }
    public function onInteract(PlayerInteractEvent $event): void { 
        $p = $event->getPlayer(); $block = $event->getBlock(); 
        if ($block->getTypeId() === VanillaBlocks::DIAMOND()->getTypeId()) { 
            if ($this->gameState === self::STATE_GAME && isset($this->teams[$p->getName()])) { $team = $this->teams[$p->getName()]; $spawnCoords = ($team === "red") ? $this->coords["red_spawn"] : $this->coords["blue_spawn"]; $p->teleport(new Vector3($spawnCoords[0], $spawnCoords[1], $spawnCoords[2])); $p->setGamemode(GameMode::SURVIVAL()); $this->applyKit($p, "default"); $p->sendMessage("§a[ONPU] §f戦場に復帰しました！"); $this->playSound($p, "random.levelup", 1.0, 1.0); return; }
            if ($this->gameState === self::STATE_GAME) { $p->sendMessage("§c[ONPU] 現在試合中です。"); return; }
            if (isset($this->queue[$p->getName()])) return; $this->queue[$p->getName()] = true; $pos = $this->coords["waiting"]; $p->teleport(new Vector3($pos[0], $pos[1], $pos[2])); $p->getInventory()->clearAll(); $p->sendMessage("§a[ONPU] §f待機場に参加しました！"); $this->sendVoteForm($p); $this->gameState = self::STATE_WAITING; return; 
        } 
        $this->tryActivateSkill($p); 
    }
    private function tryActivateSkill(Player $p): void { 
        $item = $p->getInventory()->getItemInHand(); if ($item->getTypeId() !== VanillaItems::ENCHANTED_BOOK()->getTypeId()) return; 
        $name = $p->getName(); if (!isset($this->playerKit[$name])) return; 
        $kit = $this->playerKit[$name]; $ct = $this->getCooldown($name, $kit); 
        if ($ct > 0) { $p->sendPopup("§cスキル準備中... あと" . $ct . "秒"); return; } 
        $this->playSound($p, "item.book.page_turn", 1.0, 1.2); 
        switch ($kit) { 
            case "warrior": $p->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 100, 1, true)); $p->sendMessage("§c§l[力溜め] §r§f攻撃力大幅上昇！(5秒)"); $this->setCooldown($name, "warrior", 60); break; 
            case "archer": $this->activeSkills[$name]["archer"] = time() + 20; $p->sendMessage("§a§l[五月雨撃ち] §r§f矢が拡散します！(20秒)"); $this->setCooldown($name, "archer", 60); break; 
            case "miner": $this->activeSkills[$name]["miner"] = time() + 10; $p->sendMessage("§b§l[財宝の知恵] §r§f鉱石採掘量アップ！(10秒)"); $this->setCooldown($name, "miner", 70); break; 
            case "assault": $this->activeSkills[$name]["assault"] = time() + 15; $p->sendMessage("§6§l[挫けぬ心] §r§fノックバック無効！(15秒)"); $this->setCooldown($name, "assault", 70); break; 
            case "mikawa": $this->activeSkills[$name]["mikawa"] = time() + 10; $p->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 200, 1, true)); $p->sendMessage("§b§l[紙一重] §r§f回避モード発動！(10秒)"); $this->setCooldown($name, "mikawa", 60); break; 
            case "healer": $count = 0; foreach ($p->getWorld()->getNearbyEntities($p->getBoundingBox()->expandedCopy(5, 5, 5)) as $entity) { if ($entity instanceof Player && $entity->getName() !== $name && $count < 3) { $entity->setHealth($entity->getHealth() + 6); $entity->sendMessage("§a僧侶のスキルで回復しました！"); $count++; } } $p->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 600, 1, true)); $p->sendMessage("§a§l[ベホマラー] §r§f周囲の味方を回復しました！"); $this->setCooldown($name, "healer", 30); break; 
            case "cancel": foreach ($p->getWorld()->getNearbyEntities($p->getBoundingBox()->expandedCopy(5, 5, 5)) as $entity) { if ($entity instanceof Player && $entity->getName() !== $name) { $entity->getEffects()->clear(); $entity->sendMessage("§c§l[キャンセル] §r§fバフを消去されました！"); } } $p->sendMessage("§5§l[キャンセルアイ] §r§f周囲の敵を無力化しました。"); $this->setCooldown($name, "cancel", 90); break; 
            case "builder": $p->getInventory()->addItem(VanillaBlocks::OAK_PLANKS()->asItem()->setCount(64)); $p->getInventory()->addItem(VanillaBlocks::BRICKS()->asItem()->setCount(64)); $p->sendMessage("§e§l[素材集め] §r§f建材を入手しました。"); $this->setCooldown($name, "builder", 60); break; 
        } 
    }
    public function sendVoteForm(Player $player): void { $form = new SimpleForm(function (Player $player, $data) { if ($data === null) return; $maps = [0 => "Canyon", 1 => "Valley", 2 => "Coastal", 3 => "Skylands"]; if (isset($maps[$data])) { $this->votes[$player->getName()] = $maps[$data]; $player->sendMessage("§a[ONPU] §f" . $maps[$data] . " に投票しました！"); } }); $form->setTitle("Map Vote"); $form->setContent("プレイしたいマップを選んでください"); $form->addButton("Canyon"); $form->addButton("Valley"); $form->addButton("Coastal"); $form->addButton("Skylands"); $player->sendForm($form); }
    public function onJoin(PlayerJoinEvent $event): void { $p = $event->getPlayer(); $n = $p->getName(); if (!$this->db->exists($n)) { $this->db->set($n, ["money" => 1000, "np" => 500, "kills" => 0, "deaths" => 0, "exp" => 0, "level" => 1]); $this->db->save(); } $this->sendToHub($p); }
    public function onMove(PlayerMoveEvent $event): void { $p = $event->getPlayer(); $name = $p->getName(); if ($this->gameState !== self::STATE_GAME) return; if (!isset($this->teams[$name])) return; $block = $p->getWorld()->getBlock($p->getPosition()); if ($block->getTypeId() === VanillaBlocks::NETHER_PORTAL()->getTypeId()) { if (isset($this->portalCooldown[$name]) && time() < $this->portalCooldown[$name]) return; $this->portalCooldown[$name] = time() + 3; $team = $this->teams[$name]; $spawnCoords = ($team === "red") ? $this->coords["red_spawn"] : $this->coords["blue_spawn"]; $p->teleport(new Vector3($spawnCoords[0], $spawnCoords[1], $spawnCoords[2])); $this->playSound($p, "mob.endermen.portal", 1.0, 1.0); $p->sendMessage("§a[ONPU] §f拠点のポータルを使用しました。"); $this->sendKitForm($p); } }
    private function decideMap(): string { if (empty($this->votes)) return "Canyon (Default)"; $counts = array_count_values($this->votes); arsort($counts); return array_key_first($counts); }
    private function setKitItem(Item $item, bool $isSkillBook = false): Item { $item->getNamedTag()->setInt("CorePvP_KitItem", 1); if ($isSkillBook) { $item->getNamedTag()->setInt("CorePvP_SkillBook", 1); } return $item; }
    public function onDrop(PlayerDropItemEvent $event): void { $item = $event->getItem(); $player = $event->getPlayer(); if ($item->getNamedTag()->getInt("CorePvP_KitItem", 0) === 1) { if ($item->getNamedTag()->getInt("CorePvP_SkillBook", 0) === 1) { $event->cancel(); $player->sendPopup("§cスキルブックは捨てられません！"); } else { $event->cancel(); $player->getInventory()->removeItem($item); $player->sendPopup("§7専用アイテムを破棄しました(消滅)"); $this->playSound($player, "random.fizz", 0.5, 1.5); } } }
    public function sendKitForm(Player $player): void { $form = new SimpleForm(function (Player $player, $data) { if ($data === null) return; $kits = [0 => "default", 1 => "warrior", 2 => "miner", 3 => "assault", 4 => "healer", 5 => "archer", 6 => "mikawa", 7 => "cancel", 8 => "builder"]; if (isset($kits[$data])) { $this->applyKit($player, $kits[$data]); } }); $form->setTitle("§l§eONPU Kit Select"); $form->setContent("§f使用したいKitを選んでください。"); $form->addButton("§l§7初期装備"); $form->addButton("§l§c剣士"); $form->addButton("§l§b採掘士"); $form->addButton("§l§6突撃兵"); $form->addButton("§l§d僧侶"); $form->addButton("§l§a狩人"); $form->addButton("§l§3身躱神"); $form->addButton("§l§5キャンセラー"); $form->addButton("§l§e建築士"); $player->sendForm($form); }
    public function onPacketReceive(DataPacketReceiveEvent $event): void { $packet = $event->getPacket(); if ($packet instanceof LevelSoundEventPacket && $packet->sound === LevelSoundEvent::ATTACK_NODAMAGE) { $player = $event->getOrigin()->getPlayer(); if ($player !== null) $this->tryActivateSkill($player); } }
    public function onShoot(EntityShootBowEvent $event): void { $entity = $event->getEntity(); if (!$entity instanceof Player) return; $name = $entity->getName(); if (isset($this->playerKit[$name]) && $this->playerKit[$name] === "archer") { if (isset($this->activeSkills[$name]["archer"]) && $this->activeSkills[$name]["archer"] > time()) { $originalArrow = $event->getProjectile(); if (!$originalArrow instanceof Arrow) return; $location = $entity->getLocation(); $yawOffsets = [-15, 15]; foreach ($yawOffsets as $offset) { $newLocation = clone $location; $newLocation->yaw += $offset; $rad = deg2rad($newLocation->yaw); $x = -sin($rad) * cos(deg2rad($newLocation->pitch)); $z = cos($rad) * cos(deg2rad($newLocation->pitch)); $y = -sin(deg2rad($newLocation->pitch)); $nbt = $originalArrow->saveNBT(); $arrow = new Arrow($newLocation, $entity, $originalArrow->isCritical(), $nbt); $arrow->setMotion($originalArrow->getMotion()); $arrow->spawnToAll(); $force = $event->getForce(); $arrow->setMotion((new Vector3($x, $y, $z))->normalize()->multiply($force * 3)); } $entity->sendPopup("§c>>> 五月雨撃ち！ <<<"); $this->playSound($entity, "random.bow", 1.0, 1.5); } } }
    private function playSound(Player $player, string $soundName, float $volume = 1.0, float $pitch = 1.0): void { $pk = new PlaySoundPacket(); $pk->soundName = $soundName; $pk->x = $player->getPosition()->x; $pk->y = $player->getPosition()->y; $pk->z = $player->getPosition()->z; $pk->volume = $volume; $pk->pitch = $pitch; $player->getNetworkSession()->sendDataPacket($pk); }
    public function onQuit(PlayerQuitEvent $event): void { $name = $event->getPlayer()->getName(); if (isset($this->scoreboards[$name])) unset($this->scoreboards[$name]); if (isset($this->queue[$name])) unset($this->queue[$name]); }
    private function initScoreboard(Player $player): void { $pk = new SetDisplayObjectivePacket(); $pk->displaySlot = "sidebar"; $pk->objectiveName = "onpu_board"; $pk->displayName = "§e§l ONPU Server "; $pk->criteriaName = "dummy"; $pk->sortOrder = 0; $player->getNetworkSession()->sendDataPacket($pk); $this->scoreboards[$player->getName()] = true; }
    private function getPlayerData(Player $p): array { $name = $p->getName(); $defaults = ["money" => 1000, "np" => 500, "kills" => 0, "deaths" => 0, "exp" => 0, "level" => 1]; if (!$this->db->exists($name)) { $this->db->set($name, $defaults); $this->db->save(); return $defaults; } return array_merge($defaults, $this->db->get($name)); }
    public function sendShopForm(Player $player): void { $form = new SimpleForm(function (Player $player, $data) { if ($data === null) return; $items = [ 0 => ["name" => "Speed Pot", "cost" => 1000, "item" => VanillaItems::SPLASH_POTION()->setType(PotionType::SWIFTNESS())], 1 => ["name" => "Strength Pot", "cost" => 2000, "item" => VanillaItems::SPLASH_POTION()->setType(PotionType::STRENGTH())], 2 => ["name" => "Regen Pot", "cost" => 3000, "item" => VanillaItems::SPLASH_POTION()->setType(PotionType::REGENERATION())], 3 => ["name" => "Invisibility", "cost" => 3500, "item" => VanillaItems::SPLASH_POTION()->setType(PotionType::INVISIBILITY())], 4 => ["name" => "Ender Pearl", "cost" => 3000, "item" => VanillaItems::ENDER_PEARL()], 5 => ["name" => "Golden Apple", "cost" => 1000, "item" => VanillaItems::GOLDEN_APPLE()], 6 => ["name" => "Lapis (Enchant)", "cost" => 100, "item" => VanillaItems::LAPIS_LAZULI()->setCount(32)] ]; if (isset($items[$data])) { $product = $items[$data]; $name = $player->getName(); $dbData = $this->getPlayerData($player); if ($dbData["money"] >= $product["cost"]) { $dbData["money"] -= $product["cost"]; $this->db->set($name, $dbData); $this->db->save(); $player->getInventory()->addItem($product["item"]); $player->sendMessage("§a[ONPU] §f" . $product["name"] . "を購入しました！"); } else { $player->sendMessage("§c[ONPU] §fお金が足りません！"); } } }); $d = $this->getPlayerData($player); $form->setTitle("§l§eONPU Shop"); $form->setContent("§fMoney: §e" . $d["money"]); $form->addButton("Speed Pot (1000)"); $form->addButton("Strength Pot (2000)"); $form->addButton("Regen Pot (3000)"); $form->addButton("Invisibility (3500)"); $form->addButton("Ender Pearl (3000)"); $form->addButton("Golden Apple (1000)"); $form->addButton("Lapis (100)"); $player->sendForm($form); }
    private function getCooldown(string $name, string $kit): int { if (!isset($this->cooldowns[$name][$kit])) return 0; $remaining = $this->cooldowns[$name][$kit] - time(); return ($remaining > 0) ? $remaining : 0; }
    private function setCooldown(string $name, string $kit, int $seconds): void { $this->cooldowns[$name][$kit] = time() + $seconds; }
    private function addStat(Player $p, string $key, int $amount): void { $data = $this->getPlayerData($p); $data[$key] += $amount; $this->db->set($p->getName(), $data); $this->db->save(); }
}

class SimpleForm implements Form {
    private $callable; private array $data = [];
    public function __construct(?callable $callable) { $this->callable = $callable; $this->data["type"] = "form"; $this->data["title"] = ""; $this->data["content"] = ""; $this->data["buttons"] = []; }
    public function setTitle(string $title): void { $this->data["title"] = $title; }
    public function setContent(string $content): void { $this->data["content"] = $content; }
    public function addButton(string $text, int $imageType = -1, string $imagePath = ""): void { $content = ["text" => $text]; if ($imageType !== -1) { $content["image"] = ["type" => $imageType === 0 ? "path" : "url", "data" => $imagePath]; } $this->data["buttons"][] = $content; }
    public function handleResponse(Player $player, $data): void { $p = $player; if ($this->callable !== null) { ($this->callable)($p, $data); } }
    public function jsonSerialize(): array { return $this->data; }
}