<?php

namespace chestpages\listeners;

use chestpages\CP;
use dktapps\pmforms\MenuForm;
use dktapps\pmforms\MenuOption;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\type\InvMenuTypeIds;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\inventory\Inventory;
use pocketmine\player\Player;
use pocketmine\Server;

class EventListener implements Listener {

	private array $actives = [];

	private array $timers = [];

	public static array $data = [];

	public function onInteract(PlayerInteractEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();

		if($player->isSneaking()){
			return;
		}

		$position = $block->getPosition()->getX() . ":" . $block->getPosition()->getY() . ":" . $block->getPosition()->getZ();
		if (!isset($this->timers[$player->getName()])) $this->timers[$player->getName()] = time();
		if (!$this->timers[$player->getName()] > time()) return;

		if (!isset(CP::$data[$block->getId() . ":" . $block->getMeta()])) return;
		if (!isset(CP::$chest_data[$position])) return;

		if (key_exists($position, $this->actives)) {
			$player->sendTip(str_replace("{PLAYER}", $this->actives[$position], CP::getInstance()->getConfig()->get("messages")["other_player_here"]));
			return;
		}

		$this->timers[$player->getName()] = time() + 3;

		$readonly = false;
		if ($player->hasPermission("chestpages.blocks.readonly") and !Server::getInstance()->isOp($player->getName())) {
			$readonly = true;
		}

		if(!Server::getInstance()->isOp($player->getName())){
			if (!CP::$data[$block->getId() . ":" . $block->getMeta()]["all_can_open"] and $player->getName() !== CP::$chest_data[$position]["placedBy"]) {
				$player->sendMessage(CP::$prefix . CP::getInstance()->getConfig()->get("messages")["cant_open_chest"]);
				return;
			}
		}

		$pages = [];
		for ($i = 0; ; $i++) {
			$pages[] = new MenuOption("Page " . $i + 1, null);
			if ($i == (CP::$data[$block->getId() . ":" . $block->getMeta()]["page_count"] - 1)) {
				break;
			}
		}

		$this->actives[$position] = $player->getName();
		$player->sendForm(new MenuForm(
			"Pages",
			"Choisissez la page que vous voulez",
			$pages,
			function (Player $submitter, int $selected) use ($position, $block, $readonly): void {
				$menu = InvMenu::create(InvMenuTypeIds::TYPE_CHEST);
				if ($readonly) {
					$menu->setListener(InvMenu::readonly());
				}
				$menu->getInventory()->setContents(CP::$chest_data[$position]["content"][$selected]);
				$menu->setName(CP::$data[$block->getId() . ":" . $block->getMeta()]["name"]);

				$menu->setInventoryCloseListener(function (Player $player, Inventory $inventory) use ($selected, $position): void {
					unset($this->actives[$position]);
					CP::$chest_data[$position]["content"][$selected] = $inventory->getContents();;
				});

				$menu->send($submitter);
			}, function (Player $player) use ($position): void {
			unset($this->actives[$position]);
		}
		));
	}

	public function onPlaceChest(BlockPlaceEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$position = $block->getPosition()->getX() . ":" . $block->getPosition()->getY() . ":" . $block->getPosition()->getZ();

		if (isset(CP::$data[$block->getId() . ":" . $block->getMeta()])) {
			CP::$chest_data[$position] = [
				"content" => [],
				"placedBy" => $player->getName(),
			];

			for ($i = 0; ; $i++) {
				CP::$chest_data[$position]["content"][$i] = [];
				if ($i == (CP::$data[$block->getId() . ":" . $block->getMeta()]["page_count"]) - 1) {
					break;
				}
			}
		}
	}

	public function onBreakChest(BlockBreakEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$position = $block->getPosition()->getX() . ":" . $block->getPosition()->getY() . ":" . $block->getPosition()->getZ();
		if(isset(CP::$chest_data[$position])){
			return;
		}

		if (isset(CP::$chest_data[$position])) {
			if (!CP::$data[$block->getId() . ":" . $block->getMeta()]["all_can_break"]) {
				if(CP::$chest_data[$position]["placedBy"] !== $player->getName()){
					$player->sendMessage(CP::$prefix . CP::getInstance()->getConfig()->get("messages")["cant_break_chest"]);
					$event->cancel();
					return;
				}
			}

			foreach (CP::$chest_data[$position]["content"] as $page => $items) {
				foreach ($items as $item) {
					$player->dropItem($item);
				}
			}
			unset(CP::$chest_data[$position]);
		}
	}

	public function onExlode(EntityExplodeEvent $e) {
		foreach ($e->getBlockList() as $block) {
			$position = $block->getPosition()->getX() . ":" . $block->getPosition()->getY() . ":" . $block->getPosition()->getZ();
			if (isset(CP::$chest_data[$position])) {
				if (CP::$data[$block->getId() . ":" . $block->getMeta()]["explosion_protection"]) {
					$list = $e->getBlockList();
					unset($list[array_search($block, $list)]);
					$e->setBlockList($list);
					return;
				}
			}
		}
	}
}