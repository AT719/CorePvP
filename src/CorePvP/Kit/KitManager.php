<?php
namespace CorePvP\Kit;

use CorePvP\Main;
use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\block\VanillaBlocks;
use pocketmine\color\Color;

class KitManager {
    private Main $plugin;
    
    // スキルのクールダウンや、現在の職業を記憶する配列
    public array $cooldowns = [];
    public array $activeSkills = [];
    public array $playerKit = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    // プレイヤーの現在のKit名を取得する
    public function getKitName(string $playerName): string {
        return isset($this->playerKit[$playerName]) ? ucfirst($this->playerKit[$playerName]) : "None";
    }

    // --- 装備配布処理 ---

    public function applyKit(Player $p, string $kitName): void {
        $p->getInventory()->clearAll();
        $p->getArmorInventory()->clearAll();
        $p->getEffects()->clear();
        
        $name = $p->getName();
        $this->playerKit[$name] = strtolower($kitName);

        // チームカラーの革防具を作成
        $team = $this->plugin->gameManager->getPlayerTeam($name); // 後で作るGameManagerから取得
        $color = ($team === "blue") ? Color::fromRGB(0, 0, 255) : Color::fromRGB(255, 0, 0);
        
        $helmet = $this->setKitItem(VanillaItems::LEATHER_CAP()->setCustomColor($color));
        $chest = $this->setKitItem(VanillaItems::LEATHER_TUNIC()->setCustomColor($color));
        $leggings = $this->setKitItem(VanillaItems::LEATHER_PANTS()->setCustomColor($color));
        $boots = $this->setKitItem(VanillaItems::LEATHER_BOOTS()->setCustomColor($color));
        
        $p->getArmorInventory()->setHelmet($helmet);
        $p->getArmorInventory()->setChestplate($chest);
        $p->getArmorInventory()->setLeggings($leggings);
        $p->getArmorInventory()->setBoots($boots);

        // スキルブックの準備
        $skillBook = $this->setKitItem(VanillaItems::ENCHANTED_BOOK(), true);
        $skillBook->setCustomName("§r§b" . ucfirst($kitName) . " Skill (Tap/Hit)");
        $skillBook->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1));

        // 職業ごとの個別アイテム配布
        switch (strtolower($kitName)) {
            case "default":
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::STONE_SWORD()), $this->setKitItem(VanillaItems::STONE_PICKAXE()), $this->setKitItem(VanillaItems::STONE_AXE()), $this->setKitItem(VanillaBlocks::CRAFTING_TABLE()->asItem()));
                $p->sendMessage("§a[ONPU] §fデフォルトKitを装備しました。");
                break;
            case "warrior":
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::WOODEN_PICKAXE()), $this->setKitItem(VanillaItems::WOODEN_AXE()), $skillBook);
                $p->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 999999, 0, true));
                $p->sendMessage("§a[ONPU] §f剣士になりました。");
                break;
            case "miner":
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::STONE_PICKAXE()), $this->setKitItem(VanillaItems::WOODEN_AXE()), $skillBook);
                $p->getEffects()->add(new EffectInstance(VanillaEffects::HASTE(), 999999, 1, true));
                $p->sendMessage("§a[ONPU] §f採掘士になりました。");
                break;
            case "assault":
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::WOODEN_PICKAXE()), $this->setKitItem(VanillaItems::WOODEN_AXE()), $skillBook);
                $p->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 999999, 1, true));
                $p->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 999999, 0, true));
                $p->sendMessage("§a[ONPU] §f突撃兵になりました。");
                break;
            case "archer":
                $bow = $this->setKitItem(VanillaItems::BOW());
                $bow->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 3));
                $potion = $this->setKitItem(VanillaItems::SPLASH_POTION()->setType(PotionType::LEAPING()));
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_AXE()), $bow, $potion, $skillBook, $this->setKitItem(VanillaItems::ARROW()->setCount(128)));
                $p->sendMessage("§a[ONPU] §f狩人になりました。");
                break;
            case "healer":
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_AXE()), $this->setKitItem(VanillaItems::WOODEN_PICKAXE()), $skillBook);
                $p->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), 999999, 0, true));
                $p->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 999999, 0, true));
                $p->sendMessage("§a[ONPU] §f僧侶になりました。");
                break;
            case "mikawa":
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::STONE_AXE()), $skillBook);
                $p->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 999999, 0, true));
                $p->sendMessage("§a[ONPU] §f身躱神になりました。");
                break;
            case "cancel":
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::WOODEN_PICKAXE()), $this->setKitItem(VanillaItems::WOODEN_AXE()), $skillBook);
                $p->sendMessage("§a[ONPU] §fキャンセラーになりました。");
                break;
            case "builder":
                $p->getInventory()->addItem($this->setKitItem(VanillaItems::WOODEN_SWORD()), $this->setKitItem(VanillaItems::STONE_PICKAXE()), $this->setKitItem(VanillaItems::STONE_AXE()), $this->setKitItem(VanillaItems::STONE_SHOVEL()), $skillBook);
                $p->getInventory()->addItem(VanillaBlocks::OAK_PLANKS()->asItem()->setCount(64));
                $p->getInventory()->addItem(VanillaBlocks::BRICKS()->asItem()->setCount(64));
                $p->sendMessage("§a[ONPU] §f建築士になりました。");
                break;
        }
    }

    // --- スキル発動処理 ---

    public function tryActivateSkill(Player $p): void {
        $item = $p->getInventory()->getItemInHand();
        if ($item->getTypeId() !== VanillaItems::ENCHANTED_BOOK()->getTypeId()) return;
        
        $name = $p->getName();
        if (!isset($this->playerKit[$name])) return;
        
        $kit = $this->playerKit[$name];
        $ct = $this->getCooldown($name, $kit);
        if ($ct > 0) {
            $p->sendPopup("§cスキル準備中... あと" . $ct . "秒");
            return;
        }

        // 後で作るGameManagerの音を鳴らす機能を呼び出す
        $this->plugin->gameManager->playSound($p, "item.book.page_turn", 1.0, 1.2);

        switch ($kit) {
            case "warrior":
                $p->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 100, 1, true));
                $p->sendMessage("§c§l[力溜め] §r§f攻撃力大幅上昇！(5秒)");
                $this->setCooldown($name, "warrior", 60);
                break;
            case "archer":
                $this->activeSkills[$name]["archer"] = time() + 20;
                $p->sendMessage("§a§l[五月雨撃ち] §r§f矢が拡散します！(20秒)");
                $this->setCooldown($name, "archer", 60);
                break;
            case "miner":
                $this->activeSkills[$name]["miner"] = time() + 10;
                $p->sendMessage("§b§l[財宝の知恵] §r§f鉱石採掘量アップ！(10秒)");
                $this->setCooldown($name, "miner", 70);
                break;
            case "assault":
                $this->activeSkills[$name]["assault"] = time() + 15;
                $p->sendMessage("§6§l[挫けぬ心] §r§fノックバック無効！(15秒)");
                $this->setCooldown($name, "assault", 70);
                break;
            case "mikawa":
                $this->activeSkills[$name]["mikawa"] = time() + 10;
                $p->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 200, 1, true));
                $p->sendMessage("§b§l[紙一重] §r§f回避モード発動！(10秒)");
                $this->setCooldown($name, "mikawa", 60);
                break;
            case "healer":
                $count = 0;
                foreach ($p->getWorld()->getNearbyEntities($p->getBoundingBox()->expandedCopy(5, 5, 5)) as $entity) {
                    if ($entity instanceof Player && $entity->getName() !== $name && $count < 3) {
                        $entity->setHealth($entity->getHealth() + 6);
                        $entity->sendMessage("§a僧侶のスキルで回復しました！");
                        $count++;
                    }
                }
                $p->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 600, 1, true));
                $p->sendMessage("§a§l[ベホマラー] §r§f周囲の味方を回復しました！");
                $this->setCooldown($name, "healer", 30);
                break;
            case "cancel":
                foreach ($p->getWorld()->getNearbyEntities($p->getBoundingBox()->expandedCopy(5, 5, 5)) as $entity) {
                    if ($entity instanceof Player && $entity->getName() !== $name) {
                        $entity->getEffects()->clear();
                        $entity->sendMessage("§c§l[キャンセル] §r§fバフを消去されました！");
                    }
                }
                $p->sendMessage("§5§l[キャンセルアイ] §r§f周囲の敵を無力化しました。");
                $this->setCooldown($name, "cancel", 90);
                break;
            case "builder":
                $p->getInventory()->addItem(VanillaBlocks::OAK_PLANKS()->asItem()->setCount(64));
                $p->getInventory()->addItem(VanillaBlocks::BRICKS()->asItem()->setCount(64));
                $p->sendMessage("§e§l[素材集め] §r§f建材を入手しました。");
                $this->setCooldown($name, "builder", 60);
                break;
        }
    }

    // --- ドロップ制限用タグ付け ---
    public function setKitItem(Item $item, bool $isSkillBook = false): Item {
        $item->getNamedTag()->setInt("CorePvP_KitItem", 1);
        if ($isSkillBook) {
            $item->getNamedTag()->setInt("CorePvP_SkillBook", 1);
        }
        return $item;
    }

    // --- クールダウン管理 ---
    private function getCooldown(string $name, string $kit): int {
        if (!isset($this->cooldowns[$name][$kit])) return 0;
        $remaining = $this->cooldowns[$name][$kit] - time();
        return ($remaining > 0) ? $remaining : 0;
    }

    public function setCooldown(string $name, string $kit, int $seconds): void {
        $this->cooldowns[$name][$kit] = time() + $seconds;
    }
}