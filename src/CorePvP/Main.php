<?php
namespace CorePvP;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use CorePvP\Game\GameManager;
use CorePvP\Kit\KitManager;
use CorePvP\Map\MapManager;
use CorePvP\Form\FormManager;
// ★ 新規追加：PunishManager を読み込む
use CorePvP\Punish\PunishManager;

class Main extends PluginBase {
    public Config $db;
    
    public GameManager $gameManager;
    public KitManager $kitManager;
    public MapManager $mapManager;
    public FormManager $formManager;
    public PunishManager $punishManager; // ★ 新規追加

    protected function onEnable(): void {
        $this->db = new Config($this->getDataFolder() . "players.json", Config::JSON);
        
        $this->mapManager = new MapManager($this);
        $this->kitManager = new KitManager($this);
        $this->formManager = new FormManager($this);
        $this->punishManager = new PunishManager($this); // ★ 処罰システムの起動
        $this->gameManager = new GameManager($this);
        
        $this->mapManager->loadAllWorlds();
        
        $this->getLogger()->info("§a[ONPU] CorePvP v16.0 (Punish & Rank System) 読み込み完了！");
    }

    protected function onDisable(): void {
        $this->db->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        // PunishManager に /ban や /warn コマンドの処理を任せる
        if ($this->punishManager->onCommand($sender, $command, $label, $args)) {
            return true;
        }
        return $this->gameManager->onCommand($sender, $command, $label, $args);
    }

    public function getPlayerData(string $name): array {
        $defaults = ["money" => 1000, "np" => 500, "kills" => 0, "deaths" => 0, "exp" => 0, "level" => 1];
        if (!$this->db->exists($name)) {
            $this->db->set($name, $defaults);
            $this->db->save();
            return $defaults;
        }
        return array_merge($defaults, $this->db->get($name));
    }

    public function addStat(string $name, string $key, int $amount): void {
        $data = $this->getPlayerData($name);
        $data[$key] += $amount;
        $this->db->set($name, $data);
        $this->db->save();
    }

    // ★ 新規追加：現在のEXPからレベルを自動計算するシステム
    // 指定された計算式 [レベル × (1111 + 11 × レベル)] を元に算出します
    public function getLevelData(int $exp): array {
        $level = 1;
        $totalExpNeeded = 0;
        
        while (true) {
            $requiredForNext = $level * (1111 + 11 * $level);
            if ($exp >= $totalExpNeeded + $requiredForNext) {
                $totalExpNeeded += $requiredForNext;
                $level++;
            } else {
                break;
            }
        }
        
        return [
            "level" => $level,
            "rankFormat" => $this->getRankFormat($level) // 色付きのランク記号を取得
        ];
    }

    // ★ 新規追加：レベル帯に応じたランク(E～N)とカラーを決定
    public function getRankFormat(int $level): string {
        if ($level <= 29)  return "§7[E]"; // グレー
        if ($level <= 59)  return "§d[D]"; // ピンク
        if ($level <= 89)  return "§6[C]"; // オレンジ
        if ($level <= 119) return "§b[B]"; // 水色
        if ($level <= 149) return "§a[A]"; // 黄緑
        if ($level <= 179) return "§e[S]"; // 黄色
        if ($level <= 209) return "§5[X]"; // 紫色
        if ($level <= 239) return "§0[Z]"; // 黒色 (マイクラの黒は§0です)
        return "§l§6[N]";                  // 太字の金色
    }
}