<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Profile photos: every user changes their own; the group CEO and the HR manager change anyone's (within their companies). */
class ProfilePhotoController extends Controller
{
    private function authorizeTarget(Request $request, User $user): void
    {
        $me = $request->user();
        if ($me->id === $user->id) {
            return;
        }
        abort_unless($me->hasRole('super', 'hr_mgr'), 403, 'تعديل صور الآخرين صلاحية الرئيس التنفيذي للمجموعة ومدير الموارد البشرية');
        if (! $me->hasRole('super') && $user->employee) {
            abort_unless(Access::for($me)->canSeeEmployee($user->employee), 403);
        }
    }

    public function store(Request $request, User $user)
    {
        $this->authorizeTarget($request, $user);
        $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096'], ['photo.image' => 'الملف يجب أن يكون صورة', 'photo.mimes' => 'الصيغ المسموحة: JPG أو PNG أو WEBP', 'photo.max' => 'الحد الأقصى 4 ميجابايت']);
        $file = $request->file('photo');
        $name = 'u'.$user->id.'_'.Str::random(24).'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg');
        Storage::disk('public')->putFileAs('photos', $file, $name);
        $this->dropOld($user);
        $user->forceFill(['photo_path' => 'photos/'.$name])->save();
        Audit::log('users', $user->id, 'photo');

        return ['photo_url' => $user->fresh()->photo_url];
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorizeTarget($request, $user);
        $this->dropOld($user);
        $user->forceFill(['photo_path' => null])->save();
        Audit::log('users', $user->id, 'photo_removed');

        return ['photo_url' => null];
    }

    private function dropOld(User $user): void
    {
        if ($user->photo_path) {
            Storage::disk('public')->delete($user->photo_path);
        }
    }
}
