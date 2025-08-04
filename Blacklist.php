<?php

// refactored by krispypatata
namespace app\controller;

use think\facade\View;
use think\facade\Session;
use app\http\middleware\AuthCheck;
use app\model\UserModel;

class Blacklist
{
    protected $middleware = [AuthCheck::class];

    private function getUserInfo()
    {
        $userid = Session::get("user_id");
        return UserModel::where("id", $userid)->find();
    }

    public function blacklist()
    {
        $user = $this->getUserInfo();
        $userexpiration = $user->membership_expiration;
        $usermembership = $user->membership;

        $expirationmessage = '';

        if ($userexpiration) {
            $expirationDate = new \DateTime($userexpiration);
            $formatedexpire = $expirationDate->format('M j, Y'); 

            if ($usermembership !== 'Customer') {
                $expirationmessage = "Expire in $formatedexpire";
            } else {
                $expirationmessage = "No expiration date.";
            }
        } else {
            $expirationmessage = "No expiration date.";
        }

        if ($user->membership !== "Blacklist") {
            return redirect("/");
        }

        // Fetch blacklist reason and time
        $blacklistReason = isset($user->blacklistreason) ? $user->blacklistreason->reason : 'No reason provided';
        $blacklistTime = isset($user->blacklistreason) ? gmdate("F j, Y, g:i a", $user->blacklistreason->time) : 'N/A';

        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "profile_pic" => $user->profile_pic,
            "membership_expiration" => $expirationmessage,
            "review_time" => $blacklistTime,
            "blacklistreason" => $blacklistReason,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];

        View::assign($viewassign);
        return View::fetch("blacklist");
    }
}
