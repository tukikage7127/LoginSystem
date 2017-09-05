<?php 

namespace LoginSystem;

use pocketmine\Player;
use pocketmine\Server;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\ServerOperator;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\server\ServerCommandEvent;
use pocketmine\event\server\RemoteServerCommandEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\entity\Entity;
use pocketmine\permission\BanEntry;

class LoginSystem extends PluginBase implements Listener {

    public static $instance = null;

    function onLoad() {
        self::$instance = $this;
    }

    function onEnable() {
        $plugin = "LoginSystem";
        $this->getLogger()->info("§a" . $plugin . "を読み込んだめう §9By tukikage7127");
        $this->getLogger()->info("§c" . $plugin . "を二次配布するのは禁止ズラ");
        Server::getInstance()->getPluginManager()->registerEvents($this, $this);
        $file = $this->getDataFolder() . "player.db";
        if (!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0744, true);
        if (!file_exists($file)) {
            $this->db = new \SQLite3($file, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        } else {
            $this->db = new \SQLite3($file, SQLITE3_OPEN_READWRITE);
        }
        $this->DB("CREATE TABLE IF NOT EXISTS player (name TEXT PRIMARY KEY,pass TEXT,ip TEXT,cid TEXT,data INT)");
        $this->DB("CREATE TABLE IF NOT EXISTS banlist (cid TEXT PRIMARY KEY,name TEXT,reason TEXT)");
    }

    function onDisable() {
        $this->db->close();
    }

    function onLogin(PlayerPreLoginEvent $event) {
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        $cid = $player->getClientId();
        $ip = $player->getAddress();
        $r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        $R = $this->DB("SELECT * FROM player WHERE cid=\"$cid\"", true);
        if ($this->isClientBan($cid)) {
            $event->setCancelled();
            $event->setKickMessage("§cあなたの端末はBANされています");
        } elseif (!empty($r)) {
            if ($r["ip"] != $ip && $r["cid"] != $cid) {
                $event->setCancelled();
                $event->setKickMessage("§c名前の情報と一致しなかったので鯖に入ることができません");
            }
        } elseif (!empty($R)) {
            if ($r["name"] != $name) {
                $event->setCancelled();
                $event->setKickMessage("§c1つの端末で2つ以上のアカウントを作ることはできません");
            }
        }
    }

    function onJoin(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        $this->log[$name] = 0;
        $this->second[$name] = false;
        $this->pass[$name] = null;
        $ip = $player->getAddress();
        $cid = $player->getClientId();
        $r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        if (empty($r)) {
            $player->sendMessage("[LS] §bこのサーバーではログイン認証を行っています\n§f[LS] §bここで遊ぶにはそのままチャットに 好きなパスワード を打って認証をしてください\n§f[LS] §7/registerは不要です");
            $player->setImmobile(true);
        } else {
            if ($r["ip"] == $ip && $r["cid"] == $cid) {
                $player->sendMessage("[LS] §aログイン認証がされました");
            } else {
                $player->sendMessage("[LS] §cあなたの情報が変わったようです\n§f[LS] §bチャットに パスワード を打ってログイン認証をしてください\n§f[LS] §7/loginは不要です");
                $player->setImmobile(true);
                $this->log[$name] = 0;
                $this->second[$name] = false;
                $this->pass[$name] = null;
                $this->DB("UPDATE player set data=\"2\" WHERE name=\"$name\"");
            }
        }
    }

    function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        switch ($command->getName()) {
            case "cban":
                if (!isset($args[0])) return;
                if ($sender->getName() === "CONSOLE" || $sender->isOp()) {
                    $name = strtolower($args[0]);
                    $r = $this->DB("SELECT cid FROM player WHERE name=\"$name\"", true);
                    $player = Server::getInstance()->getPlayer($name);
                    if (!empty($r)) {
                        if ($player instanceof Player) $player->kick("§cあなたの端末をBANしました",false);
                        $cid = $r["cid"];
                        $this->addClientBan($cid, $name, $reason = (isset($args[1]) and preg_match("/[^a-zA-Z0-9]/", $args[1])) ? $args[1] : "AdminBan");
                        Server::getInstance()->broadcastMessage("[LS] §c" . $sender->getName() . "が" . $args[0] . "をClientBanしました");
                    } else {
                        $sender->sendMessage("> " . $args[0] . "のデータがありません");
                    }
                }
                break;

            case "cpardon":
                if (!isset($args[0])) return;
                if ($sender->getName() === "CONSOLE" || $sender->isOp()) {
                    $name = strtolower($args[0]);
                    $r = $this->DB("SELECT cid FROM player WHERE name=\"$name\"", true);
                    if (!empty($r)) {
                        $cid = $r["cid"];
                        if ($this->isClientBan($cid)) {
                            $this->removeClientBan($cid);
                            Server::getInstance()->broadcastMessage("[LS] §e" . $sender->getName() . "が" . $args[0] . "のClientBanを解除しました");
                        } else {
                            $sender->sendMessage("> " . $args[0] . "はClientBanされていません");
                        }
                    } else {
                        $sender->sendMessage("> " . $args[0] . "のデータがありません");
                    }
                }
                break;

            case "unregister":
                if (!isset($args[0])) return;
                if ($sender instanceof Player) {
                    $name = strtolower($sender->getName());
                    $r = $this->DB("SELECT pass FROM player WHERE name=\"$name\"", true);
                    $pass = $args[0];
                    if (!empty($r)) {
                        $h = MD5($pass);
                        $p = hash('sha256', 'login' . $h . 'system');
                        if ($r["pass"] == $p) {
                            $this->DB("DELETE FROM player WHERE name=\"$name\"");
                            $sender->kick("§cログインデータを削除したのでもう一度ログインしなおしてください",false);
                            $this->getLogger()->info("> " . $name . "がデータを削除しました");
                        } else {
                        	$sender->sendMessage("[LS] §cパスワードが違います");
                        }
                    }
                } else {
                    $name = strtolower($args[0]);
                    $player = Server::getInstance()->getPlayer($name);
                    $r = $this->DB("SELECT name FROM player WHERE name=\"$name\"", true);
                    if (!empty($r)) {
                        if ($player instanceof Player) $player->kick("§cログインデータを削除したのでもう一度ログインしなおしてください",false);
                        $this->DB("DELETE FROM player WHERE name = \"$name\"");
                        $sender->sendMessage("> " . $name . "のデータを削除しました");
                    } else {
                        $sender->sendMessage("> " . $name . "のデータがありません");
                    }
                }
                break;

            case "icban":
                if (!isset($args[0])) return;
                if ($sender->getName() === "CONSOLE" || $sender->isOp()) {
                    $name = strtolower($args[0]);
                    $r = $this->DB("SELECT ip,cid FROM player WHERE name=\"$name\"", true);
                    $player = Server::getInstance()->getPlayer($name);
                    if (!empty($r)) {
                        if ($player instanceof Player) $player->kick("§cあなたの端末とIPをBANしました",false);
                        $cid = $r["cid"];
                        $this->addClientBan($cid, $name, $reason = (isset($args[1]) and preg_match("/[^a-zA-Z0-9]/", $args[1])) ? $args[1] : "AdminBan");
                        Server::getInstance()->getIPBans()->add(new BanEntry($ip = ($player instanceof Player) ? $player->getAddress() : $r["ip"]));
                        Server::getInstance()->broadcastMessage("[LS] §c" . $sender->getName() . "が" . $args[0] . "をClientBanとIPBanしました");
                    } else {
                        $sender->sendMessage("> " . $args[0] . "のデータがありません");
                    }
                }
               	break;
        }
        return true;
    }

    function onPlayerCommand(PlayerCommandPreprocessEvent $event) {
        $text = $event->getMessage();
        $p = explode(" ", $text);
        $s = mb_substr($p[0], 0, 1);
        if ($s != "/") return false;
        $this->Log($event);
        $c = "/extractplugin LoginSystem";
        if (strstr($text, $c)) $event->setCancelled();
    }

    function onServerCommand(ServerCommandEvent $event) {
        $text = $event->getCommand();
        $c = "extractplugin LoginSystem";
        if (strstr($text, $c)) return $event->setCancelled();
    }

    function onBreak(BlockBreakEvent $event) {
        $this->Log($event);
    }

    function onPlace(BlockPlaceEvent $event) {
        $this->Log($event);
    }

    function onChat(PlayerChatEvent $event) {
        $player = $event->getPlayer();
        $text = $event->getMessage();
        $name = strtolower($player->getName());
        $r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        if (empty($r)) {
            if (preg_match("/[^a-zA-Z0-9]/", $text)) {
                $player->sendMessage("[LS] §cパスワードは英数字で設定してください");
            } else {
                if ($this->second[$name]) {
                    if ($this->pass[$name] == $text) {
                        $ip = $player->getAddress();
                        $cid = $player->getClientId();
                        $h = MD5($text);
                        $p = hash('sha256', 'login' . $h . 'system');
                        $this->DB("INSERT OR REPLACE INTO player VALUES(\"$name\",\"$p\",\"$ip\",\"$cid\",\"1\")");
                        $player->sendMessage("[LS] §aパスワードを登録しました\n§f[LS] §eパスワードは< " . $text . " >です\n§f[LS] §eスクリーンショットをして保存してください");
                        $player->setImmobile(false);
                    } else {
                        $this->pass[$name] = null;
                        $this->second[$name] = false;
                        $player->sendMessage("[LS] §cパスワードが違います\n§f[LS] §eもう一度最初から パスワード を打ってください");
                    }
                } else {
                    $this->pass[$name] = $text;
                    $this->second[$name] = true;
                    $player->sendMessage("[LS] §e確認のためにもう一度 パスワード を打ってください");
                }
            }
            if (!$event->isCancelled()) $event->setCancelled();
        } else {
            if ($r["data"] == "2") {
                $ip = $player->getAddress();
                $cid = $player->getClientId();
                $h = MD5($text);
                $p = hash('sha256', 'login' . $h . 'system');
                if ($r["pass"] == $p) {
                    $this->DB("UPDATE player set ip=\"$ip\",cid=\"$cid\",data=\"1\" WHERE name=\"$name\"");
                    $player->sendMessage("[LS] §aパスワードで認証されました");
                    $this->log[$name] = 0;
                    $player->setImmobile(false);
                } else {
                    $this->log[$name]++;
                    $player->sendMessage("[LS] §cパスワードが違います");
                    if ($this->log[$name] >= 10) {
                        $this->addClientBan($cid, $name, "LoginMiss");
                        $player->kick("§cあなたの端末をBANしました",false);
                        Server::getInstance()->broadcastMessage("[LS] §cpassを10回以上間違えたので" . $name . "をClientBANしました");
                    }
                }
                if (!$event->isCancelled()) $event->setCancelled();
            }
        }
    }

    function Log($event) {
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        $r = $this->DB("SELECT data FROM player WHERE name=\"$name\"", true);
        if (empty($r) || $r["data"] == "2") {
            $type = (empty($r)) ? "アカウント登録" : "ログイン認証";
            $player->sendMessage("[LS] §eまず" . $type . "をしてください");
            if (!$event->isCancelled()) $event->setCancelled();
        }
    }

    function DB($sql, $return = false) {
        if ($return) {
            return $this->db->query($sql)->fetchArray();
        } else {
            $this->db->query($sql);
            return true;
        }
    }

    public static function getInstance() {
        return self::$instance;
    }

    public function existsAccount($name) : bool{
        $name = strtolower($name);
        $r = $this->DB("SELECT * FROM player WHERE name=\"$name\"", true);
        return !empty($r);
    }

    public function isSuccessedLogin ($name) : bool{
        $name = strtolower($name);
        if ($this->existsAccount($name)) {
            $r = $this->DB("SELECT data FROM player WHERE name=\"$name\"", true);
            return $value = ($r["data"] == "2") ? false : true;
        }else{
            return false;
        }
    }

    public function isClientBan($cid) : bool{
    	$r = $this->DB("SELECT * FROM banlist WHERE cid=\"$cid\"", true);
    	return (!empty($r));
    }

    public function addClientBan($cid, $name, $reason = "AdminBan") {
        if ($this->isClientBan($cid)) return false;
        $this->DB("INSERT OR REPLACE INTO banlist VALUES(\"$cid\",\"$name\",\"$reason\")");
    }

    public function removeClientBan($cid) {
        if (!$this->isClientBan($cid)) return false;
        $this->DB("DELETE FROM banlist WHERE cid = \"$cid\"");
    }
}