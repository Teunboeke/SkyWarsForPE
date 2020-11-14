<?php
/**
 * Adapted from the Wizardry License
 *
 * Copyright (c) 2015-2018 larryTheCoder and contributors
 *
 * Permission is hereby granted to any persons and/or organizations
 * using this software to copy, modify, merge, publish, and distribute it.
 * Said persons and/or organizations are not allowed to use the software or
 * any derivatives of the work for commercial use or any other means to generate
 * income, nor are they allowed to claim this software as their own.
 *
 * The persons and/or organizations are also disallowed from sub-licensing
 * and/or trademarking this software without explicit permission from larryTheCoder.
 *
 * Any persons and/or organizations using this software must disclose their
 * source code and have it publicly available, include this license,
 * provide sufficient credit to the original authors of the project (IE: larryTheCoder),
 * as well as provide a link to the original project.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,FITNESS FOR A PARTICULAR
 * PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE
 * USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace larryTheCoder\panel;

use larryTheCoder\arena\api\impl\ArenaState;
use larryTheCoder\arena\api\task\AsyncDirectoryDelete;
use larryTheCoder\arena\api\task\CompressionAsyncTask;
use larryTheCoder\arena\ArenaImpl;
use larryTheCoder\forms\CustomForm;
use larryTheCoder\forms\CustomFormResponse;
use larryTheCoder\forms\elements\{Button, Dropdown, Input, Label, Slider, Toggle};
use larryTheCoder\forms\MenuForm;
use larryTheCoder\forms\ModalForm;
use larryTheCoder\SkyWarsPE;
use larryTheCoder\utils\{ConfigManager, Settings, Utils};
use larryTheCoder\utils\PlayerData;
use pocketmine\{block\Slab, level\Level, Player, Server, utils\TextFormat};
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\item\{BlazeRod, Item};
use pocketmine\utils\Config;
use RuntimeException;

/**
 * Implementation of a callable-based skywars interface, no more events-styled
 * burden in case there is a new feature that is going to be implemented in the future.
 *
 * Class FormPanel
 * @package larryTheCoder\panel
 */
class FormPanel implements Listener {

	/** @var SkyWarsPE */
	private $plugin;
	/** @var SkyWarsData[] */
	private $temporaryData = [];
	/** @var string[][] */
	private $actions = [];
	/** @var int[] */
	private $mode = [];
	/** @var array<string, array<int|Item>> */
	private $lastHoldIndex = [];

	public function __construct(SkyWarsPE $plugin){
		$this->plugin = $plugin;

		try{
			$plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
		}catch(\Throwable $e){
			throw new RuntimeException("Unable to register events correctly", 0, $e);
		}
	}

	/**
	 * @param Player $player
	 * @param ArenaImpl $arena
	 */
	public function showSpectatorPanel(Player $player, ArenaImpl $arena): void{
		$form = new MenuForm(TextFormat::BLUE . "Select Player Name");

		foreach($arena->getPlayerManager()->getAlivePlayers() as $inGame){
			$form->append(new Button($inGame->getName()));
		}

		$form->setText("Select a player to spectate");
		$form->setOnSubmit(function(Player $player, Button $selected) use ($arena): void{
			// Do not attempt to do anything if the arena is no longer running.
			// Or the player is no longer in arena
			if($arena->getStatus() !== ArenaState::STATE_ARENA_RUNNING || !$arena->getPlayerManager()->isInArena($player)){
				$player->sendMessage(TextFormat::RED . "You are no longer in the arena.");

				return;
			}

			$target = $arena->getPlayerManager()->getOriginPlayer($selected->getValue());
			if($target === null){
				$player->sendMessage(TextFormat::RED . "That player is no longer in the arena.");

				return;
			}
			$player->teleport($target);
		});
		$form->setOnClose(function(Player $player): void{
			$player->sendMessage($this->plugin->getMsg($player, "panel-cancelled"));
		});

		$player->sendForm($form);
	}

	/**
	 * Shows the current player stats in this game, this function is a callable
	 * based FormAPI and you know what it is written here...
	 *
	 * @param Player $player
	 */
	public function showStatsPanel(Player $player): void{
		// Checked and worked.
		$this->plugin->getDatabase()->getPlayerData($player->getName(), function(PlayerData $result) use ($player){
			$form = new CustomForm("§a{$result->player}'s stats", [
				new Label("§6Name: §f" . $result->player),
				new Label("§6Kills: §f" . $result->kill),
				new Label("§6Deaths: §f" . $result->death),
				new Label("§6Wins: §f" . $result->wins),
				new Label("§6Lost: §f" . $result->lost),
			], function(Player $player, CustomFormResponse $response): void{
			});

			$form->append();

			$player->sendForm($form);
		});
	}

	/**
	 * The function that handles player arena creation, notifies player after he/she
	 * has successfully created the arenas.
	 *
	 * @param Player $player
	 */
	public function setupArena(Player $player): void{
		$worldPath = Server::getInstance()->getDataPath() . 'worlds/';

		// Proper way to do this instead of foreach.
		$files = array_filter(scandir($worldPath), function($file) use ($worldPath): bool{
			if($file === "." || $file === ".." ||
				Server::getInstance()->getDefaultLevel()->getFolderName() === $file ||
				is_file($worldPath . $file)){

				return false;
			}

			return empty(array_filter($this->plugin->getArenaManager()->getArenas(), function($arena) use ($file): bool{
				return $arena->getLevelName() === $file;
			}));
		});

		if(empty($files)){
			$player->sendMessage($this->plugin->getMsg($player, "no-world"));

			return;
		}

		$form = new CustomForm("§5SkyWars Setup.", [
			new Input("§6The name of your Arena.", "Donkey Island"),
			new Dropdown("§6Select your Arena level.", $files),
			new Slider("§eMaximum players", 4, 40),
			new Slider("§eMinimum players", 2, 40),
			new Toggle("§7Spectator mode", true),
			new Toggle("§7Start on full", true),
		], function(Player $player, CustomFormResponse $response): void{
			$data = new SkyWarsData();

			$responseCustom = $response;
			$data->arenaName = $responseCustom->getInput()->getValue();
			$data->arenaLevel = $responseCustom->getDropdown()->getSelectedOption();
			$data->maxPlayer = $responseCustom->getSlider()->getValue();
			$data->minPlayer = $responseCustom->getSlider()->getValue();
			$data->spectator = $responseCustom->getToggle()->getValue();
			$data->startWhenFull = $responseCustom->getToggle()->getValue();
			if($this->plugin->getArenaManager()->arenaExist($data->arenaName)){
				$player->sendMessage($this->plugin->getMsg($player, 'arena-exists'));

				return;
			}

			if(empty($data->arenaLevel)){
				$player->sendMessage($this->plugin->getMsg($player, 'panel-low-arguments'));

				return;
			}

			file_put_contents($this->plugin->getDataFolder() . "arenas/$data->arenaName.yml", $this->plugin->getResource('arenas/default.yml'));

			$a = new ConfigManager($data->arenaName, $this->plugin);
			$a->setArenaWorld($data->arenaLevel);
			$a->setArenaName($data->arenaName);
			$a->enableSpectator($data->spectator);
			$a->setPlayersCount($data->maxPlayer > $data->minPlayer ? $data->maxPlayer : $data->minPlayer, $data->minPlayer);
			$a->startOnFull($data->startWhenFull);
			$a->applyFullChanges();

			$level = Server::getInstance()->getLevelByName($data->arenaLevel);
			if($level !== null) Server::getInstance()->unloadLevel($level, true);

			// Copy files to the directive location, then we put on the modal form in next tick.
			new CompressionAsyncTask([
				Server::getInstance()->getDataPath() . "worlds/" . $data->arenaLevel,
				$this->plugin->getDataFolder() . 'arenas/worlds/' . $data->arenaLevel . ".zip",
				true,
			], function() use ($player, $data){
				$form = new ModalForm("", "§aYou may need to setup arena's spawn position so system could enable the arena much faster.",
					function(Player $player, bool $response) use ($data): void{
						if($response) $this->setupSpawn($player, $data);
					}, "Setup arena spawn.", "§cSetup later.");

				$player->sendForm($form);
			});
		}, function(Player $pl): void{
			$pl->sendMessage($this->plugin->getMsg($pl, 'panel-cancelled'));
		});

		$player->sendForm($form);
	}

	private function setupSpawn(Player $player, SkyWarsData $arena = null): void{
		Utils::loadFirst($arena->arenaLevel);

		$arenaConfig = new ConfigManager($arena->arenaName, $this->plugin);
		$arenaConfig->resetSpawnPedestal();

		$this->temporaryData[$player->getName()] = $arena;
		$this->actions[strtolower($player->getName())]['type'] = 'spawnpos';

		$level = $this->plugin->getServer()->getLevelByName($arena->arenaLevel);
		$player->teleport($level->getSpawnLocation());
		$player->sendMessage($this->plugin->getMsg($player, 'panel-spawn-wand'));
		$this->setMagicWand($player);
	}

	private function setMagicWand(Player $p): void{
		$this->lastHoldIndex[$p->getName()] = [$p->getInventory()->getHeldItemIndex(), $p->getInventory()->getHotbarSlotItem(0)];

		$p->setGamemode(1);
		$p->getInventory()->setHeldItemIndex(0);
		$p->getInventory()->setItemInHand(new BlazeRod());
	}

	private function cleanupArray(Player $player): void{
		if(isset($this->temporaryData[$player->getName()])){
			$this->plugin->getArenaManager()->reloadArena($this->temporaryData[$player->getName()]->arenaName);
			unset($this->temporaryData[$player->getName()]);
		}

		// Now, its more reliable.
		if(isset($this->lastHoldIndex[$player->getName()])){
			$holdIndex = $this->lastHoldIndex[$player->getName()][0];
			$lastItem = $this->lastHoldIndex[$player->getName()][1];
			$player->getInventory()->setItem(0, $lastItem);
			$player->getInventory()->setHeldItemIndex($holdIndex);
			unset($this->lastHoldIndex[$player->getName()]);
		}
	}

	/**
	 * This function handle the settings for arena(s)
	 *
	 * @param Player $player
	 */
	public function showSettingPanel(Player $player): void{
		$form = new MenuForm("§aChoose your arena first.");
		foreach($this->plugin->getArenaManager()->getArenas() as $arena){
			$form->append(ucwords($arena->getMapName()));
		}

		$form->setOnSubmit(function(Player $player, Button $selected): void{
			$arena = $this->plugin->getArenaManager()->getArenaByInt($selected->getValue());
			$data = $this->toData($arena);

			$form = new MenuForm("Setup for arena {$arena->getMapName()}");
			$form->append(
				"Setup Arena Spawn",            // Arena Spawn
				"Setup Spectator Spawn",        // Spectator spawn
				"Setup Arena Behaviour",        // (Grace Timer) (Spectator Mode) (Time) (Enable) (Starting Time) (Max Player) (Min Player)
				"Set Join Sign Behaviour",      // (Text) (Interval) (enable-interval)
				"Set Join Sign Location",       // Sign location teleportation.
				"Edit this world",              // Editing the world.
				TextFormat::RED . "Delete this arena"
			);

			$form->setOnSubmit(function(Player $player, Button $selected) use ($data): void{
				switch($selected->getValue()){
					case 0:
						$this->setupSpawn($player, $data);
						break;
					case 1:
						$this->setupSpectate($player, $data);
						break;
					case 2:
						$this->arenaBehaviour($player, $data);
						break;
					case 3:
						$this->joinSignBehaviour($player, $data);
						break;
					case 4:
						$this->joinSignSetup($player, $data);
						break;
					case 5:
						$this->teleportWorld($player, $data);
						break;
					case 6:
						$this->deleteSure($player, $data);
						break;
				}
			});

			$player->sendForm($form);
		});
		$form->setOnClose(function(Player $pl): void{
			$pl->sendMessage($this->plugin->getMsg($pl, 'panel-cancelled'));
		});

		$player->sendForm($form);
	}

	private function toData(ArenaImpl $arena): SkyWarsData{
		$data = new SkyWarsData();
		$data->arena = $arena;
		$data->maxPlayer = $arena->maximumPlayers;
		$data->minPlayer = $arena->minimumPlayers;
		$data->arenaLevel = $arena->arenaWorld;
		$data->arenaName = $arena->getMapName();
		$data->spectator = $arena->enableSpectator;
		$data->startWhenFull = $arena->arenaStartOnFull;
		$data->graceTimer = $arena->arenaGraceTime;
		$data->enabled = $arena->arenaEnable;
		$data->line1 = str_replace("&", "§", $arena->statusLine1);
		$data->line2 = str_replace("&", "§", $arena->statusLine2);
		$data->line3 = str_replace("&", "§", $arena->statusLine3);
		$data->line4 = str_replace("&", "§", $arena->statusLine4);

		return $data;
	}

	private function setupSpectate(Player $player, SkyWarsData $arena): void{
		Utils::loadFirst($arena->arenaLevel);

		$arenaConfig = new ConfigManager($arena->arenaName, $this->plugin);
		$arenaConfig->resetSpawnPedestal();

		$this->temporaryData[$player->getName()] = $arena;
		$this->actions[strtolower($player->getName())]['type'] = 'setspecspawn';

		$level = $this->plugin->getServer()->getLevelByName($arena->arenaLevel);
		$player->teleport($level->getSpawnLocation());
		$player->sendMessage($this->plugin->getMsg($player, 'panel-spawn-wand'));
		$this->setMagicWand($player);
	}

	private function arenaBehaviour(Player $player, SkyWarsData $arena): void{
		// (Grace Timer) (Spectator Mode) (Time) (Enable) (Starting Time) (Max Player) (Min Player)
		$form = new CustomForm("Arena settings.", [
			new Toggle("§eEnable the arena?", $arena->enabled),
			new Slider("§eSet Grace Timer", 0, 30, 1, $arena->graceTimer),
			new Toggle("§eEnable Spectator Mode?", $arena->spectator),
			new Slider("§eMaximum players to be in arena", 0, 50, 1, $arena->maxPlayer),
			new Slider("§eMinimum players to be in arena", 0, 50, 1, $arena->minPlayer),
			new Toggle("§eStart when full", $arena->startWhenFull),
		], function(Player $player, CustomFormResponse $response) use ($arena): void{
			$enable = $response->getToggle()->getValue();
			$graceTimer = $response->getSlider()->getValue();
			$spectatorMode = $response->getToggle()->getValue();
			$maxPlayer = $response->getSlider()->getValue();
			$minPlayer = $response->getSlider()->getValue();
			$startWhenFull = $response->getToggle()->getValue();
			# Get the config

			$a = new ConfigManager($arena->arenaName, $this->plugin);
			$a->setEnable($enable);
			$a->setGraceTimer($graceTimer);
			$a->enableSpectator($spectatorMode);
			$a->setPlayersCount($maxPlayer > $minPlayer ? $maxPlayer : $minPlayer, $arena->minPlayer);
			$a->startOnFull($startWhenFull);
			$a->applyFullChanges();

			$player->sendMessage(TextFormat::GREEN . "Successfully updated arena " . TextFormat::YELLOW . $arena->arenaName);
		}, function(Player $pl): void{
			$pl->sendMessage($this->plugin->getMsg($pl, 'panel-cancelled'));
		});

		$player->sendForm($form);
	}

	private function joinSignBehaviour(Player $p, SkyWarsData $data): void{
		$form = new CustomForm("§eForm Behaviour Setup", [
			new Label("§aWelcome to sign Behaviour Setup. First before you doing anything, you may need to know these"),
			new Label("§eStatus lines\n&a &b &c = you can use color with &\n%alive = amount of in-game players\n%dead = amount of dead players\n%status = game status\n%world = world name of arena\n%max = max players per arena"),
			new Input("§aSign Placeholder 1", "Sign Text", $data->line1),
			new Input("§aSign Placeholder 2", "Sign Text", $data->line2),
			new Input("§aSign Placeholder 3", "Sign Text", $data->line3),
			new Input("§aSign Placeholder 4", "Sign Text", $data->line4),
		], function(Player $player, CustomFormResponse $response) use ($data): void{
			$a = new ConfigManager($data->arenaName, $this->plugin);

			$a->setStatusLine($response->getInput()->getValue(), 1);
			$a->setStatusLine($response->getInput()->getValue(), 2);
			$a->setStatusLine($response->getInput()->getValue(), 3);
			$a->setStatusLine($response->getInput()->getValue(), 4);

			$player->sendMessage(TextFormat::GREEN . "Successfully updated sign lines for " . TextFormat::YELLOW . $data->arenaName);
		}, function(Player $pl): void{
			$pl->sendMessage($this->plugin->getMsg($pl, 'panel-cancelled'));
		});

		$p->sendForm($form);
	}

	/**
	 * Show to player the panel cages.
	 * Decide their own private spawn pedestals
	 *
	 * @param Player $player
	 */
	public function showChooseCage(Player $player): void{
		$this->plugin->getDatabase()->getPlayerData($player->getName(), function(PlayerData $pd) use ($player){
			$form = new MenuForm("§cChoose Your Cage");
			$form->setText("§aVarieties of cages available!");

			$cages = [];
			foreach($this->plugin->getCage()->getCages() as $cage){
				if((is_array($pd->cages) && !in_array(strtolower($cage->getCageName()), $pd->cages)) && $cage->getPrice() !== 0){
					$form->append("§8" . $cage->getCageName() . "\n§e[Price $" . $cage->getPrice() . "]");
				}else{
					$form->append("§8" . $cage->getCageName() . "\n§aBought");
				}
				$cages[] = $cage;
			}

			$form->setOnSubmit(function(Player $player, Button $selected) use ($cages): void{
				$this->plugin->getCage()->setPlayerCage($player, $cages[$selected->getValue()]);
			});

			$player->sendForm($form);
		});
	}

	private function joinSignSetup(Player $player, SkyWarsData $data): void{
		Utils::loadFirst($data->arenaLevel);

		$this->temporaryData[$player->getName()] = $data;
		$this->actions[strtolower($player->getName())]['type'] = 'setjoinsign';
		$player->sendMessage($this->plugin->getMsg($player, 'panel-spawn-wand'));
		$this->setMagicWand($player);
	}

	private function teleportWorld(Player $p, SkyWarsData $arena): void{
		$p->setGamemode(1);

		$this->temporaryData[$p->getName()] = $arena;
		$this->actions[strtolower($p->getName())]['WORLD'] = "EDIT-WORLD";
		$p->sendMessage("You are now be able to edit the world now, best of luck");
		$p->sendMessage("Use blaze rod if you have finished editing the world.");

		$levelName = $arena->arena->getLevelName();

		$fromPath = $this->plugin->getDataFolder() . 'arenas/worlds/' . $levelName . ".zip";
		$toPath = $this->plugin->getServer()->getDataPath() . "worlds/" . $levelName;

		$task = new CompressionAsyncTask([$fromPath, $toPath, false], function() use ($fromPath, $levelName, $p){
			$task = new AsyncDirectoryDelete([$fromPath]);
			Server::getInstance()->getAsyncPool()->submitTask($task);

			Server::getInstance()->loadLevel($levelName);

			$level = Server::getInstance()->getLevelByName($levelName);
			$level->setAutoSave(false);

			$level->setTime(Level::TIME_DAY);
			$level->stopTime();

			$p->teleport($level->getSpawnLocation());

			$p->getInventory()->setHeldItemIndex(0);
			$p->getInventory()->clearAll(); // Perhaps

			$p->sendMessage("You can now edit this world.");
		});
		Server::getInstance()->getAsyncPool()->submitTask($task);
	}

	private function deleteSure(Player $p, SkyWarsData $data): void{
		$form = new ModalForm("", "§cAre you sure to perform this action? Deleting an arena will only deletes your arena setup but will not effect your world.",
			function(Player $player, bool $response) use ($data): void{
				if(!$response) return;

				unlink($this->plugin->getDataFolder() . "arenas/$data->arenaName.yml");
				$this->plugin->getArenaManager()->deleteArena($data->arenaName);
				$player->sendMessage(str_replace("{ARENA}", $data->arenaName, $this->plugin->getMsg($player, 'arena-delete')));
			},
			"§cDelete", "Cancel");

		$p->sendForm($form);
	}

	/**
	 * @param BlockBreakEvent $e
	 * @priority HIGH
	 */
	public function onBlockBreak(BlockBreakEvent $e): void{
		$p = $e->getPlayer();
		if(isset($this->temporaryData[$p->getName()]) && isset($this->actions[strtolower($p->getName())]['type'])){
			if($e->getItem()->getId() === Item::BLAZE_ROD){
				if(!isset($this->mode[strtolower($p->getName())])) $this->mode[strtolower($p->getName())] = 1;

				$e->setCancelled(true);
				$b = $e->getBlock();
				$arena = new ConfigManager($this->temporaryData[$p->getName()]->arenaName, $this->plugin);

				if($this->actions[strtolower($p->getName())]['type'] == "setjoinsign"){
					$arena->setJoinSign($b->x, $b->y, $b->z, $b->level->getName());
					$p->sendMessage($this->plugin->getMsg($p, 'panel-join-sign'));
					unset($this->actions[strtolower($p->getName())]['type']);

					$this->cleanupArray($p);

					return;
				}

				if($this->actions[strtolower($p->getName())]['type'] == "setspecspawn"){
					$arena->setSpecSpawn($b->x, $b->y, $b->z);

					$p->sendMessage($this->plugin->getMsg($p, 'panel-join-spect'));
					$spawn = $this->plugin->getServer()->getDefaultLevel()->getSafeSpawn();
					$p->teleport($spawn, 0, 0);
					unset($this->actions[strtolower($p->getName())]['type']);

					$this->cleanupArray($p);

					return;
				}

				if($this->actions[strtolower($p->getName())]['type'] == "spawnpos"){
					if($this->mode[strtolower($p->getName())] >= 1 && $this->mode[strtolower($p->getName())] <= $arena->arena->getNested('arena.max-players')){
						$arena->setSpawnPosition([$b->getX(), $b->getY() + 1, $b->getZ()], $this->mode[strtolower($p->getName())]);

						$p->sendMessage(str_replace("{COUNT}", (string)$this->mode[strtolower($p->getName())], $this->plugin->getMsg($p, 'panel-spawn-pos')));
						$this->mode[strtolower($p->getName())]++;
					}
					if($this->mode[strtolower($p->getName())] === $arena->arena->getNested('arena.max-players') + 1){
						$p->sendMessage($this->plugin->getMsg($p, "panel-spawn-set"));
						$spawn = $this->plugin->getServer()->getDefaultLevel()->getSafeSpawn();
						$p->teleport($spawn, 0, 0);
						unset($this->mode[strtolower($p->getName())]);
						unset($this->actions[strtolower($p->getName())]['type']);

						$this->cleanupArray($p);
					}
					$arena->arena->save();

					return;
				}
			}
		}

		if(isset($this->actions[strtolower($p->getName())]['NPC'])
			&& $e->getItem()->getId() === Item::BLAZE_ROD){
			$e->setCancelled(true);
			$b = $e->getBlock();
			$cfg = new Config($this->plugin->getDataFolder() . "npc.yml", Config::YAML);
			if($this->mode[strtolower($p->getName())] >= 1 && $this->mode[strtolower($p->getName())] <= 3){
				$y = 1;
				if($b instanceof Slab){
					$y = 0.5;
				}
				$cfg->set("npc-{$this->mode[strtolower($p->getName())]}", [$b->getX() + 0.5, $b->getY() + $y, $b->getZ() + 0.5, $b->getLevel()->getName()]);
				$p->sendMessage(str_replace("{COUNT}", (string)$this->mode[strtolower($p->getName())], $this->plugin->getMsg($p, 'panel-spawn-pos')));
				$this->mode[strtolower($p->getName())]++;
			}
			if($this->mode[strtolower($p->getName())] === 4){
				unset($this->mode[strtolower($p->getName())]);
				unset($this->actions[strtolower($p->getName())]['NPC']);
				$this->cleanupArray($p);
			}
			$cfg->save();
		}

		if(isset($this->actions[strtolower($p->getName())]['WORLD'])
			&& $e->getItem()->getId() === Item::BLAZE_ROD){
			$e->setCancelled(true);

			$p->sendMessage(Settings::$prefix . "Teleporting you back to main world.");

			$level = $p->getLevel();
			$level->save(true);

			$spawn = $this->plugin->getServer()->getDefaultLevel()->getSafeSpawn();
			$p->teleport($spawn, 0, 0);

			//$this->temporaryData[$p->getName()]->arena->performEdit(ArenaState::FINISHED);

			unset($this->actions[strtolower($p->getName())]['WORLD']);
			$this->cleanupArray($p);
		}
	}

	public function showNPCConfiguration(Player $p): void{
		$p->setGamemode(1);

		$this->actions[strtolower($p->getName())]['NPC'] = "SETUP-NPC";
		$this->mode[strtolower($p->getName())] = 1;
		$p->sendMessage($this->plugin->getMsg($p, 'panel-spawn-wand'));
		$this->setMagicWand($p);
	}

}