<?php

namespace Modules\User\Http\Controllers;

use App\Helpers\TwilioHelper;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Common\Entities\Contact;
use Modules\FilesModule\Entities\Media;
use Modules\Common\Entities\Property;
use Modules\Common\Entities\TwoFactorAuth;
use Modules\Common\Jobs\SendEmail;
use Modules\Settings\Entities\Role;
use Modules\User\Entities\User;
use Modules\User\Entities\UserPermission;
use Modules\User\Http\Requests\Auth\LoginRequest;
use Modules\User\Http\Requests\Auth\ResetPasswordRequest;
use Modules\User\Http\Requests\Auth\ForgetPasswordRequest;
use Modules\User\Http\Requests\Auth\InviteRequest;
use Modules\User\Http\Requests\Auth\RegisterRequest;
use Modules\User\Transformers\UserResource;
use PragmaRX\Google2FA\Google2FA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class AuthController extends InitController
{
    public function login(LoginRequest $request)
    {
        try {
            $item = [];
            $credentials = $request->only(['email', 'phone', 'password', 'client']);
            $access_token = null;
            if (!$access_token = Auth::guard('api')->attempt($credentials, true)) {
                if($request->email){
                    throw new \Exception('Email or password is not correct!', 400);
                }
                else{
                    throw new \Exception('Phone or password is not correct!', 400);
                }
            } else {
                $item = Auth::guard('api')->user();
                $item->fcm_token = $request->fcm_token;
                $item->save();
                $item['access_token'] = $access_token;
                $item = new UserResource($item);
            }
            return $this->respondWithSuccess($item);
        } catch (\Exception $e) {
            return $this->respondUnAuthenticated($e->getMessage());
        }
    }
    public function updateFcmToken(Request $request)
    {
        try {
            $item = auth('api')->user();
            $item->fcm_token = $request->fcm_token;
            $item->save();
            $item = new UserResource($item);
            return $this->respondOk('updated successfully');
        } catch (\Exception $e) {
            return $this->respondUnAuthenticated($e->getMessage());
        }
    }

    public function profile(Request $request): JsonResponse
    {
        $user = [];
        try {
            $user = Auth::guard('api')->user();
            $user = new UserResource($user);
            return $this->respondWithSuccess($user);
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    public function invite(InviteRequest $request)
    {
        try {
            $value = $request->validated();
            switch (array_key_first($value)) {
                case 'email':
                    $this->inviteByMail($request->email);
                    break;
                case 'sms':
                    $this->inviteBySMS($request->email);
                    break;
                default:
                    break;
            }
            return $this->respondOk('Invite Sent successfully');
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    public function otherProfile($id): JsonResponse
    {
        $code = 200;
        $message = "done.";
        $user = [];
        try {
            $user = User::where(['id' => $id, 'client' => request()->header('client')])->first();
            if (empty($user)) {
                throw new \Exception('User Not Found', 400);
            }
            $user = new UserResource($user);
            return $this->respondWithSuccess($user);
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    public function forgetPassword(ForgetPasswordRequest $request)
    {
        try {
            $credentials = $request->only(['email', 'client','phone']);
            $item = User::query()->where($credentials)->first();
            if (empty($item)) {
                return $this->respondError('No user was found!');
            }
            $item->forget_password_code = $this->randomStr(6);
            $item->save();
            Log::error('Phone : '.$item->phone .' client : '.$request->client.' Phone: '.$item->forget_password_code);
            if($request->phone ){
                $this->resetBySMS($item);
                return $this->respondOk('A sms has been sent to your phone');
            }
            else if($request->email){
                $this->resetByMail($item);
                return $this->respondOk('An email has been sent to your inbox');
            }
            return $this->respondError('This sending method is not available!');
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    public function activateMail(Request $request)
    {
        $code = $request->code;
        $client = $request->client;
        $user_activation = $this->activateUser($code, $client);
        if($user_activation['status']===false){
            return $this->respondError($user_activation['message']);
        }
        return $this->respondOk($user_activation['message']);
    }

    private function activateUser($code, $client): array
    {
        try {
            $item = User::query()->where(['verification_code' => $code, 'client' => $client])->first();
            if (empty($item)) {
                return ['status'=>false, 'message'=>'The verification code is incorrect or expired'];
            }
            $item->verification_code = Null;
            $item->email_verified_at = now();
            $item->save();
            //Two factor auth creation
            //TODO add these client settings to db then retrieve them here and update the condition
            $client_domain = null;
            $client_name = null;
            if($client == 'CRISK'){
                $client_domain = 'c-risk.com';
                $client_name = 'C-Risk';
            } elseif($client == 'BUMBLEBEE'){
                $client_domain = 'bumblebeeai.io';
                $client_name = 'Bumblebee AIR';
            } elseif($client == 'PROTOSTARS'){
                $client_domain = 'protostars.ai';
                $client_name = 'Protostars';
            }
            if($client_name!=null){
                $google2fa = new Google2FA();
                $secretKey = $google2fa->generateSecretKey();
                $two_factor_auth = new TwoFactorAuth();
                $two_factor_auth->user_type = User::class;
                $two_factor_auth->user_id = $item->id;
                $two_factor_auth->client = $client;
                $two_factor_auth->secret = $secretKey;
                $two_factor_auth->save();
                $qrCodeUrl = $google2fa->getQRCodeUrl(
                    $client_name,
                    $item->email,
                    $secretKey
                );
                //$item['qr_code_url'] = $qrCodeUrl;
                $qr_code_image = QrCode::format('png')->size(200)->generate($qrCodeUrl);
                $item->qr_code_image = $qr_code_image;
            }
            $sender_name = $this->getEmailSenderName($client);
            $logo = $this->getClientLogo($client);
            $frontend_domain = $this->getClientFrontendDomain($client);
            $branding_colors = $this->getClientBrandingColors($client);
            $notification_body = '<p class="">We are delighted to have you here</p>
            <p class="mt-2">If you have any questions about getting started feel free to contact us</p>
            <p class="mt-2 mb-1">Click the button below to login</p>';
            $email_data = [
                //'template_path' => 'User::mails.welcome-mail',
                'template_path' => 'Common::mails.user-notification',
                'subject' => $client == 'MID' ? 'Welcome to My Irish Date ' : 'Welcome Mail',
                'header_title' => 'Welcome to '.$sender_name,
                'receiver_email' => $item->email,
                'name' => $item->first_name,
                'client' =>  $client == 'MID' ? 'My Irish Date' : $client,
                'logo' => $logo,
                'brand_color' => $branding_colors['primary'],
                'notification_body' => $notification_body,
                'link' => $frontend_domain.'/login',
                'link_text' => 'Login',
                'encoded_image_attachment' => $item->qr_code_image? base64_encode($item->qr_code_image) : null,
                'sender_email' => strtolower($client).'@bumblebeeai.io',
                'sender_name' => $sender_name
            ];
            SendEmail::dispatch($email_data);
            return ['status'=>true, 'message'=>'Email verified successfully!'];
        } catch (\Exception $e) {
            return ['status'=>false, 'message'=>$e->getMessage()];
        }
    }

    public function verfiyOtpToken(Request $request)
    {
        try {
            $item = User::query()->where(['verification_code' => $request->code, 'client' => $request->client])->orWhere(['forget_password_code' => $request->code, 'client' => $request->client])->first();
            Log::error('Phone : '.$item->phone .' client : '.$request->client.' Code: '.$request->code);
            if (empty($item)) {
                return $this->respondError('Otp Token is not valid');
            }
            return $this->respondOk('Otp Token is valid');
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        try {
            $item = User::query()->where(['forget_password_code' => $request->code, 'client' => $request->client])->first();
            if (empty($item)) {
                return $this->respondError('your code is not correct');
            }
            $item->password = $request->password;
            $item->forget_password_code = null;
            $item->save();
            $access_token = Auth::guard('api')->attempt(['email' => $item->email, 'password' => $request->password, 'client'=>$request->client], true);
            $item->method = 'login';
            $item->access_token = $access_token;
            $item = new UserResource($item);
            Log::error('Phone : '.$item->phone .' client : '.$request->client.' Code: '.$request->code);
            return $this->respondOk('Password rest successfully !');
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }



    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $data = $request->validated();
            //$data['name'] = $request->first_name ? $request->first_name . ' ' . $request->second_name : $request->name;
            $client = $data['client'];
            if($request->get('name')!=null){
                $name_split = explode(' ',$request->get('name'));
                $first_name = $name_split[0];
                $last_name = $name_split[1]?? '';
                $data['first_name'] = $first_name;
                $data['last_name'] = $last_name;
            } elseif($request->get('second_name')!=null) {
                $data['last_name'] = $request->get('second_name');
            }
            $data['password'] = isset($data['password'])? $data['password'] : $this->randomStr(10);
            $data['verification_code'] = $this->randomStr();
            $data['fcm_token'] = $request->fcm_token;
            $item = $user = User::create($data);
            if (!$item['access_token'] = Auth::guard('api')->attempt(['email' => $user->email, 'password' => $data['password'] , 'client'=>$data['client']], true)) {
                throw new \Exception('Something went wrong!', 400);
            } else {
                $item = new UserResource($item);
            }
            //Add user permissions if applicable
            $type = $data['role_type']?? null;
            if($type!=null){
                $role_slug = $type.'_admin';
                $role = Role::where(['slug'=>$role_slug, 'client'=>$data['client']])
                    ->first();
                if($role!=null){
                    $u_permission = UserPermission::create(['user_type' => User::class,
                        'user_id' => $user->id,'role_id' => $role->id]);
                }
            }
            $request->activate_type == 'sms' ? $this->activateBySMS($item) : $this->activateByMail($item);
            DB::commit();
            return $this->respondWithSuccess($item);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->respondUnAuthenticated($e->getMessage());
        }
    }

    public function registerwithProperty(RegisterRequest $request): JsonResponse {
        try {
            DB::beginTransaction();
            $data = $request->validated();
            $first_name = $request->get('first_name','');
            $last_name = $request->get('second_name','');
            //$data['name'] =  $first_name!=null? $first_name . ' ' . $last_name : $request->name;
            if($request->get('name')!=null){
                $name_split = explode(' ',$request->get('name'));
                $first_name = $name_split[0];
                $last_name = $name_split[1]?? '';
                $data['first_name'] = $first_name;
                $data['last_name'] = $last_name;
            } elseif($request->get('second_name')!=null) {
                $data['last_name'] = $request->get('second_name');
            }
            $data['password'] = isset($data['password'])? $data['password'] : $this->randomStr(10);
            $data['verification_code'] = $this->randomStr();
            $data['fcm_token'] = $request->fcm_token;
            $item = $user = User::create($data);
            //Add user permissions if applicable
            $type = $data['role_type']?? null;
            if($type!=null){
                $role_slug = $type.'_admin';
                $role = Role::where(['slug'=>$role_slug, 'client'=>$data['client']])
                    ->first();
                if($role!=null){
                    $u_permission = UserPermission::create(['user_type' => User::class,
                        'user_id' => $user->id,'role_id' => $role->id]);
                }
            }
            //Create a contact entry
            $contact_data = [];
            $contact_data['first_name'] = $first_name;
            $contact_data['last_name'] = $last_name;
            $contact_data['phone'] = isset($data['phone'])? $data['phone'] : null;
            $contact_data['email'] = isset($data['email'])? $data['email'] : null;
            $contact_data['model'] = User::class;
            $contact_data['model_id'] = $user->id;
            $contact_data['client'] = $data['client'];
            $contact = Contact::create($contact_data);
            $request->activate_type == 'sms' ? $this->activateBySMS($item) : $this->activateByMail($item);
            $property = $request->get('property');
            if($property!=null){
                $property_id = null;
                if(!isset($property['property_id'])){
                    //$property['owner_type'] = User::class;
                    //$property['owner_id'] = $user->id;
                    $property['owner_type'] = Contact::class;
                    $property['owner_id'] = $contact->id;
                    /*$property['address'] = $data['location'];
                    $property['lat'] = $data['location_lat'];
                    $property['lon'] = $data['location_lang'];*/
                    $images = $property['images']; unset($property['images']);
                    $property_model = Property::create($property);
                    $property_id = $property_model->id;
                    foreach($images as $image_id){
                        $media = Media::find($image_id);
                        if ($media) {
                            $property_model->media()->attach($image_id, ['type' => 'property_image']);
                        }
                    }
                } else {
                    $property_id = $property['property_id'];
                }
            }
            DB::commit();
            return $this->respondWithSuccess(['message'=>'User registered successfully','user_id'=>$user->id]);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->respondUnAuthenticated($e->getMessage());
        }
    }

    public function logout()
    {
        try {
            //Reset user firebase token before logging out to avoid notifications
            $user = Auth::guard('api')->user();
            $user->fcm_token = null;
            $user->save();
            auth('api')->logout();
            return $this->respondOk('Logged out successfully');
        } catch (\Exception $e) {
            return $this->respondUnAuthenticated($e->getMessage());
        }
    }

    private function activateByMail($the_user)
    {
        $client = $the_user->client;
        $sender_name = $this->getEmailSenderName($client);
        $logo = $this->getClientLogo($client);
        $branding_colors = $this->getClientBrandingColors($client);
        $email_data = [
            'template_path' => 'User::mails.activation-mail',
            'subject' => $client == 'MID' ? 'My Irish Date  - Verify your account' : 'Activation Mail',
            'receiver_email' => $the_user->email,
            'name' => $the_user->first_name?? $the_user->name,
            'client' => $client,
            'logo' => $logo,
            'brand_color' => $branding_colors['primary'],
            'code' => $the_user->verification_code,
            'link' => url('user/activation'.'?code='.$the_user->verification_code.'&c='.$client),
            'link_text' => 'Verify email',
            'sender_email' => strtolower($client).'@bumblebeeai.io',
            'sender_name' => $sender_name
        ];
        SendEmail::dispatch($email_data);
    }
    private function resetByMail($item)
    {
        $sender_name = $this->getEmailSenderName($item->client);
        $logo = $this->getClientLogo($item->client);
        $email_data = [
            'template_path' => 'User::mails.forget-password',
            'subject' => 'User Forget Password',
            'receiver_email' => $item->email,
            'name' => $item->first_name,
            'client' =>  $item->client == 'MID' ? 'My Irish Date' :$item->client,
            'logo' => $logo,
            'code' => $item->forget_password_code,
            'link' => env('FRONT_URL') . '/user/reset-password',
            'sender_email' => strtolower($item->client).'@bumblebeeai.io',
            'sender_name' => $sender_name
        ];
        SendEmail::dispatch($email_data);
    }
    private function activateBySMS($item)
    {
        $phone = $item->country ? $item->country->phonecode . $item->phone : $item->phone;
        $body = 'Your Activation Code is: ' . $item->verification_code;
        TwilioHelper::sendSMS($item->client, $phone, $body);
    }
    private function resetBySMS($item)
    {
        $phone = $item->phone;
        $body = 'Your reset password Code is: ' . $item->forget_password_code;
        TwilioHelper::sendSMS($item->client, $phone, $body);
    }

    private function inviteByMail($email)
    {
        $email_data = [
            'template_path' => 'User::mails.invite-mail',
            'subject' => 'Invite Mail',
            'receiver_email' => $email,
            'name' => '',
            'code' => '',
            'link' => env('FRONT_URL'),
            'sender_email' => 'no-reply@bumblebeeai.io',
            'sender_name' => 'Bumblebee AIR'
        ];
        SendEmail::dispatch($email_data);
    }
    private function inviteBySMS($phone)
    {
        $body = 'Happy to download app ';
        TwilioHelper::sendSMS(request()->header('client'), $phone, $body);
    }

    public function userActivation(Request $request){
        $code = $request->get('code');
        $client = $request->get('c');
        $user_activation = $this->activateUser($code, $client);
        $message = 'success';
        if($user_activation['status']===false){
            $message = $user_activation['message'];
        }
        return view('User::user_verification',compact('message'));
    }

    public function testUserQr(Request $request){
        $user_id = $request->get('u');
        if(!$user_id){ return "denied"; }
        $item = User::find($user_id);
        $client = $item->client;
        $client_domain = null;
        $client_name = null;
        if($client == 'CRISK'){
            $client_domain = 'c-risk.com';
            $client_name = 'C-Risk';
        } elseif($client == 'BUMBLEBEE'){
            $client_domain = 'bumblebeeai.io';
            $client_name = 'Bumblebee AIR';
        }
        if($client_name!=null){
            $google2fa = new Google2FA();
            $secretKey = $google2fa->generateSecretKey();
            $two_factor_auth = new TwoFactorAuth();
            $two_factor_auth->user_type = User::class;
            $two_factor_auth->user_id = $item->id;
            $two_factor_auth->client = $client;
            $two_factor_auth->secret = $secretKey;
            $two_factor_auth->save();
            $qrCodeUrl = $google2fa->getQRCodeUrl(
                $client_name,
                $item->email,
                $secretKey
            );
            //$item['qr_code_url'] = $qrCodeUrl;
            $qr_code_image = QrCode::format('png')->size(200)->generate($qrCodeUrl);
            $item->qr_code_image = $qr_code_image;
        }
        $sender_name = $this->getEmailSenderName($item->client);
        $logo = $this->getClientLogo($item->client);
        $email_data = [
            'template_path' => 'User::mails.welcome-mail',
            'subject' => $item->client == 'MID' ? 'Welcome to My Irish Date ' : 'Welcome Mail',
            'receiver_email' => $item->email,
            'name' => $item->first_name,
            'client' => $item->client == 'MID' ? 'My Irish Date' : $item->client,
            'logo' => $logo,
            'encoded_image_attachment' => $item->qr_code_image? base64_encode($item->qr_code_image) : null,
            'sender_email' => strtolower($item->client).'@bumblebeeai.io',
            'sender_name' => $sender_name
        ];
        SendEmail::dispatch($email_data);
        return "success";
    }
}
