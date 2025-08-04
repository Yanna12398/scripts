<?php

// krispypatata on top

namespace app\controller;
use think\facade\View;
use think\facade\Session;
use think\facade\Db;
use app\http\middleware\AuthCheck;
use app\http\middleware\DiscordVerifyCheck;
use app\http\middleware\BlacklistCheck;
use app\http\middleware\MembershipCheck;
use app\model\UserModel;
use app\model\GameModel;
use think\facade\Request;

class Panel
{
    protected $middleware = [AuthCheck::class, DiscordVerifyCheck::class, BlacklistCheck::class, MembershipCheck::class];

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

    private function getLeaderboardUsers()
    {
        $lbusers = Db::query(
            "SELECT * FROM users ORDER BY CAST(JSON_EXTRACT(stats, '$.robux') AS UNSIGNED) DESC LIMIT 10"
        );

        foreach ($lbusers as &$lbuser) {
            $lbuser["stats"] = json_decode($lbuser["stats"]);
        }

        return $lbusers;
    }

    private function handleSendPoints($userid, $userinfo) {
        if (!Request::has("pointstosend") || !Request::has("username")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "index");
        }
    
        $addpoints = Request::param("pointstosend");
        $site_username = Request::param("username");
    
        if (!is_numeric($addpoints) || $addpoints < 0) {
            return $this->assignError("Invalid game limit value", $userinfo, "index");
        }
    
        $requested_user = UserModel::where("username", $site_username)->findOrEmpty();
    
        if ($requested_user->isEmpty()) {
            return $this->assignError("User Not Found", $userinfo, "index");
        }

        $requested_user->stats->points = (int)$addpoints;
        
        $requested_user->save();
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: added +$addpoints points to $site_username");
        return $this->assignSuccess("$addpoints points added successfully for user: $site_username", $userinfo, "index");
    }

    private function handlePurchaseSlots($userid, $userinfo) {
        if (!Request::has("gameslots")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "index");
        }
        
        $purchaseslots = Request::param("gameslots");
        $site_username = $userinfo['username'];
    
        if (!is_numeric($purchaseslots) || $purchaseslots < 1) {
            return $this->assignError("Invalid game slot value", $userinfo, "index");
        }
        
        $requested_user = UserModel::where("username", $site_username)->findOrEmpty();
    
        if ($requested_user->isEmpty()) {
            return $this->assignError("User Not Found", $userinfo, "index");
        }
    
        $user_points = (int) $requested_user->stats->points;
        $required_points = $purchaseslots * 100;
        
        if ($user_points < $required_points) {
            return $this->assignError("You do not have enough points to purchase the selected game slots", $userinfo, "index");
        }
    
        $requested_user->stats->points = $user_points - $required_points;
        $requested_user->stats->game_limit = $requested_user->stats->game_limit + $purchaseslots;
        $requested_user->save();
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: purchased +$purchaseslots game slots");
        return $this->assignSuccess("$purchaseslots Game Slots have been Purchased Successfully", $userinfo, "index");
    }

    private function sendAdminLogs($username, $profilePic, $action)
    {
            $webhookUrl = config('embed_setup.admin_logs');

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
                                'name' => 'Admin Panel logs',
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

    public function index()
    {
        $user = $this->getUserInfo();
        $gamecount = GameModel::where("ownerid", $user->id)->count();
        $lbusers = $this->getLeaderboardUsers();
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

        $userinfo = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $message,    
            "profile_pic" => $user->profile_pic,
            "robux" => $user->stats->robux,
            "points" => $user->stats->points,
            "credits" => $user->stats->credits,
            "gamecount" => $gamecount,
            "statsusers" => $lbusers,
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
            "discord_server_link" => config("app.discord_server_link"),
            "game_limit" => $user->stats->game_limit,
        ];

        if (Request::isPost() && Request::has("send-points")) {
            return $this->handleSendPoints($user->id, $userinfo); 
        }

        if (Request::isPost() && Request::has("purchase-slots")) {
            return $this->handlePurchaseSlots($user->id, $userinfo);
        }

        View::assign($userinfo);
        return View::fetch("index");
    }
}
