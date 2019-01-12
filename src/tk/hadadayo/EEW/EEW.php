<?php
namespace tk\hadadayo\EEW;

use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\AsyncTask;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;

class EEW extends PluginBase implements Listener{
	public function onEnable(){
		if(!file_exists($this->getDataFolder())){
			mkdir($this->getDataFolder(), 0744, true);
		}
		$this->saveResource("config.yml");
		$this->saveResource("lang.yml");
		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
		$this->lang = new Config($this->getDataFolder() . "lang.yml", Config::YAML);
		$this->config->save();
		$this->lang->save();
		$this->earthquakecount = 0;
		$this->reportcount = 0;
		$this->earthquake = [];
		$this->alarm = 0;
		$this->time = 0;
		if($this->config->get("EEW") == true){
			$this->id = $this->getScheduler()->scheduleRepeatingTask(new EEWRepeat($this),$this->config->get("tick"))->getTaskId();
			$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("eew-on"));
			$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("time-sync-start"));
			$this->getServer()->getAsyncPool()->submitTask(new EEWSyncTime());
			$this->status = 0;
		}else{
			$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("eew-off"));
			$this->status = 1;
		}
	}
	public function getText(String $text){
		return str_replace("&","§",str_replace("%n","\n",$this->lang->get($text)));
	}
	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) :bool{
		switch (strtolower($command->getName())) {
			case "eew":
			if (count($args) == 1) {
				if(strtolower($args[0]) == "on"){
					if($this->status == 1){
						$this->config->set("EEW", true);
						$this->config->save();
						$this->id = $this->getScheduler()->scheduleRepeatingTask(new EEWRepeat($this),$this->config->get("tick"))->getTaskId();
						$this->status = 0;
						if($sender instanceof Player){
							$sender->sendMessage($this->getText("command-eew-on"));
							$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("command-eew-on"));
						}else{
							$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("command-eew-on"));
						}
						$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("time-sync-start"));
						$this->getServer()->getAsyncPool()->submitTask(new EEWSyncTime());
					}else{
						$sender->sendMessage($this->getText("command-eew-on-already"));
					}
					return true;
				}elseif(strtolower($args[0]) == "off"){
					if($this->status != 1){
						$this->config->set("EEW", false);
						$this->config->save();
						$this->getScheduler()->cancelTask($this->id);
						$this->status = 1;
						if($sender instanceof Player){
							$sender->sendMessage($this->getText("command-eew-off"));
							$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("command-eew-off"));
						}else{
							$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("command-eew-off"));
						}
					}else{
						$sender->sendMessage($this->getText("command-eew-off-already"));
					}
					return true;
				}elseif(strtolower($args[0]) == "status"){
					if($this->status == 0){
						$sender->sendMessage(str_replace("%reportcount%",$this->reportcount,str_replace("%earthquakecount%",$this->earthquakecount,$this->getText("command-status"))));
					}elseif($this->status == 1){
						$sender->sendMessage(str_replace("%reportcount%",$this->reportcount,str_replace("%earthquakecount%",$this->earthquakecount,$this->getText("command-status"))."\n".$this->getText("command-status-off")));
					}elseif($this->status == 2){
						$sender->sendMessage(str_replace("%reportcount%",$this->reportcount,str_replace("%earthquakecount%",$this->earthquakecount,$this->getText("command-status"))."\n".$this->getText("command-status-error")));
					}
					return true;
				}elseif(strtolower($args[0]) == "reloadconfig"){
					$this->config->reload();
					$this->config->save();
					$sender->sendMessage($this->getText("command-eew-reloadconfig"));
					if($this->config->get("EEW")){
						if($this->status == 0){
							$this->getScheduler()->cancelTask($this->id);
						}else{
							$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("command-eew-on"));
							$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("time-sync-start"));
							$this->getServer()->getAsyncPool()->submitTask(new EEWSyncTime());
						}
						$this->id = $this->getScheduler()->scheduleRepeatingTask(new EEWRepeat($this),$this->config->get("tick"))->getTaskId();
					}else{
						if($this->status != 1){
							$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("command-eew-off"));
						}
					}
					return true;
				}elseif(strtolower($args[0]) == "reloadlang"){
					$this->lang->reload();
					$this->lang->save();
					$sender->sendMessage($this->getText("command-eew-reloadlang"));
					return true;
				}elseif(strtolower($args[0]) == "synctime"){
					$sender->sendMessage($this->getText("time-sync-start"));
					$this->getServer()->getAsyncPool()->submitTask(new EEWSyncTime($sender->getName()));
					return true;
				}
			}
			return false;
		}
	}
	public function synctime($result){
		if($result[1] === false){
			$msg = $this->getText("time-sync-error");
		}else{
			$json = json_decode(mb_convert_encoding($result[1], "UTF-8"),true);
			if(isset($json["st"])){
				$time_ = $this->time;
				$this->time = (float)$json["st"] - (float)$result[0];
				$time = (float)$json["st"] - (float)$result[0];
				if($this->time > $time){
					$msg = str_replace("%time%",round($this->time - $time_,2),$this->getText("time-fixed-1"));
				}else{
					$msg = str_replace("%time%",round(-$time_ - -$this->time, 2),$this->getText("time-fixed-2"));
				}
			}else{
				$msg = $this->getText("time-sync-error");
			}
		}
		if(isset($result[2])){
			$player = $this->getServer()->getPlayer($result[2]);
			if($player instanceOf Player){
				$player->sendMessage($msg);
			}elseif($result[2] == "CONSOLE"){
				$this->getServer()->getLogger()->info($msg);
			}
		}else{
			$this->getServer()->getLogger()->info("[EEW-Plugin] ".$msg);
		}
	}
	public function sendEEW($content){
		if($content === false){
			if($this->status != 2){
				$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("eew-error"));
			}
			$this->status = 2;
		}else{
			if($this->status != 0){
				$this->getServer()->getLogger()->info("[EEW-Plugin] ".$this->getText("eew-restart"));
			}
			$this->status = 0;
			$json = json_decode(mb_convert_encoding($content, "UTF-8"),true);
			if(isset($json["alertflg"]) && (!isset($this->earthquake["report_time"]) || ($this->earthquake["report_time"] != $json["report_time"])) && !$json["is_training"]){
				if($json["alertflg"] == "警報"){
					$isAlert = true;
				}else{
					$isAlert = false;
				}
				$region = $json["region_name"];
				$intensity = $json["calcintensity"];
				$magunitude = $json["magunitude"];
				$depth = $json["depth"];
				$latitude = $json["latitude"];
				$longitude = $json["longitude"];
				$report_num = "第".$json["report_num"]."報";
				$is_final = $json["is_final"];
				$origin_time = date($this->config->get("origin_time_format"),strtotime(substr($json["origin_time"], 0, 4)."-".substr($json["origin_time"], 4, 2)."-".substr($json["origin_time"], 6, 2)." ".substr($json["origin_time"], 8, 2).":".substr($json["origin_time"], 10, 2).":".substr($json["origin_time"], 12, 2)));
				if($is_final){
					$report_num = "最終報";
				}
				if(!$json["report_num"] == $this->earthquake["report_num"]){
					$this->earthquakecount++;
				}
				$this->reportcount++;
				if($json["is_cancel"]){
					if($isAlert){
						if($this->config->get("alert")["broadcast"]["mode"] <= 3 || ($this->config->get("alert")["console"]["mode"] <= 3 && ($this->config->get("alert")["message"]["mode"] <= 3 || $this->config->get("alert")["title"]["mode"] <= 3 ))){
							$this->getServer()->broadcastMessage($this->getText("cancelled"));
						}elseif($this->config->get("alert")["console"]["mode"] <= 3){
							$this->getServer()->$getLogger().info("[EEW-Plugin] ".$this->getText("cancelled"));
						}elseif($this->config->get("alert")["message"]["mode"] <= 3 || $this->config->get("alert")["title"]["mode"] <= 3){
							foreach($this->etServer()->getOnlinePlayers() as $player){
								$player->sendMessage($this->getText("cancelled"));
							}
						}
					}else{
						if($this->config->get("forecast")["broadcast"]["mode"] <= 3 || ($this->config->get("forecast")["console"]["mode"] <= 3 && ($this->config->get("forecast")["message"]["mode"] <= 3 || $this->config->get("forecast")["title"]["mode"] <= 3 ))){
							$this->getServer()->broadcastMessage($this->getText("cancelled"));
						}elseif($this->config->get("forecast")["console"]["mode"] <= 3){
							$this->etLogger().info("[EEW-Plugin] ".$this->getText("cancelled"));
						}elseif($this->config->get("forecast")["message"]["mode"] <= 3 || $this->config->get("forecast")["title"]["mode"] <= 3){
							foreach($this->etServer()->getOnlinePlayers() as $player){
								$player->sendMessage($this->getText("cancelled"));
							}
						}
					}
					foreach($this->Server()->getOnlinePlayers() as $player){
						$player->removeTitles();
					}
				}elseif((!isset($this->earthquake["report_id"]) && !isset($this->earthquake["alertflg"])) || !$json["report_id"] == $this->earthquake["report_id"] || $json["alertflg"] == $this->earthquake["alertflg"]){
					if($isAlert){
						$this->sendAlert(3, $is_final, $region, $intensity, $magunitude, $depth, $latitude, $longitude, $origin_time, $report_num);
					}else{
						$this->sendForecast(3, $is_final, $region, $intensity, $magunitude, $depth, $latitude, $longitude, $origin_time, $report_num);
					}
				}elseif(!isset($this->earthquake["calcintensity"]) || !($json["calcintensity"] == $this->earthquake["calcintensity"])){
					if($isAlert){
						$this->sendAlert(2, $is_final, $region, $intensity, $magunitude, $depth, $latitude, $longitude, $origin_time, $report_num);
					}else{
						$this->sendForecast(2, $is_final, $region, $intensity, $magunitude, $depth, $latitude, $longitude, $origin_time, $report_num);
					}
				}elseif(!isset($this->earthquake["region_name"]) || !($json["region_name"] == $this->earthquake["region_name"])){
					if($isAlert){
						$this->sendAlert(1, $is_final, $region, $intensity, $magunitude, $depth, $latitude, $longitude, $origin_time, $report_num);
					}else{
						$this->sendForecast(1, $is_final, $region, $intensity, $magunitude, $depth, $latitude, $longitude, $origin_time, $report_num);
					}
				}else{
					if($isAlert){
						$this->sendAlert(0, $is_final, $region, $intensity, $magunitude, $depth, $latitude, $longitude, $origin_time, $report_num);
					}else{
						$this->sendForecast(0, $is_final, $region, $intensity, $magunitude, $depth, $latitude, $longitude, $origin_time, $report_num);
					}
				}
			}
			$this->earthquake = $json;
		}
	}
	private function sendAlert(int $mode, bool $is_final, String $region, String $intensity, String $magunitude, String $depth, String $latitude, String $longitude, String $origin_time, String $report_num){
		if(($mode != 4 && $this->config->get("alert")["console"]["mode"] <= $mode) || ($this->config->get("alert")["console"]["mode"] && $is_final)){
			$this->getServer()->getLogger()->info("[EEW-Plugin] ".str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("alert-console"))))))))));
		}
		if(($mode != 4 && $this->config->get("alert")["broadcast"]["mode"] <= $mode) || ($this->config->get("alert")["broadcast"]["mode"] && $is_final)){
			$this->getServer()->broadcastMessage(str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("alert-broadcast"))))))))));
		}
		foreach($this->getServer()->getOnlinePlayers() as $player){
			if(($mode != 4 && $this->config->get("alert")["message"]["mode"] <= $mode) || ($this->config->get("alert")["message"]["mode"] && $is_final)){
				$player->sendMessage(str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("alert-message"))))))))));
			}
			if(($mode != 4 && $this->config->get("alert")["title"]["mode"] <= $mode) || ($this->config->get("alert")["title"]["mode"] && $is_final)){
				$player->addTitle(str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("alert-title"))))))))),str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("alert-subtitle"))))))))),0,6*20);
			}
		}
		if(($mode != 4 && $this->config->get("alert")["alarm"]["mode"] <= $mode) || ($this->config->get("alert")["alarm"]["mode"] && $is_final)){
			$this->alarm = 50;
			$this->alarmId =$this->getScheduler()->scheduleRepeatingTask(new EEWAlarm($this),2)->getTaskId();
		}
	}
	private function sendForecast(int $mode, bool $is_final, String $region, String $intensity, String $magunitude, String $depth, String $latitude, String $longitude, String $origin_time, String $report_num){
		if(($mode != 4 && $this->config->get("forecast")["console"]["mode"] <= $mode) || ($this->config->get("forecast")["console"]["mode"] && $is_final)){
			$this->getServer()->getLogger()->info("[EEW-Plugin] ".str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("forecast-console"))))))))));
		}
		if(($mode != 4 && $this->config->get("forecast")["broadcast"]["mode"] <= $mode) || ($this->config->get("forecast")["broadcast"]["mode"] && $is_final)){
			$this->getServer()->broadcastMessage(str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("forecast-broadcast"))))))))));
		}
		foreach($this->getServer()->getOnlinePlayers() as $player){
			if(($mode != 4 && $this->config->get("forecast")["message"]["mode"] <= $mode) || ($this->config->get("forecast")["message"]["mode"] && $is_final)){
				$player->sendMessage(str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("forecast-message"))))))))));
			}
			if(($mode != 4 && $this->config->get("forecast")["title"]["mode"] <= $mode) || ($this->config->get("forecast")["title"]["mode"] && $is_final)){
				$player->addTitle(str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("forecast-title"))))))))),str_replace("%report_num%", $report_num, str_replace("%origin_time%", $origin_time, str_replace("%longitude%", $longitude, str_replace("%latitude%", $latitude, str_replace("%depth%", $depth, str_replace("%magunitude%", $magunitude, str_replace("%intensity%", $intensity, str_replace("%region%", $region,$this->getText("forecast-subtitle"))))))))),0,6*20);
			}
		}
	}

}
class EEWRepeat extends Task{
	public function __construct(Plugin $plugin){
		$this->plugin = $plugin;
	}
	public function onRun(int $Tick){
		$this->plugin->getServer()->getAsyncPool()->submitTask(new EEWListener($this->plugin->time));
	}
}
class EEWListener extends AsyncTask{
	public function __construct(float $time){
		$this->time = $time;
	}
	public function onRun(){
		date_default_timezone_set("Asia/Tokyo");
		$content = @file_get_contents("http://www.kmoni.bosai.go.jp/new/webservice/hypo/eew/".date("YmdHis", (microtime(true) + $this->time)).".json");
		$this->setResult($content);
	}
	public function onCompletion(Server $server){
		$server->getPluginManager()->getPlugin("EEW-Plugin")->sendEEW($this->getResult());
	}
}
class EEWSyncTime extends AsyncTask{
	public function __construct(String $player = null){
		if($player != null){
			$this->player = $player;
		}
	}
	public function onRun(){
		date_default_timezone_set("Asia/Tokyo");
		$time = microtime(true);
		$content = @file_get_contents("http://ntp-a1.nict.go.jp/cgi-bin/json");
		if(isset($this->player)){
			$this->setResult(array($time,$content,$this->player));
		}else{
			$this->setResult(array($time,$content));
		}
	}
	public function onCompletion(Server $server){
		$server->getPluginManager()->getPlugin("EEW-Plugin")->synctime($this->getResult());
	}
}
class EEWAlarm extends Task{
	public function __construct(Plugin $plugin){
		$this->plugin = $plugin;
	}
	public function onRun(int $Tick){
		if($this->plugin->alarm > 0){
			foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
				$pk = new LevelSoundEventPacket();
				$pk->sound = LevelSoundEventPacket::SOUND_NOTE;
				$pk->position = $player->asVector3();
				$pk->disableRelativeVolume = true;
				$player->dataPacket($pk);
			}
			$this->plugin->alarm--;
		}else{
			$this->plugin->getScheduler()->cancelTask($this->plugin->alarmId);
		}
	}
}