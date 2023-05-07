<?php

namespace HeyItzKillerMC;

use pocketmine\utils\Config;
use pocketmine\player\Player;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\utils\TextFormat;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\command\CommandSender;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerChatEvent;

class Main extends PluginBase implements Listener {

    private $config;
    private $frozenPlayers = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
            return false;
        }

        if (count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /" . $command->getName() . " <player>");
            return false;
        }

        $target = $this->getServer()->getPlayerExact($args[0]);

        if ($target === null) {
            $sender->sendMessage(TextFormat::RED . "Player not found.");
            return false;
        }

        switch ($command->getName()) {
            case "ss":
            case "freeze":
                if ($this->isPlayerFrozen($target)) {
                    $this->unfreezePlayer($target);
                    $sender->sendMessage(TextFormat::GREEN . $target->getName() . " has been unfrozen.");
                } else {
                    $this->freezePlayer($target);
                    $sender->sendMessage(TextFormat::GREEN . $target->getName() . " has been frozen.");
                }
                $this->broadcastToOppedPlayers(TextFormat::GRAY . TextFormat::ITALIC . "[" . $sender->getName() . ": " . ($this->isPlayerFrozen($target) ? "froze" : "unfroze") . " " . $target->getName() . "]");
                break;
            case "unfreeze":
                if ($this->isPlayerFrozen($target)) {
                    $this->unfreezePlayer($target);
                    $sender->sendMessage(TextFormat::GREEN . $target->getName() . " has been unfrozen.");
                    $this->broadcastToOppedPlayers(TextFormat::GRAY . TextFormat::ITALIC . "[" . $sender->getName() . ": " . "unfroze " . $target->getName() . "]");
                } else {
                    $sender->sendMessage(TextFormat::RED . $target->getName() . " is not frozen.");
                }
                break;
            default:
                return false;
        }
        return true;
    }

    private function broadcastToOppedPlayers(string $message): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($player->hasPermission("freezeplugin.broadcast")) {
                $player->sendMessage($message);
            }
        }
    }

    /**
     * @param PlayerChatEvent $event
     * @priority MONITOR
     * @ignoreCancelled true
     */
    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        if ($this->isPlayerFrozen($player)) {
            $event->cancel();

            $frozenPrefix = TextFormat::BOLD . TextFormat::RED . "FROZEN " . TextFormat::RESET;
            $formattedMessage = $frozenPrefix . $player->getName() . ": " . $event->getMessage();

            $recipients = $event->getRecipients();
            foreach ($recipients as $recipient) {
                if ($recipient instanceof Player && $recipient->hasPermission("freezeplugin.frozenchat")) {
                    $recipient->getNetworkSession()->onChatMessage($formattedMessage);
                }
            }
        }
    }

    private function broadcastToStaff(string $message, string $permission): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($player->hasPermission($permission)) {
                $player->sendMessage($message);
            }
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if ($this->isPlayerFrozen($player)) {
            $event->setTo($event->getFrom());
        }
    }

    public function freezePlayer(Player $player): void {
        $playerName = $player->getName();
        $world = $this->config->get("world");

        $worldManager = $this->getServer()->getWorldManager();
        $level = $worldManager->getWorldByName($world);

        if (!$level) {
            $this->getLogger()->warning("World '$world' not found. Please check your config.yml");
            return;
        }

        $spawn = $level->getSafeSpawn();
        $player->teleport($spawn);
        $player->sendTitle("§cYou have been frozen");
        $taskHandler = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function () use ($player): void {
            if (!$player->isOnline()) {
                $this->unfreezePlayer($player);
                return;
            }

            if ($this->isPlayerFrozen($player)) {
                $player->sendTitle("§cYou have been frozen");
            }
        }), 20 * 5);

        $this->frozenPlayers[$playerName] = $taskHandler;
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
    }

    public function unfreezePlayer(Player $player): void {
        $playerName = $player->getName();
        if (!isset($this->frozenPlayers[$playerName])) {
            return;
        }

        $taskHandler = $this->frozenPlayers[$playerName];
        $taskHandler->cancel();
        unset($this->frozenPlayers[$playerName]);

        if ($player->isOnline()) {
            $player->sendTitle("§aYou have been unfrozen");
        }
    }

    public function isPlayerFrozen(Player $player): bool {
        return isset($this->frozenPlayers[$player->getName()]);
    }
}
