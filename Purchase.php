<?php

//Coded By Jitler
namespace app\controller;

use think\facade\View;
use think\facade\Session;
use app\model\TokenModel;
use think\facade\Request;
use Unirest\Request as UnirestRequest;
use Unirest\Request\Body as UnirestBody;
use PHPMailer\PHPMailer\PHPMailer;

class Purchase
{

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

    private function generateRandomString($length = 10) {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }

    private function handlePurchase ($viewassign ){
        if (!Request::has("email") || !Request::has("rbxusername")) {
            return $this->assignError('Please fill up all the fields', $viewassign, 'purchase');
        }

        $shirtid = config('purchase.shirt_id');
        $email = Request::param("email");
        $username = Request::param("rbxusername");

        $data = [
            'usernames' => [$username]
        ];
        $body = UnirestBody::json($data);
        $headers = ['Accept' => "application/json, text/plain, */*", 'Content-Type' => "application/json;charset=utf-8", ];
        $getid = UnirestRequest::post("https://users.roblox.com/v1/usernames/users",$headers,$body);

        if (empty($getid->body->data[0]->id)){
            return $this->assignError('Invalid Username', $viewassign, 'purchase');
        }

        $userid = $getid->body->data[0]->id;

        $checktokenrbxid = TokenModel::where("rbxuserid",$userid)->findOrEmpty();

        if (!$checktokenrbxid->isEmpty()){
            return $this->assignError('Account Already Used', $viewassign, 'purchase');
        }

        $ownercheck = UnirestRequest::get("https://inventory.roblox.com/v1/users/$userid/items/Asset/$shirtid/is-owned");

        if ($ownercheck->code === 400) {
            return $this->assignError($ownercheck->body->errors[0]->message, $viewassign, 'purchase');
        }

        if ($ownercheck->raw_body === "true") {

            $token_purchase = $this->generateRandomString(8)."-".$this->generateRandomString(8)."-".$this->generateRandomString(8);
        
            $paymentmethod = "Via Shirt";
            $newtoken = new TokenModel;
            $newtoken->save([
                'token' => $token_purchase,
                'taken' => 0,
                'rbxuserid' => $userid,
                'paymentmethod' => $paymentmethod,
            ]);

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
            $mail->FromName = config('app.app_name')." Reset Password";

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
                    <p>Payment Method : <b>'.$paymentmethod.'</b></p>
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
            return $this->assignSuccess('Successfully Purchase Please Check Your Email', $viewassign, 'purchase');
        }else{
            return $this->assignError('Please Purchase the Shirt First', $viewassign, 'purchase');
        }

    }

    //Public Functions Serve The Main Pages

    public function purchase()
    {
        if (Session::has('logged')) {
            return redirect("/");
        }

        $shirtid = config('purchase.shirt_id');
        $viewassign = [
            "shirtid" => $shirtid,
            "discord_server_link" => config("app.discord_server_link"),
            "app_image" => config("app.app_image"),
            "app_name" => config("app.app_name"),
        ];

        if (Request::isPost()) {
            return $this->handlePurchase($viewassign);
        }
        $shirtid = config('purchase.shirt_id');

        View::assign($viewassign);

        return View::fetch("purchase");
    }

}
