<?php

//Coded By Jitler
namespace app\controller;

use app\model\TokenModel;
use app\model\PasswordResetTokensModel;
use app\model\UserModel;
use app\model\DownloadLinksModel;
use think\facade\View;
use think\facade\Session;
use app\http\middleware\AuthCheck;
use app\http\middleware\AdminCheck;
use app\http\middleware\MembershipCheck;
use think\facade\Request;
use Unirest\Request as UnirestRequest;
use Unirest\Request\Body as UnirestBody;
use PHPMailer\PHPMailer\PHPMailer;

class Admin
{

    //Private Functions
    protected $middleware = [AuthCheck::class,AdminCheck::class,MembershipCheck::class];

    private function getUserInfo()
    {
        $userid = Session::get("user_id");
        return UserModel::where("id", $userid)->find();
    }

    private function generateRandomString($length = 10) {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }

    private function assignError($error, $userinfo, $view)
    {
        View::assign(array_merge($userinfo, [
            "error" => $error,
        ]));
        return View::fetch($view);
    }

    private function assignSuccess($success, $userinfo, $view)
    {
        View::assign(array_merge($userinfo, [
            "success" => $success,
        ]));
        return View::fetch($view);
    }

    private function handleGenerateToken($userid,$userinfo) {

        $token = $this->generateRandomString(8)."-".$this->generateRandomString(8)."-".$this->generateRandomString(8);
        
        $newtoken = new TokenModel;
        $newtoken->save([
            'token' => $token,
            'taken' => 0,
            'paymentmethod' => "Generated Token"
        ]);
        $userinfo["token"] = $token;
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: generated token $token");
        return $this->assignSuccess("Token Generated Successfully", $userinfo, "admin");

    }

    

    private function handleGenerateResetPasssword ($userid,$userinfo) {

        if (!Request::has("username")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "admin");
        }

        $site_username = Request::param("username");
        
        $user = UserModel::where("id",$userid)->find();

        if ($user->membership !== "Admin") {
            return $this->assignError("You dont have permission to request a reset password link", $userinfo, "admin");
        }

        $requested_user = UserModel::where("username",$site_username)->findOrEmpty();

        if ($requested_user->isEmpty()) {
            return $this->assignError("User Not Found", $userinfo, "admin");
        }

        $reset_token = $this->generateRandomString(32);

        $passwordresettoken = new PasswordResetTokensModel;
        $passwordresettoken->save([
            'reset_token' => $reset_token,
            'ownerid' => $requested_user->id
        ]);
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: generated reset-password-token $reset_token to $site_username");
        $userinfo["reset_password_url"] = Request::server("HTTP_ORIGIN"). '/reset-password?token='.$reset_token;
        return $this->assignSuccess("Generated Successfully", $userinfo, "admin");

    }

    private function handleChangeMembership ($userid,$userinfo) {

        if (!Request::has("username") || !Request::has("membership")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "admin");
        }
        $user = $this->getUserInfo();
        $site_username = Request::param("username");
        $membership = Request::param("membership");
        $blreason = Request::param("blreason");
        
        $user = UserModel::where("id",$userid)->find();

        if ($user->membership !== "Admin") {
            return $this->assignError("You dont have permission to Change Change Membserhip", $userinfo, "admin");
        }

        $requested_user = UserModel::where("username",$site_username)->findOrEmpty();

        if ($requested_user->isEmpty()) {
            return $this->assignError("User Not Found", $userinfo, "admin");
        }

        $updateuser = UserModel::where("username",$site_username)->find();

        if ($membership === "Blacklist") {
            $updateuser->save([
                'membership' => $membership,
                'blacklistreason' => [
                    'reason' => $blreason,
                    'time' => time()
                ]
            ]);
            $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: blacklisted $site_username, blacklist reason: $blreason");
            return $this->assignSuccess("User Blacklisted Change Successfully", $userinfo, "admin");

        }else {
            $updateuser->save([
                'membership' => $membership,
                'blacklistreason' => null,
            ]);
            $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: changed $site_username membership: $membership");
            return $this->assignSuccess("Membership Change Successfully", $userinfo, "admin");
        }
    }

    private function handleGenerateTokenPurchase($userid,$userinfo) {

        if (!Request::has("email") || !Request::has("paymentmethod")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "admin");
        }

        $token_purchase = $this->generateRandomString(8)."-".$this->generateRandomString(8)."-".$this->generateRandomString(8);
        
        $email = Request::param("email");
        $paymentmethod = Request::param("paymentmethod");

        $newtoken = new TokenModel;
        $newtoken->save([
            'token' => $token_purchase,
            'taken' => 0,
            'rbxuserid' => number_format($userid),
            'paymentmethod' => $paymentmethod

        ]);
        $userinfo["token_purchase"] = $token_purchase;

        $mail = new PHPMailer(true);

        //$mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->isSMTP();
        $mail->SMTPSecure = config('smtp.secure');
        $mail->Host = config('smtp.host');
        $mail->SMTPAuth = true;
        $mail->Username = config('smtp.username');
        $mail->Password = config('smtp.password');
        $mail->Port = config('smtp.port');
        $mail->AddAddress($email);
        $mail->Sender = config('smtp.username');
        $mail->FromName = "Thank For Purchasing ".config('app.app_name');

        //Content
        $mail->isHTML(true);
        $mail->Subject = 'Thank You For Purchasing ';
        $mail->Body  = '

        <!DOCTYPE html>
        <html>
        <head>
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    background-color: #f6f6f6;
                    font-family: Arial, sans-serif;
                    font-size: 16px;
                    line-height: 1.5;
                    margin: 0;
                    padding: 0;
                }

                #wrapper {
                    background-color: #fff;
                    border-radius: 5px;
                    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
                    margin: 20px auto;
                    max-width: 600px;
                    padding: 20px;
                }

                h2 {
                    color: #333;
                    font-size: 24px;
                    font-weight: bold;
                    margin-bottom: 20px;
                }

                p {
                    color: #555;
                    margin-bottom: 10px;
                }

                a {
                    color: #007bff;
                    text-decoration: none;
                }

                a:hover {
                    text-decoration: underline;
                }
            </style>
        </head>
        <body>
            <div id="wrapper">
                <h2>Welcome to '.config('app.app_name').'</h2>

                <p>Thank you for purchasing our product. Please do not share this token with anyone else.</p>
                <p>Payment Method : <b>'.$paymentmethod
                .'</b></p>
                <p>Token : <b>'.$token_purchase.'</b></p>
                
                <p>Register here:</p>
                <p><a href="' . Request::server("HTTP_ORIGIN"). '/register">' . Request::server("HTTP_ORIGIN") . '/register</a></p>

                <p>Best regards,</p>
                <p>'.config('app.app_name').' Team</p>
            </div>
        </body>
        </html>

        ';
        $mail->send();
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: generated token $token_purchase via $paymentmethod");
        return $this->assignSuccess("Token Generated Successfully", $userinfo, "admin");

    }

    private function handleUploadThemeDownload($userid,$userinfo) {

        if (!Request::has("theme_name") || !Request::has("download_url") || !Request::file("theme_pic")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "admin");
        }

        $theme_name = Request::param("theme_name");
        $download_url = Request::param("download_url");
        $uploadedFile = Request::file("theme_pic");

        $allowedExtensions = ['jpg', 'jpeg','png','gif'];
        $fileExtension = strtolower($uploadedFile->extension());

        if (!in_array($fileExtension, $allowedExtensions)) {
            return $this->assignError("Error: Invalid file extension. Only .jpg,png,gif and .jpeg files are allowed.", $userinfo, "admins");
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

        $image_url = $uploadimage->body->data->url;

        $newdownloadlink = new DownloadLinksModel;
        $newdownloadlink->save([
            'file_name' => $theme_name,
            'theme_image' => $image_url,
            'file_link' => $download_url
        ]);
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: uploaded themename = $theme_name");
        return $this->assignSuccess("Successfully Uploaded", $userinfo, "admin");
    }

    private function handleDeleteThemeDownload($userid,$userinfo) {

        if (!Request::has("theme_id")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "admin");
        }

        $theme_id = Request::param("theme_id");

        $deletedownloadlinks = DownloadLinksModel::where("id",$theme_id)->find();
        $deletedownloadlinks->delete();
        
        $downloadlinks = DownloadLinksModel::select();

        $userinfo["downloadlinks"] = $downloadlinks;
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: deleted themeid = $theme_id");
        return $this->assignSuccess("Successfully Deleted", $userinfo, "admin");
    }

    private function handleMonthlySubscription($userid, $userinfo) {
        if (!Request::has("change_month") || !Request::has("username")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "admin");
        }
    
        $newmonthlysubscription = Request::param("change_month"); // The number of months
        $site_username = Request::param("username");
    
        if (!is_numeric($newmonthlysubscription) || $newmonthlysubscription < 1) {
            return $this->assignError("Invalid monthly subscription value", $userinfo, "admin");
        }
    
        $requested_user = UserModel::where("username", $site_username)->findOrEmpty();
    
        if ($requested_user->isEmpty()) {
            return $this->assignError("User Not Found", $userinfo, "admin");
        }

        if ($requested_user->membership !== "Customer") {
            return $this->assignError("User is not a Customer. Cannot change subscription.", $userinfo, "admin");
        }

        $currentexpiration = $requested_user->membership_expiration 
            ? new \DateTime($requested_user->membership_expiration) 
            : new \DateTime();
        $currentexpiration->modify("+{$newmonthlysubscription} month");

        $requested_user->membership_expiration = $currentexpiration->format('Y-m-d');
        $requested_user->save();
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: added +$newmonthlysubscription monthly subscription to $site_username,");
        return $this->assignSuccess("Monthly subscription updated successfully for user: $site_username", $userinfo, "admin");
    }

    private function handleGamelimit($userid, $userinfo) {
        if (!Request::has("add-gameslots") || !Request::has("username")) {
            return $this->assignError("Please fill up all the fields", $userinfo, "admin");
        }
    
        $newgamelimit = Request::param("add-gameslots");
        $site_username = Request::param("username");
    
        if (!is_numeric($newgamelimit) || $newgamelimit < 0) {
            return $this->assignError("Invalid game limit value", $userinfo, "admin");
        }
    
        $requested_user = UserModel::where("username", $site_username)->findOrEmpty();
    
        if ($requested_user->isEmpty()) {
            return $this->assignError("User Not Found", $userinfo, "admin");
        }

        $requested_user->stats->game_limit = (int)$newgamelimit;
        
        $requested_user->save();
        $user = $this->getUserInfo();
        $this->sendAdminLogs($user->username, $user->profile_pic, "$user->username: added $newgamelimit Gamelimit to $site_username,");
        return $this->assignSuccess("Game limit updated successfully for user: $site_username", $userinfo, "admin");
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
    //Public Functions Serve The Main Pages

    public function admin()
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

        $downloadlinks = DownloadLinksModel::select();
        
        $userinfo = [
            "username" => $user->username,
            "membership" => $user->membership,
            "membership_expiration" => $expirationmessage,
            "profile_pic" => $user->profile_pic,
            "downloadlinks" => $downloadlinks,
            "discord_server_link" => config("app.discord_server_link"),
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ];

        if (Request::isPost() && Request::has("generate-token")) {
            return $this->handleGenerateToken($user->id,$userinfo);
        }

        if (Request::isPost() && Request::has("generate-token-purchase")) {
            return $this->handleGenerateTokenPurchase($user->id,$userinfo);
        }

        if (Request::isPost() && Request::has("generate-reset-password")) {
            return $this->handleGenerateResetPasssword($user->id,$userinfo);
        }

        if (Request::isPost() && Request::has("change-membership")) {
            return $this->handleChangeMembership($user->id,$userinfo);
        }

        if (Request::isPost() && Request::has("change-month")) {
            return $this->handleMonthlySubscription($user->id,$userinfo);
        }

        if (Request::isPost() && Request::has("add-gameslot")) {
            return $this->handleGamelimit($user->id,$userinfo);
        }

        if (Request::isPost() && Request::has("upload-theme-download")) {
            return $this->handleUploadThemeDownload($user->id,$userinfo);
        }

        if (Request::isPost() && Request::has("delete-theme-download")) {
            return $this->handleDeleteThemeDownload($user->id,$userinfo);
        }

        View::assign($userinfo);
        return View::fetch("admin");
    }

}
