<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recording;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\PlanRules;
use App\Support\RecordingRules;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RecordingController extends Controller
{
    /** فهرست ضبط‌ها (بدون رویدادها)، جدیدترین اول: {"recordings": [RecordingMeta...]} */
    public function index(Request $request): JsonResponse
    {
        $recordings = Recording::where('user_id', $request->user()->id)
            ->select(Recording::META_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Recording $r) => $r->toApiPayload())
            ->all();

        return response()->json(['recordings' => $recordings]);
    }

    /**
     * ذخیره‌ی ضبط. multipart/form-data (یا JSON بدون صدا) با فیلدهای:
     * id (شناسه‌ی فرانت)، name، durationMs، events (آرایه یا رشته‌ی JSON)، audio (فایل WAV، اختیاری).
     * noteCount و hasAudio و زمان ساخت رو سرور خودش حساب می‌کنه. تکرار همون id چیزی رو دوباره نمی‌سازه (200).
     * سقف تعداد ضبط‌ها بر اساس پلن اینجا اعمال می‌شه: 409 با recordings.limit.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $recId = $request->input('id');
        $name = RecordingRules::parseName($request->input('name') ?? '');
        $duration = RecordingRules::parseDuration($request->input('durationMs'));
        $events = RecordingRules::parseEvents($request->input('events'));

        if (! RecordingRules::isValidId($recId) || $name === null || $duration === null || $events === null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }
        if ($name === '') {
            $name = RecordingRules::DEFAULT_NAME;
        }

        $file = $request->file('audio');
        if ($file === null && $request->has('audio') && $request->input('audio') !== null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422); // «audio» باید فایل باشه
        }
        if ($file !== null && ! $this->isValidAudio($file)) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        if ($existing = Recording::where('user_id', $user->id)->where('rec_id', $recId)->first()) {
            return response()->json(['recording' => $existing->toApiPayload()]);
        }

        $disk = Storage::disk('local');
        $path = $file !== null ? RecordingRules::audioPath($user->id, $recId) : null;

        try {
            $result = DB::transaction(function () use ($user, $recId, $name, $duration, $events, $file, $disk, $path) {
                // قفل ردیف کاربر تا دو ذخیره‌ی همزمان از سقف رد نشن
                User::whereKey($user->id)->lockForUpdate()->first();

                if (Recording::where('user_id', $user->id)->count() >= $this->limitFor($user->id)) {
                    return 'limit';
                }
                if ($file !== null) {
                    $disk->putFileAs(dirname($path), $file, basename($path));
                }

                return Recording::create([
                    'user_id' => $user->id,
                    'rec_id' => $recId,
                    'name' => $name,
                    'duration_ms' => $duration,
                    'note_count' => RecordingRules::countNotes($events),
                    'events' => $events,
                    'audio_path' => $path,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // همزمان با درخواست دیگه‌ای با همین id
            $existing = Recording::where('user_id', $user->id)->where('rec_id', $recId)->first();

            return response()->json(['recording' => $existing?->toApiPayload()]);
        } catch (Throwable $e) {
            if ($path !== null) {
                $disk->delete($path);
            }
            throw $e;
        }

        if ($result === 'limit') {
            return ApiError::response(AuthRules::ref('recordings.limit'), 409);
        }

        return response()->json(['recording' => $result->fresh()->toApiPayload()], 201);
    }

    /** رویدادهای یک ضبط (صدا جدا از /audio): {"events": [...], "hasAudio": bool} */
    public function show(Request $request, string $id): JsonResponse
    {
        $recording = $this->find($request, $id);
        if ($recording === null) {
            return ApiError::response(AuthRules::ref('api.notFound'), 404);
        }

        return response()->json(['events' => $recording->events, 'hasAudio' => $recording->audio_path !== null]);
    }

    /** فایل صدای ضبط (فقط برای صاحبش؛ روی دیسک private نگهداری می‌شه) */
    public function audio(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $recording = $this->find($request, $id);
        $disk = Storage::disk('local');

        if ($recording === null || $recording->audio_path === null || ! $disk->exists($recording->audio_path)) {
            return ApiError::response(AuthRules::ref('api.notFound'), 404);
        }

        return $disk->response($recording->audio_path, null, [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /** تغییر نام: {"name": "..."} ؛ نام خالی نامعتبره */
    public function rename(Request $request, string $id): JsonResponse
    {
        $name = RecordingRules::parseName($request->json('name'));
        if ($name === null || $name === '') {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $recording = $this->find($request, $id, light: true);
        if ($recording === null) {
            return ApiError::response(AuthRules::ref('api.notFound'), 404);
        }

        $recording->update(['name' => $name]);

        return response()->json(['recording' => $recording->toApiPayload()]);
    }

    /** حذف ضبط و فایل صداش؛ حذف چیزی که نیست هم خطا نیست (۲۰۴) */
    public function destroy(Request $request, string $id): Response
    {
        $recording = $this->find($request, $id, light: true);
        if ($recording !== null) {
            if ($recording->audio_path !== null) {
                Storage::disk('local')->delete($recording->audio_path);
            }
            $recording->delete();
        }

        return response()->noContent();
    }

    private function find(Request $request, string $id, bool $light = false): ?Recording
    {
        if (! RecordingRules::isValidId($id)) {
            return null;
        }

        $query = Recording::where('user_id', $request->user()->id)->where('rec_id', $id);
        if ($light) {
            $query->select(Recording::META_COLUMNS);
        }

        return $query->first();
    }

    private function isValidAudio(\Illuminate\Http\UploadedFile $file): bool
    {
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() <= 0 || $file->getSize() > RecordingRules::MAX_AUDIO_BYTES) {
            return false;
        }

        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return false;
        }
        $header = (string) fread($handle, 12);
        fclose($handle);

        return RecordingRules::isWavHeader($header);
    }

    /** سقف تعداد ضبط‌ها برای پلن فعلی کاربر (پریمیوم یا رایگان) */
    private function limitFor(int $userId): int
    {
        $state = Subscription::where('user_id', $userId)->first()?->toState();
        $now = DateTimeImmutable::createFromInterface(now());

        return PlanRules::recordingLimit(PlanRules::isPremium($state, $now));
    }
}
