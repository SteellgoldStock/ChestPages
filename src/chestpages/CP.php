<?php

namespace chestpages;

use chestpages\listeners\EventListener;
use muqsit\invmenu\InvMenuHandler;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\entity\Attribute;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemFactory;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\plugin\PluginBase;

class CP extends PluginBase implements Listener {

	public static CP $instance;

	public BigEndianNbtSerializer $nbtSerializer;

	public static array $data;

	public static array $chest_data;

	public static \SQLite3 $database;

	public static string $prefix = "[ChestPages Default Prefix]";


	protected function onEnable(): void {
		self::$instance = $this;
		self::$data = [];
		self::$chest_data = [];
		self::$prefix = $this->getConfig()->get("messages")["prefix"];
		self::$database = new \SQLite3(self::$instance->getDataFolder() . "database.db");
		$this->nbtSerializer = new BigEndianNbtSerializer();

		PermissionManager::getInstance()->addPermission(new Permission("chestpages.blocks.readonly","Allows you to read only the chest pages"));

		if (!InvMenuHandler::isRegistered()) {
			InvMenuHandler::register($this);
		}

		self::$database->exec("CREATE TABLE IF NOT EXISTS chestpages (id INTEGER PRIMARY KEY, x TEXT, y TEXT, z TEXT, content TEXT, placedBy TEXT);");
		$stmt = self::$database->query("SELECT * FROM chestpages;");
		while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {

			$content = [];
			foreach (unserialize(base64_decode($row["content"])) as $inventory => $value){
				$content[$inventory] = $this->read($value);
			}

			self::$chest_data["{$row["x"]}:{$row["y"]}:{$row["z"]}"] = [
				"content" => $content,
				"placedBy" => $row["placedBy"]
			];
		}

		$this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);

		foreach ($this->getConfig()->get("tiers") as $tier => $value) {
			$v = explode(':', $value['result']);
			self::$data["$v[0]:$v[1]"] = $value;
			$recipe = new ShapedRecipe(
				["ABC", "DEF", "GHI"],
				[
					"A" => self::getItem($value['shape'][0][0]),
					"B" => self::getItem($value['shape'][0][1]),
					"C" => self::getItem($value['shape'][0][2]),
					"D" => self::getItem($value['shape'][1][0]),
					"E" => self::getItem($value['shape'][1][1]),
					"F" => self::getItem($value['shape'][1][2]),
					"G" => self::getItem($value['shape'][2][0]),
					"H" => self::getItem($value['shape'][2][1]),
					"I" => self::getItem($value['shape'][2][2]),
				],
				[$this->getItem("$v[0]:$v[1]")]
			);
			$this->getLogger()->info("Registering recipe for {$value["name"]}");
			$this->getServer()->getCraftingManager()->registerShapedRecipe($recipe);
		}


		var_dump(BlockFactory::getInstance()->get(54,0)->asItem()->getId());
		var_dump(ItemFactory::getInstance()->get(54,0)->getId());
		$this->getLogger()->info("ChestPages has been enabled!");
	}

	private function getItem($item): Item {
		$items = explode(":", $item);
		$id = intval($items[0]);
		$meta = intval($items[1]);

		if (key_exists(2,$items)) {
			return ItemFactory::getInstance()->get($id, $meta, intval($items[2]));
		}
		return ItemFactory::getInstance()->get($id, $meta);
	}

	protected function onDisable(): void {
		foreach (self::$chest_data as $key => $value) {
			$content = [];
			foreach ($value["content"] as $inventory => $items) {
				$content[$inventory] = $this->write($items);
			}

			$key = explode(':', $key);
			$stmt = self::$database->prepare("INSERT INTO chestpages (x, y, z, content, placedBy) VALUES (:x, :y, :z, :content, :placedBy)");
			$stmt->bindValue(":x", $key[0]);
			$stmt->bindValue(":y", $key[1]);
			$stmt->bindValue(":z", $key[2]);
			$stmt->bindValue(":content", base64_encode(serialize($content)));
			$stmt->bindValue(":placedBy", $value["placedBy"]);
			$stmt->execute();
		}
		$this->getLogger()->info("ChestPages has been disabled!");
	}

	public function read(string $data) : mixed {
		$contents = [];
		$inventoryTag = $this->nbtSerializer->read(zlib_decode($data))->mustGetCompoundTag()->getListTag("Inventory");
		/** @var CompoundTag $tag */
		foreach($inventoryTag as $tag){
			$contents[$tag->getByte("Slot")] = Item::nbtDeserialize($tag);
		}

		return $contents;
	}

	public function write(array $items) :  mixed {
		$contents = [];
		/** @var Item[] $items */
		foreach($items as $slot => $item){
			$contents[] = $item->nbtSerialize($slot);
		}

		return zlib_encode($this->nbtSerializer->write(new TreeRoot(CompoundTag::create()
			->setTag("Inventory", new ListTag($contents, NBT::TAG_Compound))
		)), ZLIB_ENCODING_GZIP);
	}

	public static function getInstance() {
		return self::$instance;
	}
}