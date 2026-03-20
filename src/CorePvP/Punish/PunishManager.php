<?php
namespace CorePvP\Punish;

use CorePvP\Main;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\utils\Config;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\entity\Skin;

class PunishManager implements Listener {
    private Main $plugin;
    public Config $db;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->db = new Config($this->plugin->getDataFolder() . "punish.json", Config::JSON);
        $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        // OPのみ実行可能
        if (!$this->plugin->getServer()->isOp($sender->getName())) {
            $sender->sendMessage("§c権限がありません。");
            return true;
        }

        // --- BANコマンド ---
        if ($command->getName() === "ban") {
            if (count($args) < 2) {
                $sender->sendMessage("§c使い方: /ban [名前] [理由]");
                return true;
            }
            $targetName = strtolower(array_shift($args));
            $reason = implode(" ", $args);
            
            $targetPlayer = $this->plugin->getServer()->getPlayerByPrefix($targetName);
            $ip = $targetPlayer ? $targetPlayer->getNetworkSession()->getIp() : "";
            
            $this->banPlayer($targetName, $ip, $reason);
            $sender->sendMessage("§a[ONPU] §f{$targetName} をBANしました。理由: {$reason}");
            
            if ($targetPlayer) {
                $targetPlayer->kick("§cあなたはBANされています。\n§f理由: {$reason}");
            }
            return true;
        }

        // --- WARNコマンド ---
        if ($command->getName() === "warn") {
            if (count($args) < 2) {
                $sender->sendMessage("§c使い方: /warn [名前] [理由]");
                return true;
            }
            $targetName = strtolower(array_shift($args));
            $reason = implode(" ", $args);
            
            $targetPlayer = $this->plugin->getServer()->getPlayerByPrefix($targetName);
            $actualName = $targetPlayer ? strtolower($targetPlayer->getName()) : $targetName;
            
            $warns = $this->getWarnCount($actualName) + 1;
            
            if ($warns >= 3) {
                // 3回目の警告：自動BAN処理（理由を引き継ぐ）
                $banReason = "警告3回累積による自動BAN (最後の違反: {$reason})";
                $ip = $targetPlayer ? $targetPlayer->getNetworkSession()->getIp() : "";
                
                $this->banPlayer($actualName, $ip, $banReason);
                $sender->sendMessage("§c[ONPU] §f{$actualName} は警告が3回に達したため自動的にBANされました。");
                
                if ($targetPlayer) {
                    $targetPlayer->kick("§cあなたはBANされています。\n§f理由: {$banReason}");
                }
            } else {
                // 1回目・2回目の警告処理
                $this->addWarn($actualName);
                $muteUntil = time() + (7 * 24 * 60 * 60); // 1週間のミュート
                $this->setMute($actualName, $muteUntil);
                
                $sender->sendMessage("§a[ONPU] §f{$actualName} に警告を与えました (現在 {$warns} 回目)。理由: {$reason}");
                
                if ($targetPlayer) {
                    $targetPlayer->sendMessage("§c[警告] §fあなたはルール違反により警告を受けました。\n§f理由: {$reason}\n§eペナルティとして『1週間のチャットミュート』と『スキンの初期化』が適用されました。");
                    $this->playSound($targetPlayer, "note.bass", 1.0, 0.5); // 警告アラート音
                    
                    // スキンの強制初期化
                    $this->setDefaultSkin($targetPlayer);
                    
                    // GameManagerのスコアボード更新を呼んで、ネームタグに⚠️マークを即座に反映させる
                    $this->plugin->gameManager->updateScoreboard($targetPlayer);
                }
            }
            return true;
        }

        // --- ★新規追加：UNWARNコマンド ---
        if ($command->getName() === "unwarn") {
            if (count($args) < 1) {
                $sender->sendMessage("§c使い方: /unwarn [名前]");
                return true;
            }
            $targetName = strtolower($args[0]);
            $targetPlayer = $this->plugin->getServer()->getPlayerByPrefix($targetName);
            $actualName = $targetPlayer ? strtolower($targetPlayer->getName()) : $targetName;

            // 警告回数とミュートをリセット
            $this->resetWarn($actualName);
            $this->removeMute($actualName);

            $sender->sendMessage("§a[ONPU] §f{$actualName} の警告とチャットミュートを完全に解除しました。");

            if ($targetPlayer) {
                $targetPlayer->sendMessage("§a[通知] §f運営により、あなたの警告とチャットミュートが解除されました。");
                $this->playSound($targetPlayer, "random.levelup", 1.0, 1.0);
                
                // ネームタグから⚠️マークを消すために更新をかける
                $this->plugin->gameManager->updateScoreboard($targetPlayer);
            }
            return true;
        }

        return false;
    }

    public function banPlayer(string $name, string $ip, string $reason): void {
        $name = strtolower($name);
        $data = $this->db->get("bans", []);
        $data[$name] = ["reason" => $reason, "ip" => $ip];
        $this->db->set("bans", $data);
        $this->db->save();
    }

    public function isBanned(string $name, string $ip): ?string {
        $name = strtolower($name);
        $bans = $this->db->get("bans", []);
        if (isset($bans[$name])) return $bans[$name]["reason"];
        
        // IPBANのチェック
        foreach ($bans as $bannedName => $data) {
            if ($data["ip"] !== "" && $data["ip"] === $ip) {
                return $data["reason"];
            }
        }
        return null;
    }

    public function getWarnCount(string $name): int {
        $name = strtolower($name);
        $warns = $this->db->get("warns", []);
        return $warns[$name] ?? 0;
    }

    public function addWarn(string $name): void {
        $name = strtolower($name);
        $warns = $this->db->get("warns", []);
        $warns[$name] = ($warns[$name] ?? 0) + 1;
        $this->db->set("warns", $warns);
        $this->db->save();
    }

    // ★ 警告リセット用メソッド
    public function resetWarn(string $name): void {
        $name = strtolower($name);
        $warns = $this->db->get("warns", []);
        if (isset($warns[$name])) {
            unset($warns[$name]);
            $this->db->set("warns", $warns);
            $this->db->save();
        }
    }

    public function setMute(string $name, int $until): void {
        $name = strtolower($name);
        $mutes = $this->db->get("mutes", []);
        $mutes[$name] = $until;
        $this->db->set("mutes", $mutes);
        $this->db->save();
    }

    // ★ ミュート解除用メソッド
    public function removeMute(string $name): void {
        $name = strtolower($name);
        $mutes = $this->db->get("mutes", []);
        if (isset($mutes[$name])) {
            unset($mutes[$name]);
            $this->db->set("mutes", $mutes);
            $this->db->save();
        }
    }

    public function isMuted(string $name): bool {
        $name = strtolower($name);
        $mutes = $this->db->get("mutes", []);
        if (isset($mutes[$name])) {
            if (time() < $mutes[$name]) {
                return true;
            } else {
                // 1週間経過したらミュート自動解除
                unset($mutes[$name]);
                $this->db->set("mutes", $mutes);
                $this->db->save();
            }
        }
        return false;
    }

    public function hasWarn(string $name): bool {
         return $this->getWarnCount($name) > 0;
    }

    public function onPreLogin(PlayerPreLoginEvent $event): void {
        $info = $event->getPlayerInfo();
        $name = $info->getUsername();
        $ip = $event->getIp();
        
        $reason = $this->isBanned($name, $ip);
        if ($reason !== null) {
            $event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_BANNED, "§cあなたはBANされています。\n§f理由: {$reason}");
        }
    }

    public function onChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        if ($this->isMuted($player->getName())) {
            $player->sendMessage("§c[警告] あなたはルール違反によりチャットが制限されています。");
            $event->cancel(); 
        }
    }

    private function playSound(Player $player, string $soundName, float $volume = 1.0, float $pitch = 1.0): void { 
        $pk = new PlaySoundPacket();
        $pk->soundName = $soundName; 
        $pk->x = $player->getPosition()->x; 
        $pk->y = $player->getPosition()->y; 
        $pk->z = $player->getPosition()->z; 
        $pk->volume = $volume; 
        $pk->pitch = $pitch; 
        $player->getNetworkSession()->sendDataPacket($pk);
    }
    
    public function setDefaultSkin(Player $player): void {
        try {
            $skinData = str_repeat("\x00", 64 * 64 * 4);
            $newSkin = new Skin("Standard_Custom", $skinData, "", "geometry.humanoid.custom");
            $player->setSkin($newSkin);
            $player->sendSkin();
        } catch (\Exception $e) {}
    }
}