<?php

namespace App\Suppliers\Amrod;

use App\Models\Supplier;
use App\Suppliers\SupplierUnavailableException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use RuntimeException;

/**
 * Thin HTTP client for the Amrod Vendor API v2.
 *
 * Auth:  POST https://identity.amrod.co.za/VendorLogin {UserName, Password, CustomerCode} -> bearer token (1h TTL)
 * Data:  GET  https://vendorapi.amrod.co.za/api/v1/{endpoint}
 * Down:  00:00-01:00 SAST daily, API answers 204 No Content.
 */
class AmrodClient
{
    public const ENDPOINTS = [
        'products' => 'Products/GetProductsAndBranding',
        'products_changes' => 'Products/GetUpdatedProductsAndBranding',
        'products_plain' => 'Products/',
        'products_plain_changes' => 'Products/GetUpdatedProducts',
        'stock' => 'Stock/',
        'stock_changes' => 'Stock/GetUpdated',
        'prices' => 'Prices/',
        'prices_changes' => 'Prices/GetUpdated',
        'categories' => 'Categories/',
        'categories_changes' => 'Categories/GetUpdated',
        'brands' => 'Brands/',
        'brands_changes' => 'Brands/GetUpdated',
        'branding_departments' => 'BrandingDepartments/',
        'branding_departments_changes' => 'BrandingDepartments/GetUpdated',
        'inclusive_brandings' => 'InclusiveBrandings/',
        'inclusive_brandings_changes' => 'InclusiveBrandings/GetUpdated',
        'branding_prices' => 'BrandingPrices/',
        'branding_prices_changes' => 'BrandingPrices/GetUpdated',
        'colour_swatches' => 'ColourSwatches/',
        'colour_groups' => 'ColourSwatches/GetGrouping',
    ];

    public function __construct(private readonly Supplier $supplier) {}

    public function identityUrl(): string
    {
        return rtrim(data_get($this->supplier->settings, 'identity_url') ?: config('services.amrod.identity_url'), '/');
    }

    public function apiUrl(): string
    {
        return rtrim(data_get($this->supplier->settings, 'api_url') ?: config('services.amrod.api_url'), '/');
    }

    /** True during Amrod's nightly maintenance window (00:00-01:00 Africa/Johannesburg). */
    public static function inMaintenanceWindow(?Carbon $at = null): bool
    {
        $local = ($at ?? now())->copy()->setTimezone('Africa/Johannesburg');

        return $local->hour === 0;
    }

    public function token(bool $fresh = false): string
    {
        $key = "amrod-token:{$this->supplier->id}:".md5((string) $this->supplier->credential('username'));

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(55), fn () => $this->login());
    }

    public function login(): string
    {
        $username = $this->supplier->credential('username');
        $password = $this->supplier->credential('password');
        $customerCode = $this->supplier->credential('customer_code');

        if (blank($username) || blank($password) || blank($customerCode)) {
            throw new RuntimeException('Amrod username, password and customer code are all required.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->post($this->identityUrl().'/VendorLogin', [
                'UserName' => trim($username),
                'Password' => $password,
                'CustomerCode' => trim($customerCode),
            ]);

        $json = $response->json();
        $token = is_array($json)
            ? ($json['token'] ?? $json['Token'] ?? $json['access_token'] ?? $json['accessToken'] ?? null)
            : null;

        // Some deployments return the token as a bare string body.
        if (! $token && $response->successful() && is_string($json)) {
            $token = $json;
        }
        if (! $token && $response->successful() && preg_match('/^[\w\-.]{40,}$/', trim($response->body()))) {
            $token = trim($response->body(), "\" \n\r\t");
        }

        if (! $token) {
            $message = is_array($json) ? ($json['message'] ?? $json['Message'] ?? null) : null;
            $message ??= trim(strip_tags($response->body())) ?: "HTTP {$response->status()}";

            // Amrod's documented failure messages: "Invalid User", "Invalid username or password.", "API Access Denied"
            throw new RuntimeException("Amrod login failed: {$message}");
        }

        return $token;
    }

    /**
     * GET an endpoint by key (see ENDPOINTS). Returns the decoded JSON array.
     *
     * @throws SupplierUnavailableException on 204 / maintenance window
     */
    public function get(string $endpointKey, array $query = []): array
    {
        if (self::inMaintenanceWindow()) {
            throw new SupplierUnavailableException('Amrod API is unavailable between 00:00 and 01:00 (SAST).');
        }

        $path = self::ENDPOINTS[$endpointKey] ?? $endpointKey;
        $url = $this->apiUrl().'/api/v1/'.ltrim($path, '/');

        $response = $this->request()->get($url, $query);

        if ($response->status() === 401) {
            $response = $this->request(freshToken: true)->get($url, $query);
        }

        if ($response->status() === 204 || trim($response->body()) === '') {
            throw new SupplierUnavailableException("Amrod returned no content for {$path} (HTTP {$response->status()}).");
        }

        try {
            $response->throw();
        } catch (RequestException $e) {
            throw new RuntimeException("Amrod {$path} failed: HTTP {$response->status()} ".str($response->body())->limit(300), previous: $e);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException("Amrod {$path} returned a response that is not JSON.");
        }

        // Accept either a bare list or a wrapper object like {data: [...]}.
        if (! array_is_list($json)) {
            foreach (['data', 'Data', 'items', 'Items', 'result', 'Result'] as $wrap) {
                if (isset($json[$wrap]) && is_array($json[$wrap])) {
                    return $json[$wrap];
                }
            }
        }

        return $json;
    }

    /**
     * Stream a (large) endpoint: the response is saved to a temp file and records are yielded one at a time,
     * so a catalogue of any size runs in a small, constant amount of memory.
     *
     * @return \Generator<int, array>
     *
     * @throws SupplierUnavailableException on 204 / maintenance window
     */
    public function stream(string $endpointKey): \Generator
    {
        if (self::inMaintenanceWindow()) {
            throw new SupplierUnavailableException('Amrod API is unavailable between 00:00 and 01:00 (SAST).');
        }

        $path = self::ENDPOINTS[$endpointKey] ?? $endpointKey;
        $url = $this->apiUrl().'/api/v1/'.ltrim($path, '/');
        $dir = storage_path('app/private/supplier-sync');
        @mkdir($dir, 0775, true);
        $file = $dir.'/amrod-'.$this->supplier->id.'-'.str_replace('/', '_', $endpointKey).'.json';

        $response = $this->request()->sink($file)->get($url);
        if ($response->status() === 401) {
            $response = $this->request(freshToken: true)->sink($file)->get($url);
        }

        try {
            if ($response->status() === 204 || ! is_file($file) || filesize($file) === 0) {
                throw new SupplierUnavailableException("Amrod returned no content for {$path} (HTTP {$response->status()}).");
            }
            if ($response->failed()) {
                throw new RuntimeException("Amrod {$path} failed: HTTP {$response->status()} ".str((string) file_get_contents($file, length: 300))->limit(300));
            }

            // Bare list: [ {...}, {...} ]. Wrapped: { "data": [ ... ] }
            $handle = fopen($file, 'r');
            $first = '';
            while (! feof($handle) && ($first = ltrim(fread($handle, 64))) === '') {
            }
            fclose($handle);
            $pointer = '';
            if (str_starts_with($first, '{')) {
                foreach (['data', 'Data', 'items', 'Items', 'result', 'Result'] as $wrap) {
                    if (preg_match('/"'.$wrap.'"\s*:\s*\[/', (string) file_get_contents($file, length: 4096))) {
                        $pointer = '/'.$wrap;
                        break;
                    }
                }
            }

            $items = Items::fromFile($file, ['decoder' => new ExtJsonDecoder(true), 'pointer' => $pointer]);
            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }
        } finally {
            @unlink($file);
        }
    }

    private function request(bool $freshToken = false): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->token($freshToken))
            ->timeout((int) config('services.amrod.timeout', 600))
            ->connectTimeout(30)
            ->retry(2, 2000, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false);
    }
}
