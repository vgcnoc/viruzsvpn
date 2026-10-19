<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailSetting;
use App\Services\EmailDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class EmailSettingController extends Controller
{
    public function show(EmailDelivery $delivery)
    {
        return response()->json($delivery->details());
    }

    public function update(Request $request, EmailDelivery $delivery)
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253'], 'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])], 'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:1', 'max:512'], 'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'], 'is_enabled' => ['required', 'boolean'],
        ]);
        $setting = EmailSetting::firstOrNew(['id' => 1]);
        if (! empty($data['password'])) {
            $data['encrypted_password'] = Crypt::encryptString($data['password']);
        }
        unset($data['password']);
        $setting->fill($data)->save();

        return response()->json($delivery->details());
    }

    public function test(Request $request, EmailDelivery $delivery)
    {
        try {
            $delivery->sendTest($request->user()->email);
        } catch (\Throwable) {
            return response()->json(['message' => 'The test email could not be delivered. Check SMTP host, port, encryption, username, and password.'], 502);
        }

        return response()->json(['message' => 'A test email was sent to '.$request->user()->email.'.']);
    }
}
