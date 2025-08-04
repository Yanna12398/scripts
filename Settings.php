<?php

//Coded By Jitler
namespace app\controller;

use app\model\UserModel;
use think\facade\View;
use think\facade\Session;
use app\http\middleware\AuthCheck;
use app\http\middleware\DiscordVerifyCheck;
use app\http\middleware\BlacklistCheck;
use app\http\middleware\MembershipCheck;
use think\facade\Request;
use Unirest\Request as UnirestRequest;
use Unirest\Request\Body as UnirestBody;
class Settings
{

    //Private Functions
    protected $middleware = [AuthCheck::class,DiscordVerifyCheck::class,BlacklistCheck::class,MembershipCheck::class];

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

    private function handleUpdateProfile($userid, $viewassign)
    {

        if (!Request::file("profile_pic")) {
            return $this->assignError("Please fill up all the fields", $viewassign, "settings");
        }

        $uploadedFile = Request::file("profile_pic");

        $allowedExtensions = ['jpg', 'jpeg','png','gif'];
        $fileExtension = strtolower($uploadedFile->extension());

        if (!in_array($fileExtension, $allowedExtensions)) {
            return $this->assignError("Error: Invalid file extension. Only .jpg,png,gif and .jpeg files are allowed.", $viewassign, "settings");
        }

        $image = file_get_contents($uploadedFile->getRealPath());


        $uploadata = [
            'image' => base64_encode($image),
            'key' => '323a6a3ef985b77f16221c9b42dc4cff'
        ];

        $headers = [
            "Content-Type" => "application/x-www-form-urlencoded"
        ];
        $body = UnirestBody::form($uploadata);
        $uploadimage = UnirestRequest::post("https://api.imgbb.com/1/upload" ,$headers ,$body);

        $profile_url = $uploadimage->body->data->url;

        $user = UserModel::where("id",$userid)->find();
        $user->save([
            'profile_pic' => $profile_url,
        ]);
        $viewassign["profile_pic"] = $profile_url;

        return $this->assignSuccess("Profile Updated.", $viewassign, "settings");
    }

    private function handleChangePassword($userid, $viewassign)
    {

        if (!Request::has("current-password") || !Request::has("new-password") || !Request::has("confirm-password")) {
           return $this->assignError("Please fill up all the fields", $viewassign, "settings");
        }

        $currentpassword = Request::param("current-password");
        $newpassword = Request::param("new-password");
        $confirmpassword = Request::param("confirm-password");

        if ($newpassword != $confirmpassword) {
            return $this->assignError("Password Not Match", $viewassign, "settings");
        }

        $user = UserModel::where("id", $userid)->find();
        if ($user->password != sha1($currentpassword)) {
            return $this->assignError("Current Password Incorrect", $viewassign, "settings");
        }

        //Updating The new Password

        $user->save([
            'password' => sha1($newpassword)
        ]);
        return $this->assignSuccess("Password Updated Successfuly", $viewassign, "settings");
    }

    private function handleChangeUsername($userid, $viewassign)
    {

        if (!Request::has("new-username") || !Request::has("password")) {
           return $this->assignError("Please fill up all the fields", $viewassign, "settings");
        }

        $newusername = Request::param("new-username");
        $password = Request::param("password");

        $user = UserModel::where("id", $userid)->find();
        if ($user->password != sha1($password)) {
            return $this->assignError("Current Password Incorrect", $viewassign, "settings");
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $newusername)) {
            return $this->assignError("Username contains invalid characters",  $viewassign, "settings");
        }

        $usernamecheck = UserModel::where('username', $newusername)->findOrEmpty();
        if (!$usernamecheck->isEmpty()) {
            return $this->assignError('Username already taken.', $viewassign, "settings");
        }

        //Updating The new Password

        $user->save([
            'username' => $newusername
        ]);
        $viewassign["username"] = $newusername;
        return $this->assignSuccess("Username Updated Successfuly", $viewassign, "settings");
    }

    private function handleChangeEmail($userid, $viewassign)
    {

        if (!Request::has("new-email") || !Request::has("password")) {
           return $this->assignError("Please fill up all the fields", $viewassign, "settings");
        }

        $newemail = Request::param("new-email");
        $password = Request::param("password");

        $user = UserModel::where("id", $userid)->find();
        if ($user->password != sha1($password)) {
            return $this->assignError("Current Password Incorrect", $viewassign, "settings");
        }

        if (!filter_var($newemail, FILTER_VALIDATE_EMAIL)) {
            return $this->assignError("Your Email is Invalid", $viewassign, "settings");
          }

        $usernamecheck = UserModel::where('username', $newemail)->findOrEmpty();
        if (!$usernamecheck->isEmpty()) {
            return $this->assignError('Email already taken.', $viewassign, "settings");
        }

        //Updating The new Password

        $user->save([
            'email' => $newemail
        ]);
        return $this->assignSuccess("Email Updated Successfuly", $viewassign, "settings");
    }

    private function handleResetDiscord($userid, $viewassign)
    {

        if (!Request::has("password")) {
           return $this->assignError("Please fill up all the fields", $viewassign, "settings");
        }

        $password = Request::param("password");

        $user = UserModel::where("id", $userid)->find();
        if ($user->password != sha1($password)) {
            return $this->assignError("Current Password Incorrect", $viewassign, "settings");
        }

        //Updating The new Password

        $user->save([
            'discord' => null
        ]);
        return redirect("discord-verification");
    }

    //Public Functions Serve The Main Pages

    public function settings()
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

        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $expirationmessage,
            "profile_pic" => $user->profile_pic,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];

        if (Request::isPost() && Request::has("update-profile-pic")) {
            return $this->handleUpdateProfile($user->id,$viewassign);
        }

        if (Request::isPost() && Request::has("change-password")) {
            return $this->handleChangePassword($user->id,$viewassign);
        }

        if (Request::isPost() && Request::has("change-username")) {
            return $this->handleChangeUsername($user->id,$viewassign);
        }

        if (Request::isPost() && Request::has("change-email")) {
            return $this->handleChangeEmail($user->id,$viewassign);
        }

        if (Request::isPost() && Request::has("reset-discord")) {
            return $this->handleResetDiscord($user->id,$viewassign);
        }

        View::assign($viewassign);
        return View::fetch("settings");
    }

}
