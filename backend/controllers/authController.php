<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Managers\ActivityManager;
use App\Managers\AuthManager;
use App\Models\User;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Sentinel;
use Validator;

class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Registration & Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users, as well as the
    | authentication of existing users. By default, this controller uses
    | a simple trait to add these behaviors. Why don't you explore it?
    |
    */

    /**
     * Create a new authentication controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest', ['except' => 'getLogout']);
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validator(array $data)
    {
        return Validator::make($data, [
            'username' => 'required|max:255',
            // 'email' => 'required|email|max:255|unique:users',
            'password' => 'required|confirmed|min:6',
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return User
     */
    public function create(array $data)
    {
        return User::create([
            'username' => $data['username'],
            // 'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }

    /*public function authenticate()
    {
        var_dump('test');exit;
        $username = Input::get('username');
        $password = Hash::make(Input::get('password'));

        if (Auth::attempt(['username' => $username, 'password' => $password])) {
            // Authentication passed...
            return redirect()->intended('dashboard');
        }
    }*/

    public function postLogin(Request $request)
    {
        $this->validate($request, [
            'username' => 'required', 'password' => 'required',
        ]);

        $credentials = $request->only('username', 'password');

        if (Auth::attempt($credentials, $request->filled('remember'))) {
            $user_data = Auth::user();

            try {
                $user = Sentinel::findById($user_data->id);

                $sentinel_user = Sentinel::loginAndRemember($user);
            } catch (\Exception $e) {
                $user = Sentinel::findById($user_data->id);

                $sentinel_user = Sentinel::loginAndRemember($user);
            }
            $am = new AuthManager();
            if ($am->isAnyRole(['master', 'sales', 'finance', 'company_agent', 'company_master',
                'customer_service', 'service_company', 'erac_demo', 'sixt', 'driver', 'advance_best_drive', ])) {
                (new ActivityManager())->userLogin($user_data->id, 'normal login to the system');

                return redirect()->intended('dashboard');
            } elseif ($am->isAnyRole(['client_company_agent'])) {
                return redirect()->intended('job-list');
            }
            Auth::logout();
            Sentinel::logout();

            return redirect()->back()
                ->withErrors(['This user type is forbidden from login',
                ]);
        }

        return redirect()->back()
                    ->withInput($request->only(['username', 'remember']))
                    ->withErrors([
                        'username' => $this->getFailedLoginMessage(),
                    ]);
    }

    /**
     * Log the user out of the application.
     *
     * @return \Illuminate\Http\Response
     */
    public function getLogout()
    {
        if (Sentinel::check()) {
            $id = Auth::user()->id;
            (new ActivityManager())->userLogout($id, 'Logout to the system');
            Auth::logout();
            Sentinel::logout();
        }

        return redirect(property_exists($this, 'redirectAfterLogout') ? $this->redirectAfterLogout : '/');
    }

    public function getForceLogin($id)
    {
        Auth::loginUsingId($id);
        $user_data = Auth::user();
        $user = Sentinel::findById($user_data->id);

        $sentinel_user = Sentinel::loginAndRemember($user);

        return \Redirect::to('dashboard');
    }
}
