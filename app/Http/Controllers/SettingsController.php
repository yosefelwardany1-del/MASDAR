<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Media\ImageStore;
use App\Support\Audit\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function __construct(private ImageStore $images) {}

    public function edit(Request $request)
    {
        return view('settings.edit', [
            'setting' => Setting::current(),
            'editable' => $request->user()->can('settings.edit'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name_ar' => 'required|string|max:255',
            'company_name' => 'required|string|max:255',
        ]);

        DB::transaction(function () use ($data) {
            $setting = Setting::current();
            $old = $setting->only(['company_name', 'company_name_ar']);
            $setting->fill($data)->save();
            AuditTrail::record('updated', $setting, $old, $setting->only(['company_name', 'company_name_ar']));
        });

        return redirect()->route('settings.edit')->with('success', __('Settings saved.'));
    }

    public function storeLogo(Request $request)
    {
        $request->validate(['logo' => ImageStore::rules()]);

        DB::transaction(function () use ($request) {
            $setting = Setting::current();
            $old = $setting->only(['logo']);
            $this->images->store($setting, $request->file('logo'), 'logo', 'logo');
            AuditTrail::record('updated', $setting, $old, $setting->only(['logo']));
        });

        return back()->with('success', __('Logo updated.'));
    }

    public function destroyLogo()
    {
        DB::transaction(function () {
            $setting = Setting::current();
            $old = $setting->only(['logo']);
            $this->images->delete($setting, 'logo');
            AuditTrail::record('updated', $setting, $old, ['logo' => null]);
        });

        return back()->with('success', __('Logo removed.'));
    }

    /** The logo, streamed from the private disk to any signed-in user. */
    public function showLogo()
    {
        $setting = Setting::current();
        abort_unless($setting->exists && $this->images->has($setting, 'logo'), 404);

        return $this->images->stream($setting, 'logo');
    }
}
