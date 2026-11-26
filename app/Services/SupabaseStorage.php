<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Penyimpanan dokumen privat: Supabase Storage (REST) di Vercel, disk lokal di komputer sendiri. */
class SupabaseStorage
{
    private static function aktif(): bool
    {
        return filled(config('services.supabase.url')) && filled(config('services.supabase.key'));
    }

    private static function http(): PendingRequest
    {
        $key = config('services.supabase.key');

        return Http::withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$key])->timeout(30);
    }

    private static function url(string $path): string
    {
        $path = implode('/', array_map('rawurlencode', explode('/', $path)));

        return rtrim(config('services.supabase.url'), '/').'/storage/v1/object/'.rawurlencode(config('services.supabase.bucket_private')).'/'.$path;
    }

    public static function simpan(string $path, UploadedFile $file): string
    {
        if (! self::aktif()) {
            return Storage::disk('dokumen')->putFileAs(dirname($path), $file, basename($path));
        }

        self::http()
            ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType() ?: 'application/octet-stream')
            ->post(self::url($path))
            ->throw();

        return $path;
    }

    public static function hapus(string $path): void
    {
        if (! self::aktif()) {
            Storage::disk('dokumen')->delete($path);

            return;
        }
        self::http()->delete(self::url($path));
    }

    public static function unduh(string $path, string $nama)
    {
        if (! self::aktif()) {
            return response()->streamDownload(function () use ($path) {
                $stream = Storage::disk('dokumen')->readStream($path);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, $nama);
        }

        $res = self::http()->get(self::url($path))->throw();

        return response()->streamDownload(fn () => print($res->body()), $nama, [
            'Content-Type' => $res->header('Content-Type') ?: 'application/octet-stream',
        ]);
    }
}
