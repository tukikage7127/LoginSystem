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
        $this->Ban = new Config($this->getDataFolder() . "Ban.yml", Config::YAML);
        if (!file_exists($file)) {
            $this->db = new \SQLite3($file, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        } else {
            $this->db = new \SQLite3($file, SQLITE3_OPEN_READWRITE);
        }
        $this->DB("CREATE TABLE IF NOT EXISTS player (name TEXT PRIMARY KEY,pass TEXT,ip TEXT,cid TEXT,data INT)");
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
        if ($this->Ban->exists($cid)) {
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

    function onJoin(PlayerJoinEvent $G) {
        $B = $G->getPlayer();
        $A = strtolower($B->getName());
        $this->log[$A] = 0;
        $this->second[$A] = false;
        $this->pass[$A] = null;
        $i = $B->getAddress();
        $d = $B->getClientId();
        $r = $this->DB("SELECT * FROM player WHERE name=\"$A\"", true);
        if (empty($r)) {
            $B->sendMessage("[LS] §bこのサーバーではログイン認証を行っています\n§f[LS] §bここで遊ぶにはそのままチャットに 好きなパスワード を打って認証をしてください\n§f[LS] §7/registerは不要です");
            $B->setImmobile(true);
        } else {
            if ($r["ip"] == $i && $r["cid"] == $d) {
                $B->sendMessage("[LS] §aログイン認証がされました");
            } else {
                $B->sendMessage("[LS] §cあなたの情報が変わったようです\n§f[LS] §bチャットに パスワード を打ってログイン認証をしてください\n§f[LS] §7/loginは不要です");
                $B->setImmobile(true);
                $this->log[$A] = 0;
                $this->second[$A] = false;
                $this->pass[$A] = null;
                $this->DB("UPDATE player set data=\"2\" WHERE name=\"$A\"");
            }
        }
    }

    function onCommand(CommandSender $sender, Command $command, $label, array $args) {
        switch ($command->getName()) {
            case "cban":
                if (!isset($args[0])) return;
                if ($sender->getName() === "CONSOLE" || $sender->isOp()) {
                    $name = strtolower($args[0]);
                    $r = $this->DB("SELECT cid FROM player WHERE name=\"$name\"", true);
                    $player = Server::getInstance()->getPlayer($name);
                    if (!empty($r)) {
                        if ($player instanceof Player) $player->close("", "§cあなたの端末をBANしました");
                        $cid = $r["cid"];
                        $this->addClientBan($cid, $name);
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
                        if ($this->Ban->exists($cid)) {
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
                            $sender->close("", "§cログインデータを削除したのでもう一度ログインしなおしてください");
                            $this->getLogger()->info("> " . $name . "がデータを削除しました");
                        }
                    }
                } else {
                    $name = strtolower($args[0]);
                    $player = Server::getInstance()->getPlayer($name);
                    $r = $this->DB("SELECT name FROM player WHERE name=\"$name\"", true);
                    if (!empty($r)) {
                        if ($player instanceof Player) $player->close("", "§cログインデータを削除したのでもう一度ログインしなおしてください");
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
                        if ($player instanceof Player) $player->close("", "§cあなたの端末とIPをBANしました");
                        $cid = $r["cid"];
                        $this->addClientBan($cid, $name);
                        Server::getInstance()->getIPBans()->add(new BanEntry($r["ip"]));
                        Server::getInstance()->broadcastMessage("[LS] §c" . $sender->getName() . "が" . $args[0] . "をClientBanとIPBanしました");
                    } else {
                        $I->sendMessage("> " . $args[0] . "のデータがありません");
                    }
                }
                break;
        }
    }

    function onPlayerCommand(PlayerCommandPreprocessEvent $event) {
        $text = $event->getMessage();
        $p = explode(" ", $t);
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
            if (!$event->isCancelled()) $event->setCancelled();
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
        } else {
            if ($r["data"] == "2") {
                $event->setCancelled();
                $ip = $player->getAddress();
                $cid = $player->getClientId();
                $h = MD5($s);
                $p = hash('sha256', 'login' . $h . 'system');
                if ($r["pass"] == $p) {
                    $this->DB("UPDATE player set ip=\"$ip\",cid=\"$cid\",data=\"1\"WHERE name=\"$name\"");
                    $player->sendMessage("[LS] §aパスワードで認証されました");
                    $this->log[$name] = 0;
                    $player->setImmobile(false);
                } else {
                    $this->log[$name]++;
                    $player->sendMessage("[LS] §cパスワードが違います");
                    if ($this->log[$name] >= 10) {
                        $this->addClientBan($cid, $name, "LoginMiss");
                        $player->close("", "§cあなたの端末をBANしました");
                        Server::getInstance()->broadcastMessage("[LS] §cpassを10回以上間違えたので" . $name . "をClientBANしました");
                    }
                }
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
            return $this->db->query($s)->fetchArray();
        } else {
            $this->db->query($s);
            return true;
        }
    }

    public static function getInstance() {
        return self::$instance;
    }

    public function addClientBan($cid, $name, $reason = "AdminBan") {
        if ($this->Ban->exists($cid)) return false;
        $this->Ban->set($cid, $name . ": " . $reason);
        $this->Ban->save();
    }

    public function removeClientBan($cid) {
        if (!$this->Ban->exists($cid)) return false;
        $this->Ban->remove($cid);
        $this->Ban->save();
    }
}