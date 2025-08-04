<?php
namespace app\controller;

use app\BaseController;
use app\model\UserModel;
use app\model\TokenModel;
use app\model\PasswordResetTokensModel;
use think\facade\Request;
use think\facade\View;
use think\facade\Session;
use Unirest\Request as UnirestRequest;
use Unirest\Request\Body as UnirestBody;
use PHPMailer\PHPMailer\PHPMailer;

class Auth extends BaseController
{
    private function assignError($error, $view)
    {
        View::assign([
            'error' => $error,
        ]);
        return View::fetch($view);
    }

    private function assignSuccess($success, $view)
    {
        View::assign([
            'success' => $success,
        ]);
        return View::fetch($view);
    }

    public function checkUsernameAvailability()
    {
        $username = Request::param('username');
        $user = UserModel::where('username', $username)->findOrEmpty();

        if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
            return json(['available' => false, 'message' => 'Username contains invalid characters']);
        }

        if (!$user->isEmpty()) {
            return json(['available' => false, 'message' => 'Username Already Taken']);
        }

        return json(['available' => true]);
    }

    private function handleLogin($authorizeUrl)
    {
        $username = Request::param('username');
        $password = Request::param('password');

        View::assign([
            'authorizeUrl' => $authorizeUrl,
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);
        
        if (empty($username) || empty($password)) {

            return $this->assignError('Please fill up all the fields', 'login');
        }

        $user = UserModel::where('username', $username)->where('password', sha1($password))->findOrEmpty();

        if ($user->isEmpty()) {
            return $this->assignError('Invalid username or password', 'login');
        }

        Session::set('logged', true);
        Session::set('user_id', $user->id);
        return $this->assignSuccess("Login Succcessfully", "login");
    }

    private function handleRegistration()
    {
        $username = Request::param('username');
        $password = Request::param('password');
        $confirmpassword = Request::param("confirm-password");
        $email = Request::param('email');
        $token = Request::param('token');

        View::assign([
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);

        if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
            return $this->assignError("Username contains invalid characters", "register");
        }

        if ($password != $confirmpassword) {
            return $this->assignError("Password Not Match.", "register");
        }

        if (empty($username) || empty($password) || empty($email) || empty($token)) {
            return $this->assignError('Please fill up all the fields', 'register');
        }

        $usernamecheck = UserModel::where('username', $username)->findOrEmpty();

        if (!$usernamecheck->isEmpty()) {
            return $this->assignError('Username already taken.', 'register');
        }

        $emailcheck = UserModel::where('email', $email)->findOrEmpty();

        if (!$emailcheck->isEmpty()) {
            return $this->assignError('Email already taken.', 'register');
        }

        $tokencheck = TokenModel::where('token', $token)->findOrEmpty();

        if ($tokencheck->isEmpty()) {
            return $this->assignError('Invalid Token.', 'register');
        }

        if ($tokencheck->taken) {
            return $this->assignError('Token Already Taken.', 'register');
        }

        $membershipExpireDate = date('Y-m-d', strtotime('+1 month'));

        $user = new UserModel;
        $user->save([
            'username' => $username,
            'password' => sha1($password),
            'email' => $email,
            'membership' => 'Customer', 
            'membership_expiration' => $membershipExpireDate,
            'token' => $token,
            'stats' => json_encode([
                "robux" => 0,
                "credits" => 0,
                "revenue" => 0,
                "points" => 0,
                "game_limit" => config("app.game_limit"),
            ]),
        ]);

        $tokencheck->save([
            'taken' => true,
            'ownerid' => $user->id,
        ]);

        Session::set('logged', true);
        Session::set('user_id', $user->id);

        return $this->assignSuccess("Registered Succcessfully", "register");
    }

    private function handleDiscordLogin () {
        $code = Request::param("code");

        View::assign([
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);

        $data = [
            'client_id' => config('discordoauth2.discord_client_id'),
            'client_secret' => config('discordoauth2.discord_client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('discordoauth2.discord_redirect_login_url'),
            'scope' => 'identify'
        ];

        $headers = [
            "Content-Type" => "application/x-www-form-urlencoded"
        ];

        $body = UnirestBody::form($data);
        $gettoken = UnirestRequest::post("https://discord.com/api/oauth2/token",$headers ,$body);

        if ($gettoken->code != 200){
            return $this->assignError("Failed to exchange authorization code for access token", "login");
        }

        $access_token = $gettoken->body->access_token;

        $headers = [
            "Authorization" => "Bearer $access_token" 
        ];

        $discord_info = UnirestRequest::get("https://discord.com/api/users/@me",$headers);

        $discordId = $discord_info->body->id;

        $user = UserModel::where("discord", $discordId)->findOrEmpty();

        if ($user->isEmpty()){
            return $this->assignError("There are no accounts linked to your Discord account.", "login");
        }

        Session::set('logged', true);
        Session::set('user_id', $user->id);
        return $this->assignSuccess("Login Succcessfully", "login");
    }

    private function generateRandomString($length = 10) {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }

    private function handleForgotPassword() {
        $email = Request::param("email");

        View::assign([
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);

        $checkeruserEmail = UserModel::where("email",$email)->findOrEmpty();
        if ($checkeruserEmail->isEmpty()){
            return $this->assignError("There are no accounts linked to this email address.", "forgot");
        }
        $reset_token = $this->generateRandomString(32);

        $passwordresettoken = new PasswordResetTokensModel;
        $passwordresettoken->save([
            'reset_token' => $reset_token,
            'ownerid' => $checkeruserEmail->id
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
        $mail->Subject = 'Reset Password';
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

                <p>Dear '.$checkeruserEmail->username.',</p>
                <p>We have received a request to reset your password for '.config('app.app_name').' Below is your password reset key:</p>
                <p>Remember, this key contains sensitive information and should be kept confidential.</p>

                <p>To initiate the password reset process, please click the link below:</p>
                <p><a href="' . Request::server("HTTP_ORIGIN"). '/reset-password?token='.$reset_token.'">' . Request::server("HTTP_ORIGIN") . '/reset-password?token='.$reset_token.'</a></p>

                <p>Best regards,</p>
                <p>The '.config('app.app_name').' Team</p>
            </div>
        </body>
        </html>

        ';
        $mail->send();
        return $this->assignSuccess("Successfuly Please Check Your Email.", "forgot");
    }

    private function handleResetPassword() {

        $newpassword = Request::param("password");
        $confirmpassword = Request::param("confirm-password");

        View::assign([
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);

        if ($newpassword != $confirmpassword) {
            return $this->assignError("Password Not Match.", "reset");
        }

        $resettoken = Request::param("token");
        $checkresetkey = PasswordResetTokensModel::where("reset_token",$resettoken)->findOrEmpty();

        if ($checkresetkey->isEmpty()) {
            return $this->assignError("Reset Token is Invalid", "reset");
        }

        $user = UserModel::where("id", $checkresetkey->ownerid)->find();
        $user->save([
            'password' => sha1($newpassword)
        ]);

        $tokendelete = new PasswordResetTokensModel();
        $tokendelete->where("reset_token", $resettoken)->delete();

        Session::set('logged', true);
        Session::set('user_id', $user->id);
        return $this->assignSuccess("Passsword Reset Successfully", "reset");
    }

    public function login() {
        if (Session::has('logged')) {
            return redirect("/");
        }

        $authorizeUrl = 'https://discord.com/api/oauth2/authorize';
        $params = array(
            'client_id' => config('discordoauth2.discord_client_id'),
            'redirect_uri' => config('discordoauth2.discord_redirect_login_url'),
            'response_type' => 'code',
            'scope' => 'guilds.join identify'
        );
        $authorizeUrl .= '?' . http_build_query($params);

        if (Request::isPost()) {
            return $this->handleLogin($authorizeUrl);
        }

        View::assign([
            'authorizeUrl' => $authorizeUrl,
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);

        if (Request::param("code")){
            return $this->handleDiscordLogin();
        }

        return View::fetch('login');
    }

    public function logout()
    {
        Session::delete('logged');
        Session::delete('user_id');
        return redirect("/");
    }

    public function register()
    {
        if (Session::has('logged')) {
            return redirect("/");
        }

        if (Request::isPost()) {
            return $this->handleRegistration();
        }

        View::assign([
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);

        return View::fetch('register');
    }

    public function forgot()
    {
        if (Session::has('logged')) {
            return redirect("/");
        }

        if (Request::isPost()) {
            return $this->handleForgotPassword();
        }

        View::assign([
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);

        return View::fetch('forgot');
    }

    public function reset()
    {
        if (Session::has('logged')) {
            return redirect("/");
        }

        if (empty(Request::param("token"))) {

            View::assign([
                'form_error' => "Reset Token is Empty",
            ]);
            return View::fetch('reset');
        }

        $resettoken = Request::param("token");
        $checkresetkey = PasswordResetTokensModel::where("reset_token",$resettoken)->findOrEmpty();

        if ($checkresetkey->isEmpty()) {
            View::assign([
                'form_error' => "Reset Token is Invalid",
            ]);
            return View::fetch('reset');
        }

        $user = UserModel::where("id", $checkresetkey->ownerid)->find();

        View::assign([
            'reset_password_username' => $user->username,
            'reset_token' => $resettoken,
            "app_name" => config("app.app_name"),
            "app_image" => config("app.app_image"),
        ]);

        if (Request::isPost()) {
            return $this->handleResetPassword();
        }

        return View::fetch('reset');
    }
   
}
