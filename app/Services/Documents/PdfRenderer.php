<?php

namespace App\Services\Documents;

use App\Models\Product;
use App\Models\Setting;
use App\Services\Media\ImageStore;
use App\Support\SetupNotice;
use Illuminate\Http\Request;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Turns a printed page into a PDF with a headless Chromium (spec §5.8). The page's own images
 * are put inside it first — the logo and product photos sit behind sign-in, so Chromium could
 * not fetch them — and Chromium is kept off the network: what it renders is exactly the page.
 */
class PdfRenderer
{
    public function __construct(private ImageStore $images) {}

    public function binary(): ?string
    {
        $configured = config('documents.chrome_binary');
        if (filled($configured)) {
            return is_executable($configured) ? $configured : null;
        }
        foreach ((array) config('documents.chrome_candidates', []) as $pattern) {
            foreach (str_contains($pattern, '*') ? (glob($pattern) ?: []) : [$pattern] as $candidate) {
                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /** @throws DocumentException when there is no Chromium or it fails */
    public function fromHtml(string $html): string
    {
        // B32: the missing setting is named to the system administrator only.
        $binary = $this->binary() ?? throw new DocumentException(SetupNotice::for(auth()->user(),
            __('PDF files can\'t be made yet: print the page from the browser instead, or ask your administrator.'),
            __('PDF is not set up on this server: it needs Chromium (PDF_CHROME_BINARY). Print the page from the browser instead.')));

        $dir = storage_path('framework/pdf/'.Str::ulid());
        File::ensureDirectoryExists($dir);
        try {
            $page = $dir.'/page.html';
            $out = $dir.'/page.pdf';
            File::put($page, $this->inlineImages($html));

            try {
                $result = Process::timeout((int) config('documents.timeout', 30))
                    ->env(['HOME' => $dir])
                    ->run([
                        $binary, '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
                        '--disable-extensions', '--disable-background-networking', '--no-first-run', '--hide-scrollbars',
                        // Nothing leaves the machine: every image is already inside the page.
                        '--host-resolver-rules=MAP * ~NOTFOUND',
                        '--user-data-dir='.$dir.'/profile', '--no-pdf-header-footer', '--virtual-time-budget=10000',
                        '--print-to-pdf='.$out, 'file://'.$page,
                    ]);
            } catch (ProcessTimedOutException $e) {
                report($e);
                throw new DocumentException(__('The PDF could not be made. Print the page from the browser instead.'));
            }

            if (! $result->successful() && ! is_file($out)) {
                report(new \RuntimeException('Chromium failed: '.Str::limit($result->errorOutput(), 500)));
            }
            if (! is_file($out) || filesize($out) === 0) {
                report(new \RuntimeException('PDF not produced: '.Str::limit($result->errorOutput(), 500)));
                throw new DocumentException(__('The PDF could not be made. Print the page from the browser instead.'));
            }

            return (string) file_get_contents($out);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Every <img src> pointing at this application becomes a data: URI; others are dropped. */
    public function inlineImages(string $html): string
    {
        return (string) preg_replace_callback('/(<img\b[^>]*?\ssrc=)(["\'])(.*?)\2/i', function (array $m) {
            $src = html_entity_decode($m[3], ENT_QUOTES);
            if (str_starts_with($src, 'data:')) {
                return $m[0];
            }
            $data = $this->imageData($src);

            return $m[1].$m[2].($data ?? 'data:,').$m[2];
        }, $html);
    }

    private function imageData(string $src): ?string
    {
        $path = parse_url($src, PHP_URL_PATH) ?: '';
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = parse_url($src, PHP_URL_HOST);
        if ($host !== null && $host !== $appHost && $host !== request()->getHost()) {
            return null;
        }

        $bytes = null;
        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
            $bytes = match ($route->getName()) {
                'settings.logo' => $this->images->bytes(Setting::current(), 'logo'),
                'products.photo' => ($product = Product::query()->find((int) $route->parameter('product'))) ? $this->images->bytes($product, 'photo') : null,
                default => null,
            };
        } catch (\Throwable) {
            // Not a route: a public file such as the default logo.
        }
        if ($bytes === null) {
            $file = realpath(public_path(ltrim($path, '/')));
            if ($file && str_starts_with($file, realpath(public_path())) && is_file($file)) {
                $bytes = (string) file_get_contents($file);
            }
        }
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
