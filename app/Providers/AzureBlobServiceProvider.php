<?php

namespace App\Providers;

use AzureOss\Identity\DefaultAzureCredential;
use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

/**
 * Mendaftarkan driver disk "azure" untuk Laravel Storage.
 *
 * Laravel tidak punya driver Azure Blob bawaan -- config disk dengan
 * 'driver' => 'azure' TANPA provider ini gagal saat runtime dengan
 * "Driver [azure] is not supported", bukan saat boot.
 *
 * Kenapa ini perlu sama sekali: filesystem container App Service bersifat
 * ephemeral dan bisa multi-instance. Berkas di disk lokal hilang saat restart
 * dan tidak terlihat oleh instance lain, tanpa error apa pun -- unggahan
 * "berhasil", lalu lenyap.
 *
 * OTENTIKASI. Dua jalur, dipilih dari config:
 *
 *   1. connection_string -- dipakai lokal/compose (Azurite atau account key).
 *   2. endpoint + Managed Identity -- dipakai di Azure. DefaultAzureCredential
 *      mengambil token dari IMDS App Service, jadi TIDAK ADA account key yang
 *      perlu disimpan di mana pun. Ini yang membuat Key Vault tidak perlu
 *      menyimpan kunci storage.
 *
 * Managed Identity app WAJIB punya peran "Storage Blob Data Contributor" pada
 * storage account. Tanpa itu setiap operasi gagal 403 saat runtime -- deploy
 * tetap hijau karena /up tidak menyentuh storage.
 */
class AzureBlobServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('azure', function ($app, array $config) {
            $container = $config['container'] ?? null;

            if (empty($container)) {
                throw new \InvalidArgumentException(
                    'Disk azure butuh "container". Set AZURE_STORAGE_CONTAINER.'
                );
            }

            $connectionString = $config['connection_string'] ?? null;

            if (! empty($connectionString)) {
                $service = BlobServiceClient::fromConnectionString($connectionString);
            } else {
                $endpoint = $config['endpoint'] ?? null;

                if (empty($endpoint)) {
                    throw new \InvalidArgumentException(
                        'Disk azure butuh "endpoint" (atau "connection_string"). '
                        .'Set AZURE_STORAGE_BLOB_ENDPOINT, contoh '
                        .'https://sabtapps.blob.core.windows.net'
                    );
                }

                $service = new BlobServiceClient(new Uri($endpoint), new DefaultAzureCredential);
            }

            // ON_VISIBILITY_IGNORE, bukan default ON_VISIBILITY_THROW_ERROR:
            // Blob container tidak punya konsep visibility per-berkas seperti
            // POSIX, dan container ini memang privat. Dengan default-nya,
            // Storage::put($path, $data, 'private') -- pemanggilan yang sudah
            // ada di kode -- melempar exception, padahal hasilnya sudah benar.
            $adapter = new AzureBlobStorageAdapter(
                $service->getContainerClient($container),
                (string) ($config['prefix'] ?? ''),
                null,
                AzureBlobStorageAdapter::ON_VISIBILITY_IGNORE,
            );

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
