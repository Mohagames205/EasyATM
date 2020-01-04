<?php

namespace mohagames\pinautomaat;

use mohagames\PlotArea\utils\Location;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\level\Explosion;
use pocketmine\level\Position;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\ItemIds;
use jojoe77777\FormAPI\ModalForm;
use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use onebone\economyapi\EconomyAPI;
use pocketmine\item\Bow;
use pocketmine\block\Block;
use pocketmine\level\Level;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\item\Totem;
use pocketmine\math\Vector3;


class Pinautomaat extends PluginBase implements Listener
{

    public $make_atm;
    public $del_atm;

    public function onEnable(): void
    {
        $this->db = new \SQLite3($this->getDataFolder() . "atms.db");
        $this->db->query("CREATE TABLE IF NOT EXISTS atm(atm_id INTEGER PRIMARY KEY AUTOINCREMENT, atm_location TEXT, atm_level TEXT)");
        $c = new Config($this->getDataFolder() . "config.yml", Config::YAML, ["money-lore" => "CakeMoney", "money" => [1 => ItemIds::GOLD_NUGGET, 10 => ItemIds::IRON_INGOT, 20 => ItemIds::GOLD_INGOT, 100 => ItemIds::REDSTONE, 200 => ItemIds::EMERALD, 500 => ItemIds::DIAMOND], "pin_block_id" => ItemIds::ENDER_CHEST]);
        $c->save();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        switch($command->getName()){
            case "atm":
                if(isset($args[0])){
                    switch($args[0]){
                        case "create":
                            $this->make_atm[$sender->getName()] = true;
                            $sender->sendMessage("§aGelieve nu op de block te klikken waarvan je een ATM wilt maken.");
                            break;

                        case "delete":
                            $this->del_atm[$sender->getName()] = true;
                            $sender->sendMessage("§aGelieve nu op de ATM te klikken die je wilt verwijderen");
                            break;
                    }
                }
                return true;

            default:
                return false;
        }
    }

    public function createATM(Position $loc){
        $location = serialize([$loc->getFloorX(), $loc->getFloorY(), $loc->getFloorZ()]);
        $level_name = $loc->getLevel()->getName();

        $stmt = $this->db->prepare("INSERT INTO atm (atm_location, atm_level) values(:atm_location, :atm_level)");
        $stmt->bindParam("atm_location", $location, SQLITE3_TEXT);
        $stmt->bindParam("atm_level", $level_name, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function deleteATM(Position $loc){
        $location = serialize([$loc->getFloorX(), $loc->getFloorY(), $loc->getFloorZ()]);
        $level_name = $loc->getLevel()->getName();

        $stmt = $this->db->prepare("DELETE FROM atm WHERE atm_location = :location AND atm_level = :level");
        $stmt->bindParam("location", $location, SQLITE3_TEXT);
        $stmt->bindParam("level", $level_name, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function getATM(Position $loc){
        $level = $loc->getLevel()->getName();
        $location = serialize([$loc->getFloorX(), $loc->getFloorY(), $loc->getFloorZ()]);
        $stmt = $this->db->prepare("SELECT * FROM atm WHERE atm_location = :location AND atm_level = :level");
        $stmt->bindParam("location", $location, SQLITE3_TEXT);
        $stmt->bindParam("level", $level, SQLITE3_TEXT);
        $res = $stmt->execute();
        $count = 0;
        while($row = $res->fetchArray()){
            $count++;
        }

        return $count > 0;

    }

    public function makeATMInteract(PlayerInteractEvent $e){
        $player = $e->getPlayer();
        if(isset($this->make_atm[$player->getName()])){
            if($event->getBlock()->getId() == $this->getConfig()->get("pin_block_id")){
                if(!$this->getATM($e->getBlock())) {
                    $e->setCancelled();
                    $this->createATM($e->getBlock());
                    $player->sendMessage("§aDe ATM is succesvol aangemaakt.");
                }
                else{
                    $player->sendMessage("§4Hier staat al een ATM");
                }
            }
            unset($this->make_atm[$player->getName()]);
        }
    }

    public function deleteATMInteract(PlayerInteractEvent $e){
        $player = $e->getPlayer();
        if(isset($this->del_atm[$player->getName()])){
            if($this->getATM($e->getBlock())){
                $e->setCancelled();
                $this->deleteATM($e->getBlock());
                $player->sendMessage("§aDe ATM is succesvol verwijderd.");
            }
            else{
                $player->sendMessage("§4Hier staat geen ATM");
            }
            unset($this->del_atm[$player->getName()]);
        }
    }

    public function storten($player)
    {
        $form = new CustomForm(function (Player $player, $data) {
            if ($data !== null) {
                $aantal = $data[1];
                $geldlijst = $this->getConfig()->get("money");
                $waarde = array_keys($geldlijst)[$data[0]];
                $vwp = array_values($geldlijst)[$data[0]];
                $item = ItemFactory::get($vwp, 0, $aantal);
                $item->setCustomName(TextFormat::BOLD . $waarde);
                $lore = $this->getConfig()->get("money-lore");
                $item->setLore([$lore]);

                if ($player->getInventory()->contains($item) ){
                    $player->getInventory()->removeItem($item);
                    $money = $waarde * $aantal;
                    EconomyAPI::getInstance()->addMoney($player, $money);

                    $player->sendMessage("§aU heeft §2$money §aeuro gestort!");
                } else {
                    $player->sendMessage("§4U heeft niet genoeg contant geld!");
                }


            }
        });

        $geldlijst = $this->getConfig()->get("money");
        $typegeld = array_keys($geldlijst);
        $typegeld = array_map('strval', $typegeld);
        $form->setTitle("§bPinautomaat | §2Storten");
        $form->addDropdown("Hoeveelheid", $typegeld);
        $form->addSlider("Aantal", 1, 10);
        $player->sendForm($form);
    }

    public function afhalen($player)
    {
        $form = new CustomForm(function (Player $player, $data) {
            if ($data !== null) {
                $aantal = $data[1];
                $geldlijst = $this->getConfig()->get("money");
                $waarde = array_keys($geldlijst)[$data[0]];
                $vwp = array_values($geldlijst)[$data[0]];

                $money = $waarde * $aantal;

                if (EconomyAPI::getInstance()->myMoney($player) >= $money) {
                    $item = ItemFactory::get($vwp, 0, $aantal);
                    $lore = $this->getConfig()->get("money-lore");
                    $item->setLore([$lore]);
                    $item->setCustomName(TextFormat::BOLD . $waarde);
                    $player->getInventory()->addItem($item);
                    EconomyAPI::getInstance()->reduceMoney($player, $money, true);

                    $player->sendMessage("§aU heeft §2$money §aeuro opgenomen!");
                } else {
                    $player->sendMessage("§4U heeft niet genoeg geld op uw rekening.");
                }


            }
        });

        $geldlijst = $this->getConfig()->get("money");
        $typegeld = array_keys($geldlijst);
        $typegeld = array_map('strval', $typegeld);

        $form->setTitle("§bPinautomaat | §4Afhalen");
        $form->addDropdown("Hoeveelheid", $typegeld);
        $form->addSlider("Aantal", 1, 10);
        $player->sendForm($form);
    }

    public function overschrift($player)
    {
        $form = new CustomForm(function (Player $player, $data) {
            if ($data !== null) {
                if (isset($data[0]) && isset($data[1]) && isset($data[3])) {
                    $ontvanger = $data[0];
                    $aantal = $data[1];
                    $reden = $data[2];
                    $anoniem = $data[3];
                    if (EconomyAPI::getInstance()->myMoney($player) >= $aantal) {
                        $ontvanger_offline = $this->getServer()->getOfflinePlayer($ontvanger);
                        //TODO: Checken als speler bestaat
                        if ($ontvanger_offline !== $player) {
                            EconomyAPI::getInstance()->reduceMoney($player, $aantal); //verzenders geld verminderen
                            EconomyAPI::getInstance()->addMoney($ontvanger, $aantal); //ontvangersgeld aanpassen
                            $ontvanger_online = $this->getServer()->getPlayer($ontvanger);
                            $lvlapi = new leveling($this, $player->getName());
                            $lvlapi->addXP($aantal * 0.01, $player);
                            if ($anoniem !== true) {
                                $bericht = " van §1 " . $player->getName();
                            } else {
                                $bericht = null;
                            }
                            if ($ontvanger_online !== null) {
                                $ontvanger = $ontvanger_online;
                                $ontvanger_online->sendMessage("§cU heeft §d$aantal euro §contvangen" . $bericht . "\n§4Mededeling: §f§o$reden");
                            }
                        } else {
                            $player->sendMessage("§f[§cCake§fTopia§f] §eU kan geen geld naar u zelf verzenden");
                        }

                    } else {
                        $player->sendMessage("§f[§cCake§fTopia§f] §eU heeft niet genoeg geld op uw rekening.");
                    }


                }
            }
        });
        $form->setTitle("Overschrift");
        $form->addInput("Ontvanger", "Username");
        $form->addInput("Aantal geld");
        $form->addInput("Mededeling");
        $form->addToggle("Anoniem versturen");
        $player->sendForm($form);
    }

    public function menu($player)
    {
        $balance = EconomyAPI::getInstance()->myMoney($player);
        $form = new SimpleForm(function (Player $player, $data) {
            if ($data !== null) {
                switch ($data) {
                    case "0":
                        $this->storten($player);
                        break;
                    case "1":
                        $this->afhalen($player);
                        break;
                }

            }
        });

        $form->setTitle("Pinautomaat");
        $form->setContent("Welkom " . $player->getName() . ".\n\nU heeft $balance euro op uw rekening.\n\n\n\n");
        $form->addButton("Geld Storten");
        $form->addButton("Geld Opnemen");
        $player->sendForm($form);
    }

    public function aanraking(PlayerInteractEvent $event)
    {
        $player = $event->getPlayer();
        if ($event->getAction() == PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            if ($event->getBlock()->getId() == $this->getConfig()->get("pin_block_id") && $this->getATM($event->getBlock())) {
                $event->setCancelled();
                    if ($player->getGamemode() != 1) {
                        $player = $event->getPlayer();
                        $this->menu($player);
                    } else {
                        $player->sendPopup("§4U kan de pinautomaat niet gebruiken in §2Creative");
                    }
                }
        }
    }

    public function pinBreak(BlockBreakEvent $e){
        if($this->getATM($e->getBlock())){
            $this->deleteATM($e->getBlock());
            $ex = new Explosion($e->getBlock(), 1);
            $ex->explodeB();
            $e->getPlayer()->sendMessage("§4POEF, pinautomaat is gone :o");
        }
    }
}
 
