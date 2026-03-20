<?php
namespace CorePvP\Form;

use CorePvP\Main;
use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use pocketmine\item\PotionType;
use pocketmine\form\Form;
use CorePvP\Map\MapManager;

class FormManager {
    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    // ★ ここを大改修！16個の試合マップの多数決投票フォームに変更しました ★
    public function sendVoteForm(Player $player): void {
        $maps = MapManager::MATCH_MAPS;
        $currentVotes = array_count_values($this->plugin->mapManager->votes);

        $form = new SimpleForm(function (Player $player, $data) use ($maps) {
            if ($data === null) return;
            
            if (isset($maps[$data])) {
                $selectedMap = $maps[$data];
                $this->plugin->mapManager->addVote($player->getName(), $selectedMap);
                $player->sendMessage("§a[ONPU] §f『§e" . $selectedMap . "§f』に投票しました！");
            }
        });

        $form->setTitle("§l§1マップ多数決投票");
        $form->setContent("試合で使いたいマップを選んでください。\n現在の最多得票マップが次の試合に選ばれます。");
        
        foreach ($maps as $map) {
            $voteCount = $currentVotes[$map] ?? 0;
            // マップ名の下に現在の得票数を表示
            $form->addButton($map . "\n§8[ §a" . $voteCount . " 票 §8]");
        }
        
        $player->sendForm($form);
    }

    // ↓↓↓ 以下のKit機能やShop機能は一切変更せず、あなたのオリジナルを完全保持しています！ ↓↓↓

    public function sendKitForm(Player $player): void {
        $form = new SimpleForm(function (Player $player, $data) {
            if ($data === null) return;
            $kits = [0 => "default", 1 => "warrior", 2 => "miner", 3 => "assault", 4 => "healer", 5 => "archer", 6 => "mikawa", 7 => "cancel", 8 => "builder"];
            if (isset($kits[$data])) {
                $this->plugin->kitManager->applyKit($player, $kits[$data]);
            }
        });
        $form->setTitle("§l§eONPU Kit Select");
        $form->setContent("§f使用したいKitを選んでください。");
        $form->addButton("§l§7初期装備");
        $form->addButton("§l§c剣士");
        $form->addButton("§l§b採掘士");
        $form->addButton("§l§6突撃兵");
        $form->addButton("§l§d僧侶");
        $form->addButton("§l§a狩人");
        $form->addButton("§l§3身躱神");
        $form->addButton("§l§5キャンセラー");
        $form->addButton("§l§e建築士");
        $player->sendForm($form);
    }

    public function sendShopForm(Player $player): void {
        $form = new SimpleForm(function (Player $player, $data) {
            if ($data === null) return;
            $items = [
                0 => ["name" => "Speed Pot", "cost" => 1000, "item" => VanillaItems::SPLASH_POTION()->setType(PotionType::SWIFTNESS())],
                1 => ["name" => "Strength Pot", "cost" => 2000, "item" => VanillaItems::SPLASH_POTION()->setType(PotionType::STRENGTH())],
                2 => ["name" => "Regen Pot", "cost" => 3000, "item" => VanillaItems::SPLASH_POTION()->setType(PotionType::REGENERATION())],
                3 => ["name" => "Invisibility", "cost" => 3500, "item" => VanillaItems::SPLASH_POTION()->setType(PotionType::INVISIBILITY())],
                4 => ["name" => "Ender Pearl", "cost" => 3000, "item" => VanillaItems::ENDER_PEARL()],
                5 => ["name" => "Golden Apple", "cost" => 1000, "item" => VanillaItems::GOLDEN_APPLE()],
                6 => ["name" => "Lapis (Enchant)", "cost" => 100, "item" => VanillaItems::LAPIS_LAZULI()->setCount(32)]
            ];
            if (isset($items[$data])) {
                $product = $items[$data];
                $name = $player->getName();
                $dbData = $this->plugin->getPlayerData($name);
                
                if ($dbData["money"] >= $product["cost"]) {
                    $this->plugin->addStat($name, "money", -$product["cost"]);
                    $player->getInventory()->addItem($product["item"]);
                    $player->sendMessage("§a[ONPU] §f" . $product["name"] . "を購入しました！");
                } else {
                    $player->sendMessage("§c[ONPU] §fお金が足りません！");
                }
            }
        });
        $d = $this->plugin->getPlayerData($player->getName());
        $form->setTitle("§l§eONPU Shop");
        $form->setContent("§fMoney: §e" . $d["money"]);
        $form->addButton("Speed Pot (1000)");
        $form->addButton("Strength Pot (2000)");
        $form->addButton("Regen Pot (3000)");
        $form->addButton("Invisibility (3500)");
        $form->addButton("Ender Pearl (3000)");
        $form->addButton("Golden Apple (1000)");
        $form->addButton("Lapis (100)");
        $player->sendForm($form);
    }
}

// --- PMMP用 簡易フォーム作成クラス (変更なし) ---
class SimpleForm implements Form {
    private $callable;
    private array $data = [];

    public function __construct(?callable $callable) {
        $this->callable = $callable;
        $this->data["type"] = "form";
        $this->data["title"] = "";
        $this->data["content"] = "";
        $this->data["buttons"] = [];
    }

    public function setTitle(string $title): void { $this->data["title"] = $title; }
    public function setContent(string $content): void { $this->data["content"] = $content; }
    public function addButton(string $text, int $imageType = -1, string $imagePath = ""): void { 
        $content = ["text" => $text];
        if ($imageType !== -1) { $content["image"] = ["type" => $imageType === 0 ? "path" : "url", "data" => $imagePath]; } 
        $this->data["buttons"][] = $content;
    }
    public function handleResponse(Player $player, $data): void { 
        if ($this->callable !== null) { ($this->callable)($player, $data); } 
    }
    public function jsonSerialize(): array { return $this->data; }
}