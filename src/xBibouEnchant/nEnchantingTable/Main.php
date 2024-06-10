<?php
namespace xBibouEnchant\nEnchantingTable;

use EasyUI\element\Button;
use EasyUI\element\Slider;
use EasyUI\utils\FormResponse;
use EasyUI\variant\CustomForm;
use EasyUI\variant\SimpleForm;
use onebone\economyapi\EconomyAPI;
use pocketmine\block\EnchantingTable;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\enchantment\AvailableEnchantmentRegistry;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\ItemEnchantmentTagRegistry;
use pocketmine\item\Item;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\SingletonTrait;

class Main extends PluginBase implements Listener {
    use SingletonTrait;

    private array $availableEnchantments = [];

    protected function onLoad(): void {
        self::setInstance($this);
        $this->saveDefaultConfig();
        $this->saveResource("enchantments.yml");
        $config = new Config($this->getDataFolder() . "enchantments.yml", Config::YAML);
        $this->availableEnchantments = $config->get("enchantments");
    }

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    private function getAllEnchantmentsForItem(Item $item) : array {
        if(count($item->getEnchantmentTags()) === 0){
            return [];
        }

        $available_enchantments = [];
        foreach (AvailableEnchantmentRegistry::getInstance()->getAllEnchantmentsForItem($item) as $enchantment){
            $enchantName = $this->getEnchantmentName($enchantment);
            if (in_array($enchantName, array_map(fn($data) => $data["enchant"], $this->availableEnchantments))){
                if (ItemEnchantmentTagRegistry::getInstance()->isTagArrayIntersection($this->getAvailableTagsForEnchant($enchantment), $item->getEnchantmentTags())){
                    $available_enchantments[] = [
                        "enchant" => $enchantment,
                        "prix_type" => $this->getEnchantData($enchantment, "prix_type"),
                        "prix_par_level" => $this->getEnchantData($enchantment, "prix_par_level"),
                        "max_level" => $this->getEnchantData($enchantment, "max_level")
                    ];
                }
            }
        }
        return $available_enchantments;

    }

    protected function getEnchantData(Enchantment $enchantment, string $data) : mixed{
        $ret = null;
        foreach ($this->availableEnchantments as $enchantData){
            if ($enchantData["enchant"] === $this->getEnchantmentName($enchantment)){
                $ret = $enchantData[$data];
            }
        }
        return $ret;
    }

    public function getEnchantmentName(Enchantment $enchantment, bool $data = true): string
    {
        $language = $data ? new Language(Language::FALLBACK_LANGUAGE) : $this->getServer()->getLanguage();
        return ($enchantmentName = $enchantment->getName()) instanceof Translatable ? $language->translate($enchantmentName) : $language->translateString($enchantmentName);
    }

    protected function getAvailableTagsForEnchant(Enchantment $enchantment){
        $tags = [];
        foreach ($this->availableEnchantments as $enchantsData){
            if ($enchantsData["enchant"] === $this->getEnchantmentName($enchantment)){
                $tags = $enchantsData["tags"];
            }
        }
        return $tags;
    }

    public function onInteract(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $item = $event->getItem();
        $i = $player->getInventory()->getHeldItemIndex();
        $block = $event->getBlock();
        if($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            if ($block instanceof EnchantingTable){
                $event->cancel();
                $form = new SimpleForm($this->getConfig()->getNested("enchant-menu.title"));
                if ($this->getAllEnchantmentsForItem($item) !== []){
                    $form->setHeaderText($this->getConfig()->getNested("enchant-menu.headerText"));
                    foreach ($this->getAllEnchantmentsForItem($item) as $enchantData){
                        $enchantment = $enchantData["enchant"];
                        $prix = $enchantData["prix_par_level"];
                        $max = $enchantData["max_level"];
                        $type = $enchantData["prix_type"];
                        assert($enchantment instanceof Enchantment);
                        $form->addButton(new Button(str_replace(["{enchant}", "{prix}", "{prix_type}"], [$this->getEnchantmentName($enchantment, false), strval($prix), $type === "money" ? "$" : "xp"], $this->getConfig()->getNested("enchant-menu.enchantmentButton")), null, function (Player $player) use ($type, $i, $enchantment, $item, $prix, $max) {
                            $processForm = new CustomForm(str_replace("{enchant}", $this->getEnchantmentName($enchantment, false), $this->getConfig()->getNested("enchanting-menu.title")));
                            $processForm->addElement('slider', new Slider($this->getConfig()->getNested("enchanting-menu.headerText"), 1.0, floatval($max), 1.00, 1.0));
                            $processForm->setSubmitListener(function (Player $player, FormResponse $response) use ($type, $i, $enchantment, $item, $prix,$max) {
                                $slider = $response->getSliderSubmittedStep("slider");
                                $slider = intval($slider);
                                if($slider < 1 or $slider > $max) {
                                    // wtf wsh mm en mettant des "!" sa ne fix pas
                                }else{
                                    if ($type === "money") {
                                        if (EconomyAPI::getInstance()->myMoney($player) >= $slider * $prix) {
                                            EconomyAPI::getInstance()->reduceMoney($player, $slider * $prix);
                                            $this->process($enchantment, $slider, $player, $i, $item);
                                        } else $player->sendMessage($this->getConfig()->getNested("messages.noMoney"));
                                    } else {
                                        if ($player->getXpManager()->getXpLevel() > $slider * $prix) {
                                            $player->getXpManager()->setXpLevel($player->getXpManager()->getXpLevel() - ($slider * $prix));
                                            $this->process($enchantment, $slider, $player, $i, $item);
                                        } else $player->sendMessage($this->getConfig()->getNested("messages.noXp"));
                                    }
                                }
                                });
                                $player->sendForm($processForm);

                            }));
                    }
                } else {
                        $form->setHeaderText($this->getConfig()->getNested("enchant-menu.itemHasNoEnchant"));
                    }
                    $form->addButton(new Button($this->getConfig()->getNested("enchant-menu.quitButton")));
                    $player->sendForm($form);

                }
            }
        }

        private function process($enchantment, $slider, $player, $i, $item){
            $item->addEnchantment(new EnchantmentInstance($enchantment, $slider));
            $player->getInventory()->setItem($i, $item);
            $player->sendMessage($this->getConfig()->getNested("messages.itemEnchanted"));
        }

    }
