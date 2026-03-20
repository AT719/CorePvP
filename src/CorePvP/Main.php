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

class Main extends PluginBase {
    public Config $db;
    
    public GameManager $gameManager;
    public KitManager $kitManager;
    public MapManager $mapManager;
    public FormManager $formManager;

    protected function onEnable(): void {
        $this->db = new Config($this->getDataFolder() . "players.json", Config::JSON);
        
        $this->mapManager = new MapManager($this);
        $this->kitManager = new KitManager($this);
        $this->formManager = new FormManager($this);
        $this->gameManager = new GameManager($this);
        
        // ★サーバー起動時に、視察用の全ロビーマップを読み込む（自動変換も行われます）
        $this->mapManager->loadAllWorlds();
        
        $this->getLogger()->info("§a[ONPU] CorePvP v16.0 読み込み完了！");
    }

    protected function onDisable(): void {
        $this->db->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
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
}