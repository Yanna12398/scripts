<?php

// refactored by krispypatata
namespace app\controller;

use think\facade\View;
use think\facade\Session;
use app\http\middleware\AuthCheck;
use app\http\middleware\MembershipCheck;
use app\model\UserModel;
use think\facade\Request;
use Unirest\Request as UnirestRequest;
use Unirest\Request\Body as UnirestBody;
class DiscordVerification
{

    //Private Functions
    protected $middleware = [AuthCheck::class,MembershipCheck::class];

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

    private function handleDiscordVerification($userid, $viewassign)
    {
        $code = Request::param("code");

        $data = [
            'client_id' => config('discordoauth2.discord_client_id'),
            'client_secret' => config('discordoauth2.discord_client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('discordoauth2.discord_redirect_verification_url'),
            'scope' => 'identify'
        ];

        $headers = [
            "Content-Type" => "application/x-www-form-urlencoded"
        ];

        $body = UnirestBody::form($data);
        $gettoken = UnirestRequest::post("https://discord.com/api/oauth2/token",$headers ,$body);

        if ($gettoken->code != 200){
            return $this->assignError("Failed to exchange authorization code for access token", $viewassign, "discord-verification");
        }

        $access_token = $gettoken->body->access_token;

        $headers = [
            "Authorization" => "Bearer $access_token" 
        ];

        $discord_info = UnirestRequest::get("https://discord.com/api/users/@me",$headers);

        $discordId = $discord_info->body->id;

        $checkifTaken = UserModel::where("discord", $discordId)->findOrEmpty();

        if (!$checkifTaken->isEmpty()){
            return $this->assignError("This discord Account Already Used", $viewassign, "discord-verification");
        }

        $user = UserModel::where("id", $userid)->find();
        
        $user->save([
            'discord' => $discordId,
        ]);

        return $this->assignSuccess("Discord ID is Now Verified.", $viewassign, "discord-verification");

    }

    //Public Functions Serve The Main Pages

    public function discordVerification()
    {
        $user = $this->getUserInfo();

        if ($user->discord){
            return redirect("/");
        }

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

        $authorizeUrl = 'https://discord.com/api/oauth2/authorize';
        $params = array(
            'client_id' => config('discordoauth2.discord_client_id'),
            'redirect_uri' => config('discordoauth2.discord_redirect_verification_url'),
            'response_type' => 'code',
            'scope' => 'guilds.join identify'
        );
        $authorizeUrl .= '?' . http_build_query($params);

        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $expirationmessage,
            "profile_pic" => $user->profile_pic,
            "authorizeUrl" => $authorizeUrl,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];

        if (Request::param("code")){
            return $this->handleDiscordVerification($user->id,$viewassign);
        }

        View::assign($viewassign);
        return View::fetch("discord-verification");
    }


}
