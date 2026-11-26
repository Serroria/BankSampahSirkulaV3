<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Penyimpanan berkas via Supabase Storage (REST API, tanpa paket AWS).
 *
 *  - Bucket PRIVAT  (SUPABASE_BUCKET_PRIVATE, default "Storage")       -> dokumen pengajuan
 *  - Bucket PUBLIK  (SUPABASE_BUCKET_PUBLIC,  default "StoragePublic") -> foto/gambar
 *
 * Bila SUPABASE_URL / SUPABASE_SECRET_KEY kosong (mis. di komputer sendiri),
 * otomatis memakai disk lokal Laravel.
 */
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

    private static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private static function base(): string
    {
        return rtrim(config('services.supabase.url'), '/').'/storage/v1/object/';
    }

    /** URL API untuk objek di bucket tertentu (butuh secret key). */
    private static function url(string $bucket, string $path): string
    {
        return self::base().rawurlencode($bucket).'/'.self::encode($path);
    }

    private static function unggah(string $bucket, string $path, UploadedFile $file): void
    {
        self::http()
            ->withHeaders(['x-upsert' => 'true'])
            ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType() ?: 'application/octet-stream')
            ->post(self::url($bucket, $path))
            ->throw();
    }

    // ------------------------------------------------------------------
    //  PRIVAT  (dokumen)
    // ------------------------------------------------------------------

    public static function simpan(string $path, UploadedFile $file): string
    {
        if (! self::aktif()) {
            return Storage::disk('dokumen')->putFileAs(dirname($path), $file, basename($path));
        }

        self::unggah(config('services.supabase.bucket_private'), $path, $file);

        return $path;
    }

    public static function hapus(string $path): void
    {
        if (! self::aktif()) {
            Storage::disk('dokumen')->delete($path);

            return;
        }

        self::http()->delete(self::url(config('services.supabase.bucket_private'), $path));
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

        $res = self::http()->get(self::url(config('services.supabase.bucket_private'), $path))->throw();

        return response()->streamDownload(fn () => print($res->body()), $nama, [
            'Content-Type' => $res->header('Content-Type') ?: 'application/octet-stream',
        ]);
    }

    // ------------------------------------------------------------------
    //  PUBLIK  (foto / gambar)
    // ------------------------------------------------------------------

    /** Pengganti ->store($folder, 'public'). Mengembalikan path relatif untuk disimpan di database. */
    public static function simpanPublik(string $folder, UploadedFile $file): string
    {
        if (! self::aktif()) {
            return $file->store($folder, 'public');
        }

        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $path = trim($folder, '/').'/'.Str::uuid().'.'.strtolower($ext);

        self::unggah(config('services.supabase.bucket_public'), $path, $file);

        return $path;
    }

    /** Pengganti Storage::disk('public')->delete(). Gagal hapus tidak boleh menggagalkan permintaan. */
    public static function hapusPublik(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        if (! self::aktif()) {
            Storage::disk('public')->delete($path);

            return;
        }

        try {
            self::http()->delete(self::url(config('services.supabase.bucket_public'), $path));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Pengganti Storage::url(). Bucket publik dapat dibuka langsung tanpa kunci. */
    public static function urlPublik(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (! self::aktif()) {
            return Storage::disk('public')->url($path);
        }

        return self::base().'public/'.rawurlencode(config('services.supabase.bucket_public')).'/'.self::encode($path);
    }
}
